<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $live_session_id
 * @property string $npc_id
 * @property bool $is_revealed
 * @property Carbon|null $revealed_at
 * @property Carbon $updated_at
 */
class SessionNpcReveal extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['live_session_id', 'npc_id', 'is_revealed', 'revealed_at'];

    protected function casts(): array
    {
        return ['is_revealed' => 'boolean', 'revealed_at' => 'immutable_datetime'];
    }

    /** @return array{id: string, live_session_id: string, npc_id: string, is_revealed: bool, revealed_at: string|null, updated_at: string} */
    public function toApi(): array
    {
        return ['id' => $this->id, 'live_session_id' => $this->live_session_id, 'npc_id' => $this->npc_id, 'is_revealed' => $this->is_revealed, 'revealed_at' => $this->revealed_at?->toAtomString(), 'updated_at' => $this->updated_at->toAtomString()];
    }
}
