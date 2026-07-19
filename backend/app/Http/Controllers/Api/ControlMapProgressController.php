<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\StaleMapProgress;
use App\Http\Controllers\Controller;
use App\Http\Requests\ResetMapProgressRequest;
use App\Http\Requests\SetMapProgressRequest;
use App\Models\LiveSession;
use App\Services\MapProgressService;
use Illuminate\Http\JsonResponse;

class ControlMapProgressController extends Controller
{
    public function __construct(private readonly MapProgressService $progresses) {}

    public function show(string $campaign, string $session, string $map): JsonResponse
    {
        /** @var LiveSession $session */
        $session = LiveSession::query()->where('campaign_id', $campaign)->findOrFail($session);

        return response()->json(['data' => $this->progresses->snapshot($session, $map)->toApi()]);
    }

    public function update(SetMapProgressRequest $request, string $campaign, string $session, string $map): JsonResponse
    {
        try {
            /** @var list<array<string, mixed>> $tokens */
            $tokens = array_values($request->array('tokens'));
            [$response, $replayed] = $this->progresses->update($campaign, $session, $map, $request->string('command_id')->toString(), $request->integer('expected_revision'), $tokens);
        } catch (StaleMapProgress $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->progress->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function reset(ResetMapProgressRequest $request, string $campaign, string $session, string $map): JsonResponse
    {
        try {
            [$response, $replayed] = $this->progresses->reset($campaign, $session, $map, $request->string('command_id')->toString(), $request->integer('expected_revision'));
        } catch (StaleMapProgress $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->progress->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }
}
