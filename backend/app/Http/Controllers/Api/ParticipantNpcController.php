<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CampaignRevision;
use App\Models\LiveSession;
use App\Models\SessionNpcReveal;
use App\Models\SessionParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParticipantNpcController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $participantId = $request->session()->get('participant.id');
        abort_unless(is_string($participantId), 401, 'Participant authentication is required.');
        /** @var SessionParticipant $participant */
        $participant = SessionParticipant::query()->findOrFail($participantId);
        abort_if($participant->revoked_at !== null, 403, 'This participant has been revoked.');
        /** @var LiveSession $session */
        $session = LiveSession::query()->findOrFail($participant->live_session_id);
        /** @var CampaignRevision $revision */
        $revision = CampaignRevision::query()->findOrFail($session->campaign_revision_id);
        $revealed = SessionNpcReveal::query()->where('live_session_id', $session->id)->where('is_revealed', true)->pluck('revealed_at', 'npc_id');
        $npcs = [];
        foreach ($revision->manifest['npcs'] ?? [] as $npc) {
            if (! is_array($npc) || ! is_string($npc['id'] ?? null) || ! $revealed->has($npc['id'])) {
                continue;
            }
            $npcs[] = ['id' => $npc['id'], 'name' => $npc['name'] ?? null, 'pronouns' => $npc['pronouns'] ?? null, 'public_description' => $npc['public_description'] ?? null, 'revealed_at' => $revealed->get($npc['id'])?->toAtomString()];
        }

        return response()->json(['data' => $npcs]);
    }
}
