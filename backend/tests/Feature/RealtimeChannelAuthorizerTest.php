<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignRevision;
use App\Models\LiveSession;
use App\Models\PresentationDisplay;
use App\Models\SessionParticipant;
use App\Models\User;
use App\Services\RealtimeChannelAuthorizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Str;
use Tests\TestCase;

class RealtimeChannelAuthorizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_authenticates_only_active_display_and_participant_sessions_for_their_live_session(): void
    {
        $session = $this->liveSession();
        $otherSession = $this->liveSession();
        $display = PresentationDisplay::query()->create(['live_session_id' => $session->id, 'credential_hash' => hash('sha256', 'display'), 'paired_at' => now()]);
        $participant = SessionParticipant::query()->create(['live_session_id' => $session->id, 'role' => 'player', 'display_name' => 'Ari', 'display_name_normalized' => 'ari', 'resume_token_hash' => hash('sha256', 'participant')]);
        $authorizer = $this->app->make(RealtimeChannelAuthorizer::class);

        $anonymous = $this->request();
        self::assertNull($authorizer->principal($anonymous));
        self::assertFalse($authorizer->presentation($anonymous, $session->id));
        self::assertFalse($authorizer->participant($anonymous, $session->id));

        $displayRequest = $this->request(['presentation.display_id' => $display->id]);
        self::assertSame('presentation:'.$display->id, $authorizer->principal($displayRequest)?->getAuthIdentifierForBroadcasting());
        self::assertTrue($authorizer->presentation($displayRequest, $session->id));
        self::assertFalse($authorizer->presentation($displayRequest, $otherSession->id));
        self::assertTrue($authorizer->session($displayRequest, $session->id));

        $participantRequest = $this->request(['participant.id' => $participant->id]);
        self::assertSame('participant:'.$participant->id, $authorizer->principal($participantRequest)?->getAuthIdentifier());
        self::assertTrue($authorizer->participant($participantRequest, $session->id));
        self::assertFalse($authorizer->participant($participantRequest, $otherSession->id));
        self::assertTrue($authorizer->session($participantRequest, $session->id));

        $display->update(['revoked_at' => now()]);
        $participant->update(['revoked_at' => now()]);
        self::assertNull($authorizer->principal($displayRequest));
        self::assertNull($authorizer->principal($participantRequest));
        self::assertFalse($authorizer->session($displayRequest, $session->id));
        self::assertFalse($authorizer->session($participantRequest, $session->id));
    }

    public function test_control_principal_can_authorize_every_realtime_session_channel(): void
    {
        $session = $this->liveSession();
        $this->actingAs(User::factory()->create(['email' => config('control.user_email')]));
        $authorizer = $this->app->make(RealtimeChannelAuthorizer::class);
        $request = $this->request();

        self::assertSame('control', $authorizer->principal($request)?->getAuthIdentifier());
        self::assertTrue($authorizer->controls($request));
        self::assertTrue($authorizer->presentation($request, $session->id));
        self::assertTrue($authorizer->participant($request, $session->id));
        self::assertTrue($authorizer->session($request, $session->id));
    }

    /** @param array<string, string> $sessionValues */
    private function request(array $sessionValues = []): Request
    {
        $session = new Store('realtime-test', new ArraySessionHandler(120), Str::random(40));
        $session->start();
        $session->put($sessionValues);
        $request = Request::create('/broadcasting/auth', 'POST');
        $request->setLaravelSession($session);

        return $request;
    }

    private function liveSession(): LiveSession
    {
        $campaign = Campaign::query()->create(['name' => 'Realtime authorization fixture '.Str::random(8)]);
        $revision = CampaignRevision::query()->create(['campaign_id' => $campaign->id, 'number' => 1, 'manifest' => ['schema_version' => 1], 'manifest_hash' => hash('sha256', $campaign->id), 'published_at' => now()]);

        return LiveSession::query()->create(['campaign_id' => $campaign->id, 'campaign_revision_id' => $revision->id, 'progress_mode' => 'fresh', 'player_code' => strtoupper(Str::random(8)), 'display_pairing_token_hash' => hash('sha256', Str::random(20)), 'status' => 'active']);
    }
}
