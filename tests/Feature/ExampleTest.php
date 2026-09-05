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

    public function test_buyer_home_and_products_pages_load(): void
    {
        $this->get('/home')->assertStatus(200);
        $this->get('/products')->assertStatus(200);
    }

    public function test_buyer_category_filtered_products_page_loads(): void
    {
        $this->get('/products?category=men-s-apparel')->assertStatus(200);
    }

    public function test_admin_routes_require_authentication(): void
    {
        $response = $this->get('/admin/dashboard');

        $response->assertRedirect('/login');
    }
}
