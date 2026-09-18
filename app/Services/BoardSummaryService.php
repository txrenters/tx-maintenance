<?php

namespace App\Services;

use App\Ai\Agents\BoardSummaryAgent;
use App\Models\Conversation;
use App\Models\ServiceStatus;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Builds the "Summary" briefing behind each work order board.
 *
 * Two halves: a set of hard counts taken straight from the database, and a
 * short AI narrative written over those counts. The counts are authoritative —
 * if the AI is unavailable or misbehaves the popup still shows real figures
 * with a deterministic write-up, exactly as the recommendation and HOA features
 * degrade.
 *
 * Everything here is a SQL aggregate. Board payloads exhausted PHP's memory in
 * production twice (2026-07-22, 2026-07-25), so this must never hydrate work
 * order models.
 */
class BoardSummaryService
{
    /**
     * How long an AI narrative is reused for an unchanged board. Reopening the
     * popup is then free; the dialog's Refresh button bypasses this.
     */
    private const NARRATIVE_CACHE_SECONDS = 600;

    /**
     * Twilio statuses that mean the text never landed.
     *
     * @var array<int, string>
     */
    private const FAILED_DELIVERY_STATUSES = ['failed', 'undelivered', 'canceled'];

    /**
     * How many unanswered threads to name for the AI and the popup.
     */
    private const ATTENTION_SAMPLE_LIMIT = 5;

    /**
     * @var array<string, string>
     */
    private const BOARD_LABELS = [
        'main' => 'Work Orders',
        'inspections' => 'Inspections',
        'lawn_service' => 'Lawn Service',
        'turnovers' => 'Turnovers',
        'closed' => 'Closed Work Orders',
        'waiting_on_payment' => 'Waiting on Payment',
        'paid' => 'Paid Work Orders',
        'hoa' => 'HOA Violations',
        'hvac' => 'HVAC',
    ];

    public function __construct(
        private readonly WorkOrderRecommendationService $recommendationService,
        private readonly CourtesyCloserService $courtesyClosers,
    ) {}

    public static function label(string $board): string
    {
        return self::BOARD_LABELS[$board] ?? 'Work Orders';
    }

    /**
     * @return array{ready: bool, provider: ?string}
     */
    public function aiStatus(): array
    {
        return $this->recommendationService->aiStatus();
    }

    /**
     * The full popup payload: counts plus the narrative written over them.
     *
     * @param  array<string, mixed>  $filters
     * @return array{stats: array<string, mixed>, summary: array<string, mixed>, source: string}
     */
    public function summarize(string $board, array $filters, bool $refresh = false): array
    {
        $stats = $this->stats($board, $filters);

        $cacheKey = 'board_summary.'.md5($board.'|'.json_encode($filters).'|'.json_encode($stats));

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $narrative = Cache::remember(
            $cacheKey,
            self::NARRATIVE_CACHE_SECONDS,
            fn () => $this->narrate($board, $stats)
        );

        return [
            'stats' => $stats,
            'summary' => $narrative['summary'],
            'source' => $narrative['source'],
        ];
    }

    /**
     * Every figure the popup reports, scoped to one board and its active filters.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function stats(string $board, array $filters): array
    {
        $todayStart = Carbon::now()->startOfDay();
        // "Last 7 days" includes today, so it reaches back six further days.
        $weekStart = Carbon::now()->subDays(6)->startOfDay();

        return [
            'board' => $board,
            'board_label' => self::label($board),
            'generated_at' => Carbon::now()->toIso8601String(),
            'window' => [
                'today' => $todayStart->toDateString(),
                'week_start' => $weekStart->toDateString(),
            ],
            'work_orders' => $this->workOrderStats($board, $filters, $todayStart, $weekStart),
            'messages' => $this->messageStats($board, $filters, $todayStart, $weekStart),
            'awaiting_reply' => $this->awaitingReplyStats($board, $filters),
        ];
    }

    /**
     * A fresh builder for this board every time — the counts below each need
     * their own, and re-running whereHas closures on a clone is fragile.
     *
     * @param  array<string, mixed>  $filters
     */
    private function workOrders(string $board, array $filters): Builder
    {
        return WorkOrder::query()
            ->scoped()
            ->forBoard($board)
            ->boardFilters($filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function workOrderStats(string $board, array $filters, Carbon $todayStart, Carbon $weekStart): array
    {
        $newStatusId = ServiceStatus::where('name', 'New')->value('id');

        $statusNames = ServiceStatus::pluck('name', 'id');

        $byStatus = $this->workOrders($board, $filters)
            ->select('service_status_id', DB::raw('COUNT(*) as total'))
            ->groupBy('service_status_id')
            ->pluck('total', 'service_status_id')
            ->map(fn ($total, $statusId) => [
                'name' => $statusNames[$statusId] ?? 'Unknown',
                'total' => (int) $total,
            ])
            ->values()
            ->sortByDesc('total')
            ->values()
            ->all();

        $topCategories = $this->workOrders($board, $filters)
            ->select('category', DB::raw('COUNT(*) as total'))
            ->whereNotNull('category')
            ->where('category', '<>', '')
            ->groupBy('category')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row) => ['category' => $row->category, 'total' => (int) $row->total])
            ->all();

        return [
            'total' => $this->workOrders($board, $filters)->count(),
            'created_today' => $this->workOrders($board, $filters)->where('created_date', '>=', $todayStart)->count(),
            'created_last_7_days' => $this->workOrders($board, $filters)->where('created_date', '>=', $weekStart)->count(),
            'awaiting_first_touch' => $newStatusId
                ? $this->workOrders($board, $filters)->where('service_status_id', $newStatusId)->count()
                : 0,
            'in_progress' => $newStatusId
                ? $this->workOrders($board, $filters)->where('service_status_id', '<>', $newStatusId)->count()
                : 0,
            'emergencies' => $this->workOrders($board, $filters)->where('is_emergency', true)->count(),
            'needs_emergency_review' => $this->workOrders($board, $filters)->whereNull('is_emergency')->count(),
            'repeat_issues' => $this->workOrders($board, $filters)->where('is_repeat_issue', true)->count(),
            'by_status' => $byStatus,
            'top_categories' => $topCategories,
        ];
    }

