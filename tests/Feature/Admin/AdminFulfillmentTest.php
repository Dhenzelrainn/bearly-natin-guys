<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_fulfillment_page_renders_persisted_metrics_and_empty_state(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.fulfillment'))
            ->assertOk()
            ->assertSee('Shipment fulfillment')
            ->assertSee('Pending pickup')
            ->assertSee('At sorting center')
            ->assertSee('Exceptions')
            ->assertSee('No shipments recorded')
            ->assertDontSee('Preview only');
    }
}
