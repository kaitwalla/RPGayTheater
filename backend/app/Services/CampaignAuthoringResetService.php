<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CampaignAsset;
use Illuminate\Support\Facades\DB;

class CampaignAuthoringResetService
{
    /** @var list<string> */
    private const TABLES = [
        'session_poll_vote_options', 'session_poll_votes', 'session_poll_recipients', 'session_poll_options', 'session_polls',
        'session_message_recipients', 'session_messages', 'session_player_group_members', 'session_player_groups',
        'player_character_claims', 'session_participants', 'session_npc_notes', 'session_npc_reveals', 'session_rolls',
        'player_map_states', 'map_progresses', 'presentation_displays', 'presentation_states', 'overlay_states',
        'live_sessions', 'map_tokens', 'map_fog_masks', 'campaign_maps', 'stage_preset_entries', 'stage_presets',
        'scene_backdrops', 'scenes', 'npc_states', 'non_player_characters', 'player_characters', 'audio_cues', 'video_cues',
        'dice_presets', 'campaign_asset_collection_items', 'campaign_asset_collections', 'campaign_revisions', 'campaign_assets',
    ];

    public function __construct(private readonly S3MultipartUploadService $storage) {}

    /** @return array{campaigns: int, assets: int, deleted_objects: int, failed_objects: int} */
    public function reset(): array
    {
        $assetKeys = CampaignAsset::query()->whereNotNull('storage_key')->pluck('storage_key')->filter()->values();
        $campaigns = DB::table('campaigns')->count();

        DB::transaction(function (): void {
            foreach (self::TABLES as $table) {
                DB::table($table)->delete();
            }
            DB::table('session_events')->whereNotNull('campaign_id')->delete();
            DB::table('outbox_events')->where('aggregate_type', 'campaign')->delete();
            DB::table('processed_commands')->where('aggregate_type', 'campaign')->delete();
            DB::table('campaigns')->delete();
        });

        $deleted = 0;
        $failed = 0;
        foreach ($assetKeys as $key) {
            try {
                $this->storage->delete($key);
                $deleted++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        return ['campaigns' => $campaigns, 'assets' => $assetKeys->count(), 'deleted_objects' => $deleted, 'failed_objects' => $failed];
    }
}
