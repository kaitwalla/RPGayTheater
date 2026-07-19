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

class SceneService
{
    /** @return array{0: array<string, mixed>, 1: bool} */
    public function create(string $campaignId, string $commandId, int $expectedRevision, string $name, ?string $backdropId, ?string $musicCueId, string $transition, int $duration): array
    {
        return DB::transaction(function () use ($campaignId, $commandId, $expectedRevision, $name, $backdropId, $musicCueId, $transition, $duration): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var Campaign $campaign */
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->draft_revision !== $expectedRevision) {
                throw new StaleRevision($campaign);
            }
            if ($backdropId !== null) {
                abort_unless(CampaignAsset::query()->whereKey($backdropId)->where('campaign_id', $campaignId)->where('kind', 'image')->where('upload_status', CampaignAsset::STATUS_READY)->exists(), 422, 'A scene backdrop must be a ready image from this campaign.');
            }
            if ($musicCueId !== null) {
                abort_unless(AudioCue::query()->whereKey($musicCueId)->where('campaign_id', $campaignId)->where('kind', 'music')->exists(), 422, 'Scene default music must be a music cue from this campaign.');
            }
            $scene = Scene::query()->create(['campaign_id' => $campaignId, 'name' => trim($name), 'primary_backdrop_asset_id' => $backdropId, 'default_music_cue_id' => $musicCueId, 'transition' => $transition, 'transition_duration_ms' => $duration, 'sort_order' => (int) Scene::query()->where('campaign_id', $campaignId)->max('sort_order') + 1]);
            $campaign->increment('draft_revision');
            $response = ['data' => ['id' => $scene->id, 'name' => $scene->name, 'primary_backdrop_asset_id' => $scene->primary_backdrop_asset_id, 'default_music_cue_id' => $scene->default_music_cue_id, 'transition' => $scene->transition, 'transition_duration_ms' => $scene->transition_duration_ms]];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);

            return [$response, false];
        });
    }
}
