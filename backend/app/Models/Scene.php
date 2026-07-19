<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Scene extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['campaign_id', 'name', 'primary_backdrop_asset_id', 'default_music_cue_id', 'base_stage_preset_id', 'transition', 'transition_duration_ms', 'sort_order'];

    protected function casts(): array
    {
        return ['transition_duration_ms' => 'integer', 'sort_order' => 'integer'];
    }
}
