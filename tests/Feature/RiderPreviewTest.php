<?php

namespace Tests\Feature;

use Tests\TestCase;

class RiderPreviewTest extends TestCase
{
    public function test_every_rider_preview_page_is_available(): void
    {
        $routes = [
            'rider.landing',
            'rider.register',
            'rider.dashboard.pickups',
            'rider.dashboard.deliveries',
            'rider.earnings.index',
            'rider.history.index',
            'rider.messages.index',
            'rider.profile.index',
        ];

        foreach ($routes as $route) {
            $this->get(route($route))->assertOk();
        }

        $this->get(route('rider.login'))->assertRedirect(route('login'));

        $this->get(route('rider.orders.pickup', 'PU-24091'))->assertOk();
        $this->get(route('rider.orders.delivery', 'DL-8412'))->assertOk();
    }

    public function test_rider_registration_requires_the_erp_fields(): void
    {
        $this->post(route('rider.register.submit'), [])->assertSessionHasErrors([
            'logistics_partner',
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
            'vehicle_type',
            'plate_number',
            'or_cr',
            'driver_license',
        ]);
    }
}
