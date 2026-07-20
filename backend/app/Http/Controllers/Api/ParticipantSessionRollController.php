<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateSessionRollRequest;
use App\Services\SessionRollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParticipantSessionRollController extends Controller
{
    public function __construct(private readonly SessionRollService $rolls) {}

    public function index(Request $request): JsonResponse
    {
        $participantId = $request->session()->get('participant.id');
        abort_unless(is_string($participantId), 401, 'Participant authentication is required.');

        return response()->json(['data' => $this->rolls->participantRolls($participantId)->map(fn ($roll): array => $this->rolls->toApi($roll))->values()]);
    }

    public function store(CreateSessionRollRequest $request): JsonResponse
    {
        $participantId = $request->session()->get('participant.id');
        abort_unless(is_string($participantId), 401, 'Participant authentication is required.');
        [$response, $replayed] = $this->rolls->create($participantId, $request->string('command_id')->toString(), $request->string('expression')->toString() ?: null, $request->string('dice_preset_id')->toString() ?: null, $request->string('visibility')->toString() ?: null);

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }
}
