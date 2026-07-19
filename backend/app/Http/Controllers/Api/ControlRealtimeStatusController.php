<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OutboxEvent;
use Illuminate\Http\JsonResponse;

class ControlRealtimeStatusController extends Controller
{
    public function show(): JsonResponse
    {
        $pending = OutboxEvent::query()->whereNull('dispatched_at');
        $latest = OutboxEvent::query()->latest('last_attempted_at')->first();

        return response()->json(['data' => [
            'pending_count' => (clone $pending)->count(),
            'failed_count' => (clone $pending)->whereNotNull('last_error')->count(),
            'latest_attempted_at' => $latest?->last_attempted_at?->toAtomString(),
            'latest_error' => $latest?->last_error,
        ]]);
    }
}
