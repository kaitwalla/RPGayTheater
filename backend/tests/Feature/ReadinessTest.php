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
}
