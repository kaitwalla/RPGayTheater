<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\RealtimePublisher;
use App\Exceptions\OutboxPayloadTooLarge;
use App\Models\OutboxEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;

class LaravelRealtimePublisher implements RealtimePublisher
{
    public function publish(OutboxEvent $event): void
    {
        $payload = [
            'id' => $event->id,
            'aggregate_type' => $event->aggregate_type,
            'aggregate_id' => $event->aggregate_id,
            'occurred_at' => $event->occurred_at->toAtomString(),
        ] + $event->payload;
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > (int) config('realtime.max_event_bytes', 9_216)) {
            throw new OutboxPayloadTooLarge('Realtime event payloads must be smaller than 9 KiB.');
        }
        $channel = new PrivateChannel($event->topic);
        Broadcast::connection()->broadcast([$channel->name], 'rpgays.outbox', $payload);
    }
}
