<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StaleRevision;
use App\Models\Campaign;
use App\Models\CampaignAsset;
use App\Models\PlayerCharacter;
use App\Models\ProcessedCommand;
use Illuminate\Support\Facades\DB;

class PlayerCharacterService
{
    /** @return array{0: array<string, mixed>, 1: bool} */
    public function create(string $campaignId, string $commandId, int $expectedRevision, string $name, ?string $pronouns, ?string $description, ?string $avatarId): array
    {
        return DB::transaction(function () use ($campaignId, $commandId, $expectedRevision, $name, $pronouns, $description, $avatarId): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var Campaign $campaign */
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->draft_revision !== $expectedRevision) {
                throw new StaleRevision($campaign);
            }
            if ($avatarId !== null) {
                abort_unless(CampaignAsset::query()->whereKey($avatarId)->where('campaign_id', $campaignId)->where('kind', 'image')->availableForAuthoring()->exists(), 422, 'A PC avatar must be a ready, unarchived image from this campaign.');
            }
            $character = PlayerCharacter::query()->create(['campaign_id' => $campaignId, 'avatar_asset_id' => $avatarId, 'name' => trim($name), 'pronouns' => $pronouns, 'public_description' => $description, 'sort_order' => (int) PlayerCharacter::query()->where('campaign_id', $campaignId)->max('sort_order') + 1]);
            $campaign->increment('draft_revision');
            $response = ['data' => $character->toApi()];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);

            return [$response, false];
        });
    }
}
