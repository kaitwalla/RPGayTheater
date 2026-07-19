<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StaleRevision;
use App\Models\AudioCue;
use App\Models\Campaign;
use App\Models\CampaignAsset;
use App\Models\ProcessedCommand;
use App\Models\Scene;
use App\Models\SceneBackdrop;
use App\Models\StagePreset;
use Illuminate\Support\Facades\DB;

class SceneService
{
    /** @return array{0: array<string, mixed>, 1: bool} */
    public function create(string $campaignId, string $commandId, int $expectedRevision, string $name, ?string $backdropId, ?string $musicCueId, ?string $baseStagePresetId, string $transition, int $duration): array
    {
        return DB::transaction(function () use ($campaignId, $commandId, $expectedRevision, $name, $backdropId, $musicCueId, $baseStagePresetId, $transition, $duration): array {
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
            if ($baseStagePresetId !== null) {
                abort_unless(StagePreset::query()->whereKey($baseStagePresetId)->where('campaign_id', $campaignId)->exists(), 422, 'Scene base staging must be a preset from this campaign.');
            }
            $scene = Scene::query()->create(['campaign_id' => $campaignId, 'name' => trim($name), 'primary_backdrop_asset_id' => $backdropId, 'default_music_cue_id' => $musicCueId, 'base_stage_preset_id' => $baseStagePresetId, 'transition' => $transition, 'transition_duration_ms' => $duration, 'sort_order' => (int) Scene::query()->where('campaign_id', $campaignId)->max('sort_order') + 1]);
            $campaign->increment('draft_revision');
            $response = ['data' => ['id' => $scene->id, 'name' => $scene->name, 'primary_backdrop_asset_id' => $scene->primary_backdrop_asset_id, 'default_music_cue_id' => $scene->default_music_cue_id, 'base_stage_preset_id' => $scene->base_stage_preset_id, 'transition' => $scene->transition, 'transition_duration_ms' => $scene->transition_duration_ms]];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);

            return [$response, false];
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function createBackdrop(string $campaignId, string $sceneId, string $commandId, int $expectedRevision, string $name, string $assetId): array
    {
        return DB::transaction(function () use ($campaignId, $sceneId, $commandId, $expectedRevision, $name, $assetId): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var Campaign $campaign */
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->draft_revision !== $expectedRevision) {
                throw new StaleRevision($campaign);
            }
            abort_unless(Scene::query()->whereKey($sceneId)->where('campaign_id', $campaignId)->exists(), 404);
            abort_unless(CampaignAsset::query()->whereKey($assetId)->where('campaign_id', $campaignId)->where('kind', 'image')->where('upload_status', CampaignAsset::STATUS_READY)->exists(), 422, 'A scene backdrop must be a ready image from this campaign.');
            $backdrop = SceneBackdrop::query()->create(['scene_id' => $sceneId, 'asset_id' => $assetId, 'name' => trim($name), 'sort_order' => (int) SceneBackdrop::query()->where('scene_id', $sceneId)->max('sort_order') + 1]);
            $campaign->increment('draft_revision');
            $response = ['data' => ['id' => $backdrop->id, 'scene_id' => $backdrop->scene_id, 'asset_id' => $backdrop->asset_id, 'name' => $backdrop->name, 'sort_order' => $backdrop->sort_order]];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);

            return [$response, false];
        });
    }
}
