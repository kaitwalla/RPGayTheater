<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateNpcNoteRequest;
use App\Models\SessionParticipant;
use App\Services\SessionNpcNoteService;
use Illuminate\Http\JsonResponse;

class ParticipantNpcNoteController extends Controller
{
    public function __construct(private readonly SessionNpcNoteService $notes) {}

    public function store(CreateNpcNoteRequest $request, string $npc): JsonResponse
    {
        $participantId = $request->session()->get('participant.id');
        abort_unless(is_string($participantId), 401, 'Participant authentication is required.');
        SessionParticipant::query()->findOrFail($participantId);
        [$response, $replayed] = $this->notes->createParticipant($participantId, $request->string('command_id')->toString(), $npc, $request->string('body')->toString());

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }
}
