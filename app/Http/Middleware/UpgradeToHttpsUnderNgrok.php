<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UpgradeToHttpsUnderNgrok
{
    public function handle($request, Closure $next): Response|JsonResponse|RedirectResponse|BinaryFileResponse
    {
        if (str_ends_with($request->getHost(), '.ngrok-free.app')) {
            URL::forceScheme('https');
        }

        return $next($request);
    }
}
