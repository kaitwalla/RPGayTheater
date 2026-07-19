<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Campaign;
use RuntimeException;

class StaleRevision extends RuntimeException
{
    public function __construct(public readonly Campaign $campaign)
    {
        parent::__construct('The campaign has changed. Refetch its current state and retry.');
    }
}
