<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class UpgradeToHttpsUnderNgrok
{
    public function handle(Request $request, Closure $next): Response | JsonResponse | RedirectResponse
    {
        if (str_ends_with($request->getHost(), '.ngrok-free.app')) {
            URL::forceScheme('https');
        }

        return $next($request);
    }
}
