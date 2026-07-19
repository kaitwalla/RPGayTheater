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

class ParticipantRosterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $participantId = $request->session()->get('participant.id');
        abort_unless(is_string($participantId), 401, 'Participant authentication is required.');
        /** @var SessionParticipant $participant */
        $participant = SessionParticipant::query()->whereNull('revoked_at')->findOrFail($participantId);
        /** @var LiveSession $session */
        $session = LiveSession::query()->findOrFail($participant->live_session_id);
        /** @var CampaignRevision $revision */
        $revision = CampaignRevision::query()->findOrFail($session->campaign_revision_id);
        $claimed = PlayerCharacterClaim::query()->where('live_session_id', $session->id)->pluck('session_participant_id', 'player_character_id');
        $characters = [];
        foreach ($revision->manifest['player_characters'] ?? [] as $character) {
            if (! is_array($character) || ! is_string($character['id'] ?? null)) {
                continue;
            }
            $owner = $claimed->get($character['id']);
            $characters[] = [
                'id' => $character['id'],
                'name' => $character['name'] ?? null,
                'pronouns' => $character['pronouns'] ?? null,
                'public_description' => $character['public_description'] ?? null,
                'claimed' => $owner !== null,
                'claimed_by_me' => $owner === $participant->id,
            ];
        }

        return response()->json(['data' => ['role' => $participant->role, 'characters' => $characters]]);
    }
}
