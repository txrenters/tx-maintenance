<?php

namespace App\Http\Middleware;

use App\Http\Controllers\FeatureUpdatesController;
use App\Models\Technician;
use App\Models\WorkOrderNotes;
use App\Services\ActivityBoards;
use App\Services\HvacBoardNewCounter;
use App\Services\UnreadThreadCounter;
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

        $twilio_phone_number = env('TWILIO_PHONE_NUMBER');
        $maintenance_twilio_phone_number = env('MAINTENANC_TWILIO_PHONE_NUMBER');
        $jobber_twilio_phone_number = env('TWILIO_PHONE_NUMBER');

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
            'twilio_phone_number' => $twilio_phone_number,
            'maintenance_twilio_phone_number' => $maintenance_twilio_phone_number,
            'jobber_twilio_phone_number' => $jobber_twilio_phone_number,
            'logo' => asset('tx-logo.webp'),
            'app_url' => env('APP_URL'),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
            // Drives the red badge on the Messages nav: threads holding a
            // message this user has not opened yet (Messenger semantics —
            // reading clears it, replying is not required). Only the staff who
            // see that nav pay for the query; cached per user for a minute.
            'unread_thread_count' => fn () => $request->user()?->hasAnyRole(['admin', 'woc'])
                ? app(UnreadThreadCounter::class)->cachedCountFor($request->user()->id)
                : 0,
            // Drives the badge on the What's New nav: release dates of every
            // shipped update, newest first; the layout counts the ones newer
            // than this browser's last visit to the page.
            'feature_update_dates' => fn () => $request->user()?->hasAnyRole(['admin', 'woc'])
                ? FeatureUpdatesController::updateDates()
                : [],
            // Drives the number beside HVAC in the sidebar: the work orders that
            // moved since this user last marked that board seen, so movement is
            // visible without opening the board. Staff only (vendors, tenants
            // and owners never pay for the query); cached per user for a minute.
            'hvac_board_new_count' => fn () => $request->user()?->seesHvacBoardActivity()
                ? app(HvacBoardNewCounter::class)->cachedCountFor($request->user())
                : 0,
            // The same number for the Tenant Easy Fix board.
            'easy_fix_board_new_count' => fn () => $request->user()?->seesEasyFixBoardActivity()
                ? app(HvacBoardNewCounter::class)->cachedCountFor($request->user(), ActivityBoards::EASY_FIX)
                : 0,
            // The names the note dialog makes the THMP login pick from before a
            // note can be saved: the crew shares that one login, so the picked
            // name is the only record of who typed it. Empty for everyone else
            // (no picker), and until the note columns exist on this database.
            'note_technicians' => fn () => $request->user()?->hasRole('vendor')
                && $request->user()->vendor?->isThmp()
                && WorkOrderNotes::recordsTechnician()
                ? Technician::noteAuthorOptions()
                : [],
        ]);
    }
}
