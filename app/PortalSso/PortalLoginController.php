<?php

namespace App\PortalSso;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PortalLoginController extends Controller
{
    public function redirect(Request $request, PortalClient $portal): RedirectResponse
    {
        $state = Str::random(40);
        $verifier = Str::random(64);

        $request->session()->put(['portal.state' => $state, 'portal.verifier' => $verifier]);

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return redirect()->away($portal->authorizationUrl($state, $challenge));
    }

    public function callback(Request $request, PortalClient $portal, PortalUserLinker $linker): RedirectResponse
    {
        // One-time use: pull state and verifier out of the session whatever happens next.
        $expectedState = $request->session()->pull('portal.state');
        $verifier = $request->session()->pull('portal.verifier');

        abort_unless(
            is_string($expectedState) && is_string($verifier) && hash_equals($expectedState, (string) $request->query('state')),
            403,
            'This sign-in link has expired. Please open Maintenance again.',
        );

        abort_if($request->filled('error') || ! $request->filled('code'), 403, 'The TR Staff Portal did not sign you in.');

        try {
            $tokens = $portal->exchangeCode((string) $request->query('code'), $verifier);
            $claims = $portal->userinfo($tokens['access_token']);
        } catch (PortalAuthException|PortalUnavailableException $e) {
            Log::warning('Portal sign-in failed', ['error' => $e->getMessage()]);
            abort(403, 'Sign-in through the TR Staff Portal did not complete. Please try again.');
        }

        abort_unless(
            in_array(config('portal.app_slug'), (array) ($claims['apps'] ?? []), true),
            403,
            "You don't have access to Maintenance. Ask IT to add it for you in the TR Staff Portal.",
        );

        try {
            $user = $linker->link($claims);
        } catch (PortalLinkConflict $e) {
            abort(409, $e->getMessage());
        } catch (PortalAccessRefused $e) {
            abort(403, $e->getMessage());
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put([
            'portal.sub' => (string) $claims['sub'],
            'portal.refresh_token' => $tokens['refresh_token'],
            'portal.checked_at' => now()->getTimestamp(),
        ]);

        return $this->landing($user);
    }

    /** Same destinations as the password login (FortifyServiceProvider's LoginResponse). */
    private function landing(User $user): RedirectResponse
    {
        if ($user->hasRole('accounting')) {
            return redirect()->route('work_orders.waiting_on_payment');
        }

        return redirect()->intended(config('fortify.home'));
    }
}
