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

        $this->get('/control')
            ->assertOk()
            ->assertSee('name="rpgays-realtime-config"', false)
            ->assertSee('pusher', false)
            ->assertSee('public-pusher-key', false)
            ->assertSee('us2', false)
            ->assertDontSee('window.RPGAYS_REALTIME_CONFIG', false)
            ->assertDontSee('<script>', false)
            ->assertDontSee('secret', false);
    }
}
