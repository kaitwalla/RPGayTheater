<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StaleRevision;
use App\Models\Campaign;
use App\Models\NonPlayerCharacter;
use App\Models\NpcState;
use App\Models\ProcessedCommand;
use App\Models\StagePreset;
use App\Models\StagePresetEntry;
use Illuminate\Support\Facades\DB;

class StagePresetService
{
    /** @return array{0: array<string, mixed>, 1: bool} */
    public function create(string $campaignId, string $commandId, int $expectedRevision, string $name, int $tweenDuration, string $tweenEasing): array
    {
        return DB::transaction(function () use ($campaignId, $commandId, $expectedRevision, $name, $tweenDuration, $tweenEasing): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var Campaign $campaign */
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->draft_revision !== $expectedRevision) {
                throw new StaleRevision($campaign);
            }
            $preset = StagePreset::query()->create(['campaign_id' => $campaignId, 'name' => trim($name), 'tween_duration_ms' => $tweenDuration, 'tween_easing' => $tweenEasing]);
            $campaign->increment('draft_revision');
            $response = ['data' => ['id' => $preset->id, 'campaign_id' => $preset->campaign_id, 'name' => $preset->name, 'tween_duration_ms' => $preset->tween_duration_ms, 'tween_easing' => $preset->tween_easing]];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);

            return [$response, false];
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function createEntry(string $campaignId, string $presetId, string $commandId, int $expectedRevision, string $npcId, ?string $npcStateId, float $positionX, float $positionY, float $scale, int $layerOrder, string $facing): array
    {
        return DB::transaction(function () use ($campaignId, $presetId, $commandId, $expectedRevision, $npcId, $npcStateId, $positionX, $positionY, $scale, $layerOrder, $facing): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var Campaign $campaign */
            $campaign = Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            if ($campaign->draft_revision !== $expectedRevision) {
                throw new StaleRevision($campaign);
            }
            abort_unless(StagePreset::query()->whereKey($presetId)->where('campaign_id', $campaignId)->exists(), 404);
            abort_unless(NonPlayerCharacter::query()->whereKey($npcId)->where('campaign_id', $campaignId)->exists(), 422, 'A stage entry requires an NPC from this campaign.');
            if ($npcStateId !== null) {
                abort_unless(NpcState::query()->whereKey($npcStateId)->where('npc_id', $npcId)->exists(), 422, 'A stage entry state must belong to its NPC.');
            }
            $entry = StagePresetEntry::query()->create(['stage_preset_id' => $presetId, 'npc_id' => $npcId, 'npc_state_id' => $npcStateId, 'position_x' => $positionX, 'position_y' => $positionY, 'scale' => $scale, 'layer_order' => $layerOrder, 'facing' => $facing]);
            $campaign->increment('draft_revision');
            $response = ['data' => ['id' => $entry->id, 'stage_preset_id' => $entry->stage_preset_id, 'npc_id' => $entry->npc_id, 'npc_state_id' => $entry->npc_state_id, 'position_x' => $entry->position_x, 'position_y' => $entry->position_y, 'scale' => $entry->scale, 'layer_order' => $entry->layer_order, 'facing' => $entry->facing]];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);

            return [$response, false];
        });
    }
}
