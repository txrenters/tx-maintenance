<?php

namespace App\Console\Commands;

use App\Models\Scopes\WorkOrderScope;
use App\Models\WorkOrder;
use App\Services\PropertyWareService;
use App\Services\WorkOrderService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Backstop for the PropertyWare intake: finds the work orders PropertyWare
 * has that the app does not, and imports them.
 *
 * The 10-minute fast lane (import:work-orders) only ever sees the newest
 * SOAP pages, and a payload it cannot process is retried every run until it
 * ages out of that window — after which nothing ever picks the work order
 * up. There is no PropertyWare webhook. This command walks the REST listing
 * (one call per 500 work orders, newest first), diffs each page against
 * work_orders.propertyware_id, and imports every missing work order through
 * WorkOrderService — the same SOAP-by-number path as the board's Import
 * Work Order button — because tenant, lease, owner and notes only come from
 * SOAP.
 *
 * Automations are tiered by how fresh the work order is (see decideTier), so
 * a historical backlog never texts anybody, and a work order that still
 * cannot be imported is raised in the bell with the reason instead of being
 * skipped in silence.
 */
class ImportMissingWorkOrders extends Command
{
    public const TIER_FULL = 'full';

    public const TIER_QUIET = 'quiet';

    public const TIER_SILENT = 'silent';

    private const TIER_LABELS = [
        self::TIER_FULL => 'with the intake messages',
        self::TIER_QUIET => 'AI recommendation only',
        self::TIER_SILENT => 'record only',
    ];

    private const FAILED_ALERT_KEY = 'import-missing-work-orders:failed:';

    private const SOAP_DOWN_ALERT_KEY = 'import-missing-work-orders:soap-down';

    private const TABLE_ROW_CAP = 200;

