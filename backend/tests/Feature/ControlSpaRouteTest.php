<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ControlSpaRouteTest extends TestCase
{
    public function test_control_deep_links_render_the_spa_shell(): void
    {
        $this->withoutVite();

        foreach ([
            '/control',
            '/control/login',
            '/control/campaigns/campaign-1',
            '/control/campaigns/campaign-1/scenes',
            '/control/campaigns/campaign-1/sessions',
        ] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('RPGays Control')
                ->assertSee('<div id="app"></div>', false);
        }
    }

    public function test_control_spa_fallback_does_not_capture_api_routes(): void
    {
        $this->getJson('/api/control/v1/missing-route')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }

    public function test_spa_shell_renders_public_runtime_pusher_configuration(): void
    {
        $this->withoutVite();
        config()->set('broadcasting.default', 'pusher');
        config()->set('broadcasting.connections.pusher.key', 'public-pusher-key');
        config()->set('broadcasting.connections.pusher.options.cluster', 'us2');

        $response = $this->get('/control')
            ->assertOk()
            ->assertSee('name="rpgays-realtime-config"', false)
            ->assertSee('pusher', false)
            ->assertSee('public-pusher-key', false)
            ->assertSee('us2', false)
            ->assertDontSee('window.RPGAYS_REALTIME_CONFIG', false)
            ->assertDontSee('<script>', false)
            ->assertDontSee('secret', false);

        preg_match('/<meta name="rpgays-realtime-config" content="([^"]+)"/', (string) $response->getContent(), $matches);
        self::assertArrayHasKey(1, $matches);

        $config = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('pusher', $config['broadcaster']);
        self::assertSame('public-pusher-key', $config['key']);
        self::assertSame('us2', $config['cluster']);
    }

    public function test_spa_shell_renders_public_runtime_reverb_configuration(): void
    {
        $this->withoutVite();
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.key', 'server-reverb-key');
        config()->set('broadcasting.connections.reverb.options.host', 'reverb.internal');
        config()->set('broadcasting.connections.reverb.options.port', 8080);
        config()->set('broadcasting.connections.reverb.options.scheme', 'http');
        config()->set('realtime.client.reverb.key', 'public-reverb-key');
        config()->set('realtime.client.reverb.host', 'realtime.example.test');
        config()->set('realtime.client.reverb.port', 443);
        config()->set('realtime.client.reverb.scheme', 'https');

        $response = $this->get('/player')
            ->assertOk()
            ->assertSee('name="rpgays-realtime-config"', false)
            ->assertSee('reverb', false)
            ->assertSee('public-reverb-key', false)
            ->assertSee('realtime.example.test', false)
            ->assertDontSee('window.RPGAYS_REALTIME_CONFIG', false)
            ->assertDontSee('<script>', false)
            ->assertDontSee('secret', false);

        preg_match('/<meta name="rpgays-realtime-config" content="([^"]+)"/', (string) $response->getContent(), $matches);
        self::assertArrayHasKey(1, $matches);

        $config = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('reverb', $config['broadcaster']);
        self::assertSame('public-reverb-key', $config['key']);
        self::assertSame('realtime.example.test', $config['host']);
        self::assertSame(443, $config['port']);
        self::assertSame('https', $config['scheme']);
        self::assertNull($config['cluster']);
    }
}
