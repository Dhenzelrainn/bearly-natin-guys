<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileBuyerCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_buyer_can_read_catalog_details_and_categories_with_public_fields(): void
    {
        config(['filesystems.disks.public.url' => 'https://cdn.example.test/storage']);

        $parent = $this->category('Home and Living', 'home-and-living');
        $category = $this->category('Home and Furniture', 'home-and-furniture', $parent->id);
        $emptyCategory = $this->category('Empty Category', 'empty-category');
        $store = $this->store($category);
        $product = $this->product($store, $category, [
            'name' => 'Handmade Bear Plush',
            'slug' => 'handmade-bear-plush',
            'description' => 'A soft handmade bear for a cozy room.',
        ]);
        $available = $this->variant($product, [
            'name' => 'Honey / Large',
            'sku' => 'PRIVATE-SELLER-SKU-1',
            'options' => ['Color' => 'Honey', 'Size' => 'Large'],
            'price_minor' => 12500,
            'compare_at_price_minor' => 15000,
            'stock_on_hand' => 5,
            'stock_reserved' => 2,
            'position' => 1,
        ]);
        $this->variant($product, [
            'name' => 'Cream / Small',
            'sku' => 'PRIVATE-SELLER-SKU-2',
            'options' => ['Color' => 'Cream', 'Size' => 'Small'],
            'price_minor' => 14000,
            'stock_on_hand' => 2,
            'stock_reserved' => 2,
            'position' => 2,
        ]);
        ProductImage::create([
            'product_id' => $product->id,
            'path' => 'storage/products/handmade-bear.jpg',
            'disk' => 'public',
            'alt_text' => 'Handmade bear plush',
            'position' => 0,
            'is_primary' => true,
        ]);
        ProductImage::create([
            'product_id' => $product->id,
            'variant_id' => $available->id,
            'path' => 'products/honey-variant.jpg',
            'disk' => 'public',
            'alt_text' => 'Honey color option',
            'position' => 1,
        ]);
        ProductImage::create([
            'product_id' => $product->id,
            'path' => 'private/seller-registration-document.pdf',
            'disk' => 'local',
            'alt_text' => 'Private document',
            'position' => 2,
        ]);

        $buyer = $this->buyer();
        $this->publicReview($buyer, $store, $product, $available, 5);
        $token = $buyer->createToken('catalog-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/products')
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.price_minor', 12500)
            ->assertJsonPath('data.0.regular_price_minor', 15000)
            ->assertJsonPath('data.0.currency', 'PHP')
            ->assertJsonPath('data.0.in_stock', true)
            ->assertJsonPath('data.0.variants.0.in_stock', true)
            ->assertJsonPath('data.0.variants.1.in_stock', false)
            ->assertJsonPath('data.0.variants.0.options.Color', 'Honey')
            ->assertJsonPath('data.0.image_url', 'https://cdn.example.test/storage/products/handmade-bear.jpg')
            ->assertJsonPath('data.0.rating_summary.count', 1)
            ->assertJsonPath('data.0.rating_summary.average', 5)
            ->assertJsonMissingPath('data.0.variants.0.sku')
            ->assertJsonMissingPath('data.0.product_status')
            ->assertJsonMissing(['legal_business_name' => 'Private Legal Seller Name']);

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.description', 'A soft handmade bear for a cozy room.')
            ->assertJsonPath('data.category.parent.id', $parent->id)
            ->assertJsonPath('data.images.0.url', 'https://cdn.example.test/storage/products/handmade-bear.jpg')
            ->assertJsonMissing(['alt_text' => 'Private document'])
            ->assertJsonMissing(['legal_business_name' => 'Private Legal Seller Name']);

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/categories')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $category->id,
                'name' => 'Home and Furniture',
                'slug' => 'home-and-furniture',
            ])
            ->assertJsonFragment([
                'id' => $parent->id,
                'name' => 'Home and Living',
                'slug' => 'home-and-living',
            ])
            ->assertJsonMissing(['id' => $emptyCategory->id]);
    }

    public function test_catalog_requires_an_active_buyer_account_and_rejects_other_roles(): void
    {
        $this->getJson('/api/mobile/buyer/products')->assertUnauthorized();

        foreach (['rider', 'seller'] as $role) {
            $user = $this->buyer(['role' => $role]);
            $token = $user->createToken('unauthorized-catalog-test')->plainTextToken;

            $this->withToken($token)
                ->getJson('/api/mobile/buyer/products')
                ->assertForbidden();
        }

        $suspendedBuyer = $this->buyer(['status' => 'suspended']);
        $token = $suspendedBuyer->createToken('suspended-catalog-test')->plainTextToken;
        $tokenHash = hash('sha256', $token);

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/categories')
            ->assertForbidden();

        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $tokenHash]);
    }

    public function test_hidden_products_and_products_from_ineligible_stores_are_not_discoverable(): void
    {
        $category = $this->category('Electronics and Gadgets', 'electronics-and-gadgets');
        $visibleStore = $this->store($category);
        $visible = $this->product($visibleStore, $category, ['name' => 'Visible Headphones']);
        $this->variant($visible);

        $draft = $this->product($visibleStore, $category, [
            'name' => 'Draft Headphones',
            'product_status' => 'draft',
        ]);
        $this->variant($draft);

        $blocked = $this->product($visibleStore, $category, [
            'name' => 'Blocked Headphones',
            'product_status' => 'blocked',
            'compliance_status' => 'blocked',
        ]);
        $this->variant($blocked);

        $removed = $this->product($visibleStore, $category, [
            'name' => 'Removed Headphones',
            'removed_at' => now(),
        ]);
        $this->variant($removed);

        $noActiveVariant = $this->product($visibleStore, $category, ['name' => 'No Variant Headphones']);
        $this->variant($noActiveVariant, ['is_active' => false]);

        $ineligibleStore = $this->store($category, ['seller_status' => 'suspended']);
        $ineligibleProduct = $this->product($ineligibleStore, $category, ['name' => 'Suspended Seller Headphones']);
        $this->variant($ineligibleProduct);

        $draftStore = $this->store($category, ['publication_status' => 'draft']);
        $draftStoreProduct = $this->product($draftStore, $category, ['name' => 'Draft Store Headphones']);
        $this->variant($draftStoreProduct);

        $token = $this->buyer()->createToken('visibility-catalog-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);

        foreach ([$draft, $blocked, $removed, $noActiveVariant, $ineligibleProduct, $draftStoreProduct] as $hidden) {
            $this->withToken($token)
                ->getJson('/api/mobile/buyer/products/'.$hidden->id)
                ->assertNotFound();
        }
    }

    public function test_search_category_sort_validation_and_pagination_follow_web_catalog_filters(): void
    {
        $food = $this->category('Food and Gourmet', 'food-and-gourmet');
        $fashion = $this->category('Fashion and Apparel', 'fashion-and-apparel');
        $store = $this->store($food);
        $tea = $this->product($store, $food, [
            'name' => 'Green Tea Set',
            'description' => 'A loose leaf tea set.',
            'slug' => 'green-tea-set',
        ]);
        $this->variant($tea, ['price_minor' => 5000]);

        $fashionStore = $this->store($fashion);
        $scarf = $this->product($fashionStore, $fashion, [
            'name' => 'Green Scarf',
            'description' => 'Soft cotton scarf.',
            'slug' => 'green-scarf',
        ]);
        $this->variant($scarf, ['price_minor' => 2500]);

        $third = $this->product($store, $food, [
            'name' => 'Ceramic Mug',
            'slug' => 'ceramic-mug',
        ]);
        $this->variant($third, ['price_minor' => 3000]);

        $token = $this->buyer()->createToken('filter-catalog-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/products?search=green%20tea')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tea->id);

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/products?category=fashion-and-apparel')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $scarf->id);

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/products?sort=price_low&per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tea->id);

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/products?sort=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/products?per_page=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    private function category(string $name, string $slug, ?int $parentId = null): Category
    {
        return Category::create([
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $options */
    private function store(Category $category, array $options = []): Store
    {
        $seller = $this->buyer([
            'role' => 'seller',
            'status' => $options['seller_status'] ?? 'active',
        ]);
        $profile = SellerProfile::create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Private Legal Seller Name',
            'approved_category_id' => $category->id,
            'approved_at' => now(),
            'standing_status' => $options['standing_status'] ?? 'good_standing',
        ]);

        return Store::create([
            'seller_profile_id' => $profile->id,
            'name' => $options['name'] ?? 'Public Bearly Store',
            'slug' => 'public-bearly-store-'.uniqid(),
            'publication_status' => $options['publication_status'] ?? 'published',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function product(Store $store, Category $category, array $attributes = []): Product
    {
        $name = (string) ($attributes['name'] ?? 'Test Product '.uniqid());

        return Product::create(array_merge([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => 'test-product-'.uniqid(),
            'description' => 'A test catalog description.',
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'published_at' => now(),
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function variant(Product $product, array $attributes = []): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'sku' => 'PRIVATE-SKU-'.uniqid(),
            'name' => 'Default',
            'options' => [],
            'price_minor' => 10000,
            'stock_on_hand' => 5,
            'stock_reserved' => 0,
            'is_active' => true,
            'position' => 1,
        ], $attributes));
    }

    private function publicReview(
        User $buyer,
        Store $store,
        Product $product,
        ProductVariant $variant,
        int $rating,
    ): ProductReview {
        $order = Order::create([
            'order_no' => 'TEST-'.strtoupper(uniqid()),
            'buyer_id' => $buyer->id,
            'recipient_name' => 'Test Buyer',
            'recipient_phone' => '09170000000',
            'address_line' => 'Test Street',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'currency' => 'PHP',
            'subtotal_minor' => (int) $variant->price_minor,
            'total_minor' => (int) $variant->price_minor,
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);
        $sellerOrder = SellerOrder::create([
            'seller_order_no' => 'TEST-SO-'.strtoupper(uniqid()),
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => 'completed',
            'subtotal_minor' => (int) $variant->price_minor,
            'total_minor' => (int) $variant->price_minor,
        ]);
        $item = OrderItem::create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name' => $product->name,
            'variant_name' => $variant->name,
            'sku' => $variant->sku,
            'options' => $variant->options,
            'unit_price_minor' => (int) $variant->price_minor,
            'quantity' => 1,
            'subtotal_minor' => (int) $variant->price_minor,
        ]);

        return ProductReview::create([
            'buyer_id' => $buyer->id,
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
            'order_item_id' => $item->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'store_id' => $store->id,
            'rating' => $rating,
            'comment' => 'A useful test fixture review.',
            'is_visible' => true,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function buyer(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'buyer',
            'status' => 'active',
        ], $attributes));
    }
}
