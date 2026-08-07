<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\InboxThreadRead;
use App\Models\WorkOrder;
use App\Services\ConversationParticipants;
use App\Services\CourtesyCloserService;
use App\Services\TapbackDetector;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A single place to work through every conversation, instead of opening each
 * work order to find out who is still waiting.
 *
 * A "thread" here is one work order plus one party, matching how
 * BoardSummaryService already groups: a work order can hold a tenant thread, an
 * owner thread, and one thread per assigned vendor.
 */
class InboxController extends Controller
{
    private const THREADS_PER_PAGE = 40;

    /** How many characters of the latest message the list shows. */
    private const PREVIEW_LENGTH = 120;

    private const STATUS_FILTERS = ['all', 'awaiting', 'unread', 'unanswered_24h'];

    /**
     * The conversation_type values threads are actually stored under. Anything
     * else is a legacy or unknown row and lands under 'all' only.
     */
    private const PARTY_FILTERS = ['tenant', 'owner', 'vendor', 'vendor_tenant', 'vendor_owner'];

    public function __construct(
        private ConversationParticipants $participants,
        private CourtesyCloserService $courtesyClosers,
    ) {}

    public function index(Request $request)
    {
        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'party' => (string) $request->input('party', 'all'),
            'status' => (string) $request->input('status', 'all'),
        ];

        if (! in_array($filters['status'], self::STATUS_FILTERS, true)) {
            $filters['status'] = 'all';
        }

        if (! in_array($filters['party'], self::PARTY_FILTERS, true)) {
            $filters['party'] = 'all';
        }

        $threads = $this->threads($filters);

