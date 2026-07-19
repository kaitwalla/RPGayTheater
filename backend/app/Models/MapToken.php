<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MapToken extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['map_id', 'token_type', 'player_character_id', 'npc_id', 'asset_id', 'label', 'position_x', 'position_y', 'scale', 'sort_order'];

    protected function casts(): array
    {
        return ['position_x' => 'float', 'position_y' => 'float', 'scale' => 'float', 'sort_order' => 'integer'];
    }
}
