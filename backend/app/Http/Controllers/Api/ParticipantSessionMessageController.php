<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateParticipantSessionMessageRequest;
use App\Services\SessionMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParticipantSessionMessageController extends Controller
{
    public function __construct(private readonly SessionMessageService $messages) {}

    public function index(Request $request): JsonResponse
    {
        $participantId = $request->session()->get('participant.id');
        abort_unless(is_string($participantId), 401, 'Participant authentication is required.');

        return response()->json(['data' => $this->messages->participantMessages($participantId)->map(fn ($message): array => $this->messages->toApi($message))->values()]);
    }

    public function store(CreateParticipantSessionMessageRequest $request): JsonResponse
    {
        $participantId = $request->session()->get('participant.id');
        abort_unless(is_string($participantId), 401, 'Participant authentication is required.');
        [$response, $replayed] = $this->messages->createParticipant($participantId, $request->string('command_id')->toString(), $request->string('target_type')->toString(), $request->string('session_player_group_id')->toString() ?: null, $request->string('reply_to_session_message_id')->toString() ?: null, $request->string('body')->toString());

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }
}
