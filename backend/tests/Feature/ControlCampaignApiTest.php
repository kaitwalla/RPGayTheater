<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AudioCue;
use App\Models\Campaign;
use App\Models\CampaignAsset;
use App\Models\CampaignRevision;
use App\Models\NonPlayerCharacter;
use App\Models\NpcState;
use App\Models\PlayerCharacter;
use App\Models\Scene;
use App\Models\StagePreset;
use App\Services\S3MultipartUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $commandId = (string) Str::uuid7();

        $response = $this->postJson("/api/control/v1/campaigns/{$campaign->id}/publish", [
            'command_id' => $commandId,
            'expected_revision' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.number', 1)
            ->assertJsonPath('meta.replayed', false);

        $revision = CampaignRevision::query()->findOrFail($response->json('data.id'));
        $this->assertSame('The Glass Archive', $revision->manifest['campaign']['name']);
        $this->assertSame(hash('sha256', json_encode($revision->manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), $revision->manifest_hash);

        $this->postJson("/api/control/v1/campaigns/{$campaign->id}/publish", [
            'command_id' => $commandId,
            'expected_revision' => 1,
        ])->assertOk()->assertJsonPath('meta.replayed', true);

        $this->assertDatabaseCount('campaign_revisions', 1);
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

    private function authenticateControl(): void
    {
        $this->postJson('/api/control/v1/auth/login', ['secret' => 'correct-horse-battery-staple-for-tests'])->assertOk();
    }
}
