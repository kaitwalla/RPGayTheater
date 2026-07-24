<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StalePresentationState;
use App\Models\CampaignRevision;
use App\Models\LiveSession;
use App\Models\OutboxEvent;
use App\Models\PresentationDisplay;
use App\Models\PresentationState;
use App\Models\ProcessedCommand;
use App\Models\SessionEvent;
use Illuminate\Support\Facades\DB;

class PresentationStateService
{
    /** @return array<string, mixed> */
    public static function initialState(): array
    {
        return ['scene_id' => null, 'backdrop_asset_id' => null, 'music_cue_id' => null, 'music_playback' => self::stoppedMusic(), 'sfx_master_volume' => 1, 'sfx_instances' => [], 'video_cue_id' => null, 'video_restore_state' => null, 'stage_preset_id' => null, 'stage_entries' => [], 'standby' => null, 'standby_status' => 'idle', 'standby_error' => null];
    }

    public function snapshot(LiveSession $session): PresentationState
    {
        return PresentationState::query()->firstOrCreate(['live_session_id' => $session->id], ['revision' => 1, 'state' => self::initialState()]);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public function set(string $campaignId, string $sessionId, string $commandId, int $expectedRevision, array $state): array
    {
        return DB::transaction(function () use ($campaignId, $sessionId, $commandId, $expectedRevision, $state): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var LiveSession $session */
            $session = LiveSession::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($sessionId);
            $snapshot = PresentationState::query()->where('live_session_id', $session->id)->lockForUpdate()->first();
            if ($snapshot === null) {
                $snapshot = PresentationState::query()->create(['live_session_id' => $session->id, 'revision' => 1, 'state' => self::initialState()]);
            }
            if ($snapshot->revision !== $expectedRevision) {
                throw new StalePresentationState($snapshot);
            }
            $state['stage_entries'] ??= $snapshot->state['stage_entries'] ?? [];
            $normalized = $this->validate($session, $state);
            $normalized = $this->withVideoCapture($snapshot->state, $normalized);
            $snapshot->update(['state' => $normalized, 'revision' => $snapshot->revision + 1]);
            $snapshot->refresh();
            $response = ['data' => $snapshot->toApi()];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'presentation_state', 'aggregate_id' => $snapshot->id, 'response' => $response]);
            SessionEvent::query()->create(['campaign_id' => $campaignId, 'actor_type' => 'control', 'event_type' => 'presentation_state.updated', 'command_id' => $commandId, 'payload' => ['live_session_id' => $session->id, 'presentation_state_id' => $snapshot->id, 'revision' => $snapshot->revision], 'occurred_at' => now()]);
            OutboxEvent::query()->create(['aggregate_type' => 'presentation_state', 'aggregate_id' => $snapshot->id, 'topic' => 'presentation_states.'.$session->id, 'payload' => ['event_type' => 'presentation_state.updated', 'revision' => $snapshot->revision], 'occurred_at' => now()]);

            return [$response, false];
        });
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public function standby(string $campaignId, string $sessionId, string $commandId, int $expectedRevision, array $state): array
    {
        return DB::transaction(function () use ($campaignId, $sessionId, $commandId, $state): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var LiveSession $session */
            $session = LiveSession::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($sessionId);
            $snapshot = PresentationState::query()->where('live_session_id', $session->id)->lockForUpdate()->first() ?? PresentationState::query()->create(['live_session_id' => $session->id, 'revision' => 1, 'state' => self::initialState()]);
            $next = $snapshot->state;
            $state['stage_entries'] ??= [];
            $next['standby'] = $this->validate($session, $state);
            $next['standby_status'] = $this->hasPairedPresentation($session) ? 'preparing' : 'ready';
            $next['standby_error'] = null;
            $snapshot->update(['state' => $next, 'revision' => $snapshot->revision + 1]);

            return $this->record($campaignId, $session, $snapshot, $commandId, 'presentation_state.standby_requested');
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function go(string $campaignId, string $sessionId, string $commandId, int $expectedRevision): array
    {
        return DB::transaction(function () use ($campaignId, $sessionId, $commandId, $expectedRevision): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var LiveSession $session */
            $session = LiveSession::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($sessionId);
            $snapshot = PresentationState::query()->where('live_session_id', $session->id)->lockForUpdate()->firstOrFail();
            if ($snapshot->revision !== $expectedRevision) {
                throw new StalePresentationState($snapshot);
            }
            $next = $snapshot->state;
            abort_unless(($next['standby_status'] ?? null) === 'ready' && is_array($next['standby'] ?? null), 422, 'Presentation must report the standby cue ready before Go.');
            $active = $next['standby'];
            if (is_string($active['video_cue_id'] ?? null)) {
                $active['video_restore_state'] = $this->withoutVideo($active);
            }
            $next = $active + ['standby' => null, 'standby_status' => 'idle', 'standby_error' => null];
            $snapshot->update(['state' => $next, 'revision' => $snapshot->revision + 1]);

            return $this->record($campaignId, $session, $snapshot, $commandId, 'presentation_state.go');
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function report(LiveSession $session, string $commandId, int $expectedRevision, string $status, ?string $error): array
    {
        return DB::transaction(function () use ($session, $commandId, $expectedRevision, $status, $error): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            $snapshot = PresentationState::query()->where('live_session_id', $session->id)->lockForUpdate()->firstOrFail();
            if ($snapshot->revision !== $expectedRevision) {
                throw new StalePresentationState($snapshot);
            }
            $next = $snapshot->state;
            abort_unless(is_array($next['standby'] ?? null) && ($next['standby_status'] ?? null) === 'preparing', 422, 'There is no standby cue awaiting a Presentation report.');
            $next['standby_status'] = $status;
            $next['standby_error'] = $status === 'error' ? $error : null;
            $snapshot->update(['state' => $next, 'revision' => $snapshot->revision + 1]);

            return $this->record($session->campaign_id, $session, $snapshot, $commandId, 'presentation_state.standby_'.$status, 'presentation');
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function completeVideo(LiveSession $session, string $commandId, int $expectedRevision, string $videoCueId, bool $failed = false): array
    {
        return DB::transaction(function () use ($session, $commandId, $expectedRevision, $videoCueId, $failed): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            $snapshot = PresentationState::query()->where('live_session_id', $session->id)->lockForUpdate()->firstOrFail();
            if ($snapshot->revision !== $expectedRevision) {
                throw new StalePresentationState($snapshot);
            }
            $active = $snapshot->state;
            abort_unless(($active['video_cue_id'] ?? null) === $videoCueId, 422, 'This video cue is no longer active.');
            /** @var CampaignRevision $revision */
            $revision = CampaignRevision::query()->findOrFail($session->campaign_revision_id);
            $video = $this->index($revision->manifest, 'video_cues')[$videoCueId] ?? null;
            abort_unless(is_array($video), 422, 'The active video cue is not in the pinned revision.');

            $restore = is_array($active['video_restore_state'] ?? null) ? $active['video_restore_state'] : $this->withoutVideo($active);
            $next = $restore;
            if (! $failed && ($video['completion_mode'] ?? null) === 'enter_target_scene' && is_string($video['target_scene_id'] ?? null)) {
                $next = $this->sceneState($revision->manifest, $video['target_scene_id']);
            }
            $musicAfter = $video['music_after'] ?? 'keep_current';
            if ($musicAfter === 'remain_silent') {
                $next['music_cue_id'] = null;
                $next['music_playback'] = self::stoppedMusic();
            } elseif ($musicAfter !== 'start_target_default') {
                $next['music_cue_id'] = $restore['music_cue_id'] ?? null;
                $next['music_playback'] = $restore['music_playback'] ?? self::stoppedMusic();
            }
            $next['video_cue_id'] = null;
            $next['video_restore_state'] = null;
            $snapshot->update(['state' => $next, 'revision' => $snapshot->revision + 1]);

            return $this->record($session->campaign_id, $session, $snapshot, $commandId, $failed ? 'presentation_state.video_failed' : 'presentation_state.video_completed', 'presentation');
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function completeSfx(LiveSession $session, string $commandId, int $_expectedRevision, string $instanceId): array
    {
        return DB::transaction(function () use ($session, $commandId, $instanceId): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            $snapshot = PresentationState::query()->where('live_session_id', $session->id)->lockForUpdate()->firstOrFail();
            // A one-shot is terminal and keyed by an immutable instance ID. A later
            // Control command must not make an already-finished sound replay.
            $next = $snapshot->state;
            $instances = array_values(array_filter($next['sfx_instances'] ?? [], static fn (mixed $instance): bool => ! is_array($instance) || ($instance['id'] ?? null) !== $instanceId));
            abort_unless(count($instances) !== count($next['sfx_instances'] ?? []), 422, 'This sound effect is no longer active.');
            $next['sfx_instances'] = $instances;
            $snapshot->update(['state' => $next, 'revision' => $snapshot->revision + 1]);

            return $this->record($session->campaign_id, $session, $snapshot, $commandId, 'presentation_state.sfx_completed', 'presentation');
        });
    }

    /** @return array{0: array<string, mixed>, 1: false} */
    private function record(string $campaignId, LiveSession $session, PresentationState $snapshot, string $commandId, string $eventType, string $actorType = 'control'): array
    {
        $snapshot->refresh();
        $response = ['data' => $snapshot->toApi()];
        ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'presentation_state', 'aggregate_id' => $snapshot->id, 'response' => $response]);
        SessionEvent::query()->create(['campaign_id' => $campaignId, 'actor_type' => $actorType, 'event_type' => $eventType, 'command_id' => $commandId, 'payload' => ['live_session_id' => $session->id, 'presentation_state_id' => $snapshot->id, 'revision' => $snapshot->revision], 'occurred_at' => now()]);
        OutboxEvent::query()->create(['aggregate_type' => 'presentation_state', 'aggregate_id' => $snapshot->id, 'topic' => 'presentation_states.'.$session->id, 'payload' => ['event_type' => $eventType, 'revision' => $snapshot->revision], 'occurred_at' => now()]);

        return [$response, false];
    }

    private function hasPairedPresentation(LiveSession $session): bool
    {
        return PresentationDisplay::query()
            ->where('live_session_id', $session->id)
            ->whereNull('revoked_at')
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function validate(LiveSession $session, array $state): array
    {
        /** @var CampaignRevision $revision */
        $revision = CampaignRevision::query()->findOrFail($session->campaign_revision_id);
        $manifest = $revision->manifest;
        $this->assertReference($manifest, 'scenes', $state['scene_id'] ?? null, 'scene');
        $this->assertReference($manifest, 'assets', $state['backdrop_asset_id'] ?? null, 'backdrop asset');
        $musicId = $state['music_cue_id'] ?? null;
        $this->assertReference($manifest, 'audio_cues', $musicId, 'music cue');
        $this->assertReference($manifest, 'video_cues', $state['video_cue_id'] ?? null, 'video cue');
        $this->assertReference($manifest, 'stage_presets', $state['stage_preset_id'] ?? null, 'stage preset');
        $npcs = $this->index($manifest, 'npcs');
        $states = $this->index($manifest, 'npc_states');
        $entries = [];
        foreach ($state['stage_entries'] ?? [] as $entry) {
            if (! is_array($entry) || ! isset($entry['npc_id']) || ! is_string($entry['npc_id']) || ! isset($npcs[$entry['npc_id']])) {
                abort(422, 'Every stage entry must reference an NPC in the pinned revision.');
            }
            $stateId = $entry['npc_state_id'] ?? null;
            if ($stateId !== null && (! is_string($stateId) || ! isset($states[$stateId]) || ($states[$stateId]['npc_id'] ?? null) !== $entry['npc_id'])) {
                abort(422, 'Every stage entry state must belong to its NPC in the pinned revision.');
            }
            $entries[] = ['npc_id' => $entry['npc_id'], 'npc_state_id' => $stateId, 'position_x' => (float) $entry['position_x'], 'position_y' => (float) $entry['position_y'], 'scale' => (float) $entry['scale'], 'layer_order' => (int) $entry['layer_order'], 'facing' => $entry['facing']];
        }

        $musicCue = is_string($musicId) ? $this->index($manifest, 'audio_cues')[$musicId] ?? null : null;
        abort_unless($musicCue === null || ($musicCue['kind'] ?? null) === 'music', 422, 'The music cue must be an authored music cue.');
        $playback = is_array($state['music_playback'] ?? null) ? $state['music_playback'] : [];
        $musicPlayback = $musicCue === null ? self::stoppedMusic() : [
            'status' => $playback['status'] ?? 'playing',
            'position_seconds' => (float) ($playback['position_seconds'] ?? 0),
            'position_command_id' => $playback['position_command_id'] ?? null,
            'loop' => (bool) ($playback['loop'] ?? $musicCue['loop'] ?? true),
            'volume' => (float) ($playback['volume'] ?? ((float) ($musicCue['default_volume'] ?? 100) / 100)),
            'fade_duration_ms' => (int) ($playback['fade_duration_ms'] ?? 0),
        ];
        $sfxCues = $this->index($manifest, 'audio_cues');
        $sfxInstances = [];
        foreach ($state['sfx_instances'] ?? [] as $instance) {
            if (! is_array($instance) || ! is_string($instance['id'] ?? null) || ! is_string($instance['cue_id'] ?? null)) {
                abort(422, 'Every sound effect instance must reference a pinned sound effect cue.');
            }
            $cue = $sfxCues[$instance['cue_id']] ?? null;
            if (! is_array($cue) || ($cue['kind'] ?? null) !== 'sfx') {
                abort(422, 'Every sound effect instance must reference an authored sound effect cue.');
            }
            $sfxInstances[] = ['id' => $instance['id'], 'cue_id' => $instance['cue_id'], 'loop' => (bool) $instance['loop'], 'volume' => (float) $instance['volume']];
        }

        return ['scene_id' => $state['scene_id'] ?? null, 'backdrop_asset_id' => $state['backdrop_asset_id'] ?? null, 'music_cue_id' => $musicId, 'music_playback' => $musicPlayback, 'sfx_master_volume' => (float) ($state['sfx_master_volume'] ?? 1), 'sfx_instances' => $sfxInstances, 'video_cue_id' => $state['video_cue_id'] ?? null, 'stage_preset_id' => $state['stage_preset_id'] ?? null, 'stage_entries' => $entries];
    }

    /** @param array<string, mixed> $previous
     * @param  array<string, mixed>  $next
     * @return array<string, mixed>
     */
    private function withVideoCapture(array $previous, array $next): array
    {
        $previousVideo = $previous['video_cue_id'] ?? null;
        $nextVideo = $next['video_cue_id'] ?? null;
        if (! is_string($nextVideo)) {
            return is_array($previous['video_restore_state'] ?? null) ? $previous['video_restore_state'] : $next + ['video_restore_state' => null];
        }
        if (is_string($previousVideo) && is_array($previous['video_restore_state'] ?? null)) {
            $next['video_restore_state'] = $previous['video_restore_state'];

            return $next;
        }
        $next['video_restore_state'] = $this->withoutVideo($next);

        return $next;
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function withoutVideo(array $state): array
    {
        return [
            'scene_id' => $state['scene_id'] ?? null,
            'backdrop_asset_id' => $state['backdrop_asset_id'] ?? null,
            'music_cue_id' => $state['music_cue_id'] ?? null,
            'music_playback' => $state['music_playback'] ?? self::stoppedMusic(),
            'sfx_master_volume' => $state['sfx_master_volume'] ?? 1,
            'sfx_instances' => $state['sfx_instances'] ?? [],
            'video_cue_id' => null,
            'stage_preset_id' => $state['stage_preset_id'] ?? null,
            'stage_entries' => $state['stage_entries'] ?? [],
        ];
    }

    /** @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function sceneState(array $manifest, string $sceneId): array
    {
        $scene = $this->index($manifest, 'scenes')[$sceneId] ?? null;
        abort_unless(is_array($scene), 422, 'The video target scene is not in the pinned revision.');
        $presetId = is_string($scene['base_stage_preset_id'] ?? null) ? $scene['base_stage_preset_id'] : null;
        $entries = [];
        foreach ($manifest['stage_preset_entries'] ?? [] as $entry) {
            if (is_array($entry) && $entry['stage_preset_id'] === $presetId) {
                $entries[] = [
                    'npc_id' => $entry['npc_id'],
                    'npc_state_id' => $entry['npc_state_id'] ?? null,
                    'position_x' => (float) $entry['position_x'],
                    'position_y' => (float) $entry['position_y'],
                    'scale' => (float) $entry['scale'],
                    'layer_order' => (int) $entry['layer_order'],
                    'facing' => $entry['facing'],
                ];
            }
        }

        $musicCue = is_string($scene['default_music_cue_id'] ?? null) ? $this->index($manifest, 'audio_cues')[$scene['default_music_cue_id']] ?? null : null;

        return ['scene_id' => $scene['id'], 'backdrop_asset_id' => $scene['primary_backdrop_asset_id'] ?? null, 'music_cue_id' => $scene['default_music_cue_id'] ?? null, 'music_playback' => $musicCue === null ? self::stoppedMusic() : ['status' => 'playing', 'position_seconds' => 0, 'position_command_id' => null, 'loop' => (bool) ($musicCue['loop'] ?? true), 'volume' => (float) ($musicCue['default_volume'] ?? 100) / 100, 'fade_duration_ms' => 0], 'sfx_master_volume' => 1, 'sfx_instances' => [], 'video_cue_id' => null, 'video_restore_state' => null, 'stage_preset_id' => $presetId, 'stage_entries' => $entries];
    }

    /** @return array{status: string, position_seconds: float, position_command_id: null, loop: bool, volume: float, fade_duration_ms: int} */
    private static function stoppedMusic(): array
    {
        return ['status' => 'stopped', 'position_seconds' => 0, 'position_command_id' => null, 'loop' => true, 'volume' => 1, 'fade_duration_ms' => 0];
    }

    /** @param array<string, mixed> $manifest */
    private function assertReference(array $manifest, string $collection, mixed $id, string $label): void
    {
        if ($id !== null && (! is_string($id) || ! isset($this->index($manifest, $collection)[$id]))) {
            abort(422, "The {$label} must belong to the pinned revision.");
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, array<string, mixed>>
     */
    private function index(array $manifest, string $collection): array
    {
        $indexed = [];
        foreach ($manifest[$collection] ?? [] as $record) {
            if (is_array($record) && isset($record['id']) && is_string($record['id'])) {
                $indexed[$record['id']] = $record;
            }
        }

        return $indexed;
    }
}
