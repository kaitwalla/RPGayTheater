<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\StaleRevision;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateNpcRequest;
use App\Http\Requests\CreateNpcStateRequest;
use App\Models\NonPlayerCharacter;
use App\Models\NpcState;
use App\Services\NpcService;
use Illuminate\Http\JsonResponse;

class ControlNpcController extends Controller
{
    public function __construct(private readonly NpcService $npcs) {}

    public function index(string $campaign): JsonResponse
    {
        return response()->json(['data' => NonPlayerCharacter::query()->where('campaign_id', $campaign)->orderBy('name')->get(['id', 'campaign_id', 'normal_asset_id', 'name', 'pronouns', 'public_description', 'native_facing'])]);
    }

    public function store(CreateNpcRequest $request, string $campaign): JsonResponse
    {
        try {
            [$response, $replayed] = $this->npcs->create($campaign, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->string('name')->toString(), $request->string('normal_asset_id')->toString(), $request->input('pronouns'), $request->input('public_description'));
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }

    public function states(string $campaign, string $npc): JsonResponse
    {
        return response()->json(['data' => NpcState::query()->where('npc_id', $npc)->get(['id', 'npc_id', 'asset_id', 'name', 'sort_order'])]);
    }

    public function storeState(CreateNpcStateRequest $request, string $campaign, string $npc): JsonResponse
    {
        try {
            [$response, $replayed] = $this->npcs->createState($campaign, $npc, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->string('name')->toString(), $request->string('asset_id')->toString());
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }
}
