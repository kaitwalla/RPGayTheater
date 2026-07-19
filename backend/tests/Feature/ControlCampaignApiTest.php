<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    private function authenticateControl(): void
    {
        $this->postJson('/api/control/v1/auth/login', ['secret' => 'correct-horse-battery-staple-for-tests'])->assertOk();
    }
}
