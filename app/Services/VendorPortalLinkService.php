<?php

namespace App\Services;

use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderVendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The single place that knows how a vendor reaches the portal: the per-assignment
 * magic link for one work order, and the vendor's own all-jobs dashboard link.
 *
 * Every caller here is either an automated message or a coordinator convenience,
 * so link() is log-never-throw and returns null; a text simply omits the link
 * line rather than losing the whole notification.
 */
class VendorPortalLinkService
{
    /**
     * How every automated vendor text introduces the portal link. Kept in one
     * place so the wording can be changed without touching the messages.
     */
    public const LINK_LEAD = 'Set the schedule, upload photos or invoices, or message us here — no login needed:';

    /**
     * The link as it appears in a text: its own paragraph, with the URL on its
     * own line so it stays tappable and doesn't run into the sentence before it.
     */
    public static function linkBlock(?string $url): ?string
    {
        return filled($url) ? self::LINK_LEAD."\n".$url : null;
    }

    /**
     * This vendor's access token for this work order, minted on first use so an
     * assignment that predates the token backfill still gets a working link.
     */
    public function tokenFor(WorkOrder $workOrder, Vendor $vendor): ?string
    {
        $token = $workOrder->vendors()
            ->where('vendors.id', $vendor->id)
            ->first()?->pivot?->access_token;

        if (filled($token)) {
            return $token;
        }

        if (! $workOrder->vendors()->where('vendors.id', $vendor->id)->exists()) {
            return null;
        }

        $token = WorkOrderVendor::generateUniqueAccessToken();

        $workOrder->vendors()->updateExistingPivot($vendor->id, ['access_token' => $token]);

        return $token;
    }

    /**
     * This assignment's portal URL, or null when no token could be issued.
     */
    public function link(WorkOrder $workOrder, Vendor $vendor): ?string
    {
        try {
            $token = $this->tokenFor($workOrder, $vendor);

            return $token ? route('vendor.portal.show', $token) : null;
        } catch (\Throwable $exception) {
            Log::error('Vendor portal link could not be issued.', [
                'work_order_id' => $workOrder->id,
                'vendor_id' => $vendor->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The vendor's all-jobs dashboard URL, or null when it could not be issued.
     */
    public function dashboardLink(Vendor $vendor): ?string
    {
        try {
            return route('vendor.portal.dashboard', $vendor->ensurePortalToken());
        } catch (\Throwable $exception) {
            Log::error('Vendor dashboard link could not be issued.', [
                'vendor_id' => $vendor->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Copyable links for every vendor on a work order, for the coordinator's
     * Vendors tab — one link per assignment plus the vendor's all-jobs link.
     *
     * @return Collection<int, array{vendor_id: int, name: ?string, has_email: bool, url: ?string, dashboard_url: ?string}>
     */
    public function linksFor(WorkOrder $workOrder): Collection
    {
        return $workOrder->vendors->map(function (Vendor $vendor) use ($workOrder) {
            $token = $this->ensureLoadedAssignmentToken($workOrder, $vendor);

            return [
                'vendor_id' => $vendor->id,
                'name' => $vendor->name,
                'has_email' => (bool) $vendor->email,
                'url' => $token ? route('vendor.portal.show', $token) : null,
                'dashboard_url' => $this->dashboardLink($vendor),
            ];
        })->values();
    }

    /**
     * The token on an already-loaded pivot, minted on the spot when it is
     * missing. Assignments only got a token if the vendor was assigned through
     * the app — imported ones have none, which used to leave the coordinator
     * looking at a dead "This job" button.
     */
    private function ensureLoadedAssignmentToken(WorkOrder $workOrder, Vendor $vendor): ?string
    {
        $token = $vendor->pivot?->access_token;

        if (filled($token)) {
            return $token;
        }

        try {
            $token = WorkOrderVendor::generateUniqueAccessToken();

            $workOrder->vendors()->updateExistingPivot($vendor->id, ['access_token' => $token]);

            if ($vendor->pivot) {
                $vendor->pivot->access_token = $token;
            }

            return $token;
        } catch (\Throwable $exception) {
            Log::error('Vendor portal link could not be issued.', [
                'work_order_id' => $workOrder->id,
                'vendor_id' => $vendor->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
