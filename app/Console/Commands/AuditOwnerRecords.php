<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('owners:audit {--details : List duplicate PropertyWare IDs and their relationship counts} {--notify : Raise a bell notification when the problem counts change}')]
#[Description('Audit owner records and work-order relationships without changing data')]
class AuditOwnerRecords extends Command
{
    /**
     * Bell-notification event key; registered in config/staff_notifications.php
     * so it also reaches the desktop client.
     */
    public const NOTIFY_EVENT = 'owner_data_audit';

    /**
     * app_settings key holding the counts of the last notified run, so a
     * nightly scheduled audit only raises a bell when something changed.
     */
    public const LAST_COUNTS_KEY = 'owner_audit_last_counts';

    public function handle(): int
    {
        $duplicatePropertywareIds = DB::table('owners')
            ->select('propertyware_id', DB::raw('COUNT(*) as owner_count'))
            ->whereNotNull('propertyware_id')
            ->groupBy('propertyware_id')
            ->having('owner_count', '>', 1)
            ->get();

        $sharedEmails = DB::table('owners')
            ->selectRaw('LOWER(TRIM(email)) as normalized_email, COUNT(*) as owner_count')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->groupByRaw('LOWER(TRIM(email))')
            ->having('owner_count', '>', 1)
            ->get();

        $duplicatePivotPairs = DB::query()->fromSub(
            DB::table('work_order_owners')
                ->select('work_order_id', 'owner_id')
                ->groupBy('work_order_id', 'owner_id')
                ->havingRaw('COUNT(*) > 1'),
            'duplicate_owner_links'
        )->count();

        // Owner SMS reads mobile first and falls back to phone; with both blank
        // every text to that owner is a silent no-op (no error, no log). Raw
        // columns on purpose — the Owner model's accessors rewrite the values.
        $ownersMissingPhone = DB::table('owners')
            ->whereIn('id', DB::table('work_order_owners')->select('owner_id'))
            ->where(fn ($query) => $query->whereNull('phone')->orWhere('phone', ''))
            ->where(fn ($query) => $query->whereNull('mobile')->orWhere('mobile', ''))
            ->count();

        $this->table(['Metric', 'Count'], [
            ['Owner records', DB::table('owners')->count()],
            ['Distinct property owners linked through pivot', DB::table('work_order_owners')->distinct()->count('owner_id')],
            ['Distinct management contacts', DB::table('work_orders')->whereNotNull('property_manager_id')->distinct()->count('property_manager_id')],
            ['Duplicate PropertyWare ID groups', $duplicatePropertywareIds->count()],
            ['Shared-email groups (not automatically duplicates)', $sharedEmails->count()],
            ['Duplicate work-order/owner pivot pairs', $duplicatePivotPairs],
            ['Owners with work orders but no phone or mobile', $ownersMissingPhone],
        ]);

        if ($this->option('details') && $duplicatePropertywareIds->isNotEmpty()) {
            $this->table(
                ['PropertyWare ID', 'Rows'],
                $duplicatePropertywareIds->map(fn ($group): array => [
                    $group->propertyware_id,
                    $group->owner_count,
                ])->all(),
            );
        }

        if ($this->option('notify')) {
            $this->notifyWhenCountsChange([
                'duplicate_propertyware_id_groups' => $duplicatePropertywareIds->count(),
                'shared_email_groups' => $sharedEmails->count(),
                'duplicate_pivot_pairs' => $duplicatePivotPairs,
                'owners_missing_phone' => $ownersMissingPhone,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * One summary bell, and only when the counts moved since the last notified
     * run: most of these problems are standing conditions (56% of owners have
     * no phone), and an identical nightly alert would just be ignored.
     *
     * @param  array<string, int>  $counts
     */
    private function notifyWhenCountsChange(array $counts): void
    {
        $stored = AppSetting::getValue(self::LAST_COUNTS_KEY, null);

        if ($stored === $counts) {
            return;
        }

        AppSetting::putValue(self::LAST_COUNTS_KEY, $counts);

        if (array_sum($counts) === 0) {
            Log::info('Owner audit counts changed but everything is clean; no bell raised.', $counts);

            return;
        }

        $labels = [
            'duplicate_propertyware_id_groups' => 'duplicate PropertyWare ID group(s)',
            'shared_email_groups' => 'shared-email group(s)',
            'duplicate_pivot_pairs' => 'duplicate work-order/owner link(s)',
            'owners_missing_phone' => 'owner(s) with work orders but no phone on file',
        ];

        $parts = collect($counts)
            ->filter(fn (int $count): bool => $count > 0)
            ->map(fn (int $count, string $key): string => "{$count} {$labels[$key]}")
            ->values()
            ->implode(', ');

        activity()
            ->event(self::NOTIFY_EVENT)
            ->withProperties([
                'message' => "Nightly owner data audit: {$parts}. Fix phone numbers and duplicates in PropertyWare.",
                'counts' => $counts,
                'read' => false,
            ])
            ->log('Owner data audit found issues');

        $this->info('Counts changed since the last notified run — bell notification raised.');
    }
}
