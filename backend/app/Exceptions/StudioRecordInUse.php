<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class StudioRecordInUse extends RuntimeException
{
    /** @param list<array{section: string, id: string, label: string}> $usages */
    public function __construct(public readonly array $usages)
    {
        $locations = array_map(
            static fn (array $usage): string => ucfirst(str_replace('_', ' ', $usage['section'])).' "'.$usage['label'].'"',
            $usages,
        );

        parent::__construct('This item is still used by: '.implode('; ', $locations).'.');
    }
}
