<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StagePreset extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['campaign_id', 'name', 'tween_duration_ms', 'tween_easing'];

    protected function casts(): array
    {
        return ['tween_duration_ms' => 'integer'];
    }
}
