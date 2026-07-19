<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveSession;
use App\Models\SessionParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ParticipantSessionController extends Controller
{
    public function join(Request $request): JsonResponse
    {
        $input = $request->validate(['player_code' => ['required', 'string', 'max:12'], 'display_name' => ['required', 'string', 'max:120'], 'role' => ['required', 'in:player,spectator']]);
        $token = Str::random(64);
        $participant = DB::transaction(function () use ($input, $token): SessionParticipant {
            $session = LiveSession::query()->where('player_code', strtoupper($input['player_code']))->where('status', 'active')->lockForUpdate()->firstOrFail();
            $name = trim($input['display_name']);
            abort_if(SessionParticipant::query()->where('live_session_id', $session->id)->where('display_name_normalized', mb_strtolower($name))->exists(), 422, 'That display name is already in use for this session.');

            return SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => $input['role'], 'display_name' => $name, 'display_name_normalized' => mb_strtolower($name), 'resume_token_hash' => hash('sha256', $token)]);
        });
        $request->session()->put('participant.id', $participant->id);
        $request->session()->regenerate();

        return response()->json(['data' => ['id' => $participant->id, 'role' => $participant->role, 'display_name' => $participant->display_name, 'resume_token' => $token]], 201);
    }
}
