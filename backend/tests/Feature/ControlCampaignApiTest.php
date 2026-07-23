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
use App\Models\PresentationState;
use App\Models\Scene;
use App\Models\SceneBackdrop;
use App\Models\SessionNpcNote;
use App\Models\SessionParticipant;
use App\Models\SessionPlayerGroup;
use App\Models\SessionPlayerGroupMember;
use App\Models\SessionRoll;
use App\Models\StagePreset;
use App\Models\StagePresetEntry;
use App\Models\User;
use App\Models\VideoCue;
use App\Services\CampaignAuthoringResetService;
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
        $this->assertDatabaseHas('users', ['email' => config('control.user_email')]);
    }

    public function test_passkey_management_requires_a_recent_control_secret_confirmation(): void
    {
        $this->authenticateControl();

        $this->getJson('/api/control/v1/passkeys')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->getJson('/api/control/v1/user/passkeys/options')
            ->assertOk()
            ->assertJsonStructure(['options']);

        $this->actingAs(User::query()->where('email', config('control.user_email'))->firstOrFail())
            ->withSession(['control.secret_confirmed_at' => now()->subSeconds((int) config('control.secret_confirmation_seconds') + 1)->unix()])
            ->getJson('/api/control/v1/user/passkeys/options')
            ->assertForbidden()
            ->assertJsonPath('message', 'Re-enter the Control secret before changing passkeys.');

        $this->postJson('/api/control/v1/auth/confirm-secret', ['secret' => 'wrong'])
            ->assertUnprocessable();

        $this->postJson('/api/control/v1/auth/confirm-secret', ['secret' => 'correct-horse-battery-staple-for-tests'])
            ->assertOk()
            ->assertJsonPath('data.confirmed_until', now()->addSeconds((int) config('control.secret_confirmation_seconds'))->toIso8601String());
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

    public function test_publish_preflight_reports_the_same_draft_validation_used_by_publish(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Preflight Archive']);

        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/publish-preflight")
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.issues', [])
            ->assertJsonPath('data.summary.assets', 0);

        $asset = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'pending.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_INITIATED]);
        PlayerCharacter::query()->create(['campaign_id' => $campaign->id, 'avatar_asset_id' => $asset->id, 'name' => 'Ari']);

        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/publish-preflight")
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.issues.0', 'Every referenced asset must be ready, unarchived, and belong to this campaign.');
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

    public function test_publishing_an_unchanged_draft_returns_the_existing_revision(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Preview Archive']);

        $first = $this->postJson("/api/control/v1/campaigns/{$campaign->id}/publish", [
            'command_id' => (string) Str::uuid7(),
            'expected_revision' => 1,
        ])->assertCreated();

        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/publish", [
            'command_id' => (string) Str::uuid7(),
            'expected_revision' => 1,
        ])->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('meta.replayed', false)
            ->assertJsonPath('meta.existing', true);

        $this->assertDatabaseCount('campaign_revisions', 1);
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

    public function test_control_can_archive_only_unreferenced_assets_and_archived_assets_cannot_be_reused(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Asset Archive']);
        $asset = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'retired.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'validated_mime' => 'image/png', 'byte_size' => 12, 'sha256' => str_repeat('a', 64), 'storage_key' => 'assets/sha256/retired', 'upload_status' => CampaignAsset::STATUS_READY]);
        $commandId = (string) Str::uuid7();

        $this->deleteJson("/api/control/v1/campaigns/{$campaign->id}/assets/{$asset->id}", ['command_id' => $commandId, 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('meta.replayed', false)->assertJsonPath('data.id', $asset->id);
        $this->deleteJson("/api/control/v1/campaigns/{$campaign->id}/assets/{$asset->id}", ['command_id' => $commandId, 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('meta.replayed', true);
        $this->assertDatabaseMissing('campaign_assets', ['id' => $asset->id, 'archived_at' => null]);

        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/npcs", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 2, 'name' => 'Cannot reuse', 'normal_asset_id' => $asset->id, 'native_facing' => 'right'])
            ->assertUnprocessable();

        $referenced = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'active.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'validated_mime' => 'image/png', 'byte_size' => 12, 'sha256' => str_repeat('b', 64), 'storage_key' => 'assets/sha256/active', 'upload_status' => CampaignAsset::STATUS_READY]);
        PlayerCharacter::query()->create(['campaign_id' => $campaign->id, 'avatar_asset_id' => $referenced->id, 'name' => 'Ari']);
        $this->deleteJson("/api/control/v1/campaigns/{$campaign->id}/assets/{$referenced->id}", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 2])
            ->assertUnprocessable();

        $published = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'published.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'validated_mime' => 'image/png', 'byte_size' => 12, 'sha256' => str_repeat('c', 64), 'storage_key' => 'assets/sha256/published', 'upload_status' => CampaignAsset::STATUS_READY]);
        CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => ['schema_version' => 1, 'assets' => [['id' => $published->id]]], 'manifest_hash' => str_repeat('d', 64), 'published_at' => now()]);
        $this->deleteJson("/api/control/v1/campaigns/{$campaign->id}/assets/{$published->id}", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 2])
            ->assertUnprocessable();
    }

    public function test_control_can_permanently_delete_unreferenced_media_and_preserve_referenced_media(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Delete Bin']);
        $unique = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'unique.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'validated_mime' => 'image/png', 'byte_size' => 12, 'storage_key' => 'assets/sha256/unique', 'upload_status' => CampaignAsset::STATUS_READY]);
        $referenced = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'referenced.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'validated_mime' => 'image/png', 'byte_size' => 12, 'storage_key' => 'assets/sha256/referenced', 'upload_status' => CampaignAsset::STATUS_READY]);
        PlayerCharacter::query()->create(['campaign_id' => $campaign->id, 'avatar_asset_id' => $referenced->id, 'name' => 'Ari']);
        $storage = Mockery::mock(S3MultipartUploadService::class);
        $storage->shouldReceive('delete')->once()->with('assets/sha256/unique');
        $this->app->instance(S3MultipartUploadService::class, $storage);

        $commandId = (string) Str::uuid7();
        $this->deleteJson("/api/control/v1/campaigns/{$campaign->id}/assets/{$unique->id}/permanently", ['command_id' => $commandId, 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('data.id', $unique->id)->assertJsonPath('meta.replayed', false);
        $this->deleteJson("/api/control/v1/campaigns/{$campaign->id}/assets/{$unique->id}/permanently", ['command_id' => $commandId, 'expected_revision' => 1])
            ->assertOk()->assertJsonPath('meta.replayed', true);
        $this->assertDatabaseMissing('campaign_assets', ['id' => $unique->id]);

        $this->deleteJson("/api/control/v1/campaigns/{$campaign->id}/assets/{$referenced->id}/permanently", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 2])
            ->assertUnprocessable();
        $this->assertDatabaseHas('campaign_assets', ['id' => $referenced->id]);
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
        $revision = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => ['player_characters' => [['id' => '018f7c2a-b9a9-728a-90f7-4b6aff606fde', 'name' => 'Ari', 'pronouns' => 'they/them', 'public_description' => 'A scout']]], 'manifest_hash' => str_repeat('c', 64), 'published_at' => now()]);
        $session = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh', 'player_code' => 'CLAIM001', 'display_pairing_token_hash' => str_repeat('d', 64), 'status' => 'active']);
        $participant = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'player', 'display_name' => 'Mara', 'display_name_normalized' => 'mara', 'resume_token_hash' => str_repeat('e', 64)]);

        $this->withSession(['participant.id' => $participant->id])->getJson('/api/participant/v1/roster')->assertOk()->assertJsonPath('data.characters.0.name', 'Ari')->assertJsonPath('data.characters.0.claimed', false);
        $this->withSession(['participant.id' => $participant->id])->postJson('/api/participant/v1/claim', ['player_character_id' => '018f7c2a-b9a9-728a-90f7-4b6aff606fde'])->assertCreated();
        $this->withSession(['participant.id' => $participant->id])->getJson('/api/participant/v1/roster')->assertOk()->assertJsonPath('data.characters.0.claimed_by_me', true);
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
        $manifest = ['schema_version' => 1, 'assets' => [['id' => $ids['asset']]], 'audio_cues' => [['id' => $ids['music'], 'kind' => 'music']], 'video_cues' => [['id' => $ids['video']]], 'npcs' => [['id' => $ids['npc']]], 'npc_states' => [['id' => $ids['state'], 'npc_id' => $ids['npc']]], 'scenes' => [['id' => $ids['scene']]]];
        $current = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => $manifest, 'manifest_hash' => str_repeat('a', 64), 'published_at' => now()]);
        $target = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 2, 'manifest' => ['schema_version' => 1, 'scenes' => [['id' => $ids['scene']]], 'npcs' => [['id' => $ids['npc']]], 'npc_states' => [['id' => $ids['state'], 'npc_id' => $ids['npc']]]], 'manifest_hash' => str_repeat('b', 64), 'published_at' => now()]);
        $session = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $current->id, 'progress_mode' => 'fresh', 'player_code' => 'STATE001', 'display_pairing_token_hash' => str_repeat('d', 64), 'status' => 'active']);
        $base = "/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}/presentation-state";
        $this->getJson($base)->assertOk()->assertJsonPath('data.revision', 1);
        $this->getJson('/api/presentation/v1/state')->assertUnauthorized();
        $state = ['scene_id' => $ids['scene'], 'backdrop_asset_id' => $ids['asset'], 'music_cue_id' => $ids['music'], 'music_playback' => ['status' => 'paused', 'position_seconds' => 14.5, 'position_command_id' => (string) Str::uuid7(), 'loop' => false, 'volume' => .35, 'fade_duration_ms' => 500], 'video_cue_id' => $ids['video'], 'stage_entries' => [['npc_id' => $ids['npc'], 'npc_state_id' => $ids['state'], 'position_x' => 0.2, 'position_y' => 0.3, 'scale' => 1, 'layer_order' => 2, 'facing' => 'left']]];
        $payload = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'state' => $state];
        $this->putJson($base, $payload)->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.state.scene_id', $ids['scene'])->assertJsonPath('data.state.music_playback.status', 'paused')->assertJsonPath('data.state.music_playback.position_seconds', 14.5)->assertJsonPath('data.state.music_playback.loop', false)->assertJsonPath('data.state.music_playback.volume', .35)->assertJsonPath('data.state.music_playback.fade_duration_ms', 500);
        $this->putJson($base, ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'state' => $state])->assertConflict()->assertJsonPath('data.revision', 2);
        $display = PresentationDisplay::query()->create(['live_session_id' => $session->id, 'credential_hash' => str_repeat('e', 64), 'paired_at' => now()]);
        $this->withSession(['presentation.display_id' => $display->id])->getJson('/api/presentation/v1/state')->assertOk()->assertJsonPath('data.revision', 2);
        $standby = $this->postJson("{$base}/standby", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 2, 'state' => $state])->assertOk()->assertJsonPath('data.revision', 3)->assertJsonPath('data.state.standby_status', 'preparing')->assertJsonPath('data.state.scene_id', $ids['scene']);
        $this->postJson("{$base}/go", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 3])->assertUnprocessable();
        $this->withSession(['presentation.display_id' => $display->id])->postJson('/api/presentation/v1/standby/report', ['command_id' => (string) Str::uuid7(), 'expected_revision' => 3, 'status' => 'error', 'error' => 'decode failed'])->assertOk()->assertJsonPath('data.revision', 4)->assertJsonPath('data.state.standby_status', 'error')->assertJsonPath('data.state.standby_error', 'decode failed');
        $this->postJson("{$base}/go", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 4])->assertUnprocessable();
        $this->postJson("{$base}/standby", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 4, 'state' => $state])->assertOk()->assertJsonPath('data.revision', 5)->assertJsonPath('data.state.standby_status', 'preparing');
        $report = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 5, 'status' => 'ready'];
        $this->withSession(['presentation.display_id' => $display->id])->postJson('/api/presentation/v1/standby/report', $report)->assertOk()->assertJsonPath('data.revision', 6)->assertJsonPath('data.state.standby_status', 'ready');
        $this->withSession(['presentation.display_id' => $display->id])->postJson('/api/presentation/v1/standby/report', $report)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->postJson("{$base}/go", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 6])->assertOk()->assertJsonPath('data.revision', 7)->assertJsonPath('data.state.standby_status', 'idle')->assertJsonPath('data.state.scene_id', $ids['scene']);
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}/revisions/{$target->id}/preflight")
            ->assertOk()->assertJsonPath('data.compatible', false)->assertJsonPath('data.blockers.0.reference_type', 'backdrop_asset_id');
    }

    public function test_presentation_render_resolves_only_the_pinned_active_and_standby_assets(): void
    {
        $campaign = Campaign::query()->create(['name' => 'The Render Archive']);
        $asset = static function (Campaign $campaign, string $name): CampaignAsset {
            return CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => "{$name}.png", 'kind' => 'image', 'declared_mime' => 'image/png', 'validated_mime' => 'image/png', 'byte_size' => 12, 'sha256' => hash('sha256', $name), 'storage_key' => "assets/{$name}", 'upload_status' => CampaignAsset::STATUS_READY]);
        };
        $backdrop = $asset($campaign, 'backdrop');
        $normal = $asset($campaign, 'normal');
        $stateAsset = $asset($campaign, 'state');
        $standbyBackdrop = $asset($campaign, 'standby');
        $primaryVideo = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'arrival.mp4', 'kind' => 'video', 'declared_mime' => 'video/mp4', 'validated_mime' => 'video/mp4', 'byte_size' => 12, 'sha256' => hash('sha256', 'arrival'), 'storage_key' => 'assets/arrival', 'upload_status' => CampaignAsset::STATUS_READY]);
        $fallbackVideo = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'arrival.webm', 'kind' => 'video', 'declared_mime' => 'video/webm', 'validated_mime' => 'video/webm', 'byte_size' => 12, 'sha256' => hash('sha256', 'arrival-fallback'), 'storage_key' => 'assets/arrival-fallback', 'upload_status' => CampaignAsset::STATUS_READY]);
        $ids = ['scene' => (string) Str::uuid7(), 'standby_scene' => (string) Str::uuid7(), 'preset' => (string) Str::uuid7(), 'npc' => (string) Str::uuid7(), 'state' => (string) Str::uuid7(), 'video' => (string) Str::uuid7()];
        $manifest = ['schema_version' => 1, 'assets' => [['id' => $backdrop->id], ['id' => $normal->id], ['id' => $stateAsset->id], ['id' => $standbyBackdrop->id], ['id' => $primaryVideo->id], ['id' => $fallbackVideo->id]], 'scenes' => [['id' => $ids['scene'], 'name' => 'The Hall', 'transition' => 'cross_dissolve', 'transition_duration_ms' => 450], ['id' => $ids['standby_scene'], 'name' => 'The Garden']], 'stage_presets' => [['id' => $ids['preset'], 'tween_duration_ms' => 300, 'tween_easing' => 'ease_in_out']], 'npcs' => [['id' => $ids['npc'], 'name' => 'Guide', 'normal_asset_id' => $normal->id, 'native_facing' => 'right']], 'npc_states' => [['id' => $ids['state'], 'npc_id' => $ids['npc'], 'asset_id' => $stateAsset->id]], 'video_cues' => [['id' => $ids['video'], 'primary_asset_id' => $primaryVideo->id, 'fallback_asset_id' => $fallbackVideo->id, 'completion_mode' => 'restore_captured_scene', 'target_scene_id' => null, 'music_during' => 'pause', 'music_after' => 'resume_prior', 'embedded_audio_volume' => 70, 'embedded_audio_muted' => false]]];
        $revision = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => $manifest, 'manifest_hash' => str_repeat('a', 64), 'published_at' => now()]);
        $session = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh', 'player_code' => 'RENDER01', 'display_pairing_token_hash' => str_repeat('d', 64), 'status' => 'active']);
        $entry = ['npc_id' => $ids['npc'], 'npc_state_id' => $ids['state'], 'position_x' => 0.25, 'position_y' => 0.75, 'scale' => 1, 'layer_order' => 1, 'facing' => 'left'];
        PresentationState::query()->create(['live_session_id' => $session->id, 'revision' => 3, 'state' => ['scene_id' => $ids['scene'], 'backdrop_asset_id' => $backdrop->id, 'music_cue_id' => null, 'video_cue_id' => $ids['video'], 'video_restore_state' => ['scene_id' => $ids['scene'], 'backdrop_asset_id' => $backdrop->id, 'music_cue_id' => null, 'video_cue_id' => null, 'stage_preset_id' => $ids['preset'], 'stage_entries' => [$entry]], 'stage_preset_id' => $ids['preset'], 'stage_entries' => [$entry], 'standby' => ['scene_id' => $ids['standby_scene'], 'backdrop_asset_id' => $standbyBackdrop->id, 'music_cue_id' => null, 'video_cue_id' => null, 'stage_preset_id' => null, 'stage_entries' => []], 'standby_status' => 'preparing', 'standby_error' => null]]);
        $display = PresentationDisplay::query()->create(['live_session_id' => $session->id, 'credential_hash' => str_repeat('e', 64), 'paired_at' => now()]);

        $this->withSession(['presentation.display_id' => $display->id])->getJson('/api/presentation/v1/render')
            ->assertOk()->assertJsonPath('data.revision', 3)->assertJsonPath('data.scene.name', 'The Hall')->assertJsonPath('data.video.primary_asset_id', $primaryVideo->id)->assertJsonPath('data.video.fallback_asset_id', $fallbackVideo->id)->assertJsonPath('data.video.music_during', 'pause')->assertJsonPath('data.stage_tween.duration_ms', 300)->assertJsonPath('data.stage_tween.easing', 'ease_in_out')->assertJsonPath('data.stage_entries.0.asset_id', $stateAsset->id)->assertJsonPath('data.stage_entries.0.native_facing', 'right')->assertJsonPath('data.standby.backdrop_asset_id', $standbyBackdrop->id);

        $storage = Mockery::mock(S3MultipartUploadService::class);
        $storage->shouldReceive('signedReadUrl')->once()->with($stateAsset->storage_key)->andReturn('https://assets.test/state');
        $storage->shouldReceive('signedReadUrl')->once()->with($primaryVideo->storage_key)->andReturn('https://assets.test/video');
        $this->app->instance(S3MultipartUploadService::class, $storage);
        $this->withSession(['presentation.display_id' => $display->id])->getJson("/api/presentation/v1/assets/{$stateAsset->id}/read")
            ->assertOk()->assertJsonPath('data.url', 'https://assets.test/state');
        $this->withSession(['presentation.display_id' => $display->id])->getJson("/api/presentation/v1/assets/{$primaryVideo->id}/read")
            ->assertOk()->assertJsonPath('data.url', 'https://assets.test/video');
        $this->withSession(['presentation.display_id' => $display->id])->getJson("/api/presentation/v1/assets/{$normal->id}/read")->assertNotFound();
    }

    public function test_presentation_video_completion_and_failure_are_applied_server_side(): void
    {
        $campaign = Campaign::query()->create(['name' => 'The Playback Archive']);
        $ids = ['current' => (string) Str::uuid7(), 'target' => (string) Str::uuid7(), 'video' => (string) Str::uuid7(), 'current_backdrop' => (string) Str::uuid7(), 'target_backdrop' => (string) Str::uuid7(), 'prior_music' => (string) Str::uuid7(), 'target_music' => (string) Str::uuid7()];
        $manifest = ['schema_version' => 1, 'assets' => [['id' => $ids['current_backdrop']], ['id' => $ids['target_backdrop']]], 'audio_cues' => [['id' => $ids['prior_music']], ['id' => $ids['target_music']]], 'scenes' => [['id' => $ids['current'], 'name' => 'Before', 'primary_backdrop_asset_id' => $ids['current_backdrop'], 'default_music_cue_id' => $ids['prior_music'], 'base_stage_preset_id' => null], ['id' => $ids['target'], 'name' => 'After', 'primary_backdrop_asset_id' => $ids['target_backdrop'], 'default_music_cue_id' => $ids['target_music'], 'base_stage_preset_id' => null]], 'video_cues' => [['id' => $ids['video'], 'primary_asset_id' => (string) Str::uuid7(), 'fallback_asset_id' => null, 'completion_mode' => 'enter_target_scene', 'target_scene_id' => $ids['target'], 'music_during' => 'pause', 'music_after' => 'start_target_default', 'embedded_audio_volume' => 100, 'embedded_audio_muted' => false]]];
        $revision = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => $manifest, 'manifest_hash' => str_repeat('a', 64), 'published_at' => now()]);
        $session = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh', 'player_code' => 'VIDEO001', 'display_pairing_token_hash' => str_repeat('d', 64), 'status' => 'active']);
        $restore = ['scene_id' => $ids['current'], 'backdrop_asset_id' => $ids['current_backdrop'], 'music_cue_id' => $ids['prior_music'], 'video_cue_id' => null, 'stage_preset_id' => null, 'stage_entries' => []];
        PresentationState::query()->create(['live_session_id' => $session->id, 'revision' => 2, 'state' => array_merge($restore, ['video_cue_id' => $ids['video'], 'video_restore_state' => $restore])]);
        $display = PresentationDisplay::query()->create(['live_session_id' => $session->id, 'credential_hash' => str_repeat('e', 64), 'paired_at' => now()]);

        $this->withSession(['presentation.display_id' => $display->id])->postJson('/api/presentation/v1/video/complete', ['command_id' => (string) Str::uuid7(), 'expected_revision' => 2, 'video_cue_id' => $ids['video']])
            ->assertOk()->assertJsonPath('data.revision', 3)->assertJsonPath('data.state.scene_id', $ids['target'])->assertJsonPath('data.state.backdrop_asset_id', $ids['target_backdrop'])->assertJsonPath('data.state.music_cue_id', $ids['target_music'])->assertJsonPath('data.state.video_cue_id', null);

        $snapshot = PresentationState::query()->where('live_session_id', $session->id)->firstOrFail();
        $snapshot->update(['revision' => 4, 'state' => array_merge($restore, ['video_cue_id' => $ids['video'], 'video_restore_state' => $restore])]);
        $this->withSession(['presentation.display_id' => $display->id])->postJson('/api/presentation/v1/video/fail', ['command_id' => (string) Str::uuid7(), 'expected_revision' => 4, 'video_cue_id' => $ids['video']])
            ->assertOk()->assertJsonPath('data.revision', 5)->assertJsonPath('data.state.scene_id', $ids['current'])->assertJsonPath('data.state.music_cue_id', $ids['prior_music'])->assertJsonPath('data.state.video_cue_id', null);

        $manifest['video_cues'][0]['music_after'] = 'remain_silent';
        $revision->update(['manifest' => $manifest]);
        $snapshot->refresh();
        $snapshot->update(['revision' => 6, 'state' => array_merge($restore, ['video_cue_id' => $ids['video'], 'video_restore_state' => $restore])]);
        $this->withSession(['presentation.display_id' => $display->id])->postJson('/api/presentation/v1/video/complete', ['command_id' => (string) Str::uuid7(), 'expected_revision' => 6, 'video_cue_id' => $ids['video']])
            ->assertOk()->assertJsonPath('data.revision', 7)->assertJsonPath('data.state.scene_id', $ids['target'])->assertJsonPath('data.state.music_cue_id', null)->assertJsonPath('data.state.music_playback.status', 'stopped');

        $manifest['video_cues'][0]['music_after'] = 'keep_current';
        $revision->update(['manifest' => $manifest]);
        $snapshot->refresh();
        $snapshot->update(['revision' => 8, 'state' => array_merge($restore, ['video_cue_id' => $ids['video'], 'video_restore_state' => $restore])]);
        $this->withSession(['presentation.display_id' => $display->id])->postJson('/api/presentation/v1/video/complete', ['command_id' => (string) Str::uuid7(), 'expected_revision' => 8, 'video_cue_id' => $ids['video']])
            ->assertOk()->assertJsonPath('data.revision', 9)->assertJsonPath('data.state.scene_id', $ids['target'])->assertJsonPath('data.state.music_cue_id', $ids['prior_music']);
    }

    public function test_presentation_sfx_instances_are_pinned_revisioned_and_cleaned_up(): void
    {
        $campaign = Campaign::query()->create(['name' => 'The Sound Archive']);
        $asset = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'bell.ogg', 'kind' => 'audio', 'declared_mime' => 'audio/ogg', 'validated_mime' => 'audio/ogg', 'byte_size' => 12, 'sha256' => hash('sha256', 'bell'), 'storage_key' => 'assets/bell', 'upload_status' => CampaignAsset::STATUS_READY]);
        $ids = ['cue' => (string) Str::uuid7(), 'instance' => (string) Str::uuid7()];
        $revision = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => ['schema_version' => 1, 'audio_cues' => [['id' => $ids['cue'], 'name' => 'Bell', 'kind' => 'sfx', 'asset_id' => $asset->id, 'loop' => false, 'default_volume' => 60]]], 'manifest_hash' => str_repeat('a', 64), 'published_at' => now()]);
        $session = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh', 'player_code' => 'SFX00001', 'display_pairing_token_hash' => str_repeat('d', 64), 'status' => 'active']);
        PresentationState::query()->create(['live_session_id' => $session->id, 'revision' => 2, 'state' => ['scene_id' => null, 'backdrop_asset_id' => null, 'music_cue_id' => null, 'music_playback' => ['status' => 'stopped', 'position_seconds' => 0, 'position_command_id' => null, 'loop' => true, 'volume' => 1, 'fade_duration_ms' => 0], 'sfx_master_volume' => .5, 'sfx_instances' => [['id' => $ids['instance'], 'cue_id' => $ids['cue'], 'loop' => false, 'volume' => .8]], 'video_cue_id' => null, 'stage_preset_id' => null, 'stage_entries' => []]]);
        $display = PresentationDisplay::query()->create(['live_session_id' => $session->id, 'credential_hash' => str_repeat('e', 64), 'paired_at' => now()]);

        $this->withSession(['presentation.display_id' => $display->id])->getJson('/api/presentation/v1/render')
            ->assertOk()->assertJsonPath('data.sfx.master_volume', .5)->assertJsonPath('data.sfx.instances.0.id', $ids['instance'])->assertJsonPath('data.sfx.instances.0.asset_id', $asset->id)->assertJsonPath('data.sfx.instances.0.volume', .8);

        $storage = Mockery::mock(S3MultipartUploadService::class);
        $storage->shouldReceive('signedReadUrl')->once()->with($asset->storage_key)->andReturn('https://assets.test/bell');
        $this->app->instance(S3MultipartUploadService::class, $storage);
        $this->withSession(['presentation.display_id' => $display->id])->getJson("/api/presentation/v1/assets/{$asset->id}/read")->assertOk()->assertJsonPath('data.url', 'https://assets.test/bell');
        $snapshot = PresentationState::query()->where('live_session_id', $session->id)->firstOrFail();
        $snapshot->update(['revision' => 3]);
        $this->withSession(['presentation.display_id' => $display->id])->postJson('/api/presentation/v1/sfx/complete', ['command_id' => (string) Str::uuid7(), 'expected_revision' => 2, 'sfx_instance_id' => $ids['instance']])
            ->assertOk()->assertJsonPath('data.revision', 4)->assertJsonPath('data.state.sfx_instances', []);
    }

    public function test_overlay_lanes_are_revisioned_independent_and_available_to_the_paired_display(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Overlay Archive']);
        $revision = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => ['schema_version' => 1], 'manifest_hash' => str_repeat('a', 64), 'published_at' => now()]);
        $session = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh', 'player_code' => 'OVERLAY1', 'display_pairing_token_hash' => str_repeat('d', 64), 'status' => 'active']);
        $base = "/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}/overlays";
        $this->getJson($base)->assertOk()->assertJsonPath('data.revision', 1)->assertJsonPath('data.state.corner.current', null);
        $this->getJson('/api/presentation/v1/overlays')->assertUnauthorized();
        $corner = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'placement' => 'corner', 'content' => 'Ari rolled 18.', 'duration_seconds' => 8, 'pinned' => false, 'source_type' => 'roll'];
        $cornerResponse = $this->postJson($base, $corner)->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.state.corner.current.content', 'Ari rolled 18.')->assertJsonPath('meta.replayed', false);
        $this->postJson($base, $corner)->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('meta.replayed', true);
        $this->postJson($base, ['command_id' => (string) Str::uuid7(), 'expected_revision' => 2, 'placement' => 'full', 'content' => 'The poll is open.', 'duration_seconds' => 20, 'pinned' => true])->assertOk()->assertJsonPath('data.revision', 3)->assertJsonPath('data.state.corner.current.content', 'Ari rolled 18.')->assertJsonPath('data.state.full.current.content', 'The poll is open.');
        $queued = $this->postJson($base, ['command_id' => (string) Str::uuid7(), 'expected_revision' => 3, 'placement' => 'corner', 'content' => 'Ari rolled 12.', 'duration_seconds' => 8, 'pinned' => false])->assertOk()->assertJsonPath('data.revision', 4)->assertJsonPath('data.state.corner.queue.0.content', 'Ari rolled 12.');
        $queuedId = $queued->json('data.state.corner.queue.0.id');
        $this->patchJson("{$base}/{$queuedId}", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 4, 'placement' => 'full'])->assertOk()->assertJsonPath('data.revision', 5)->assertJsonPath('data.state.corner.queue', [])->assertJsonPath('data.state.full.queue.0.content', 'Ari rolled 12.');
        $cornerId = $cornerResponse->json('data.state.corner.current.id');
        $this->patchJson("{$base}/{$cornerId}", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 5, 'duration_seconds' => 12, 'pinned' => true])->assertOk()->assertJsonPath('data.revision', 6)->assertJsonPath('data.state.corner.current.duration_seconds', 12)->assertJsonPath('data.state.corner.current.pinned', true);
        $this->patchJson("{$base}/{$cornerId}", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 5, 'content' => 'Lost update'])->assertConflict()->assertJsonPath('data.revision', 6);
        $this->postJson("{$base}/corner/advance", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 6])->assertOk()->assertJsonPath('data.revision', 7)->assertJsonPath('data.state.corner.current', null);
        $this->postJson("{$base}/full/dismiss", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 7])->assertOk()->assertJsonPath('data.revision', 8)->assertJsonPath('data.state.full.current.content', 'Ari rolled 12.');
        $display = PresentationDisplay::query()->create(['live_session_id' => $session->id, 'credential_hash' => str_repeat('e', 64), 'paired_at' => now()]);
        $this->withSession(['presentation.display_id' => $display->id])->getJson('/api/presentation/v1/overlays')->assertOk()->assertJsonPath('data.revision', 8)->assertJsonPath('data.state.full.current.content', 'Ari rolled 12.');
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

    public function test_control_can_brush_live_fog_and_participants_receive_only_visible_tokens(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Fogged Archive']);
        $mapId = '018f7c2a-b9a9-728a-90f7-4b6aff606fc1';
        $fogId = '018f7c2a-b9a9-728a-90f7-4b6aff606fc2';
        $visibleTokenId = '018f7c2a-b9a9-728a-90f7-4b6aff606fc3';
        $hiddenTokenId = '018f7c2a-b9a9-728a-90f7-4b6aff606fc4';
        $mapAsset = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'chapel.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 100, 'storage_key' => 'assets/chapel.png', 'upload_status' => CampaignAsset::STATUS_READY]);
        $mapAssetId = $mapAsset->id;
        $manifest = ['schema_version' => 1, 'maps' => [['id' => $mapId, 'name' => 'Sunken Chapel', 'image_asset_id' => $mapAssetId]], 'map_fog_masks' => [['map_id' => $mapId, 'asset_id' => $fogId]], 'map_tokens' => [
            ['id' => $visibleTokenId, 'map_id' => $mapId, 'token_type' => 'custom', 'label' => 'Mara', 'position_x' => 0.2, 'position_y' => 0.2, 'scale' => 1, 'sort_order' => 0],
            ['id' => $hiddenTokenId, 'map_id' => $mapId, 'token_type' => 'custom', 'label' => 'The Watcher', 'position_x' => 0.8, 'position_y' => 0.8, 'scale' => 1, 'sort_order' => 1],
        ]];
        $revision = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => $manifest, 'manifest_hash' => str_repeat('a', 64), 'published_at' => now()]);
        $session = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh', 'player_code' => 'FOG00001', 'display_pairing_token_hash' => str_repeat('d', 64), 'status' => 'active']);
        $participant = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'player', 'display_name' => 'Mara', 'display_name_normalized' => 'mara', 'resume_token_hash' => str_repeat('e', 64)]);
        $controlBase = "/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}/maps/{$mapId}/progress";
        $participantPath = "/api/participant/v1/maps/{$mapId}/progress";
        $playerMapPath = "/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}/player-map";

        $this->getJson($participantPath)->assertUnauthorized();
        $this->withSession(['participant.id' => $participant->id])->getJson('/api/participant/v1/map')
            ->assertOk()->assertJsonPath('data.state.map_id', null)->assertJsonPath('data.map', null)->assertJsonPath('data.progress', null);
        $this->withSession(['participant.id' => $participant->id])->getJson($participantPath)->assertNotFound();
        $selection = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'map_id' => $mapId];
        $this->putJson($playerMapPath, $selection)->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.map_id', $mapId);
        $this->putJson($playerMapPath, $selection)->assertOk()->assertJsonPath('meta.replayed', true)->assertJsonPath('data.revision', 2);
        $this->putJson($playerMapPath, ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'map_id' => $mapId])->assertConflict()->assertJsonPath('data.revision', 2);
        $this->putJson($playerMapPath, ['command_id' => (string) Str::uuid7(), 'expected_revision' => 2, 'map_id' => null])->assertOk()->assertJsonPath('data.revision', 3)->assertJsonPath('data.map_id', null);
        $this->withSession(['participant.id' => $participant->id])->getJson($participantPath)->assertNotFound();
        $this->putJson($playerMapPath, ['command_id' => (string) Str::uuid7(), 'expected_revision' => 3, 'map_id' => $mapId])->assertOk()->assertJsonPath('data.revision', 4);
        $this->withSession(['participant.id' => $participant->id])->getJson($participantPath)
            ->assertOk()->assertJsonPath('data.map.name', 'Sunken Chapel')->assertJsonPath('data.progress.fog.default_visibility', 'hidden')->assertJsonCount(0, 'data.progress.tokens');
        $storage = Mockery::mock(S3MultipartUploadService::class);
        $storage->shouldReceive('signedReadUrl')->once()->with('assets/chapel.png')->andReturn('https://storage.example.test/chapel.png');
        $this->app->instance(S3MultipartUploadService::class, $storage);
        $this->withSession(['participant.id' => $participant->id])->getJson("/api/participant/v1/map/assets/{$mapAssetId}/read")->assertOk()->assertJsonPath('data.url', 'https://storage.example.test/chapel.png');

        $reveal = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'mode' => 'reveal', 'center_x' => 0.2, 'center_y' => 0.2, 'radius' => 0.1];
        $this->postJson("{$controlBase}/fog", $reveal)->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.fog.brushes.0.mode', 'reveal');
        $this->postJson("{$controlBase}/fog", $reveal)->assertOk()->assertJsonPath('meta.replayed', true)->assertJsonPath('data.revision', 2);
        $this->postJson("{$controlBase}/fog", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'mode' => 'hide', 'center_x' => 0.2, 'center_y' => 0.2, 'radius' => 0.1])
            ->assertConflict()->assertJsonPath('data.revision', 2);

        $this->withSession(['participant.id' => $participant->id])->getJson($participantPath)
            ->assertOk()->assertJsonCount(1, 'data.progress.tokens')->assertJsonPath('data.progress.tokens.0.source_token_id', $visibleTokenId)
            ->assertJsonMissing(['source_token_id' => $hiddenTokenId])->assertJsonMissing(['label' => 'The Watcher']);

        $this->postJson("{$controlBase}/fog", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 2, 'mode' => 'hide', 'center_x' => 0.2, 'center_y' => 0.2, 'radius' => 0.1])
            ->assertOk()->assertJsonPath('data.revision', 3);
        $this->withSession(['participant.id' => $participant->id])->getJson($participantPath)->assertOk()->assertJsonCount(0, 'data.progress.tokens');

        $participant->update(['revoked_at' => now()]);
        $this->withSession(['participant.id' => $participant->id])->getJson($participantPath)->assertForbidden();
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

    public function test_control_can_manage_idempotent_session_scoped_player_groups(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Group Archive']);
        $revision = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => ['schema_version' => 1], 'manifest_hash' => str_repeat('c', 64), 'published_at' => now()]);
        $session = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh', 'player_code' => 'GROUP001', 'display_pairing_token_hash' => str_repeat('d', 64), 'status' => 'active']);
        $player = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'player', 'display_name' => 'Mara', 'display_name_normalized' => 'mara', 'resume_token_hash' => str_repeat('e', 64)]);
        $spectator = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'spectator', 'display_name' => 'Rowan', 'display_name_normalized' => 'rowan', 'resume_token_hash' => str_repeat('f', 64)]);
        $otherSession = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh', 'player_code' => 'GROUP002', 'display_pairing_token_hash' => str_repeat('a', 64), 'status' => 'active']);
        $otherPlayer = SessionParticipant::query()->create(['live_session_id' => $otherSession->id, 'role' => 'player', 'display_name' => 'Elsewhere', 'display_name_normalized' => 'elsewhere', 'resume_token_hash' => str_repeat('b', 64)]);
        $base = "/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}/player-groups";
        $create = ['command_id' => (string) Str::uuid7(), 'name' => '  Lantern Bearers  '];

        $group = $this->postJson($base, $create)->assertCreated()->assertJsonPath('data.name', 'Lantern Bearers')->assertJsonPath('data.member_participant_ids', [])->assertJsonPath('meta.replayed', false)->json('data');
        $this->postJson($base, $create)->assertOk()->assertJsonPath('meta.replayed', true);
        $memberPath = "{$base}/{$group['id']}/members/{$player->id}";
        $add = ['command_id' => (string) Str::uuid7()];
        $this->putJson($memberPath, $add)->assertOk()->assertJsonPath('data.member_participant_ids', [$player->id])->assertJsonPath('meta.replayed', false);
        $this->putJson($memberPath, $add)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->getJson($base)->assertOk()->assertJsonPath('data.0.member_participant_ids', [$player->id]);
        $this->putJson("{$base}/{$group['id']}/members/{$spectator->id}", ['command_id' => (string) Str::uuid7()])->assertUnprocessable();
        $this->putJson("{$base}/{$group['id']}/members/{$otherPlayer->id}", ['command_id' => (string) Str::uuid7()])->assertNotFound();
        $remove = ['command_id' => (string) Str::uuid7()];
        $this->deleteJson($memberPath, $remove)->assertOk()->assertJsonPath('data.member_participant_ids', [])->assertJsonPath('meta.replayed', false);
        $this->deleteJson($memberPath, $remove)->assertOk()->assertJsonPath('meta.replayed', true);

        $this->assertDatabaseCount('session_player_groups', 1);
        $this->assertDatabaseCount('session_player_group_members', 0);
        $this->assertDatabaseCount('processed_commands', 3);
        $this->assertDatabaseCount('session_events', 3);
        $this->assertDatabaseCount('outbox_events', 3);
    }

    public function test_resumed_sessions_transfer_player_group_names_and_pc_based_memberships(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Continuity Archive']);
        $pcId = (string) Str::uuid7();
        $revision = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => ['schema_version' => 1, 'player_characters' => [['id' => $pcId, 'name' => 'Mara']]], 'manifest_hash' => str_repeat('c', 64), 'published_at' => now()]);
        $prior = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh', 'player_code' => 'PRIOR001', 'display_pairing_token_hash' => str_repeat('d', 64), 'status' => 'ended']);
        $priorPlayer = SessionParticipant::query()->create(['live_session_id' => $prior->id, 'role' => 'player', 'display_name' => 'Mara', 'display_name_normalized' => 'mara', 'resume_token_hash' => str_repeat('e', 64)]);
        PlayerCharacterClaim::query()->create(['live_session_id' => $prior->id, 'player_character_id' => $pcId, 'session_participant_id' => $priorPlayer->id]);
        $priorGroup = SessionPlayerGroup::query()->create(['live_session_id' => $prior->id, 'name' => 'Lantern Bearers', 'name_normalized' => 'lantern bearers']);
        SessionPlayerGroupMember::query()->create(['session_player_group_id' => $priorGroup->id, 'session_participant_id' => $priorPlayer->id]);

        $resumed = $this->postJson("/api/control/v1/campaigns/{$campaign->id}/sessions", ['command_id' => (string) Str::uuid7(), 'campaign_revision_id' => $revision->id, 'progress_mode' => 'resume'])->assertCreated()->json('data');
        $controlGroups = "/api/control/v1/campaigns/{$campaign->id}/sessions/{$resumed['id']}/player-groups";
        $this->getJson($controlGroups)->assertOk()->assertJsonPath('data.0.name', 'Lantern Bearers')->assertJsonPath('data.0.member_participant_ids', []);
        $resumedPlayer = SessionParticipant::query()->create(['live_session_id' => $resumed['id'], 'role' => 'player', 'display_name' => 'New Mara', 'display_name_normalized' => 'new mara', 'resume_token_hash' => str_repeat('f', 64)]);
        $this->withSession(['participant.id' => $resumedPlayer->id])->postJson('/api/participant/v1/claim', ['player_character_id' => $pcId])->assertCreated();
        $this->withSession(['participant.id' => $resumedPlayer->id])->getJson('/api/participant/v1/player-groups')->assertOk()->assertJsonPath('data.0.name', 'Lantern Bearers');
        $this->assertDatabaseHas('session_events', ['campaign_id' => $campaign->id, 'event_type' => 'player_group.member_restored']);
        $this->assertDatabaseHas('outbox_events', ['topic' => 'player_groups.'.$resumed['id'], 'payload->event_type' => 'player_group.member_restored']);
        $resumedSpectator = SessionParticipant::query()->create(['live_session_id' => $resumed['id'], 'role' => 'spectator', 'display_name' => 'Rowan', 'display_name_normalized' => 'rowan', 'resume_token_hash' => str_repeat('a', 64)]);
        $this->withSession(['participant.id' => $resumedSpectator->id])->getJson('/api/participant/v1/player-groups')->assertOk()->assertJsonCount(0, 'data');

        $fresh = $this->postJson("/api/control/v1/campaigns/{$campaign->id}/sessions", ['command_id' => (string) Str::uuid7(), 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh'])->assertCreated()->json('data');
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/sessions/{$fresh['id']}/player-groups")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_session_messages_snapshot_recipients_and_enforce_the_target_matrix(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Correspondence Archive']);
        $revision = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => ['schema_version' => 1], 'manifest_hash' => str_repeat('c', 64), 'published_at' => now()]);
        $session = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh', 'player_code' => 'MESSAGE1', 'display_pairing_token_hash' => str_repeat('d', 64), 'status' => 'active']);
        $player = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'player', 'display_name' => 'Mara', 'display_name_normalized' => 'mara', 'resume_token_hash' => str_repeat('e', 64)]);
        $groupMember = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'player', 'display_name' => 'Iris', 'display_name_normalized' => 'iris', 'resume_token_hash' => str_repeat('f', 64)]);
        $outsidePlayer = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'player', 'display_name' => 'Dev', 'display_name_normalized' => 'dev', 'resume_token_hash' => str_repeat('a', 64)]);
        $spectator = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'spectator', 'display_name' => 'Rowan', 'display_name_normalized' => 'rowan', 'resume_token_hash' => str_repeat('b', 64)]);
        $group = SessionPlayerGroup::query()->create(['live_session_id' => $session->id, 'name' => 'Lantern Bearers', 'name_normalized' => 'lantern bearers']);
        SessionPlayerGroupMember::query()->create(['session_player_group_id' => $group->id, 'session_participant_id' => $player->id]);
        SessionPlayerGroupMember::query()->create(['session_player_group_id' => $group->id, 'session_participant_id' => $groupMember->id]);
        $controlPath = "/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}/messages";
        $sendControl = function (array $payload) use ($controlPath): array {
            return $this->postJson($controlPath, $payload + ['command_id' => (string) Str::uuid7()])->assertCreated()->json('data');
        };

        $sendControl(['target_type' => 'individual', 'target_session_participant_id' => $player->id, 'body' => 'Private note']);
        $sendControl(['target_type' => 'player_group', 'session_player_group_id' => $group->id, 'body' => 'Group briefing']);
        $sendControl(['target_type' => 'all_players', 'body' => 'Player broadcast']);
        $sendControl(['target_type' => 'all_spectators', 'body' => 'Spectator broadcast']);
        $broadcast = $sendControl(['target_type' => 'all', 'body' => 'Everyone hears this']);
        $this->postJson($controlPath, ['command_id' => (string) Str::uuid7(), 'target_type' => 'all', 'body' => '   '])->assertUnprocessable();

        $participantPath = '/api/participant/v1/messages';
        $this->withSession(['participant.id' => $player->id])->getJson($participantPath)->assertOk()->assertJsonCount(4, 'data')->assertJsonFragment(['body' => 'Private note'])->assertJsonFragment(['body' => 'Group briefing'])->assertJsonFragment(['body' => 'Player broadcast'])->assertJsonFragment(['body' => 'Everyone hears this'])->assertJsonMissing(['body' => 'Spectator broadcast']);
        $this->withSession(['participant.id' => $outsidePlayer->id])->getJson($participantPath)->assertOk()->assertJsonCount(2, 'data')->assertJsonMissing(['body' => 'Private note'])->assertJsonMissing(['body' => 'Group briefing']);
        $this->withSession(['participant.id' => $spectator->id])->getJson($participantPath)->assertOk()->assertJsonCount(2, 'data')->assertJsonFragment(['body' => 'Spectator broadcast'])->assertJsonMissing(['body' => 'Player broadcast']);

        $this->withSession(['participant.id' => $player->id])->postJson($participantPath, ['command_id' => (string) Str::uuid7(), 'target_type' => 'control', 'body' => 'A private Player question'])->assertCreated();
        $this->withSession(['participant.id' => $player->id])->postJson($participantPath, ['command_id' => (string) Str::uuid7(), 'target_type' => 'player_group', 'session_player_group_id' => $group->id, 'body' => 'A group reply'])->assertCreated();
        $this->withSession(['participant.id' => $outsidePlayer->id])->postJson($participantPath, ['command_id' => (string) Str::uuid7(), 'target_type' => 'player_group', 'session_player_group_id' => $group->id, 'body' => 'Unauthorized group message'])->assertForbidden();
        $this->withSession(['participant.id' => $spectator->id])->postJson($participantPath, ['command_id' => (string) Str::uuid7(), 'target_type' => 'player_group', 'session_player_group_id' => $group->id, 'body' => 'Unauthorized spectator group message'])->assertForbidden();
        $this->withSession(['participant.id' => $spectator->id])->postJson($participantPath, ['command_id' => (string) Str::uuid7(), 'target_type' => 'control', 'reply_to_session_message_id' => $broadcast['id'], 'body' => 'Spectator reply'])->assertCreated();

        $this->withSession(['participant.id' => $groupMember->id])->getJson($participantPath)->assertOk()->assertJsonFragment(['body' => 'A group reply'])->assertJsonMissing(['body' => 'A private Player question'])->assertJsonMissing(['body' => 'Spectator reply']);
        $this->withSession(['participant.id' => $outsidePlayer->id])->getJson($participantPath)->assertOk()->assertJsonMissing(['body' => 'A group reply'])->assertJsonMissing(['body' => 'Spectator reply']);
        $this->getJson($controlPath)->assertOk()->assertJsonCount(8, 'data')->assertJsonFragment(['body' => 'Spectator reply']);
        $this->assertDatabaseCount('session_events', 8);
        $this->assertDatabaseCount('outbox_events', 8);
    }

    public function test_control_can_publish_a_spectator_reply_to_the_full_presentation_overlay(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Reply Archive']);
        $revision = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => ['schema_version' => 1], 'manifest_hash' => str_repeat('c', 64), 'published_at' => now()]);
        $session = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh', 'player_code' => 'REPLIES1', 'display_pairing_token_hash' => str_repeat('d', 64), 'status' => 'active']);
        $spectator = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'spectator', 'display_name' => 'Rowan', 'display_name_normalized' => 'rowan', 'resume_token_hash' => str_repeat('e', 64)]);
        $player = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'player', 'display_name' => 'Mara', 'display_name_normalized' => 'mara', 'resume_token_hash' => str_repeat('f', 64)]);
        $messages = '/api/participant/v1/messages';
        $spectatorReply = $this->withSession(['participant.id' => $spectator->id])->postJson($messages, ['command_id' => (string) Str::uuid7(), 'target_type' => 'control', 'body' => 'I found the hidden door.'])->assertCreated()->json('data');
        $playerReply = $this->withSession(['participant.id' => $player->id])->postJson($messages, ['command_id' => (string) Str::uuid7(), 'target_type' => 'control', 'body' => 'I should stay private.'])->assertCreated()->json('data');
        $queuedReply = $this->withSession(['participant.id' => $spectator->id])->postJson($messages, ['command_id' => (string) Str::uuid7(), 'target_type' => 'control', 'body' => 'The second clue is in the library.'])->assertCreated()->json('data');
        $base = "/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}";
        $commandId = (string) Str::uuid7();

        $this->postJson("{$base}/messages/{$playerReply['id']}/publish-spectator-reply", ['command_id' => (string) Str::uuid7()])->assertUnprocessable();
        $this->postJson("{$base}/messages/{$spectatorReply['id']}/publish-spectator-reply", ['command_id' => $commandId])
            ->assertOk()
            ->assertJsonPath('data.id', $spectatorReply['id'])
            ->assertJsonPath('data.sender_name', 'Rowan')
            ->assertJsonPath('meta.replayed', false);
        $this->getJson("{$base}/overlays")
            ->assertOk()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.state.full.current.content', 'Rowan: I found the hidden door.')
            ->assertJsonPath('data.state.full.current.duration_seconds', 15)
            ->assertJsonPath('data.state.full.current.pinned', false)
            ->assertJsonPath('data.state.full.current.source_type', 'session_message')
            ->assertJsonPath('data.state.full.current.source_id', $spectatorReply['id']);

        $this->postJson("{$base}/messages/{$spectatorReply['id']}/publish-spectator-reply", ['command_id' => $commandId])->assertOk()->assertJsonPath('meta.replayed', true);
        $this->postJson("{$base}/messages/{$queuedReply['id']}/publish-spectator-reply", ['command_id' => (string) Str::uuid7()])->assertOk();
        $this->getJson("{$base}/overlays")->assertOk()->assertJsonPath('data.revision', 3)->assertJsonPath('data.state.full.queue.0.content', 'Rowan: The second clue is in the library.');
        $this->assertDatabaseCount('session_events', 5);
        $this->assertDatabaseCount('outbox_events', 5);
        $this->assertDatabaseHas('session_events', ['event_type' => 'overlay_state.spectator_reply_published', 'command_id' => $commandId]);
        $this->assertDatabaseHas('outbox_events', ['topic' => 'overlay_states.'.$session->id, 'payload->event_type' => 'overlay_state.spectator_reply_published']);
    }

    public function test_session_polls_snapshot_recipients_allow_vote_changes_and_publish_aggregate_results(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Ballot Archive']);
        $revision = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => ['schema_version' => 1], 'manifest_hash' => str_repeat('c', 64), 'published_at' => now()]);
        $session = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh', 'player_code' => 'POLLS001', 'display_pairing_token_hash' => str_repeat('d', 64), 'status' => 'active']);
        $player = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'player', 'display_name' => 'Mara', 'display_name_normalized' => 'mara', 'resume_token_hash' => str_repeat('e', 64)]);
        $spectator = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'spectator', 'display_name' => 'Rowan', 'display_name_normalized' => 'rowan', 'resume_token_hash' => str_repeat('f', 64)]);
        $base = "/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}/polls";
        $poll = $this->postJson($base, ['command_id' => (string) Str::uuid7(), 'question' => 'Which door?', 'options' => ['North', 'South', 'Wait'], 'allows_multiple' => false, 'target_type' => 'all_players'])->assertCreated()->assertJsonPath('data.question', 'Which door?')->assertJsonPath('data.options.0.votes', 0)->json('data');
        $this->withSession(['participant.id' => $player->id])->getJson('/api/participant/v1/polls')->assertOk()->assertJsonPath('data.0.options.0.votes', null);
        $this->withSession(['participant.id' => $spectator->id])->getJson('/api/participant/v1/polls')->assertOk()->assertJsonCount(0, 'data');
        $votePath = "/api/participant/v1/polls/{$poll['id']}/vote";
        $first = ['command_id' => (string) Str::uuid7(), 'option_ids' => [$poll['options'][0]['id']]];
        $this->withSession(['participant.id' => $player->id])->postJson($votePath, $first)->assertOk()->assertJsonPath('data.my_option_ids.0', $poll['options'][0]['id']);
        $this->withSession(['participant.id' => $player->id])->postJson($votePath, ['command_id' => (string) Str::uuid7(), 'option_ids' => [$poll['options'][1]['id']]])->assertOk()->assertJsonPath('data.my_option_ids.0', $poll['options'][1]['id']);
        $this->postJson("{$base}/{$poll['id']}/publish-results", ['command_id' => (string) Str::uuid7(), 'visibility' => 'live'])->assertOk()->assertJsonPath('data.result_visibility', 'live');
        $this->withSession(['participant.id' => $player->id])->getJson('/api/participant/v1/polls')->assertOk()->assertJsonPath('data.0.options.0.votes', 0)->assertJsonPath('data.0.options.1.votes', 1)->assertJsonMissing(['session_participant_id' => $player->id]);
        $this->postJson("{$base}/{$poll['id']}/close", ['command_id' => (string) Str::uuid7()])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->withSession(['participant.id' => $player->id])->postJson($votePath, ['command_id' => (string) Str::uuid7(), 'option_ids' => [$poll['options'][2]['id']]])->assertUnprocessable();
        $this->postJson("{$base}/{$poll['id']}/publish-results", ['command_id' => (string) Str::uuid7(), 'visibility' => 'final'])->assertOk()->assertJsonPath('data.result_visibility', 'final');
    }

    public function test_session_rolls_are_server_evaluated_private_by_default_and_revealable_by_control(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Dice Archive']);
        $presetId = '018f7c2a-b9a9-728a-90f7-4b6aff606f99';
        $revision = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => ['schema_version' => 1, 'dice_presets' => [['id' => $presetId, 'name' => 'Brave check', 'expression' => '1d20+2', 'default_visibility' => 'public']]], 'manifest_hash' => str_repeat('c', 64), 'published_at' => now()]);
        $session = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh', 'player_code' => 'ROLLS001', 'display_pairing_token_hash' => str_repeat('d', 64), 'status' => 'active']);
        $player = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'player', 'display_name' => 'Mara', 'display_name_normalized' => 'mara', 'resume_token_hash' => str_repeat('e', 64)]);
        $spectator = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'spectator', 'display_name' => 'Rowan', 'display_name_normalized' => 'rowan', 'resume_token_hash' => str_repeat('f', 64)]);
        $rollPath = '/api/participant/v1/rolls';
        $privateCommand = (string) Str::uuid7();
        $private = ['command_id' => $privateCommand, 'expression' => '2d6kh1+2', 'visibility' => 'private'];
        $privateRoll = $this->withSession(['participant.id' => $player->id])->postJson($rollPath, $private)->assertCreated()->assertJsonPath('data.visibility', 'private')->assertJsonPath('data.breakdown.left.type', 'dice')->json('data');
        $this->withSession(['participant.id' => $player->id])->postJson($rollPath, $private)->assertOk()->assertJsonPath('meta.replayed', true)->assertJsonPath('data.id', $privateRoll['id']);
        $this->withSession(['participant.id' => $spectator->id])->getJson($rollPath)->assertOk()->assertJsonCount(0, 'data');
        $this->withSession(['participant.id' => $spectator->id])->postJson($rollPath, ['command_id' => (string) Str::uuid7(), 'expression' => '1d20'])->assertForbidden();

        $this->withSession(['participant.id' => $player->id])->postJson($rollPath, ['command_id' => (string) Str::uuid7(), 'dice_preset_id' => $presetId])->assertCreated()->assertJsonPath('data.dice_preset_name', 'Brave check')->assertJsonPath('data.visibility', 'public');
        $this->withSession(['participant.id' => $spectator->id])->getJson($rollPath)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.roller_name', 'Mara');

        $controlPath = "/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}/rolls";
        $this->getJson($controlPath)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.visibility', 'private');
        $this->postJson("{$controlPath}/{$privateRoll['id']}/reveal", ['command_id' => (string) Str::uuid7()])->assertOk()->assertJsonPath('data.visibility', 'public')->assertJsonPath('data.revealed_at', fn (mixed $value): bool => is_string($value));
        $this->withSession(['participant.id' => $spectator->id])->getJson($rollPath)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}/overlays")->assertOk()->assertJsonPath('data.state.corner.current.source_type', 'session_roll')->assertJsonPath('data.state.corner.queue.0.content', 'Mara rolled '.SessionRoll::query()->where('id', $privateRoll['id'])->firstOrFail()->total.'.')->assertJsonCount(1, 'data.state.corner.queue');
        $this->assertDatabaseCount('session_rolls', 2);
        $this->assertDatabaseCount('session_events', 5);
        $this->assertDatabaseCount('outbox_events', 5);
    }

    public function test_control_explicitly_reveals_pinned_npc_profiles_to_participants(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Veiled Archive']);
        $npcId = '018f7c2a-b9a9-728a-90f7-4b6aff606f20';
        $revision = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => ['schema_version' => 1, 'npcs' => [['id' => $npcId, 'name' => 'The Curator', 'pronouns' => 'they/them', 'public_description' => 'A careful keeper of forbidden books.']]], 'manifest_hash' => str_repeat('a', 64), 'published_at' => now()]);
        $session = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh', 'player_code' => 'REVEAL01', 'display_pairing_token_hash' => str_repeat('d', 64), 'status' => 'active']);
        $participant = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'spectator', 'display_name' => 'Rowan', 'display_name_normalized' => 'rowan', 'resume_token_hash' => str_repeat('e', 64)]);
        $participantPath = '/api/participant/v1/npcs';
        $base = "/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}/npc-reveals/{$npcId}";

        $this->withSession(['participant.id' => $participant->id])->getJson($participantPath)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(dirname($base))->assertOk()->assertJsonCount(0, 'data');
        $payload = ['command_id' => (string) Str::uuid7(), 'is_revealed' => true];
        $this->putJson($base, $payload)->assertOk()->assertJsonPath('data.npc_id', $npcId)->assertJsonPath('data.is_revealed', true)->assertJsonPath('meta.replayed', false);
        $this->putJson($base, $payload)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->withSession(['participant.id' => $participant->id])->getJson($participantPath)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'The Curator')->assertJsonPath('data.0.public_description', 'A careful keeper of forbidden books.');
        $this->putJson($base, ['command_id' => (string) Str::uuid7(), 'is_revealed' => false])->assertOk()->assertJsonPath('data.is_revealed', false);
        $this->withSession(['participant.id' => $participant->id])->getJson($participantPath)->assertOk()->assertJsonCount(0, 'data');
        $this->putJson("/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}/npc-reveals/018f7c2a-b9a9-728a-90f7-4b6aff606f21", ['command_id' => (string) Str::uuid7(), 'is_revealed' => true])->assertUnprocessable();
        $participant->update(['revoked_at' => now()]);
        $this->withSession(['participant.id' => $participant->id])->getJson($participantPath)->assertForbidden();
    }

    public function test_players_can_add_plain_text_notes_to_revealed_npc_profiles(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Annotated Archive']);
        $npcId = '018f7c2a-b9a9-728a-90f7-4b6aff606f30';
        $revision = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => ['schema_version' => 1, 'npcs' => [['id' => $npcId, 'name' => 'The Chronicler']]], 'manifest_hash' => str_repeat('a', 64), 'published_at' => now()]);
        $session = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh', 'player_code' => 'NOTES001', 'display_pairing_token_hash' => str_repeat('d', 64), 'status' => 'active']);
        $player = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'player', 'display_name' => 'Mara', 'display_name_normalized' => 'mara', 'resume_token_hash' => str_repeat('e', 64)]);
        $spectator = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'spectator', 'display_name' => 'Rowan', 'display_name_normalized' => 'rowan', 'resume_token_hash' => str_repeat('f', 64)]);
        $reveal = "/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}/npc-reveals/{$npcId}";
        $this->putJson($reveal, ['command_id' => (string) Str::uuid7(), 'is_revealed' => true])->assertOk();
        $path = "/api/participant/v1/npcs/{$npcId}/notes";
        $payload = ['command_id' => (string) Str::uuid7(), 'body' => '  Carries a brass key.  '];

        $note = $this->withSession(['participant.id' => $player->id])->postJson($path, $payload)->assertCreated()->assertJsonPath('data.body', 'Carries a brass key.')->assertJsonPath('data.author_type', 'participant')->json('data');
        $this->withSession(['participant.id' => $player->id])->postJson($path, $payload)->assertOk()->assertJsonPath('meta.replayed', true);
        $this->withSession(['participant.id' => $spectator->id])->getJson('/api/participant/v1/npcs')->assertOk()->assertJsonPath('data.0.notes.0.author_name', 'Mara')->assertJsonPath('data.0.notes.0.body', 'Carries a brass key.');
        $notePath = "/api/participant/v1/npc-notes/{$note['id']}";
        $this->withSession(['participant.id' => $spectator->id])->patchJson($notePath, ['command_id' => (string) Str::uuid7(), 'body' => 'Not allowed'])->assertForbidden();
        $this->withSession(['participant.id' => $player->id])->patchJson($notePath, ['command_id' => (string) Str::uuid7(), 'body' => '  The brass key opens the west door.  '])->assertOk()->assertJsonPath('data.body', 'The brass key opens the west door.');
        $this->withSession(['participant.id' => $player->id])->deleteJson($notePath, ['command_id' => (string) Str::uuid7()])->assertOk()->assertJsonPath('data.id', $note['id']);
        $this->withSession(['participant.id' => $spectator->id])->getJson('/api/participant/v1/npcs')->assertOk()->assertJsonCount(0, 'data.0.notes');
        $this->withSession(['participant.id' => $spectator->id])->postJson($path, ['command_id' => (string) Str::uuid7(), 'body' => 'Not allowed'])->assertForbidden();
        $this->withSession(['participant.id' => $player->id])->postJson($path, ['command_id' => (string) Str::uuid7(), 'body' => '   '])->assertUnprocessable();
        $this->putJson($reveal, ['command_id' => (string) Str::uuid7(), 'is_revealed' => false])->assertOk();
        $this->withSession(['participant.id' => $player->id])->postJson($path, ['command_id' => (string) Str::uuid7(), 'body' => 'Not visible'])->assertNotFound();
        $archivedNote = SessionNpcNote::query()->create(['live_session_id' => $session->id, 'npc_id' => $npcId, 'author_type' => 'participant', 'session_participant_id' => $player->id, 'body' => 'Preserve this archive note.']);
        $session->update(['status' => 'ended']);
        $this->withSession(['participant.id' => $player->id])->patchJson("/api/participant/v1/npc-notes/{$archivedNote->id}", ['command_id' => (string) Str::uuid7(), 'body' => 'No longer allowed'])->assertUnprocessable();
        $controlNotePath = "/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}/npc-notes/{$archivedNote->id}";
        $this->getJson(dirname($controlNotePath))->assertOk()->assertJsonPath('data.0.body', 'Preserve this archive note.');
        $this->patchJson($controlNotePath, ['command_id' => (string) Str::uuid7(), 'body' => 'Control corrected archive note.'])->assertOk()->assertJsonPath('data.body', 'Control corrected archive note.');
        $this->deleteJson($controlNotePath, ['command_id' => (string) Str::uuid7()])->assertOk()->assertJsonPath('data.id', $archivedNote->id);
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

    public function test_control_can_start_an_asset_replacement_without_changing_its_id(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Recast Archive']);
        $asset = CampaignAsset::query()->create([
            'campaign_id' => $campaign->id, 'original_filename' => 'old.png', 'kind' => 'image',
            'declared_mime' => 'image/png', 'validated_mime' => 'image/png', 'byte_size' => 100,
            'sha256' => str_repeat('b', 64), 'storage_key' => 'assets/sha256/'.str_repeat('b', 64),
            'upload_status' => CampaignAsset::STATUS_READY,
        ]);
        $storage = Mockery::mock(S3MultipartUploadService::class);
        $storage->shouldReceive('initiate')->once()->with("staging/assets/{$asset->id}/replacement", 'image/png', 101)
            ->andReturn(['upload_id' => 'replacement-upload', 'part_size' => 101, 'parts' => [['number' => 1, 'url' => 'https://storage.example.test/upload']]]);
        $this->app->instance(S3MultipartUploadService::class, $storage);

        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/assets/{$asset->id}/replacement", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'original_filename' => 'new.png',
            'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 101,
        ])->assertCreated()->assertJsonPath('data.id', $asset->id)->assertJsonPath('upload.upload_id', 'replacement-upload');

        $this->assertDatabaseHas('campaign_assets', ['id' => $asset->id, 'original_filename' => 'old.png', 'replacement_original_filename' => 'new.png', 'replacement_upload_id' => 'replacement-upload']);
    }

    public function test_control_reports_a_storage_outage_instead_of_a_server_error_when_replacing_media(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Offline Archive']);
        $asset = CampaignAsset::query()->create([
            'campaign_id' => $campaign->id, 'original_filename' => 'old.png', 'kind' => 'image',
            'declared_mime' => 'image/png', 'validated_mime' => 'image/png', 'byte_size' => 100,
            'sha256' => str_repeat('b', 64), 'storage_key' => 'assets/sha256/'.str_repeat('b', 64),
            'upload_status' => CampaignAsset::STATUS_READY,
        ]);
        $storage = Mockery::mock(S3MultipartUploadService::class);
        $storage->shouldReceive('initiate')->once()->andThrow(new \RuntimeException('NoSuchBucket'));
        $this->app->instance(S3MultipartUploadService::class, $storage);

        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/assets/{$asset->id}/replacement", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'original_filename' => 'new.png',
            'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 101,
        ])->assertStatus(503)->assertJsonPath('message', 'Media storage is unavailable. Please try again.');

        $this->assertDatabaseHas('campaign_assets', ['id' => $asset->id, 'original_filename' => 'old.png', 'replacement_upload_id' => null]);
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'draft_revision' => 1]);
    }

    public function test_control_can_complete_an_asset_replacement_without_reattaching_references(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Replaced Archive']);
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL6NwAAAABJRU5ErkJggg==', true);
        self::assertIsString($bytes);
        $oldHash = str_repeat('b', 64);
        $asset = CampaignAsset::query()->create([
            'campaign_id' => $campaign->id, 'original_filename' => 'old.png', 'kind' => 'image',
            'declared_mime' => 'image/png', 'validated_mime' => 'image/png', 'byte_size' => 100,
            'sha256' => $oldHash, 'storage_key' => "assets/sha256/{$oldHash}", 'upload_status' => CampaignAsset::STATUS_READY,
            'replacement_original_filename' => 'new.png', 'replacement_declared_mime' => 'image/png',
            'replacement_byte_size' => strlen($bytes), 'replacement_upload_id' => 'replacement-upload',
        ]);
        $storage = Mockery::mock(S3MultipartUploadService::class);
        $storage->shouldReceive('complete')->once()->with("staging/assets/{$asset->id}/replacement", 'replacement-upload', [['number' => 1, 'e_tag' => 'part-etag']]);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);
        $storage->shouldReceive('read')->once()->andReturn($stream);
        $hash = hash('sha256', $bytes);
        $storage->shouldReceive('promote')->once()->with("staging/assets/{$asset->id}/replacement", "assets/sha256/{$hash}");
        $storage->shouldReceive('delete')->once()->with("assets/sha256/{$oldHash}");
        $this->app->instance(S3MultipartUploadService::class, $storage);

        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/assets/{$asset->id}/replacement/complete", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 1,
            'parts' => [['number' => 1, 'e_tag' => 'part-etag']],
        ])->assertOk()->assertJsonPath('data.id', $asset->id)->assertJsonPath('data.original_filename', 'new.png')
            ->assertJsonPath('data.sha256', $hash)->assertJsonPath('data.metadata.width', 1);

        $this->assertDatabaseHas('campaign_assets', ['id' => $asset->id, 'original_filename' => 'new.png', 'storage_key' => "assets/sha256/{$hash}", 'replacement_upload_id' => null]);
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
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/player-characters", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 2, 'name' => 'Ilya', 'pronouns' => 'he/him', 'public_description' => 'A scout.', 'avatar_asset_id' => $avatar->id])
            ->assertCreated()->assertJsonPath('data.sort_order', 2);
        $this->assertDatabaseCount('player_characters', 2);
        $unready = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'pending.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_INITIATED]);
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/player-characters", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 3, 'name' => 'Rejected', 'avatar_asset_id' => $unready->id])->assertUnprocessable();
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/player-characters")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_control_can_create_a_right_facing_npc_only_with_a_ready_normal_image(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Thorn Archive']);
        $image = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'npc.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY]);
        $payload = ['command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'name' => 'The Thorn Witch', 'normal_asset_id' => $image->id];
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/npcs", $payload)->assertCreated()->assertJsonPath('data.name', 'The Thorn Witch')->assertJsonPath('data.native_facing', 'right');
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

    public function test_campaign_studio_snapshot_edits_draft_records_and_organizes_media(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Studio Archive']);
        $asset = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'court.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY]);
        $character = PlayerCharacter::query()->create(['campaign_id' => $campaign->id, 'avatar_asset_id' => $asset->id, 'name' => 'Ari']);

        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/studio")
            ->assertOk()
            ->assertJsonPath('data.campaign.name', 'The Studio Archive')
            ->assertJsonPath('data.records.assets.0.id', $asset->id)
            ->assertJsonPath('data.records.player_characters.0.id', $character->id);

        $this->patchJson("/api/control/v1/campaigns/{$campaign->id}/studio/player-characters/{$character->id}", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'patch' => ['name' => 'Ari Vale', 'pronouns' => 'they/them'],
        ])->assertOk()->assertJsonPath('data.record.name', 'Ari Vale')->assertJsonPath('data.campaign.draft_revision', 2);

        $collection = $this->postJson("/api/control/v1/campaigns/{$campaign->id}/studio/asset-collections", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 2, 'name' => 'Act one',
        ])->assertCreated()->assertJsonPath('data.record.name', 'Act one')->json('data.record');

        $this->patchJson("/api/control/v1/campaigns/{$campaign->id}/studio/asset-collections/{$collection['id']}", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 3, 'patch' => ['asset_ids' => [$asset->id]],
        ])->assertOk()->assertJsonPath('data.campaign.draft_revision', 4);

        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/studio")
            ->assertOk()
            ->assertJsonPath('data.records.asset_collections.0.asset_ids.0', $asset->id);

        $this->deleteJson("/api/control/v1/campaigns/{$campaign->id}/studio/assets/{$asset->id}", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 4,
        ])->assertUnprocessable()->assertJsonPath('usages.0.section', 'player_characters');
    }

    public function test_campaign_studio_rejects_stale_or_cross_campaign_updates(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Studio Guard']);
        $otherCampaign = Campaign::query()->create(['name' => 'Elsewhere']);
        $character = PlayerCharacter::query()->create(['campaign_id' => $campaign->id, 'name' => 'Ari']);
        $otherCharacter = PlayerCharacter::query()->create(['campaign_id' => $otherCampaign->id, 'name' => 'Not Ari']);

        $this->patchJson("/api/control/v1/campaigns/{$campaign->id}/studio/player-characters/{$character->id}", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 9, 'patch' => ['name' => 'Nope'],
        ])->assertConflict()->assertJsonPath('data.draft_revision', 1);

        $this->patchJson("/api/control/v1/campaigns/{$campaign->id}/studio/player-characters/{$otherCharacter->id}", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'patch' => ['name' => 'Nope'],
        ])->assertNotFound();
    }

    public function test_campaign_studio_reorders_records_and_deletes_unreferenced_resources(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Studio Mutations']);
        $firstPreset = DicePreset::query()->create(['campaign_id' => $campaign->id, 'name' => 'First', 'expression' => '1d20', 'default_visibility' => 'public', 'sort_order' => 0]);
        $secondPreset = DicePreset::query()->create(['campaign_id' => $campaign->id, 'name' => 'Second', 'expression' => '2d6', 'default_visibility' => 'public', 'sort_order' => 1]);
        $asset = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'unused.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY]);

        $this->putJson("/api/control/v1/campaigns/{$campaign->id}/studio/dice-presets/order", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'ids' => [$secondPreset->id, $firstPreset->id],
        ])->assertOk()->assertJsonPath('data.campaign.draft_revision', 2);
        $this->assertSame(0, $secondPreset->fresh()->sort_order);
        $this->assertSame(1, $firstPreset->fresh()->sort_order);

        $this->deleteJson("/api/control/v1/campaigns/{$campaign->id}/studio/assets/{$asset->id}", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 2,
        ])->assertOk()->assertJsonPath('data.campaign.draft_revision', 3);
        $this->assertNotNull($asset->fresh()->archived_at);

        $collection = $this->postJson("/api/control/v1/campaigns/{$campaign->id}/studio/asset-collections", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 3, 'name' => 'Disposable collection',
        ])->assertCreated()->json('data.record');
        $this->deleteJson("/api/control/v1/campaigns/{$campaign->id}/studio/asset-collections/{$collection['id']}", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 4,
        ])->assertOk()->assertJsonPath('data.campaign.draft_revision', 5);
        $this->assertDatabaseMissing('campaign_asset_collections', ['id' => $collection['id']]);

        $this->deleteJson("/api/control/v1/campaigns/{$campaign->id}/studio/dice-presets/{$firstPreset->id}", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 5,
        ])->assertOk()->assertJsonPath('data.campaign.draft_revision', 6);
        $this->assertDatabaseMissing('dice_presets', ['id' => $firstPreset->id]);
    }

    public function test_campaign_studio_updates_each_nested_resource_owner(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Studio Graph']);
        $asset = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'graph.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY]);
        $npc = NonPlayerCharacter::query()->create(['campaign_id' => $campaign->id, 'normal_asset_id' => $asset->id, 'name' => 'Guide', 'native_facing' => 'right']);
        $state = NpcState::query()->create(['npc_id' => $npc->id, 'asset_id' => $asset->id, 'name' => 'Ready']);
        $scene = Scene::query()->create(['campaign_id' => $campaign->id, 'name' => 'Gallery', 'transition' => 'cut']);
        $backdrop = SceneBackdrop::query()->create(['scene_id' => $scene->id, 'asset_id' => $asset->id, 'name' => 'Day']);
        $preset = StagePreset::query()->create(['campaign_id' => $campaign->id, 'name' => 'Opening']);
        $entry = StagePresetEntry::query()->create(['stage_preset_id' => $preset->id, 'npc_id' => $npc->id, 'npc_state_id' => $state->id, 'position_x' => 0.2, 'position_y' => 0.3, 'scale' => 1, 'facing' => 'right']);
        $map = CampaignMap::query()->create(['campaign_id' => $campaign->id, 'image_asset_id' => $asset->id, 'name' => 'Floor plan']);
        $token = MapToken::query()->create(['map_id' => $map->id, 'token_type' => 'custom', 'asset_id' => $asset->id, 'label' => 'Marker', 'position_x' => 0.4, 'position_y' => 0.5, 'scale' => 1]);

        $this->patchJson("/api/control/v1/campaigns/{$campaign->id}/studio/npc-states/{$state->id}", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'patch' => ['name' => 'Alert'],
        ])->assertOk()->assertJsonPath('data.record.name', 'Alert');
        $this->patchJson("/api/control/v1/campaigns/{$campaign->id}/studio/scene-backdrops/{$backdrop->id}", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 2, 'patch' => ['name' => 'Night'],
        ])->assertOk()->assertJsonPath('data.record.name', 'Night');
        $this->patchJson("/api/control/v1/campaigns/{$campaign->id}/studio/stage-preset-entries/{$entry->id}", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 3, 'patch' => ['position_x' => 0.7],
        ])->assertOk()->assertJsonPath('data.record.position_x', 0.7);
        $this->patchJson("/api/control/v1/campaigns/{$campaign->id}/studio/map-tokens/{$token->id}", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 4, 'patch' => ['label' => 'Exit'],
        ])->assertOk()->assertJsonPath('data.record.label', 'Exit')->assertJsonPath('data.campaign.draft_revision', 5);
    }

    public function test_campaign_studio_updates_asset_labels_and_reports_stale_mutations(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Studio Labels']);
        $asset = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'label.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY]);
        $preset = DicePreset::query()->create(['campaign_id' => $campaign->id, 'name' => 'Check', 'expression' => '1d20', 'default_visibility' => 'public']);

        $this->patchJson("/api/control/v1/campaigns/{$campaign->id}/studio/assets/{$asset->id}", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'patch' => ['label' => 'Stage art'],
        ])->assertOk()->assertJsonPath('data.record.label', 'Stage art');
        $this->patchJson("/api/control/v1/campaigns/{$campaign->id}/studio/assets/{$asset->id}", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 2, 'patch' => ['label' => null],
        ])->assertOk()->assertJsonPath('data.record.label', null);

        $collection = $this->postJson("/api/control/v1/campaigns/{$campaign->id}/studio/asset-collections", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 3, 'name' => 'First act',
        ])->assertCreated()->json('data.record');
        $this->patchJson("/api/control/v1/campaigns/{$campaign->id}/studio/asset-collections/{$collection['id']}", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 4, 'patch' => ['name' => 'Second act'],
        ])->assertOk()->assertJsonPath('data.record.name', 'Second act');

        $maximumLengthCollection = $this->postJson("/api/control/v1/campaigns/{$campaign->id}/studio/asset-collections", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 5, 'name' => str_repeat('x', 120),
        ])->assertCreated()->json('data.record');
        $this->assertSame(2, $maximumLengthCollection['sort_order']);

        $this->putJson("/api/control/v1/campaigns/{$campaign->id}/studio/dice-presets/order", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 9, 'ids' => [$preset->id],
        ])->assertConflict()->assertJsonPath('data.draft_revision', 6);
        $this->deleteJson("/api/control/v1/campaigns/{$campaign->id}/studio/assets/{$asset->id}", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 9,
        ])->assertConflict()->assertJsonPath('data.draft_revision', 6);
        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/studio/asset-collections", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 9, 'name' => 'Never created',
        ])->assertConflict()->assertJsonPath('data.draft_revision', 6);
    }

    public function test_scene_specific_cues_are_published_and_separate_from_global_cues(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'The Cue Archive']);
        $scene = Scene::query()->create(['campaign_id' => $campaign->id, 'name' => 'The Ballroom', 'transition' => 'cut']);
        $audio = CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'waltz.mp3', 'kind' => 'audio', 'declared_mime' => 'audio/mpeg', 'byte_size' => 10, 'upload_status' => CampaignAsset::STATUS_READY]);

        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/audio-cues", [
            'command_id' => (string) Str::uuid7(), 'expected_revision' => 1, 'name' => 'Ballroom waltz', 'asset_id' => $audio->id,
            'scene_id' => $scene->id, 'kind' => 'music', 'loop' => true, 'default_volume' => 65,
        ])->assertCreated()->assertJsonPath('data.scene_id', $scene->id);

        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/studio")
            ->assertOk()
            ->assertJsonPath('data.records.audio_cues.0.scene_id', $scene->id);

        $revision = $this->postJson("/api/control/v1/campaigns/{$campaign->id}/publish", ['command_id' => (string) Str::uuid7(), 'expected_revision' => 2])
            ->assertCreated()->json('data');
        $this->getJson("/api/control/v1/campaigns/{$campaign->id}/revisions/{$revision['id']}")
            ->assertOk()
            ->assertJsonPath('data.manifest.audio_cues.0.scene_id', $scene->id);
    }

    public function test_campaign_authoring_reset_removes_campaign_data_but_preserves_control_identity(): void
    {
        $this->authenticateControl();
        $campaign = Campaign::query()->create(['name' => 'Disposable Archive']);
        CampaignAsset::query()->create(['campaign_id' => $campaign->id, 'original_filename' => 'disposable.png', 'kind' => 'image', 'declared_mime' => 'image/png', 'byte_size' => 10, 'storage_key' => 'assets/sha256/disposable', 'upload_status' => CampaignAsset::STATUS_READY]);
        $storage = Mockery::mock(S3MultipartUploadService::class);
        $storage->shouldReceive('delete')->once()->with('assets/sha256/disposable');
        $this->app->instance(S3MultipartUploadService::class, $storage);

        $result = $this->app->make(CampaignAuthoringResetService::class)->reset();

        $this->assertSame(1, $result['campaigns']);
        $this->assertSame(1, $result['deleted_objects']);
        $this->assertDatabaseCount('campaigns', 0);
        $this->assertDatabaseCount('campaign_assets', 0);
        $this->assertDatabaseHas('users', ['email' => config('control.user_email')]);
    }

    private function authenticateControl(): void
    {
        $this->postJson('/api/control/v1/auth/login', ['secret' => 'correct-horse-battery-staple-for-tests'])->assertOk();
    }
}
