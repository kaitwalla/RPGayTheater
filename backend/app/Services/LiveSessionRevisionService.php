<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CampaignRevision;
use App\Models\LiveSession;
use App\Models\MapProgress;
use App\Models\OutboxEvent;
use App\Models\PlayerCharacterClaim;
use App\Models\PlayerMapState;
use App\Models\PresentationState;
use App\Models\ProcessedCommand;
use App\Models\SessionEvent;
use Illuminate\Support\Facades\DB;

class LiveSessionRevisionService
{
    /** @var list<string> */
    private const COLLECTIONS = ['assets', 'player_characters', 'npcs', 'npc_states', 'audio_cues', 'stage_presets', 'stage_preset_entries', 'scenes', 'scene_backdrops', 'maps', 'map_fog_masks', 'map_tokens', 'video_cues', 'dice_presets'];

    /** @return array<string, mixed> */
    public function preflight(string $campaignId, string $sessionId, string $revisionId): array
    {
        /** @var LiveSession $session */
        $session = LiveSession::query()->where('campaign_id', $campaignId)->findOrFail($sessionId);
        /** @var CampaignRevision $target */
        $target = CampaignRevision::query()->where('campaign_id', $campaignId)->findOrFail($revisionId);

        return $this->preflightFor($session, $target);
    }

    /** @return array{0: array<string, mixed>, 1: bool} */
    public function adopt(string $campaignId, string $sessionId, string $commandId, string $revisionId): array
    {
        return DB::transaction(function () use ($campaignId, $sessionId, $commandId, $revisionId): array {
            $previous = ProcessedCommand::query()->find($commandId)?->response;
            if (is_array($previous)) {
                return [$previous, true];
            }
            /** @var LiveSession $session */
            $session = LiveSession::query()->where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($sessionId);
            /** @var CampaignRevision $target */
            $target = CampaignRevision::query()->where('campaign_id', $campaignId)->findOrFail($revisionId);
            $preflight = $this->preflightFor($session, $target);
            abort_unless($preflight['compatible'], 422, 'The target revision cannot preserve the session’s live references.');
            $fromRevisionId = $session->campaign_revision_id;
            $session->update(['campaign_revision_id' => $target->id]);
            $session->refresh();
            $response = ['data' => $session->toApi(), 'preflight' => $preflight];
            ProcessedCommand::query()->create(['command_id' => $commandId, 'aggregate_type' => 'live_session', 'aggregate_id' => $session->id, 'response' => $response]);
            SessionEvent::query()->create(['campaign_id' => $campaignId, 'actor_type' => 'control', 'event_type' => 'live_session.revision_adopted', 'command_id' => $commandId, 'payload' => ['live_session_id' => $session->id, 'from_revision_id' => $fromRevisionId, 'to_revision_id' => $target->id], 'occurred_at' => now()]);
            OutboxEvent::query()->create(['aggregate_type' => 'live_session', 'aggregate_id' => $session->id, 'topic' => 'live_sessions.'.$session->id, 'payload' => ['event_type' => 'live_session.revision_adopted', 'from_revision_id' => $fromRevisionId, 'to_revision_id' => $target->id], 'occurred_at' => now()]);

            return [$response, false];
        });
    }

