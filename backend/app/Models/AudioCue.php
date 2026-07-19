<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AudioCue extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['campaign_id', 'asset_id', 'name', 'kind', 'loop', 'default_volume', 'sort_order'];

    protected function casts(): array
    {
        return ['loop' => 'boolean', 'default_volume' => 'integer', 'sort_order' => 'integer'];
    }
}
