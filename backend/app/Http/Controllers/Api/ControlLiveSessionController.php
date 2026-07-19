<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLiveSessionRequest;
use App\Models\LiveSession;
use App\Services\LiveSessionService;
use Illuminate\Http\JsonResponse;

class ControlLiveSessionController extends Controller
{
    public function __construct(private readonly LiveSessionService $sessions) {}

    public function index(string $campaign): JsonResponse
    {
        return response()->json(['data' => LiveSession::query()->where('campaign_id', $campaign)->latest()->get()->map->toApi()->values()]);
    }

    public function store(CreateLiveSessionRequest $request, string $campaign): JsonResponse
    {
        [$response, $replayed] = $this->sessions->create($campaign, $request->string('command_id')->toString(), $request->string('campaign_revision_id')->toString(), $request->string('progress_mode')->toString());

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }
}
