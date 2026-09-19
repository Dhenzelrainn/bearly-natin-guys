<?php

namespace Tests\Feature;

use Tests\TestCase;

class LogisticsPreviewTest extends TestCase
{
    public function test_every_logistics_preview_page_is_available(): void
    {
        $routes = [
            'logistics.landing',
            'logistics.login',
            'logistics.register',
            'logistics.dashboard',
            'logistics.riders',
            'logistics.pickups',
            'logistics.incoming',
            'logistics.sorting',
            'logistics.dispatch',
            'logistics.monitoring',
            'logistics.reports',
            'logistics.messages',
            'logistics.account',
        ];

        foreach ($routes as $route) {
            $this->get(route($route))->assertOk();
        }
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
