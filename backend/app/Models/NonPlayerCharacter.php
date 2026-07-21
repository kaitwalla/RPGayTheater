<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $campaign_id
 * @property string $normal_asset_id
 * @property string $name
 * @property string|null $pronouns
 * @property string|null $public_description
 * @property string $native_facing
 */
class NonPlayerCharacter extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['campaign_id', 'normal_asset_id', 'name', 'pronouns', 'public_description', 'native_facing', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }
}
