<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateControlSessionMessageRequest;
use App\Services\SessionMessageService;
use Illuminate\Http\JsonResponse;

class ControlSessionMessageController extends Controller
{
    public function __construct(private readonly SessionMessageService $messages) {}

    public function index(string $campaign, string $session): JsonResponse
    {
        return response()->json(['data' => $this->messages->controlMessages($campaign, $session)->map(fn ($message): array => $this->messages->toApi($message))->values()]);
    }

    public function store(CreateControlSessionMessageRequest $request, string $campaign, string $session): JsonResponse
    {
        [$response, $replayed] = $this->messages->createControl($campaign, $session, $request->string('command_id')->toString(), $request->string('target_type')->toString(), $request->string('target_session_participant_id')->toString() ?: null, $request->string('session_player_group_id')->toString() ?: null, $request->string('body')->toString());

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }
}
