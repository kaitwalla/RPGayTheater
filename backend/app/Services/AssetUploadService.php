<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StaleRevision;
use App\Models\Campaign;
use App\Models\CampaignAsset;
use App\Models\OutboxEvent;
use App\Models\ProcessedCommand;
use App\Models\SessionEvent;
use Illuminate\Support\Facades\DB;

class AssetUploadService
{
    public function __construct(private readonly S3MultipartUploadService $multipart, private readonly CampaignAssetReferenceService $references, private readonly MediaMetadataExtractor $metadataExtractor) {}

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function initiate(string $campaignId, string $commandId, int $expectedRevision, string $filename, string $kind, string $mime, int $byteSize): array
    {
        return DB::transaction(function () use ($campaignId, $commandId, $expectedRevision, $filename, $kind, $mime, $byteSize): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }

            /** @var Campaign $campaign */
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->draft_revision !== $expectedRevision) {
                throw new StaleRevision($campaign);
            }
            abort_if($campaign->archived_at !== null, 422, 'Archived campaigns cannot accept assets.');
            abort_unless(in_array($mime, config("assets.mime_types.{$kind}"), true), 422, 'The declared media type is not permitted for this asset kind.');
            abort_if($byteSize > (int) config("assets.limits.{$kind}"), 422, 'The asset exceeds the configured size limit.');

            $asset = CampaignAsset::query()->create([
                'campaign_id' => $campaign->getKey(), 'original_filename' => trim($filename), 'kind' => $kind,
                'declared_mime' => $mime, 'byte_size' => $byteSize, 'upload_status' => CampaignAsset::STATUS_INITIATED,
            ]);
            $multipart = $this->startMultipartUpload("staging/assets/{$asset->getKey()}", $mime, $byteSize);
            $asset->upload_id = $multipart['upload_id'];
            $asset->save();
            $campaign->draft_revision++;
            $campaign->save();

