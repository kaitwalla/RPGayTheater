<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

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
