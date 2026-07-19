<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\PresentationState;
use RuntimeException;

class StalePresentationState extends RuntimeException
{
    public function __construct(public readonly PresentationState $state)
    {
        parent::__construct('The presentation state has changed. Refetch its current snapshot and retry.');
    }
}
