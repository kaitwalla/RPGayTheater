<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StalePlayerMapState;
use App\Models\CampaignRevision;
use App\Models\LiveSession;
use App\Models\OutboxEvent;
use App\Models\PlayerMapState;
use App\Models\ProcessedCommand;
use App\Models\SessionEvent;
use App\Models\SessionParticipant;
use Illuminate\Support\Facades\DB;

class PlayerMapStateService
{
    public function __construct(private readonly MapProgressService $progresses) {}

    public function snapshot(LiveSession $session): PlayerMapState
    {
        return PlayerMapState::query()->firstOrCreate(['live_session_id' => $session->id], ['revision' => 1, 'map_id' => null]);
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function set(string $campaignId, string $sessionId, string $commandId, int $expectedRevision, ?string $mapId): array
    {
        return DB::transaction(function () use ($campaignId, $sessionId, $commandId, $expectedRevision, $mapId): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var LiveSession $session */
            $session = LiveSession::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($sessionId);
            $state = PlayerMapState::query()->where('live_session_id', $session->id)->lockForUpdate()->first()
                ?? PlayerMapState::query()->create(['live_session_id' => $session->id, 'revision' => 1, 'map_id' => null]);
            if ($state->revision !== $expectedRevision) {
                throw new StalePlayerMapState($state);
            }
            $this->assertMapInPinnedRevision($session, $mapId);
            $state->update(['map_id' => $mapId, 'revision' => $state->revision + 1]);
            $state->refresh();
            $response = ['data' => $state->toApi()];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'player_map_state', 'aggregate_id' => $state->id, 'response' => $response]);
            $eventType = $mapId === null ? 'player_map.hidden' : 'player_map.selected';
            SessionEvent::query()->create(['campaign_id' => $campaignId, 'actor_type' => 'control', 'event_type' => $eventType, 'command_id' => $commandId, 'payload' => ['live_session_id' => $session->id, 'map_id' => $mapId, 'revision' => $state->revision], 'occurred_at' => now()]);
            OutboxEvent::query()->create(['aggregate_type' => 'player_map_state', 'aggregate_id' => $state->id, 'topic' => 'player_map_states.'.$session->id, 'payload' => ['event_type' => $eventType, 'revision' => $state->revision, 'map_id' => $mapId], 'occurred_at' => now()]);

            return [$response, false];
        });
    }

    /** @return array{state: array<string, mixed>, map: array<string, mixed>|null, progress: array<string, mixed>|null} */
    public function participantSnapshot(SessionParticipant $participant): array
    {
        /** @var LiveSession $session */
        $session = LiveSession::query()->findOrFail($participant->live_session_id);
        $state = $this->snapshot($session);
        if ($state->map_id === null) {
            return ['state' => $state->toApi(), 'map' => null, 'progress' => null];
        }
        $map = $this->progresses->participantSnapshot($participant, $state->map_id);

        return ['state' => $state->toApi(), 'map' => $map['map'], 'progress' => $map['progress']];
    }

    private function assertMapInPinnedRevision(LiveSession $session, ?string $mapId): void
    {
        if ($mapId === null) {
            return;
        }
        /** @var CampaignRevision $revision */
        $revision = CampaignRevision::query()->findOrFail($session->campaign_revision_id);
        foreach ($revision->manifest['maps'] ?? [] as $map) {
            if (is_array($map) && ($map['id'] ?? null) === $mapId) {
                return;
            }
        }

        abort(422, 'The selected map must belong to the pinned revision.');
    }
}
