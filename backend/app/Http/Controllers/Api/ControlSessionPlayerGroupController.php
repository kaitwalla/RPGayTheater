<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateSessionPlayerGroupRequest;
use App\Http\Requests\SetSessionPlayerGroupMemberRequest;
use App\Models\LiveSession;
use App\Models\SessionPlayerGroup;
use App\Models\SessionPlayerGroupMember;
use App\Services\SessionPlayerGroupService;
use Illuminate\Http\JsonResponse;

class ControlSessionPlayerGroupController extends Controller
{
    public function __construct(private readonly SessionPlayerGroupService $groups) {}

    public function index(string $campaign, string $session): JsonResponse
    {
        LiveSession::query()->where('campaign_id', $campaign)->findOrFail($session);
        $groups = SessionPlayerGroup::query()->where('live_session_id', $session)->orderBy('name')->get(['id', 'name']);
        $membersByGroup = SessionPlayerGroupMember::query()->whereIn('session_player_group_id', $groups->pluck('id'))->orderBy('session_participant_id')->get()->groupBy('session_player_group_id');
        $data = $groups->map(static fn (SessionPlayerGroup $group): array => [
            'id' => $group->id,
            'name' => $group->name,
            'member_participant_ids' => $membersByGroup->get($group->id, collect())->pluck('session_participant_id')->all(),
        ])->all();

        return response()->json(['data' => $data]);
    }

    public function store(CreateSessionPlayerGroupRequest $request, string $campaign, string $session): JsonResponse
    {
        [$response, $replayed] = $this->groups->create($campaign, $session, $request->string('command_id')->toString(), $request->string('name')->toString());

        return response()->json($response + ['meta' => ['replayed' => $replayed]], $replayed ? 200 : 201);
    }

    public function addMember(SetSessionPlayerGroupMemberRequest $request, string $campaign, string $session, string $group, string $participant): JsonResponse
    {
        [$response, $replayed] = $this->groups->addMember($campaign, $session, $group, $participant, $request->string('command_id')->toString());

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }

    public function removeMember(SetSessionPlayerGroupMemberRequest $request, string $campaign, string $session, string $group, string $participant): JsonResponse
    {
        [$response, $replayed] = $this->groups->removeMember($campaign, $session, $group, $participant, $request->string('command_id')->toString());

        return response()->json($response + ['meta' => ['replayed' => $replayed]]);
    }
}
