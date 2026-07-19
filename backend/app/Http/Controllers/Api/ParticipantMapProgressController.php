<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SessionParticipant;
use App\Services\MapProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParticipantMapProgressController extends Controller
{
    public function __construct(private readonly MapProgressService $progresses) {}

    public function show(Request $request, string $map): JsonResponse
    {
        $participantId = $request->session()->get('participant.id');
        abort_unless(is_string($participantId), 401, 'Participant authentication is required.');
        $participant = SessionParticipant::query()->findOrFail($participantId);
        abort_if($participant->revoked_at !== null, 403, 'This participant has been revoked.');

        return response()->json(['data' => $this->progresses->participantSnapshot($participant, $map)]);
    }
}
