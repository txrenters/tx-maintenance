<?php

namespace App\Http\Middleware;

use App\Models\Vendor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveVendorAccountToken
{
    /**
     * Resolve a vendor dashboard magic-link token to its vendor and inject it
     * into the request. A bad token 404s so we never reveal whether it existed.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('vendorToken');

        $vendor = Vendor::query()
            ->with('user')
            ->where('portal_token', $token)
            ->first();

        if (! $vendor) {
            abort(404);
        }

        $request->attributes->set('portal_vendor', $vendor);

        return $next($request);
    }
}
