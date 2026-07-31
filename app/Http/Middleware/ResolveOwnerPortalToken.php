<?php

namespace App\Http\Middleware;

use App\Models\Owner;
use App\Models\OwnerPortalToken;
use App\Models\Scopes\WorkOrderScope;
use App\Models\WorkOrder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveOwnerPortalToken
{
    /**
     * Resolve an owner-portal magic-link token to its work order and owner and
     * inject both into the request. A bad or revoked token 404s so we never
     * reveal whether a token ever existed. Mirrors ResolveTenantPortalToken.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');

        $portalToken = OwnerPortalToken::query()
            ->where('token', $token)
            ->first();

        if (! $portalToken) {
            abort(404);
        }

        // The magic-link portal is public and gated solely by the token. Bypass
        // the WorkOrderScope global scope so an unrelated logged-in user's scope
        // doesn't cause an otherwise-valid token to resolve to nothing and 404.
        $workOrder = WorkOrder::withoutGlobalScope(WorkOrderScope::class)
            ->with(['building', 'service_status'])
            ->find($portalToken->work_order_id);

        $owner = Owner::query()->find($portalToken->owner_id);

        if (! $workOrder || ! $owner) {
            abort(404);
        }

        $request->attributes->set('portal_token', $portalToken);
        $request->attributes->set('portal_work_order', $workOrder);
        $request->attributes->set('portal_owner', $owner);

        return $next($request);
    }
}
