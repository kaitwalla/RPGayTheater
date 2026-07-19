<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $aggregate_type
 * @property string|null $aggregate_id
 * @property string $topic
 * @property array<string, mixed> $payload
 * @property Carbon $occurred_at
 * @property Carbon|null $dispatched_at
 * @property int $attempts
 * @property Carbon|null $last_attempted_at
 * @property Carbon|null $dispatching_at
 * @property string|null $last_error
 */
class OutboxEvent extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['aggregate_type', 'aggregate_id', 'topic', 'payload', 'occurred_at', 'dispatched_at', 'attempts', 'last_attempted_at', 'dispatching_at', 'last_error'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime', 'attempts' => 'integer', 'last_attempted_at' => 'immutable_datetime', 'dispatching_at' => 'immutable_datetime'];
    }
}
