<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\WorkOrder;
use App\Services\ConversationParticipants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

    private const STATUS_FILTERS = ['all', 'awaiting', 'unanswered_24h'];

    public function __construct(private ConversationParticipants $participants) {}

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

        $threads = $this->threads($filters);

        return inertia('Inbox/Index', [
            'title' => 'Inbox',
            'threads' => $threads,
            'filters' => $filters,
            'stats' => [
                'total' => $threads->count(),
                'awaiting' => $threads->where('awaiting', true)->count(),
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

        return response()->json([
            'messages' => $messages,
            'work_order' => $workOrder,
            'participants' => $this->participants->mapFor($workOrder),
            'woc_phone_number' => $this->participants->wocNumberFor($workOrder),
            'recipient_number' => $this->recipientFor($messages, $workOrder),
        ]);
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
        // Run the work order set through Conversation so the model's global
        // scope applies; the inbox must never widen anyone's visibility.
        $visibleWorkOrderIds = Conversation::query()
            ->select('work_order_id')
            ->distinct()
            ->pluck('work_order_id');

        $latestPerThread = DB::table('work_order_conversations')
            ->selectRaw('MAX(id) as last_id')
            ->whereIn('work_order_id', $visibleWorkOrderIds)
            ->groupByRaw("work_order_id, COALESCE(NULLIF(conversation_type, ''), 'unknown'), COALESCE(vendor_id, 0), COALESCE(owner_id, 0)");

        $rows = DB::table('work_order_conversations as c')
            ->joinSub($latestPerThread, 'latest', fn ($join) => $join->on('c.id', '=', 'latest.last_id'))
            ->join('work_orders as wo', 'wo.id', '=', 'c.work_order_id')
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
                'wo.work_order_no',
                'wo.description as work_order_description',
            ])
            ->when(
                $filters['party'] !== 'all',
                fn ($query) => $query->where('c.conversation_type', $filters['party'])
            )
            ->when(
                $filters['status'] !== 'all',
                fn ($query) => $query->where('c.is_read', false)
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
            ->map(fn ($row) => $this->presentThread($row, $workOrders->get($row->work_order_id), $now))
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
     * @return array<string, mixed>
     */
    private function presentThread(object $row, ?WorkOrder $workOrder, Carbon $now): array
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
            'awaiting' => ! $row->is_read,
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
        $message = trim(preg_replace('/\s+/', ' ', (string) $row->message));

        if ($message === '') {
            return $row->is_mms ? 'Attachment' : '';
        }

        return mb_strimwidth($message, 0, self::PREVIEW_LENGTH, '…');
    }
}
