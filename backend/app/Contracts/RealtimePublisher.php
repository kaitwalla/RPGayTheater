<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\OutboxEvent;

interface RealtimePublisher
{
    public function publish(OutboxEvent $event): void;
}
