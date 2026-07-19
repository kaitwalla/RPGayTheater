<?php

declare(strict_types=1);

namespace App\Services;

class RealtimeBroadcastPrincipal
{
    public function __construct(private readonly string $id) {}

    public function getAuthIdentifier(): string
    {
        return $this->id;
    }

    public function getAuthIdentifierForBroadcasting(): string
    {
        return $this->id;
    }
}
