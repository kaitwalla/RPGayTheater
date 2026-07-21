<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\StaleRevision;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateAudioCueRequest;
use App\Models\AudioCue;
use App\Services\AudioCueService;
use Illuminate\Http\JsonResponse;

class ControlAudioCueController extends Controller
{
    public function __construct(private readonly AudioCueService $cues) {}

    public function index(string $campaign): JsonResponse
    {
        return response()->json(['data' => AudioCue::query()->where('campaign_id', $campaign)->orderBy('sort_order')->get()]);
    }

    public function store(CreateAudioCueRequest $request, string $campaign): JsonResponse
    {
        try {
            [$response, $replayed] = $this->cues->create($campaign, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->string('name')->toString(), $request->string('asset_id')->toString(), $request->input('scene_id'), $request->string('kind')->toString(), $request->boolean('loop'), $request->integer('default_volume', 100));
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }
}
