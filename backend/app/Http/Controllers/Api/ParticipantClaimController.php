<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CampaignRevision;
use App\Models\LiveSession;
use App\Models\PlayerCharacterClaim;
use App\Models\SessionParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ParticipantClaimController extends Controller
{
    public function claim(Request $request): JsonResponse
    {
        $pcId = $request->validate(['player_character_id' => ['required', 'uuid']])['player_character_id'];
        $participantId = $request->session()->get('participant.id');
        abort_unless(is_string($participantId), 401, 'Participant authentication is required.');
        $claim = DB::transaction(function () use ($participantId, $pcId): PlayerCharacterClaim {
            $participant = SessionParticipant::query()->lockForUpdate()->findOrFail($participantId);
            abort_unless($participant->role === 'player' && $participant->revoked_at === null, 403, 'Only active players can claim a character.');
            $session = LiveSession::query()->lockForUpdate()->findOrFail($participant->live_session_id);
            $revision = CampaignRevision::query()->findOrFail($session->campaign_revision_id);
            $ids = array_column($revision->manifest['player_characters'] ?? [], 'id');
            abort_unless(in_array($pcId, $ids, true), 422, 'This character is not available in the pinned campaign revision.');
            abort_if(PlayerCharacterClaim::query()->where('session_participant_id', $participant->id)->exists(), 422, 'This participant already has a character claim.');

            return PlayerCharacterClaim::query()->create(['live_session_id' => $session->id, 'player_character_id' => $pcId, 'session_participant_id' => $participant->id]);
        });

        return response()->json(['data' => ['id' => $claim->id, 'player_character_id' => $claim->player_character_id]], 201);
    }
}
