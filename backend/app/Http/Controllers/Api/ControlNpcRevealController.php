<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SetNpcRevealRequest;
use App\Models\LiveSession;
use App\Models\SessionNpcReveal;
use App\Services\NpcRevealService;
use Illuminate\Http\JsonResponse;

class ControlNpcRevealController extends Controller
{
    public function __construct(private readonly NpcRevealService $reveals) {}

    public function index(string $campaign, string $session): JsonResponse
    {
        LiveSession::query()->where('campaign_id', $campaign)->findOrFail($session);

        return response()->json(['data' => SessionNpcReveal::query()->where('live_session_id', $session)->orderBy('npc_id')->get()->map->toApi()->values()]);
    }

    public function update(SetNpcRevealRequest $request, string $campaign, string $session, string $npc): JsonResponse
    {
        [$response, $replayed] = $this->reveals->set($campaign, $session, $request->string('command_id')->toString(), $npc, $request->boolean('is_revealed'));

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }
}
