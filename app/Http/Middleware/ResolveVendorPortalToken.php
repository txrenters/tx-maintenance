<?php

namespace App\Http\Middleware;

use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderVendor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveVendorPortalToken
{
    /**
     * Resolve a vendor-portal magic-link token to its single work-order/vendor
     * assignment and inject it into the request. A bad or revoked token 404s so
     * we never reveal whether a token ever existed.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');

        $assignment = WorkOrderVendor::query()
            ->where('access_token', $token)
            ->first();

        if (! $assignment) {
            abort(404);
        }

        $workOrder = WorkOrder::find($assignment->work_order_id);
        $vendor = Vendor::with('user')->find($assignment->vendor_id);

        if (! $workOrder || ! $vendor) {
            abort(404);
        }

        $request->attributes->set('portal_assignment', $assignment);
        $request->attributes->set('portal_work_order', $workOrder);
        $request->attributes->set('portal_vendor', $vendor);

        return $next($request);
    }
}
