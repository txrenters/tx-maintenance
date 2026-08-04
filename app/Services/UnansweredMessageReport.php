<?php

namespace App\Services;

use App\Ai\Agents\UnansweredMessageAgent;
use App\Models\Conversation;
use App\Models\WorkOrder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The "who is still waiting on us" report behind the Inbox summary.
 *
 * Two halves, the same split the board summary uses: hard counts straight from
 * the database, and an AI briefing written over them. The counts are
 * authoritative — if the provider is down or misbehaves the report still shows
 * real figures with a deterministic write-up.
 *
 * The AI is given the actual message text, which is the point: a count says
 * eleven people are waiting, but only the text says one of them has no hot
 * water. It identifies threads by a ref number rather than a work order number
 * so nothing it makes up can reach the page.
 */
class UnansweredMessageReport
{
    /** How long a briefing is reused for an unchanged queue. Refresh bypasses. */
    private const NARRATIVE_CACHE_SECONDS = 600;

    /** How many threads are described in detail, longest-waiting first. */
    private const DETAIL_LIMIT = 12;

    /** How much of the unanswered message the report and the AI see. */
    private const PREVIEW_LENGTH = 220;

    public function __construct(
        private readonly ConversationParticipants $participants,
        private readonly WorkOrderRecommendationService $recommendations,
    ) {}

    /**
     * @return array{ready: bool, provider: ?string}
     */
    public function aiStatus(): array
    {
        return $this->recommendations->aiStatus();
    }

    /**
     * @return array{stats: array<string, mixed>, summary: array<string, mixed>, source: string}
     */
    public function build(bool $refresh = false): array
    {
        $stats = $this->stats();

        // Keyed on the figures themselves, so the briefing is rebuilt exactly
        // when the queue changes and not on a timer. generated_at is excluded
        // deliberately — it changes every second, and hashing it would mean
        // paying for a fresh AI call every time the dialog is opened.
        $cacheKey = 'inbox_unanswered_summary.'.md5(json_encode(
            collect($stats)->except('generated_at')->all()
        ));

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $narrative = Cache::remember(
            $cacheKey,
            self::NARRATIVE_CACHE_SECONDS,
            fn () => $this->narrate($stats)
        );

        return [
            'stats' => $stats,
            'summary' => $narrative['summary'],
            'source' => $narrative['source'],
        ];
    }

    /**
     * Every figure the report quotes.
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $now = Carbon::now();
        $unanswered = $this->unansweredThreads();

        $totals = (clone $unanswered)
            ->selectRaw('COUNT(*) as threads')
            ->selectRaw('COUNT(DISTINCT c.work_order_id) as work_orders')
            ->selectRaw('MIN(c.created_at) as oldest')
            ->first();

        $oldest = filled($totals->oldest ?? null) ? Carbon::parse($totals->oldest) : null;

        return [
            'generated_at' => $now->toIso8601String(),
            'threads' => (int) ($totals->threads ?? 0),
            'work_orders' => (int) ($totals->work_orders ?? 0),
            'oldest_waiting_hours' => $oldest ? (int) $oldest->diffInHours($now) : null,
            'by_party' => $this->byParty($unanswered),
            'by_age' => $this->byAge($unanswered, $now),
            'details' => $this->details($unanswered, $now),
        ];
    }

    /**
     * Threads whose newest message came from the outside.
     *
     * is_read is the de facto direction column — see BoardSummaryService, which
     * derives the same set for a single board. This one spans everything the
     * signed-in user may see: the work order set is filtered through the
     * Conversation model as a subquery, so its global scope applies and no id
     * list is ever pulled into PHP.
     */
    private function unansweredThreads(): Builder
    {
        $latestPerThread = DB::table('work_order_conversations')
            ->selectRaw('MAX(id) as last_id')
            ->whereIn('work_order_id', Conversation::query()->select('work_order_id'))
            ->groupByRaw("work_order_id, COALESCE(NULLIF(conversation_type, ''), 'unknown'), COALESCE(vendor_id, 0), COALESCE(owner_id, 0)");

        return DB::table('work_order_conversations as c')
            ->joinSub($latestPerThread, 'latest', fn ($join) => $join->on('c.id', '=', 'latest.last_id'))
            ->join('work_orders as wo', 'wo.id', '=', 'c.work_order_id')
            ->where('c.is_read', false);
    }

