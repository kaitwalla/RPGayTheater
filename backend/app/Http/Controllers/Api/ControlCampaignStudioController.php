<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\StaleRevision;
use App\Exceptions\StudioRecordInUse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StudioMutationRequest;
use App\Models\Campaign;
use App\Services\CampaignStudioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ControlCampaignStudioController extends Controller
{
    public function __construct(private readonly CampaignStudioService $studio) {}

    public function show(string $campaign): JsonResponse
    {
        /** @var Campaign $campaign */
        $campaign = Campaign::query()->whereNull('archived_at')->findOrFail($campaign);

        return response()->json(['data' => $this->studio->snapshot($campaign)]);
    }

    public function update(StudioMutationRequest $request, string $campaign, string $resource, string $record): JsonResponse
    {
        try {
            [$response, $replayed] = $this->studio->update($campaign, $resource, $record, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->array('patch'));
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function reorder(StudioMutationRequest $request, string $campaign, string $resource): JsonResponse
    {
        try {
            /** @var list<string> $ids */
            $ids = $request->array('ids');
            [$response, $replayed] = $this->studio->reorder($campaign, $resource, $request->string('command_id')->toString(), $request->integer('expected_revision'), $ids);
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function destroy(StudioMutationRequest $request, string $campaign, string $resource, string $record): JsonResponse
    {
        try {
            [$response, $replayed] = $this->studio->destroy($campaign, $resource, $record, $request->string('command_id')->toString(), $request->integer('expected_revision'));
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        } catch (StudioRecordInUse $exception) {
            return response()->json(['message' => $exception->getMessage(), 'usages' => $exception->usages], 422);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function storeCollection(Request $request, string $campaign): JsonResponse
    {
        $data = $request->validate(['command_id' => ['required', 'uuid'], 'expected_revision' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:120']]);
        try {
            [$response, $replayed] = $this->studio->createCollection($campaign, $data['command_id'], $data['expected_revision'], $data['name']);
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }
}
