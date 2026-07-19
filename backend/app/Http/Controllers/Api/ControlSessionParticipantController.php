<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveSession;
use App\Models\PlayerCharacterClaim;
use App\Models\SessionParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ControlSessionParticipantController extends Controller
{
    public function index(string $campaign, string $session): JsonResponse
    {
        $this->sessionForCampaign($campaign, $session);
        $claims = PlayerCharacterClaim::query()->where('live_session_id', $session)->pluck('player_character_id', 'session_participant_id');
        $data = [];

        foreach (SessionParticipant::query()->where('live_session_id', $session)->orderBy('display_name')->get() as $participant) {
            $claim = $claims->get($participant->id);
            $data[] = [
                'id' => $participant->id,
                'role' => $participant->role,
                'display_name' => $participant->display_name,
                'player_character_id' => is_string($claim) ? $claim : null,
                'revoked_at' => $participant->revoked_at?->toAtomString(),
            ];
        }

        return response()->json(['data' => $data]);
    }

    public function release(string $campaign, string $session, string $participant): JsonResponse
    {
        $this->participantForCampaign($campaign, $session, $participant);
        PlayerCharacterClaim::query()->where('session_participant_id', $participant)->delete();

        return response()->json(status: 204);
    }

    public function revoke(string $campaign, string $session, string $participant): JsonResponse
    {
        DB::transaction(function () use ($campaign, $session, $participant): void {
            $this->sessionForCampaign($campaign, $session, true);
            $participant = SessionParticipant::query()->lockForUpdate()->where('live_session_id', $session)->findOrFail($participant);
            $participant->update(['revoked_at' => now()]);
            PlayerCharacterClaim::query()->where('session_participant_id', $participant->id)->delete();
        });

        return response()->json(status: 204);
    }

    private function participantForCampaign(string $campaign, string $session, string $participant): SessionParticipant
    {
        $this->sessionForCampaign($campaign, $session);

        return SessionParticipant::query()->where('live_session_id', $session)->findOrFail($participant);
    }

    private function sessionForCampaign(string $campaign, string $session, bool $lock = false): LiveSession
    {
        $query = LiveSession::query()->where('campaign_id', $campaign);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($session);
    }
}
