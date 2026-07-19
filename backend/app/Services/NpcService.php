<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StaleRevision;
use App\Models\Campaign;
use App\Models\CampaignAsset;
use App\Models\NonPlayerCharacter;
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
}
