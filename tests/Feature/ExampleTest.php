<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_admin_frontend_preview_routes_are_available(): void
    {
        $this->get('/admin/dashboard')->assertOk();
        $this->get('/admin/transactions')->assertOk();
        $this->get('/admin/payments')->assertOk();
        $this->get('/admin/reports')->assertOk();
    }
}