    /**
     * Message counts for this board's work orders.
     *
     * work_order_conversations has no direction column. Nothing in the app ever
     * flips is_read from 0 to 1 — every outbound/automated writer sets it true
     * on insert and every inbound writer leaves it false — so is_read IS the
     * direction: 0 came from a tenant/vendor/owner, 1 we sent.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function messageStats(string $board, array $filters, Carbon $todayStart, Carbon $weekStart): array
    {
        $messages = fn () => Conversation::query()
            ->whereIn('work_order_id', $this->workOrderIdQuery($board, $filters));

        $byType = $messages()
            ->where('created_at', '>=', $weekStart)
            ->select('conversation_type', DB::raw('COUNT(*) as total'))
            ->groupBy('conversation_type')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (string) ($row->conversation_type ?: 'unknown') => (int) $row->total,
            ])
            ->all();

        return [
            'total_today' => $messages()->where('created_at', '>=', $todayStart)->count(),
            'received_today' => $messages()->where('created_at', '>=', $todayStart)->where('is_read', false)->count(),
            'sent_today' => $messages()->where('created_at', '>=', $todayStart)->where('is_read', true)->count(),
            'total_last_7_days' => $messages()->where('created_at', '>=', $weekStart)->count(),
            'received_last_7_days' => $messages()->where('created_at', '>=', $weekStart)->where('is_read', false)->count(),
            'sent_last_7_days' => $messages()->where('created_at', '>=', $weekStart)->where('is_read', true)->count(),
            'by_conversation_last_7_days' => $byType,
            'failed_last_7_days' => $messages()
                ->where('created_at', '>=', $weekStart)
                ->whereRaw($this->failedDeliverySql())
                ->count(),
        ];
    }

    /**
     * Threads whose most recent message came from the outside and so is still
     * sitting unanswered.
     *
     * No column stores this, so it is derived: group every message on this
     * board into its thread, take the newest row in each (MAX(id) — monotonic,
     * and free of created_at ties), and keep the threads whose newest row is
     * inbound. A work order can hold several threads (tenant, owner, and one
     * per vendor), which is why the key carries vendor_id and owner_id.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function awaitingReplyStats(string $board, array $filters): array
    {
        $unanswered = $this->unansweredThreadQuery($board, $filters);

        $totals = (clone $unanswered)
            ->selectRaw('COUNT(*) as threads')
            ->selectRaw('MIN(c.created_at) as oldest')
            ->first();

        $threads = (int) ($totals->threads ?? 0);

        $oldest = filled($totals->oldest ?? null) ? Carbon::parse($totals->oldest) : null;

        $samples = (clone $unanswered)
            ->select('wo.work_order_no', 'c.conversation_type', 'c.created_at')
            ->orderBy('c.created_at')
            ->limit(self::ATTENTION_SAMPLE_LIMIT)
            ->get()
            ->map(fn ($row) => [
                'work_order_no' => $row->work_order_no,
                'party' => $this->partyLabel($row->conversation_type),
                'waiting_hours' => (int) Carbon::parse($row->created_at)->diffInHours(Carbon::now()),
            ])
            ->all();

        return [
            'threads' => $threads,
            'oldest_waiting_hours' => $oldest ? (int) $oldest->diffInHours(Carbon::now()) : null,
            'samples' => $samples,
        ];
    }

    /**
     * The join behind awaitingReplyStats(), shared by its count and its sample
     * list. Uses the query builder directly: the work order set is already
     * restricted by the scoped subquery, and the endpoint is staff-only.
     *
     * @param  array<string, mixed>  $filters
     */
    private function unansweredThreadQuery(string $board, array $filters): \Illuminate\Database\Query\Builder
    {
        $latestPerThread = DB::table('work_order_conversations')
            ->selectRaw('MAX(id) as last_id')
            ->whereIn('work_order_id', $this->workOrderIdQuery($board, $filters))
            ->groupByRaw("work_order_id, COALESCE(NULLIF(conversation_type, ''), 'unknown'), COALESCE(vendor_id, 0), COALESCE(owner_id, 0)");

        $courtesyIds = $this->courtesyClosers->courtesyIds();

        return DB::table('work_order_conversations as c')
            ->joinSub($latestPerThread, 'latest', fn ($join) => $join->on('c.id', '=', 'latest.last_id'))
            ->join('work_orders as wo', 'wo.id', '=', 'c.work_order_id')
            ->where('c.is_read', false)
            ->when($courtesyIds !== [], fn ($query) => $query->whereNotIn('c.id', $courtesyIds));
    }

