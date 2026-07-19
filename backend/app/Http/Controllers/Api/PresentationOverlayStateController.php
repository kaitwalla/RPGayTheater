<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveSession;
use App\Models\PresentationDisplay;
use App\Services\OverlayStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PresentationOverlayStateController extends Controller
{
    public function __construct(private readonly OverlayStateService $states) {}

    public function show(Request $request): JsonResponse
    {
        $displayId = $request->session()->get('presentation.display_id');
        abort_unless(is_string($displayId), 401, 'Presentation authentication is required.');
        /** @var PresentationDisplay $display */
        $display = PresentationDisplay::query()->whereNull('revoked_at')->findOrFail($displayId);
        /** @var LiveSession $session */
        $session = LiveSession::query()->findOrFail($display->live_session_id);

        return response()->json(['data' => $this->states->snapshot($session)->toApi()]);
    }
}
