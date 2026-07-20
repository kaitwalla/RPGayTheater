<?php

namespace App\Providers;

use App\Contracts\RealtimePublisher;
use App\Http\Middleware\ResolveRealtimeBroadcastPrincipal;
use App\Models\OutboxEvent;
use App\Observers\OutboxEventObserver;
use App\Services\LaravelRealtimePublisher;
use App\Services\RealtimeChannelAuthorizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RealtimePublisher::class, LaravelRealtimePublisher::class);
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
        RateLimiter::for('session-messages', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by((string) $request->session()->get('participant.id', $request->ip())));
        OutboxEvent::observe(OutboxEventObserver::class);
        Broadcast::resolveAuthenticatedUserUsing(fn (Request $request) => $this->app->make(RealtimeChannelAuthorizer::class)->principal($request));
        Broadcast::routes(['middleware' => ['web', ResolveRealtimeBroadcastPrincipal::class]]);
        require base_path('routes/channels.php');
    }
}
