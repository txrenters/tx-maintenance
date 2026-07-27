<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('owners:audit {--details : List duplicate PropertyWare IDs and their relationship counts}')]
#[Description('Audit owner records and work-order relationships without changing data')]
class AuditOwnerRecords extends Command
{
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

        $this->table(['Metric', 'Count'], [
            ['Owner records', DB::table('owners')->count()],
            ['Distinct property owners linked through pivot', DB::table('work_order_owners')->distinct()->count('owner_id')],
            ['Distinct management contacts', DB::table('work_orders')->whereNotNull('property_manager_id')->distinct()->count('property_manager_id')],
            ['Duplicate PropertyWare ID groups', $duplicatePropertywareIds->count()],
            ['Shared-email groups (not automatically duplicates)', $sharedEmails->count()],
            ['Duplicate work-order/owner pivot pairs', DB::query()->fromSub(
                DB::table('work_order_owners')
                    ->select('work_order_id', 'owner_id')
                    ->groupBy('work_order_id', 'owner_id')
                    ->havingRaw('COUNT(*) > 1'),
                'duplicate_owner_links'
            )->count()],
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

        return self::SUCCESS;
    }
}
