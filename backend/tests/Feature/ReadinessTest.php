<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_reports_database_cache_and_storage_health(): void
    {
        Storage::fake('local');

        $this->getJson('/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.cache', 'ok')
            ->assertJsonPath('checks.storage', 'ok');
    }

    public function test_liveness_is_dependency_free_and_request_ids_are_correlated(): void
    {
        $this->withHeader('X-Request-Id', 'release-smoke-42')
            ->getJson('/live')
            ->assertOk()
            ->assertJsonPath('status', 'alive')
            ->assertHeader('X-Request-Id', 'release-smoke-42');

        $response = $this->withHeader('X-Request-Id', 'unsafe request id')
            ->getJson('/live')
            ->assertOk()
            ->assertHeader('X-Request-Id');

        self::assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', (string) $response->headers->get('X-Request-Id'));
    }

    public function test_http_responses_include_the_browser_security_policy(): void
    {
        $this->getJson('/ready')
            ->assertOk()
            ->assertHeader('Content-Security-Policy')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=(), payment=(), usb=()')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_control_authentication_endpoint_matches_the_openapi_contract_sample(): void
    {
        $document = json_decode((string) file_get_contents(base_path('openapi/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('3.1.0', $document['openapi']);
        self::assertTrue($document['paths']['/api/control/v1/auth/login']['post']['requestBody']['required']);
        self::assertArrayHasKey('200', $document['paths']['/api/control/v1/auth/login']['post']['responses']);
        self::assertArrayHasKey('422', $document['paths']['/api/control/v1/auth/login']['post']['responses']);

        $this->getJson('/api/control/v1/auth')->assertOk()->assertJsonStructure(['data' => ['authenticated']]);
        $this->postJson('/api/control/v1/auth/login', [])->assertUnprocessable()->assertJsonStructure(['message']);
    }

    public function test_participant_api_routes_are_all_covered_by_the_openapi_contract(): void
    {
        $document = json_decode((string) file_get_contents(base_path('openapi/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $expectedOperations = [
            '/api/participant/v1/join' => ['post'],
            '/api/participant/v1/resume' => ['post'],
            '/api/participant/v1/roster' => ['get'],
            '/api/participant/v1/player-groups' => ['get'],
            '/api/participant/v1/messages' => ['get', 'post'],
            '/api/participant/v1/polls' => ['get'],
            '/api/participant/v1/polls/{poll}/vote' => ['post'],
            '/api/participant/v1/rolls' => ['get', 'post'],
            '/api/participant/v1/roll-presets' => ['get'],
            '/api/participant/v1/claim' => ['post'],
            '/api/participant/v1/npcs' => ['get'],
            '/api/participant/v1/npcs/{npc}/notes' => ['post'],
            '/api/participant/v1/npc-notes/{note}' => ['patch', 'delete'],
            '/api/participant/v1/map' => ['get'],
            '/api/participant/v1/map/assets/{asset}/read' => ['get'],
            '/api/participant/v1/maps/{map}/progress' => ['get'],
        ];

        $participantPaths = array_filter(array_keys($document['paths']), static fn (string $path): bool => str_starts_with($path, '/api/participant/v1/'));
        sort($participantPaths);
        $expectedPaths = array_keys($expectedOperations);
        sort($expectedPaths);

        self::assertSame($expectedPaths, $participantPaths);
        foreach ($expectedOperations as $path => $methods) {
            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $document['paths'][$path]);
                self::assertArrayHasKey('operationId', $document['paths'][$path][$method]);
                self::assertNotEmpty($document['paths'][$path][$method]['responses']);
            }
        }

        self::assertSame(64, $document['components']['schemas']['ParticipantResumeRequest']['properties']['resume_token']['minLength']);
        self::assertSame(['control', 'player_group'], $document['components']['schemas']['CreateParticipantMessageRequest']['allOf'][1]['properties']['target_type']['enum']);
        self::assertSame(12, $document['components']['schemas']['VoteParticipantPollRequest']['allOf'][1]['properties']['option_ids']['maxItems']);
    }

    public function test_presentation_api_routes_are_all_covered_by_the_openapi_contract(): void
    {
        $document = json_decode((string) file_get_contents(base_path('openapi/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $expectedOperations = [
            '/api/presentation/v1/pair' => ['post'],
            '/api/presentation/v1/state' => ['get'],
            '/api/presentation/v1/render' => ['get'],
            '/api/presentation/v1/assets/{asset}/read' => ['get'],
            '/api/presentation/v1/standby/report' => ['post'],
            '/api/presentation/v1/video/complete' => ['post'],
            '/api/presentation/v1/video/fail' => ['post'],
            '/api/presentation/v1/sfx/complete' => ['post'],
            '/api/presentation/v1/overlays' => ['get'],
        ];

        $presentationPaths = array_filter(array_keys($document['paths']), static fn (string $path): bool => str_starts_with($path, '/api/presentation/v1/'));
        sort($presentationPaths);
        $expectedPaths = array_keys($expectedOperations);
        sort($expectedPaths);

        self::assertSame($expectedPaths, $presentationPaths);
        foreach ($expectedOperations as $path => $methods) {
            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $document['paths'][$path]);
                self::assertArrayHasKey('operationId', $document['paths'][$path][$method]);
                self::assertNotEmpty($document['paths'][$path][$method]['responses']);
            }
        }

        self::assertSame(64, $document['components']['schemas']['PresentationPairRequest']['properties']['token']['minLength']);
        self::assertSame(1, $document['components']['schemas']['PresentationCommandReport']['allOf'][1]['properties']['expected_revision']['minimum']);
        self::assertSame('#/components/responses/StalePresentationStateResponse', $document['paths']['/api/presentation/v1/video/fail']['post']['responses']['409']['$ref']);
    }

    public function test_control_campaign_lifecycle_routes_are_covered_by_the_openapi_contract(): void
    {
        $document = json_decode((string) file_get_contents(base_path('openapi/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $expectedOperations = [
            '/api/control/v1/campaigns' => ['get', 'post'],
            '/api/control/v1/campaigns/import' => ['post'],
            '/api/control/v1/campaigns/{campaign}/revisions' => ['get'],
            '/api/control/v1/campaigns/{campaign}/revisions/{revision}' => ['get'],
            '/api/control/v1/campaigns/{campaign}/revisions/{revision}/package' => ['get'],
            '/api/control/v1/campaigns/{campaign}' => ['patch', 'delete'],
            '/api/control/v1/campaigns/{campaign}/publish' => ['post'],
            '/api/control/v1/campaigns/{campaign}/publish-preflight' => ['get'],
        ];

        foreach ($expectedOperations as $path => $methods) {
            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $document['paths'][$path]);
                self::assertArrayHasKey('operationId', $document['paths'][$path][$method]);
                self::assertNotEmpty($document['paths'][$path][$method]['responses']);
            }
        }

        self::assertSame('multipart/form-data', array_key_first($document['paths']['/api/control/v1/campaigns/import']['post']['requestBody']['content']));
        self::assertSame(1, $document['components']['schemas']['ControlCampaignCommand']['allOf'][1]['properties']['expected_revision']['minimum']);
        self::assertSame('#/components/responses/StaleControlCampaignResponse', $document['paths']['/api/control/v1/campaigns/{campaign}/publish']['post']['responses']['409']['$ref']);
        self::assertSame('binary', $document['paths']['/api/control/v1/campaigns/{campaign}/revisions/{revision}/package']['get']['responses']['200']['content']['application/zip']['schema']['format']);
    }

    public function test_control_asset_pipeline_routes_are_covered_by_the_openapi_contract(): void
    {
        $document = json_decode((string) file_get_contents(base_path('openapi/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $expectedOperations = [
            '/api/control/v1/campaigns/{campaign}/assets' => ['get'],
            '/api/control/v1/campaigns/{campaign}/assets/uploads' => ['post'],
            '/api/control/v1/campaigns/{campaign}/assets/{asset}/complete' => ['post'],
            '/api/control/v1/campaigns/{campaign}/assets/{asset}/read' => ['get'],
            '/api/control/v1/campaigns/{campaign}/assets/{asset}' => ['delete'],
        ];

        foreach ($expectedOperations as $path => $methods) {
            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $document['paths'][$path]);
                self::assertArrayHasKey('operationId', $document['paths'][$path][$method]);
                self::assertNotEmpty($document['paths'][$path][$method]['responses']);
            }
        }

        self::assertSame(['image', 'audio', 'video'], $document['components']['schemas']['InitiateControlAssetUploadRequest']['allOf'][1]['properties']['kind']['enum']);
        self::assertSame(10000, $document['components']['schemas']['CompleteControlAssetUploadRequest']['allOf'][1]['properties']['parts']['maxItems']);
        self::assertSame('#/components/responses/SignedUrlResponse', $document['paths']['/api/control/v1/campaigns/{campaign}/assets/{asset}/read']['get']['responses']['200']['$ref']);
        self::assertSame('#/components/responses/StaleControlCampaignResponse', $document['paths']['/api/control/v1/campaigns/{campaign}/assets/uploads']['post']['responses']['409']['$ref']);
    }

    public function test_control_character_and_npc_authoring_routes_are_covered_by_the_openapi_contract(): void
    {
        $document = json_decode((string) file_get_contents(base_path('openapi/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $expectedOperations = [
            '/api/control/v1/campaigns/{campaign}/player-characters' => ['get', 'post'],
            '/api/control/v1/campaigns/{campaign}/npcs' => ['get', 'post'],
            '/api/control/v1/campaigns/{campaign}/npcs/{npc}/states' => ['get', 'post'],
        ];

        foreach ($expectedOperations as $path => $methods) {
            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $document['paths'][$path]);
                self::assertArrayHasKey('operationId', $document['paths'][$path][$method]);
                self::assertNotEmpty($document['paths'][$path][$method]['responses']);
            }
        }

        self::assertSame(500, $document['components']['schemas']['CreateControlPlayerCharacterRequest']['allOf'][1]['properties']['public_description']['maxLength']);
        self::assertSame(['left', 'right'], $document['components']['schemas']['CreateControlNpcRequest']['allOf'][1]['properties']['native_facing']['enum']);
        self::assertSame('#/components/responses/StaleControlCampaignResponse', $document['paths']['/api/control/v1/campaigns/{campaign}/npcs/{npc}/states']['post']['responses']['409']['$ref']);
    }

    public function test_control_media_and_stage_authoring_routes_are_covered_by_the_openapi_contract(): void
    {
        $document = json_decode((string) file_get_contents(base_path('openapi/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $expectedOperations = [
            '/api/control/v1/campaigns/{campaign}/audio-cues' => ['get', 'post'],
            '/api/control/v1/campaigns/{campaign}/video-cues' => ['get', 'post'],
            '/api/control/v1/campaigns/{campaign}/dice-presets' => ['get', 'post'],
            '/api/control/v1/campaigns/{campaign}/scenes' => ['get', 'post'],
            '/api/control/v1/campaigns/{campaign}/scenes/{scene}/backdrops' => ['get', 'post'],
            '/api/control/v1/campaigns/{campaign}/stage-presets' => ['get', 'post'],
            '/api/control/v1/campaigns/{campaign}/stage-presets/{stagePreset}/entries' => ['get', 'post'],
        ];

        foreach ($expectedOperations as $path => $methods) {
            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $document['paths'][$path]);
                self::assertArrayHasKey('operationId', $document['paths'][$path][$method]);
                self::assertNotEmpty($document['paths'][$path][$method]['responses']);
            }
        }

        self::assertSame(['music', 'sfx'], $document['components']['schemas']['CreateControlAudioCueRequest']['allOf'][1]['properties']['kind']['enum']);
        self::assertSame(['restore_captured_scene', 'enter_target_scene'], $document['components']['schemas']['CreateControlVideoCueRequest']['allOf'][1]['properties']['completion_mode']['enum']);
        self::assertSame(['public', 'private'], $document['components']['schemas']['CreateControlDicePresetRequest']['allOf'][1]['properties']['default_visibility']['enum']);
        self::assertSame(30000, $document['components']['schemas']['CreateControlSceneRequest']['allOf'][1]['properties']['transition_duration_ms']['maximum']);
        self::assertSame(0.1, $document['components']['schemas']['CreateControlStagePresetEntryRequest']['allOf'][1]['properties']['scale']['minimum']);
        self::assertSame('#/components/responses/StaleControlCampaignResponse', $document['paths']['/api/control/v1/campaigns/{campaign}/stage-presets/{stagePreset}/entries']['post']['responses']['409']['$ref']);
    }

    public function test_control_map_authoring_routes_are_covered_by_the_openapi_contract(): void
    {
        $document = json_decode((string) file_get_contents(base_path('openapi/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $expectedOperations = [
            '/api/control/v1/campaigns/{campaign}/maps' => ['get', 'post'],
            '/api/control/v1/campaigns/{campaign}/maps/{map}/fog-mask' => ['get', 'put'],
            '/api/control/v1/campaigns/{campaign}/maps/{map}/tokens' => ['get', 'post'],
        ];

        foreach ($expectedOperations as $path => $methods) {
            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $document['paths'][$path]);
                self::assertArrayHasKey('operationId', $document['paths'][$path][$method]);
                self::assertNotEmpty($document['paths'][$path][$method]['responses']);
            }
        }

        self::assertSame(['pc', 'npc', 'custom'], $document['components']['schemas']['CreateControlMapTokenRequest']['allOf'][1]['properties']['token_type']['enum']);
        self::assertSame(0.1, $document['components']['schemas']['CreateControlMapTokenRequest']['allOf'][1]['properties']['scale']['minimum']);
        self::assertSame('null', $document['components']['schemas']['ControlMapFogMaskResponse']['properties']['data']['anyOf'][1]['type']);
        self::assertSame('#/components/responses/StaleControlCampaignResponse', $document['paths']['/api/control/v1/campaigns/{campaign}/maps/{map}/fog-mask']['put']['responses']['409']['$ref']);
    }

    public function test_control_live_session_lifecycle_routes_are_covered_by_the_openapi_contract(): void
    {
        $document = json_decode((string) file_get_contents(base_path('openapi/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $expectedOperations = [
            '/api/control/v1/campaigns/{campaign}/sessions' => ['get', 'post'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/revisions/{revision}/preflight' => ['get'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/adopt-revision' => ['post'],
        ];

        foreach ($expectedOperations as $path => $methods) {
            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $document['paths'][$path]);
                self::assertArrayHasKey('operationId', $document['paths'][$path][$method]);
                self::assertNotEmpty($document['paths'][$path][$method]['responses']);
            }
        }

        self::assertSame(['fresh', 'resume'], $document['components']['schemas']['CreateControlLiveSessionRequest']['allOf'][1]['properties']['progress_mode']['enum']);
        self::assertSame(['pending', 'active', 'ended'], $document['components']['schemas']['ControlLiveSession']['properties']['status']['enum']);
        self::assertSame(64, $document['components']['schemas']['ControlLiveSession']['properties']['display_pairing_token']['minLength']);
        self::assertSame(['from_revision_id', 'to_revision_id', 'compatible', 'blockers', 'changes'], $document['components']['schemas']['ControlLiveSessionRevisionPreflight']['required']);
        self::assertSame('#/components/responses/ControlLiveSessionRevisionAdoptionResponse', $document['paths']['/api/control/v1/campaigns/{campaign}/sessions/{session}/adopt-revision']['post']['responses']['200']['$ref']);
    }

    public function test_control_presentation_state_routes_are_covered_by_the_openapi_contract(): void
    {
        $document = json_decode((string) file_get_contents(base_path('openapi/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $expectedOperations = [
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/presentation-state' => ['get', 'put'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/presentation-state/standby' => ['post'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/presentation-state/go' => ['post'],
        ];

        foreach ($expectedOperations as $path => $methods) {
            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $document['paths'][$path]);
                self::assertArrayHasKey('operationId', $document['paths'][$path][$method]);
                self::assertNotEmpty($document['paths'][$path][$method]['responses']);
            }
        }

        self::assertSame(64, $document['components']['schemas']['ControlPresentationTargetState']['properties']['sfx_instances']['maxItems']);
        self::assertSame(30000, $document['components']['schemas']['ControlPresentationTargetState']['properties']['music_playback']['properties']['fade_duration_ms']['maximum']);
        self::assertSame(['left', 'right'], $document['components']['schemas']['ControlPresentationStageEntry']['properties']['facing']['enum']);
        self::assertSame('#/components/responses/StalePresentationStateResponse', $document['paths']['/api/control/v1/campaigns/{campaign}/sessions/{session}/presentation-state/go']['post']['responses']['409']['$ref']);
    }

    public function test_control_overlay_routes_are_covered_by_the_openapi_contract(): void
    {
        $document = json_decode((string) file_get_contents(base_path('openapi/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $expectedOperations = [
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/overlays' => ['get', 'post'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/overlays/{overlay}' => ['patch'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/overlays/{lane}/advance' => ['post'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/overlays/{lane}/dismiss' => ['post'],
        ];

        foreach ($expectedOperations as $path => $methods) {
            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $document['paths'][$path]);
                self::assertArrayHasKey('operationId', $document['paths'][$path][$method]);
                self::assertNotEmpty($document['paths'][$path][$method]['responses']);
            }
        }

        self::assertSame(['corner', 'full'], $document['components']['schemas']['EnqueueControlOverlayRequest']['allOf'][1]['properties']['placement']['enum']);
        self::assertSame(4000, $document['components']['schemas']['EnqueueControlOverlayRequest']['allOf'][1]['properties']['content']['maxLength']);
        self::assertSame(300, $document['components']['schemas']['UpdateControlOverlayRequest']['allOf'][1]['properties']['duration_seconds']['maximum']);
        self::assertSame('#/components/responses/StaleControlOverlayStateResponse', $document['paths']['/api/control/v1/campaigns/{campaign}/sessions/{session}/overlays/{lane}/advance']['post']['responses']['409']['$ref']);
    }

    public function test_control_live_map_routes_are_covered_by_the_openapi_contract(): void
    {
        $document = json_decode((string) file_get_contents(base_path('openapi/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $expectedOperations = [
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/player-map' => ['get', 'put'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/maps/{map}/progress' => ['get', 'put'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/maps/{map}/progress/reset' => ['post'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/maps/{map}/progress/fog' => ['post'],
        ];

        foreach ($expectedOperations as $path => $methods) {
            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $document['paths'][$path]);
                self::assertArrayHasKey('operationId', $document['paths'][$path][$method]);
                self::assertNotEmpty($document['paths'][$path][$method]['responses']);
            }
        }

        self::assertSame(['reveal', 'hide'], $document['components']['schemas']['ApplyControlMapFogBrushRequest']['allOf'][1]['properties']['mode']['enum']);
        self::assertSame(0.005, $document['components']['schemas']['ApplyControlMapFogBrushRequest']['allOf'][1]['properties']['radius']['minimum']);
        self::assertSame(5000, $document['components']['schemas']['ControlMapFog']['properties']['brushes']['maxItems']);
        self::assertSame('#/components/responses/StaleControlPlayerMapStateResponse', $document['paths']['/api/control/v1/campaigns/{campaign}/sessions/{session}/player-map']['put']['responses']['409']['$ref']);
        self::assertSame('#/components/responses/StaleControlMapProgressResponse', $document['paths']['/api/control/v1/campaigns/{campaign}/sessions/{session}/maps/{map}/progress/fog']['post']['responses']['409']['$ref']);
    }

    public function test_control_session_participant_and_group_routes_are_covered_by_the_openapi_contract(): void
    {
        $document = json_decode((string) file_get_contents(base_path('openapi/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $expectedOperations = [
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/participants' => ['get'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/participants/{participant}/claim' => ['delete'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/participants/{participant}' => ['delete'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/player-groups' => ['get', 'post'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/player-groups/{group}/members/{participant}' => ['put', 'delete'],
        ];

        foreach ($expectedOperations as $path => $methods) {
            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $document['paths'][$path]);
                self::assertArrayHasKey('operationId', $document['paths'][$path][$method]);
                self::assertNotEmpty($document['paths'][$path][$method]['responses']);
            }
        }

        self::assertSame(['player', 'spectator'], $document['components']['schemas']['ControlSessionParticipant']['properties']['role']['enum']);
        self::assertSame(120, $document['components']['schemas']['CreateControlSessionPlayerGroupRequest']['allOf'][1]['properties']['name']['maxLength']);
        self::assertSame('uuid', $document['components']['parameters']['SessionParticipantId']['schema']['format']);
        self::assertSame('uuid', $document['components']['parameters']['SessionPlayerGroupId']['schema']['format']);
        self::assertSame('#/components/responses/ControlSessionPlayerGroupMutationResponse', $document['paths']['/api/control/v1/campaigns/{campaign}/sessions/{session}/player-groups/{group}/members/{participant}']['delete']['responses']['200']['$ref']);
        self::assertArrayHasKey('204', $document['paths']['/api/control/v1/campaigns/{campaign}/sessions/{session}/participants/{participant}']['delete']['responses']);
    }

    public function test_control_session_collaboration_routes_are_covered_by_the_openapi_contract(): void
    {
        $document = json_decode((string) file_get_contents(base_path('openapi/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $expectedOperations = [
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/messages' => ['get', 'post'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/messages/{message}/publish-spectator-reply' => ['post'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/polls' => ['get', 'post'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/polls/{poll}/close' => ['post'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/polls/{poll}/publish-results' => ['post'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/rolls' => ['get'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/rolls/{roll}/reveal' => ['post'],
        ];

        foreach ($expectedOperations as $path => $methods) {
            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $document['paths'][$path]);
                self::assertArrayHasKey('operationId', $document['paths'][$path][$method]);
                self::assertNotEmpty($document['paths'][$path][$method]['responses']);
            }
        }

        self::assertSame(['individual', 'player_group', 'all_players', 'all_spectators', 'all'], $document['components']['schemas']['CreateControlSessionMessageRequest']['allOf'][1]['properties']['target_type']['enum']);
        self::assertSame(2000, $document['components']['schemas']['CreateControlSessionMessageRequest']['allOf'][1]['properties']['body']['maxLength']);
        self::assertSame(2, $document['components']['schemas']['CreateControlSessionPollRequest']['allOf'][1]['properties']['options']['minItems']);
        self::assertSame(12, $document['components']['schemas']['CreateControlSessionPollRequest']['allOf'][1]['properties']['options']['maxItems']);
        self::assertSame(['live', 'final'], $document['components']['schemas']['PublishControlSessionPollResultsRequest']['allOf'][1]['properties']['visibility']['enum']);
        self::assertSame('integer', $document['components']['schemas']['ControlSessionPollOption']['properties']['votes']['type']);
        self::assertSame(['public', 'private'], $document['components']['schemas']['ControlSessionRoll']['properties']['visibility']['enum']);
        self::assertSame('#/components/responses/ControlSessionRollMutationResponse', $document['paths']['/api/control/v1/campaigns/{campaign}/sessions/{session}/rolls/{roll}/reveal']['post']['responses']['200']['$ref']);
    }

    public function test_control_session_npc_disclosure_routes_are_covered_by_the_openapi_contract(): void
    {
        $document = json_decode((string) file_get_contents(base_path('openapi/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $expectedOperations = [
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/npc-reveals' => ['get'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/npc-reveals/{npc}' => ['put'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/npc-notes' => ['get'],
            '/api/control/v1/campaigns/{campaign}/sessions/{session}/npc-notes/{note}' => ['patch', 'delete'],
        ];

        foreach ($expectedOperations as $path => $methods) {
            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $document['paths'][$path]);
                self::assertArrayHasKey('operationId', $document['paths'][$path][$method]);
                self::assertNotEmpty($document['paths'][$path][$method]['responses']);
            }
        }

        self::assertSame('boolean', $document['components']['schemas']['SetControlSessionNpcRevealRequest']['allOf'][1]['properties']['is_revealed']['type']);
        self::assertSame(2000, $document['components']['schemas']['UpdateControlSessionNpcNoteRequest']['allOf'][1]['properties']['body']['maxLength']);
        self::assertSame(['control', 'participant'], $document['components']['schemas']['ControlSessionNpcNote']['properties']['author_type']['enum']);
        self::assertSame('null', $document['components']['schemas']['ControlSessionNpcReveal']['properties']['revealed_at']['type'][1]);
        self::assertSame('#/components/responses/ControlSessionNpcNoteMutationResponse', $document['paths']['/api/control/v1/campaigns/{campaign}/sessions/{session}/npc-notes/{note}']['delete']['responses']['200']['$ref']);
    }

    public function test_control_operational_routes_are_covered_by_the_openapi_contract(): void
    {
        $document = json_decode((string) file_get_contents(base_path('openapi/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $expectedOperations = [
            '/api/control/v1/passkeys' => ['get'],
            '/api/control/v1/realtime/status' => ['get'],
        ];

        foreach ($expectedOperations as $path => $methods) {
            foreach ($methods as $method) {
                self::assertArrayHasKey($method, $document['paths'][$path]);
                self::assertArrayHasKey('operationId', $document['paths'][$path][$method]);
                self::assertNotEmpty($document['paths'][$path][$method]['responses']);
            }
        }

        self::assertSame('null', $document['components']['schemas']['ControlPasskey']['properties']['last_used_at']['type'][1]);
        self::assertSame(0, $document['components']['schemas']['ControlRealtimeDeliveryStatus']['properties']['pending_count']['minimum']);
        self::assertSame('null', $document['components']['schemas']['ControlRealtimeDeliveryStatus']['properties']['latest_error']['type'][1]);
        self::assertSame('#/components/responses/ControlRealtimeDeliveryStatusResponse', $document['paths']['/api/control/v1/realtime/status']['get']['responses']['200']['$ref']);
    }
}
