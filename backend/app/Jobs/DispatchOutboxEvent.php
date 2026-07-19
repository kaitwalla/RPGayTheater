<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\OutboxDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchOutboxEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [1, 5, 15];

    public function __construct(public readonly string $eventId)
    {
        $this->onQueue('realtime');
    }

    public function handle(OutboxDispatcher $dispatcher): void
    {
        $dispatcher->dispatch($this->eventId);
    }
}
