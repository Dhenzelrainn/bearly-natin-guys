<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ComplianceRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SellerBuyerCatalogIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_publish_persists_a_compliant_product_for_buyer_catalog(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Integration Store',
            'slug' => 'integration-store',
            'contact_email' => $seller->email,
            'contact_phone' => '+639171234567',
            'publication_status' => 'published',
        ]);

        $this->actingAs($seller)
            ->post('/seller/products', [
                'name' => 'Live Integration Shirt',
                'category' => $category->name,
                'description' => 'A persisted seller product.',
                'sku' => 'LIVE-SHIRT-001',
                'price' => '1299.00',
                'discount_percent' => 10,
                'voucher_eligible' => 1,
                'stock' => 4,
                'low_stock_threshold' => 2,
                'option_one_name' => 'Size',
                'option_one_values' => 'Medium',
                'option_two_name' => 'Color',
                'option_two_values' => 'Olive',
                'variants' => [[
                    'label' => 'Medium / Olive',
                    'sku' => 'LIVE-SHIRT-001',
                    'price' => '1299.00',
                    'stock' => 4,
                ]],
                'intent' => 'publish',
            ])
            ->assertRedirect('/seller/products');

        $product = Product::query()->where('store_id', $store->id)->firstOrFail();
        $variant = $product->variants()->firstOrFail();

        $this->assertSame('active', $product->product_status);
        $this->assertSame('clear', $product->compliance_status);
        $this->assertSame(116910, $variant->price_minor);
        $this->assertDatabaseHas('inventory_movements', [
            'variant_id' => $variant->id,
            'quantity_delta' => 4,
            'balance_after' => 4,
        ]);

        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->get('/home')
            ->assertOk()
            ->assertSee('Live Integration Shirt');

        $this->actingAs($buyer)
            ->get('/products?category=men-s-apparel')
            ->assertOk()
            ->assertSee('Live Integration Shirt');

        $this->actingAs($buyer)
            ->get('/products/'.$product->id)
            ->assertOk()
            ->assertSee('Live Integration Shirt')
            ->assertSee('Integration Store');

        $this->actingAs($buyer)
            ->get('/stores/'.$store->slug)
            ->assertOk()
            ->assertSee('Live Integration Shirt');

        $this->actingAs($buyer)
            ->postJson('/cart/add', [
                'product_variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.cart_count', 1);
    }

    public function test_compliance_scan_blocks_a_prohibited_publish(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Compliance Store',
            'slug' => 'compliance-store',
            'publication_status' => 'published',
        ]);
        ComplianceRule::create([
            'code' => 'TEST_BLOCKED_TERM',
            'name' => 'Blocked test term',
            'rule_type' => 'keyword',
            'target_field' => 'name_description',
            'pattern' => 'prohibited weapon',
            'severity' => 'critical',
            'default_action' => 'block',
            'is_active' => true,
        ]);

        $this->actingAs($seller)
            ->post('/seller/products', [
                'name' => 'Prohibited Weapon Listing',
                'category' => $category->name,
                'description' => '',
                'price' => '100.00',
                'stock' => 1,
                'low_stock_threshold' => 1,
                'intent' => 'publish',
            ])
            ->assertRedirect('/seller/products');

        $product = Product::where('store_id', $store->id)->firstOrFail();

        $this->assertSame('blocked', $product->product_status);
        $this->assertSame('blocked', $product->compliance_status);
        $this->assertDatabaseHas('product_violations', [
            'product_id' => $product->id,
            'status' => 'flagged',
            'violation_type' => 'TEST_BLOCKED_TERM',
        ]);
    }

    public function test_seller_publication_requires_readiness_and_persists_visibility(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Publication Store',
            'slug' => 'publication-store',
            'contact_email' => $seller->email,
            'contact_phone' => '+639171234567',
            'description' => 'A complete store description.',
            'logo_path' => 'seller-store/logo.png',
            'banner_path' => 'seller-store/banner.png',
            'publication_status' => 'draft',
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Publication Product',
            'slug' => 'publication-product',
            'product_status' => 'active',
            'compliance_status' => 'clear',
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'PUBLICATION-001',
            'name' => 'Default',
            'price_minor' => 10000,
            'stock_on_hand' => 2,
            'is_active' => true,
        ]);
        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->get('/stores/'.$store->slug)
            ->assertNotFound();

        $this->actingAs($seller)
            ->postJson('/seller/store/publication', ['published' => true])
            ->assertOk()
            ->assertJsonPath('data.published', true);

        $this->assertDatabaseHas('stores', [
            'id' => $store->id,
            'publication_status' => 'published',
        ]);

        $this->actingAs($buyer)
            ->get('/stores/'.$store->slug)
            ->assertOk();
    }

    public function test_buyer_does_not_expose_a_product_without_an_active_variant(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Unavailable Store',
            'slug' => 'unavailable-store',
            'publication_status' => 'published',
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Unavailable Product',
            'slug' => 'unavailable-product',
            'product_status' => 'active',
            'compliance_status' => 'clear',
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'UNAVAILABLE-001',
            'name' => 'Default',
            'price_minor' => 10000,
            'stock_on_hand' => 5,
            'is_active' => false,
        ]);

        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->get('/products/'.$product->id)
            ->assertNotFound();

        $this->actingAs($buyer)
            ->postJson('/wishlist/toggle', ['product_id' => $product->id])
            ->assertNotFound();
    }

    private function category(): Category
    {
        return Category::create([
            'name' => 'Fashion and Apparel',
            'slug' => 'fashion-and-apparel',
        ]);
    }

    private function seller(Category $category): User
    {
        $seller = User::create([
            'name' => 'Integration Seller',
            'first_name' => 'Integration',
            'last_name' => 'Seller',
            'email' => 'seller-'.uniqid().'@example.test',
            'role' => 'seller',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);

        SellerProfile::create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Integration Seller Business',
            'approved_category_id' => $category->id,
            'approved_at' => now(),
        ]);

        return $seller->fresh('sellerProfile');
    }

    private function buyer(): User
    {
        return User::create([
            'name' => 'Integration Buyer',
            'first_name' => 'Integration',
            'last_name' => 'Buyer',
            'email' => 'buyer-'.uniqid().'@example.test',
            'role' => 'buyer',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
    }
}
