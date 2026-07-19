<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignAsset;
use App\Models\CampaignRevision;
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

    private function authenticateControl(): void
    {
        $this->postJson('/api/control/v1/auth/login', ['secret' => 'correct-horse-battery-staple-for-tests'])->assertOk();
    }
}
