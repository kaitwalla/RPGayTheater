<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\StaleRevision;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePlayerCharacterRequest;
use App\Models\PlayerCharacter;
use App\Services\PlayerCharacterService;
use Illuminate\Http\JsonResponse;

class ControlPlayerCharacterController extends Controller
{
    public function __construct(private readonly PlayerCharacterService $characters) {}

    public function index(string $campaign): JsonResponse
    {
        return response()->json(['data' => PlayerCharacter::query()->where('campaign_id', $campaign)->orderBy('sort_order')->get()->map->toApi()->values()]);
    }

    public function store(CreatePlayerCharacterRequest $request, string $campaign): JsonResponse
    {
        try {
            [$response, $replayed] = $this->characters->create($campaign, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->string('name')->toString(), $request->input('pronouns'), $request->input('public_description'), $request->input('avatar_asset_id'));
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }
}
