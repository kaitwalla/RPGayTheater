<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SessionParticipant extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['live_session_id', 'role', 'display_name', 'display_name_normalized', 'resume_token_hash', 'revoked_at'];
}
