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
            'music' => $cue['music'],
            'sfx' => $cue['sfx'],
            'video' => $cue['video'],
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
            if (is_string($resolved['music']['asset_id'] ?? null)) {
                $ids[] = $resolved['music']['asset_id'];
            }
            foreach ($resolved['sfx']['instances'] as $instance) {
                $ids[] = $instance['asset_id'];
            }
            foreach (['primary_asset_id', 'fallback_asset_id'] as $field) {
                if (is_string($resolved['video'][$field] ?? null)) {
                    $ids[] = $resolved['video'][$field];
                }
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
     * @return array{scene: array<string, mixed>|null, backdrop_asset_id: string|null, music: array{asset_id: string, loop: bool, volume: float, status: string, position_seconds: float, position_command_id: string|null, fade_duration_ms: int}|null, sfx: array{master_volume: float, instances: list<array{id: string, cue_id: string, asset_id: string, loop: bool, volume: float}>}, video: array{id: string, primary_asset_id: string, fallback_asset_id: string|null, completion_mode: string, target_scene_id: string|null, music_during: string, music_after: string, embedded_audio_volume: int, embedded_audio_muted: bool}|null, stage_tween: array{duration_ms: int, easing: string}, stage_entries: list<array<string, mixed>>}
     */
    private function cue(array $manifest, array $state): array
    {
        $scenes = $this->index($manifest, 'scenes');
        $presets = $this->index($manifest, 'stage_presets');
        $audioCues = $this->index($manifest, 'audio_cues');
        $videoCues = $this->index($manifest, 'video_cues');
        $npcs = $this->index($manifest, 'npcs');
        $states = $this->index($manifest, 'npc_states');
        $scene = is_string($state['scene_id'] ?? null) ? $scenes[$state['scene_id']] ?? null : null;
        $presetId = is_string($state['stage_preset_id'] ?? null) ? $state['stage_preset_id'] : (is_array($scene) ? $scene['base_stage_preset_id'] ?? null : null);
        $preset = is_string($presetId) ? $presets[$presetId] ?? null : null;
        $musicCue = is_string($state['music_cue_id'] ?? null) ? $audioCues[$state['music_cue_id']] ?? null : null;
        $musicPlayback = is_array($state['music_playback'] ?? null) ? $state['music_playback'] : [];
        $videoCue = is_string($state['video_cue_id'] ?? null) ? $videoCues[$state['video_cue_id']] ?? null : null;
        $sfxInstances = [];
        foreach ($state['sfx_instances'] ?? [] as $instance) {
            if (! is_array($instance) || ! is_string($instance['id'] ?? null) || ! is_string($instance['cue_id'] ?? null)) {
                continue;
            }
            $sfxCue = $audioCues[$instance['cue_id']] ?? null;
            if (! is_array($sfxCue) || ($sfxCue['kind'] ?? null) !== 'sfx' || ! is_string($sfxCue['asset_id'] ?? null)) {
                continue;
            }
            $sfxInstances[] = ['id' => $instance['id'], 'cue_id' => $instance['cue_id'], 'asset_id' => $sfxCue['asset_id'], 'loop' => (bool) ($instance['loop'] ?? $sfxCue['loop'] ?? false), 'volume' => (float) ($instance['volume'] ?? ((float) ($sfxCue['default_volume'] ?? 100) / 100))];
        }
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
                'native_facing' => 'right',
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
            'music' => ! is_array($musicCue) || ! is_string($musicCue['asset_id'] ?? null) ? null : ['asset_id' => $musicCue['asset_id'], 'loop' => (bool) ($musicPlayback['loop'] ?? $musicCue['loop'] ?? true), 'volume' => (float) ($musicPlayback['volume'] ?? ((float) ($musicCue['default_volume'] ?? 100) / 100)), 'status' => $musicPlayback['status'] ?? 'playing', 'position_seconds' => (float) ($musicPlayback['position_seconds'] ?? 0), 'position_command_id' => is_string($musicPlayback['position_command_id'] ?? null) ? $musicPlayback['position_command_id'] : null, 'fade_duration_ms' => (int) ($musicPlayback['fade_duration_ms'] ?? 0)],
            'sfx' => ['master_volume' => (float) ($state['sfx_master_volume'] ?? 1), 'instances' => $sfxInstances],
            'video' => ! is_array($videoCue) || ! is_string($videoCue['primary_asset_id'] ?? null) ? null : [
                'id' => $videoCue['id'],
                'primary_asset_id' => $videoCue['primary_asset_id'],
                'fallback_asset_id' => is_string($videoCue['fallback_asset_id'] ?? null) ? $videoCue['fallback_asset_id'] : null,
                'completion_mode' => $videoCue['completion_mode'] ?? 'restore_captured_scene',
                'target_scene_id' => is_string($videoCue['target_scene_id'] ?? null) ? $videoCue['target_scene_id'] : null,
                'music_during' => $videoCue['music_during'] ?? 'continue',
                'music_after' => $videoCue['music_after'] ?? 'keep_current',
                'embedded_audio_volume' => (int) ($videoCue['embedded_audio_volume'] ?? 100),
                'embedded_audio_muted' => (bool) ($videoCue['embedded_audio_muted'] ?? false),
            ],
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
