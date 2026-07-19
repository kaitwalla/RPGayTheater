<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CampaignRevision;
use App\Models\LiveSession;
use App\Models\PresentationState;
use App\Models\ProcessedCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LiveSessionService
{
    /** @return array{0: array<string, mixed>, 1: bool} */
    public function create(string $campaignId, string $commandId, string $revisionId, string $progressMode): array
    {
        return DB::transaction(function () use ($campaignId, $commandId, $revisionId, $progressMode): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            abort_unless(CampaignRevision::query()->whereKey($revisionId)->where('campaign_id', $campaignId)->exists(), 422, 'A live session must be pinned to a published revision from this campaign.');
            $token = Str::random(64);
            $session = LiveSession::query()->create(['campaign_id' => $campaignId, 'campaign_revision_id' => $revisionId, 'progress_mode' => $progressMode, 'player_code' => $this->playerCode(), 'display_pairing_token_hash' => hash('sha256', $token)]);
            PresentationState::query()->create(['live_session_id' => $session->id, 'revision' => 1, 'state' => PresentationStateService::initialState()]);
            $response = ['data' => $session->toApi() + ['display_pairing_token' => $token]];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'live_session', 'aggregate_id' => $session->getKey(), 'response' => $response]);

            return [$response, false];
        });
    }

    private function playerCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (LiveSession::query()->where('player_code', $code)->exists());

        return $code;
    }
}
