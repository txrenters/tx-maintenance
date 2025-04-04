<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {

        $sharedData = parent::share($request);

        $userData = null;

        if ($request->user()) {
            $userData = $request->user()->only('id', 'name', 'email', 'phone', 'company', 'address', 'website', 'profile_photo_url') + [
                'roles' => $request->user()->roles->pluck('name'),
            ];

            // Add vendor-specific data if user has vendor role
            if ($request->user()->hasRole('vendor')) {
                $userData['vendor'] = $request->user()->vendor ?? null;
            }

            if ($request->user()->hasRole('woc')) {
                $userData['woc'] = $request->user()->wocNumber->twilioPhoneNumber ?? null;
            }
        }

        return array_merge($sharedData, [
            'auth.user' => $userData,
        ]);
    }
}
