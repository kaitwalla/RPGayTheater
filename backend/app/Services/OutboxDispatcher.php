<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\RealtimePublisher;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Throwable;

class OutboxDispatcher
{
    public function __construct(private readonly RealtimePublisher $publisher) {}

    public function dispatch(string $eventId): bool
    {
        $event = DB::transaction(function () use ($eventId): ?OutboxEvent {
            /** @var OutboxEvent|null $event */
            $event = OutboxEvent::query()->lockForUpdate()->find($eventId);
            if ($event === null || $event->dispatched_at !== null) {
                return null;
            }
            $leaseExpiresAt = now()->subSeconds((int) config('realtime.dispatch_lease_seconds', 60));
            if ($event->dispatching_at !== null && $event->dispatching_at->greaterThan($leaseExpiresAt)) {
                return null;
            }
            $event->update(['attempts' => $event->attempts + 1, 'last_attempted_at' => now(), 'dispatching_at' => now(), 'last_error' => null]);
            $event->refresh();

            return $event;
        });
        if ($event === null) {
            return false;
        }
        try {
            $this->publisher->publish($event);
        } catch (Throwable $exception) {
            OutboxEvent::query()->whereKey($event->id)->whereNull('dispatched_at')->update(['dispatching_at' => null, 'last_error' => mb_substr($exception->getMessage(), 0, 1000)]);

            throw $exception;
        }
        OutboxEvent::query()->whereKey($event->id)->whereNull('dispatched_at')->update(['dispatched_at' => now(), 'dispatching_at' => null, 'last_error' => null]);

        return true;
    }
}
