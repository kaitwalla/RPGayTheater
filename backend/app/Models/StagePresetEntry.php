<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StagePresetEntry extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['stage_preset_id', 'npc_id', 'npc_state_id', 'position_x', 'position_y', 'scale', 'layer_order', 'facing'];

    protected function casts(): array
    {
        return ['position_x' => 'float', 'position_y' => 'float', 'scale' => 'float', 'layer_order' => 'integer'];
    }
}
