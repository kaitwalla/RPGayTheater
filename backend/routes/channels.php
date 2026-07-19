<?php

declare(strict_types=1);

use App\Services\RealtimeChannelAuthorizer;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('control.campaigns', function (mixed $user): bool {
    return app(RealtimeChannelAuthorizer::class)->controls(request());
});
Broadcast::channel('live_sessions.{sessionId}', function (mixed $user, string $sessionId): bool {
    return app(RealtimeChannelAuthorizer::class)->session(request(), $sessionId);
});
Broadcast::channel('presentation_states.{sessionId}', function (mixed $user, string $sessionId): bool {
    return app(RealtimeChannelAuthorizer::class)->presentation(request(), $sessionId);
});
Broadcast::channel('overlay_states.{sessionId}', function (mixed $user, string $sessionId): bool {
    return app(RealtimeChannelAuthorizer::class)->presentation(request(), $sessionId);
});
Broadcast::channel('map_progresses.{sessionId}.{mapId}', function (mixed $user, string $sessionId): bool {
    return app(RealtimeChannelAuthorizer::class)->participant(request(), $sessionId);
});
Broadcast::channel('player_map_states.{sessionId}', function (mixed $user, string $sessionId): bool {
    return app(RealtimeChannelAuthorizer::class)->participant(request(), $sessionId);
});
