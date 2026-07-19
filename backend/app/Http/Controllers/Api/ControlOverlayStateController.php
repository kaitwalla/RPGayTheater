<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\StaleOverlayState;
use App\Http\Controllers\Controller;
use App\Http\Requests\ControlOverlayActionRequest;
use App\Http\Requests\EnqueueOverlayRequest;
use App\Http\Requests\UpdateOverlayEntryRequest;
use App\Models\LiveSession;
use App\Services\OverlayStateService;
use Illuminate\Http\JsonResponse;

class ControlOverlayStateController extends Controller
{
    public function __construct(private readonly OverlayStateService $states) {}

    public function show(string $campaign, string $session): JsonResponse
    {
        /** @var LiveSession $session */
        $session = LiveSession::query()->where('campaign_id', $campaign)->findOrFail($session);

        return response()->json(['data' => $this->states->snapshot($session)->toApi()]);
    }

    public function enqueue(EnqueueOverlayRequest $request, string $campaign, string $session): JsonResponse
    {
        $entry = $request->only(['placement', 'content', 'duration_seconds', 'pinned', 'source_type', 'source_id']);

        return $this->respond(function () use ($campaign, $session, $request, $entry): array {
            return $this->states->enqueue($campaign, $session, $request->string('command_id')->toString(), $request->integer('expected_revision'), $entry);
        });
    }

    public function update(UpdateOverlayEntryRequest $request, string $campaign, string $session, string $overlay): JsonResponse
    {
        $patch = $request->only(['placement', 'content', 'duration_seconds', 'pinned']);
        abort_if($patch === [], 422, 'At least one overlay field must be supplied.');

        return $this->respond(function () use ($campaign, $session, $overlay, $request, $patch): array {
            return $this->states->update($campaign, $session, $overlay, $request->string('command_id')->toString(), $request->integer('expected_revision'), $patch);
        });
    }

    public function advance(ControlOverlayActionRequest $request, string $campaign, string $session, string $lane): JsonResponse
    {
        return $this->respond(function () use ($campaign, $session, $lane, $request): array {
            return $this->states->advance($campaign, $session, $lane, $request->string('command_id')->toString(), $request->integer('expected_revision'));
        });
    }

    public function dismiss(ControlOverlayActionRequest $request, string $campaign, string $session, string $lane): JsonResponse
    {
        return $this->respond(function () use ($campaign, $session, $lane, $request): array {
            return $this->states->dismiss($campaign, $session, $lane, $request->string('command_id')->toString(), $request->integer('expected_revision'));
        });
    }

    /** @param callable(): array{0: array<string, mixed>, 1: bool} $operation */
    private function respond(callable $operation): JsonResponse
    {
        try {
            [$response, $replayed] = $operation();
        } catch (StaleOverlayState $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->state->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }
}
