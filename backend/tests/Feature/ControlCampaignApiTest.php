<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AudioCue;
use App\Models\Campaign;
use App\Models\CampaignAsset;
use App\Models\CampaignMap;
use App\Models\CampaignRevision;
use App\Models\DicePreset;
use App\Models\LiveSession;
use App\Models\MapFogMask;
use App\Models\MapToken;
use App\Models\NonPlayerCharacter;
use App\Models\NpcState;
use App\Models\PlayerCharacter;
use App\Models\PlayerCharacterClaim;
use App\Models\PresentationDisplay;
use App\Models\Scene;
use App\Models\SceneBackdrop;
use App\Models\SessionParticipant;
use App\Models\StagePreset;
use App\Models\StagePresetEntry;
use App\Models\VideoCue;
use App\Services\CampaignManifestService;
use App\Services\CampaignPackageService;
use App\Services\S3MultipartUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ControlCampaignApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('control.secret', 'correct-horse-battery-staple-for-tests');
    }

    public function test_control_secret_authenticates_a_session(): void
    {
        $this->postJson('/api/control/v1/auth/login', ['secret' => 'wrong'])
            ->assertUnprocessable();

        $this->postJson('/api/control/v1/auth/login', ['secret' => 'correct-horse-battery-staple-for-tests'])
            ->assertOk()
            ->assertJsonPath('data.authenticated', true);

        $this->getJson('/api/control/v1/campaigns')->assertOk();
    }

    public function test_campaign_creation_is_idempotent_and_records_an_auditable_outbox_event(): void
    {
        $this->authenticateControl();
        $commandId = (string) Str::uuid7();
        $payload = ['command_id' => $commandId, 'name' => 'The Moth Court'];

        $this->postJson('/api/control/v1/campaigns', $payload)
            ->assertCreated()
            ->assertJsonPath('data.name', 'The Moth Court')
            ->assertJsonPath('data.draft_revision', 1)
            ->assertJsonPath('meta.replayed', false);

        $this->postJson('/api/control/v1/campaigns', $payload)
            ->assertOk()
            ->assertJsonPath('meta.replayed', true);

        $this->assertDatabaseCount('campaigns', 1);
        $this->assertDatabaseCount('processed_commands', 1);
        $this->assertDatabaseCount('session_events', 1);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertDatabaseHas('processed_commands', ['command_id' => $commandId]);
    }

    public function test_stale_campaign_write_returns_the_current_revision_without_overwriting_it(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'Before']);

        $this->patchJson("/api/control/v1/campaigns/{$campaign->id}", [
            'command_id' => (string) Str::uuid7(),
            'expected_revision' => 1,
            'name' => 'After',
        ])->assertOk()->assertJsonPath('data.draft_revision', 2);

        $this->patchJson("/api/control/v1/campaigns/{$campaign->id}", [
            'command_id' => (string) Str::uuid7(),
            'expected_revision' => 1,
            'name' => 'Lost update',
        ])->assertConflict()
            ->assertJsonPath('data.name', 'After')
            ->assertJsonPath('data.draft_revision', 2);

        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'name' => 'After', 'draft_revision' => 2]);
    }

    public function test_unauthenticated_requests_cannot_access_campaigns(): void
    {
        $this->getJson('/api/control/v1/campaigns')->assertUnauthorized();
    }

    public function test_publishing_creates_an_immutable_manifest_for_the_current_campaign_revision(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Glass Archive']);
        $packageBytes = 'packaged-asset';
        $packageHash = hash('sha256', $packageBytes);
        $avatar = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'hero.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'validated_mime' => 'image/png', 'byte_size' => strlen($packageBytes), 'sha256' => $packageHash, 'storage_key' => 'assets/sha256/'.$packageHash, 'upload_status' => CampaignAsset::STATUS_READY]);
        PlayerCharacter::query()->create(['campaign_id' => $campaign->id, 'avatar_asset_id' => $avatar->id, 'name' => 'Ari']);
        $commandId = (string) Str::uuid7();

        $response = $this->postJson("/api/control/v1/campaigns/{$campaign->id}/publish", [
            'command_id' => $commandId,
            'expected_revision' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.number', 1)
            ->assertJsonPath('meta.replayed', false);

        $revision = CampaignRevision::query()->findOrFail($response->json('data.id'));
        $this->assertSame('The Glass Archive', $revision->manifest['campaign']['name']);
        $this->assertCount(1, $revision->manifest['assets']);
        $this->assertSame('Ari', $revision->manifest['player_characters'][0]['name']);
        $this->assertSame(hash('sha256', json_encode($revision->manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $revision->manifest_hash);

        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/publish", [
            'command_id' => $commandId,
            'expected_revision' => 1,
        ])->assertOk()->assertJsonPath('meta.replayed', true);

        $this->assertDatabaseCount('campaign_revisions', 1);
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/revisions")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/revisions/{$revision->id}")
            ->assertOk()->assertJsonPath('data.manifest.player_characters.0.name', 'Ari');
        $storage = Mockery::mock(S3MultipartUploadService::class);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $packageBytes);
        rewind($stream);
        $storage->shouldReceive('read')->once()->with($avatar->storage_key)->andReturn($stream);
        $this->app->instance(S3MultipartUploadService::class, $storage);
        $this->get("/api/control/v1/campaigns/{$campaign->id}/revisions/{$revision->id}/package")
            ->assertOk()->assertDownload("campaign-{$campaign->id}-revision-1.zip");
    }

    public function test_control_can_import_a_revision_package_as_a_new_draft_with_remapped_assets(): void
    {
        $this->authenticateControl();
        $source = Campaign::query()->create(['name' => 'The Imported Archive']);
        $bytes = 'packaged-asset';
        $asset = CampaignAsset::query()->create(['campaign_id' => $source->id, 'original_filename' => 'hero.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'validated_mime' => 'image/png', 'byte_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'storage_key' => 'assets/source-hero', 'upload_status' => CampaignAsset::STATUS_READY]);
        $player = PlayerCharacter::query()->create(['campaign_id' => $source->id, 'avatar_asset_id' => $asset->id, 'name' => 'Ari']);
        $manifest = $this->app->make(CampaignManifestService::class)->build($source);
        $revision = CampaignRevision::query()->create(['campaign_id' => $source->id, 'number' => 1, 'manifest' => $manifest, 'manifest_hash' => hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 'published_at' => now()]);
        $storage = Mockery::mock(S3MultipartUploadService::class);
        $sourceStream = fopen('php://temp', 'w+b');
        fwrite($sourceStream, $bytes);
        rewind($sourceStream);
        $storage->shouldReceive('read')->once()->with($asset->storage_key)->andReturn($sourceStream);
        $storage->shouldReceive('put')->once()->withArgs(static fn (string $key, mixed $contents, string $mime): bool => str_starts_with($key, 'assets/sha256/'.hash('sha256', 'packaged-asset').'/') && is_resource($contents) && $mime === 'image/png');
        $this->app->instance(S3MultipartUploadService::class, $storage);
        $package = $this->app->make(CampaignPackageService::class)->export($revision);
        $contents = file_get_contents($package['path']);
        unlink($package['path']);
        self::assertIsString($contents);
        $commandId = (string) Str::uuid7();

        $response = $this->post('/api/control/v1/campaigns/import', ['command_id' => $commandId, 'package' => UploadedFile::fake()->createWithContent('campaign.zip', $contents)])
            ->assertCreated()->assertJsonPath('data.name', $source->name)->assertJsonPath('meta.replayed', false)->json('data');
        self::assertNotSame($source->id, $response['id']);
        $importedAsset = CampaignAsset::query()->where('campaign_id', $response['id'])->sole();
        $importedPlayer = PlayerCharacter::query()->where('campaign_id', $response['id'])->sole();
        self::assertNotSame($asset->id, $importedAsset->id);
        self::assertNotSame($player->id, $importedPlayer->id);
        self::assertSame($importedAsset->id, $importedPlayer->avatar_asset_id);
        self::assertSame(CampaignAsset::STATUS_READY, $importedAsset->upload_status);

        $this->post('/api/control/v1/campaigns/import', ['command_id' => $commandId, 'package' => UploadedFile::fake()->createWithContent('campaign.zip', $contents)])
            ->assertOk()->assertJsonPath('data.id', $response['id'])->assertJsonPath('meta.replayed', true);
        $this->assertDatabaseCount('campaigns', 2);
    }

    public function test_campaign_package_import_rejects_an_invalid_archive_without_creating_a_draft(): void
    {
        $this->authenticateControl();
        $storage = Mockery::mock(S3MultipartUploadService::class);
        $storage->shouldNotReceive('put');
        $this->app->instance(S3MultipartUploadService::class, $storage);

        $this->post('/api/control/v1/campaigns/import', ['command_id' => (string) Str::uuid7(), 'package' => UploadedFile::fake()->createWithContent('campaign.zip', 'not an archive')])
            ->assertUnprocessable();
        $this->assertDatabaseCount('campaigns', 0);
    }

    public function test_campaign_package_round_trip_remaps_the_full_authored_graph(): void
    {
        $this->authenticateControl();
        $source = Campaign::query()->create(['name' => 'The Complete Package Archive']);
        $media = [];
        $asset = function (string $name, string $kind, string $mime, ?string $contents = null) use ($source, &$media): CampaignAsset {
            $bytes = $contents ?? "package-{$name}";
            $record = CampaignAsset::query()->create(['campaign_id' => $source->id, 'original_filename' => "{$name}.bin", 'kind' => $kind, 'declared_mime' => $mime, 'validated_mime' => $mime, 'byte_size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'storage_key' => "assets/source-{$name}", 'upload_status' => CampaignAsset::STATUS_READY]);
            $media[$record->storage_key] = $bytes;

            return $record;
        };
        $pcAvatar = $asset('pc-avatar', 'image', 'image/png');
        $npcImage = $asset('npc', 'image', 'image/png');
        $stateImage = $asset('state', 'image', 'image/png');
        $backdrop = $asset('backdrop', 'image', 'image/png');
        $alternate = $asset('alternate', 'image', 'image/png', 'package-backdrop');
        $mapImage = $asset('map', 'image', 'image/png');
        $fogImage = $asset('fog', 'image', 'image/png');
        $marker = $asset('marker', 'image', 'image/png');
        $music = $asset('music', 'audio', 'audio/mpeg');
        $video = $asset('video', 'video', 'video/mp4');
        $fallback = $asset('fallback', 'video', 'video/webm');
        $pc = PlayerCharacter::query()->create(['campaign_id' => $source->id, 'avatar_asset_id' => $pcAvatar->id, 'name' => 'Ari']);
        $npc = NonPlayerCharacter::query()->create(['campaign_id' => $source->id, 'normal_asset_id' => $npcImage->id, 'name' => 'Archivist', 'native_facing' => 'right']);
        $state = NpcState::query()->create(['npc_id' => $npc->id, 'asset_id' => $stateImage->id, 'name' => 'Smiling']);
        $audio = AudioCue::query()->create(['campaign_id' => $source->id, 'asset_id' => $music->id, 'name' => 'Theme', 'kind' => 'music', 'loop' => true]);
        $preset = StagePreset::query()->create(['campaign_id' => $source->id, 'name' => 'Opening', 'tween_duration_ms' => 300, 'tween_easing' => 'ease_in_out']);
        StagePresetEntry::query()->create(['stage_preset_id' => $preset->id, 'npc_id' => $npc->id, 'npc_state_id' => $state->id, 'position_x' => 0.25, 'position_y' => 0.5, 'scale' => 1, 'layer_order' => 1, 'facing' => 'left']);
        $scene = Scene::query()->create(['campaign_id' => $source->id, 'name' => 'Library', 'primary_backdrop_asset_id' => $backdrop->id, 'default_music_cue_id' => $audio->id, 'base_stage_preset_id' => $preset->id, 'transition' => 'cross_dissolve', 'transition_duration_ms' => 500]);
        SceneBackdrop::query()->create(['scene_id' => $scene->id, 'asset_id' => $alternate->id, 'name' => 'Night']);
        $map = CampaignMap::query()->create(['campaign_id' => $source->id, 'image_asset_id' => $mapImage->id, 'name' => 'Stacks']);
        MapFogMask::query()->create(['map_id' => $map->id, 'asset_id' => $fogImage->id]);
        MapToken::query()->create(['map_id' => $map->id, 'token_type' => 'pc', 'player_character_id' => $pc->id, 'position_x' => 0.2, 'position_y' => 0.3, 'scale' => 1]);
        MapToken::query()->create(['map_id' => $map->id, 'token_type' => 'npc', 'npc_id' => $npc->id, 'position_x' => 0.4, 'position_y' => 0.5, 'scale' => 1]);
        MapToken::query()->create(['map_id' => $map->id, 'token_type' => 'custom', 'asset_id' => $marker->id, 'label' => 'Seal', 'position_x' => 0.6, 'position_y' => 0.7, 'scale' => 1]);
        VideoCue::query()->create(['campaign_id' => $source->id, 'primary_asset_id' => $video->id, 'fallback_asset_id' => $fallback->id, 'name' => 'Vision', 'completion_mode' => 'enter_target_scene', 'target_scene_id' => $scene->id, 'music_during' => 'pause', 'music_after' => 'start_target_default']);
        DicePreset::query()->create(['campaign_id' => $source->id, 'name' => 'Check', 'expression' => '1d20+2', 'default_visibility' => 'public', 'is_default' => true]);
        $manifest = $this->app->make(CampaignManifestService::class)->build($source);
        $revision = CampaignRevision::query()->create(['campaign_id' => $source->id, 'number' => 1, 'manifest' => $manifest, 'manifest_hash' => hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 'published_at' => now()]);
        $storage = Mockery::mock(S3MultipartUploadService::class);
        $storage->shouldReceive('read')->times(count($media))->andReturnUsing(function (string $key) use ($media) {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $media[$key]);
            rewind($stream);

            return $stream;
        });
        $storage->shouldReceive('put')->times(count($media));
        $this->app->instance(S3MultipartUploadService::class, $storage);
        $package = $this->app->make(CampaignPackageService::class)->export($revision);
        $contents = file_get_contents($package['path']);
        unlink($package['path']);
        self::assertIsString($contents);

        $imported = $this->post('/api/control/v1/campaigns/import', ['command_id' => (string) Str::uuid7(), 'package' => UploadedFile::fake()->createWithContent('campaign.zip', $contents)])
            ->assertCreated()->json('data');
        $roundTrip = $this->app->make(CampaignManifestService::class)->build(Campaign::query()->findOrFail($imported['id']));
        self::assertCount(count($manifest['assets']), $roundTrip['assets']);
        self::assertCount(1, $roundTrip['player_characters']);
        self::assertCount(1, $roundTrip['npcs']);
        self::assertCount(1, $roundTrip['npc_states']);
        self::assertCount(1, $roundTrip['audio_cues']);
        self::assertCount(1, $roundTrip['stage_presets']);
        self::assertCount(1, $roundTrip['stage_preset_entries']);
        self::assertCount(1, $roundTrip['scenes']);
        self::assertCount(1, $roundTrip['scene_backdrops']);
        self::assertCount(1, $roundTrip['maps']);
        self::assertCount(1, $roundTrip['map_fog_masks']);
        self::assertCount(3, $roundTrip['map_tokens']);
        self::assertCount(1, $roundTrip['video_cues']);
        self::assertCount(1, $roundTrip['dice_presets']);
    }

    public function test_publishing_rejects_a_referenced_asset_that_is_not_ready(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Broken Archive']);
        $asset = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'pending.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_INITIATED]);
        PlayerCharacter::query()->create(['campaign_id' => $campaign->id, 'avatar_asset_id' => $asset->id, 'name' => 'Pending']);

        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/publish", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('campaign_revisions', 0);
    }

    public function test_publishing_rejects_a_cross_campaign_authored_reference(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Local Archive']);
        $otherCampaign = Campaign::query()->create(['name' => 'The Foreign Archive']);
        $foreignPreset = StagePreset::query()->create(['campaign_id' => $otherCampaign->id, 'name' => 'Foreign', 'tween_duration_ms' => 0, 'tween_easing' => 'linear']);
        Scene::query()->create(['campaign_id' => $campaign->id, 'name' => 'Broken scene', 'base_stage_preset_id' => $foreignPreset->id, 'transition' => 'cut']);

        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/publish", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('campaign_revisions', 0);
    }

    public function test_control_can_create_an_idempotent_live_session_pinned_to_a_revision(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Session Archive']);
        $revision = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => ['schema_version' => 1], 'manifest_hash' => str_repeat('e', 64), 'published_at' => now()]);
        $payload = ['command_id' => (string) Str::uuid7(), 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh'];

        $response = $this->postJson("/api/control/v1/campaigns/{$campaign->id}/sessions", $payload)
            ->assertCreated()->assertJsonPath('data.campaign_revision_id', $revision->id)->assertJsonPath('data.progress_mode', 'fresh')->json('data');
        self::assertIsString($response['display_pairing_token']);
        self::assertSame(64, strlen($response['display_pairing_token']));
        $this->postJson('/api/presentation/v1/pair', ['token' => $response['display_pairing_token']])
            ->assertOk()->assertJsonPath('data.session_id', $response['id']);
        $this->postJson('/api/presentation/v1/pair', ['token' => $response['display_pairing_token']])->assertNotFound();
        $this->assertDatabaseHas('live_sessions', ['id' => $response['id'], 'status' => 'active']);
        $this->assertDatabaseCount('presentation_displays', 1);
        $join = $this->postJson('/api/participant/v1/join', ['player_code' => strtolower($response['player_code']), 'display_name' => 'Mara', 'role' => 'player'])->assertCreated()->assertJsonPath('data.display_name', 'Mara')->json('data');
        self::assertIsString($join['resume_token']);
        $this->postJson('/api/participant/v1/resume', ['resume_token' => $join['resume_token']])->assertOk()->assertJsonPath('data.id', $join['id']);
        $this->postJson('/api/participant/v1/join', ['player_code' => $response['player_code'], 'display_name' => 'mara', 'role' => 'spectator'])->assertUnprocessable();
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/sessions", $payload)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/sessions")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_player_can_claim_only_a_pc_from_the_pinned_revision(): void
    {
        $campaign = Campaign::query()->create(['name' => 'The Claim Archive']);
        $revision = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => ['player_characters' => [['id' => '018f7c2a-b9a9-728a-90f7-4b6aff606fde']]], 'manifest_hash' => str_repeat('c', 64), 'published_at' => now()]);
        $session = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh', 'player_code' => 'CLAIM001', 'display_pairing_token_hash' => str_repeat('d', 64), 'status' => 'active']);
        $participant = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'player', 'display_name' => 'Mara', 'display_name_normalized' => 'mara', 'resume_token_hash' => str_repeat('e', 64)]);

        $this->withSession(['participant.id' => $participant->id])->postJson('/api/participant/v1/claim', ['player_character_id' => '018f7c2a-b9a9-728a-90f7-4b6aff606fde'])->assertCreated();
        $this->assertDatabaseCount('player_character_claims', 1);
    }

    public function test_control_preflights_and_explicitly_adopts_compatible_session_revisions(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Adoption Archive']);
        $pcId = '018f7c2a-b9a9-728a-90f7-4b6aff606fde';
        $sceneId = '018f7c2a-b9a9-728a-90f7-4b6aff606fdf';
        $current = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => ['schema_version' => 1, 'player_characters' => [['id' => $pcId, 'name' => 'Ari']], 'scenes' => [['id' => $sceneId, 'name' => 'Before']]], 'manifest_hash' => str_repeat('a', 64), 'published_at' => now()]);
        $removedClaim = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 2, 'manifest' => ['schema_version' => 1, 'scenes' => [['id' => $sceneId, 'name' => 'After']]], 'manifest_hash' => str_repeat('b', 64), 'published_at' => now()]);
        $compatible = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 3, 'manifest' => ['schema_version' => 1, 'player_characters' => [['id' => $pcId, 'name' => 'Ari']], 'scenes' => [['id' => $sceneId, 'name' => 'After']]], 'manifest_hash' => str_repeat('c', 64), 'published_at' => now()]);
        $session = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $current->id, 'progress_mode' => 'fresh', 'player_code' => 'ADOPT001', 'display_pairing_token_hash' => str_repeat('d', 64), 'status' => 'active']);
        $participant = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'player', 'display_name' => 'Mara', 'display_name_normalized' => 'mara', 'resume_token_hash' => str_repeat('e', 64)]);
        PlayerCharacterClaim::query()->create(['live_session_id' => $session->id, 'player_character_id' => $pcId, 'session_participant_id' => $participant->id]);

        $base = "/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}";
        $this->getJson("{$base}/revisions/{$removedClaim->id}/preflight")
            ->assertOk()->assertJsonPath('data.compatible', false)->assertJsonPath('data.blockers.0.type', 'claimed_player_character_removed');
        $this->postJson("{$base}/adopt-revision", ['command_id' => (string) Str::uuid7(), 'campaign_revision_id' => $removedClaim->id])->assertUnprocessable();
        $this->getJson("{$base}/revisions/{$compatible->id}/preflight")
            ->assertOk()->assertJsonPath('data.compatible', true)->assertJsonPath('data.changes.scenes.changed.0', $sceneId);
        $payload = ['command_id' => (string) Str::uuid7(), 'campaign_revision_id' => $compatible->id];
        $this->postJson("{$base}/adopt-revision", $payload)
            ->assertOk()->assertJsonPath('data.campaign_revision_id', $compatible->id)->assertJsonPath('meta.replayed', false);
        $this->postJson("{$base}/adopt-revision", $payload)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->assertDatabaseHas('session_events', ['campaign_id' => $campaign->id, 'event_type' => 'live_session.revision_adopted']);
        $this->assertDatabaseHas('outbox_events', ['aggregate_id' => $session->id, 'topic' => 'live_sessions.'.$session->id]);
    }

    public function test_presentation_state_is_revisioned_authorized_and_preserved_by_adoption_preflight(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The State Archive']);
        $ids = ['scene' => '018f7c2a-b9a9-728a-90f7-4b6aff606fd1', 'asset' => '018f7c2a-b9a9-728a-90f7-4b6aff606fd2', 'music' => '018f7c2a-b9a9-728a-90f7-4b6aff606fd3', 'video' => '018f7c2a-b9a9-728a-90f7-4b6aff606fd4', 'npc' => '018f7c2a-b9a9-728a-90f7-4b6aff606fd5', 'state' => '018f7c2a-b9a9-728a-90f7-4b6aff606fd6'];
        $manifest = ['schema_version' => 1, 'assets' => [['id' => $ids['asset']]], 'audio_cues' => [['id' => $ids['music']]], 'video_cues' => [['id' => $ids['video']]], 'npcs' => [['id' => $ids['npc']]], 'npc_states' => [['id' => $ids['state'], 'npc_id' => $ids['npc']]], 'scenes' => [['id' => $ids['scene']]]];
        $current = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => $manifest, 'manifest_hash' => str_repeat('a', 64), 'published_at' => now()]);
        $target = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 2, 'manifest' => ['schema_version' => 1, 'scenes' => [['id' => $ids['scene']]], 'npcs' => [['id' => $ids['npc']]], 'npc_states' => [['id' => $ids['state'], 'npc_id' => $ids['npc']]]], 'manifest_hash' => str_repeat('b', 64), 'published_at' => now()]);
        $session = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $current->id, 'progress_mode' => 'fresh', 'player_code' => 'STATE001', 'display_pairing_token_hash' => str_repeat('d', 64), 'status' => 'active']);
        $base = "/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}/presentation-state";
        $this->getJson($base)->assertOk()->assertJsonPath('data.revision', 1);
        $this->getJson('/api/presentation/v1/state')->assertUnauthorized();
        $state = ['scene_id' => $ids['scene'], 'backdrop_asset_id' => $ids['asset'], 'music_cue_id' => $ids['music'], 'video_cue_id' => $ids['video'], 'stage_entries' => [['npc_id' => $ids['npc'], 'npc_state_id' => $ids['state'], 'position_x' => 0.2, 'position_y' => 0.3, 'scale' => 1, 'layer_order' => 2, 'facing' => 'left']]];
        $payload = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'state' => $state];
        $this->putJson($base, $payload)->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.state.scene_id', $ids['scene']);
        $this->putJson($base, ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'state' => $state])->assertConflict()->assertJsonPath('data.revision', 2);
        $display = PresentationDisplay::query()->create(['live_session_id' => $session->id, 'credential_hash' => str_repeat('e', 64), 'paired_at' => now()]);
        $this->withSession(['presentation.display_id' => $display->id])->getJson('/api/presentation/v1/state')->assertOk()->assertJsonPath('data.revision', 2);
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}/revisions/{$target->id}/preflight")
            ->assertOk()->assertJsonPath('data.compatible', false)->assertJsonPath('data.blockers.0.reference_type', 'backdrop_asset_id');
    }

    public function test_map_progress_is_revisioned_resets_to_authored_defaults_and_blocks_map_removal(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Map Progress Archive']);
        $mapId = '018f7c2a-b9a9-728a-90f7-4b6aff606fc1';
        $fogId = '018f7c2a-b9a9-728a-90f7-4b6aff606fc2';
        $tokenId = '018f7c2a-b9a9-728a-90f7-4b6aff606fc3';
        $manifest = ['schema_version' => 1, 'maps' => [['id' => $mapId]], 'map_fog_masks' => [['map_id' => $mapId, 'asset_id' => $fogId]], 'map_tokens' => [['id' => $tokenId, 'map_id' => $mapId, 'token_type' => 'custom', 'position_x' => 0.2, 'position_y' => 0.3, 'scale' => 1, 'sort_order' => 0]]];
        $current = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => $manifest, 'manifest_hash' => str_repeat('a', 64), 'published_at' => now()]);
        $target = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 2, 'manifest' => ['schema_version' => 1], 'manifest_hash' => str_repeat('b', 64), 'published_at' => now()]);
        $session = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $current->id, 'progress_mode' => 'fresh', 'player_code' => 'MAP00001', 'display_pairing_token_hash' => str_repeat('d', 64), 'status' => 'active']);
        $base = "/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}/maps/{$mapId}/progress";
        $this->getJson($base)->assertOk()->assertJsonPath('data.revision', 1)->assertJsonPath('data.fog.mask_asset_id', $fogId)->assertJsonPath('data.tokens.0.position_x', 0.2);
        $payload = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'tokens' => [['source_token_id' => $tokenId, 'position_x' => 0.8, 'position_y' => 0.7, 'scale' => 1.5, 'sort_order' => 1]]];
        $this->putJson($base, $payload)->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.tokens.0.position_x', 0.8);
        $this->putJson($base, ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'tokens' => $payload['tokens']])->assertConflict()->assertJsonPath('data.revision', 2);
        $this->postJson("{$base}/reset", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 2])->assertOk()->assertJsonPath('data.revision', 3)->assertJsonPath('data.tokens.0.position_x', 0.2);
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}/revisions/{$target->id}/preflight")
            ->assertOk()->assertJsonPath('data.compatible', false)->assertJsonPath('data.blockers.0.type', 'active_map_removed');
    }

    public function test_control_can_list_release_and_revoke_session_participants(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Moderation Archive']);
        $revision = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => ['schema_version' => 1], 'manifest_hash' => str_repeat('c', 64), 'published_at' => now()]);
        $session = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh', 'player_code' => 'MODERATE', 'display_pairing_token_hash' => str_repeat('d', 64), 'status' => 'active']);
        $participant = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'player', 'display_name' => 'Mara', 'display_name_normalized' => 'mara', 'resume_token_hash' => str_repeat('e', 64)]);
        $claim = PlayerCharacterClaim::query()->create(['live_session_id' => $session->id, 'player_character_id' => '018f7c2a-b9a9-728a-90f7-4b6aff606fde', 'session_participant_id' => $participant->id]);

        $base = "/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}/participants/{$participant->id}";
        $this->getJson(dirname($base))->assertOk()->assertJsonPath('data.0.player_character_id', $claim->player_character_id);
        $this->deleteJson("{$base}/claim")->assertNoContent();
        $this->assertDatabaseMissing('player_character_claims', ['id' => $claim->id]);
        $this->deleteJson($base)->assertNoContent();
        $this->assertDatabaseHas('session_participants', ['id' => $participant->id, 'revoked_at' => now()->toDateTimeString()]);
    }

    public function test_control_can_initiate_a_private_asset_upload_idempotently(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Radiant Archive']);
        $multipart = Mockery::mock(S3MultipartUploadService::class);
        $multipart->shouldReceive('initiate')->once()->andReturn([
            'upload_id' => 'multipart-upload-id', 'part_size' => 8_388_608,
            'parts' => [['number' => 1, 'url' => 'https://storage.example.test/part-1']],
        ]);
        $this->app->instance(S3MultipartUploadService::class, $multipart);
        $payload = [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 1,
            'original_filename' => 'portrait.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 1024,
        ];

        $response = $this->postJson("/api/control/v1/campaigns/{$campaign->id}/assets/uploads", $payload)
            ->assertCreated()->assertJsonPath('data.upload_status', CampaignAsset::STATUS_INITIATED)
            ->assertJsonPath('upload.upload_id', 'multipart-upload-id')->assertJsonPath('meta.replayed', false);

        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/assets/uploads", $payload)
            ->assertOk()->assertJsonPath('data.id', $response->json('data.id'))->assertJsonPath('meta.replayed', true);
        $this->assertDatabaseHas('campaign_assets', ['campaign_id' => $campaign->id, 'upload_status' => CampaignAsset::STATUS_INITIATED]);
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'draft_revision' => 2]);
    }

    public function test_asset_upload_rejects_disallowed_types_and_stale_campaign_revisions(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Drowned Archive', 'draft_revision' => 2]);
        $base = ['command_id' => (string) Str::uuid7(), 'original_filename' => 'not-a-video.txt', 'kind' => 'video', 'declared_mime' => 'text/plain', 'byte_size' => 100];

        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/assets/uploads", $base + ['expected_revision' => 2])
            ->assertUnprocessable();
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/assets/uploads", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 1,
            'original_filename' => 'scene.mp4', 'kind' => 'video', 'declared_mime' => 'video/mp4', 'byte_size' => 100,
        ])->assertConflict()->assertJsonPath('data.draft_revision', 2);
    }

    public function test_ready_assets_are_exposed_only_through_short_lived_control_reads(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Echo Archive']);
        $asset = CampaignAsset::query()->create([
            'campaign_id' => $campaign->id, 'original_filename' => 'portrait.png', 'kind' => 'image',
            'declared_mime' => 'image/png', 'validated_mime' => 'image/png', 'byte_size' => 100,
            'sha256' => str_repeat('a', 64), 'storage_key' => 'assets/sha256/'.str_repeat('a', 64),
            'upload_status' => CampaignAsset::STATUS_READY,
        ]);
        $storage = Mockery::mock(S3MultipartUploadService::class);
        $storage->shouldReceive('signedReadUrl')->once()->with($asset->storage_key)->andReturn('https://storage.example.test/signed');
        $this->app->instance(S3MultipartUploadService::class, $storage);

        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/assets/{$asset->id}/read")
            ->assertOk()->assertJsonPath('data.url', 'https://storage.example.test/signed');

        $asset->update(['upload_status' => CampaignAsset::STATUS_INITIATED]);
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/assets/{$asset->id}/read")->assertUnprocessable();
    }

    public function test_completed_image_uploads_are_validated_and_promoted_to_checksum_storage(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Ember Archive']);
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL6NwAAAABJRU5ErkJggg==', true);
        self::assertIsString($bytes);
        $asset = CampaignAsset::query()->create([
            'campaign_id' => $campaign->id, 'original_filename' => 'pixel.png', 'kind' => 'image',
            'declared_mime' => 'image/png', 'byte_size' => strlen($bytes), 'upload_id' => 'upload-id',
            'upload_status' => CampaignAsset::STATUS_INITIATED,
        ]);
        $storage = Mockery::mock(S3MultipartUploadService::class);
        $storage->shouldReceive('complete')->once();
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);
        $storage->shouldReceive('read')->once()->andReturn($stream);
        $hash = hash('sha256', $bytes);
        $storage->shouldReceive('promote')->once()->with("staging/assets/{$asset->id}", "assets/sha256/{$hash}");
        $this->app->instance(S3MultipartUploadService::class, $storage);

        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/assets/{$asset->id}/complete", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 1,
            'parts' => [['number' => 1, 'e_tag' => 'part-etag']],
        ])->assertOk()->assertJsonPath('data.upload_status', CampaignAsset::STATUS_READY)
            ->assertJsonPath('data.sha256', $hash)->assertJsonPath('data.metadata.width', 1);
    }

    public function test_control_can_create_a_pc_only_with_a_ready_same_campaign_avatar(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Lantern Archive']);
        $avatar = CampaignAsset::query()->create([
            'campaign_id' => $campaign->id, 'original_filename' => 'avatar.png', 'kind' => 'image',
            'declared_mime' => 'image/png', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY,
        ]);
        $payload = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'name' => 'Mara', 'pronouns' => 'she/her', 'public_description' => 'A lantern keeper.', 'avatar_asset_id' => $avatar->id];
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/player-characters", $payload)
            ->assertCreated()->assertJsonPath('data.name', 'Mara')->assertJsonPath('data.avatar_asset_id', $avatar->id);
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/player-characters", $payload)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->assertDatabaseCount('player_characters', 1);
        $unready = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'pending.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_INITIATED]);
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/player-characters", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 2, 'name' => 'Rejected', 'avatar_asset_id' => $unready->id])->assertUnprocessable();
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/player-characters")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_control_can_create_an_npc_only_with_a_ready_normal_image(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Thorn Archive']);
        $image = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'npc.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY]);
        $payload = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'name' => 'The Thorn Witch', 'normal_asset_id' => $image->id, 'native_facing' => 'left'];
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/npcs", $payload)->assertCreated()->assertJsonPath('data.name', 'The Thorn Witch')->assertJsonPath('data.native_facing', 'left');
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/npcs", $payload)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/npcs")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_control_can_add_a_ready_image_as_an_optional_npc_state(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Glass Thorn']);
        $image = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'normal.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY]);
        $stateImage = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'angry.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY]);
        $npc = NonPlayerCharacter::query()->create(['campaign_id' => $campaign->id, 'normal_asset_id' => $image->id, 'name' => 'Thorn Witch', 'native_facing' => 'right']);
        $payload = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'name' => 'Angry', 'asset_id' => $stateImage->id];
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/npcs/{$npc->id}/states", $payload)->assertCreated()->assertJsonPath('data.name', 'Angry')->assertJsonPath('data.asset_id', $stateImage->id);
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/npcs/{$npc->id}/states", $payload)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/npcs/{$npc->id}/states")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_control_can_create_audio_cues_only_from_ready_campaign_audio(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Bell Archive']);
        $audio = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'bell.mp3', 'kind' => 'audio', 'declared_mime' => 'audio/mpeg', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY]);
        $payload = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'name' => 'Bell Theme', 'asset_id' => $audio->id, 'kind' => 'music', 'loop' => true, 'default_volume' => 70];
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/audio-cues", $payload)->assertCreated()->assertJsonPath('data.kind', 'music')->assertJsonPath('data.default_volume', 70);
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/audio-cues", $payload)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/audio-cues")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_control_can_create_a_scene_with_ready_image_and_music_references(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Observatory']);
        $backdrop = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'observatory.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY]);
        $audio = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'stars.mp3', 'kind' => 'audio', 'declared_mime' => 'audio/mpeg', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY]);
        $music = AudioCue::query()->create(['campaign_id' => $campaign->id, 'asset_id' => $audio->id, 'name' => 'Star Song', 'kind' => 'music', 'loop' => true, 'default_volume' => 60]);
        $preset = StagePreset::query()->create(['campaign_id' => $campaign->id, 'name' => 'Arrival', 'tween_duration_ms' => 500, 'tween_easing' => 'ease_out']);
        $payload = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'name' => 'Observatory', 'primary_backdrop_asset_id' => $backdrop->id, 'default_music_cue_id' => $music->id, 'base_stage_preset_id' => $preset->id, 'transition' => 'cross_dissolve', 'transition_duration_ms' => 700];

        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/scenes", $payload)
            ->assertCreated()->assertJsonPath('data.name', 'Observatory')->assertJsonPath('data.primary_backdrop_asset_id', $backdrop->id)
            ->assertJsonPath('data.default_music_cue_id', $music->id)->assertJsonPath('data.base_stage_preset_id', $preset->id)->assertJsonPath('data.transition', 'cross_dissolve');
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/scenes", $payload)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/scenes")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_control_can_add_ready_images_as_alternate_scene_backdrops(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Eclipse Archive']);
        $scene = Scene::query()->create(['campaign_id' => $campaign->id, 'name' => 'Eclipse', 'transition' => 'cut']);
        $backdrop = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'eclipse.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY]);
        $payload = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'name' => 'Totality', 'asset_id' => $backdrop->id];

        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/scenes/{$scene->id}/backdrops", $payload)
            ->assertCreated()->assertJsonPath('data.name', 'Totality')->assertJsonPath('data.asset_id', $backdrop->id);
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/scenes/{$scene->id}/backdrops", $payload)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/scenes/{$scene->id}/backdrops")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_control_can_author_a_stage_preset_with_an_npc_state_entry(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Stage Archive']);
        $normal = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'normal.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY]);
        $stateImage = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'smile.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY]);
        $npc = NonPlayerCharacter::query()->create(['campaign_id' => $campaign->id, 'normal_asset_id' => $normal->id, 'name' => 'Archivist', 'native_facing' => 'right']);
        $state = NpcState::query()->create(['npc_id' => $npc->id, 'asset_id' => $stateImage->id, 'name' => 'Smiling']);
        $presetPayload = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'name' => 'Opening', 'tween_duration_ms' => 450, 'tween_easing' => 'ease_in_out'];

        $preset = $this->postJson("/api/control/v1/campaigns/{$campaign->id}/stage-presets", $presetPayload)
            ->assertCreated()->assertJsonPath('data.name', 'Opening')->assertJsonPath('data.tween_easing', 'ease_in_out')->json('data');
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/stage-presets", $presetPayload)->assertOk()->assertJsonPath('meta.replayed', true);
        $entryPayload = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 2, 'npc_id' => $npc->id, 'npc_state_id' => $state->id, 'position_x' => 0.25, 'position_y' => 0.75, 'scale' => 1.25, 'layer_order' => 4, 'facing' => 'left'];
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/stage-presets/{$preset['id']}/entries", $entryPayload)
            ->assertCreated()->assertJsonPath('data.npc_id', $npc->id)->assertJsonPath('data.npc_state_id', $state->id)->assertJsonPath('data.facing', 'left');
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/stage-presets/{$preset['id']}/entries", $entryPayload)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/stage-presets/{$preset['id']}/entries")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_control_can_author_a_map_fog_mask_and_custom_token(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Cartographer Archive']);
        $mapImage = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'city.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY]);
        $fogImage = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'fog.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY]);
        $tokenImage = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'marker.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY]);
        $mapPayload = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'name' => 'Old City', 'image_asset_id' => $mapImage->id];

        $map = $this->postJson("/api/control/v1/campaigns/{$campaign->id}/maps", $mapPayload)
            ->assertCreated()->assertJsonPath('data.image_asset_id', $mapImage->id)->json('data');
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/maps", $mapPayload)->assertOk()->assertJsonPath('meta.replayed', true);
        $fogPayload = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 2, 'asset_id' => $fogImage->id];
        $this->putJson("/api/control/v1/campaigns/{$campaign->id}/maps/{$map['id']}/fog-mask", $fogPayload)
            ->assertCreated()->assertJsonPath('data.asset_id', $fogImage->id);
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/maps/{$map['id']}/fog-mask")->assertOk()->assertJsonPath('data.asset_id', $fogImage->id);
        $tokenPayload = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 3, 'token_type' => 'custom', 'asset_id' => $tokenImage->id, 'label' => 'Ritual Site', 'position_x' => 0.6, 'position_y' => 0.4, 'scale' => 1.2];
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/maps/{$map['id']}/tokens", $tokenPayload)
            ->assertCreated()->assertJsonPath('data.token_type', 'custom')->assertJsonPath('data.label', 'Ritual Site');
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/maps/{$map['id']}/tokens", $tokenPayload)->assertOk()->assertJsonPath('meta.replayed', true);
        $pc = PlayerCharacter::query()->create(['campaign_id' => $campaign->id, 'name' => 'Cartographer']);
        $npc = NonPlayerCharacter::query()->create(['campaign_id' => $campaign->id, 'normal_asset_id' => $tokenImage->id, 'name' => 'Guide', 'native_facing' => 'right']);
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/maps/{$map['id']}/tokens", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 4, 'token_type' => 'pc', 'player_character_id' => $pc->id, 'position_x' => 0.2, 'position_y' => 0.3, 'scale' => 1])
            ->assertCreated()->assertJsonPath('data.player_character_id', $pc->id);
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/maps/{$map['id']}/tokens", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 5, 'token_type' => 'npc', 'npc_id' => $npc->id, 'position_x' => 0.4, 'position_y' => 0.3, 'scale' => 1])
            ->assertCreated()->assertJsonPath('data.npc_id', $npc->id);
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/maps/{$map['id']}/tokens")->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_control_can_author_video_cues_with_completion_and_music_policies(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Cinema Archive']);
        $primary = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'arrival.mp4', 'kind' => 'video', 'declared_mime' => 'video/mp4', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY]);
        $fallback = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'arrival.webm', 'kind' => 'video', 'declared_mime' => 'video/webm', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY]);
        $scene = Scene::query()->create(['campaign_id' => $campaign->id, 'name' => 'Aftermath', 'transition' => 'cut']);
        $payload = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'name' => 'Arrival', 'primary_asset_id' => $primary->id, 'fallback_asset_id' => $fallback->id, 'completion_mode' => 'enter_target_scene', 'target_scene_id' => $scene->id, 'music_during' => 'pause', 'music_after' => 'start_target_default', 'embedded_audio_volume' => 70, 'embedded_audio_muted' => false];

        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/video-cues", $payload)
            ->assertCreated()->assertJsonPath('data.target_scene_id', $scene->id)->assertJsonPath('data.music_during', 'pause')->assertJsonPath('data.embedded_audio_volume', 70);
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/video-cues", $payload)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/video-cues")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_control_can_author_a_default_dice_preset(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Dice Archive']);
        $payload = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'name' => 'Ability Check', 'expression' => '4d6kh3 + 2', 'default_visibility' => 'public', 'is_default' => true];

        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/dice-presets", $payload)
            ->assertCreated()->assertJsonPath('data.expression', '4d6kh3+2')->assertJsonPath('data.is_default', true);
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/dice-presets", $payload)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/dice-presets")->assertOk()->assertJsonCount(1, 'data');
    }

    private function authenticateControl(): void
    {
        $this->postJson('/api/control/v1/auth/login', ['secret' => 'correct-horse-battery-staple-for-tests'])->assertOk();
    }
}
