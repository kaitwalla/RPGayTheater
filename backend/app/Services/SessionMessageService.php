<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LiveSession;
use App\Models\OutboxEvent;
use App\Models\ProcessedCommand;
use App\Models\SessionEvent;
use App\Models\SessionMessage;
use App\Models\SessionMessageRecipient;
use App\Models\SessionParticipant;
use App\Models\SessionPlayerGroup;
use App\Models\SessionPlayerGroupMember;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SessionMessageService
{
    /** @return array{0: array<string, mixed>, 1: bool} */
    public function createControl(string $campaignId, string $sessionId, string $commandId, string $targetType, ?string $targetParticipantId, ?string $groupId, string $body): array
    {
        return DB::transaction(function () use ($campaignId, $sessionId, $commandId, $targetType, $targetParticipantId, $groupId, $body): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var LiveSession $session */
            $session = LiveSession::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($sessionId);
            $this->assertActiveSession($session);
            $body = $this->plainBody($body);
            [$recipientIds, $participantId, $playerGroupId] = $this->controlRecipients($session, $targetType, $targetParticipantId, $groupId);
            $message = SessionMessage::query()->create(['live_session_id' => $session->id, 'sender_type' => 'control', 'sender_session_participant_id' => null, 'target_type' => $targetType, 'target_session_participant_id' => $participantId, 'session_player_group_id' => $playerGroupId, 'reply_to_session_message_id' => null, 'body' => $body]);
            $this->storeRecipients($message, $recipientIds);
            $response = ['data' => $this->toApi($message, 'Control')];
            $this->record($session, $message, $commandId, 'control');

            return [$response, false];
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function createParticipant(string $participantId, string $commandId, string $targetType, ?string $groupId, ?string $replyToId, string $body): array
    {
        return DB::transaction(function () use ($participantId, $commandId, $targetType, $groupId, $replyToId, $body): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var SessionParticipant $participant */
            $participant = SessionParticipant::query()->lockForUpdate()->findOrFail($participantId);
            abort_if($participant->revoked_at !== null, 403, 'This participant has been revoked.');
            /** @var LiveSession $session */
            $session = LiveSession::query()->lockForUpdate()->findOrFail($participant->live_session_id);
            $this->assertActiveSession($session);
            $body = $this->plainBody($body);
            $recipientIds = [];
            $playerGroupId = null;
            if ($targetType === 'player_group') {
                abort_unless($participant->role === 'player', 403, 'Only Players may message a named Player group.');
                /** @var SessionPlayerGroup $group */
                $group = SessionPlayerGroup::query()->where('live_session_id', $session->id)->lockForUpdate()->findOrFail($groupId);
                abort_unless(SessionPlayerGroupMember::query()->where('session_player_group_id', $group->id)->where('session_participant_id', $participant->id)->exists(), 403, 'You are not a member of this Player group.');
                $recipientIds = $this->activeGroupMemberIds($group);
                $playerGroupId = $group->id;
            } else {
                abort_if($groupId !== null, 422, 'A group is valid only for Player group messages.');
                if ($replyToId !== null) {
                    $reply = SessionMessage::query()->where('live_session_id', $session->id)->findOrFail($replyToId);
                    abort_unless(in_array($reply->target_type, ['all_players', 'all_spectators', 'all'], true) && SessionMessageRecipient::query()->where('session_message_id', $reply->id)->where('session_participant_id', $participant->id)->exists(), 403, 'You can reply only to a broadcast you received.');
                }
            }
            $message = SessionMessage::query()->create(['live_session_id' => $session->id, 'sender_type' => 'participant', 'sender_session_participant_id' => $participant->id, 'target_type' => $targetType, 'target_session_participant_id' => null, 'session_player_group_id' => $playerGroupId, 'reply_to_session_message_id' => $replyToId, 'body' => $body]);
            $this->storeRecipients($message, $recipientIds);
            $response = ['data' => $this->toApi($message, $participant->display_name)];
            $this->record($session, $message, $commandId, 'participant');

            return [$response, false];
        });
    }

    /** @return Collection<int, SessionMessage> */
    public function controlMessages(string $campaignId, string $sessionId): Collection
    {
        LiveSession::query()->where('campaign_id', $campaignId)->findOrFail($sessionId);

        return SessionMessage::query()->where('live_session_id', $sessionId)->orderBy('created_at')->get();
    }

    /** @return Collection<int, SessionMessage> */
    public function participantMessages(string $participantId): Collection
    {
        /** @var SessionParticipant $participant */
        $participant = SessionParticipant::query()->findOrFail($participantId);
        abort_if($participant->revoked_at !== null, 403, 'This participant has been revoked.');

        return SessionMessage::query()
            ->where('live_session_id', $participant->live_session_id)
            ->where(static function ($query) use ($participant): void {
                $query->where('sender_session_participant_id', $participant->id)
                    ->orWhereIn('id', SessionMessageRecipient::query()->where('session_participant_id', $participant->id)->select('session_message_id'));
            })
            ->orderBy('created_at')
            ->get();
    }

    /** @return array<string, mixed> */
    public function toApi(SessionMessage $message, ?string $senderName = null): array
    {
        if ($senderName === null) {
            $senderName = $message->sender_type === 'control'
                ? 'Control'
                : SessionParticipant::query()->findOrFail($message->sender_session_participant_id)->display_name;
        }

        return $message->toApi($senderName);
    }

    private function assertActiveSession(LiveSession $session): void
    {
        abort_unless($session->status === 'active', 422, 'Messages can be sent only during an active session.');
    }

    private function plainBody(string $body): string
    {
        $body = trim($body);
        abort_if($body === '', 422, 'A message cannot be blank.');

        return $body;
    }

    /** @return array{0: array<int, string>, 1: string|null, 2: string|null} */
    private function controlRecipients(LiveSession $session, string $targetType, ?string $targetParticipantId, ?string $groupId): array
    {
        if ($targetType === 'individual') {
            /** @var SessionParticipant $participant */
            $participant = SessionParticipant::query()->where('live_session_id', $session->id)->lockForUpdate()->findOrFail($targetParticipantId);
            abort_if($participant->revoked_at !== null, 422, 'The selected participant is no longer active.');

            return [[$participant->id], $participant->id, null];
        }
        if ($targetType === 'player_group') {
            /** @var SessionPlayerGroup $group */
            $group = SessionPlayerGroup::query()->where('live_session_id', $session->id)->lockForUpdate()->findOrFail($groupId);

            return [$this->activeGroupMemberIds($group), null, $group->id];
        }
        abort_if($targetParticipantId !== null || $groupId !== null, 422, 'This audience does not accept an individual or group target.');
        $participants = SessionParticipant::query()->where('live_session_id', $session->id)->whereNull('revoked_at');
        if ($targetType === 'all_players') {
            $participants->where('role', 'player');
        } elseif ($targetType === 'all_spectators') {
            $participants->where('role', 'spectator');
        }

        return [$participants->pluck('id')->all(), null, null];
    }

    /** @return array<int, string> */
    private function activeGroupMemberIds(SessionPlayerGroup $group): array
    {
        return SessionParticipant::query()
            ->where('live_session_id', $group->live_session_id)
            ->where('role', 'player')
            ->whereNull('revoked_at')
            ->whereIn('id', SessionPlayerGroupMember::query()->where('session_player_group_id', $group->id)->select('session_participant_id'))
            ->pluck('id')
            ->all();
    }

    /** @param array<int, string> $recipientIds */
    private function storeRecipients(SessionMessage $message, array $recipientIds): void
    {
        foreach (array_unique($recipientIds) as $recipientId) {
            SessionMessageRecipient::query()->create(['session_message_id' => $message->id, 'session_participant_id' => $recipientId]);
        }
    }

    private function record(LiveSession $session, SessionMessage $message, string $commandId, string $actorType): void
    {
        SessionEvent::query()->create(['campaign_id' => $session->campaign_id, 'actor_type' => $actorType, 'event_type' => 'message.sent', 'command_id' => $commandId, 'payload' => ['live_session_id' => $session->id, 'session_message_id' => $message->id, 'target_type' => $message->target_type, 'session_player_group_id' => $message->session_player_group_id, 'target_session_participant_id' => $message->target_session_participant_id], 'occurred_at' => now()]);
        OutboxEvent::query()->create(['aggregate_type' => 'session_message', 'aggregate_id' => $message->id, 'topic' => 'session_messages.'.$session->id, 'payload' => ['event_type' => 'message.sent', 'session_message_id' => $message->id], 'occurred_at' => now()]);
        ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'session_message', 'aggregate_id' => $message->id, 'response' => ['data' => $this->toApi($message)]]);
    }
}
