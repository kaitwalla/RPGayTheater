<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $live_session_id
 * @property string|null $map_id
 * @property int $revision
 * @property Carbon $updated_at
 */
class PlayerMapState extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['live_session_id', 'map_id', 'revision'];

    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }

    /** @return array{id: string, live_session_id: string, map_id: string|null, revision: int, updated_at: string} */
    public function toApi(): array
    {
        return ['id' => $this->id, 'live_session_id' => $this->live_session_id, 'map_id' => $this->map_id, 'revision' => $this->revision, 'updated_at' => $this->updated_at->toAtomString()];
    }
}
