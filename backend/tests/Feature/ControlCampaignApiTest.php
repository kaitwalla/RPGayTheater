<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
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

    private function authenticateControl(): void
    {
        $this->postJson('/api/control/v1/auth/login', ['secret' => 'correct-horse-battery-staple-for-tests'])->assertOk();
    }
}
