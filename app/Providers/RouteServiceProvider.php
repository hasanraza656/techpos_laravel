<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $r) => [Limit::perMinute(20)->by($r->ip()), Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip())]);
        RateLimiter::for('registration', fn (Request $r) => Limit::perHour(10)->by($r->ip()));
        RateLimiter::for('api', function (Request $request) {
            return $request->user() ? Limit::perMinute(180)->by('user:'.$request->user()->id) : Limit::perMinute(60)->by('ip:'.$request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
