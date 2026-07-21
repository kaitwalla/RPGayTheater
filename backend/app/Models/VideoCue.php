<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VideoCue extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['campaign_id', 'scene_id', 'primary_asset_id', 'fallback_asset_id', 'name', 'completion_mode', 'target_scene_id', 'music_during', 'music_after', 'embedded_audio_volume', 'embedded_audio_muted', 'sort_order'];

    protected function casts(): array
    {
        return ['embedded_audio_volume' => 'integer', 'embedded_audio_muted' => 'boolean', 'sort_order' => 'integer'];
    }
}
