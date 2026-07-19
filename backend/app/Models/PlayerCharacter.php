<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $campaign_id
 * @property string|null $avatar_asset_id
 * @property string $name
 * @property string|null $pronouns
 * @property string|null $public_description
 * @property int $sort_order
 * @property Carbon $updated_at
 */
class PlayerCharacter extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['campaign_id', 'avatar_asset_id', 'name', 'pronouns', 'public_description', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** @return array{id: string, campaign_id: string, avatar_asset_id: string|null, name: string, pronouns: string|null, public_description: string|null, sort_order: int, updated_at: string} */
    public function toApi(): array
    {
        return ['id' => $this->getKey(), 'campaign_id' => $this->campaign_id, 'avatar_asset_id' => $this->avatar_asset_id, 'name' => $this->name, 'pronouns' => $this->pronouns, 'public_description' => $this->public_description, 'sort_order' => $this->sort_order, 'updated_at' => $this->updated_at->toAtomString()];
    }
}
