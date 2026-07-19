<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $live_session_id
 * @property string $map_id
 * @property int $revision
 * @property array<string, mixed> $fog
 * @property list<array<string, mixed>> $tokens
 * @property Carbon $updated_at
 */
class MapProgress extends Model
{
    use HasUuids;

    protected $table = 'map_progresses';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['live_session_id', 'map_id', 'revision', 'fog', 'tokens'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'fog' => 'array', 'tokens' => 'array'];
    }

    /** @return array{id: string, live_session_id: string, map_id: string, revision: int, fog: array<string, mixed>, tokens: list<array<string, mixed>>, updated_at: string} */
    public function toApi(): array
    {
        return ['id' => $this->id, 'live_session_id' => $this->live_session_id, 'map_id' => $this->map_id, 'revision' => $this->revision, 'fog' => $this->fog, 'tokens' => $this->tokens, 'updated_at' => $this->updated_at->toAtomString()];
    }
}
