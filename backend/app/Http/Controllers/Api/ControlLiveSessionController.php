<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdoptLiveSessionRevisionRequest;
use App\Http\Requests\CreateLiveSessionRequest;
use App\Http\Requests\IssuePresentationPairingRequest;
use App\Http\Requests\UpdateLiveSessionRequest;
use App\Models\LiveSession;
use App\Services\LiveSessionManagementService;
use App\Services\LiveSessionRevisionService;
use App\Services\LiveSessionService;
use Illuminate\Http\JsonResponse;

class ControlLiveSessionController extends Controller
{
    public function __construct(
        private readonly LiveSessionService $sessions,
        private readonly LiveSessionRevisionService $revisions,
        private readonly LiveSessionManagementService $management,
    ) {}

    public function index(string $campaign): JsonResponse
    {
        return response()->json(['data' => LiveSession::query()->where('campaign_id', $campaign)->latest()->get()->map->toApi()->values()]);
    }

    public function store(CreateLiveSessionRequest $request, string $campaign): JsonResponse
    {
        $copyPlayerGroups = $request->has('copy_player_groups') ? $request->boolean('copy_player_groups') : null;
        [$response, $replayed] = $this->sessions->create($campaign, $request->string('command_id')->toString(), $request->string('campaign_revision_id')->toString(), $request->string('progress_mode')->toString(), $copyPlayerGroups, $request->string('name')->toString() ?: null);

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }

    public function update(UpdateLiveSessionRequest $request, string $campaign, string $session): JsonResponse
    {
        [$response, $replayed] = $this->management->rename($campaign, $session, $request->string('command_id')->toString(), $request->string('name')->toString());

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function archive(UpdateLiveSessionRequest $request, string $campaign, string $session): JsonResponse
    {
        [$response, $replayed] = $this->management->archive($campaign, $session, $request->string('command_id')->toString());

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function destroy(UpdateLiveSessionRequest $request, string $campaign, string $session): JsonResponse
    {
        [$response, $replayed] = $this->management->delete($campaign, $session, $request->string('command_id')->toString());

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function issuePresentationPairing(IssuePresentationPairingRequest $request, string $campaign, string $session): JsonResponse
    {
        [$response, $replayed] = $this->management->issuePresentationPairing($campaign, $session, $request->string('command_id')->toString());

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function preflight(string $campaign, string $session, string $revision): JsonResponse
    {
        return response()->json(['data' => $this->revisions->preflight($campaign, $session, $revision)]);
    }

    public function adopt(AdoptLiveSessionRevisionRequest $request, string $campaign, string $session): JsonResponse
    {
        [$response, $replayed] = $this->revisions->adopt($campaign, $session, $request->string('command_id')->toString(), $request->string('campaign_revision_id')->toString());

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }
}
