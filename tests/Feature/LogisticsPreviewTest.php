<?php

namespace Tests\Feature;

use Tests\TestCase;

class LogisticsPreviewTest extends TestCase
{
    public function test_every_logistics_preview_page_is_available(): void
    {
        $routes = [
            'logistics.landing',
            'logistics.register',
            'logistics.dashboard',
            'logistics.riders.index',
            'logistics.pickups.index',
            'logistics.sorting.incoming',
            'logistics.sorting.center',
            'logistics.dispatch.index',
            'logistics.dispatch.monitoring',
            'logistics.reports.index',
            'logistics.messages.index',
            'logistics.profile.index',
        ];

        foreach ($routes as $route) {
            $this->get(route($route))->assertOk();
        }

        $this->get(route('logistics.login'))->assertRedirect(route('login'));

        $this->get(route('logistics.riders.show', 'RA-1048'))
            ->assertOk()
            ->assertSee('Jared Molina');
    }

    public function test_logistics_registration_requires_the_erp_fields(): void
    {
        $this->post(route('logistics.register.submit'), [])
            ->assertSessionHasErrors([
                'first_name',
                'last_name',
                'sex',
                'email',
                'contact_number',
                'birthday',
                'province',
                'municipality',
                'barangay',
                'street',
                'house_number',
                'business_name',
                'valid_id',
                'business_permit',
            ]);
    }
}
