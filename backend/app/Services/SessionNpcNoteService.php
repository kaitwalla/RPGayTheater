<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LiveSession;
use App\Models\OutboxEvent;
use App\Models\ProcessedCommand;
use App\Models\SessionEvent;
use App\Models\SessionNpcNote;
use App\Models\SessionNpcReveal;
use App\Models\SessionParticipant;
use Illuminate\Support\Facades\DB;

class SessionNpcNoteService
{
    /** @return array{0: array<string, mixed>, 1: bool} */
    public function createParticipant(string $participantId, string $commandId, string $npcId, string $body): array
    {
        return DB::transaction(function () use ($participantId, $commandId, $npcId, $body): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var SessionParticipant $participant */
            $participant = SessionParticipant::query()->lockForUpdate()->findOrFail($participantId);
            abort_unless($participant->role === 'player' && $participant->revoked_at === null, 403, 'Only active players can add shared NPC notes.');
            /** @var LiveSession $session */
            $session = LiveSession::query()->lockForUpdate()->findOrFail($participant->live_session_id);
            abort_unless($session->status === 'active', 422, 'Shared NPC notes can be added only during an active session.');
            abort_unless(SessionNpcReveal::query()->where('live_session_id', $session->id)->where('npc_id', $npcId)->where('is_revealed', true)->exists(), 404, 'This NPC profile is not revealed to participants.');
            $body = trim($body);
            abort_if($body === '', 422, 'A shared NPC note cannot be blank.');
            $note = SessionNpcNote::query()->create(['live_session_id' => $session->id, 'npc_id' => $npcId, 'author_type' => 'participant', 'session_participant_id' => $participant->id, 'body' => $body]);
            $response = ['data' => $note->toApi()];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'session_npc_note', 'aggregate_id' => $note->id, 'response' => $response]);
            SessionEvent::query()->create(['campaign_id' => $session->campaign_id, 'actor_type' => 'participant', 'event_type' => 'npc_note.created', 'command_id' => $commandId, 'payload' => ['live_session_id' => $session->id, 'npc_id' => $npcId, 'note_id' => $note->id, 'session_participant_id' => $participant->id], 'occurred_at' => now()]);
            OutboxEvent::query()->create(['aggregate_type' => 'session_npc_note', 'aggregate_id' => $note->id, 'topic' => 'npc_notes.'.$session->id, 'payload' => ['event_type' => 'npc_note.created', 'npc_id' => $npcId, 'note_id' => $note->id], 'occurred_at' => now()]);

            return [$response, false];
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function updateParticipant(string $participantId, string $commandId, string $noteId, string $body): array
    {
        return $this->mutateParticipant($participantId, $commandId, $noteId, $body, false);
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function deleteParticipant(string $participantId, string $commandId, string $noteId): array
    {
        return $this->mutateParticipant($participantId, $commandId, $noteId, null, true);
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    private function mutateParticipant(string $participantId, string $commandId, string $noteId, ?string $body, bool $delete): array
    {
        return DB::transaction(function () use ($participantId, $commandId, $noteId, $body, $delete): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var SessionParticipant $participant */
            $participant = SessionParticipant::query()->lockForUpdate()->findOrFail($participantId);
            abort_unless($participant->role === 'player' && $participant->revoked_at === null, 403, 'Only active players can edit their shared NPC notes.');
            /** @var LiveSession $session */
            $session = LiveSession::query()->lockForUpdate()->findOrFail($participant->live_session_id);
            abort_unless($session->status === 'active', 422, 'Shared NPC notes can be changed only during an active session.');
            /** @var SessionNpcNote $note */
            $note = SessionNpcNote::query()->where('live_session_id', $session->id)->lockForUpdate()->findOrFail($noteId);
            abort_unless($note->author_type === 'participant' && $note->session_participant_id === $participant->id, 403, 'You can change only your own shared NPC notes.');
            $eventType = $delete ? 'npc_note.deleted' : 'npc_note.updated';
            if ($delete) {
                $data = $note->toApi();
                $note->delete();
                $response = ['data' => $data];
            } else {
                $body = trim((string) $body);
                abort_if($body === '', 422, 'A shared NPC note cannot be blank.');
                $note->update(['body' => $body]);
                $note->refresh();
                $response = ['data' => $note->toApi()];
            }
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'session_npc_note', 'aggregate_id' => $noteId, 'response' => $response]);
            SessionEvent::query()->create(['campaign_id' => $session->campaign_id, 'actor_type' => 'participant', 'event_type' => $eventType, 'command_id' => $commandId, 'payload' => ['live_session_id' => $session->id, 'npc_id' => $note->npc_id, 'note_id' => $noteId, 'session_participant_id' => $participant->id], 'occurred_at' => now()]);
            OutboxEvent::query()->create(['aggregate_type' => 'session_npc_note', 'aggregate_id' => $noteId, 'topic' => 'npc_notes.'.$session->id, 'payload' => ['event_type' => $eventType, 'npc_id' => $note->npc_id, 'note_id' => $noteId], 'occurred_at' => now()]);

            return [$response, false];
        });
    }
}
