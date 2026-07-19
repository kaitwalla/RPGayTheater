<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DispatchOutboxEvent;
use App\Models\OutboxEvent;
use Illuminate\Console\Command;

class DispatchPendingOutboxEvents extends Command
{
    protected $signature = 'outbox:dispatch {--limit=100 : Maximum pending events to enqueue}';

    protected $description = 'Enqueue pending transactional outbox events for realtime delivery';

    public function handle(): int
    {
        $limit = max(1, min((int) $this->option('limit'), 1_000));
        $events = OutboxEvent::query()->whereNull('dispatched_at')->orderBy('occurred_at')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($events as $eventId) {
            if (is_string($eventId)) {
                DispatchOutboxEvent::dispatch($eventId);
            }
        }
        $this->info("Enqueued {$events->count()} outbox event(s).");

        return self::SUCCESS;
    }
}
