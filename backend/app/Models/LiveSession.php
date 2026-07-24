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

    protected $fillable = ['campaign_id', 'campaign_revision_id', 'name', 'progress_mode', 'player_code', 'display_pairing_token_hash', 'status', 'archived_at'];

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    /** @return array{id: string, campaign_id: string, campaign_revision_id: string, name: string, progress_mode: string, player_code: string, status: string, archived_at: string|null, created_at: string} */
    public function toApi(): array
    {
        /** @var Carbon $createdAt */
        $createdAt = $this->created_at;

        $archivedAt = $this->archived_at === null ? null : Carbon::parse($this->archived_at)->toAtomString();

        return ['id' => $this->getKey(), 'campaign_id' => $this->campaign_id, 'campaign_revision_id' => $this->campaign_revision_id, 'name' => $this->name, 'progress_mode' => $this->progress_mode, 'player_code' => $this->player_code, 'status' => $this->status, 'archived_at' => $archivedAt, 'created_at' => $createdAt->toAtomString()];
    }
}
