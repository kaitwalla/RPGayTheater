<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\StaleRevision;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCampaignMapRequest;
use App\Http\Requests\CreateMapTokenRequest;
use App\Http\Requests\SetMapFogMaskRequest;
use App\Models\CampaignMap;
use App\Models\MapFogMask;
use App\Models\MapToken;
use App\Services\CampaignMapService;
use Illuminate\Http\JsonResponse;

class ControlCampaignMapController extends Controller
{
    public function __construct(private readonly CampaignMapService $maps) {}

    public function index(string $campaign): JsonResponse
    {
        return response()->json(['data' => CampaignMap::query()->where('campaign_id', $campaign)->orderBy('sort_order')->get()]);
    }

    public function store(CreateCampaignMapRequest $request, string $campaign): JsonResponse
    {
        try {
            [$response, $replayed] = $this->maps->create($campaign, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->string('name')->toString(), $request->string('image_asset_id')->toString());
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }

    public function fogMask(string $campaign, string $map): JsonResponse
    {
        abort_unless(CampaignMap::query()->whereKey($map)->where('campaign_id', $campaign)->exists(), 404);

        return response()->json(['data' => MapFogMask::query()->where('map_id', $map)->first()]);
    }

    public function setFogMask(SetMapFogMaskRequest $request, string $campaign, string $map): JsonResponse
    {
        try {
            [$response, $replayed] = $this->maps->setFogMask($campaign, $map, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->string('asset_id')->toString());
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }

    public function tokens(string $campaign, string $map): JsonResponse
    {
        abort_unless(CampaignMap::query()->whereKey($map)->where('campaign_id', $campaign)->exists(), 404);

        return response()->json(['data' => MapToken::query()->where('map_id', $map)->orderBy('sort_order')->get()]);
    }

    public function storeToken(CreateMapTokenRequest $request, string $campaign, string $map): JsonResponse
    {
        try {
            [$response, $replayed] = $this->maps->createToken($campaign, $map, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->string('token_type')->toString(), $request->input('player_character_id'), $request->input('npc_id'), $request->input('asset_id'), $request->input('label'), (float) $request->input('position_x'), (float) $request->input('position_y'), (float) $request->input('scale'));
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }
}
