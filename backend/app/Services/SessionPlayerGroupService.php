<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LiveSession;
use App\Models\OutboxEvent;
use App\Models\ProcessedCommand;
use App\Models\SessionEvent;
use App\Models\SessionParticipant;
use App\Models\SessionPlayerGroup;
use App\Models\SessionPlayerGroupMember;
use Illuminate\Support\Facades\DB;

class SessionPlayerGroupService
{
    /** @return array{0: array<string, mixed>, 1: bool} */
    public function create(string $campaignId, string $sessionId, string $commandId, string $name): array
    {
        return DB::transaction(function () use ($campaignId, $sessionId, $commandId, $name): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var LiveSession $session */
            $session = LiveSession::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($sessionId);
            $name = trim($name);
            abort_if($name === '', 422, 'A group name cannot be blank.');
            $normalizedName = mb_strtolower($name);
            abort_if(SessionPlayerGroup::query()->where('live_session_id', $session->id)->where('name_normalized', $normalizedName)->exists(), 422, 'A Player group with that name already exists.');
            $group = SessionPlayerGroup::query()->create(['live_session_id' => $session->id, 'name' => $name, 'name_normalized' => $normalizedName]);
            $response = ['data' => $this->toApi($group)];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'session_player_group', 'aggregate_id' => $group->id, 'response' => $response]);
            SessionEvent::query()->create(['campaign_id' => $campaignId, 'actor_type' => 'control', 'event_type' => 'player_group.created', 'command_id' => $commandId, 'payload' => ['live_session_id' => $session->id, 'session_player_group_id' => $group->id], 'occurred_at' => now()]);
            OutboxEvent::query()->create(['aggregate_type' => 'session_player_group', 'aggregate_id' => $group->id, 'topic' => 'player_groups.'.$session->id, 'payload' => ['event_type' => 'player_group.created', 'session_player_group_id' => $group->id], 'occurred_at' => now()]);

            return [$response, false];
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function addMember(string $campaignId, string $sessionId, string $groupId, string $participantId, string $commandId): array
    {
        return $this->mutateMember($campaignId, $sessionId, $groupId, $participantId, $commandId, true);
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function removeMember(string $campaignId, string $sessionId, string $groupId, string $participantId, string $commandId): array
    {
        return $this->mutateMember($campaignId, $sessionId, $groupId, $participantId, $commandId, false);
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    private function mutateMember(string $campaignId, string $sessionId, string $groupId, string $participantId, string $commandId, bool $add): array
    {
        return DB::transaction(function () use ($campaignId, $sessionId, $groupId, $participantId, $commandId, $add): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var LiveSession $session */
            $session = LiveSession::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($sessionId);
            /** @var SessionPlayerGroup $group */
            $group = SessionPlayerGroup::query()->where('live_session_id', $session->id)->lockForUpdate()->findOrFail($groupId);
            /** @var SessionParticipant $participant */
            $participant = SessionParticipant::query()->where('live_session_id', $session->id)->lockForUpdate()->findOrFail($participantId);
            abort_unless($participant->role === 'player' && $participant->revoked_at === null, 422, 'Only active Players may join a named Player group.');
            if ($add) {
                SessionPlayerGroupMember::query()->firstOrCreate(['session_player_group_id' => $group->id, 'session_participant_id' => $participant->id]);
            } else {
                SessionPlayerGroupMember::query()->where('session_player_group_id', $group->id)->where('session_participant_id', $participant->id)->delete();
            }
            $eventType = $add ? 'player_group.member_added' : 'player_group.member_removed';
            $response = ['data' => $this->toApi($group)];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'session_player_group', 'aggregate_id' => $group->id, 'response' => $response]);
            SessionEvent::query()->create(['campaign_id' => $campaignId, 'actor_type' => 'control', 'event_type' => $eventType, 'command_id' => $commandId, 'payload' => ['live_session_id' => $session->id, 'session_player_group_id' => $group->id, 'session_participant_id' => $participant->id], 'occurred_at' => now()]);
            OutboxEvent::query()->create(['aggregate_type' => 'session_player_group', 'aggregate_id' => $group->id, 'topic' => 'player_groups.'.$session->id, 'payload' => ['event_type' => $eventType, 'session_player_group_id' => $group->id, 'session_participant_id' => $participant->id], 'occurred_at' => now()]);

            return [$response, false];
        });
    }

    /** @return array{id: string, name: string, member_participant_ids: array<int, string>} */
    private function toApi(SessionPlayerGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'member_participant_ids' => SessionPlayerGroupMember::query()
                ->where('session_player_group_id', $group->id)
                ->orderBy('session_participant_id')
                ->pluck('session_participant_id')
                ->all(),
        ];
    }
}
