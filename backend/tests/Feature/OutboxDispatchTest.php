<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\RealtimePublisher;
use App\Jobs\DispatchOutboxEvent;
use App\Models\OutboxEvent;
use App\Services\OutboxDispatcher;
use App\Services\RealtimeChannelAuthorizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class OutboxDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('control.secret', 'correct-horse-battery-staple-for-tests');
    }

    public function test_outbox_events_are_enqueued_only_after_the_creating_transaction_commits(): void
    {
        Queue::fake();
        $event = null;
        DB::transaction(function () use (&$event): void {
            $event = $this->outboxEvent();
            Queue::assertNothingPushed();
        });

        Queue::assertPushed(DispatchOutboxEvent::class, fn (DispatchOutboxEvent $job): bool => $job->eventId === $event->id && $job->queue === 'realtime');
    }

    public function test_dispatch_marks_the_event_only_after_a_successful_publish_and_is_idempotent(): void
    {
        Queue::fake();
        $published = [];
        $this->app->instance(RealtimePublisher::class, new class($published) implements RealtimePublisher
        {
            /** @var list<string> */
            public array $published = [];

            /** @param list<string> $published */
            public function __construct(array $published)
            {
                $this->published = $published;
            }

            public function publish(OutboxEvent $event): void
            {
                $this->published[] = $event->id;
            }
        });
        /** @var RealtimePublisher&object{published: list<string>} $publisher */
        $publisher = $this->app->make(RealtimePublisher::class);
        $event = $this->outboxEvent();

        $dispatcher = $this->app->make(OutboxDispatcher::class);
        $this->assertTrue($dispatcher->dispatch($event->id));
        $this->assertFalse($dispatcher->dispatch($event->id));
        $event->refresh();
        $this->assertSame(1, $event->attempts);
        $this->assertNotNull($event->dispatched_at);
        $this->assertNull($event->dispatching_at);
        $this->assertSame([$event->id], $publisher->published);
    }

    public function test_failed_delivery_retains_retry_diagnostics_and_releases_its_lease(): void
    {
        Queue::fake();
        $this->app->instance(RealtimePublisher::class, new class implements RealtimePublisher
        {
            public function publish(OutboxEvent $event): void
            {
                throw new RuntimeException('provider unavailable');
            }
        });
        $event = $this->outboxEvent();

        try {
            $this->app->make(OutboxDispatcher::class)->dispatch($event->id);
            $this->fail('Expected the publisher failure to be propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('provider unavailable', $exception->getMessage());
        }
        $event->refresh();
        $this->assertSame(1, $event->attempts);
        $this->assertNull($event->dispatched_at);
        $this->assertNull($event->dispatching_at);
        $this->assertSame('provider unavailable', $event->last_error);
    }

    public function test_control_can_inspect_delivery_health_and_authenticate_a_private_channel(): void
    {
        Queue::fake();
        $this->outboxEvent();
        $this->getJson('/api/control/v1/realtime/status')->assertUnauthorized();
        $this->postJson('/api/control/v1/auth/login', ['secret' => 'correct-horse-battery-staple-for-tests'])->assertOk();
        $this->getJson('/api/control/v1/realtime/status')->assertOk()->assertJsonPath('data.pending_count', 1)->assertJsonPath('data.failed_count', 0);
        config()->set('broadcasting.default', 'pusher');
        config()->set('broadcasting.connections.pusher.key', 'test-key');
        config()->set('broadcasting.connections.pusher.secret', 'test-secret');
        config()->set('broadcasting.connections.pusher.app_id', 'test-app');
        Broadcast::resolveAuthenticatedUserUsing(fn ($request) => app(RealtimeChannelAuthorizer::class)->principal($request));
        require base_path('routes/channels.php');
        $this->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-control.campaigns'])
            ->assertOk()->assertJsonStructure(['auth']);
    }

    private function outboxEvent(): OutboxEvent
    {
        return OutboxEvent::query()->create(['aggregate_type' => 'presentation_state', 'topic' => 'presentation_states/invalid', 'payload' => ['event_type' => 'presentation_state.updated', 'revision' => 2], 'occurred_at' => now()]);
    }
}
