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

    public function test_rider_uses_shared_registration_header_while_logistics_keeps_original_landing_layout(): void
    {
        $this->get(route('rider.landing'))
            ->assertOk()
            ->assertSee('data-module="rider"', false)
            ->assertSee('data-registration-header', false)
            ->assertSee('registration-brand-divider', false)
            ->assertSee('Rider portal')
            ->assertSee('data-theme-toggle', false);

        $this->get(route('logistics.landing'))
            ->assertOk()
            ->assertSee('class="logistics-public-landing"', false)
            ->assertDontSee('data-registration-header', false)
            ->assertDontSee('registration-brand-divider', false)
            ->assertSee('Logistics Partner Portal')
            ->assertSee('portal-theme-toggle', false)
            ->assertSee('data-theme-toggle', false);
    }
}
