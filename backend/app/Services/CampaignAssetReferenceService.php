<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AudioCue;
use App\Models\CampaignAsset;
use App\Models\CampaignMap;
use App\Models\CampaignRevision;
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
        $campaignId = $asset->campaign_id;
        $assetId = $asset->getKey();
        $npcIds = NonPlayerCharacter::query()->where('campaign_id', $campaignId)->select('id');
        $sceneIds = Scene::query()->where('campaign_id', $campaignId)->select('id');
        $mapIds = CampaignMap::query()->where('campaign_id', $campaignId)->select('id');

        if (PlayerCharacter::query()->where('campaign_id', $campaignId)->where('avatar_asset_id', $assetId)->exists()
            || NonPlayerCharacter::query()->where('campaign_id', $campaignId)->where('normal_asset_id', $assetId)->exists()
            || NpcState::query()->whereIn('npc_id', $npcIds)->where('asset_id', $assetId)->exists()
            || AudioCue::query()->where('campaign_id', $campaignId)->where('asset_id', $assetId)->exists()
            || Scene::query()->where('campaign_id', $campaignId)->where('primary_backdrop_asset_id', $assetId)->exists()
            || SceneBackdrop::query()->whereIn('scene_id', $sceneIds)->where('asset_id', $assetId)->exists()
            || CampaignMap::query()->where('campaign_id', $campaignId)->where('image_asset_id', $assetId)->exists()
            || MapFogMask::query()->whereIn('map_id', $mapIds)->where('asset_id', $assetId)->exists()
            || MapToken::query()->whereIn('map_id', $mapIds)->where('asset_id', $assetId)->exists()
            || VideoCue::query()->where('campaign_id', $campaignId)->where(function ($query) use ($assetId): void {
                $query->where('primary_asset_id', $assetId)->orWhere('fallback_asset_id', $assetId);
            })->exists()) {
            return true;
        }

        foreach (CampaignRevision::query()->where('campaign_id', $campaignId)->cursor() as $revision) {
            if ($this->contains($revision->manifest, $assetId)) {
                return true;
            }
        }

        return false;
    }

    private function contains(mixed $value, string $assetId): bool
    {
        if (is_string($value)) {
            return $value === $assetId;
        }
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if ($this->contains($item, $assetId)) {
                return true;
            }
        }

        return false;
    }
}
