<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\StaleMapProgress;
use App\Models\CampaignRevision;
use App\Models\LiveSession;
use App\Models\MapProgress;
use App\Models\OutboxEvent;
use App\Models\ProcessedCommand;
use App\Models\SessionEvent;
use App\Models\SessionParticipant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MapProgressService
{
    public function snapshot(LiveSession $session, string $mapId): MapProgress
    {
        $seed = $this->seed($session, $mapId);

        return MapProgress::query()->firstOrCreate(['live_session_id' => $session->id, 'map_id' => $mapId], ['revision' => 1, 'fog' => $seed['fog'], 'tokens' => $seed['tokens']]);
    }

    /**
     * @param  list<array<string, mixed>>  $tokens
     * @return array{0: array<string, mixed>, 1: bool}
     */
    public function update(string $campaignId, string $sessionId, string $mapId, string $commandId, int $expectedRevision, array $tokens): array
    {
        return DB::transaction(function () use ($campaignId, $sessionId, $mapId, $commandId, $expectedRevision, $tokens): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var LiveSession $session */
            $session = LiveSession::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($sessionId);
            $progress = MapProgress::query()->where('live_session_id', $session->id)->where('map_id', $mapId)->lockForUpdate()->first();
            if ($progress === null) {
                $seed = $this->seed($session, $mapId);
                $progress = MapProgress::query()->create(['live_session_id' => $session->id, 'map_id' => $mapId, 'revision' => 1, 'fog' => $seed['fog'], 'tokens' => $seed['tokens']]);
            }
            if ($progress->revision !== $expectedRevision) {
                throw new StaleMapProgress($progress);
            }
            $progress->update(['tokens' => $this->mergeTokens($session, $mapId, $tokens), 'revision' => $progress->revision + 1]);

            return $this->record($campaignId, $session, $progress, $commandId, 'map_progress.updated');
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function reset(string $campaignId, string $sessionId, string $mapId, string $commandId, int $expectedRevision): array
    {
        return DB::transaction(function () use ($campaignId, $sessionId, $mapId, $commandId, $expectedRevision): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var LiveSession $session */
            $session = LiveSession::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($sessionId);
            $progress = MapProgress::query()->where('live_session_id', $session->id)->where('map_id', $mapId)->lockForUpdate()->first();
            if ($progress === null) {
                $seed = $this->seed($session, $mapId);
                $progress = MapProgress::query()->create(['live_session_id' => $session->id, 'map_id' => $mapId, 'revision' => 1, 'fog' => $seed['fog'], 'tokens' => $seed['tokens']]);
            }
            if ($progress->revision !== $expectedRevision) {
                throw new StaleMapProgress($progress);
            }
            $seed = $this->seed($session, $mapId);
            $progress->update(['fog' => $seed['fog'], 'tokens' => $seed['tokens'], 'revision' => $progress->revision + 1]);

            return $this->record($campaignId, $session, $progress, $commandId, 'map_progress.reset');
        });
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function brush(string $campaignId, string $sessionId, string $mapId, string $commandId, int $expectedRevision, string $mode, float $centerX, float $centerY, float $radius): array
    {
        return DB::transaction(function () use ($campaignId, $sessionId, $mapId, $commandId, $expectedRevision, $mode, $centerX, $centerY, $radius): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var LiveSession $session */
            $session = LiveSession::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($sessionId);
            $progress = $this->lockedProgress($session, $mapId);
            if ($progress->revision !== $expectedRevision) {
                throw new StaleMapProgress($progress);
            }
            $fog = $this->normaliseFog($progress->fog);
            abort_if(count($fog['brushes']) >= 5000, 422, 'The fog brush history has reached its limit. Reset the map progress before adding more brushes.');
            $fog['brushes'][] = ['id' => (string) Str::uuid7(), 'mode' => $mode, 'center_x' => $centerX, 'center_y' => $centerY, 'radius' => $radius];
            $progress->update(['fog' => $fog, 'revision' => $progress->revision + 1]);

            return $this->record($campaignId, $session, $progress, $commandId, $mode === 'reveal' ? 'map_progress.fog_revealed' : 'map_progress.fog_hidden');
        });
    }

    /** @return array{map: array<string, mixed>, progress: array<string, mixed>} */
    public function participantSnapshot(SessionParticipant $participant, string $mapId): array
    {
        /** @var LiveSession $session */
        $session = LiveSession::query()->findOrFail($participant->live_session_id);
        $progress = $this->snapshot($session, $mapId);
        /** @var CampaignRevision $revision */
        $revision = CampaignRevision::query()->findOrFail($session->campaign_revision_id);
        $map = $this->index($revision->manifest, 'maps')[$mapId] ?? abort(422, 'The map must belong to the pinned revision.');
        $data = $progress->toApi();
        $fog = $this->normaliseFog($progress->fog);
        $data['fog'] = $fog;
        $data['tokens'] = array_values(array_filter($progress->tokens, fn (array $token): bool => $this->tokenIsVisible($token, $fog)));

        return ['map' => $map, 'progress' => $data];
    }

    /** @return array{0: array<string, mixed>, 1: false} */
    private function record(string $campaignId, LiveSession $session, MapProgress $progress, string $commandId, string $eventType): array
    {
        $progress->refresh();
        $response = ['data' => $progress->toApi()];
        ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'map_progress', 'aggregate_id' => $progress->id, 'response' => $response]);
        SessionEvent::query()->create(['campaign_id' => $campaignId, 'actor_type' => 'control', 'event_type' => $eventType, 'command_id' => $commandId, 'payload' => ['live_session_id' => $session->id, 'map_id' => $progress->map_id, 'revision' => $progress->revision], 'occurred_at' => now()]);
        OutboxEvent::query()->create(['aggregate_type' => 'map_progress', 'aggregate_id' => $progress->id, 'topic' => 'map_progresses.'.$session->id.'.'.$progress->map_id, 'payload' => ['event_type' => $eventType, 'revision' => $progress->revision], 'occurred_at' => now()]);

        return [$response, false];
    }

    /** @return array{fog: array{mask_asset_id: string|null, default_visibility: string, brushes: list<array<string, float|string>>}, tokens: list<array<string, mixed>>} */
    private function seed(LiveSession $session, string $mapId): array
    {
        /** @var CampaignRevision $revision */
        $revision = CampaignRevision::query()->findOrFail($session->campaign_revision_id);
        $manifest = $revision->manifest;
        abort_unless(isset($this->index($manifest, 'maps')[$mapId]), 422, 'The map must belong to the pinned revision.');
        $fog = null;
        foreach ($manifest['map_fog_masks'] ?? [] as $mask) {
            if (is_array($mask) && ($mask['map_id'] ?? null) === $mapId && isset($mask['asset_id']) && is_string($mask['asset_id'])) {
                $fog = $mask['asset_id'];
                break;
            }
        }
        $tokens = [];
        foreach ($manifest['map_tokens'] ?? [] as $token) {
            if (is_array($token) && ($token['map_id'] ?? null) === $mapId && isset($token['id']) && is_string($token['id'])) {
                $tokens[] = ['source_token_id' => $token['id'], 'token_type' => $token['token_type'] ?? null, 'player_character_id' => $token['player_character_id'] ?? null, 'npc_id' => $token['npc_id'] ?? null, 'asset_id' => $token['asset_id'] ?? null, 'label' => $token['label'] ?? null, 'position_x' => (float) ($token['position_x'] ?? 0), 'position_y' => (float) ($token['position_y'] ?? 0), 'scale' => (float) ($token['scale'] ?? 1), 'sort_order' => (int) ($token['sort_order'] ?? 0)];
            }
        }

        return ['fog' => ['mask_asset_id' => $fog, 'default_visibility' => $fog === null ? 'revealed' : 'hidden', 'brushes' => []], 'tokens' => $tokens];
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     * @return list<array<string, mixed>>
     */
    private function mergeTokens(LiveSession $session, string $mapId, array $updates): array
    {
        $seed = $this->seed($session, $mapId)['tokens'];
        $source = [];
        foreach ($seed as $token) {
            $source[$token['source_token_id']] = $token;
        }
        $received = [];
        foreach ($updates as $update) {
            $id = $update['source_token_id'] ?? null;
            if (! is_string($id) || ! isset($source[$id]) || isset($received[$id])) {
                abort(422, 'Map progress may move only tokens authored for the pinned map.');
            }
            $received[$id] = $update;
        }
        abort_unless(array_diff_key($source, $received) === [] && array_diff_key($received, $source) === [], 422, 'Map progress must retain every authored token.');
        foreach ($source as $id => $token) {
            $update = $received[$id];
            $source[$id] = $token + [];
            $source[$id]['position_x'] = (float) $update['position_x'];
            $source[$id]['position_y'] = (float) $update['position_y'];
            $source[$id]['scale'] = (float) $update['scale'];
            $source[$id]['sort_order'] = (int) $update['sort_order'];
        }

        return array_values($source);
    }

    private function lockedProgress(LiveSession $session, string $mapId): MapProgress
    {
        $progress = MapProgress::query()->where('live_session_id', $session->id)->where('map_id', $mapId)->lockForUpdate()->first();
        if ($progress !== null) {
            return $progress;
        }
        $seed = $this->seed($session, $mapId);

        return MapProgress::query()->create(['live_session_id' => $session->id, 'map_id' => $mapId, 'revision' => 1, 'fog' => $seed['fog'], 'tokens' => $seed['tokens']]);
    }

    /**
     * @param  array<string, mixed>  $fog
     * @return array{mask_asset_id: string|null, default_visibility: string, brushes: list<array{id: string, mode: string, center_x: float, center_y: float, radius: float}>}
     */
    private function normaliseFog(array $fog): array
    {
        $maskAssetId = is_string($fog['mask_asset_id'] ?? null) ? $fog['mask_asset_id'] : null;
        $defaultVisibility = ($fog['default_visibility'] ?? null) === 'revealed' ? 'revealed' : ($maskAssetId === null ? 'revealed' : 'hidden');
        $brushes = [];
        foreach ($fog['brushes'] ?? [] as $brush) {
            if (! is_array($brush) || ! is_string($brush['id'] ?? null) || ! in_array($brush['mode'] ?? null, ['reveal', 'hide'], true)) {
                continue;
            }
            if (! is_numeric($brush['center_x'] ?? null) || ! is_numeric($brush['center_y'] ?? null) || ! is_numeric($brush['radius'] ?? null)) {
                continue;
            }
            $brushes[] = ['id' => $brush['id'], 'mode' => $brush['mode'], 'center_x' => (float) $brush['center_x'], 'center_y' => (float) $brush['center_y'], 'radius' => (float) $brush['radius']];
        }

        return ['mask_asset_id' => $maskAssetId, 'default_visibility' => $defaultVisibility, 'brushes' => $brushes];
    }

    /**
     * @param  array<string, mixed>  $token
     * @param  array{default_visibility: string, brushes: list<array{mode: string, center_x: float, center_y: float, radius: float}>}  $fog
     */
    private function tokenIsVisible(array $token, array $fog): bool
    {
        $visible = $fog['default_visibility'] === 'revealed';
        $x = (float) ($token['position_x'] ?? 0);
        $y = (float) ($token['position_y'] ?? 0);
        foreach ($fog['brushes'] as $brush) {
            $xDelta = $x - $brush['center_x'];
            $yDelta = $y - $brush['center_y'];
            if (($xDelta * $xDelta) + ($yDelta * $yDelta) <= $brush['radius'] * $brush['radius']) {
                $visible = $brush['mode'] === 'reveal';
            }
        }

        return $visible;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, array<string, mixed>>
     */
    private function index(array $manifest, string $collection): array
    {
        $indexed = [];
        foreach ($manifest[$collection] ?? [] as $record) {
            if (is_array($record) && isset($record['id']) && is_string($record['id'])) {
                $indexed[$record['id']] = $record;
            }
        }

        return $indexed;
    }
}
