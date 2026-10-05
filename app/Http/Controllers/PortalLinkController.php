<?php

namespace App\Http\Controllers;

use App\Models\Owner;
use App\Models\TenantUploadToken;
use App\Models\WorkOrder;
use App\Services\OwnerPortalLinkService;
use App\Services\TenantPortalLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The WOC's "Portal link" button on the tenant and owner conversation tabs:
 * hands back the no-login portal link so a coordinator can copy it and send it
 * by hand.
 *
 * Built for the move to the new domain, where every link already texted or
 * emailed still points at the old host. The token is the one the automated
 * messages carry (created on first use), so the link is the same portal the
 * person already had, rebuilt on the current APP_URL. Nothing is sent from
 * here.
 */
class PortalLinkController extends Controller
{
    /**
     * @var list<string>
     */
    private const AUDIENCES = ['tenant', 'owner'];

    public function store(Request $request, WorkOrder $workOrder, string $audience, TenantPortalLinkService $tenantLinks, OwnerPortalLinkService $ownerLinks): JsonResponse
    {
        // A portal link is the key to that person's portal: office staff only.
        abort_unless((bool) $request->user()?->isStaff(), 403);

        if (! in_array($audience, self::AUDIENCES, true)) {
            return response()->json(['error' => 'Unknown audience.'], 422);
        }

        return response()->json([
            'links' => $audience === 'tenant'
                ? $this->tenantLinks($workOrder, $tenantLinks)
                : $this->ownerLinks($workOrder, $ownerLinks),
        ]);
    }

    /**
     * The general work order link every automated tenant message carries,
     * plus the HOA link when this violation's tenant was already sent one (it
     * is the link that shows their deadline). Only the general token is ever
     * created here: the HOA and easy-fix tokens drive their own reminders.
     *
     * @return list<array{label: string, url: string}>
     */
    private function tenantLinks(WorkOrder $workOrder, TenantPortalLinkService $tenantLinks): array
    {
        $links = [];
        $general = $tenantLinks->link($workOrder);

        if ($general !== null) {
            $links[] = ['label' => 'Tenant portal link', 'url' => $general];
        }

        $hoaToken = TenantUploadToken::query()
            ->where('work_order_id', $workOrder->id)
            ->where('purpose', TenantUploadToken::PURPOSE_HOA_VIOLATION)
            ->first();

        if ($hoaToken !== null) {
            $links[] = ['label' => 'HOA violation link', 'url' => $tenantLinks->urlFor($hoaToken)];
        }

        return $links;
    }

    /**
     * One link per owner on the work order, largest ownership stake first.
     * An owner with no phone is still listed: the coordinator may be emailing
     * the link.
     *
     * @return list<array{label: string, url: string}>
     */
    private function ownerLinks(WorkOrder $workOrder, OwnerPortalLinkService $ownerLinks): array
    {
        return $workOrder->owners
            ->sortByDesc(fn (Owner $owner): float => (float) $owner->percentage_ownership)
            ->map(fn (Owner $owner): array => [
                'label' => trim($owner->first_name.' '.$owner->last_name) ?: ($owner->name ?: 'Owner'),
                'url' => $ownerLinks->link($workOrder, $owner),
            ])
            ->filter(fn (array $link): bool => $link['url'] !== null)
            ->values()
            ->all();
    }
}
