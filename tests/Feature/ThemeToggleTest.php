<?php

namespace Tests\Feature;

use Tests\TestCase;

class ThemeToggleTest extends TestCase
{
    public function test_public_auth_and_partner_portal_pages_render_the_theme_toggle(): void
    {
        foreach ([
            route('shop.home'),
            route('about'),
            route('contact'),
            route('login'),
            route('rider.landing'),
            route('logistics.landing'),
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('data-theme-toggle', false)
                ->assertSee('role="switch"', false)
                ->assertSee('bearly-theme');
        }
    }
}