            $response = ['data' => $asset->toApi(), 'upload' => $multipart];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaign->getKey(), 'response' => $response]);
            SessionEvent::query()->create(['campaign_id' => $campaign->getKey(), 'actor_type' => 'control', 'event_type' => 'asset.upload_initiated', 'command_id' => $commandId, 'payload' => ['asset_id' => $asset->getKey()], 'occurred_at' => now()]);
            OutboxEvent::query()->create(['aggregate_type' => 'campaign', 'aggregate_id' => $campaign->getKey(), 'topic' => 'control.campaigns', 'payload' => ['event_type' => 'asset.upload_initiated', 'command_id' => $commandId, 'revision' => $campaign->draft_revision], 'occurred_at' => now()]);

            return [$response, false];
        });
    }

    /**
     * @param  list<array{number: int, e_tag: string}>  $parts
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public function complete(string $campaignId, string $assetId, string $commandId, int $expectedRevision, array $parts): array
    {
        return DB::transaction(function () use ($campaignId, $assetId, $commandId, $expectedRevision, $parts): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var Campaign $campaign */
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->draft_revision !== $expectedRevision) {
                throw new StaleRevision($campaign);
            }
            /** @var CampaignAsset $asset */
            $asset = CampaignAsset::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($assetId);
            abort_unless($asset->upload_status === CampaignAsset::STATUS_INITIATED && $asset->upload_id !== null, 422, 'This asset upload cannot be completed.');
            $stagingKey = "staging/assets/{$asset->getKey()}";
            $temporary = null;
            try {
                $this->multipart->complete($stagingKey, $asset->upload_id, $parts);
                $temporary = tempnam(sys_get_temp_dir(), 'rpgays-asset-');
                if ($temporary === false) {
                    throw new \RuntimeException('Unable to create a validation workspace.');
                }
                $destination = fopen($temporary, 'wb');
                if ($destination === false) {
                    throw new \RuntimeException('Unable to open the validation workspace.');
                }
                stream_copy_to_stream($this->multipart->read($stagingKey), $destination);
                fclose($destination);
                $actualSize = filesize($temporary);
                if ($actualSize !== $asset->byte_size) {
                    throw new \RuntimeException('The uploaded byte size does not match the declared size.');
                }
                $actualMime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary);
                if (! is_string($actualMime) || $actualMime !== $asset->declared_mime) {
                    throw new \RuntimeException('The uploaded file content does not match its declared media type.');
                }
                $metadata = [];
                if ($asset->kind === 'image') {
                    $dimensions = getimagesize($temporary);
                    if ($dimensions === false) {
                        throw new \RuntimeException('The uploaded image has invalid dimensions.');
                    }
                    $metadata = ['width' => $dimensions[0], 'height' => $dimensions[1]];
                } elseif (in_array($asset->kind, ['audio', 'video'], true)) {
                    $metadata = $this->metadataExtractor->duration($temporary);
                }
                $hash = hash_file('sha256', $temporary);
                $key = "assets/sha256/{$hash}";
                $this->multipart->promote($stagingKey, $key);
                $asset->update(['validated_mime' => $actualMime, 'sha256' => $hash, 'storage_key' => $key, 'upload_id' => null, 'upload_status' => CampaignAsset::STATUS_READY, 'metadata' => $metadata]);
            } catch (\Throwable $exception) {
                $asset->update(['upload_status' => CampaignAsset::STATUS_FAILED, 'validation_error' => $exception->getMessage()]);
            } finally {
                if (is_string($temporary) && is_file($temporary)) {
                    unlink($temporary);
                }
            }
            $campaign->draft_revision++;
            $campaign->save();
            $response = ['data' => $asset->toApi()];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);

            return [$response, false];
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function initiateReplacement(string $campaignId, string $assetId, string $commandId, int $expectedRevision, string $filename, string $kind, string $mime, int $byteSize): array
    {
        return DB::transaction(function () use ($campaignId, $assetId, $commandId, $expectedRevision, $filename, $kind, $mime, $byteSize): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var Campaign $campaign */
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->draft_revision !== $expectedRevision) {
                throw new StaleRevision($campaign);
            }
            /** @var CampaignAsset $asset */
            $asset = CampaignAsset::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($assetId);
            abort_unless($asset->upload_status === CampaignAsset::STATUS_READY, 422, 'Only ready assets can be replaced.');
            abort_unless($asset->kind === $kind, 422, 'Replacement media must be the same kind as the original asset.');
            abort_unless(in_array($mime, config("assets.mime_types.{$kind}"), true), 422, 'The declared media type is not permitted for this asset kind.');
            abort_if($byteSize > (int) config("assets.limits.{$kind}"), 422, 'The asset exceeds the configured size limit.');
            abort_if($asset->replacement_upload_id !== null, 422, 'A replacement upload is already in progress.');

            $multipart = $this->startMultipartUpload("staging/assets/{$asset->getKey()}/replacement", $mime, $byteSize);
            $asset->update(['replacement_original_filename' => trim($filename), 'replacement_declared_mime' => $mime, 'replacement_byte_size' => $byteSize, 'replacement_upload_id' => $multipart['upload_id'], 'validation_error' => null]);
            $campaign->increment('draft_revision');
            $response = ['data' => $asset->toApi(), 'upload' => $multipart];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);

            return [$response, false];
        });
    }

    /**
     * @param  list<array{number: int, e_tag: string}>  $parts
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public function completeReplacement(string $campaignId, string $assetId, string $commandId, int $expectedRevision, array $parts): array
    {
        return DB::transaction(function () use ($campaignId, $assetId, $commandId, $expectedRevision, $parts): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var Campaign $campaign */
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->draft_revision !== $expectedRevision) {
                throw new StaleRevision($campaign);
            }
            /** @var CampaignAsset $asset */
            $asset = CampaignAsset::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($assetId);
            abort_unless($asset->upload_status === CampaignAsset::STATUS_READY && $asset->replacement_upload_id !== null && $asset->replacement_declared_mime !== null && $asset->replacement_byte_size !== null, 422, 'This asset replacement cannot be completed.');

            $temporary = null;
            try {
                $stagingKey = "staging/assets/{$asset->getKey()}/replacement";
                $this->multipart->complete($stagingKey, $asset->replacement_upload_id, $parts);
                $temporary = tempnam(sys_get_temp_dir(), 'rpgays-asset-');
                if ($temporary === false) {
                    throw new \RuntimeException('Unable to create a validation workspace.');
                }
                $destination = fopen($temporary, 'wb');
                if ($destination === false) {
                    throw new \RuntimeException('Unable to open the validation workspace.');
                }
                stream_copy_to_stream($this->multipart->read($stagingKey), $destination);
                fclose($destination);
                if (filesize($temporary) !== $asset->replacement_byte_size) {
                    throw new \RuntimeException('The uploaded byte size does not match the declared size.');
                }
                $actualMime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary);
                if (! is_string($actualMime) || $actualMime !== $asset->replacement_declared_mime) {
                    throw new \RuntimeException('The uploaded file content does not match its declared media type.');
                }
                $metadata = $asset->kind === 'image' ? (function () use ($temporary): array {
                    $dimensions = getimagesize($temporary);
                    if ($dimensions === false) {
                        throw new \RuntimeException('The uploaded image has invalid dimensions.');
                    }

                    return ['width' => $dimensions[0], 'height' => $dimensions[1]];
                })() : $this->metadataExtractor->duration($temporary);
                $hash = hash_file('sha256', $temporary);
                $key = "assets/sha256/{$hash}";
                $this->multipart->promote($stagingKey, $key);
                $oldStorageKey = $asset->storage_key;
                $asset->update(['original_filename' => $asset->replacement_original_filename, 'declared_mime' => $asset->replacement_declared_mime, 'validated_mime' => $actualMime, 'byte_size' => $asset->replacement_byte_size, 'sha256' => $hash, 'storage_key' => $key, 'metadata' => $metadata, 'validation_error' => null, 'replacement_original_filename' => null, 'replacement_declared_mime' => null, 'replacement_byte_size' => null, 'replacement_upload_id' => null]);
                if ($oldStorageKey !== null && $oldStorageKey !== $key && ! CampaignAsset::query()->where('storage_key', $oldStorageKey)->exists()) {
                    $this->multipart->delete($oldStorageKey);
                }
            } catch (\Throwable $exception) {
                $asset->update(['replacement_original_filename' => null, 'replacement_declared_mime' => null, 'replacement_byte_size' => null, 'replacement_upload_id' => null, 'validation_error' => $exception->getMessage()]);
            } finally {
                if (is_string($temporary) && is_file($temporary)) {
                    unlink($temporary);
                }
            }
            $campaign->increment('draft_revision');
            $response = ['data' => $asset->toApi()];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);

            return [$response, false];
        });
    }

    /** @return array{upload_id: string, part_size: int, parts: list<array{number: int, url: string}>} */
    private function startMultipartUpload(string $key, string $mime, int $byteSize): array
    {
        try {
            return $this->multipart->initiate($key, $mime, $byteSize);
        } catch (\Throwable) {
            abort(503, 'Media storage is unavailable. Please try again.');
        }
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function archive(string $campaignId, string $assetId, string $commandId, int $expectedRevision): array
    {
        return DB::transaction(function () use ($campaignId, $assetId, $commandId, $expectedRevision): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var Campaign $campaign */
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->draft_revision !== $expectedRevision) {
                throw new StaleRevision($campaign);
            }
            /** @var CampaignAsset $asset */
            $asset = CampaignAsset::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($assetId);
            abort_if($asset->archived_at !== null, 422, 'This asset is already archived.');
            abort_if($asset->upload_status === CampaignAsset::STATUS_INITIATED, 422, 'Complete or cancel this upload before archiving it.');
            abort_if($this->references->isReferenced($asset), 422, 'This asset is still referenced by authored or immutable campaign content.');

            $asset->archived_at = now()->toImmutable();
            $asset->save();
            $campaign->increment('draft_revision');
            $asset->refresh();
            $response = ['data' => $asset->toApi()];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);
            SessionEvent::query()->create(['campaign_id' => $campaignId, 'actor_type' => 'control', 'event_type' => 'asset.archived', 'command_id' => $commandId, 'payload' => ['asset_id' => $assetId], 'occurred_at' => now()]);
            OutboxEvent::query()->create(['aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'topic' => 'control.campaigns', 'payload' => ['event_type' => 'asset.archived', 'command_id' => $commandId, 'revision' => $campaign->draft_revision], 'occurred_at' => now()]);

            return [$response, false];
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function purge(string $campaignId, string $assetId, string $commandId, int $expectedRevision): array
    {
        return DB::transaction(function () use ($campaignId, $assetId, $commandId, $expectedRevision): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var Campaign $campaign */
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->draft_revision !== $expectedRevision) {
                throw new StaleRevision($campaign);
            }
            /** @var CampaignAsset $asset */
            $asset = CampaignAsset::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($assetId);
            abort_if($asset->upload_status === CampaignAsset::STATUS_INITIATED, 422, 'Complete or cancel this upload before deleting it.');
            abort_if($this->references->isReferenced($asset), 422, 'This asset is still referenced by authored or immutable campaign content.');

            $storageKey = $asset->storage_key;
            DB::table('campaign_asset_collection_items')->where('campaign_asset_id', $asset->getKey())->delete();
            $asset->delete();
            if ($storageKey !== null && ! CampaignAsset::query()->where('storage_key', $storageKey)->exists()) {
                $this->multipart->delete($storageKey);
            }

            $campaign->increment('draft_revision');
            $response = ['data' => ['id' => $assetId]];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);
            SessionEvent::query()->create(['campaign_id' => $campaignId, 'actor_type' => 'control', 'event_type' => 'asset.deleted', 'command_id' => $commandId, 'payload' => ['asset_id' => $assetId], 'occurred_at' => now()]);
            OutboxEvent::query()->create(['aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'topic' => 'control.campaigns', 'payload' => ['event_type' => 'asset.deleted', 'command_id' => $commandId, 'revision' => $campaign->draft_revision], 'occurred_at' => now()]);

            return [$response, false];
        });
    }
}
