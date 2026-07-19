<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PresentationDisplay extends Model
{
    use HasUuids;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = ['live_session_id', 'credential_hash', 'paired_at', 'revoked_at'];
}
