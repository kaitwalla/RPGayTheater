<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveSession;
use App\Models\SessionParticipant;
use App\Services\MapProgressService;
use App\Services\PlayerMapStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParticipantMapProgressController extends Controller
{
    public function __construct(private readonly MapProgressService $progresses, private readonly PlayerMapStateService $states) {}

    public function show(Request $request, string $map): JsonResponse
    {
        $participant = $this->participant($request);
        /** @var LiveSession $session */
        $session = LiveSession::query()->findOrFail($participant->live_session_id);
        abort_unless($this->states->snapshot($session)->map_id === $map, 404, 'This map is not currently available to participants.');

        return response()->json(['data' => $this->progresses->participantSnapshot($participant, $map)]);
    }

    public function current(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->states->participantSnapshot($this->participant($request))]);
    }

    private function participant(Request $request): SessionParticipant
    {
        $participantId = $request->session()->get('participant.id');
        abort_unless(is_string($participantId), 401, 'Participant authentication is required.');
        $participant = SessionParticipant::query()->find($participantId);
        abort_unless($participant instanceof SessionParticipant, 401, 'Participant authentication is required.');
        abort_if($participant->revoked_at !== null, 403, 'This participant has been revoked.');

        return $participant;
    }
}
