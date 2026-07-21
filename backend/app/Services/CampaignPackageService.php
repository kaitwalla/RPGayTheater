<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AudioCue;
use App\Models\Campaign;
use App\Models\CampaignAsset;
use App\Models\CampaignMap;
use App\Models\CampaignRevision;
use App\Models\DicePreset;
use App\Models\MapFogMask;
use App\Models\MapToken;
use App\Models\NonPlayerCharacter;
use App\Models\NpcState;
use App\Models\OutboxEvent;
use App\Models\PlayerCharacter;
use App\Models\ProcessedCommand;
use App\Models\Scene;
use App\Models\SceneBackdrop;
use App\Models\SessionEvent;
use App\Models\StagePreset;
use App\Models\StagePresetEntry;
use App\Models\VideoCue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;

class CampaignPackageService
{
    private const MAX_MEDIA_BYTES = 512 * 1024 * 1024;

    /** @var list<string> */
    private const COLLECTIONS = ['assets', 'player_characters', 'npcs', 'npc_states', 'audio_cues', 'stage_presets', 'stage_preset_entries', 'scenes', 'scene_backdrops', 'maps', 'map_fog_masks', 'map_tokens', 'video_cues', 'dice_presets'];

    public function __construct(private readonly S3MultipartUploadService $storage) {}

    /** @return array{path: string, filename: string} */
    public function export(CampaignRevision $revision): array
    {
        $path = tempnam(sys_get_temp_dir(), 'rpgays-package-');
        if ($path === false) {
            throw new RuntimeException('Unable to create package workspace.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create campaign package.');
        }
        $manifest = $revision->manifest;
        $media = [];
        $writtenMedia = [];
        foreach ($manifest['assets'] as $asset) {
            if (! is_array($asset) || ! isset($asset['id'], $asset['sha256'], $asset['storage_key'])) {
                throw new RuntimeException('The revision asset manifest is invalid.');
            }
            $entry = 'media/'.$asset['sha256'];
            $contents = stream_get_contents($this->storage->read((string) $asset['storage_key']));
            if ($contents === false) {
                throw new RuntimeException('Unable to read a packaged asset.');
            }
            if (! hash_equals((string) $asset['sha256'], hash('sha256', $contents))) {
                throw new RuntimeException('A packaged asset does not match its immutable checksum.');
            }
            if (! isset($writtenMedia[$entry])) {
                $zip->addFromString($entry, $contents);
                $writtenMedia[$entry] = true;
            }
            $media[] = ['asset_id' => $asset['id'], 'path' => $entry, 'sha256' => $asset['sha256']];
        }
        $metadata = ['package_schema_version' => 1, 'campaign_revision_id' => $revision->getKey(), 'campaign_id' => $revision->campaign_id, 'revision_number' => $revision->number, 'manifest_hash' => $revision->manifest_hash, 'media' => $media];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('package.json', json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return ['path' => $path, 'filename' => "campaign-{$revision->campaign_id}-revision-{$revision->number}.zip"];
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function import(string $commandId, string $path): array
    {
        if ($previous = ProcessedCommand::query()->find($commandId)?->response) {
            return [$previous, true];
        }
        $package = $this->validate($path);
        $storedKeys = [];

        try {
            return DB::transaction(function () use ($commandId, $path, $package, &$storedKeys): array {
                if ($previous = ProcessedCommand::query()->find($commandId)?->response) {
                    return [$previous, true];
                }
                $campaign = $this->store($path, $package, $storedKeys);
                $response = ['data' => $campaign->toApi()];
                ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaign->id, 'response' => $response]);
                SessionEvent::query()->create(['campaign_id' => $campaign->id, 'actor_type' => 'control', 'event_type' => 'campaign.imported', 'command_id' => $commandId, 'payload' => ['campaign' => $response['data']], 'occurred_at' => now()]);
                OutboxEvent::query()->create(['aggregate_type' => 'campaign', 'aggregate_id' => $campaign->id, 'topic' => 'control.campaigns', 'payload' => ['event_type' => 'campaign.imported', 'command_id' => $commandId, 'revision' => $campaign->draft_revision], 'occurred_at' => now()]);

                return [$response, false];
            });
        } catch (Throwable $exception) {
            foreach ($storedKeys as $key) {
                try {
                    $this->storage->delete($key);
                } catch (Throwable) {
                    // A storage-cleanup failure cannot safely hide the original import error.
                }
            }

            throw $exception;
        }
    }

