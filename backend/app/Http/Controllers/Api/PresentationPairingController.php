<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveSession;
use App\Models\PresentationDisplay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PresentationPairingController extends Controller
{
    public function pair(Request $request): JsonResponse
    {
        $token = $request->validate(['token' => ['required', 'string', 'size:64']])['token'];
        $credential = Str::random(64);
        $display = DB::transaction(function () use ($token, $credential): PresentationDisplay {
            $session = LiveSession::query()->where('display_pairing_token_hash', hash('sha256', $token))->lockForUpdate()->firstOrFail();
            $session->update(['display_pairing_token_hash' => hash('sha256', Str::random(64)), 'status' => 'active']);

            return PresentationDisplay::query()->create(['live_session_id' => $session->id, 'credential_hash' => hash('sha256', $credential), 'paired_at' => now()]);
        });
        $request->session()->put('presentation.display_id', $display->id);
        $request->session()->regenerate();

        return response()->json(['data' => ['session_id' => $display->live_session_id]]);
    }
}
