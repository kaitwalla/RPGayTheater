<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AudioCue;
use App\Models\CampaignAsset;
use App\Models\CampaignMap;
use App\Models\MapFogMask;
use App\Models\MapToken;
use App\Models\NonPlayerCharacter;
use App\Models\NpcState;
use App\Models\PlayerCharacter;
use App\Models\Scene;
use App\Models\SceneBackdrop;
use App\Models\VideoCue;

class CampaignAssetReferenceService
{
    public function isReferenced(CampaignAsset $asset): bool
    {
        return $this->descriptions($asset) !== [];
    }

    /** @return list<string> */
    public function descriptions(CampaignAsset $asset): array
    {
        $campaignId = $asset->campaign_id;
        $assetId = $asset->getKey();
        $npcs = NonPlayerCharacter::query()->where('campaign_id', $campaignId)->get(['id', 'name'])->keyBy('id');
        $scenes = Scene::query()->where('campaign_id', $campaignId)->get(['id', 'name'])->keyBy('id');
        $maps = CampaignMap::query()->where('campaign_id', $campaignId)->get(['id', 'name'])->keyBy('id');
        $descriptions = [];

        foreach (PlayerCharacter::query()->where('campaign_id', $campaignId)->where('avatar_asset_id', $assetId)->get(['name']) as $character) {
            $descriptions[] = 'Player character "'.$character->name.'" (avatar)';
        }
        foreach (NonPlayerCharacter::query()->where('campaign_id', $campaignId)->where('normal_asset_id', $assetId)->get(['name']) as $npc) {
            $descriptions[] = 'NPC "'.$npc->name.'" (base art)';
        }
        foreach (NpcState::query()->whereIn('npc_id', $npcs->keys())->where('asset_id', $assetId)->get(['npc_id', 'name']) as $state) {
            $npc = $npcs->get($state->npc_id);
            $npcName = $npc === null ? 'Unknown' : $npc->name;
            $descriptions[] = 'NPC "'.$npcName.'", emotion "'.$state->name.'"';
        }
        foreach (AudioCue::query()->where('campaign_id', $campaignId)->where('asset_id', $assetId)->get(['name', 'kind']) as $cue) {
            $descriptions[] = ucfirst($cue->kind).' cue "'.$cue->name.'"';
        }
        foreach (Scene::query()->where('campaign_id', $campaignId)->where('primary_backdrop_asset_id', $assetId)->get(['name']) as $scene) {
            $descriptions[] = 'Scene "'.$scene->name.'" (primary backdrop)';
        }
        foreach (SceneBackdrop::query()->whereIn('scene_id', $scenes->keys())->where('asset_id', $assetId)->get(['scene_id', 'name']) as $backdrop) {
            $scene = $scenes->get($backdrop->scene_id);
            $sceneName = $scene === null ? 'Unknown' : $scene->name;
            $descriptions[] = 'Scene "'.$sceneName.'", alternate backdrop "'.$backdrop->name.'"';
        }
        foreach (CampaignMap::query()->where('campaign_id', $campaignId)->where('image_asset_id', $assetId)->get(['name']) as $map) {
            $descriptions[] = 'Map "'.$map->name.'"';
        }
        foreach (MapFogMask::query()->whereIn('map_id', $maps->keys())->where('asset_id', $assetId)->get(['map_id']) as $mask) {
            $map = $maps->get($mask->map_id);
            $mapName = $map === null ? 'Unknown' : $map->name;
            $descriptions[] = 'Map "'.$mapName.'" (fog mask)';
        }
        foreach (MapToken::query()->whereIn('map_id', $maps->keys())->where('asset_id', $assetId)->get(['map_id', 'label']) as $token) {
            $map = $maps->get($token->map_id);
            $label = is_string($token->label) && $token->label !== '' ? ' "'.$token->label.'"' : '';
            $mapName = $map === null ? 'Unknown' : $map->name;
            $descriptions[] = 'Map "'.$mapName.'", token'.$label;
        }
        foreach (VideoCue::query()->where('campaign_id', $campaignId)->where('primary_asset_id', $assetId)->get(['name']) as $cue) {
            $descriptions[] = 'Video cue "'.$cue->name.'" (primary media)';
        }
        foreach (VideoCue::query()->where('campaign_id', $campaignId)->where('fallback_asset_id', $assetId)->get(['name']) as $cue) {
            $descriptions[] = 'Video cue "'.$cue->name.'" (fallback media)';
        }

        return $descriptions;
    }
}
