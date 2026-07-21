<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StaleRevision;
use App\Exceptions\StudioRecordInUse;
use App\Models\AudioCue;
use App\Models\Campaign;
use App\Models\CampaignAsset;
use App\Models\CampaignAssetCollection;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class CampaignStudioService
{
    /** @var array<string, array{class-string<Model>, list<string>, string}> */
    private const RESOURCES = [
        'assets' => [CampaignAsset::class, ['label'], 'campaign_id'],
        'player-characters' => [PlayerCharacter::class, ['name', 'pronouns', 'public_description', 'avatar_asset_id', 'sort_order'], 'campaign_id'],
        'npcs' => [NonPlayerCharacter::class, ['name', 'pronouns', 'public_description', 'normal_asset_id', 'native_facing', 'sort_order'], 'campaign_id'],
        'npc-states' => [NpcState::class, ['name', 'asset_id', 'sort_order'], 'npc_id'],
        'scenes' => [Scene::class, ['name', 'primary_backdrop_asset_id', 'default_music_cue_id', 'base_stage_preset_id', 'transition', 'transition_duration_ms', 'sort_order'], 'campaign_id'],
        'scene-backdrops' => [SceneBackdrop::class, ['name', 'asset_id', 'sort_order'], 'scene_id'],
        'stage-presets' => [StagePreset::class, ['name', 'tween_duration_ms', 'tween_easing', 'sort_order'], 'campaign_id'],
        'stage-preset-entries' => [StagePresetEntry::class, ['npc_id', 'npc_state_id', 'position_x', 'position_y', 'scale', 'layer_order', 'facing'], 'stage_preset_id'],
        'maps' => [CampaignMap::class, ['name', 'image_asset_id', 'sort_order'], 'campaign_id'],
        'map-tokens' => [MapToken::class, ['token_type', 'player_character_id', 'npc_id', 'asset_id', 'label', 'position_x', 'position_y', 'scale', 'sort_order'], 'map_id'],
        'audio-cues' => [AudioCue::class, ['name', 'scene_id', 'asset_id', 'kind', 'loop', 'default_volume', 'sort_order'], 'campaign_id'],
        'video-cues' => [VideoCue::class, ['name', 'scene_id', 'primary_asset_id', 'fallback_asset_id', 'completion_mode', 'target_scene_id', 'music_during', 'music_after', 'embedded_audio_volume', 'embedded_audio_muted', 'sort_order'], 'campaign_id'],
        'dice-presets' => [DicePreset::class, ['name', 'expression', 'default_visibility', 'is_default', 'sort_order'], 'campaign_id'],
        'asset-collections' => [CampaignAssetCollection::class, ['name', 'sort_order'], 'campaign_id'],
    ];

    /** @return array<string, mixed> */
    public function snapshot(Campaign $campaign): array
    {
        $campaignId = $campaign->getKey();
        $records = [
            'assets' => CampaignAsset::query()->where('campaign_id', $campaignId)->latest()->get()->map(fn (CampaignAsset $item) => $item->toApi())->values(),
            'player_characters' => PlayerCharacter::query()->where('campaign_id', $campaignId)->orderBy('sort_order')->get()->map->toApi()->values(),
            'npcs' => NonPlayerCharacter::query()->where('campaign_id', $campaignId)->orderBy('sort_order')->orderBy('name')->get()->map(fn (Model $item) => $item->attributesToArray())->values(),
            'npc_states' => NpcState::query()->whereIn('npc_id', NonPlayerCharacter::query()->where('campaign_id', $campaignId)->select('id'))->orderBy('sort_order')->get()->map(fn (Model $item) => $item->attributesToArray())->values(),
            'scenes' => Scene::query()->where('campaign_id', $campaignId)->orderBy('sort_order')->get()->map(fn (Model $item) => $item->attributesToArray())->values(),
            'scene_backdrops' => SceneBackdrop::query()->whereIn('scene_id', Scene::query()->where('campaign_id', $campaignId)->select('id'))->orderBy('sort_order')->get()->map(fn (Model $item) => $item->attributesToArray())->values(),
            'stage_presets' => StagePreset::query()->where('campaign_id', $campaignId)->orderBy('sort_order')->orderBy('name')->get()->map(fn (Model $item) => $item->attributesToArray())->values(),
            'stage_preset_entries' => StagePresetEntry::query()->whereIn('stage_preset_id', StagePreset::query()->where('campaign_id', $campaignId)->select('id'))->orderBy('layer_order')->get()->map(fn (Model $item) => $item->attributesToArray())->values(),
            'maps' => CampaignMap::query()->where('campaign_id', $campaignId)->orderBy('sort_order')->get()->map(fn (Model $item) => $item->attributesToArray())->values(),
            'map_fog_masks' => MapFogMask::query()->whereIn('map_id', CampaignMap::query()->where('campaign_id', $campaignId)->select('id'))->get()->map(fn (Model $item) => $item->attributesToArray())->values(),
            'map_tokens' => MapToken::query()->whereIn('map_id', CampaignMap::query()->where('campaign_id', $campaignId)->select('id'))->orderBy('sort_order')->get()->map(fn (Model $item) => $item->attributesToArray())->values(),
            'audio_cues' => AudioCue::query()->where('campaign_id', $campaignId)->orderBy('sort_order')->get()->map(fn (Model $item) => $item->attributesToArray())->values(),
            'video_cues' => VideoCue::query()->where('campaign_id', $campaignId)->orderBy('sort_order')->get()->map(fn (Model $item) => $item->attributesToArray())->values(),
            'dice_presets' => DicePreset::query()->where('campaign_id', $campaignId)->orderBy('sort_order')->get()->map(fn (Model $item) => $item->attributesToArray())->values(),
            'asset_collections' => CampaignAssetCollection::query()->where('campaign_id', $campaignId)->orderBy('sort_order')->get()->map(function (CampaignAssetCollection $item): array {
                $data = $item->attributesToArray();
                $data['asset_ids'] = DB::table('campaign_asset_collection_items')->where('campaign_asset_collection_id', $item->getKey())->pluck('campaign_asset_id')->values();

                return $data;
            })->values(),
        ];

        return ['campaign' => $campaign->toApi(), 'records' => $records];
    }

    /** @param array<string, mixed> $patch
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public function update(string $campaignId, string $resource, string $id, string $commandId, int $expectedRevision, array $patch): array
    {
        return DB::transaction(function () use ($campaignId, $resource, $id, $commandId, $expectedRevision, $patch): array {
            if ($previous = $this->previousResponse($commandId)) {
                return [$previous, true];
            }
            $campaign = $this->lockedCampaign($campaignId, $expectedRevision);
            $record = $this->recordQuery($resource, $campaignId)->lockForUpdate()->findOrFail($id);
            $allowed = array_flip($this->definition($resource)[1]);
            $changes = array_intersect_key($patch, $allowed);
            $updatesCollectionAssets = $resource === 'asset-collections' && array_key_exists('asset_ids', $patch);
            if ($changes === [] && ! $updatesCollectionAssets) {
                throw ValidationException::withMessages(['patch' => 'No editable fields were supplied.']);
            }
            if ($resource === 'assets' && array_key_exists('label', $changes)) {
                $changes['label'] = $this->nullableString($changes['label'], 120);
            }
            if ($resource === 'asset-collections' && array_key_exists('name', $changes)) {
                $changes['name'] = $this->requiredString($changes['name'], 120);
            }
            if ($changes !== []) {
                $record->fill($changes);
                $record->save();
            }
            if ($updatesCollectionAssets) {
                $this->syncCollectionAssets($campaignId, $record->getKey(), $patch['asset_ids']);
            }

            return $this->recordMutation($campaign, $commandId, 'campaign.studio_updated', ['data' => ['record' => $record->fresh()?->attributesToArray(), 'campaign' => $this->incrementedCampaign($campaign)->toApi()]]);
        });
    }

    /** @param list<string> $ids
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public function reorder(string $campaignId, string $resource, string $commandId, int $expectedRevision, array $ids): array
    {
        return DB::transaction(function () use ($campaignId, $resource, $commandId, $expectedRevision, $ids): array {
            if ($previous = $this->previousResponse($commandId)) {
                return [$previous, true];
            }
            $campaign = $this->lockedCampaign($campaignId, $expectedRevision);
            abort_unless(in_array('sort_order', $this->definition($resource)[1], true), 422, 'This resource cannot be reordered.');
            $records = $this->recordQuery($resource, $campaignId)->lockForUpdate()->whereIn('id', $ids)->get()->keyBy('id');
            abort_unless($records->count() === count($ids), 422, 'The order must only contain records from this campaign.');
            foreach ($ids as $index => $id) {
                $record = $records->get($id);
                abort_unless($record instanceof Model, 422, 'The order must only contain records from this campaign.');
                $record->update(['sort_order' => $index]);
            }

            return $this->recordMutation($campaign, $commandId, 'campaign.studio_reordered', ['data' => ['campaign' => $this->incrementedCampaign($campaign)->toApi()]]);
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function createCollection(string $campaignId, string $commandId, int $expectedRevision, string $name): array
    {
        return DB::transaction(function () use ($campaignId, $commandId, $expectedRevision, $name): array {
            if ($previous = $this->previousResponse($commandId)) {
                return [$previous, true];
            }
            $campaign = $this->lockedCampaign($campaignId, $expectedRevision);
            $collection = CampaignAssetCollection::query()->create(['campaign_id' => $campaignId, 'name' => $this->requiredString($name, 120), 'sort_order' => (int) CampaignAssetCollection::query()->where('campaign_id', $campaignId)->max('sort_order') + 1]);

            return $this->recordMutation($campaign, $commandId, 'campaign.collection_created', ['data' => ['record' => $collection->attributesToArray(), 'campaign' => $this->incrementedCampaign($campaign)->toApi()]]);
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function destroy(string $campaignId, string $resource, string $id, string $commandId, int $expectedRevision): array
    {
        return DB::transaction(function () use ($campaignId, $resource, $id, $commandId, $expectedRevision): array {
            if ($previous = $this->previousResponse($commandId)) {
                return [$previous, true];
            }
            $campaign = $this->lockedCampaign($campaignId, $expectedRevision);
            $record = $this->recordQuery($resource, $campaignId)->lockForUpdate()->findOrFail($id);
            $usages = $this->usages($campaign, $resource, $id);
            if ($usages !== []) {
                throw new StudioRecordInUse($usages);
            }
            if ($record instanceof CampaignAsset) {
                $record->archived_at = now()->toImmutable();
                $record->save();
            } else {
                if ($resource === 'asset-collections') {
                    DB::table('campaign_asset_collection_items')->where('campaign_asset_collection_id', $id)->delete();
                }
                $record->delete();
            }

            return $this->recordMutation($campaign, $commandId, 'campaign.studio_deleted', ['data' => ['id' => $id, 'campaign' => $this->incrementedCampaign($campaign)->toApi()]]);
        });
    }

    /** @return array{class-string<Model>, list<string>, string} */
    private function definition(string $resource): array
    {
        abort_unless(isset(self::RESOURCES[$resource]), 404);

        return self::RESOURCES[$resource];
    }

    /** @return Builder<Model> */
    private function recordQuery(string $resource, string $campaignId): Builder
    {
        [$class, , $owner] = $this->definition($resource);
        if ($owner === 'campaign_id') {
            return $class::query()->where('campaign_id', $campaignId);
        }
        $parents = match ($owner) {
            'npc_id' => NonPlayerCharacter::query()->where('campaign_id', $campaignId)->select('id'),
            'scene_id' => Scene::query()->where('campaign_id', $campaignId)->select('id'),
            'stage_preset_id' => StagePreset::query()->where('campaign_id', $campaignId)->select('id'),
            'map_id' => CampaignMap::query()->where('campaign_id', $campaignId)->select('id'),
            default => throw new LogicException("Unsupported resource owner: {$owner}"),
        };

        return $class::query()->whereIn($owner, $parents);
    }

    private function lockedCampaign(string $campaignId, int $expectedRevision): Campaign
    {
        /** @var Campaign $campaign */
        $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
        if ($campaign->draft_revision !== $expectedRevision) {
            throw new StaleRevision($campaign);
        }

        return $campaign;
    }

    private function incrementedCampaign(Campaign $campaign): Campaign
    {
        $campaign->increment('draft_revision');
        $campaign->refresh();

        return $campaign;
    }

    /** @param array<string, mixed> $response
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function recordMutation(Campaign $campaign, string $commandId, string $eventType, array $response): array
    {
        ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaign->getKey(), 'response' => $response]);
        SessionEvent::query()->create(['campaign_id' => $campaign->getKey(), 'actor_type' => 'control', 'event_type' => $eventType, 'command_id' => $commandId, 'payload' => $response['data'], 'occurred_at' => now()]);
        OutboxEvent::query()->create(['aggregate_type' => 'campaign', 'aggregate_id' => $campaign->getKey(), 'topic' => 'control.campaigns', 'payload' => ['event_type' => $eventType, 'command_id' => $commandId, 'revision' => $campaign->draft_revision], 'occurred_at' => now()]);

        return [$response, false];
    }

    /** @return array<string, mixed>|null */
    private function previousResponse(string $commandId): ?array
    {
        return ProcessedCommand::query()->find($commandId)?->response;
    }

    /** @param mixed $value */
    private function nullableString($value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->requiredString($value, $max);
    }

    /** @param mixed $value */
    private function requiredString($value, int $max): string
    {
        abort_unless(is_string($value) && trim($value) !== '' && mb_strlen(trim($value)) <= $max, 422, 'The supplied label is invalid.');

        return trim($value);
    }

    /** @param mixed $assetIds */
    private function syncCollectionAssets(string $campaignId, string $collectionId, $assetIds): void
    {
        abort_unless(is_array($assetIds) && array_is_list($assetIds) && count($assetIds) === count(array_unique($assetIds)), 422, 'Collection assets must be a unique list.');
        abort_unless(CampaignAsset::query()->where('campaign_id', $campaignId)->whereIn('id', $assetIds)->count() === count($assetIds), 422, 'Collections can only contain this campaign’s assets.');
        DB::table('campaign_asset_collection_items')->where('campaign_asset_collection_id', $collectionId)->delete();
        if ($assetIds !== []) {
            DB::table('campaign_asset_collection_items')->insert(array_map(fn (string $id) => ['campaign_asset_collection_id' => $collectionId, 'campaign_asset_id' => $id, 'created_at' => now(), 'updated_at' => now()], $assetIds));
        }
    }

    /** @return list<array{section: string, id: string, label: string}> */
    private function usages(Campaign $campaign, string $resource, string $id): array
    {
        $records = $this->snapshot($campaign)['records'];
        $ownSection = str_replace('-', '_', $resource);
        $usages = [];
        foreach ($records as $section => $items) {
            foreach ($items as $item) {
                if ($section === $ownSection && ($item['id'] ?? null) === $id) {
                    continue;
                }
                if ($this->contains($item, $id)) {
                    $usages[] = ['section' => $section, 'id' => (string) ($item['id'] ?? ''), 'label' => (string) ($item['name'] ?? $item['label'] ?? $item['original_filename'] ?? 'Untitled')];
                }
            }
        }
        foreach (CampaignRevision::query()->where('campaign_id', $campaign->getKey())->get() as $revision) {
            if ($this->contains($revision->manifest, $id)) {
                $usages[] = ['section' => 'published_revisions', 'id' => $revision->getKey(), 'label' => "Revision {$revision->number}"];
            }
        }

        return $usages;
    }

    private function contains(mixed $value, string $id): bool
    {
        if (is_string($value)) {
            return $value === $id;
        }
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if ($this->contains($item, $id)) {
                return true;
            }
        }

        return false;
    }
}
