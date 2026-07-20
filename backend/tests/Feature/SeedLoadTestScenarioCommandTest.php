<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRevision;
use App\Models\LiveSession;
use App\Models\OverlayState;
use App\Models\PresentationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedLoadTestScenarioCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_load_test_seed_command_creates_an_idempotent_active_fixture(): void
    {
        $this->artisan('load-test:seed')->assertSuccessful();
        $this->artisan('load-test:seed')->assertSuccessful();

        $campaign = Campaign::query()->sole();
        $revision = CampaignRevision::query()->sole();
        $session = LiveSession::query()->sole();

        self::assertSame('Load test campaign', $campaign->name);
        self::assertSame($campaign->id, $revision->campaign_id);
        self::assertSame($revision->id, $session->campaign_revision_id);
        self::assertSame('LOADTEST', $session->player_code);
        self::assertSame('active', $session->status);
        self::assertTrue(PresentationState::query()->where('live_session_id', $session->id)->exists());
        self::assertTrue(OverlayState::query()->where('live_session_id', $session->id)->exists());
    }
}
