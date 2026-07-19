<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CampaignRevision;
use App\Models\LiveSession;
use App\Models\OutboxEvent;
use App\Models\ProcessedCommand;
use App\Models\SessionEvent;
use App\Models\SessionNpcReveal;
use Illuminate\Support\Facades\DB;

class NpcRevealService
{
    /** @return array{0: array<string, mixed>, 1: bool} */
    public function set(string $campaignId, string $sessionId, string $commandId, string $npcId, bool $isRevealed): array
    {
        return DB::transaction(function () use ($campaignId, $sessionId, $commandId, $npcId, $isRevealed): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var LiveSession $session */
            $session = LiveSession::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($sessionId);
            /** @var CampaignRevision $revision */
            $revision = CampaignRevision::query()->findOrFail($session->campaign_revision_id);
            abort_unless(in_array($npcId, array_column($revision->manifest['npcs'] ?? [], 'id'), true), 422, 'This NPC is not available in the pinned campaign revision.');

            $reveal = SessionNpcReveal::query()->where('live_session_id', $session->id)->where('npc_id', $npcId)->lockForUpdate()->first();
            if ($reveal === null) {
                $reveal = SessionNpcReveal::query()->create(['live_session_id' => $session->id, 'npc_id' => $npcId, 'is_revealed' => $isRevealed, 'revealed_at' => $isRevealed ? now() : null]);
            } else {
                $reveal->update(['is_revealed' => $isRevealed, 'revealed_at' => $isRevealed ? now() : null]);
            }
            $reveal->refresh();
            $response = ['data' => $reveal->toApi()];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'session_npc_reveal', 'aggregate_id' => $reveal->id, 'response' => $response]);
            $eventType = $isRevealed ? 'npc.revealed' : 'npc.hidden';
            SessionEvent::query()->create(['campaign_id' => $campaignId, 'actor_type' => 'control', 'event_type' => $eventType, 'command_id' => $commandId, 'payload' => ['live_session_id' => $session->id, 'npc_id' => $npcId], 'occurred_at' => now()]);
            OutboxEvent::query()->create(['aggregate_type' => 'session_npc_reveal', 'aggregate_id' => $reveal->id, 'topic' => 'npc_reveals.'.$session->id, 'payload' => ['event_type' => $eventType, 'npc_id' => $npcId], 'occurred_at' => now()]);

            return [$response, false];
        });
    }
}
