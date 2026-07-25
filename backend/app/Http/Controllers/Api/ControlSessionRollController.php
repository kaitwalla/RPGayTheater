<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateSessionRollRequest;
use App\Http\Requests\RevealSessionRollRequest;
use App\Services\SessionRollService;
use Illuminate\Http\JsonResponse;

class ControlSessionRollController extends Controller
{
    public function __construct(private readonly SessionRollService $rolls) {}

    public function index(string $campaign, string $session): JsonResponse
    {
        return response()->json(['data' => $this->rolls->controlRolls($campaign, $session)->map(fn ($roll): array => $this->rolls->toApi($roll))->values()]);
    }

    public function store(CreateSessionRollRequest $request, string $campaign, string $session): JsonResponse
    {
        [$response, $replayed] = $this->rolls->createForControl($campaign, $session, $request->string('command_id')->toString(), $request->string('expression')->toString() ?: null, $request->string('dice_preset_id')->toString() ?: null, $request->string('visibility')->toString() ?: null);

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }

    public function reveal(RevealSessionRollRequest $request, string $campaign, string $session, string $roll): JsonResponse
    {
        [$response, $replayed] = $this->rolls->reveal($campaign, $session, $roll, $request->string('command_id')->toString());

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }
}
