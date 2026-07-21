<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StaleRevision;
use App\Models\AudioCue;
use App\Models\Campaign;
use App\Models\CampaignAsset;
use App\Models\ProcessedCommand;
use App\Models\Scene;
use Illuminate\Support\Facades\DB;

class AudioCueService
{
    /** @return array{0: array<string, mixed>, 1: bool} */
    public function create(string $campaignId, string $commandId, int $expectedRevision, string $name, string $assetId, ?string $sceneId, string $kind, bool $loop, int $volume): array
    {
        return DB::transaction(function () use ($campaignId, $commandId, $expectedRevision, $name, $assetId, $sceneId, $kind, $loop, $volume): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var Campaign $campaign */
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->draft_revision !== $expectedRevision) {
                throw new StaleRevision($campaign);
            }
            abort_unless(CampaignAsset::query()->whereKey($assetId)->where('campaign_id', $campaignId)->where('kind', 'audio')->availableForAuthoring()->exists(), 422, 'An audio cue requires a ready, unarchived audio asset from this campaign.');
            if ($sceneId !== null) {
                abort_unless(Scene::query()->whereKey($sceneId)->where('campaign_id', $campaignId)->exists(), 422, 'A scene cue must belong to this campaign.');
            }
            $cue = AudioCue::query()->create(['campaign_id' => $campaignId, 'scene_id' => $sceneId, 'asset_id' => $assetId, 'name' => trim($name), 'kind' => $kind, 'loop' => $loop, 'default_volume' => $volume, 'sort_order' => (int) AudioCue::query()->where('campaign_id', $campaignId)->max('sort_order') + 1]);
            $campaign->increment('draft_revision');
            $response = ['data' => ['id' => $cue->id, 'name' => $cue->name, 'scene_id' => $cue->scene_id, 'asset_id' => $cue->asset_id, 'kind' => $cue->kind, 'loop' => $cue->loop, 'default_volume' => $cue->default_volume]];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);

            return [$response, false];
        });
    }
}
