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
}