    /**
     * The board's work order ids as a subquery, so no id list is ever pulled
     * into PHP.
     *
     * @param  array<string, mixed>  $filters
     */
    private function workOrderIdQuery(string $board, array $filters): Builder
    {
        return $this->workOrders($board, $filters)->select('work_orders.id');
    }

    private function failedDeliverySql(): string
    {
        $statuses = collect(self::FAILED_DELIVERY_STATUSES)
            ->map(fn ($status) => "'{$status}'")
            ->implode(', ');

        return "LOWER(COALESCE(twilio_status, '')) IN ({$statuses})";
    }

    private function partyLabel(?string $conversationType): string
    {
        return match ($conversationType) {
            'tenant' => 'Tenant',
            'owner' => 'Owner',
            'vendor' => 'Vendor',
            'vendor_tenant' => 'Vendor–Tenant',
            'vendor_owner' => 'Vendor–Owner',
            default => 'Unknown',
        };
    }

    /**
     * Ask the AI to write the briefing, falling back to a deterministic one.
     *
     * @param  array<string, mixed>  $stats
     * @return array{summary: array<string, mixed>, source: string}
     */
    private function narrate(string $board, array $stats): array
    {
        if ($this->aiStatus()['ready']) {
            try {
                $response = (new BoardSummaryAgent(self::label($board)))
                    ->prompt($this->buildPrompt($stats));

                $headline = trim((string) data_get($response, 'headline', ''));

                if ($headline !== '') {
                    return [
                        'summary' => [
                            'headline' => $headline,
                            'sections' => $this->normalizeSections(data_get($response, 'sections', [])),
                            'attention' => $this->normalizeAttention(data_get($response, 'attention', [])),
                        ],
                        'source' => 'ai',
                    ];
                }

                Log::warning('Board summary agent returned an empty headline.', ['board' => $board]);
            } catch (\Throwable $e) {
                Log::warning('Board summary AI generation failed; using computed figures.', [
                    'board' => $board,
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
        $workOrders = $stats['work_orders'];
        $messages = $stats['messages'];
        $awaiting = $stats['awaiting_reply'];

        $lines = [
            "Board: {$stats['board_label']}",
            "Window: today is {$stats['window']['today']}; the 7-day window starts {$stats['window']['week_start']}.",
            '',
            'WORK ORDERS',
            "- on this board: {$workOrders['total']}",
            "- created today: {$workOrders['created_today']}",
            "- created in the last 7 days: {$workOrders['created_last_7_days']}",
            "- still in the New column (nobody has picked them up): {$workOrders['awaiting_first_touch']}",
            "- past the New column: {$workOrders['in_progress']}",
            "- flagged emergency: {$workOrders['emergencies']}",
            "- emergency not yet decided: {$workOrders['needs_emergency_review']}",
            "- flagged repeat issue: {$workOrders['repeat_issues']}",
        ];

        if ($workOrders['by_status'] !== []) {
            $lines[] = '- by status: '.collect($workOrders['by_status'])
                ->map(fn ($row) => "{$row['name']} {$row['total']}")
                ->implode(', ');
        }

        if ($workOrders['top_categories'] !== []) {
            $lines[] = '- most common categories: '.collect($workOrders['top_categories'])
                ->map(fn ($row) => "{$row['category']} {$row['total']}")
                ->implode(', ');
        }

        $lines = array_merge($lines, [
            '',
            'TEXT MESSAGES (on this board\'s work orders)',
            "- sent and received today: {$messages['total_today']}",
            "- received from tenants/vendors/owners today: {$messages['received_today']}",
            "- sent by the office today: {$messages['sent_today']}",
            "- total in the last 7 days: {$messages['total_last_7_days']}",
            "- received in the last 7 days: {$messages['received_last_7_days']}",
            "- sent in the last 7 days: {$messages['sent_last_7_days']}",
            "- texts that failed to deliver in the last 7 days: {$messages['failed_last_7_days']}",
            '',
            'CONVERSATIONS WAITING ON A REPLY FROM THE OFFICE',
            "- threads whose newest message came from the other party: {$awaiting['threads']}",
        ]);

        if ($awaiting['oldest_waiting_hours'] !== null) {
            $lines[] = "- the longest has been waiting {$awaiting['oldest_waiting_hours']} hours";
        }

        foreach ($awaiting['samples'] as $sample) {
            $lines[] = "- WO #{$sample['work_order_no']} ({$sample['party']}) waiting {$sample['waiting_hours']} hours";
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
     * @param  mixed  $attention
     * @return array<int, array{label: string, detail: string, severity: string}>
     */
    private function normalizeAttention($attention): array
    {
        return collect(is_array($attention) ? $attention : [])
            ->map(function ($item) {
                $severity = Str::lower((string) data_get($item, 'severity', 'low'));

                return [
                    'label' => (string) data_get($item, 'label', ''),
                    'detail' => (string) data_get($item, 'detail', ''),
                    'severity' => in_array($severity, ['low', 'medium', 'high'], true) ? $severity : 'low',
                ];
            })
            ->filter(fn ($item) => $item['label'] !== '')
            ->values()
            ->all();
    }

    /**
     * The briefing when AI is unavailable. Same shape, same figures, written
     * from a template so the popup is never empty.
     *
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    private function fallbackSummary(array $stats): array
    {
        $workOrders = $stats['work_orders'];
        $messages = $stats['messages'];
        $awaiting = $stats['awaiting_reply'];

        $headline = "{$workOrders['total']} work orders on the {$stats['board_label']} board, "
            ."{$workOrders['created_today']} opened today. "
            ."{$messages['total_today']} texts today and {$awaiting['threads']} conversations waiting on a reply.";

        $sections = [
            [
                'title' => 'Work orders',
                'bullets' => array_values(array_filter([
                    "{$workOrders['total']} on this board",
                    "{$workOrders['created_today']} opened today, {$workOrders['created_last_7_days']} in the last 7 days",
                    "{$workOrders['awaiting_first_touch']} still in the New column, {$workOrders['in_progress']} further along",
                    $workOrders['emergencies'] > 0 ? "{$workOrders['emergencies']} flagged as emergencies" : null,
                    $workOrders['repeat_issues'] > 0 ? "{$workOrders['repeat_issues']} flagged as repeat issues" : null,
                ])),
            ],
            [
                'title' => 'Messages',
                'bullets' => array_values(array_filter([
                    "{$messages['total_today']} today — {$messages['received_today']} received, {$messages['sent_today']} sent",
                    "{$messages['total_last_7_days']} in the last 7 days",
                    $messages['failed_last_7_days'] > 0
                        ? "{$messages['failed_last_7_days']} failed to deliver in the last 7 days"
                        : null,
                ])),
            ],
        ];

        $attention = [];

        if ($awaiting['threads'] > 0) {
            $detail = "{$awaiting['threads']} conversation".($awaiting['threads'] === 1 ? '' : 's')
                .' had the last word from the tenant, vendor or owner.';

            if ($awaiting['oldest_waiting_hours'] !== null) {
                $detail .= " The longest has been waiting {$awaiting['oldest_waiting_hours']} hours.";
            }

            $attention[] = [
                'label' => 'Waiting on a reply',
                'detail' => $detail,
                'severity' => 'high',
            ];
        }

        if ($messages['failed_last_7_days'] > 0) {
            $attention[] = [
                'label' => 'Texts that never arrived',
                'detail' => "{$messages['failed_last_7_days']} text".($messages['failed_last_7_days'] === 1 ? '' : 's')
                    .' failed to deliver in the last 7 days.',
                'severity' => 'high',
            ];
        }

        if ($workOrders['needs_emergency_review'] > 0) {
            $attention[] = [
                'label' => 'Emergency not yet decided',
                'detail' => "{$workOrders['needs_emergency_review']} work order".($workOrders['needs_emergency_review'] === 1 ? '' : 's')
                    .' have no emergency decision recorded.',
                'severity' => 'medium',
            ];
        }

        return [
            'headline' => $headline,
            'sections' => $sections,
            'attention' => $attention,
        ];
    }
}
