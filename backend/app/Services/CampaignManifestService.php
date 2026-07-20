<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AudioCue;
use App\Models\Campaign;
use App\Models\CampaignAsset;
use App\Models\CampaignMap;
use App\Models\DicePreset;
use App\Models\MapFogMask;
use App\Models\MapToken;
use App\Models\NonPlayerCharacter;
use App\Models\NpcState;
use App\Models\PlayerCharacter;
use App\Models\Scene;
use App\Models\SceneBackdrop;
use App\Models\StagePreset;
use App\Models\StagePresetEntry;
use App\Models\VideoCue;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CampaignManifestService
{
    /** @return array{valid: bool, issues: list<string>, summary: array<string, int>} */
    public function preflight(Campaign $campaign): array
    {
        if ($campaign->archived_at !== null) {
            return ['valid' => false, 'issues' => ['Archived campaigns cannot be published.'], 'summary' => []];
        }

        try {
            $manifest = $this->build($campaign);
        } catch (HttpExceptionInterface $exception) {
            return ['valid' => false, 'issues' => [$exception->getMessage()], 'summary' => []];
        }

        return [
            'valid' => true,
            'issues' => [],
            'summary' => [
                'assets' => count($manifest['assets']),
                'player_characters' => count($manifest['player_characters']),
                'npcs' => count($manifest['npcs']),
                'scenes' => count($manifest['scenes']),
                'maps' => count($manifest['maps']),
                'audio_cues' => count($manifest['audio_cues']),
                'video_cues' => count($manifest['video_cues']),
                'dice_presets' => count($manifest['dice_presets']),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function build(Campaign $campaign): array
    {
        $campaignId = $campaign->getKey();
        $assets = $this->arrays(CampaignAsset::query()->where('campaign_id', $campaignId)->availableForAuthoring()->orderBy('id')->get(['id', 'campaign_id', 'original_filename', 'kind', 'validated_mime', 'byte_size', 'sha256', 'storage_key', 'metadata']));
        $pcs = $this->arrays(PlayerCharacter::query()->where('campaign_id', $campaignId)->orderBy('sort_order')->orderBy('id')->get(['id', 'campaign_id', 'avatar_asset_id', 'name', 'pronouns', 'public_description', 'sort_order']));
        $npcs = $this->arrays(NonPlayerCharacter::query()->where('campaign_id', $campaignId)->orderBy('name')->orderBy('id')->get(['id', 'campaign_id', 'normal_asset_id', 'name', 'pronouns', 'public_description', 'native_facing']));
        $npcIds = array_column($npcs, 'id');
        $states = $this->arrays(NpcState::query()->whereIn('npc_id', $npcIds)->orderBy('npc_id')->orderBy('sort_order')->orderBy('id')->get(['id', 'npc_id', 'asset_id', 'name', 'sort_order']));
        $audioCues = $this->arrays(AudioCue::query()->where('campaign_id', $campaignId)->orderBy('sort_order')->orderBy('id')->get(['id', 'campaign_id', 'asset_id', 'name', 'kind', 'loop', 'default_volume', 'sort_order']));
        $presets = $this->arrays(StagePreset::query()->where('campaign_id', $campaignId)->orderBy('name')->orderBy('id')->get(['id', 'campaign_id', 'name', 'tween_duration_ms', 'tween_easing']));
        $presetIds = array_column($presets, 'id');
        $presetEntries = $this->arrays(StagePresetEntry::query()->whereIn('stage_preset_id', $presetIds)->orderBy('stage_preset_id')->orderBy('layer_order')->orderBy('id')->get(['id', 'stage_preset_id', 'npc_id', 'npc_state_id', 'position_x', 'position_y', 'scale', 'layer_order', 'facing']));
        $scenes = $this->arrays(Scene::query()->where('campaign_id', $campaignId)->orderBy('sort_order')->orderBy('id')->get(['id', 'campaign_id', 'name', 'primary_backdrop_asset_id', 'default_music_cue_id', 'base_stage_preset_id', 'transition', 'transition_duration_ms', 'sort_order']));
        $sceneIds = array_column($scenes, 'id');
        $backdrops = $this->arrays(SceneBackdrop::query()->whereIn('scene_id', $sceneIds)->orderBy('scene_id')->orderBy('sort_order')->orderBy('id')->get(['id', 'scene_id', 'asset_id', 'name', 'sort_order']));
        $maps = $this->arrays(CampaignMap::query()->where('campaign_id', $campaignId)->orderBy('sort_order')->orderBy('id')->get(['id', 'campaign_id', 'image_asset_id', 'name', 'sort_order']));
        $mapIds = array_column($maps, 'id');
        $fogMasks = $this->arrays(MapFogMask::query()->whereIn('map_id', $mapIds)->orderBy('map_id')->get(['id', 'map_id', 'asset_id']));
        $tokens = $this->arrays(MapToken::query()->whereIn('map_id', $mapIds)->orderBy('map_id')->orderBy('sort_order')->orderBy('id')->get(['id', 'map_id', 'token_type', 'player_character_id', 'npc_id', 'asset_id', 'label', 'position_x', 'position_y', 'scale', 'sort_order']));
        $videos = $this->arrays(VideoCue::query()->where('campaign_id', $campaignId)->orderBy('sort_order')->orderBy('id')->get(['id', 'campaign_id', 'primary_asset_id', 'fallback_asset_id', 'name', 'completion_mode', 'target_scene_id', 'music_during', 'music_after', 'embedded_audio_volume', 'embedded_audio_muted', 'sort_order']));
        $dicePresets = $this->arrays(DicePreset::query()->where('campaign_id', $campaignId)->orderBy('sort_order')->orderBy('id')->get(['id', 'campaign_id', 'name', 'expression', 'default_visibility', 'is_default', 'sort_order']));

        $this->validate($campaignId, $pcs, $npcs, $states, $audioCues, $presets, $presetEntries, $scenes, $backdrops, $maps, $fogMasks, $tokens, $videos);

        return ['schema_version' => 1, 'campaign' => ['id' => $campaignId, 'name' => $campaign->name, 'draft_revision' => $campaign->draft_revision], 'assets' => $assets, 'player_characters' => $pcs, 'npcs' => $npcs, 'npc_states' => $states, 'audio_cues' => $audioCues, 'stage_presets' => $presets, 'stage_preset_entries' => $presetEntries, 'scenes' => $scenes, 'scene_backdrops' => $backdrops, 'maps' => $maps, 'map_fog_masks' => $fogMasks, 'map_tokens' => $tokens, 'video_cues' => $videos, 'dice_presets' => $dicePresets];
    }

    /** @param list<array<string, mixed>> ...$records */
    private function validate(string $campaignId, array ...$records): void
    {
        $assetIds = [];
        foreach ($records as $set) {
            foreach ($set as $record) {
                foreach (['avatar_asset_id', 'normal_asset_id', 'asset_id', 'primary_backdrop_asset_id', 'image_asset_id', 'primary_asset_id', 'fallback_asset_id'] as $field) {
                    if (isset($record[$field])) {
                        $assetIds[] = $record[$field];
                    }
                }
            }
        }
        $assetIds = array_values(array_unique(array_filter($assetIds, 'is_string')));
        abort_unless(CampaignAsset::query()->where('campaign_id', $campaignId)->availableForAuthoring()->whereIn('id', $assetIds)->count() === count($assetIds), 422, 'Every referenced asset must be ready, unarchived, and belong to this campaign.');

        $npcs = $records[1];
        $states = $records[2];
        $audioCues = $records[3];
        $presets = $records[4];
        $presetEntries = $records[5];
        $scenes = $records[6];
        $maps = $records[8];
        $tokens = $records[10];
        $videos = $records[11];
        $this->assertReferences($states, 'npc_id', $this->ids($npcs), 'Every NPC state must belong to a campaign NPC.');
        $this->assertReferences($presetEntries, 'npc_id', $this->ids($npcs), 'Every stage entry must reference a campaign NPC.');
        $this->assertReferences($presetEntries, 'npc_state_id', $this->ids($states), 'Every stage entry state must belong to its NPC roster.');
        $this->assertReferences($scenes, 'default_music_cue_id', $this->ids($audioCues), 'Every scene music cue must belong to this campaign.');
        $this->assertReferences($scenes, 'base_stage_preset_id', $this->ids($presets), 'Every scene stage preset must belong to this campaign.');
        $this->assertReferences($tokens, 'player_character_id', $this->ids($records[0]), 'Every PC token must reference a campaign player character.');
        $this->assertReferences($tokens, 'npc_id', $this->ids($npcs), 'Every NPC token must reference a campaign NPC.');
        $this->assertReferences($videos, 'target_scene_id', $this->ids($scenes), 'Every video target scene must belong to this campaign.');
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<string>
     */
    private function ids(array $records): array
    {
        return array_values(array_filter(array_column($records, 'id'), 'is_string'));
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  list<string>  $allowed
     */
    private function assertReferences(array $records, string $field, array $allowed, string $message): void
    {
        $references = array_values(array_unique(array_filter(array_column($records, $field), 'is_string')));
        abort_unless(array_diff($references, $allowed) === [], 422, $message);
    }

    /**
     * @param  iterable<int, Model>  $records
     * @return list<array<string, mixed>>
     */
    private function arrays(iterable $records): array
    {
        $result = [];
        foreach ($records as $record) {
            $result[] = $record->attributesToArray();
        }

        return $result;
    }
}
