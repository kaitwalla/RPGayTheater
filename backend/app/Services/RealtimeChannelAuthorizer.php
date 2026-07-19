<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PresentationDisplay;
use App\Models\SessionParticipant;
use Illuminate\Http\Request;

class RealtimeChannelAuthorizer
{
    public function controls(Request $request): bool
    {
        return $request->session()->get('control.authenticated') === true;
    }

    public function principal(Request $request): ?RealtimeBroadcastPrincipal
    {
        if ($this->controls($request)) {
            return new RealtimeBroadcastPrincipal('control');
        }
        $displayId = $request->session()->get('presentation.display_id');
        if (is_string($displayId) && PresentationDisplay::query()->whereKey($displayId)->whereNull('revoked_at')->exists()) {
            return new RealtimeBroadcastPrincipal('presentation:'.$displayId);
        }
        $participantId = $request->session()->get('participant.id');
        if (is_string($participantId) && SessionParticipant::query()->whereKey($participantId)->whereNull('revoked_at')->exists()) {
            return new RealtimeBroadcastPrincipal('participant:'.$participantId);
        }

        return null;
    }

    public function presentation(Request $request, string $sessionId): bool
    {
        if ($this->controls($request)) {
            return true;
        }
        $displayId = $request->session()->get('presentation.display_id');

        return is_string($displayId) && PresentationDisplay::query()->whereKey($displayId)->where('live_session_id', $sessionId)->whereNull('revoked_at')->exists();
    }

    public function participant(Request $request, string $sessionId): bool
    {
        if ($this->controls($request)) {
            return true;
        }
        $participantId = $request->session()->get('participant.id');

        return is_string($participantId) && SessionParticipant::query()->whereKey($participantId)->where('live_session_id', $sessionId)->whereNull('revoked_at')->exists();
    }

    public function session(Request $request, string $sessionId): bool
    {
        return $this->presentation($request, $sessionId) || $this->participant($request, $sessionId);
    }
}
