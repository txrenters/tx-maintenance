<?php

namespace App\Console\Commands;

use App\Models\Scopes\WorkOrderScope;
use App\Models\WorkOrder;
use App\Services\TenantEasyFixService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Signature('easy-fix:audit {--days=90 : How many days of work orders to scan, by creation date} {--csv= : Also write the rows to this path on the local disk (e.g. easy-fix-audit.csv)} {--all : List every scanned work order, not only the matches} {--tagged : Re-judge the OPEN work orders already tagged an easy fix (with the AI judge) and list the ones the verdict now disagrees with}')]
#[Description('List recent work orders the tenant easy-fix rules would have judged a tenant easy fix, without changing data')]
class AuditTenantEasyFix extends Command
{
    /**
     * A read-only pass over recent work orders with the same rules the intake
     * automation uses, so operations can review which requests would get the
     * how-to video (and which the rules miss or over-match) before the texts
     * are switched on. Never writes a verdict.
     */
    public function handle(TenantEasyFixService $service): int
    {
        if ($this->option('tagged')) {
            return $this->auditTagged($service);
        }

        $days = max(1, (int) $this->option('days'));

        $workOrders = WorkOrder::withoutGlobalScope(WorkOrderScope::class)
            ->with(['vendors:vendors.id,vendors.name', 'service_status:id,name'])
            ->where('created_date', '>=', now()->subDays($days))
            ->orderByDesc('created_date')
            ->get();

        $rows = [];
        $counts = ['easy_fix' => 0, 'scanned' => $workOrders->count()];

        foreach ($workOrders as $workOrder) {
            $judgement = $service->judge($workOrder);
            $matched = $judgement['key'] !== null;

            if ($matched) {
                $counts['easy_fix']++;
            }

            if (! $matched && ! $this->option('all')) {
                continue;
            }

            $rows[] = [
                'work_order_no' => $workOrder->work_order_no,
                'created' => Str::limit((string) $workOrder->created_date, 10, ''),
                'category' => (string) $workOrder->category,
                'description' => Str::limit(trim((string) preg_replace('/\s+/', ' ', (string) $workOrder->description)), 200),
                'verdict' => $matched ? 'easy_fix' : '',
                'item' => $judgement['key'] ?? '',
                'reason' => $judgement['reason'],
                'vendor' => $workOrder->vendors->pluck('name')->implode('; '),
                'service_status' => (string) ($workOrder->service_status?->name ?? ''),
                'status' => (string) $workOrder->status,
            ];
        }

        $this->table(['Metric', 'Count'], [
            ['Work orders scanned (last '.$days.' days)', $counts['scanned']],
            ['Would be texted the easy-fix how-to', $counts['easy_fix']],
        ]);

        if ($rows !== []) {
            $this->table(
                ['WO#', 'Created', 'Category', 'Verdict', 'Item', 'Reason', 'Vendor', 'Service status'],
                array_map(fn (array $row): array => [
                    $row['work_order_no'],
                    $row['created'],
                    Str::limit($row['category'], 24),
                    $row['verdict'],
                    $row['item'],
                    Str::limit($row['reason'], 40),
                    Str::limit($row['vendor'], 24),
                    Str::limit($row['service_status'], 30),
                ], $rows),
            );
        }

        $csvPath = $this->option('csv');

        if (filled($csvPath)) {
            Storage::disk('local')->put($csvPath, $this->csv($rows));
            $this->info('CSV written to '.Storage::disk('local')->path($csvPath).' ('.count($rows).' rows).');
        }

        return self::SUCCESS;
    }

    /**
     * The open work orders already carrying an easy-fix tag, judged again
     * with today's rules (keywords + AI judge), listing the ones whose stored
     * tag the fresh verdict no longer agrees with - the candidates to untag.
     * Read-only like the rest of the audit: clearing a tag is a separate,
     * deliberate step.
     */
    private function auditTagged(TenantEasyFixService $service): int
    {
        if (! $service->aiJudgeEnabled()) {
            $this->warn('The AI judge is off or no AI provider is configured: the fresh verdict below is the keywords alone.');
        }

        $workOrders = WorkOrder::withoutGlobalScope(WorkOrderScope::class)
            ->with(['vendors:vendors.id,vendors.name', 'service_status:id,name'])
            ->whereNotNull('easy_fix_key')
            ->where('status', 'Open')
            ->orderByDesc('created_date')
            ->get();

        $rows = [];
        $disagree = 0;

        foreach ($workOrders as $workOrder) {
            $judgement = $service->judge($workOrder);
            $agrees = $judgement['key'] === $workOrder->easy_fix_key;

            if (! $agrees) {
                $disagree++;
            }

            if ($agrees && ! $this->option('all')) {
                continue;
            }

            $rows[] = [
                'work_order_no' => $workOrder->work_order_no,
                'created' => Str::limit((string) $workOrder->created_date, 10, ''),
                'category' => (string) $workOrder->category,
                'description' => Str::limit(trim((string) preg_replace('/\s+/', ' ', (string) $workOrder->description)), 200),
                'verdict' => $agrees ? 'agrees' : 'disagrees',
                'stored_item' => (string) $workOrder->easy_fix_key,
                'item' => $judgement['key'] ?? '',
                'reason' => $judgement['reason'],
                'vendor' => $workOrder->vendors->pluck('name')->implode('; '),
                'service_status' => (string) ($workOrder->service_status?->name ?? ''),
                'status' => (string) $workOrder->status,
            ];
        }

        $this->table(['Metric', 'Count'], [
            ['Open work orders tagged an easy fix', $workOrders->count()],
            ['Fresh verdict disagrees with the tag', $disagree],
        ]);

        if ($rows !== []) {
            $this->table(
                ['WO#', 'Created', 'Verdict', 'Tagged as', 'Fresh verdict', 'Reason', 'Service status'],
                array_map(fn (array $row): array => [
                    $row['work_order_no'],
                    $row['created'],
                    $row['verdict'],
                    $row['stored_item'],
                    $row['item'] === '' ? 'not an easy fix' : $row['item'],
                    Str::limit($row['reason'], 70),
                    Str::limit($row['service_status'], 30),
                ], $rows),
            );
        }

        $csvPath = $this->option('csv');

        if (filled($csvPath)) {
            Storage::disk('local')->put($csvPath, $this->csv($rows, ['work_order_no', 'created', 'category', 'description', 'verdict', 'stored_item', 'item', 'reason', 'vendor', 'service_status', 'status']));
            $this->info('CSV written to '.Storage::disk('local')->path($csvPath).' ('.count($rows).' rows).');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>|null  $headers
     */
    private function csv(array $rows, ?array $headers = null): string
    {
        $handle = fopen('php://temp', 'r+');
        $headers ??= ['work_order_no', 'created', 'category', 'description', 'verdict', 'item', 'reason', 'vendor', 'service_status', 'status'];

        fputcsv($handle, $headers, ',', '"', '\\');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $column) => (string) ($row[$column] ?? ''), $headers), ',', '"', '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
