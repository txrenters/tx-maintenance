<?php

namespace App\Console\Commands;

use App\Models\TenantUploadToken;
use App\Models\WorkOrder;
use App\Services\HoaViolationIntakeService;
use Illuminate\Console\Command;

class RelinkHoaViolationTenants extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hoa:relink-tenants
        {--dry-run : Report what would be linked without writing anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Link a requesting tenant onto HOA violation work orders that have none: the PropertyWare create used to come back tenant-less, which silently stopped every tenant text while the vendor escalation still fired. Idempotent; safe to re-run.';

    /**
     * Execute the console command.
     */
    public function handle(HoaViolationIntakeService $intake): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $workOrders = WorkOrder::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->whereHas('tenantUploadTokens', function ($tokens) {
                $tokens->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION);
            })
            ->orderBy('id')
            ->get();

        if ($workOrders->isEmpty()) {
            $this->info('Every HOA violation work order already has a tenant linked.');

            return self::SUCCESS;
        }

        $linked = 0;
        $noTenant = 0;

        foreach ($workOrders as $workOrder) {
            $label = 'WO#'.($workOrder->work_order_no ?? $workOrder->id);

            $tenant = $intake->leaseTenantFor($workOrder);

            if ($tenant === null) {
                $noTenant++;
                $this->warn("{$label}: no lease tenant on record — link one by setting Requested By in PropertyWare.");

                continue;
            }

            $name = trim(($tenant->first_name ?? '').' '.($tenant->last_name ?? ''));
            $phone = $tenant->mobile_phone ?: $tenant->home_phone ?: 'no phone on file';

            if ($dryRun) {
                $this->line("{$label}: would link {$name} ({$phone}).");
            } else {
                $intake->linkTenantFromLease($workOrder);
                $this->line("{$label}: linked {$name} ({$phone}).");
            }

            $linked++;
        }

        $verb = $dryRun ? 'Would link' : 'Linked';
        $this->info("{$verb} {$linked} work order(s). No lease tenant found: {$noTenant}.");

        if (! $dryRun && $linked > 0) {
            $this->comment('Open violations without a vendor or completed photos resume tenant texts at the next hoa:send-reminders run (10:10 America/Chicago). Pause any you would rather handle by vendor via the tenant tab\'s Automation toggle first.');
        }

        return self::SUCCESS;
    }
}
