<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $live_session_id
 * @property string $role
 * @property string $display_name
 * @property string $display_name_normalized
 * @property CarbonImmutable|null $revoked_at
 */
class SessionParticipant extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['live_session_id', 'role', 'display_name', 'display_name_normalized', 'resume_token_hash', 'revoked_at'];

    protected function casts(): array
    {
        return ['revoked_at' => 'immutable_datetime'];
    }
}
