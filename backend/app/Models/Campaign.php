<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property int $draft_revision
 * @property CarbonImmutable|null $archived_at
 * @property Carbon $updated_at
 */
class Campaign extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['name', 'draft_revision', 'archived_at'];

    protected function casts(): array
    {
        return [
            'archived_at' => 'immutable_datetime',
            'draft_revision' => 'integer',
        ];
    }

    /** @return array{id: string, name: string, draft_revision: int, archived_at: string|null, updated_at: string} */
    public function toApi(): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->name,
            'draft_revision' => $this->draft_revision,
            'archived_at' => $this->archived_at?->toAtomString(),
            'updated_at' => $this->updated_at->toAtomString(),
        ];
    }
}
