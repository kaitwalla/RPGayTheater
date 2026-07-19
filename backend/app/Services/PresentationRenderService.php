<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CampaignRevision;
use App\Models\LiveSession;

class PresentationRenderService
{
    public function __construct(private readonly PresentationStateService $states) {}

    /** @return array<string, mixed> */
    public function render(LiveSession $session): array
    {
        $snapshot = $this->states->snapshot($session);
        /** @var CampaignRevision $revision */
        $revision = CampaignRevision::query()->findOrFail($session->campaign_revision_id);
        $cue = $this->cue($revision->manifest, $snapshot->state);
        $standby = is_array($snapshot->state['standby'] ?? null) ? $this->cue($revision->manifest, $snapshot->state['standby']) : null;

        return [
            'live_session_id' => $session->id,
            'revision' => $snapshot->revision,
            'scene' => $cue['scene'],
            'backdrop_asset_id' => $cue['backdrop_asset_id'],
            'stage_tween' => $cue['stage_tween'],
            'stage_entries' => $cue['stage_entries'],
            'standby' => $standby,
        ];
    }

    /** @return list<string> */
    public function allowedAssetIds(LiveSession $session): array
    {
        /** @var CampaignRevision $revision */
        $revision = CampaignRevision::query()->findOrFail($session->campaign_revision_id);
        $state = $this->states->snapshot($session)->state;
        $ids = [];
        foreach ([$state, $state['standby'] ?? null] as $cue) {
            if (! is_array($cue)) {
                continue;
            }
            $resolved = $this->cue($revision->manifest, $cue);
            if (is_string($resolved['backdrop_asset_id'])) {
                $ids[] = $resolved['backdrop_asset_id'];
            }
            foreach ($resolved['stage_entries'] as $entry) {
                if (is_string($entry['asset_id'] ?? null)) {
                    $ids[] = $entry['asset_id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $state
     * @return array{scene: array<string, mixed>|null, backdrop_asset_id: string|null, stage_tween: array{duration_ms: int, easing: string}, stage_entries: list<array<string, mixed>>}
     */
    private function cue(array $manifest, array $state): array
    {
        $scenes = $this->index($manifest, 'scenes');
        $presets = $this->index($manifest, 'stage_presets');
        $npcs = $this->index($manifest, 'npcs');
        $states = $this->index($manifest, 'npc_states');
        $scene = is_string($state['scene_id'] ?? null) ? $scenes[$state['scene_id']] ?? null : null;
        $presetId = is_string($state['stage_preset_id'] ?? null) ? $state['stage_preset_id'] : (is_array($scene) ? $scene['base_stage_preset_id'] ?? null : null);
        $preset = is_string($presetId) ? $presets[$presetId] ?? null : null;
        $entries = [];
        foreach ($state['stage_entries'] ?? [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['npc_id'] ?? null) || ! isset($npcs[$entry['npc_id']])) {
                continue;
            }
            $npc = $npcs[$entry['npc_id']];
            $npcState = is_string($entry['npc_state_id'] ?? null) ? $states[$entry['npc_state_id']] ?? null : null;
            $entries[] = [
                'npc_id' => $entry['npc_id'],
                'npc_state_id' => $entry['npc_state_id'] ?? null,
                'name' => $npc['name'] ?? null,
                'asset_id' => $npcState['asset_id'] ?? $npc['normal_asset_id'] ?? null,
                'position_x' => (float) ($entry['position_x'] ?? 0),
                'position_y' => (float) ($entry['position_y'] ?? 0),
                'scale' => (float) ($entry['scale'] ?? 1),
                'layer_order' => (int) ($entry['layer_order'] ?? 0),
                'facing' => $entry['facing'] ?? null,
                'native_facing' => $npc['native_facing'] ?? 'right',
            ];
        }
        usort($entries, static fn (array $left, array $right): int => $left['layer_order'] <=> $right['layer_order']);

        return [
            'scene' => $scene === null ? null : [
                'id' => $scene['id'],
                'name' => $scene['name'] ?? null,
                'transition' => $scene['transition'] ?? 'cut',
                'transition_duration_ms' => $scene['transition_duration_ms'] ?? 0,
            ],
            'backdrop_asset_id' => is_string($state['backdrop_asset_id'] ?? null) ? $state['backdrop_asset_id'] : null,
            'stage_tween' => ['duration_ms' => (int) ($preset['tween_duration_ms'] ?? 0), 'easing' => (string) ($preset['tween_easing'] ?? 'linear')],
            'stage_entries' => $entries,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, array<string, mixed>>
     */
    private function index(array $manifest, string $collection): array
    {
        $indexed = [];
        foreach ($manifest[$collection] ?? [] as $record) {
            if (is_array($record) && is_string($record['id'] ?? null)) {
                $indexed[$record['id']] = $record;
            }
        }

        return $indexed;
    }
}