    /** @return array<string, mixed> */
    private function preflightFor(LiveSession $session, CampaignRevision $target): array
    {
        /** @var CampaignRevision $current */
        $current = CampaignRevision::query()->findOrFail($session->campaign_revision_id);
        $changes = [];
        foreach (self::COLLECTIONS as $collection) {
            $changes[$collection] = $this->diff($this->records($current->manifest, $collection), $this->records($target->manifest, $collection));
        }
        $targetCharacters = $this->index($this->records($target->manifest, 'player_characters'));
        $blockers = [];
        foreach (PlayerCharacterClaim::query()->where('live_session_id', $session->id)->pluck('player_character_id') as $playerCharacterId) {
            if (is_string($playerCharacterId) && ! isset($targetCharacters[$playerCharacterId])) {
                $blockers[] = ['type' => 'claimed_player_character_removed', 'player_character_id' => $playerCharacterId];
            }
        }
        $presentation = PresentationState::query()->where('live_session_id', $session->id)->first();
        if ($presentation !== null) {
            $state = $presentation->state;
            $targetNpcs = $this->index($this->records($target->manifest, 'npcs'));
            $targetStates = $this->index($this->records($target->manifest, 'npc_states'));
            foreach ([$state, $state['video_restore_state'] ?? null, $state['standby'] ?? null] as $cue) {
                if (! is_array($cue)) {
                    continue;
                }
                foreach (['scene_id' => 'scenes', 'backdrop_asset_id' => 'assets', 'music_cue_id' => 'audio_cues', 'video_cue_id' => 'video_cues', 'stage_preset_id' => 'stage_presets'] as $field => $collection) {
                    $id = $cue[$field] ?? null;
                    if (is_string($id) && ! isset($this->index($this->records($target->manifest, $collection))[$id])) {
                        $blockers[] = ['type' => 'active_presentation_reference_removed', 'reference_type' => $field, 'reference_id' => $id];
                    }
                }
                foreach ($cue['stage_entries'] ?? [] as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }
                    foreach (['npc_id' => $targetNpcs, 'npc_state_id' => $targetStates] as $field => $records) {
                        $id = $entry[$field] ?? null;
                        if (is_string($id) && ! isset($records[$id])) {
                            $blockers[] = ['type' => 'active_presentation_reference_removed', 'reference_type' => $field, 'reference_id' => $id];
                        }
                    }
                }
            }
        }
        $targetMaps = $this->index($this->records($target->manifest, 'maps'));
        $targetAssets = $this->index($this->records($target->manifest, 'assets'));
        $targetTokens = $this->index($this->records($target->manifest, 'map_tokens'));
        foreach (MapProgress::query()->where('live_session_id', $session->id)->get() as $progress) {
            if (! isset($targetMaps[$progress->map_id])) {
                $blockers[] = ['type' => 'active_map_removed', 'map_id' => $progress->map_id];

                continue;
            }
            $fogAssetId = $progress->fog['mask_asset_id'] ?? null;
            if (is_string($fogAssetId) && ! isset($targetAssets[$fogAssetId])) {
                $blockers[] = ['type' => 'active_map_reference_removed', 'reference_type' => 'fog_mask_asset_id', 'reference_id' => $fogAssetId];
            }
            foreach ($progress->tokens as $token) {
                $sourceTokenId = $token['source_token_id'] ?? null;
                if (is_string($sourceTokenId) && ! isset($targetTokens[$sourceTokenId])) {
                    $blockers[] = ['type' => 'active_map_reference_removed', 'reference_type' => 'source_token_id', 'reference_id' => $sourceTokenId];
                }
            }
        }
        $playerMap = PlayerMapState::query()->where('live_session_id', $session->id)->first();
        if ($playerMap?->map_id !== null && ! isset($targetMaps[$playerMap->map_id])) {
            $blockers[] = ['type' => 'current_player_map_removed', 'map_id' => $playerMap->map_id];
        }

        return ['from_revision_id' => $current->id, 'to_revision_id' => $target->id, 'compatible' => $blockers === [], 'blockers' => $blockers, 'changes' => $changes];
    }

    /**
     * @param  list<array<string, mixed>>  $from
     * @param  list<array<string, mixed>>  $to
     * @return array{added: list<string>, removed: list<string>, changed: list<string>}
     */
    private function diff(array $from, array $to): array
    {
        $from = $this->index($from);
        $to = $this->index($to);
        $added = array_values(array_diff(array_keys($to), array_keys($from)));
        $removed = array_values(array_diff(array_keys($from), array_keys($to)));
        $changed = [];
        foreach (array_intersect(array_keys($from), array_keys($to)) as $id) {
            if (json_encode($from[$id], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) !== json_encode($to[$id], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) {
                $changed[] = $id;
            }
        }

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array<string, mixed>>
     */
    private function records(array $manifest, string $collection): array
    {
        $records = $manifest[$collection] ?? [];
        if (! is_array($records)) {
            return [];
        }
        $result = [];
        foreach ($records as $record) {
            if (is_array($record) && isset($record['id']) && is_string($record['id'])) {
                $result[] = $record;
            }
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, array<string, mixed>>
     */
    private function index(array $records): array
    {
        $indexed = [];
        foreach ($records as $record) {
            $id = $record['id'] ?? null;
            if (is_string($id)) {
                $indexed[$id] = $record;
            }
        }

        return $indexed;
    }
}
