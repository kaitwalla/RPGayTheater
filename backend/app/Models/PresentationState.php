<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $live_session_id
 * @property int $revision
 * @property array<string, mixed> $state
 * @property Carbon $updated_at
 */
class PresentationState extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['live_session_id', 'revision', 'state'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'state' => 'array'];
    }

    /** @return array{id: string, live_session_id: string, revision: int, state: array<string, mixed>, updated_at: string} */
    public function toApi(): array
    {
        return ['id' => $this->id, 'live_session_id' => $this->live_session_id, 'revision' => $this->revision, 'state' => $this->state, 'updated_at' => $this->updated_at->toAtomString()];
    }
}
