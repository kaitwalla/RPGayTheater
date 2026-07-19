<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\PlayerMapState;
use RuntimeException;

class StalePlayerMapState extends RuntimeException
{
    public function __construct(public readonly PlayerMapState $state)
    {
        parent::__construct('The player map selection has changed. Refetch its current snapshot and retry.');
    }
}
