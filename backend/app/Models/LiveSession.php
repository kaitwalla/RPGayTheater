<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class LiveSession extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['campaign_id', 'campaign_revision_id', 'progress_mode', 'player_code', 'display_pairing_token_hash', 'status'];

    /** @return array{id: string, campaign_id: string, campaign_revision_id: string, progress_mode: string, player_code: string, status: string, created_at: string} */
    public function toApi(): array
    {
        /** @var Carbon $createdAt */
        $createdAt = $this->created_at;

        return ['id' => $this->getKey(), 'campaign_id' => $this->campaign_id, 'campaign_revision_id' => $this->campaign_revision_id, 'progress_mode' => $this->progress_mode, 'player_code' => $this->player_code, 'status' => $this->status, 'created_at' => $createdAt->toAtomString()];
    }
}
