<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\SmokePusherRealtime;
use App\Contracts\RealtimePublisher;
use App\Models\OutboxEvent;
use Tests\TestCase;

class SmokePusherRealtimeCommandTest extends TestCase
{
    public function test_staging_pusher_smoke_publishes_an_ephemeral_private_event(): void
    {
        config()->set('app.env', 'staging');
        config()->set('broadcasting.default', 'pusher');
        $publisher = new class implements RealtimePublisher
        {
            public ?OutboxEvent $event = null;

            public function publish(OutboxEvent $event): void
            {
                $this->event = $event;
            }
        };
        $this->app->instance(RealtimePublisher::class, $publisher);

        $this->artisan('realtime:pusher-smoke')->assertSuccessful();

        self::assertNotNull($publisher->event);
        self::assertSame('realtime_smoke', $publisher->event->aggregate_type);
        self::assertStringStartsWith('smoke.pusher.', $publisher->event->topic);
        self::assertSame('realtime.pusher_smoke', $publisher->event->payload['event_type']);
        self::assertSame($publisher->event->id, $publisher->event->payload['probe_id']);
    }

    public function test_pusher_smoke_rejects_non_staging_or_non_pusher_configuration(): void
    {
        config()->set('app.env', 'production');
        config()->set('broadcasting.default', 'pusher');
        $this->artisan(SmokePusherRealtime::class)->assertFailed();

        config()->set('app.env', 'staging');
        config()->set('broadcasting.default', 'reverb');
        $this->artisan(SmokePusherRealtime::class)->assertFailed();
    }
}
