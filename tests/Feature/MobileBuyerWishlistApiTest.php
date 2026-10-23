<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MobileBuyerWishlistApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_gets_an_empty_list_and_web_saved_products_with_catalog_fields(): void
    {
        config(['filesystems.disks.public.url' => 'https://cdn.example.test/storage']);

        $buyer = $this->buyer();
        $token = $this->token($buyer);

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/wishlist')
            ->assertOk()
            ->assertExactJson([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 12,
                    'total' => 0,
                    'last_page' => 1,
                    'has_more' => false,
                ],
            ]);

        ['product' => $product, 'variant' => $variant] = $this->productFixture(
            variantAttributes: [
                'price_minor' => 12500,
                'compare_at_price_minor' => 15000,
                'sku' => 'PRIVATE-WISHLIST-SKU',
            ],
        );
        ProductImage::create([
            'product_id' => $product->id,
            'path' => 'products/wishlist-public.jpg',
            'disk' => 'public',
            'alt_text' => 'Public product photo',
            'is_primary' => true,
            'position' => 0,
        ]);
        ProductImage::create([
            'product_id' => $product->id,
            'path' => 'private/seller-registration-document.pdf',
            'disk' => 'local',
            'alt_text' => 'Private seller document',
            'position' => 1,
        ]);

        $this->actingAs($buyer)
            ->postJson('/wishlist/toggle', ['product_id' => $product->id])
            ->assertOk()
            ->assertJsonPath('data.is_wishlisted', true);

        $response = $this->withToken($token)
            ->getJson('/api/mobile/buyer/wishlist')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.product.id', $product->id)
            ->assertJsonPath('data.0.product.name', $product->name)
            ->assertJsonPath('data.0.product.price_minor', 12500)
            ->assertJsonPath('data.0.product.regular_price_minor', 15000)
            ->assertJsonPath('data.0.product.currency', 'PHP')
            ->assertJsonPath('data.0.product.store.name', 'Public Bearly Store')
            ->assertJsonPath('data.0.product.category.name', 'Wishlist Category')
            ->assertJsonPath('data.0.product.in_stock', true)
            ->assertJsonPath('data.0.product.image_url', 'https://cdn.example.test/storage/products/wishlist-public.jpg')
            ->assertJsonMissingPath('data.0.product.variants.0.sku')
            ->assertJsonMissingPath('data.0.product.product_status');

        $this->assertNotEmpty($response->json('data.0.saved_at'));
        $this->assertNotEmpty($response->json('data.0.wishlist_id'));
        $response->assertDontSee('PRIVATE-WISHLIST-SKU');
        $response->assertDontSee('private/seller-registration-document.pdf');
        $this->assertSame(0, Wishlist::query()->where('product_id', $product->id)->whereNull('user_id')->count());
        $this->assertSame(12500, (int) $variant->fresh()->price_minor);
    }

    public function test_put_saves_idempotently_and_mobile_save_is_visible_on_the_web(): void
    {
        $buyer = $this->buyer();
        $otherBuyer = $this->buyer();
        ['product' => $product] = $this->productFixture();
        $token = $this->token($buyer);

        foreach ([1, 2] as $_attempt) {
            $this->withToken($token)
                ->putJson('/api/mobile/buyer/wishlist/'.$product->id, [
                    'user_id' => $otherBuyer->id,
                    'price_minor' => 1,
                ])
                ->assertOk()
                ->assertJsonPath('data.product_id', $product->id)
                ->assertJsonPath('data.is_saved', true);
        }

        $this->assertDatabaseCount('wishlists', 1);
        $this->assertDatabaseHas('wishlists', [
            'user_id' => $buyer->id,
            'product_id' => $product->id,
        ]);
        $this->assertDatabaseMissing('wishlists', [
            'user_id' => $otherBuyer->id,
            'product_id' => $product->id,
        ]);

        $this->actingAs($buyer)
            ->get('/wishlist')
            ->assertOk()
            ->assertSee($product->name);
    }

    public function test_delete_is_idempotent_and_removes_only_the_authenticated_buyers_record(): void
    {
        $buyer = $this->buyer();
        $otherBuyer = $this->buyer();
        ['product' => $product] = $this->productFixture();
        Wishlist::create(['user_id' => $buyer->id, 'product_id' => $product->id]);
        Wishlist::create(['user_id' => $otherBuyer->id, 'product_id' => $product->id]);
        $token = $this->token($buyer);

        $this->withToken($token)
            ->deleteJson('/api/mobile/buyer/wishlist/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.product_id', $product->id)
            ->assertJsonPath('data.is_saved', false);

        $this->withToken($token)
            ->deleteJson('/api/mobile/buyer/wishlist/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.is_saved', false);

        $this->assertDatabaseMissing('wishlists', [
            'user_id' => $buyer->id,
            'product_id' => $product->id,
        ]);
        $this->assertDatabaseHas('wishlists', [
            'user_id' => $otherBuyer->id,
            'product_id' => $product->id,
        ]);

        $this->actingAs($buyer)
            ->get('/wishlist')
            ->assertOk()
            ->assertDontSee($product->name);
    }

    public function test_wishlist_reads_are_isolated_between_buyers(): void
    {
        $buyer = $this->buyer();
        $otherBuyer = $this->buyer();
        ['product' => $product] = $this->productFixture();
        Wishlist::create(['user_id' => $otherBuyer->id, 'product_id' => $product->id]);

        $this->withToken($this->token($buyer))
            ->getJson('/api/mobile/buyer/wishlist')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_hidden_products_are_not_listed_or_newly_saved_but_can_be_unsaved(): void
    {
        $buyer = $this->buyer();
        ['product' => $product] = $this->productFixture();
        Wishlist::create(['user_id' => $buyer->id, 'product_id' => $product->id]);
        $product->update(['product_status' => 'draft']);
        $token = $this->token($buyer);

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/wishlist')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        $this->withToken($token)
            ->putJson('/api/mobile/buyer/wishlist/'.$product->id)
            ->assertNotFound();

        $this->assertDatabaseHas('wishlists', [
            'user_id' => $buyer->id,
            'product_id' => $product->id,
        ]);

        $this->withToken($token)
            ->deleteJson('/api/mobile/buyer/wishlist/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.is_saved', false);

        $this->assertDatabaseMissing('wishlists', [
            'user_id' => $buyer->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_wishlist_rejects_unauthenticated_non_buyer_and_ineligible_accounts(): void
    {
        $this->getJson('/api/mobile/buyer/wishlist')->assertUnauthorized();
        $this->putJson('/api/mobile/buyer/wishlist/1')->assertUnauthorized();
        $this->deleteJson('/api/mobile/buyer/wishlist/1')->assertUnauthorized();

        foreach (['rider', 'seller', 'admin', 'logistics'] as $role) {
            $user = $this->buyer(['role' => $role]);
            $token = $this->token($user);

            $this->withToken($token)
                ->getJson('/api/mobile/buyer/wishlist')
                ->assertForbidden();
            $this->withToken($token)
                ->putJson('/api/mobile/buyer/wishlist/1')
                ->assertForbidden();
            $this->withToken($token)
                ->deleteJson('/api/mobile/buyer/wishlist/1')
                ->assertForbidden();
        }

        $suspendedBuyer = $this->buyer(['status' => 'suspended']);
        $suspendedToken = $this->token($suspendedBuyer);

        $this->withToken($suspendedToken)
            ->getJson('/api/mobile/buyer/wishlist')
            ->assertForbidden();
        $this->assertDatabaseMissing('personal_access_tokens', [
            'token' => hash('sha256', $suspendedToken),
        ]);
    }

    public function test_wishlist_routes_are_rate_limited(): void
    {
        $token = $this->token($this->buyer());

        for ($request = 0; $request < 60; $request++) {
            $this->withToken($token)
                ->getJson('/api/mobile/buyer/wishlist')
                ->assertOk();
        }

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/wishlist')
            ->assertTooManyRequests();
    }

    public function test_wishlist_operations_do_not_change_cart_orders_payments_or_inventory(): void
    {
        $buyer = $this->buyer();
        ['product' => $product, 'variant' => $variant] = $this->productFixture();
        $token = $this->token($buyer);
        $before = [
            'carts' => DB::table('carts')->count(),
            'cart_items' => DB::table('cart_items')->count(),
            'orders' => DB::table('orders')->count(),
            'payments' => DB::table('payments')->count(),
            'inventory_movements' => DB::table('inventory_movements')->count(),
            'stock_on_hand' => (int) $variant->stock_on_hand,
            'stock_reserved' => (int) $variant->stock_reserved,
        ];

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/wishlist')
            ->assertOk();
        $this->withToken($token)
            ->putJson('/api/mobile/buyer/wishlist/'.$product->id)
            ->assertOk();
        $this->withToken($token)
            ->deleteJson('/api/mobile/buyer/wishlist/'.$product->id)
            ->assertOk();

        $this->assertSame($before, [
            'carts' => DB::table('carts')->count(),
            'cart_items' => DB::table('cart_items')->count(),
            'orders' => DB::table('orders')->count(),
            'payments' => DB::table('payments')->count(),
            'inventory_movements' => DB::table('inventory_movements')->count(),
            'stock_on_hand' => (int) $variant->fresh()->stock_on_hand,
            'stock_reserved' => (int) $variant->fresh()->stock_reserved,
        ]);
    }

    public function test_get_wishlist_paginates_with_the_catalog_response_convention(): void
    {
        $buyer = $this->buyer();
        $token = $this->token($buyer);
        $products = [];

        for ($index = 0; $index < 3; $index++) {
            ['product' => $product] = $this->productFixture(
                productAttributes: ['name' => 'Wishlist Product '.$index],
            );
            $products[] = $product;
            Wishlist::create(['user_id' => $buyer->id, 'product_id' => $product->id]);
        }

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/wishlist?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product.id', $products[0]->id);
    }

    private function productFixture(
        array $productAttributes = [],
        array $variantAttributes = [],
        array $storeAttributes = [],
    ): array {
        $category = Category::create([
            'name' => 'Wishlist Category',
            'slug' => 'wishlist-category-'.uniqid(),
            'is_active' => true,
        ]);
        $seller = $this->buyer([
            'role' => 'seller',
            'status' => $storeAttributes['seller_status'] ?? 'active',
        ]);
        $profile = SellerProfile::create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Private Wishlist Seller',
            'approved_category_id' => $category->id,
            'approved_at' => now(),
            'standing_status' => $storeAttributes['standing_status'] ?? 'good_standing',
        ]);
        $store = Store::create([
            'seller_profile_id' => $profile->id,
            'name' => $storeAttributes['name'] ?? 'Public Bearly Store',
            'slug' => 'wishlist-store-'.uniqid(),
            'publication_status' => $storeAttributes['publication_status'] ?? 'published',
        ]);
        $product = Product::create(array_merge([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Wishlist Product '.uniqid(),
            'slug' => 'wishlist-product-'.uniqid(),
            'description' => 'A product saved from the wishlist API.',
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'published_at' => now(),
        ], $productAttributes));
        $variant = ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'sku' => 'PRIVATE-WISHLIST-SKU-'.uniqid(),
            'name' => 'Default option',
            'options' => [],
            'price_minor' => 10000,
            'stock_on_hand' => 5,
            'stock_reserved' => 0,
            'is_active' => true,
            'position' => 1,
        ], $variantAttributes));

        return compact('product', 'variant', 'store', 'category');
    }

    private function buyer(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'buyer',
            'status' => 'active',
        ], $attributes));
    }

    private function token(User $user): string
    {
        return $user->createToken('mobile-wishlist-test')->plainTextToken;
    }
}
