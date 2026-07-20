<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OverlayStateService;
use App\Services\PresentationStateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedLoadTestScenario extends Command
{
    private const CAMPAIGN_ID = '00000000-0000-7000-8000-000000000001';

    private const REVISION_ID = '00000000-0000-7000-8000-000000000002';

    private const SESSION_ID = '00000000-0000-7000-8000-000000000003';

    private const MAP_ID = '00000000-0000-7000-8000-000000000004';

    private const PLAYER_CHARACTER_ID = '00000000-0000-7000-8000-000000000007';

    private const PRESENTATION_TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    /** @var string */
    protected $signature = 'load-test:seed';

    /** @var string */
    protected $description = 'Seed the deterministic, non-production fixture used by the 30-participant load test';

    public function handle(): int
    {
        if ($this->laravel->environment('production')) {
            $this->components->error('The load-test fixture cannot be seeded in production.');

            return self::FAILURE;
        }

        DB::transaction(function (): void {
            $timestamp = now();
            DB::table('campaigns')->insertOrIgnore(['id' => self::CAMPAIGN_ID, 'name' => 'Load test campaign', 'draft_revision' => 1, 'created_at' => $timestamp, 'updated_at' => $timestamp]);
            DB::table('campaign_revisions')->insertOrIgnore([
                'id' => self::REVISION_ID,
                'campaign_id' => self::CAMPAIGN_ID,
                'number' => 1,
                'manifest' => json_encode([
                    'schema_version' => 1,
                    'maps' => [['id' => self::MAP_ID, 'name' => 'Load test map']],
                    'player_characters' => [['id' => self::PLAYER_CHARACTER_ID, 'name' => 'Browser fixture character', 'pronouns' => null, 'public_description' => 'A deterministic browser-test character.', 'avatar_asset_id' => null]],
                ], JSON_THROW_ON_ERROR),
                'manifest_hash' => hash('sha256', 'load-test-fixture-v1'),
                'published_at' => $timestamp,
            ]);
            DB::table('live_sessions')->insertOrIgnore([
                'id' => self::SESSION_ID,
                'campaign_id' => self::CAMPAIGN_ID,
                'campaign_revision_id' => self::REVISION_ID,
                'progress_mode' => 'fresh',
                'player_code' => 'LOADTEST',
                'display_pairing_token_hash' => hash('sha256', self::PRESENTATION_TOKEN),
                'status' => 'active',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            DB::table('presentation_states')->insertOrIgnore([
                'id' => '00000000-0000-7000-8000-000000000005',
                'live_session_id' => self::SESSION_ID,
                'revision' => 1,
                'state' => json_encode(PresentationStateService::initialState(), JSON_THROW_ON_ERROR),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            DB::table('overlay_states')->insertOrIgnore([
                'id' => '00000000-0000-7000-8000-000000000006',
                'live_session_id' => self::SESSION_ID,
                'revision' => 1,
                'state' => json_encode(OverlayStateService::initialState(), JSON_THROW_ON_ERROR),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        });

        $this->line(json_encode([
            'campaign_id' => self::CAMPAIGN_ID,
            'session_id' => self::SESSION_ID,
            'map_id' => self::MAP_ID,
            'player_code' => 'LOADTEST',
            'presentation_token' => self::PRESENTATION_TOKEN,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