    /**
     * @param  array{manifest: array<string, mixed>, media: array<string, array{path: string, sha256: string, byte_size: int, mime: string}>}  $package
     * @param  list<string>  $storedKeys
     */
    private function store(string $path, array $package, array &$storedKeys): Campaign
    {
        $manifest = $package['manifest'];
        $campaign = Campaign::query()->create(['name' => $this->string($this->record($manifest, 'campaign'), 'name')]);
        /** @var array{assets: array<string, string>, player_characters: array<string, string>, npcs: array<string, string>, npc_states: array<string, string>, audio_cues: array<string, string>, stage_presets: array<string, string>, scenes: array<string, string>, maps: array<string, string>} $ids */
        $ids = ['assets' => [], 'player_characters' => [], 'npcs' => [], 'npc_states' => [], 'audio_cues' => [], 'stage_presets' => [], 'scenes' => [], 'maps' => []];
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new InvalidArgumentException('The campaign package could not be opened.');
        }

        try {
            foreach ($this->records($manifest, 'assets') as $asset) {
                $sourceId = $this->string($asset, 'id');
                $id = (string) Str::uuid7();
                $media = $package['media'][$sourceId];
                $stream = $zip->getStream($media['path']);
                if (! is_resource($stream)) {
                    throw new InvalidArgumentException('A packaged media file could not be read.');
                }
                $key = "assets/sha256/{$media['sha256']}/{$id}";
                $storedKeys[] = $key;
                try {
                    $this->storage->put($key, $stream, $media['mime']);
                } finally {
                    fclose($stream);
                }
                $importedAsset = CampaignAsset::query()->forceCreate(['id' => $id, 'campaign_id' => $campaign->id, 'original_filename' => $this->string($asset, 'original_filename'), 'kind' => $this->string($asset, 'kind'), 'declared_mime' => $media['mime'], 'validated_mime' => $media['mime'], 'byte_size' => $media['byte_size'], 'sha256' => $media['sha256'], 'storage_key' => $key, 'upload_status' => CampaignAsset::STATUS_READY, 'metadata' => $asset['metadata'] ?? null]);
                $ids['assets'][$sourceId] = $importedAsset->id;
            }

            foreach ($this->records($manifest, 'player_characters') as $record) {
                $ids['player_characters'][$this->string($record, 'id')] = $this->create(PlayerCharacter::class, ['campaign_id' => $campaign->id, 'avatar_asset_id' => $this->nullableReference($ids['assets'], $record['avatar_asset_id'] ?? null), 'name' => $this->string($record, 'name'), 'pronouns' => $record['pronouns'] ?? null, 'public_description' => $record['public_description'] ?? null, 'sort_order' => $this->integer($record, 'sort_order')]);
            }
            foreach ($this->records($manifest, 'npcs') as $record) {
                $ids['npcs'][$this->string($record, 'id')] = $this->create(NonPlayerCharacter::class, ['campaign_id' => $campaign->id, 'normal_asset_id' => $this->reference($ids['assets'], $record, 'normal_asset_id'), 'name' => $this->string($record, 'name'), 'pronouns' => $record['pronouns'] ?? null, 'public_description' => $record['public_description'] ?? null, 'native_facing' => $this->string($record, 'native_facing')]);
            }
            foreach ($this->records($manifest, 'npc_states') as $record) {
                $ids['npc_states'][$this->string($record, 'id')] = $this->create(NpcState::class, ['npc_id' => $this->reference($ids['npcs'], $record, 'npc_id'), 'asset_id' => $this->reference($ids['assets'], $record, 'asset_id'), 'name' => $this->string($record, 'name'), 'sort_order' => $this->integer($record, 'sort_order')]);
            }
            foreach ($this->records($manifest, 'audio_cues') as $record) {
                $ids['audio_cues'][$this->string($record, 'id')] = $this->create(AudioCue::class, ['campaign_id' => $campaign->id, 'scene_id' => null, 'asset_id' => $this->reference($ids['assets'], $record, 'asset_id'), 'name' => $this->string($record, 'name'), 'kind' => $this->string($record, 'kind'), 'loop' => $this->boolean($record, 'loop'), 'default_volume' => $this->integer($record, 'default_volume'), 'sort_order' => $this->integer($record, 'sort_order')]);
            }
            foreach ($this->records($manifest, 'stage_presets') as $record) {
                $ids['stage_presets'][$this->string($record, 'id')] = $this->create(StagePreset::class, ['campaign_id' => $campaign->id, 'name' => $this->string($record, 'name'), 'tween_duration_ms' => $this->integer($record, 'tween_duration_ms'), 'tween_easing' => $this->string($record, 'tween_easing')]);
            }
            foreach ($this->records($manifest, 'stage_preset_entries') as $record) {
                $this->create(StagePresetEntry::class, ['stage_preset_id' => $this->reference($ids['stage_presets'], $record, 'stage_preset_id'), 'npc_id' => $this->reference($ids['npcs'], $record, 'npc_id'), 'npc_state_id' => $this->nullableReference($ids['npc_states'], $record['npc_state_id'] ?? null), 'position_x' => $this->number($record, 'position_x'), 'position_y' => $this->number($record, 'position_y'), 'scale' => $this->number($record, 'scale'), 'layer_order' => $this->integer($record, 'layer_order'), 'facing' => $this->string($record, 'facing')]);
            }
            foreach ($this->records($manifest, 'scenes') as $record) {
                $ids['scenes'][$this->string($record, 'id')] = $this->create(Scene::class, ['campaign_id' => $campaign->id, 'name' => $this->string($record, 'name'), 'primary_backdrop_asset_id' => $this->nullableReference($ids['assets'], $record['primary_backdrop_asset_id'] ?? null), 'default_music_cue_id' => $this->nullableReference($ids['audio_cues'], $record['default_music_cue_id'] ?? null), 'base_stage_preset_id' => $this->nullableReference($ids['stage_presets'], $record['base_stage_preset_id'] ?? null), 'transition' => $this->string($record, 'transition'), 'transition_duration_ms' => $this->integer($record, 'transition_duration_ms'), 'sort_order' => $this->integer($record, 'sort_order')]);
            }
            foreach ($this->records($manifest, 'audio_cues') as $record) {
                AudioCue::query()->findOrFail($this->reference($ids['audio_cues'], $record, 'id'))->update(['scene_id' => $this->nullableReference($ids['scenes'], $record['scene_id'] ?? null)]);
            }
            foreach ($this->records($manifest, 'scene_backdrops') as $record) {
                $this->create(SceneBackdrop::class, ['scene_id' => $this->reference($ids['scenes'], $record, 'scene_id'), 'asset_id' => $this->reference($ids['assets'], $record, 'asset_id'), 'name' => $this->string($record, 'name'), 'sort_order' => $this->integer($record, 'sort_order')]);
            }
            foreach ($this->records($manifest, 'maps') as $record) {
                $ids['maps'][$this->string($record, 'id')] = $this->create(CampaignMap::class, ['campaign_id' => $campaign->id, 'image_asset_id' => $this->reference($ids['assets'], $record, 'image_asset_id'), 'name' => $this->string($record, 'name'), 'sort_order' => $this->integer($record, 'sort_order')]);
            }
            foreach ($this->records($manifest, 'map_fog_masks') as $record) {
                $this->create(MapFogMask::class, ['map_id' => $this->reference($ids['maps'], $record, 'map_id'), 'asset_id' => $this->reference($ids['assets'], $record, 'asset_id')]);
            }
            foreach ($this->records($manifest, 'map_tokens') as $record) {
                $this->create(MapToken::class, ['map_id' => $this->reference($ids['maps'], $record, 'map_id'), 'token_type' => $this->string($record, 'token_type'), 'player_character_id' => $this->nullableReference($ids['player_characters'], $record['player_character_id'] ?? null), 'npc_id' => $this->nullableReference($ids['npcs'], $record['npc_id'] ?? null), 'asset_id' => $this->nullableReference($ids['assets'], $record['asset_id'] ?? null), 'label' => $record['label'] ?? null, 'position_x' => $this->number($record, 'position_x'), 'position_y' => $this->number($record, 'position_y'), 'scale' => $this->number($record, 'scale'), 'sort_order' => $this->integer($record, 'sort_order')]);
            }
            foreach ($this->records($manifest, 'video_cues') as $record) {
                $this->create(VideoCue::class, ['campaign_id' => $campaign->id, 'scene_id' => $this->nullableReference($ids['scenes'], $record['scene_id'] ?? null), 'primary_asset_id' => $this->reference($ids['assets'], $record, 'primary_asset_id'), 'fallback_asset_id' => $this->nullableReference($ids['assets'], $record['fallback_asset_id'] ?? null), 'name' => $this->string($record, 'name'), 'completion_mode' => $this->string($record, 'completion_mode'), 'target_scene_id' => $this->nullableReference($ids['scenes'], $record['target_scene_id'] ?? null), 'music_during' => $this->string($record, 'music_during'), 'music_after' => $this->string($record, 'music_after'), 'embedded_audio_volume' => $this->integer($record, 'embedded_audio_volume'), 'embedded_audio_muted' => $this->boolean($record, 'embedded_audio_muted'), 'sort_order' => $this->integer($record, 'sort_order')]);
            }
            foreach ($this->records($manifest, 'dice_presets') as $record) {
                $this->create(DicePreset::class, ['campaign_id' => $campaign->id, 'name' => $this->string($record, 'name'), 'expression' => $this->string($record, 'expression'), 'default_visibility' => $this->string($record, 'default_visibility'), 'is_default' => $this->boolean($record, 'is_default'), 'sort_order' => $this->integer($record, 'sort_order')]);
            }
        } finally {
            $zip->close();
        }

