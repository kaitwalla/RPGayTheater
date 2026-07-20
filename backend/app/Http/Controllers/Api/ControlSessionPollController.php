<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateSessionPollRequest;
use App\Http\Requests\PublishSessionPollResultsRequest;
use App\Services\SessionPollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ControlSessionPollController extends Controller
{
    public function __construct(private readonly SessionPollService $polls) {}

    public function index(string $campaign, string $session): JsonResponse
    {
        return response()->json(['data' => $this->polls->controlPolls($campaign, $session)->map(fn ($poll): array => $this->polls->toApi($poll, null, true))->values()]);
    }

    public function store(CreateSessionPollRequest $request, string $campaign, string $session): JsonResponse
    {
        [$response,$replayed] = $this->polls->create($campaign, $session, $request->string('command_id')->toString(), $request->string('question')->toString(), $request->input('options'), $request->boolean('allows_multiple'), $request->string('target_type')->toString(), $request->string('target_session_participant_id')->toString() ?: null, $request->string('session_player_group_id')->toString() ?: null);

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }

    public function close(Request $request, string $campaign, string $session, string $poll): JsonResponse
    {
        $input = $request->validate(['command_id' => ['required', 'uuid']]);
        [$response,$replayed] = $this->polls->setState($campaign, $session, $poll, $input['command_id'], null, true);

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function publish(PublishSessionPollResultsRequest $request, string $campaign, string $session, string $poll): JsonResponse
    {
        [$response,$replayed] = $this->polls->setState($campaign, $session, $poll, $request->string('command_id')->toString(), $request->string('visibility')->toString(), false);

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }
}
