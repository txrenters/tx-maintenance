<?php

namespace App\Http\Middleware;

use App\Models\Scopes\WorkOrderScope;
use App\Models\TenantUploadToken;
use App\Models\WorkOrder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantPortalToken
{
    /**
     * Resolve a tenant-portal magic-link token to its work order and inject it
     * into the request. A bad or revoked token 404s so we never reveal whether
     * a token ever existed. Mirrors ResolveVendorPortalToken.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');

        $uploadToken = TenantUploadToken::query()
            ->where('token', $token)
            ->first();

        if (! $uploadToken) {
            abort(404);
        }

        // The magic-link portal is public and gated solely by the token. Bypass
        // the WorkOrderScope global scope so an unrelated logged-in user's scope
        // doesn't cause an otherwise-valid token to resolve to nothing and 404.
        $workOrder = WorkOrder::withoutGlobalScope(WorkOrderScope::class)
            ->with('requested_by')
            ->find($uploadToken->work_order_id);

        if (! $workOrder) {
            abort(404);
        }

        $request->attributes->set('portal_upload_token', $uploadToken);
        $request->attributes->set('portal_work_order', $workOrder);

        return $next($request);
    }
}
