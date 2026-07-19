<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StaleRevision;
use App\Models\Campaign;
use App\Models\CampaignAsset;
use App\Models\CampaignMap;
use App\Models\MapFogMask;
use App\Models\MapToken;
use App\Models\NonPlayerCharacter;
use App\Models\PlayerCharacter;
use App\Models\ProcessedCommand;
use Illuminate\Support\Facades\DB;

class CampaignMapService
{
    /** @return array{0: array<string, mixed>, 1: bool} */
    public function create(string $campaignId, string $commandId, int $expectedRevision, string $name, string $imageAssetId): array
    {
        return DB::transaction(function () use ($campaignId, $commandId, $expectedRevision, $name, $imageAssetId): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var Campaign $campaign */
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->draft_revision !== $expectedRevision) {
                throw new StaleRevision($campaign);
            }
            $this->assertReadyImage($campaignId, $imageAssetId, 'A map requires a ready image from this campaign.');
            $map = CampaignMap::query()->create(['campaign_id' => $campaignId, 'image_asset_id' => $imageAssetId, 'name' => trim($name), 'sort_order' => (int) CampaignMap::query()->where('campaign_id', $campaignId)->max('sort_order') + 1]);
            $campaign->increment('draft_revision');
            $response = ['data' => ['id' => $map->id, 'campaign_id' => $map->campaign_id, 'image_asset_id' => $map->image_asset_id, 'name' => $map->name, 'sort_order' => $map->sort_order]];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);

            return [$response, false];
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function setFogMask(string $campaignId, string $mapId, string $commandId, int $expectedRevision, string $assetId): array
    {
        return DB::transaction(function () use ($campaignId, $mapId, $commandId, $expectedRevision, $assetId): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var Campaign $campaign */
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->draft_revision !== $expectedRevision) {
                throw new StaleRevision($campaign);
            }
            abort_unless(CampaignMap::query()->whereKey($mapId)->where('campaign_id', $campaignId)->exists(), 404);
            $this->assertReadyImage($campaignId, $assetId, 'An initial fog mask must be a ready image from this campaign.');
            $fogMask = MapFogMask::query()->updateOrCreate(['map_id' => $mapId], ['asset_id' => $assetId]);
            $campaign->increment('draft_revision');
            $response = ['data' => ['id' => $fogMask->id, 'map_id' => $fogMask->map_id, 'asset_id' => $fogMask->asset_id]];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);

            return [$response, false];
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function createToken(string $campaignId, string $mapId, string $commandId, int $expectedRevision, string $type, ?string $playerCharacterId, ?string $npcId, ?string $assetId, ?string $label, float $positionX, float $positionY, float $scale): array
    {
        return DB::transaction(function () use ($campaignId, $mapId, $commandId, $expectedRevision, $type, $playerCharacterId, $npcId, $assetId, $label, $positionX, $positionY, $scale): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var Campaign $campaign */
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->draft_revision !== $expectedRevision) {
                throw new StaleRevision($campaign);
            }
            abort_unless(CampaignMap::query()->whereKey($mapId)->where('campaign_id', $campaignId)->exists(), 404);
            if ($type === 'pc') {
                abort_unless($playerCharacterId !== null && PlayerCharacter::query()->whereKey($playerCharacterId)->where('campaign_id', $campaignId)->exists(), 422, 'A PC token requires a player character from this campaign.');
                abort_if($npcId !== null || $assetId !== null, 422, 'A PC token cannot override its source character.');
            } elseif ($type === 'npc') {
                abort_unless($npcId !== null && NonPlayerCharacter::query()->whereKey($npcId)->where('campaign_id', $campaignId)->exists(), 422, 'An NPC token requires an NPC from this campaign.');
                abort_if($playerCharacterId !== null || $assetId !== null, 422, 'An NPC token cannot override its source character.');
            } else {
                abort_unless($assetId !== null && trim((string) $label) !== '', 422, 'A custom token requires a label and ready image.');
                $this->assertReadyImage($campaignId, $assetId, 'A custom token requires a ready image from this campaign.');
                abort_if($playerCharacterId !== null || $npcId !== null, 422, 'A custom token cannot reference a character.');
            }
            $token = MapToken::query()->create(['map_id' => $mapId, 'token_type' => $type, 'player_character_id' => $playerCharacterId, 'npc_id' => $npcId, 'asset_id' => $assetId, 'label' => $label === null ? null : trim($label), 'position_x' => $positionX, 'position_y' => $positionY, 'scale' => $scale, 'sort_order' => (int) MapToken::query()->where('map_id', $mapId)->max('sort_order') + 1]);
            $campaign->increment('draft_revision');
            $response = ['data' => ['id' => $token->id, 'map_id' => $token->map_id, 'token_type' => $token->token_type, 'player_character_id' => $token->player_character_id, 'npc_id' => $token->npc_id, 'asset_id' => $token->asset_id, 'label' => $token->label, 'position_x' => $token->position_x, 'position_y' => $token->position_y, 'scale' => $token->scale, 'sort_order' => $token->sort_order]];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);

            return [$response, false];
        });
    }

    private function assertReadyImage(string $campaignId, string $assetId, string $message): void
    {
        abort_unless(CampaignAsset::query()->whereKey($assetId)->where('campaign_id', $campaignId)->where('kind', 'image')->where('upload_status', CampaignAsset::STATUS_READY)->exists(), 422, $message);
    }
}
