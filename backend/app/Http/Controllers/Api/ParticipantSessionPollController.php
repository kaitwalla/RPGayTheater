<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VoteSessionPollRequest;
use App\Services\SessionPollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParticipantSessionPollController extends Controller
{
    public function __construct(private readonly SessionPollService $polls) {}

    public function index(Request $request): JsonResponse
    {
        $id = $request->session()->get('participant.id');
        abort_unless(is_string($id), 401, 'Participant authentication is required.');

        return response()->json(['data' => $this->polls->participantPolls($id)->map(fn ($poll): array => $this->polls->toApi($poll, $id, $poll->result_visibility !== 'none'))->values()]);
    }

    public function vote(VoteSessionPollRequest $request, string $poll): JsonResponse
    {
        $id = $request->session()->get('participant.id');
        abort_unless(is_string($id), 401, 'Participant authentication is required.');
        [$response,$replayed] = $this->polls->vote($id, $poll, $request->string('command_id')->toString(), $request->input('option_ids'));

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }
}
