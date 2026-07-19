<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\StaleRevision;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateVideoCueRequest;
use App\Models\VideoCue;
use App\Services\VideoCueService;
use Illuminate\Http\JsonResponse;

class ControlVideoCueController extends Controller
{
    public function __construct(private readonly VideoCueService $videos) {}

    public function index(string $campaign): JsonResponse
    {
        return response()->json(['data' => VideoCue::query()->where('campaign_id', $campaign)->orderBy('sort_order')->get()]);
    }

    public function store(CreateVideoCueRequest $request, string $campaign): JsonResponse
    {
        try {
            [$response, $replayed] = $this->videos->create($campaign, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->string('name')->toString(), $request->string('primary_asset_id')->toString(), $request->input('fallback_asset_id'), $request->string('completion_mode')->toString(), $request->input('target_scene_id'), $request->string('music_during')->toString(), $request->string('music_after')->toString(), $request->integer('embedded_audio_volume'), $request->boolean('embedded_audio_muted'));
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }
}
