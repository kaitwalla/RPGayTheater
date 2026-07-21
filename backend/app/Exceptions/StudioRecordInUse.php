<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class StudioRecordInUse extends RuntimeException
{
    /** @param list<array{section: string, id: string, label: string}> $usages */
    public function __construct(public readonly array $usages)
    {
        parent::__construct('Remove or reassign every listed usage before deleting this item.');
    }
}
