<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $campaign_id
 * @property int $number
 * @property array<string, mixed> $manifest
 * @property string $manifest_hash
 * @property CarbonImmutable $published_at
 */
class CampaignRevision extends Model
{
    use HasUuids;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = ['campaign_id', 'number', 'manifest', 'manifest_hash', 'published_at'];

    protected function casts(): array
    {
        return ['manifest' => 'array', 'published_at' => 'immutable_datetime', 'number' => 'integer'];
    }

    /** @return array{id: string, campaign_id: string, number: int, manifest_hash: string, published_at: string} */
    public function toApi(): array
    {
        return [
            'id' => $this->getKey(),
            'campaign_id' => $this->campaign_id,
            'number' => $this->number,
            'manifest_hash' => $this->manifest_hash,
            'published_at' => $this->published_at->toAtomString(),
        ];
    }
}
