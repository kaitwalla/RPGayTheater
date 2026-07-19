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
 * @property string $author_type
 * @property string|null $session_participant_id
 * @property string $body
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SessionNpcNote extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['live_session_id', 'npc_id', 'author_type', 'session_participant_id', 'body'];

    /** @return array{id: string, npc_id: string, author_type: string, session_participant_id: string|null, body: string, created_at: string, updated_at: string} */
    public function toApi(): array
    {
        return ['id' => $this->id, 'npc_id' => $this->npc_id, 'author_type' => $this->author_type, 'session_participant_id' => $this->session_participant_id, 'body' => $this->body, 'created_at' => $this->created_at->toAtomString(), 'updated_at' => $this->updated_at->toAtomString()];
    }
}
