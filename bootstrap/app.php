<?php

use App\Http\Middleware\CompressResponse;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveJobberPortalToken;
use App\Http\Middleware\ResolveTenantPortalToken;
use App\Http\Middleware\ResolveVendorAccountToken;
use App\Http\Middleware\ResolveVendorPortalToken;
use App\Http\Middleware\UpgradeToHttpsUnderNgrok;
use App\Http\Middleware\ValidateApiKey;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

ini_set('memory_limit', '256M');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,

            UpgradeToHttpsUnderNgrok::class,

        ]);

        $middleware->trustProxies(at: '*');

        // Outermost so it compresses every text response (web + api) after the
        // rest of the stack has produced it.
        $middleware->prepend(CompressResponse::class);

        // Disable string trimming for building custom fields API to preserve trailing spaces
        // (needed for PropertyWare API compatibility, e.g., "By Owner " for Debris Removal)
        $middleware->trimStrings(except: [
            fn (Request $request) => $request->is('api/buildings/*/update-custom-fields'),
        ]);

        $middleware->alias([
            'api.key' => ValidateApiKey::class,
            'vendor.portal' => ResolveVendorPortalToken::class,
            'jobber.portal' => ResolveJobberPortalToken::class,
            'vendor.account' => ResolveVendorAccountToken::class,
            'tenant.portal' => ResolveTenantPortalToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if (! app()->environment(['local', 'testing']) && in_array($response->getStatusCode(), [500, 503, 404, 403])) {
                return Inertia::render('Error', ['status' => $response->getStatusCode()])
                    ->toResponse($request)
                    ->setStatusCode($response->getStatusCode());
            } elseif ($response->getStatusCode() === 419) {
                return back()->with([
                    'message' => 'The page expired, please try again.',
                ]);
            }

            return $response;
        });
    })->create();
