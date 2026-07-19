<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StalePresentationState;
use App\Models\CampaignRevision;
use App\Models\LiveSession;
use App\Models\OutboxEvent;
use App\Models\PresentationState;
use App\Models\ProcessedCommand;
use App\Models\SessionEvent;
use Illuminate\Support\Facades\DB;

class PresentationStateService
{
    /** @return array<string, mixed> */
    public static function initialState(): array
    {
        return ['scene_id' => null, 'backdrop_asset_id' => null, 'music_cue_id' => null, 'video_cue_id' => null, 'stage_entries' => []];
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
            $normalized = $this->validate($session, $state);
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
     * @return array<string, mixed>
     */
    private function validate(LiveSession $session, array $state): array
    {
        /** @var CampaignRevision $revision */
        $revision = CampaignRevision::query()->findOrFail($session->campaign_revision_id);
        $manifest = $revision->manifest;
        $this->assertReference($manifest, 'scenes', $state['scene_id'] ?? null, 'scene');
        $this->assertReference($manifest, 'assets', $state['backdrop_asset_id'] ?? null, 'backdrop asset');
        $this->assertReference($manifest, 'audio_cues', $state['music_cue_id'] ?? null, 'music cue');
        $this->assertReference($manifest, 'video_cues', $state['video_cue_id'] ?? null, 'video cue');
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

        return ['scene_id' => $state['scene_id'] ?? null, 'backdrop_asset_id' => $state['backdrop_asset_id'] ?? null, 'music_cue_id' => $state['music_cue_id'] ?? null, 'video_cue_id' => $state['video_cue_id'] ?? null, 'stage_entries' => $entries];
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
