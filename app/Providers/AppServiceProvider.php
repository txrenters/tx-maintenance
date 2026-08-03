<?php

namespace App\Providers;

use App\Services\Images\GdImageProcessor;
use App\Services\Images\ImageProcessor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;

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
