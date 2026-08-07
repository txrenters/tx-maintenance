<?php

namespace App\Providers;

use App\Services\Desktop\DesktopTokenService;
use App\Services\Images\GdImageProcessor;
use App\Services\Images\ImageProcessor;
use App\Services\Notifications\StaffActivityNotifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Opcodes\LogViewer\Facades\LogViewer;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // GD is the only driver available on the Azure instance today. Once
        // laravel/framework reaches >= 13.20 (Illuminate\Image) this becomes a
        // one-line swap via config/thumbnails.php.
        $this->app->bind(ImageProcessor::class, function () {
            return new GdImageProcessor;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(Request $request): void
    {
        Gate::before(function ($user) {
            return $user->hasRole('admin') ? true : null;
        });

        Gate::define('search_twilio_messages', function ($user) {
            return $user->hasRole('woc');
        });

        // A second, token-authenticated broadcasting auth route at
        // "api/broadcasting/auth" for the TexasRenters Desktop client, which
        // carries a Bearer token rather than a session cookie. The session
        // guarded "/broadcasting/auth" Laravel Echo uses in the browser is
        // registered separately by withRouting(channels:) and is left alone.
        // Deliberately unthrottled: the client reconnects with exponential
        // backoff, and several applications may reauthorise at once.
        Broadcast::routes([
            'prefix' => 'api',
            'middleware' => ['auth:sanctum', CheckAbilities::class.':'.DesktopTokenService::LISTEN_ABILITY],
        ]);

        // Every staff-facing event in this application is already an
        // activity-log row — that is what the notification bell renders. Rather
        // than editing all nineteen activity() call sites, notifications are
        // driven off the same rows, so the desktop and the bell cannot drift.
        // StaffActivityNotifier swallows its own errors: this runs inline on
        // the Twilio webhook path.
        Activity::created(function (Activity $activity) {
            app(StaffActivityNotifier::class)->handle($activity);
        });

        LogViewer::auth(function ($request) {
            return $request->user()?->hasRole('admin') ? true : null;
        });

        if (App::environment('production')) {
            URL::forceScheme('https');
            URL::forceRootUrl(config('app.url'));
        }

        // Model::shouldBeStrict(! App::environment('production'));
        // Model::automaticallyEagerLoadRelationships();

    }
}
