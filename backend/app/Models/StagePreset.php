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

    protected $fillable = ['campaign_id', 'scene_id', 'name', 'scene_backdrop_id', 'tween_duration_ms', 'tween_easing', 'sort_order'];

    protected function casts(): array
    {
        return ['tween_duration_ms' => 'integer', 'sort_order' => 'integer'];
    }
}
