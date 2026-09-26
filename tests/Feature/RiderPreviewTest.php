<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_rider_pages_are_available(): void
    {
        $this->get(route('rider.landing'))->assertOk();
        $this->get(route('rider.register'))->assertOk();
        $this->get(route('rider.login'))->assertRedirect(route('login'));
    }

    public function test_every_rider_workspace_page_is_available_to_active_riders(): void
    {
        $rider = User::factory()->create([
            'role' => UserRole::Rider->value,
            'status' => AccountStatus::Active->value,
        ]);

        $routes = [
            'rider.dashboard.pickups',
            'rider.dashboard.deliveries',
            'rider.earnings.index',
            'rider.history.index',
            'rider.messages.index',
            'rider.profile.index',
        ];

        foreach ($routes as $route) {
            $this->actingAs($rider)->get(route($route))->assertOk();
        }

        $this->actingAs($rider)->get(route('rider.orders.pickup', 'PU-24091'))->assertOk();
        $this->actingAs($rider)->get(route('rider.orders.delivery', 'DL-8412'))->assertOk();
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
