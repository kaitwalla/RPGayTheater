<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\StaleRevision;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateStagePresetEntryRequest;
use App\Http\Requests\CreateStagePresetRequest;
use App\Models\StagePreset;
use App\Models\StagePresetEntry;
use App\Services\StagePresetService;
use Illuminate\Http\JsonResponse;

class ControlStagePresetController extends Controller
{
    public function __construct(private readonly StagePresetService $presets) {}

    public function index(string $campaign): JsonResponse
    {
        return response()->json(['data' => StagePreset::query()->where('campaign_id', $campaign)->orderBy('name')->get()]);
    }

    public function store(CreateStagePresetRequest $request, string $campaign): JsonResponse
    {
        try {
            [$response, $replayed] = $this->presets->create($campaign, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->string('name')->toString(), $request->integer('tween_duration_ms'), $request->string('tween_easing')->toString());
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }

    public function entries(string $campaign, string $stagePreset): JsonResponse
    {
        abort_unless(StagePreset::query()->whereKey($stagePreset)->where('campaign_id', $campaign)->exists(), 404);

        return response()->json(['data' => StagePresetEntry::query()->where('stage_preset_id', $stagePreset)->orderBy('layer_order')->get()]);
    }

    public function storeEntry(CreateStagePresetEntryRequest $request, string $campaign, string $stagePreset): JsonResponse
    {
        try {
            [$response, $replayed] = $this->presets->createEntry($campaign, $stagePreset, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->string('npc_id')->toString(), $request->input('npc_state_id'), (float) $request->input('position_x'), (float) $request->input('position_y'), (float) $request->input('scale'), $request->integer('layer_order'), $request->string('facing')->toString());
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }
}
