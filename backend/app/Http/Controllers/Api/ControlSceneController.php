<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\StaleRevision;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateSceneRequest;
use App\Models\Scene;
use App\Services\SceneService;
use Illuminate\Http\JsonResponse;

class ControlSceneController extends Controller
{
    public function __construct(private readonly SceneService $scenes) {}

    public function index(string $campaign): JsonResponse
    {
        return response()->json(['data' => Scene::query()->where('campaign_id', $campaign)->orderBy('sort_order')->get()]);
    }

    public function store(CreateSceneRequest $request, string $campaign): JsonResponse
    {
        try {
            [$response, $replayed] = $this->scenes->create($campaign, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->string('name')->toString(), $request->input('primary_backdrop_asset_id'), $request->input('default_music_cue_id'), $request->string('transition')->toString(), $request->integer('transition_duration_ms'));
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }
}
