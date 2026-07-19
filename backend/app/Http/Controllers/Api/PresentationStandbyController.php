<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\StalePresentationState;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReportPresentationStandbyRequest;
use App\Models\LiveSession;
use App\Models\PresentationDisplay;
use App\Services\PresentationStateService;
use Illuminate\Http\JsonResponse;

class PresentationStandbyController extends Controller
{
    public function __construct(private readonly PresentationStateService $states) {}

    public function report(ReportPresentationStandbyRequest $request): JsonResponse
    {
        $displayId = $request->session()->get('presentation.display_id');
        abort_unless(is_string($displayId), 401, 'Presentation authentication is required.');
        /** @var PresentationDisplay $display */
        $display = PresentationDisplay::query()->whereNull('revoked_at')->findOrFail($displayId);
        /** @var LiveSession $session */
        $session = LiveSession::query()->findOrFail($display->live_session_id);
        try {
            [$response, $replayed] = $this->states->report($session, $request->string('command_id')->toString(), $request->integer('expected_revision'), $request->string('status')->toString(), $request->input('error'));
        } catch (StalePresentationState $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $exception->state->toApi()], 409);
        }

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }
}