        return $campaign;
    }

    /** @return array{manifest: array<string, mixed>, media: array<string, array{path: string, sha256: string, byte_size: int, mime: string}>} */
    private function validate(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true || $zip->numFiles > 10_000) {
            throw new InvalidArgumentException('The campaign package is invalid.');
        }
        try {
            $manifest = $this->json($zip->getFromName('manifest.json'), 'manifest.json');
            $metadata = $this->json($zip->getFromName('package.json'), 'package.json');
            if (($manifest['schema_version'] ?? null) !== 1 || ($metadata['package_schema_version'] ?? null) !== 1 || ! isset($metadata['manifest_hash']) || ! is_string($metadata['manifest_hash']) || ! hash_equals($metadata['manifest_hash'], hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)))) {
                throw new InvalidArgumentException('The campaign package manifest is invalid.');
            }
            $this->record($manifest, 'campaign');
            foreach (self::COLLECTIONS as $collection) {
                $this->records($manifest, $collection);
            }
            if (! isset($metadata['media']) || ! is_array($metadata['media'])) {
                throw new InvalidArgumentException('The campaign package media index is invalid.');
            }
            /** @var array<string, array{sha256: string, byte_size: int, mime: string}> $assets */
            $assets = [];
            foreach ($this->records($manifest, 'assets') as $asset) {
                $id = $this->string($asset, 'id');
                $hash = $this->string($asset, 'sha256');
                if (! preg_match('/^[a-f0-9]{64}$/', $hash) || isset($assets[$id])) {
                    throw new InvalidArgumentException('The campaign package asset manifest is invalid.');
                }
                $assets[$id] = ['sha256' => $hash, 'byte_size' => $this->integer($asset, 'byte_size'), 'mime' => $this->string($asset, 'validated_mime')];
            }
            /** @var array<string, array{path: string, sha256: string, byte_size: int, mime: string}> $media */
            $media = [];
            $totalMediaBytes = 0;
            $expectedNames = ['manifest.json' => true, 'package.json' => true];
            foreach ($metadata['media'] as $entry) {
                if (! is_array($entry)) {
                    throw new InvalidArgumentException('The campaign package media index is invalid.');
                }
                $assetId = $this->string($entry, 'asset_id');
                $mediaPath = $this->string($entry, 'path');
                $hash = $this->string($entry, 'sha256');
                if (! isset($assets[$assetId]) || isset($media[$assetId]) || $assets[$assetId]['sha256'] !== $hash || $mediaPath !== 'media/'.$hash) {
                    throw new InvalidArgumentException('The campaign package media index is invalid.');
                }
                $totalMediaBytes += $assets[$assetId]['byte_size'];
                if ($totalMediaBytes > self::MAX_MEDIA_BYTES) {
                    throw new InvalidArgumentException('The campaign package media is too large.');
                }
                $this->validateMedia($zip, $mediaPath, $assets[$assetId]['byte_size'], $hash);
                $expectedNames[$mediaPath] = true;
                $media[$assetId] = ['path' => $mediaPath, 'sha256' => $hash, 'byte_size' => $assets[$assetId]['byte_size'], 'mime' => $assets[$assetId]['mime']];
            }
            if (array_keys($assets) !== array_keys($media) || $zip->numFiles !== count($expectedNames)) {
                throw new InvalidArgumentException('The campaign package contains missing or unexpected files.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (! is_string($name) || ! isset($expectedNames[$name])) {
                    throw new InvalidArgumentException('The campaign package contains unsafe file paths.');
                }
            }

            return ['manifest' => $manifest, 'media' => $media];
        } finally {
            $zip->close();
        }
    }

    private function validateMedia(ZipArchive $zip, string $path, int $byteSize, string $hash): void
    {
        $stat = $zip->statName($path);
        if (! is_array($stat) || $stat['size'] !== $byteSize || $byteSize < 1 || $byteSize > self::MAX_MEDIA_BYTES) {
            throw new InvalidArgumentException('A packaged media file has an invalid size.');
        }
        $stream = $zip->getStream($path);
        if (! is_resource($stream)) {
            throw new InvalidArgumentException('A packaged media file could not be read.');
        }
        $context = hash_init('sha256');
        while (! feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                fclose($stream);
                throw new InvalidArgumentException('A packaged media file could not be read.');
            }
            hash_update($context, $chunk);
        }
        fclose($stream);
        if (! hash_equals($hash, hash_final($context))) {
            throw new InvalidArgumentException('A packaged media file checksum does not match.');
        }
    }

    /** @return array<string, mixed> */
    private function json(string|false $contents, string $name): array
    {
        if ($contents === false) {
            throw new InvalidArgumentException("The campaign package is missing {$name}.");
        }
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException("The campaign package {$name} is not valid JSON.");
        }
        if (! is_array($decoded)) {
            throw new InvalidArgumentException("The campaign package {$name} is invalid.");
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array<string, mixed>>
     */
    private function records(array $manifest, string $name): array
    {
        if (! isset($manifest[$name]) || ! is_array($manifest[$name]) || ! array_is_list($manifest[$name])) {
            throw new InvalidArgumentException("The campaign package {$name} collection is invalid.");
        }
        $records = [];
        foreach ($manifest[$name] as $record) {
            if (! is_array($record)) {
                throw new InvalidArgumentException("The campaign package {$name} collection is invalid.");
            }
            $records[] = $record;
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function record(array $values, string $name): array
    {
        $record = $values[$name] ?? null;
        if (! is_array($record)) {
            throw new InvalidArgumentException("The campaign package {$name} record is invalid.");
        }

        return $record;
    }

    /** @param array<string, mixed> $record */
    private function string(array $record, string $field): string
    {
        $value = $record[$field] ?? null;
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("The campaign package {$field} value is invalid.");
        }

        return $value;
    }

    /** @param array<string, mixed> $record */
    private function integer(array $record, string $field): int
    {
        $value = $record[$field] ?? null;
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException("The campaign package {$field} value is invalid.");
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $record */
    private function number(array $record, string $field): float
    {
        $value = $record[$field] ?? null;
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            throw new InvalidArgumentException("The campaign package {$field} value is invalid.");
        }

        return (float) $value;
    }

    /** @param array<string, mixed> $record */
    private function boolean(array $record, string $field): bool
    {
        if (! isset($record[$field]) || ! is_bool($record[$field])) {
            throw new InvalidArgumentException("The campaign package {$field} value is invalid.");
        }

        return $record[$field];
    }

    /**
     * @param  array<string, string>  $ids
     * @param  array<string, mixed>  $record
     */
    private function reference(array $ids, array $record, string $field): string
    {
        return $this->nullableReference($ids, $record[$field] ?? null) ?? throw new InvalidArgumentException("The campaign package {$field} reference is invalid.");
    }

    /** @param array<string, string> $ids */
    private function nullableReference(array $ids, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! isset($ids[$value])) {
            throw new InvalidArgumentException('The campaign package contains an invalid reference.');
        }

        return $ids[$value];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $attributes
     */
    private function create(string $model, array $attributes): string
    {
        /** @var Model $record */
        $record = $model::query()->create(['id' => (string) Str::uuid7()] + $attributes);

        return (string) $record->getKey();
    }
}
