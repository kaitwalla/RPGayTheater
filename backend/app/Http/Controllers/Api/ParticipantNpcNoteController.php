<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateNpcNoteRequest;
use App\Http\Requests\UpdateNpcNoteRequest;
use App\Models\SessionParticipant;
use App\Services\SessionNpcNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function update(UpdateNpcNoteRequest $request, string $note): JsonResponse
    {
        $participantId = $request->session()->get('participant.id');
        abort_unless(is_string($participantId), 401, 'Participant authentication is required.');
        [$response, $replayed] = $this->notes->updateParticipant($participantId, $request->string('command_id')->toString(), $note, $request->string('body')->toString());

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function destroy(Request $request, string $note): JsonResponse
    {
        $input = $request->validate(['command_id' => ['required', 'uuid']]);
        $participantId = $request->session()->get('participant.id');
        abort_unless(is_string($participantId), 401, 'Participant authentication is required.');
        [$response, $replayed] = $this->notes->deleteParticipant($participantId, $input['command_id'], $note);

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }
}