        return inertia('Inbox/Index', [
            'title' => 'Inbox',
            'threads' => $threads,
            'filters' => $filters,
            // Counted before the party filter is applied, so each chip can say
            // what is behind it without being clicked.
            'partyCounts' => $this->partyCounts($filters),
            'stats' => [
                'total' => $threads->count(),
                'awaiting' => $threads->where('awaiting', true)->count(),
                'unread' => $threads->where('unread', true)->count(),
                'overdue' => $threads->where('awaiting', true)
                    ->where('waiting_hours', '>=', 24)
                    ->count(),
            ],
        ]);
    }

    /**
     * The messages of one thread, plus the names needed to label them. Fetched
     * over axios when a thread is opened so switching threads never reloads the
     * page.
     */
    public function thread(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'work_order_id' => ['required', 'integer'],
            'conversation_type' => ['required', 'string', 'max:50'],
            'vendor_id' => ['nullable', 'integer'],
            'owner_id' => ['nullable', 'integer'],
        ]);

        // Conversation's global scope keeps vendors and tenants to their own
        // work orders, so a thread nobody may read comes back empty rather than
        // leaking. The work order itself is checked the same way.
        $readable = Conversation::query()
            ->where('work_order_id', $validated['work_order_id'])
            ->exists();

        abort_unless($readable, 404);

        $workOrder = WorkOrder::query()
            ->with(['vendors.user', 'owners', 'requested_by', 'woc.wocNumber.twilioPhoneNumber', 'building'])
            ->findOrFail($validated['work_order_id']);

        $messages = Conversation::query()
            ->with('media')
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', $validated['conversation_type'])
            ->when(
                filled($validated['vendor_id'] ?? null),
                fn ($query) => $query->where('vendor_id', $validated['vendor_id']),
                fn ($query) => $query->whereNull('vendor_id')
            )
            ->when(
                filled($validated['owner_id'] ?? null),
                fn ($query) => $query->where('owner_id', $validated['owner_id']),
                fn ($query) => $query->whereNull('owner_id')
            )
            ->orderBy('id')
            ->get();

        $this->markThreadRead($request, $workOrder, $validated, $messages);

        return response()->json([
            'messages' => $messages,
            'work_order' => $workOrder,
            'participants' => $this->participants->mapFor($workOrder),
            'woc_phone_number' => $this->participants->wocNumberFor($workOrder),
            'recipient_number' => $this->recipientFor($messages, $workOrder),
        ]);
    }

    /**
     * Remember, for this staff user, that everything currently in the thread
     * has been seen. Opening the thread again after a new inbound message
     * moves the marker forward; the marker never moves backwards.
     *
     * Log-never-throw: reading a thread must never fail over its marker. Two
     * tabs opening the same thread at once can race the unique index on
     * firstOrCreate — the loser falls through to the guarded update, which is
     * idempotent.
     *
     * @param  array<string, mixed>  $validated
     * @param  Collection<int, Conversation>  $messages
     */
    private function markThreadRead(Request $request, WorkOrder $workOrder, array $validated, Collection $messages): void
    {
        $user = $request->user();
        $lastId = (int) ($messages->max('id') ?? 0);

        if ($user === null || $lastId === 0) {
            return;
        }

        $threadKey = [
            'user_id' => $user->id,
            'work_order_id' => $workOrder->id,
            'conversation_type' => $validated['conversation_type'] ?: 'unknown',
            'vendor_id' => (int) ($validated['vendor_id'] ?? 0),
            'owner_id' => (int) ($validated['owner_id'] ?? 0),
        ];

        try {
            try {
                InboxThreadRead::query()->firstOrCreate($threadKey);
            } catch (QueryException) {
                // A concurrent open created the row between the find and the
                // insert; it exists now, which is all the update below needs.
            }

            InboxThreadRead::query()
                ->where($threadKey)
                ->where('last_read_conversation_id', '<', $lastId)
                ->update(['last_read_conversation_id' => $lastId]);
        } catch (\Throwable $exception) {
            Log::warning('Inbox read marker could not be stored.', [
                'work_order_id' => $workOrder->id,
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * One row per thread, carrying its newest message.
     *
     * The newest row per thread is picked with MAX(id): ids are monotonic, so
     * this is free of created_at ties. Names are resolved in PHP afterwards
     * rather than in four more joins — the page is bounded, and the fallbacks
     * for legacy untagged rows read better as code.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function threads(array $filters): Collection
    {
        // Judged courtesy closers ("thank you") stop counting as awaiting: they
        // are hidden from the awaiting filters but still listed under 'all',
        // rendered as answered. See CourtesyCloserService — fails open.
        $courtesyIds = $this->courtesyClosers->courtesyIds();

        $rows = DB::table('work_order_conversations as c')
            ->joinSub($this->latestPerThread(), 'latest', fn ($join) => $join->on('c.id', '=', 'latest.last_id'))
            ->join('work_orders as wo', 'wo.id', '=', 'c.work_order_id')
            ->leftJoin('inbox_thread_reads as r', $this->readMarkerJoin())
            ->select([
                'c.id',
                'c.work_order_id',
                'c.conversation_type',
                'c.vendor_id',
                'c.owner_id',
                'c.message',
                'c.sender_number',
                'c.is_read',
                'c.is_mms',
                'c.twilio_status',
                'c.created_at',
                'r.last_read_conversation_id as last_read_id',
                'wo.work_order_no',
                'wo.status as work_order_status',
                'wo.description as work_order_description',
            ])
            ->when(
                $filters['party'] !== 'all',
                fn ($query) => $query->where('c.conversation_type', $filters['party'])
            )
            ->when(
                $filters['status'] !== 'all',
                fn ($query) => $query->where('c.is_read', false)
                    ->when($courtesyIds !== [], fn ($q) => $q->whereNotIn('c.id', $courtesyIds))
                    ->tap(fn ($q) => $this->notClosed($q))
            )
            ->when(
                $filters['status'] === 'unread',
                fn ($query) => $query->where(fn ($query) => $query
                    ->whereNull('r.id')
                    ->orWhereColumn('c.id', '>', 'r.last_read_conversation_id'))
            )
            ->orderByDesc('c.created_at')
            // Room to spare so the PHP-side search and age filters still have
            // a full page to draw from.
            ->limit(self::THREADS_PER_PAGE * 4)
            ->get();

        $workOrders = WorkOrder::query()
            ->with(['vendors.user', 'owners', 'requested_by'])
            ->whereIn('id', $rows->pluck('work_order_id')->unique())
            ->get()
            ->keyBy('id');

        $now = Carbon::now();

        return $rows
            ->map(fn ($row) => $this->presentThread($row, $workOrders->get($row->work_order_id), $now, $courtesyIds))
            ->when(
                $filters['status'] === 'unanswered_24h',
                fn (Collection $threads) => $threads->where('waiting_hours', '>=', 24)
            )
            ->when(
                filled($filters['search']),
                fn (Collection $threads) => $threads->filter(
                    fn (array $thread) => $this->matchesSearch($thread, $filters['search'])
                )
            )
            ->take(self::THREADS_PER_PAGE)
            ->values();
    }

    /**
     * The newest message of every thread, as a subquery to join against.
     *
     * The work order set is run through Conversation first so the model's
     * global scope applies — the inbox must never widen anyone's visibility.
     */
    private function latestPerThread(): Builder
    {
        $visibleWorkOrderIds = Conversation::query()
            ->select('work_order_id')
            ->distinct()
            ->pluck('work_order_id');

        return DB::table('work_order_conversations')
            ->selectRaw('MAX(id) as last_id')
            ->whereIn('work_order_id', $visibleWorkOrderIds)
            ->groupByRaw("work_order_id, COALESCE(NULLIF(conversation_type, ''), 'unknown'), COALESCE(vendor_id, 0), COALESCE(owner_id, 0)");
    }

    /**
     * The join that pairs a thread's newest message with the signed-in staff
     * user's read marker for that thread, matching the same NULL normalization
     * the thread grouping uses. Each staff member has their own markers, so
     * "unread" is personal — one coordinator opening a thread does not clear
     * it for anyone else.
     */
    /**
     * Threads on closed work orders wait on nobody. NULL-safe: a status-less
     * row keeps counting rather than going silent. Requires `wo` to be joined.
     *
     * @template TQuery of \Illuminate\Database\Query\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function notClosed($query)
    {
        return $query->where(fn ($q) => $q
            ->whereNotIn('wo.status', WorkOrder::CLOSED_STATUSES)
            ->orWhereNull('wo.status'));
    }

    private function readMarkerJoin(): \Closure
    {
        $userId = (int) (auth()->id() ?? 0);

        return function ($join) use ($userId) {
            $join->on('r.work_order_id', '=', 'c.work_order_id')
                ->where('r.user_id', $userId)
                ->whereRaw("r.conversation_type = COALESCE(NULLIF(c.conversation_type, ''), 'unknown')")
                ->whereRaw('r.vendor_id = COALESCE(c.vendor_id, 0)')
                ->whereRaw('r.owner_id = COALESCE(c.owner_id, 0)');
        };
    }

    /**
     * How many threads sit behind each party chip.
     *
     * Counted over every thread rather than the page the list draws, so a chip
     * never reads zero while holding conversations. The status filter is
     * applied here too — is_read false is the inbound marker, and 24h is the
     * same cutoff presentThread derives waiting_hours from — but search is not,
     * because search matches on names resolved in PHP. The page hides these
     * numbers while a search is active rather than showing stale ones.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    private function partyCounts(array $filters): array
    {
        $courtesyIds = $this->courtesyClosers->courtesyIds();

        $counts = DB::table('work_order_conversations as c')
            ->joinSub($this->latestPerThread(), 'latest', fn ($join) => $join->on('c.id', '=', 'latest.last_id'))
            ->when(
                $filters['status'] !== 'all',
                fn ($query) => $query->where('c.is_read', false)
                    ->when($courtesyIds !== [], fn ($q) => $q->whereNotIn('c.id', $courtesyIds))
                    ->join('work_orders as wo', 'wo.id', '=', 'c.work_order_id')
                    ->tap(fn ($q) => $this->notClosed($q))
            )
            ->when(
                $filters['status'] === 'unread',
                fn ($query) => $query
                    ->leftJoin('inbox_thread_reads as r', $this->readMarkerJoin())
                    ->where(fn ($query) => $query
                        ->whereNull('r.id')
                        ->orWhereColumn('c.id', '>', 'r.last_read_conversation_id'))
            )
            ->when(
                $filters['status'] === 'unanswered_24h',
                fn ($query) => $query->where('c.created_at', '<=', Carbon::now()->subDay())
            )
            ->selectRaw('c.conversation_type as party, count(*) as total')
            ->groupBy('c.conversation_type')
            ->get()
            ->pluck('total', 'party');

        $byParty = collect(self::PARTY_FILTERS)
            ->mapWithKeys(fn (string $party) => [$party => (int) $counts->get($party, 0)])
            ->all();

        return ['all' => (int) $counts->sum()] + $byParty;
    }

    /**
     * @param  array<int, int>  $courtesyIds
     * @return array<string, mixed>
     */
    private function presentThread(object $row, ?WorkOrder $workOrder, Carbon $now, array $courtesyIds = []): array
    {
        return [
            'key' => implode(':', [
                $row->work_order_id,
                $row->conversation_type ?: 'unknown',
                $row->vendor_id ?: 0,
                $row->owner_id ?: 0,
            ]),
            'work_order_id' => (int) $row->work_order_id,
            'work_order_no' => $row->work_order_no,
            'work_order_description' => $row->work_order_description,
            'conversation_type' => $row->conversation_type,
            'vendor_id' => $row->vendor_id ? (int) $row->vendor_id : null,
            'owner_id' => $row->owner_id ? (int) $row->owner_id : null,
            'party' => $this->participants->partyLabel($row->conversation_type),
            'counterparty' => $this->participants->counterpartyName($row, $workOrder),
            'preview' => $this->preview($row),
            'last_message_at' => $row->created_at,
            'waiting_hours' => (int) Carbon::parse($row->created_at)->diffInHours($now),
            // is_read is the de facto direction column (see BoardSummaryService):
            // 0 means the message came in from the outside and nobody replied.
            // A judged courtesy closer ("thank you") no longer waits on anyone,
            // and neither does a thread on a closed work order (NULL-safe: a
            // status-less row keeps counting).
            'awaiting' => ! $row->is_read
                && ! in_array((int) $row->id, $courtesyIds, true)
                && ! in_array((string) ($row->work_order_status ?? ''), WorkOrder::CLOSED_STATUSES, true),
            // Unread is personal: the newest message is inbound AND this staff
            // user has not opened the thread since it arrived.
            'unread' => ! $row->is_read
                && ($row->last_read_id === null || (int) $row->id > (int) $row->last_read_id),
            'twilio_status' => $row->twilio_status,
        ];
    }

    /**
     * @param  array<string, mixed>  $thread
     */
    private function matchesSearch(array $thread, string $search): bool
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            $thread['work_order_no'],
            $thread['counterparty'],
            $thread['party'],
            $thread['preview'],
            $thread['work_order_description'],
        ])));

        return str_contains($haystack, mb_strtolower($search));
    }

    /**
     * The number a reply should go to: whoever last wrote in from the outside,
     * else whoever we last sent to.
     *
     * @param  Collection<int, Conversation>  $messages
     */
    private function recipientFor(Collection $messages, WorkOrder $workOrder): ?string
    {
        $ourNumber = $this->participants->phoneKey(
            $this->participants->wocNumberFor($workOrder)
        );

        $inbound = $messages->last(
            fn (Conversation $message) => $this->participants->phoneKey($message->sender_number) !== $ourNumber
        );

        return $inbound?->sender_number ?? $messages->last()?->receiver_number;
    }

    private function preview(object $row): string
    {
        // An inbound tapback reaction quotes the entire original message;
        // "👍 Liked a message" reads better than that wall of text.
        if (! $row->is_read && ($tapback = TapbackDetector::detect($row->message)) !== null) {
            return $tapback['emoji'].' '.$tapback['label'];
        }

        $message = trim(preg_replace('/\s+/', ' ', (string) $row->message));

        if ($message === '') {
            return $row->is_mms ? 'Attachment' : '';
        }

        return mb_strimwidth($message, 0, self::PREVIEW_LENGTH, '…');
    }
}
