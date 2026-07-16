<?php

namespace App\Console\Commands;

use App\Models\Scopes\WorkOrderScope;
use App\Models\ServiceStatus;
use App\Models\TenantUploadToken;
use App\Models\WorkOrder;
use App\Services\TenantPortalLinkService;
use Illuminate\Console\Command;

class SendTenantPortalLinks extends Command
{
    protected $signature = 'tenant-portal:send-links';

    protected $description = 'Send (and re-send) the no-login tenant portal photo link for work orders marked as tenant easy fix';

    /**
     * A scan catches every way a work order can enter the easy-fix status
     * (set by the WOC in PropertyWare and synced in, or changed in-app), so no
     * status-change hook can be missed. The human decides (sets the status);
     * this only automates the sending. Both sends and reminders are idempotent
     * and capped, and the whole thing is gated off by default inside the
     * service, so running this in local/testing texts no one.
     */
    public function handle(TenantPortalLinkService $service): int
    {
        $statusId = ServiceStatus::query()
            ->where('name', 'Checking for Tenant Easy Fix')
            ->value('id');

        if (! $statusId) {
            $this->warn('Service status "Checking for Tenant Easy Fix" not found; nothing to do.');

            return self::SUCCESS;
        }

        // 1) Initial sends: easy-fix work orders that have never been sent a link.
        $workOrders = WorkOrder::withoutGlobalScope(WorkOrderScope::class)
            ->where('service_status_id', $statusId)
            ->whereDoesntHave('tenantUploadTokens', function ($query) {
                $query->where('purpose', TenantUploadToken::PURPOSE_TENANT_EASY_FIX);
            })
            ->get();

        foreach ($workOrders as $workOrder) {
            $service->sendLink($workOrder);
        }

        // 2) Reminders: links sent but not completed, capped, spaced 2+ days apart.
        $tokens = TenantUploadToken::query()
            ->where('purpose', TenantUploadToken::PURPOSE_TENANT_EASY_FIX)
            ->whereNull('completed_at')
            ->where('notified_count', '<', TenantPortalLinkService::MAX_NOTIFICATIONS)
            ->where('last_notified_at', '<=', now()->subWeekdays(2))
            ->get();

        foreach ($tokens as $token) {
            $service->remind($token);
        }

        $this->info(sprintf('Tenant portal links: %d new, %d reminders considered.', $workOrders->count(), $tokens->count()));

        return self::SUCCESS;
    }
}
