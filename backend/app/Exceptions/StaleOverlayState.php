<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\OverlayState;
use RuntimeException;

class StaleOverlayState extends RuntimeException
{
    public function __construct(public readonly OverlayState $state)
    {
        parent::__construct('The overlay state has changed. Refetch its current snapshot and retry.');
    }
}
