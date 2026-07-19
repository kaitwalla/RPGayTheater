<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\StaleRevision;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDicePresetRequest;
use App\Models\DicePreset;
use App\Services\DicePresetService;
use Illuminate\Http\JsonResponse;

class ControlDicePresetController extends Controller
{
    public function __construct(private readonly DicePresetService $dice) {}

    public function index(string $campaign): JsonResponse
    {
        return response()->json(['data' => DicePreset::query()->where('campaign_id', $campaign)->orderBy('sort_order')->get()]);
    }

    public function store(CreateDicePresetRequest $request, string $campaign): JsonResponse
    {
        try {
            [$response, $replayed] = $this->dice->create($campaign, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->string('name')->toString(), $request->string('expression')->toString(), $request->string('default_visibility')->toString(), $request->boolean('is_default'));
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }
}
