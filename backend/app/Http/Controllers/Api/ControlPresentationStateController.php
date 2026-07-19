<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\StalePresentationState;
use App\Http\Controllers\Controller;
use App\Http\Requests\PresentationCommandRequest;
use App\Http\Requests\SetPresentationStateRequest;
use App\Models\LiveSession;
use App\Services\PresentationStateService;
use Illuminate\Http\JsonResponse;

class ControlPresentationStateController extends Controller
{
    public function __construct(private readonly PresentationStateService $states) {}

    public function show(string $campaign, string $session): JsonResponse
    {
        /** @var LiveSession $session */
        $session = LiveSession::query()->where('campaign_id', $campaign)->findOrFail($session);

        return response()->json(['data' => $this->states->snapshot($session)->toApi()]);
    }

    public function update(SetPresentationStateRequest $request, string $campaign, string $session): JsonResponse
    {
        try {
            [$response, $replayed] = $this->states->set($campaign, $session, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->array('state'));
        } catch (StalePresentationState $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->state->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function standby(SetPresentationStateRequest $request, string $campaign, string $session): JsonResponse
    {
        try {
            [$response, $replayed] = $this->states->standby($campaign, $session, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->array('state'));
        } catch (StalePresentationState $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->state->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function go(PresentationCommandRequest $request, string $campaign, string $session): JsonResponse
    {
        try {
            [$response, $replayed] = $this->states->go($campaign, $session, $request->string('command_id')->toString(), $request->integer('expected_revision'));
        } catch (StalePresentationState $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->state->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }
}