    private const BELL_NUMBER_CAP = 20;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:missing-work-orders
        {--days=7 : Stop walking once a REST page is older than this many days (ignored with --all); the newest page is always checked}
        {--all : Walk every PropertyWare work order}
        {--dry-run : Report what is missing and how each would import; change nothing}
        {--limit=50 : Maximum SOAP import attempts per run, 0 for no cap; the rest is reported as pending}
        {--fresh-hours=48 : Only an open, unassigned work order newer than this gets the full intake automations}
        {--grace-minutes=15 : Leave work orders newer than this to import:work-orders}
        {--page-size=500 : REST page size (the PropertyWare maximum; smaller only for tests)}
        {--max-pages=100 : Safety cap on REST pages per run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find PropertyWare work orders missing from the local database and import them via SOAP. Scheduled every half hour (newest pages) and nightly (--all); safe to re-run.';

    /**
     * Execute the console command.
     */
    public function handle(PropertyWareService $propertyWare, WorkOrderService $workOrders): int
    {
        $startedAt = microtime(true);

        $dryRun = (bool) $this->option('dry-run');
        $all = (bool) $this->option('all');
        $days = max(1, (int) $this->option('days'));
        $limit = max(0, (int) $this->option('limit'));
        $freshHours = max(0, (int) $this->option('fresh-hours'));
        $graceMinutes = max(0, (int) $this->option('grace-minutes'));
        $pageSize = max(1, min(500, (int) $this->option('page-size')));
        $maxPages = max(1, (int) $this->option('max-pages'));

        $now = now();
        $cutoff = $now->copy()->subDays($days);
        $graceEdge = $now->copy()->subMinutes($graceMinutes);
        $freshEdge = $now->copy()->subHours($freshHours);

        $summary = [
            'mode' => $dryRun ? 'dry-run' : 'import',
            'scope' => $all ? 'all' : "days:{$days}",
            'pages' => 0,
            'pages_failed' => 0,
            'scanned' => 0,
            'missing' => 0,
            'too_fresh' => 0,
            'undated' => 0,
            'imported' => [self::TIER_FULL => [], self::TIER_QUIET => [], self::TIER_SILENT => []],
            'imported_count' => 0,
            'failed' => [],
            'not_in_soap' => [],
            'no_number' => [],
            'id_mismatch' => [],
            'pending' => 0,
            'soap_unreachable' => false,
            'duration_ms' => 0,
        ];

        $candidates = [];
        $seen = [];
        $attempts = 0;
        $offset = 0;

        $this->info(sprintf(
            'Checking PropertyWare for work orders missing locally%s (%s)...',
            $dryRun ? ' [dry-run]' : '',
            $summary['scope'],
        ));

        while ($summary['pages'] < $maxPages) {
            $page = $propertyWare->fetchWorkOrdersPage($pageSize, $offset);
            $summary['pages']++;
            $offset += $pageSize;

            if ($page === null) {
                // Skip the batch and keep walking, like the sibling walkers;
                // the next run sees this page again.
                $summary['pages_failed']++;

                continue;
            }

            if ($page === []) {
                break;
            }

            $summary['scanned'] += count($page);

            // Offset paging over a newest-first list can show a record twice
            // when a work order lands mid-walk; never import one twice a run.
            $records = [];

            foreach ($page as $record) {
                $record = (array) $record;
                $pwId = (int) ($record['id'] ?? 0);

                if ($pwId <= 0 || isset($seen[$pwId])) {
                    continue;
                }

                $seen[$pwId] = true;
                $records[$pwId] = $record;
            }

            $present = WorkOrder::withoutGlobalScope(WorkOrderScope::class)
                ->whereIn('propertyware_id', array_keys($records))
                ->pluck('propertyware_id')
                ->map(fn ($id) => (int) $id)
                ->flip();

            $oldestOnPage = null;

            foreach ($records as $pwId => $record) {
                $created = PropertyWareService::parseDate($record['createdDateTime'] ?? null);

                if ($created !== null && ($oldestOnPage === null || $created->lt($oldestOnPage))) {
                    $oldestOnPage = $created;
                }

                if ($present->has($pwId)) {
                    continue;
                }

                if ($created === null) {
                    $summary['undated']++;
                } elseif ($created->gt($graceEdge)) {
                    // Younger than a fast-lane cycle: import:work-orders is
                    // probably about to bring it in with its own intake.
                    $summary['too_fresh']++;

                    continue;
                }

                $number = (int) ($record['number'] ?? 0);
                $tier = $this->decideTier($record, $created, $freshEdge);

                $candidate = [
                    'propertyware_id' => $pwId,
                    'number' => $number > 0 ? $number : null,
                    'created' => $created?->toDateTimeString() ?? '(unreadable)',
                    'status' => trim((string) ($record['status'] ?? '')),
                    'tier' => $tier,
                    'result' => $dryRun ? 'would import' : 'pending',
                ];

                $summary['missing']++;

                if ($dryRun) {
                    $candidates[] = $candidate;

                    continue;
                }

                if ($summary['soap_unreachable'] || ($limit > 0 && $attempts >= $limit)) {
                    $summary['pending']++;
                    $candidates[] = $candidate;

                    continue;
                }

                $attempts++;
                $candidate['result'] = $this->importOne($candidate, $propertyWare, $workOrders, $summary);
                $candidates[] = $candidate;
            }

            if ($summary['soap_unreachable']) {
                // PropertyWare is unhealthy: stop hammering it, retry next run.
                break;
            }

            if (count($page) < $pageSize) {
                break;
            }

            if (! $all && $oldestOnPage !== null && $oldestOnPage->lt($cutoff)) {
                break;
            }
        }

        $summary['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

        if (! $dryRun && $summary['imported_count'] > 0) {
            $this->alertImported($summary);
        }

        Log::info('import:missing-work-orders finished', $summary);

        $this->report($summary, $candidates, $dryRun);

        if ($summary['soap_unreachable']) {
            $this->error('PropertyWare SOAP did not answer; the remaining work orders are retried on the next run.');

            return self::FAILURE;
        }

        if ($summary['pages'] > 0 && $summary['pages_failed'] === $summary['pages']) {
            $this->error('PropertyWare REST never answered.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Which automations a missing work order earns, judged from the REST
     * listing alone (no extra calls):
     *
     * - full: still Open, nobody assigned in PropertyWare, created within
     *   --fresh-hours — the intake the fast lane would have run.
     * - silent: closed or completed — the row only, no jobs at all (the AI
     *   classification is a paid call that regenerates tasks and can raise an
     *   emergency alert; wrong for history).
     * - quiet: everything else (older, undated, already has a vendor, or an
     *   unknown status) — the AI recommendation only, no messages.
     *
     * @param  array<string, mixed>  $record
     */
    private function decideTier(array $record, ?Carbon $created, Carbon $freshEdge): string
    {
        $status = trim((string) ($record['status'] ?? ''));
        $completed = ! blank($record['completedDate'] ?? null);
        $closed = in_array(Str::lower($status), array_map(Str::lower(...), WorkOrder::CLOSED_STATUSES), true);

        if ($completed || $closed) {
            return self::TIER_SILENT;
        }

        $assigned = ! empty($record['assignedVendors']);

        if (Str::lower($status) === 'open' && ! $assigned && $created !== null && $created->gte($freshEdge)) {
            return self::TIER_FULL;
        }

        return self::TIER_QUIET;
    }

    /**
     * Fetch one missing work order from SOAP by number and import it at its
     * tier. Returns the short result shown in the run's table; the summary
     * buckets are updated in place.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $summary
     */
    private function importOne(array $candidate, PropertyWareService $propertyWare, WorkOrderService $workOrders, array &$summary): string
    {
        $pwId = $candidate['propertyware_id'];
        $number = $candidate['number'];
        $tier = $candidate['tier'];

        if ($number === null) {
            $summary['no_number'][] = $pwId;
            $this->alertImportFailed($pwId, null, 'no_number', 'PropertyWare lists the work order without a number, so it cannot be fetched by number.');

            return 'no number';
        }

        $payloads = $propertyWare->getWorkOrderByNumber($number);

        // getWorkOrderByNumber returns an 'Error: ...' string when the SOAP
        // call fails; anything non-array means PropertyWare never answered.
        if (! is_array($payloads)) {
            $summary['soap_unreachable'] = true;
            $summary['pending']++;
            $this->alertSoapUnreachable(is_string($payloads) ? $payloads : 'no response');

            return 'PropertyWare unreachable';
        }

        if ($payloads === []) {
            $summary['not_in_soap'][] = $number;
            $this->alertImportFailed($pwId, $number, 'not_in_soap', 'PropertyWare lists the work order but the SOAP lookup by number returned nothing.');

            return 'not found via SOAP';
        }

        try {
            $ids = match ($tier) {
                self::TIER_FULL => $workOrders->handle($payloads, dispatchNewWorkOrderAutomations: true),
                self::TIER_QUIET => $workOrders->handle($payloads),
                default => $workOrders->handle($payloads, dispatchRecommendation: false),
            };
        } catch (\Throwable $exception) {
            $summary['failed'][] = [
                'number' => $number,
                'propertyware_id' => $pwId,
                'error' => $exception->getMessage(),
            ];
            $this->alertImportFailed($pwId, $number, 'exception', $exception->getMessage());

            return 'failed: '.Str::limit($exception->getMessage(), 80);
        }

        // Cheap insurance that the SOAP lookup by number returned the work
        // order the REST listing named, and that the row really landed.
        $landed = $ids !== [] && WorkOrder::withoutGlobalScope(WorkOrderScope::class)
            ->whereKey($ids)
            ->where('propertyware_id', $pwId)
            ->exists();

        if (! $landed) {
            $summary['id_mismatch'][] = $number;
            $this->alertImportFailed($pwId, $number, 'id_mismatch', "The SOAP payload for work order #{$number} did not carry PropertyWare id {$pwId}.");

            return 'mismatch';
        }

        $summary['imported'][$tier][] = $number;
        $summary['imported_count']++;

        return "imported ({$tier})";
    }

    /**
     * One bell notice per run naming what was backfilled, so staff learn of
     * work orders that reached the board without the usual intake.
     *
     * @param  array<string, mixed>  $summary
     */
    private function alertImported(array $summary): void
    {
        $numbers = array_merge(...array_values($summary['imported']));
        $count = $summary['imported_count'];

        $tiers = collect($summary['imported'])
            ->filter()
            ->map(fn (array $tierNumbers, string $tier) => count($tierNumbers).' '.self::TIER_LABELS[$tier])
            ->implode(', ');

        $listed = implode(', ', array_map(fn (int $number) => '#'.$number, array_slice($numbers, 0, self::BELL_NUMBER_CAP)));
        $more = count($numbers) > self::BELL_NUMBER_CAP ? ' and '.(count($numbers) - self::BELL_NUMBER_CAP).' more' : '';
        $pending = $summary['pending'] > 0 ? " {$summary['pending']} more still pending for the next run." : '';

        try {
            activity()
                ->event('missing_work_orders_imported')
                ->withProperties([
                    'message' => "PropertyWare had {$count} work order(s) the app did not: {$listed}{$more} ({$tiers}).{$pending}",
                    'work_order_numbers' => $numbers,
                    'read' => false,
                ])
                ->log("{$count} work order(s) backfilled from PropertyWare");
        } catch (\Throwable $exception) {
            Log::error('Failed to raise the missing work orders imported alert.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * One bell notice per work order per day naming why it cannot be
     * imported — the reason a work order stays missing used to live only in
     * the log.
     */
    private function alertImportFailed(int $pwId, ?int $number, string $reason, string $detail): void
    {
        if (! Cache::add(self::FAILED_ALERT_KEY.$pwId, true, now()->addDay())) {
            return;
        }

        $label = $number !== null ? "Work order #{$number}" : "PropertyWare work order {$pwId}";

        try {
            activity()
                ->event('work_order_import_failed')
                ->withProperties([
                    'message' => "{$label} exists in PropertyWare but could not be imported ({$reason}): ".Str::limit($detail, 200),
                    'work_order_no' => $number,
                    'propertyware_id' => $pwId,
                    'reason' => $reason,
                    'read' => false,
                ])
                ->log("{$label} could not be imported from PropertyWare");
        } catch (\Throwable $exception) {
            Log::error('Failed to raise the work order import failed alert.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * One bell notice per outage: the half-hourly run would otherwise nag
     * for as long as PropertyWare is down.
     */
    private function alertSoapUnreachable(string $detail): void
    {
        if (! Cache::add(self::SOAP_DOWN_ALERT_KEY, true, now()->addHours(6))) {
            return;
        }

        try {
            activity()
                ->event('propertyware_unreachable')
                ->withProperties([
                    'message' => 'PropertyWare did not answer the SOAP lookup while importing missing work orders ('.Str::limit($detail, 200).'). The remaining work orders are retried on the next run.',
                    'read' => false,
                ])
                ->log('PropertyWare is not answering');
        } catch (\Throwable $exception) {
            Log::error('Failed to raise the PropertyWare unreachable alert.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, array<string, mixed>>  $candidates
     */
    private function report(array $summary, array $candidates, bool $dryRun): void
    {
        if ($candidates !== []) {
            $this->table(
                ['PW id', 'WO#', 'Created', 'Status', 'Tier', 'Result'],
                array_map(fn (array $candidate) => [
                    $candidate['propertyware_id'],
                    $candidate['number'] ?? '-',
                    $candidate['created'],
                    $candidate['status'],
                    $candidate['tier'],
                    $candidate['result'],
                ], array_slice($candidates, 0, self::TABLE_ROW_CAP)),
            );

            if (count($candidates) > self::TABLE_ROW_CAP) {
                $this->line('... and '.(count($candidates) - self::TABLE_ROW_CAP).' more');
            }
        }

        $prefix = $dryRun ? '[dry-run] ' : '';

        $this->info($prefix."Pages: {$summary['pages']}");
        $this->info($prefix."Pages failed: {$summary['pages_failed']}");
        $this->info($prefix."Scanned: {$summary['scanned']}");
        $this->info($prefix."Missing: {$summary['missing']}");
        $this->info($prefix."Too fresh: {$summary['too_fresh']}");
        $this->info($prefix."Undated: {$summary['undated']}");
        $this->info($prefix."Imported: {$summary['imported_count']}");
        $this->info($prefix.'Failed: '.count($summary['failed']));
        $this->info($prefix.'Not in SOAP: '.count($summary['not_in_soap']));
        $this->info($prefix.'No number: '.count($summary['no_number']));
        $this->info($prefix.'Mismatch: '.count($summary['id_mismatch']));
        $this->info($prefix."Pending: {$summary['pending']}");
    }
}
