<?php

namespace App\Console\Commands;

use App\Models\TenantUploadToken;
use App\Models\WorkOrder;
use App\Services\HoaViolationIntakeService;
use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;

class RelinkHoaViolationTenants extends Command
{
    /**
     * Bell-notification event key; registered in config/staff_notifications.php
     * so it also reaches the desktop client.
     */
    public const NOTIFY_EVENT = 'hoa_tenant_link_missing';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hoa:relink-tenants
        {--dry-run : Report what would be linked without writing anything}
        {--notify : Raise a bell notification for each work order left without a tenant (once per work order)}';

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
        $notify = (bool) $this->option('notify');

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

            // A live run fixes found tenants itself, so only the work orders
            // left tenant-less are worth a bell there; a dry run fixes nothing,
            // so every candidate is.
            if ($notify && ($tenant === null || $dryRun)) {
                $this->notifyMissingTenant($workOrder, $label, $tenant !== null);
            }

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

    /**
     * One bell per affected work order, ever: the nightly scheduled dry-run
     * would otherwise re-raise the same alert every night until someone fixes
     * the link, and repeated identical bells train staff to ignore them.
     */
    private function notifyMissingTenant(WorkOrder $workOrder, string $label, bool $leaseTenantAvailable): void
    {
        $alreadyNotified = Activity::where('event', self::NOTIFY_EVENT)
            ->where('properties->work_order_id', $workOrder->id)
            ->exists();

        if ($alreadyNotified) {
            return;
        }

        $message = $leaseTenantAvailable
            ? 'A lease tenant is on record — run hoa:relink-tenants (without --dry-run) to restore their texts.'
            : 'No lease tenant on record — set Requested By in PropertyWare, then run hoa:relink-tenants.';

        activity()
            ->performedOn($workOrder)
            ->event(self::NOTIFY_EVENT)
            ->withProperties([
                'work_order_id' => $workOrder->id,
                'message' => $message,
                'read' => false,
            ])
            ->log("HOA violation {$label} has no tenant linked — tenant texts are not sending");
    }
}
