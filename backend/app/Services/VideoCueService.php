<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StaleRevision;
use App\Models\Campaign;
use App\Models\CampaignAsset;
use App\Models\ProcessedCommand;
use App\Models\Scene;
use App\Models\VideoCue;
use Illuminate\Support\Facades\DB;

class VideoCueService
{
    /** @return array{0: array<string, mixed>, 1: bool} */
    public function create(string $campaignId, string $commandId, int $expectedRevision, string $name, string $primaryAssetId, ?string $fallbackAssetId, string $completionMode, ?string $targetSceneId, string $musicDuring, string $musicAfter, int $embeddedAudioVolume, bool $embeddedAudioMuted): array
    {
        return DB::transaction(function () use ($campaignId, $commandId, $expectedRevision, $name, $primaryAssetId, $fallbackAssetId, $completionMode, $targetSceneId, $musicDuring, $musicAfter, $embeddedAudioVolume, $embeddedAudioMuted): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var Campaign $campaign */
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->draft_revision !== $expectedRevision) {
                throw new StaleRevision($campaign);
            }
            $this->assertReadyVideo($campaignId, $primaryAssetId, 'A video cue requires a ready video from this campaign.');
            if ($fallbackAssetId !== null) {
                $this->assertReadyVideo($campaignId, $fallbackAssetId, 'A video fallback must be a ready video from this campaign.');
            }
            $targetsScene = $completionMode === 'enter_target_scene';
            abort_unless($targetsScene === ($targetSceneId !== null), 422, 'Target scene selection must match the completion mode.');
            if ($targetSceneId !== null) {
                abort_unless(Scene::query()->whereKey($targetSceneId)->where('campaign_id', $campaignId)->exists(), 422, 'A video target scene must belong to this campaign.');
            }
            $cue = VideoCue::query()->create(['campaign_id' => $campaignId, 'primary_asset_id' => $primaryAssetId, 'fallback_asset_id' => $fallbackAssetId, 'name' => trim($name), 'completion_mode' => $completionMode, 'target_scene_id' => $targetSceneId, 'music_during' => $musicDuring, 'music_after' => $musicAfter, 'embedded_audio_volume' => $embeddedAudioVolume, 'embedded_audio_muted' => $embeddedAudioMuted, 'sort_order' => (int) VideoCue::query()->where('campaign_id', $campaignId)->max('sort_order') + 1]);
            $campaign->increment('draft_revision');
            $response = ['data' => ['id' => $cue->id, 'name' => $cue->name, 'primary_asset_id' => $cue->primary_asset_id, 'fallback_asset_id' => $cue->fallback_asset_id, 'completion_mode' => $cue->completion_mode, 'target_scene_id' => $cue->target_scene_id, 'music_during' => $cue->music_during, 'music_after' => $cue->music_after, 'embedded_audio_volume' => $cue->embedded_audio_volume, 'embedded_audio_muted' => $cue->embedded_audio_muted]];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);

            return [$response, false];
        });
    }

    private function assertReadyVideo(string $campaignId, string $assetId, string $message): void
    {
        abort_unless(CampaignAsset::query()->whereKey($assetId)->where('campaign_id', $campaignId)->where('kind', 'video')->availableForAuthoring()->exists(), 422, $message);
    }
}
