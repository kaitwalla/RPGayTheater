<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\StaleRevision;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateNpcRequest;
use App\Models\NonPlayerCharacter;
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
            [$response, $replayed] = $this->npcs->create($campaign, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->string('name')->toString(), $request->string('normal_asset_id')->toString(), $request->input('pronouns'), $request->input('public_description'), $request->string('native_facing')->toString());
        } catch (StaleRevision $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->campaign->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }
}
