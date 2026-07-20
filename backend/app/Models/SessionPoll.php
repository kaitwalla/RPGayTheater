<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property CarbonImmutable|null $closed_at
 * @property Carbon $created_at
 */
class SessionPoll extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['live_session_id', 'question', 'allows_multiple', 'target_type', 'target_session_participant_id', 'session_player_group_id', 'status', 'result_visibility', 'closed_at'];

    protected function casts(): array
    {
        return ['allows_multiple' => 'boolean', 'closed_at' => 'immutable_datetime'];
    }
}
