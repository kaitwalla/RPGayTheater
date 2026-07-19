<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production') && strlen((string) config('control.secret')) < 32) {
            throw new \RuntimeException('CONTROL_SECRET must be at least 32 characters in production.');
        }

        RateLimiter::for('control-login', static fn (Request $request): Limit => Limit::perMinute(5)
            ->by($request->ip()));
    }
}
