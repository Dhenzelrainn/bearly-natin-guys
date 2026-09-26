<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogisticsPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_logistics_pages_are_available(): void
    {
        $this->get(route('logistics.landing'))->assertOk();
        $this->get(route('logistics.register'))->assertOk();
        $this->get(route('logistics.login'))->assertRedirect(route('login'));
    }

    public function test_every_logistics_workspace_page_is_available_to_active_logistics_users(): void
    {
        $logistics = User::factory()->create([
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
        ]);

        $routes = [
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
            $this->actingAs($logistics)->get(route($route))->assertOk();
        }

        $this->actingAs($logistics)->get(route('logistics.riders.show', 'RA-1048'))
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
