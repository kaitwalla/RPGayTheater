<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\DispatchOutboxEvent;
use App\Models\OutboxEvent;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class OutboxEventObserver implements ShouldHandleEventsAfterCommit
{
    public function created(OutboxEvent $event): void
    {
        DispatchOutboxEvent::dispatch($event->id);
    }
}
