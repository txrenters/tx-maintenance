<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Notifications\DesktopTestNotification;
use App\Services\Desktop\DesktopTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DesktopNotificationController extends Controller
{
    public function __construct(
        private readonly DesktopTokenService $tokens,
    ) {}

    /**
     * Show the desktop connection status and its actions.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/DesktopNotifications', [
            'title' => 'Desktop Notifications',
            'connection' => $this->tokens->status($request->user()),
            // Only ever present on the redirect straight after generating one;
            // the plain text is never stored, so it cannot be shown again.
            'plainTextToken' => $request->session()->get('desktopToken'),
            'setupTokenTtl' => config('desktop.setup_token_ttl'),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Generate a short-lived desktop connection token.
     */
    public function store(Request $request): RedirectResponse
    {
        $token = $this->tokens->issueSetupToken($request->user());

        return to_route('settings.desktop-notifications.edit')
            ->with('desktopToken', $token->plainTextToken);
    }

    /**
     * Disconnect every desktop client belonging to this user.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $this->tokens->revokeAll($request->user());

        return to_route('settings.desktop-notifications.edit')
            ->with('status', 'Desktop clients disconnected.');
    }

    /**
     * Broadcast a test notification down the user's real private channel.
     */
    public function test(Request $request): RedirectResponse
    {
        $request->user()->notify(new DesktopTestNotification);

        return to_route('settings.desktop-notifications.edit')
            ->with('status', 'Test notification sent.');
    }
}
