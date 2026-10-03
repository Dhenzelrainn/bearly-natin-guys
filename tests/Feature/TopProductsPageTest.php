<?php

namespace Tests\Feature;

use Tests\TestCase;

class TopProductsPageTest extends TestCase
{
    public function test_top_products_page_loads_without_add_to_cart_buttons(): void
    {
        $response = $this->get('/top-products');

        $response->assertOk();
        $response->assertSee('Top Products');
        $response->assertDontSee('Add to Cart');
    }

    public function test_legacy_category_slug_still_loads_product_page(): void
    {
        $response = $this->get('/products?category=mens-apparel');

        $response->assertOk();
        $response->assertSee('Men');
        $response->assertSee('Apparel');
    }
}
