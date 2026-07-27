<?php

namespace App\Http\Middleware;

use App\Models\Jobber;
use App\Models\JobberJobVendor;
use App\Models\Vendor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveJobberPortalToken
{
    /**
     * Resolve a Jobber-job magic-link token to its single job/vendor assignment
     * and inject it into the request. A bad or revoked token 404s so we never
     * reveal whether a token ever existed.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');

        $assignment = JobberJobVendor::query()
            ->where('access_token', $token)
            ->first();

        if (! $assignment) {
            abort(404);
        }

        $job = Jobber::with(['client', 'property'])->find($assignment->jobber_job_id);
        $vendor = Vendor::with('user')->find($assignment->vendor_id);

        if (! $job || ! $vendor) {
            abort(404);
        }

        $request->attributes->set('portal_assignment', $assignment);
        $request->attributes->set('portal_jobber_job', $job);
        $request->attributes->set('portal_vendor', $vendor);

        return $next($request);
    }
}
