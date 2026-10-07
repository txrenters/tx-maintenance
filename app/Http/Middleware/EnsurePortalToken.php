<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets only the client portal (tx-client) through, by the bearer token in
 * PORTAL_API_TOKEN. With no token configured, nothing gets through.
 */
class EnsurePortalToken
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('services.portal.token');

        abort_if($token === '' || ! hash_equals($token, (string) $request->bearerToken()), 401);

        return $next($request);
    }
}
