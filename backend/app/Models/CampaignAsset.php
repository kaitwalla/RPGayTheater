<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $campaign_id
 * @property string $original_filename
 * @property string $kind
 * @property string $declared_mime
 * @property string|null $validated_mime
 * @property int $byte_size
 * @property string|null $sha256
 * @property string|null $storage_key
 * @property string|null $upload_id
 * @property string $upload_status
 * @property array<string, mixed>|null $metadata
 * @property string|null $validation_error
 * @property string|null $label
 * @property CarbonImmutable|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CampaignAsset extends Model
{
    use HasUuids;

    public const STATUS_INITIATED = 'initiated';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'campaign_id', 'original_filename', 'kind', 'declared_mime', 'validated_mime', 'byte_size',
        'sha256', 'storage_key', 'upload_id', 'upload_status', 'metadata', 'validation_error', 'label', 'archived_at',
    ];

    protected function casts(): array
    {
        return ['byte_size' => 'integer', 'metadata' => 'array', 'archived_at' => 'immutable_datetime'];
    }

    /** @param Builder<CampaignAsset> $query */
    public function scopeAvailableForAuthoring(Builder $query): void
    {
        $query->where('upload_status', self::STATUS_READY)->whereNull('archived_at');
    }

    /** @return array{id: string, campaign_id: string, original_filename: string, label: string|null, kind: string, declared_mime: string, validated_mime: string|null, byte_size: int, sha256: string|null, upload_status: string, metadata: array<string, mixed>|null, validation_error: string|null, archived_at: string|null, created_at: string} */
    public function toApi(): array
    {
        return [
            'id' => $this->getKey(),
            'campaign_id' => $this->campaign_id,
            'original_filename' => $this->original_filename,
            'label' => $this->label,
            'kind' => $this->kind,
            'declared_mime' => $this->declared_mime,
            'validated_mime' => $this->validated_mime,
            'byte_size' => $this->byte_size,
            'sha256' => $this->sha256,
            'upload_status' => $this->upload_status,
            'metadata' => $this->metadata,
            'validation_error' => $this->validation_error,
            'archived_at' => $this->archived_at?->toAtomString(),
            'created_at' => $this->created_at->toAtomString(),
        ];
    }
}
