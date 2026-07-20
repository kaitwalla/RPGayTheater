<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $live_session_id
 * @property string $session_participant_id
 * @property string|null $dice_preset_id
 * @property string|null $dice_preset_name
 * @property string $expression
 * @property string $visibility
 * @property int $total
 * @property array<string, mixed> $breakdown
 * @property CarbonImmutable|null $revealed_at
 * @property Carbon $created_at
 */
class SessionRoll extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['live_session_id', 'session_participant_id', 'dice_preset_id', 'dice_preset_name', 'expression', 'visibility', 'total', 'breakdown', 'revealed_at'];

    protected function casts(): array
    {
        return ['total' => 'integer', 'breakdown' => 'array', 'revealed_at' => 'immutable_datetime'];
    }
}
