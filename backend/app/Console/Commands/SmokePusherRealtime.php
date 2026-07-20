<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\RealtimePublisher;
use App\Models\OutboxEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SmokePusherRealtime extends Command
{
    /** @var string */
    protected $signature = 'realtime:pusher-smoke';

    /** @var string */
    protected $description = 'Publish an ephemeral staging probe through the configured production Pusher service';

    public function handle(RealtimePublisher $publisher): int
    {
        if (config('app.env') !== 'staging') {
            $this->components->error('The Pusher smoke test may run only in the staging environment.');

            return self::FAILURE;
        }
        if (config('broadcasting.default') !== 'pusher') {
            $this->components->error('The Pusher smoke test requires BROADCAST_CONNECTION=pusher.');

            return self::FAILURE;
        }

        $probeId = (string) Str::uuid7();
        $event = new OutboxEvent([
            'aggregate_type' => 'realtime_smoke',
            'topic' => 'smoke.pusher.'.$probeId,
            'payload' => ['event_type' => 'realtime.pusher_smoke', 'probe_id' => $probeId],
            'occurred_at' => now(),
        ]);
        $event->id = $probeId;
        $publisher->publish($event);

        $this->components->info("Pusher smoke event {$probeId} published.");

        return self::SUCCESS;
    }
}
