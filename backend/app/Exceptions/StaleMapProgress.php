<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\MapProgress;
use RuntimeException;

class StaleMapProgress extends RuntimeException
{
    public function __construct(public readonly MapProgress $progress)
    {
        parent::__construct('The map progress has changed. Refetch its current snapshot and retry.');
    }
}
