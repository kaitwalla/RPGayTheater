<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LiveSession;
use App\Models\OutboxEvent;
use App\Models\PlayerCharacterClaim;
use App\Models\SessionEvent;
use App\Models\SessionPlayerGroup;
use App\Models\SessionPlayerGroupMember;

class SessionPlayerGroupTransferService
{
    public function restoreForClaim(PlayerCharacterClaim $claim): void
    {
        /** @var LiveSession $session */
        $session = LiveSession::query()->findOrFail($claim->live_session_id);
        $groups = SessionPlayerGroup::query()
            ->where('live_session_id', $claim->live_session_id)
            ->whereNotNull('source_session_player_group_id')
            ->get(['id', 'source_session_player_group_id']);
        if ($groups->isEmpty()) {
            return;
        }
        $sourceGroupIds = $groups->pluck('source_session_player_group_id')->filter()->all();
        $priorParticipantIds = PlayerCharacterClaim::query()
            ->where('player_character_id', $claim->player_character_id)
            ->whereIn('session_participant_id', SessionPlayerGroupMember::query()->whereIn('session_player_group_id', $sourceGroupIds)->pluck('session_participant_id'))
            ->pluck('session_participant_id');
        if ($priorParticipantIds->isEmpty()) {
            return;
        }
        $eligibleSourceGroupIds = SessionPlayerGroupMember::query()
            ->whereIn('session_player_group_id', $sourceGroupIds)
            ->whereIn('session_participant_id', $priorParticipantIds)
            ->pluck('session_player_group_id');

        foreach ($groups->whereIn('source_session_player_group_id', $eligibleSourceGroupIds) as $group) {
            $member = SessionPlayerGroupMember::query()->firstOrCreate(['session_player_group_id' => $group->id, 'session_participant_id' => $claim->session_participant_id]);
            if (! $member->wasRecentlyCreated) {
                continue;
            }
            SessionEvent::query()->create(['campaign_id' => $session->campaign_id, 'actor_type' => 'participant', 'event_type' => 'player_group.member_restored', 'command_id' => null, 'payload' => ['live_session_id' => $session->id, 'session_player_group_id' => $group->id, 'session_participant_id' => $claim->session_participant_id, 'player_character_id' => $claim->player_character_id], 'occurred_at' => now()]);
            OutboxEvent::query()->create(['aggregate_type' => 'session_player_group', 'aggregate_id' => $group->id, 'topic' => 'player_groups.'.$session->id, 'payload' => ['event_type' => 'player_group.member_restored', 'session_player_group_id' => $group->id, 'session_participant_id' => $claim->session_participant_id], 'occurred_at' => now()]);
        }
    }
}
