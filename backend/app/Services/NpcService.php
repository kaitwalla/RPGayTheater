<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StaleRevision;
use App\Models\Campaign;
use App\Models\CampaignAsset;
use App\Models\NonPlayerCharacter;
use App\Models\NpcState;
use App\Models\ProcessedCommand;
use Illuminate\Support\Facades\DB;

class NpcService
{
    /** @return array{0: array<string, mixed>, 1: bool} */
    public function create(string $campaignId, string $commandId, int $expectedRevision, string $name, string $normalAssetId, ?string $pronouns, ?string $description, string $facing): array
    {
        return DB::transaction(function () use ($campaignId, $commandId, $expectedRevision, $name, $normalAssetId, $pronouns, $description, $facing): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var Campaign $campaign */
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->draft_revision !== $expectedRevision) {
                throw new StaleRevision($campaign);
            }
            abort_unless(CampaignAsset::query()->whereKey($normalAssetId)->where('campaign_id', $campaignId)->where('kind', 'image')->where('upload_status', CampaignAsset::STATUS_READY)->exists(), 422, 'An NPC normal image must be a ready image from this campaign.');
            $npc = NonPlayerCharacter::query()->create(['campaign_id' => $campaignId, 'normal_asset_id' => $normalAssetId, 'name' => trim($name), 'pronouns' => $pronouns, 'public_description' => $description, 'native_facing' => $facing]);
            $campaign->increment('draft_revision');
            $response = ['data' => ['id' => $npc->id, 'campaign_id' => $npc->campaign_id, 'normal_asset_id' => $npc->normal_asset_id, 'name' => $npc->name, 'native_facing' => $npc->native_facing]];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);

            return [$response, false];
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function createState(string $campaignId, string $npcId, string $commandId, int $expectedRevision, string $name, string $assetId): array
    {
        return DB::transaction(function () use ($campaignId, $npcId, $commandId, $expectedRevision, $name, $assetId): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var Campaign $campaign */
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->draft_revision !== $expectedRevision) {
                throw new StaleRevision($campaign);
            }
            abort_unless(NonPlayerCharacter::query()->whereKey($npcId)->where('campaign_id', $campaignId)->exists(), 404);
            abort_unless(CampaignAsset::query()->whereKey($assetId)->where('campaign_id', $campaignId)->where('kind', 'image')->where('upload_status', CampaignAsset::STATUS_READY)->exists(), 422, 'An NPC state image must be a ready image from this campaign.');
            $state = NpcState::query()->create(['npc_id' => $npcId, 'asset_id' => $assetId, 'name' => trim($name), 'sort_order' => (int) NpcState::query()->where('npc_id', $npcId)->max('sort_order') + 1]);
            $campaign->increment('draft_revision');
            $response = ['data' => ['id' => $state->id, 'npc_id' => $state->npc_id, 'asset_id' => $state->asset_id, 'name' => $state->name, 'sort_order' => $state->sort_order]];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);

            return [$response, false];
        });
    }
}
