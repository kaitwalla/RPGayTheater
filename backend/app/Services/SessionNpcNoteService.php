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
}
