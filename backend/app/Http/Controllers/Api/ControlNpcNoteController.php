<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNpcNoteRequest;
use App\Models\LiveSession;
use App\Models\SessionNpcNote;
use App\Services\SessionNpcNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ControlNpcNoteController extends Controller
{
    public function __construct(private readonly SessionNpcNoteService $notes) {}

    public function index(string $campaign, string $session): JsonResponse
    {
        LiveSession::query()->where('campaign_id', $campaign)->findOrFail($session);

        return response()->json(['data' => SessionNpcNote::query()->where('live_session_id', $session)->orderBy('created_at')->get()->map->toApi()->values()]);
    }

    public function update(UpdateNpcNoteRequest $request, string $campaign, string $session, string $note): JsonResponse
    {
        [$response, $replayed] = $this->notes->moderate($campaign, $session, $request->string('command_id')->toString(), $note, $request->string('body')->toString(), false);

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function destroy(Request $request, string $campaign, string $session, string $note): JsonResponse
    {
        $input = $request->validate(['command_id' => ['required', 'uuid']]);
        [$response, $replayed] = $this->notes->moderate($campaign, $session, $input['command_id'], $note, null, true);

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }
}
