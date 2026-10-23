<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileBuyerCartApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_buyer_can_retrieve_an_empty_cart_without_creating_one(): void
    {
        $buyer = $this->buyer();

        $this->withToken($this->token($buyer))
            ->getJson('/api/mobile/buyer/cart')
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.cart_count', 0)
            ->assertJsonPath('data.subtotal_minor', 0)
            ->assertJsonPath('data.currency', 'PHP');

        $this->assertDatabaseCount('carts', 0);
    }

    public function test_buyer_reads_existing_web_cart_with_public_product_and_variant_details(): void
    {
        config(['filesystems.disks.public.url' => 'https://cdn.example.test/storage']);
        $buyer = $this->buyer();
        ['product' => $product, 'variant' => $variant] = $this->productFixture(
            variantAttributes: [
                'name' => 'Honey / Large',
                'options' => ['Color' => 'Honey', 'Size' => 'Large'],
                'price_minor' => 12500,
                'stock_on_hand' => 5,
                'stock_reserved' => 2,
            ],
        );
        ProductImage::create([
            'product_id' => $product->id,
            'path' => 'products/cart-bear.jpg',
            'disk' => 'public',
            'purpose' => 'primary',
            'is_primary' => true,
            'position' => 0,
        ]);
        $cart = Cart::create(['user_id' => $buyer->id, 'status' => 'active']);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
            'selected' => true,
        ]);

        $this->withToken($this->token($buyer))
            ->getJson('/api/mobile/buyer/cart')
            ->assertOk()
            ->assertJsonPath('data.cart_count', 2)
            ->assertJsonPath('data.subtotal_minor', 25000)
            ->assertJsonPath('data.currency', 'PHP')
            ->assertJsonPath('data.items.0.id', CartItem::query()->value('id'))
            ->assertJsonPath('data.items.0.product_id', $product->id)
            ->assertJsonPath('data.items.0.product.id', $product->id)
            ->assertJsonPath('data.items.0.product.name', $product->name)
            ->assertJsonPath('data.items.0.product_variant_id', $variant->id)
            ->assertJsonPath('data.items.0.variant.id', $variant->id)
            ->assertJsonPath('data.items.0.variant.name', 'Honey / Large')
            ->assertJsonPath('data.items.0.variant.options.Size', 'Large')
            ->assertJsonPath('data.items.0.unit_price_minor', 12500)
            ->assertJsonPath('data.items.0.line_subtotal_minor', 25000)
            ->assertJsonPath('data.items.0.available_stock', 3)
            ->assertJsonPath('data.items.0.in_stock', true)
            ->assertJsonPath('data.items.0.is_available', true)
            ->assertJsonPath('data.items.0.store_name', 'Public Bearly Store')
            ->assertJsonPath('data.items.0.image_url', 'https://cdn.example.test/storage/products/cart-bear.jpg')
            ->assertJsonMissingPath('data.items.0.variant.sku')
            ->assertJsonMissing(['legal_business_name' => 'Private Legal Seller Name']);
    }

    public function test_mobile_add_merges_repeated_variant_and_uses_web_cart_records_and_server_price(): void
    {
        $buyer = $this->buyer();
        ['variant' => $variant] = $this->productFixture(
            variantAttributes: [
                'price_minor' => 17800,
                'stock_on_hand' => 8,
                'stock_reserved' => 1,
            ],
        );
        $token = $this->token($buyer);

        $this->withToken($token)
            ->postJson('/api/mobile/buyer/cart/items', [
                'product_variant_id' => $variant->id,
                'quantity' => 1,
                'price_minor' => 1,
                'subtotal_minor' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.cart_count', 1)
            ->assertJsonPath('data.items.0.price_minor', 17800)
            ->assertJsonPath('data.items.0.line_subtotal_minor', 17800);

        $this->withToken($token)
            ->postJson('/api/mobile/buyer/cart/items', [
                'product_variant_id' => $variant->id,
                'quantity' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.cart_count', 3)
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonPath('data.items.0.unit_price_minor', 17800)
            ->assertJsonPath('data.items.0.line_subtotal_minor', 53400)
            ->assertJsonPath('data.subtotal_minor', 53400)
            ->assertJsonPath('data.item.quantity', 3);

        $this->assertDatabaseCount('carts', 1);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseHas('cart_items', [
            'cart_id' => Cart::query()->where('user_id', $buyer->id)->value('id'),
            'product_variant_id' => $variant->id,
            'quantity' => 3,
        ]);
        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'stock_on_hand' => 8,
            'stock_reserved' => 1,
        ]);

        $this->actingAs($buyer)
            ->getJson('/cart/data')
            ->assertOk()
            ->assertJsonPath('data.cart_count', 3)
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonPath('data.subtotal_minor', 53400);
    }

    public function test_buyer_can_update_and_remove_only_items_in_their_own_cart(): void
    {
        $buyer = $this->buyer();
        $otherBuyer = $this->buyer();
        ['variant' => $variant] = $this->productFixture();
        $ownItem = $this->cartItem($buyer, $variant, 1);
        $otherItem = $this->cartItem($otherBuyer, $variant, 2);
        $token = $this->token($buyer);

        $this->withToken($token)
            ->patchJson('/api/mobile/buyer/cart/items/'.$ownItem->id, ['quantity' => 2])
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.item.quantity', 2)
            ->assertJsonPath('data.cart_count', 2);

        $this->withToken($token)
            ->patchJson('/api/mobile/buyer/cart/items/'.$otherItem->id, ['quantity' => 1])
            ->assertNotFound();

        $this->withToken($token)
            ->deleteJson('/api/mobile/buyer/cart/items/'.$otherItem->id)
            ->assertNotFound();

        $this->withToken($token)
            ->deleteJson('/api/mobile/buyer/cart/items/'.$ownItem->id)
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.cart_count', 0)
            ->assertJsonPath('data.subtotal_minor', 0);

        $this->assertDatabaseMissing('cart_items', ['id' => $ownItem->id]);
        $this->assertDatabaseHas('cart_items', ['id' => $otherItem->id, 'quantity' => 2]);
    }

    public function test_invalid_or_mismatched_variant_and_unpublished_products_are_rejected(): void
    {
        $buyer = $this->buyer();
        $token = $this->token($buyer);
        ['product' => $product, 'variant' => $variant] = $this->productFixture();
        ['product' => $otherProduct] = $this->productFixture();

        $this->withToken($token)
            ->postJson('/api/mobile/buyer/cart/items', ['quantity' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_variant_id');

        $this->withToken($token)
            ->postJson('/api/mobile/buyer/cart/items', [
                'product_variant_id' => 999999,
                'quantity' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_variant_id');

        $this->withToken($token)
            ->postJson('/api/mobile/buyer/cart/items', [
                'product_id' => $product->id,
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.item.product_variant_id', $variant->id);

        $this->withToken($token)
            ->postJson('/api/mobile/buyer/cart/items', [
                'product_id' => $otherProduct->id,
                'product_variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_variant_id');

        $inactive = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'INACTIVE-'.uniqid(),
            'name' => 'Inactive option',
            'options' => [],
            'price_minor' => 10000,
            'stock_on_hand' => 5,
            'stock_reserved' => 0,
            'is_active' => false,
        ]);
        $this->withToken($token)
            ->postJson('/api/mobile/buyer/cart/items', [
                'product_variant_id' => $inactive->id,
                'quantity' => 1,
            ])
            ->assertNotFound();

        ['product' => $hiddenProduct, 'variant' => $hiddenVariant] = $this->productFixture(
            productAttributes: ['product_status' => 'draft'],
        );
        $this->withToken($token)
            ->postJson('/api/mobile/buyer/cart/items', [
                'product_id' => $hiddenProduct->id,
                'product_variant_id' => $hiddenVariant->id,
                'quantity' => 1,
            ])
            ->assertNotFound();

        ['variant' => $ineligibleStoreVariant] = $this->productFixture(
            storeAttributes: ['publication_status' => 'draft'],
        );
        $this->withToken($token)
            ->postJson('/api/mobile/buyer/cart/items', [
                'product_variant_id' => $ineligibleStoreVariant->id,
                'quantity' => 1,
            ])
            ->assertNotFound();

        ['variant' => $poorStandingVariant] = $this->productFixture(
            storeAttributes: ['standing_status' => 'under_review'],
        );
        $this->withToken($token)
            ->postJson('/api/mobile/buyer/cart/items', [
                'product_variant_id' => $poorStandingVariant->id,
                'quantity' => 1,
            ])
            ->assertNotFound();

        ['variant' => $suspendedSellerVariant] = $this->productFixture(
            storeAttributes: ['seller_status' => 'suspended'],
        );
        $this->withToken($token)
            ->postJson('/api/mobile/buyer/cart/items', [
                'product_variant_id' => $suspendedSellerVariant->id,
                'quantity' => 1,
            ])
            ->assertNotFound();
    }

    public function test_requested_quantity_and_merged_quantity_cannot_exceed_available_stock(): void
    {
        $buyer = $this->buyer();
        ['variant' => $variant] = $this->productFixture(
            variantAttributes: ['stock_on_hand' => 5, 'stock_reserved' => 2],
        );
        $token = $this->token($buyer);

        $this->withToken($token)
            ->postJson('/api/mobile/buyer/cart/items', [
                'product_variant_id' => $variant->id,
                'quantity' => 4,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->withToken($token)
            ->postJson('/api/mobile/buyer/cart/items', [
                'product_variant_id' => $variant->id,
                'quantity' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->withToken($token)
            ->postJson('/api/mobile/buyer/cart/items', [
                'product_variant_id' => $variant->id,
                'quantity' => 2,
            ])
            ->assertOk();

        $this->withToken($token)
            ->postJson('/api/mobile/buyer/cart/items', [
                'product_variant_id' => $variant->id,
                'quantity' => 2,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $item = CartItem::query()->firstOrFail();
        $this->withToken($token)
            ->patchJson('/api/mobile/buyer/cart/items/'.$item->id, ['quantity' => 4])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');

        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 2]);
        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'stock_on_hand' => 5,
            'stock_reserved' => 2,
        ]);
    }

    public function test_cart_images_never_return_private_disk_paths(): void
    {
        $buyer = $this->buyer();
        ['product' => $product, 'variant' => $variant] = $this->productFixture();
        ProductImage::create([
            'product_id' => $product->id,
            'path' => 'private/seller-registration-document.pdf',
            'disk' => 'local',
            'purpose' => 'primary',
            'is_primary' => true,
            'position' => 0,
        ]);

        $response = $this->withToken($this->token($buyer))
            ->postJson('/api/mobile/buyer/cart/items', [
                'product_variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.item.image_url', null)
            ->assertJsonPath('data.item.image', '');

        $response->assertDontSee('private/seller-registration-document.pdf');
    }

    public function test_cart_api_requires_an_active_buyer_mobile_session(): void
    {
        $this->getJson('/api/mobile/buyer/cart')->assertUnauthorized();
        $this->postJson('/api/mobile/buyer/cart/items', [])->assertUnauthorized();

        foreach (['rider', 'seller', 'admin', 'logistics'] as $role) {
            $user = $this->buyer(['role' => $role]);
            $this->withToken($this->token($user))
                ->getJson('/api/mobile/buyer/cart')
                ->assertForbidden();
        }

        $suspendedBuyer = $this->buyer(['status' => 'suspended']);
        $token = $this->token($suspendedBuyer);
        $this->withToken($token)
            ->getJson('/api/mobile/buyer/cart')
            ->assertForbidden();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'token' => hash('sha256', $token),
        ]);
    }

    public function test_cart_api_is_rate_limited(): void
    {
        $buyer = $this->buyer();
        $token = $this->token($buyer);

        for ($request = 0; $request < 60; $request++) {
            $this->withToken($token)
                ->getJson('/api/mobile/buyer/cart')
                ->assertOk();
        }

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/cart')
            ->assertTooManyRequests();
    }

    private function cartItem(User $buyer, ProductVariant $variant, int $quantity): CartItem
    {
        $cart = Cart::query()->firstOrCreate([
            'user_id' => $buyer->id,
            'status' => 'active',
        ]);

        return CartItem::create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
            'selected' => true,
        ]);
    }

    /** @param array<string, mixed> $productAttributes
     * @param  array<string, mixed>  $variantAttributes
     * @param  array<string, mixed>  $storeAttributes
     * @return array{product: Product, variant: ProductVariant, store: Store}
     */
    private function productFixture(
        array $productAttributes = [],
        array $variantAttributes = [],
        array $storeAttributes = [],
    ): array {
        $category = Category::create([
            'name' => 'Cart Category '.uniqid(),
            'slug' => 'cart-category-'.uniqid(),
            'is_active' => true,
        ]);
        $seller = $this->buyer([
            'role' => 'seller',
            'status' => $storeAttributes['seller_status'] ?? 'active',
        ]);
        $profile = SellerProfile::create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Private Legal Seller Name',
            'approved_category_id' => $category->id,
            'approved_at' => now(),
            'standing_status' => $storeAttributes['standing_status'] ?? 'good_standing',
        ]);
        $store = Store::create([
            'seller_profile_id' => $profile->id,
            'name' => $storeAttributes['name'] ?? 'Public Bearly Store',
            'slug' => 'public-bearly-store-'.uniqid(),
            'publication_status' => $storeAttributes['publication_status'] ?? 'published',
        ]);
        $product = Product::create(array_merge([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Test Bear Product '.uniqid(),
            'slug' => 'test-bear-product-'.uniqid(),
            'description' => 'A test cart product.',
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'published_at' => now(),
        ], $productAttributes));
        $variant = ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'sku' => 'PRIVATE-CART-SKU-'.uniqid(),
            'name' => 'Default option',
            'options' => [],
            'price_minor' => 10000,
            'stock_on_hand' => 5,
            'stock_reserved' => 0,
            'is_active' => true,
            'position' => 1,
        ], $variantAttributes));

        return compact('product', 'variant', 'store');
    }

    /** @param array<string, mixed> $attributes */
    private function buyer(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'buyer',
            'status' => 'active',
        ], $attributes));
    }

    private function token(User $user): string
    {
        return $user->createToken('mobile-cart-test')->plainTextToken;
    }
}