    /**
     * @return array<int, array{party: string, total: int}>
     */
    private function byParty(Builder $unanswered): array
    {
        return (clone $unanswered)
            ->selectRaw('c.conversation_type as conversation_type, COUNT(*) as total')
            ->groupBy('c.conversation_type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'party' => $this->participants->partyLabel($row->conversation_type),
                'total' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * How long people have been waiting, bucketed. Counted in SQL so a long
     * queue never has to be loaded to be measured.
     *
     * @return array<string, int>
     */
    private function byAge(Builder $unanswered, Carbon $now): array
    {
        $fourHours = $now->copy()->subHours(4);
        $oneDay = $now->copy()->subDay();
        $threeDays = $now->copy()->subDays(3);

        $row = (clone $unanswered)
            ->selectRaw(
                'SUM(CASE WHEN c.created_at >= ? THEN 1 ELSE 0 END) as under_4h,'
                .'SUM(CASE WHEN c.created_at < ? AND c.created_at >= ? THEN 1 ELSE 0 END) as under_24h,'
                .'SUM(CASE WHEN c.created_at < ? AND c.created_at >= ? THEN 1 ELSE 0 END) as under_3d,'
                .'SUM(CASE WHEN c.created_at < ? THEN 1 ELSE 0 END) as over_3d',
                [$fourHours, $fourHours, $oneDay, $oneDay, $threeDays, $threeDays]
            )
            ->first();

        return [
            'under_4h' => (int) ($row->under_4h ?? 0),
            'four_to_24h' => (int) ($row->under_24h ?? 0),
            'one_to_three_days' => (int) ($row->under_3d ?? 0),
            'over_three_days' => (int) ($row->over_3d ?? 0),
        ];
    }

    /**
     * The longest-waiting threads, named and quoted.
     *
     * Each carries a ref the AI refers back to, so its commentary can be joined
     * onto a real row instead of trusting it to repeat an identifier.
     *
     * @return array<int, array<string, mixed>>
     */
    private function details(Builder $unanswered, Carbon $now): array
    {
        $rows = (clone $unanswered)
            ->select([
                'c.work_order_id',
                'c.conversation_type',
                'c.vendor_id',
                'c.owner_id',
                'c.message',
                'c.sender_number',
                'c.is_mms',
                'c.created_at',
                'wo.work_order_no',
                'wo.description as work_order_description',
                'wo.is_emergency',
            ])
            ->orderBy('c.created_at')
            ->limit(self::DETAIL_LIMIT)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $workOrders = WorkOrder::query()
            ->with(['vendors.user', 'owners', 'requested_by'])
            ->whereIn('id', $rows->pluck('work_order_id')->unique())
            ->get()
            ->keyBy('id');

        return $rows
            ->values()
            ->map(fn ($row, $index) => [
                'ref' => $index + 1,
                // Same key InboxController builds, so a thread opened from the
                // report is the same row the list already knows about.
                'key' => implode(':', [
                    $row->work_order_id,
                    $row->conversation_type ?: 'unknown',
                    $row->vendor_id ?: 0,
                    $row->owner_id ?: 0,
                ]),
                'work_order_id' => (int) $row->work_order_id,
                'work_order_no' => $row->work_order_no,
                'work_order_description' => Str::limit((string) $row->work_order_description, 120),
                'conversation_type' => $row->conversation_type,
                'vendor_id' => $row->vendor_id ? (int) $row->vendor_id : null,
                'owner_id' => $row->owner_id ? (int) $row->owner_id : null,
                'party' => $this->participants->partyLabel($row->conversation_type),
                'counterparty' => $this->participants->counterpartyName(
                    $row,
                    $workOrders->get($row->work_order_id)
                ),
                'is_emergency' => (bool) $row->is_emergency,
                'waiting_hours' => (int) Carbon::parse($row->created_at)->diffInHours($now),
                'message' => $this->preview($row),
            ])
            ->all();
    }

    private function preview(object $row): string
    {
        $message = trim(preg_replace('/\s+/', ' ', (string) $row->message));

        if ($message === '') {
            return $row->is_mms ? 'Sent an attachment with no text.' : '';
        }

        return Str::limit($message, self::PREVIEW_LENGTH);
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array{summary: array<string, mixed>, source: string}
     */
    private function narrate(array $stats): array
    {
        if ($stats['threads'] === 0) {
            return [
                'summary' => [
                    'headline' => 'Nothing is waiting on a reply. Every conversation has been answered.',
                    'sections' => [],
                    'priorities' => [],
                ],
                'source' => 'empty',
            ];
        }

        if ($this->aiStatus()['ready']) {
            try {
                $response = (new UnansweredMessageAgent)->prompt($this->buildPrompt($stats));

                $headline = trim((string) data_get($response, 'headline', ''));

                if ($headline !== '') {
                    return [
                        'summary' => [
                            'headline' => $headline,
                            'sections' => $this->normalizeSections(data_get($response, 'sections', [])),
                            'priorities' => $this->normalizePriorities(
                                data_get($response, 'priorities', []),
                                $stats['details']
                            ),
                        ],
                        'source' => 'ai',
                    ];
                }

                Log::warning('Unanswered message agent returned an empty headline.');
            } catch (\Throwable $e) {
                Log::warning('Unanswered message summary failed; using computed figures.', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'summary' => $this->fallbackSummary($stats),
            'source' => 'fallback',
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function buildPrompt(array $stats): string
    {
        $age = $stats['by_age'];

        $lines = [
            'UNANSWERED CONVERSATIONS (the other party sent the last message)',
            "- threads waiting: {$stats['threads']}",
            "- work orders involved: {$stats['work_orders']}",
        ];

        if ($stats['oldest_waiting_hours'] !== null) {
            $lines[] = "- the longest has been waiting {$stats['oldest_waiting_hours']} hours";
        }

        $lines[] = '';
        $lines[] = 'WHO IS WAITING';

        foreach ($stats['by_party'] as $party) {
            $lines[] = "- {$party['party']}: {$party['total']}";
        }

        $lines = array_merge($lines, [
            '',
            'HOW LONG THEY HAVE BEEN WAITING',
            "- under 4 hours: {$age['under_4h']}",
            "- 4 to 24 hours: {$age['four_to_24h']}",
            "- 1 to 3 days: {$age['one_to_three_days']}",
            "- more than 3 days: {$age['over_three_days']}",
            '',
            'THE THREADS THEMSELVES, LONGEST WAITING FIRST',
            'Refer to these by ref number only.',
        ]);

        foreach ($stats['details'] as $detail) {
            $lines[] = sprintf(
                '- ref %d | %s | waiting %d hours%s | job: %s | they wrote: "%s"',
                $detail['ref'],
                $detail['party'],
                $detail['waiting_hours'],
                $detail['is_emergency'] ? ' | FLAGGED EMERGENCY' : '',
                $detail['work_order_description'] ?: 'not described',
                $detail['message'] ?: 'no text',
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param  mixed  $sections
     * @return array<int, array{title: string, bullets: array<int, string>}>
     */
    private function normalizeSections($sections): array
    {
        return collect(is_array($sections) ? $sections : [])
            ->map(fn ($section) => [
                'title' => (string) data_get($section, 'title', ''),
                'bullets' => collect(data_get($section, 'bullets', []))
                    ->map(fn ($bullet) => (string) $bullet)
                    ->filter()
                    ->values()
                    ->all(),
            ])
            ->filter(fn ($section) => $section['title'] !== '' && $section['bullets'] !== [])
            ->values()
            ->all();
    }

    /**
     * Joins the AI's commentary back onto the real thread rows by ref.
     *
     * A ref that does not exist is dropped rather than rendered: the identity
     * of a thread always comes from the database, never from the model.
     *
     * @param  mixed  $priorities
     * @param  array<int, array<string, mixed>>  $details
     * @return array<int, array<string, mixed>>
     */
    private function normalizePriorities($priorities, array $details): array
    {
        $byRef = collect($details)->keyBy('ref');

        return collect(is_array($priorities) ? $priorities : [])
            ->map(function ($item) use ($byRef) {
                $thread = $byRef->get((int) data_get($item, 'ref'));

                if ($thread === null) {
                    return null;
                }

                $urgency = Str::lower((string) data_get($item, 'urgency', 'medium'));

                return $thread + [
                    'reason' => (string) data_get($item, 'reason', ''),
                    'next_step' => (string) data_get($item, 'next_step', ''),
                    'urgency' => in_array($urgency, ['low', 'medium', 'high'], true) ? $urgency : 'medium',
                ];
            })
            ->filter()
            ->unique('ref')
            ->values()
            ->all();
    }

    /**
     * The report when AI is unavailable: same shape, same figures, ordered by
     * how long people have been waiting.
     *
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    private function fallbackSummary(array $stats): array
    {
        $age = $stats['by_age'];
        $stale = $age['one_to_three_days'] + $age['over_three_days'];

        $headline = "{$stats['threads']} conversation".($stats['threads'] === 1 ? '' : 's')
            .' waiting on a reply across '.$stats['work_orders'].' work order'
            .($stats['work_orders'] === 1 ? '' : 's').'.';

        if ($stale > 0) {
            $headline .= " {$stale} of them ".($stale === 1 ? 'has' : 'have').' been waiting over a day.';
        }

        $sections = [];

        if ($stats['by_party'] !== []) {
            $sections[] = [
                'title' => 'Who is waiting',
                'bullets' => collect($stats['by_party'])
                    ->map(fn ($party) => "{$party['party']}: {$party['total']}")
                    ->all(),
            ];
        }

        $sections[] = [
            'title' => 'How long',
            'bullets' => array_values(array_filter([
                $age['under_4h'] > 0 ? "{$age['under_4h']} under 4 hours" : null,
                $age['four_to_24h'] > 0 ? "{$age['four_to_24h']} between 4 and 24 hours" : null,
                $age['one_to_three_days'] > 0 ? "{$age['one_to_three_days']} between 1 and 3 days" : null,
                $age['over_three_days'] > 0 ? "{$age['over_three_days']} more than 3 days" : null,
            ])),
        ];

        $priorities = collect($stats['details'])
            ->take(8)
            ->map(fn (array $detail) => $detail + [
                'reason' => 'Waiting '.$this->waitedLabel($detail['waiting_hours'])
                    .' since '.Str::lower($detail['party']).' wrote in.',
                'next_step' => 'Read the thread and reply.',
                'urgency' => $this->fallbackUrgency($detail),
            ])
            ->values()
            ->all();

        return [
            'headline' => $headline,
            'sections' => $sections,
            'priorities' => $priorities,
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function fallbackUrgency(array $detail): string
    {
        if ($detail['is_emergency'] || $detail['waiting_hours'] >= 24) {
            return 'high';
        }

        return $detail['waiting_hours'] >= 4 ? 'medium' : 'low';
    }

    /**
     * Phrased exactly as the Inbox list and the board dialog phrase it, so the
     * same wait never reads two different ways.
     */
    private function waitedLabel(int $hours): string
    {
        if ($hours < 1) {
            return 'under an hour';
        }

        if ($hours < 24) {
            return $hours.'h';
        }

        $days = intdiv($hours, 24);
        $remainder = $hours % 24;

        return $remainder > 0 ? "{$days}d {$remainder}h" : "{$days}d";
    }
}
