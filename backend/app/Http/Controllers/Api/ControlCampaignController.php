<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\StaleRevision;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCampaignRequest;
use App\Http\Requests\UpdateCampaignRequest;
use App\Models\Campaign;
use App\Services\CampaignCommandService;
use Illuminate\Http\JsonResponse;

class ControlCampaignController extends Controller
{
    public function __construct(private readonly CampaignCommandService $commands) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Campaign::query()->whereNull('archived_at')->orderBy('name')->get()->map->toApi()->values(),
        ]);
    }

    public function store(CreateCampaignRequest $request): JsonResponse
    {
        [$response, $replayed] = $this->commands->create($request->string('command_id')->toString(), $request->string('name')->toString());

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }

    public function update(UpdateCampaignRequest $request, string $campaign): JsonResponse
    {
        try {
            [$response, $replayed] = $this->commands->rename(
                $campaign,
                $request->string('command_id')->toString(),
                $request->integer('expected_revision'),
                $request->string('name')->toString(),
            );
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function destroy(UpdateCampaignRequest $request, string $campaign): JsonResponse
    {
        try {
            [$response, $replayed] = $this->commands->archive(
                $campaign,
                $request->string('command_id')->toString(),
                $request->integer('expected_revision'),
            );
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function publish(UpdateCampaignRequest $request, string $campaign): JsonResponse
    {
        try {
            [$response, $replayed] = $this->commands->publish(
                $campaign,
                $request->string('command_id')->toString(),
                $request->integer('expected_revision'),
            );
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }
}
