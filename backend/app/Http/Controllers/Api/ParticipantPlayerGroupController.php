<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SessionParticipant;
use App\Models\SessionPlayerGroup;
use App\Models\SessionPlayerGroupMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParticipantPlayerGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $participantId = $request->session()->get('participant.id');
        abort_unless(is_string($participantId), 401, 'Participant authentication is required.');
        /** @var SessionParticipant $participant */
        $participant = SessionParticipant::query()->findOrFail($participantId);
        abort_if($participant->revoked_at !== null, 403, 'This participant has been revoked.');
        if ($participant->role !== 'player') {
            return response()->json(['data' => []]);
        }
        $groupIds = SessionPlayerGroupMember::query()->where('session_participant_id', $participant->id)->pluck('session_player_group_id');
        $groups = SessionPlayerGroup::query()
            ->where('live_session_id', $participant->live_session_id)
            ->whereIn('id', $groupIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (SessionPlayerGroup $group): array => ['id' => $group->id, 'name' => $group->name])
            ->values();

        return response()->json(['data' => $groups]);
    }
}
