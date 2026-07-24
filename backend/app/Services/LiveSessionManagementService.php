<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LiveSession;
use App\Models\ProcessedCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LiveSessionManagementService
{
    /** @return array{0: array<string, mixed>, 1: bool} */
    public function issuePresentationPairing(string $campaignId, string $sessionId, string $commandId): array
    {
        return DB::transaction(function () use ($campaignId, $sessionId, $commandId): array {
            if ($response = $this->previousResponse($commandId)) {
                return [$response, true];
            }
            /** @var LiveSession $session */
            $session = LiveSession::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($sessionId);
            abort_if($session->archived_at !== null, 422, 'Archived sessions cannot pair a presentation.');

            $token = Str::random(64);
            $session->update(['display_pairing_token_hash' => hash('sha256', $token), 'status' => 'pending']);
            DB::table('presentation_displays')->where('live_session_id', $session->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $session->refresh();
            $response = ['data' => $session->toApi() + ['display_pairing_token' => $token]];

            return [$this->record($commandId, $session, $response), false];
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function rename(string $campaignId, string $sessionId, string $commandId, string $name): array
    {
        return DB::transaction(function () use ($campaignId, $sessionId, $commandId, $name): array {
            if ($response = $this->previousResponse($commandId)) {
                return [$response, true];
            }

            /** @var LiveSession $session */
            $session = LiveSession::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($sessionId);
            $session->name = trim($name);
            $session->save();

            $session->refresh();

            return [$this->record($commandId, $session, ['data' => $session->toApi()]), false];
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function archive(string $campaignId, string $sessionId, string $commandId): array
    {
        return DB::transaction(function () use ($campaignId, $sessionId, $commandId): array {
            if ($response = $this->previousResponse($commandId)) {
                return [$response, true];
            }

            /** @var LiveSession $session */
            $session = LiveSession::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($sessionId);
            $session->status = 'ended';
            $session->archived_at = now()->toDateTimeString();
            $session->save();
            DB::table('presentation_displays')->where('live_session_id', $session->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            DB::table('session_participants')->where('live_session_id', $session->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

            $session->refresh();

            return [$this->record($commandId, $session, ['data' => $session->toApi()]), false];
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function delete(string $campaignId, string $sessionId, string $commandId): array
    {
        return DB::transaction(function () use ($campaignId, $sessionId, $commandId): array {
            if ($response = $this->previousResponse($commandId)) {
                return [$response, true];
            }

            /** @var LiveSession $session */
            $session = LiveSession::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($sessionId);
            $sessionKey = $session->getKey();
            $groupIds = DB::table('session_player_groups')->where('live_session_id', $sessionKey)->pluck('id');
            $messageIds = DB::table('session_messages')->where('live_session_id', $sessionKey)->pluck('id');
            $pollIds = DB::table('session_polls')->where('live_session_id', $sessionKey)->pluck('id');
            $voteIds = DB::table('session_poll_votes')->whereIn('session_poll_id', $pollIds)->pluck('id');

            DB::table('session_poll_vote_options')->whereIn('session_poll_vote_id', $voteIds)->delete();
            DB::table('session_poll_votes')->whereIn('session_poll_id', $pollIds)->delete();
            DB::table('session_poll_recipients')->whereIn('session_poll_id', $pollIds)->delete();
            DB::table('session_poll_options')->whereIn('session_poll_id', $pollIds)->delete();
            DB::table('session_polls')->where('live_session_id', $sessionKey)->delete();
            DB::table('session_message_recipients')->whereIn('session_message_id', $messageIds)->delete();
            DB::table('session_messages')->where('live_session_id', $sessionKey)->delete();
            DB::table('session_player_group_members')->whereIn('session_player_group_id', $groupIds)->delete();
            DB::table('session_player_groups')->where('live_session_id', $sessionKey)->delete();
            DB::table('player_character_claims')->where('live_session_id', $sessionKey)->delete();
            DB::table('session_npc_notes')->where('live_session_id', $sessionKey)->delete();
            DB::table('session_npc_reveals')->where('live_session_id', $sessionKey)->delete();
            DB::table('session_rolls')->where('live_session_id', $sessionKey)->delete();
            DB::table('presentation_displays')->where('live_session_id', $sessionKey)->delete();
            DB::table('presentation_states')->where('live_session_id', $sessionKey)->delete();
            DB::table('overlay_states')->where('live_session_id', $sessionKey)->delete();
            DB::table('player_map_states')->where('live_session_id', $sessionKey)->delete();
            DB::table('map_progresses')->where('live_session_id', $sessionKey)->delete();
            DB::table('session_participants')->where('live_session_id', $sessionKey)->delete();
            DB::table('processed_commands')->where('aggregate_type', 'live_session')->where('aggregate_id', $sessionKey)->delete();
            $session->delete();

            $response = ['data' => ['id' => $sessionKey, 'deleted' => true]];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'campaign', 'aggregate_id' => $campaignId, 'response' => $response]);

            return [$response, false];
        });
    }

    /** @return array<string, mixed>|null */
    private function previousResponse(string $commandId): ?array
    {
        return ProcessedCommand::query()->find($commandId)?->response;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function record(string $commandId, LiveSession $session, array $response): array
    {
        ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'live_session', 'aggregate_id' => $session->getKey(), 'response' => $response]);

        return $response;
    }
}
