<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRevision;
use App\Models\LiveSession;
use App\Models\PresentationDisplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PresentationJoinQrTest extends TestCase
{
    use RefreshDatabase;

    public function test_control_toggles_the_presentation_join_qr_without_changing_the_active_cue(): void
    {
        config()->set('control.secret', 'correct-horse-battery-staple-for-tests');
        $this->postJson('/api/control/v1/auth/login', ['secret' => 'correct-horse-battery-staple-for-tests'])->assertOk();
        $campaign = Campaign::query()->create(['name' => 'The Join Hall']);
        $revision = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => ['schema_version' => 1], 'manifest_hash' => str_repeat('a', 64), 'published_at' => now()]);
        $session = LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh', 'player_code' => 'JOINQR01', 'display_pairing_token_hash' => str_repeat('b', 64), 'status' => 'active']);
        $endpoint = "/api/control/v1/campaigns/{$campaign->id}/sessions/{$session->id}/presentation-state/join-qr";
        $commandId = (string) Str::uuid7();

        $this->patchJson($endpoint, ['command_id' => $commandId, 'expected_revision' => 1, 'show_join_qr' => true])
            ->assertOk()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.state.show_join_qr', true)
            ->assertJsonPath('data.state.scene_id', null);
        $this->patchJson($endpoint, ['command_id' => $commandId, 'expected_revision' => 1, 'show_join_qr' => true])
            ->assertOk()
            ->assertJsonPath('meta.replayed', true)
            ->assertJsonPath('data.revision', 2);

        $display = PresentationDisplay::query()->create(['live_session_id' => $session->id, 'credential_hash' => str_repeat('c', 64), 'paired_at' => now()]);
        $this->withSession(['presentation.display_id' => $display->id])->getJson('/api/presentation/v1/render')
            ->assertOk()
            ->assertJsonPath('data.show_join_qr', true)
            ->assertJsonPath('data.join_code', 'JOINQR01')
            ->assertJsonPath('data.join_url', url('/player?code=JOINQR01'));
    }
}
