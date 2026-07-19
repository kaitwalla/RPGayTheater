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
    public function __construct(private readonly S3MultipartUploadService $multipart) {}

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
            $multipart = $this->multipart->initiate("staging/assets/{$asset->getKey()}", $mime, $byteSize);
            $asset->upload_id = $multipart['upload_id'];
            $asset->save();
            $campaign->draft_revision++;
            $campaign->save();

            $asset->refresh();
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
                }
                $hash = hash_file('sha256', $temporary);
                unlink($temporary);
                $key = "assets/sha256/{$hash}";
                $this->multipart->promote($stagingKey, $key);
                $asset->update(['validated_mime' => $actualMime, 'sha256' => $hash, 'storage_key' => $key, 'upload_id' => null, 'upload_status' => CampaignAsset::STATUS_READY, 'metadata' => $metadata]);
            } catch (\Throwable $exception) {
                $asset->update(['upload_status' => CampaignAsset::STATUS_FAILED, 'validation_error' => $exception->getMessage()]);
            }
            $campaign->draft_revision++;
            $campaign->save();
            $asset->refresh();
            $response = ['data' => $asset->toApi()];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);

            return [$response, false];
        });
    }
}
