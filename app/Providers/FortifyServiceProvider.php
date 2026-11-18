<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use App\PortalSso\StaffAccounts;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, function () {
            return new class implements LoginResponseContract
            {
                public function toResponse($request)
                {
                    $user = auth()->user();

                    // Redirect accounting users to waiting on payments page
                    if ($user->hasRole('accounting')) {
                        return redirect()->route('work_orders.waiting_on_payment');
                    }

                    // Default redirect for other users
                    return redirect()->intended(config('fortify.home'));
                }
            };
        });

        // Staff who signed in through the TR Staff Portal go back to its app list.
        $this->app->singleton(LogoutResponseContract::class, function () {
            return new class implements LogoutResponseContract
            {
                public function toResponse($request)
                {
                    if ($request->attributes->get('portal_session')) {
                        return redirect()->away(config('portal.issuer').'/dashboard');
                    }

                    return $request->wantsJson() ? new JsonResponse('', 204) : redirect(Fortify::redirects('logout', '/'));
                }
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // While the TR Staff Portal is on, staff sign in only through it; vendors, tenants and
        // owners are unaffected. Every failure gets Fortify's one generic message, and the
        // password is always checked, so neither the answer nor its timing reveals staff.
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', $request->input(Fortify::username()))->first();
            $valid = $user !== null && Hash::check((string) $request->input('password'), $user->password);

            return $valid && ! StaffAccounts::mustUsePortal($user) ? $user : null;
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }
}
