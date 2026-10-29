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

    public function test_mobile_product_resource_uses_approved_local_http_image_urls(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');
        config([
            'app.url' => 'http://127.0.0.1:8011',
            'filesystems.disks.public.url' => 'https://bearlymarket.com/storage',
            'image_urls.local_http_hosts' => ['localhost', '127.0.0.1', '192.168.1.42'],
        ]);

        $category = $this->category('Local Product Images', 'local-product-images');
        $store = $this->store($category);
        $product = $this->product($store, $category, ['name' => 'Local Image Product']);
        $this->variant($product);
        ProductImage::create([
            'product_id' => $product->id,
            'path' => 'products/local-mobile-product.jpg',
            'disk' => 'public',
            'alt_text' => 'Local product image',
            'position' => 0,
            'is_primary' => true,
        ]);
        $buyer = $this->buyer();
        $token = $buyer->createToken('local-image-catalog-test')->plainTextToken;
        $imageUrl = 'http://127.0.0.1:8011/storage/products/local-mobile-product.jpg';

        $this->withServerVariables([
            'HTTP_HOST' => '127.0.0.1:8011',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => 8011,
            'HTTPS' => 'off',
        ])->withToken($token)
            ->getJson('http://127.0.0.1:8011/api/mobile/buyer/products')
            ->assertOk()
            ->assertJsonPath('data.0.image_url', $imageUrl);

        $this->withServerVariables([
            'HTTP_HOST' => '127.0.0.1:8011',
            'SERVER_NAME' => '127.0.0.1',
            'SERVER_PORT' => 8011,
            'HTTPS' => 'off',
        ])->withToken($token)
            ->getJson('http://127.0.0.1:8011/api/mobile/buyer/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.image_url', $imageUrl)
            ->assertJsonPath('data.images.0.url', $imageUrl);
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

    public function test_mobile_catalog_filters_cod_ineligible_products_before_pagination(): void
    {
        $category = $this->category('Electronics and Gadgets', 'electronics-and-gadgets');
        $store = $this->store($category);
        $publishedAt = now();

        $olderCod = $this->product($store, $category, [
            'name' => 'Older COD Product',
            'published_at' => $publishedAt->copy()->subMinutes(3),
            'payment_methods' => ['cod'],
        ]);
        $this->variant($olderCod);

        $onlineOnly = $this->product($store, $category, [
            'name' => 'Online Only Product',
            'published_at' => $publishedAt->copy()->subMinutes(2),
            'payment_methods' => ['online'],
        ]);
        $this->variant($onlineOnly);

        $newerCod = $this->product($store, $category, [
            'name' => 'Newer COD Product',
            'published_at' => $publishedAt->copy()->subMinute(),
            'payment_methods' => ['cod'],
        ]);
        $this->variant($newerCod);

        $draft = $this->product($store, $category, [
            'name' => 'Draft COD Product',
            'product_status' => 'draft',
            'payment_methods' => ['cod'],
        ]);
        $this->variant($draft);

        $blocked = $this->product($store, $category, [
            'name' => 'Blocked COD Product',
            'compliance_status' => 'blocked',
            'payment_methods' => ['cod'],
        ]);
        $this->variant($blocked);

        $token = $this->buyer()->createToken('cod-pagination-catalog-test')->plainTextToken;

        $pageOne = $this->withToken($token)
            ->getJson('/api/mobile/buyer/products?per_page=1&page=1')
            ->assertOk();

        $pageTwo = $this->withToken($token)
            ->getJson('/api/mobile/buyer/products?per_page=1&page=2')
            ->assertOk();

        $actualPagination = [
            'total' => $pageOne->json('meta.total'),
            'last_page' => $pageOne->json('meta.last_page'),
            'page_one_ids' => array_column($pageOne->json('data'), 'id'),
            'page_two_ids' => array_column($pageTwo->json('data'), 'id'),
            'page_two_has_more' => $pageTwo->json('meta.has_more'),
        ];
        $this->assertSame([
            'total' => 2,
            'last_page' => 2,
            'page_one_ids' => [$newerCod->id],
            'page_two_ids' => [$olderCod->id],
            'page_two_has_more' => false,
        ], $actualPagination, 'Only COD-orderable visible products should determine pagination totals and contiguous page boundaries.');
    }

    public function test_mobile_product_detail_matches_web_cod_eligibility_contract(): void
    {
        $category = $this->category('Electronics and Gadgets', 'electronics-and-gadgets');
        $store = $this->store($category);
        $codProduct = $this->product($store, $category, [
            'name' => 'Detail COD Product',
            'payment_methods' => ['cod'],
        ]);
        $this->variant($codProduct);
        $onlineOnly = $this->product($store, $category, [
            'name' => 'Detail Online Only Product',
            'payment_methods' => ['online'],
        ]);
        $this->variant($onlineOnly);
        $token = $this->buyer()->createToken('detail-orderability-catalog-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/products/'.$onlineOnly->id)
            ->assertNotFound();

        $this->withToken($token)
            ->getJson('/api/mobile/buyer/products/'.$codProduct->id)
            ->assertOk()
            ->assertJsonPath('data.id', $codProduct->id);
    }

    public function test_mobile_price_low_sort_uses_active_variant_prices(): void
    {
        $scenario = $this->variantPriceSortingScenario();

        $response = $this->withToken($scenario['token'])
            ->getJson('/api/mobile/buyer/products?sort=price_low&per_page=10')
            ->assertOk();

        $this->assertSame([
            'total' => 2,
            'products' => [
                ['id' => $scenario['lower_active_price']->id, 'price_minor' => 10000, 'active_variant_count' => 2],
                ['id' => $scenario['higher_active_price']->id, 'price_minor' => 12000, 'active_variant_count' => 2],
            ],
        ], $this->sortedProductSummary($response), 'Price-low ordering must use the same active variant prices returned by the Mobile API.');
    }

    public function test_mobile_price_high_sort_uses_active_variant_prices(): void
    {
        $scenario = $this->variantPriceSortingScenario();

        $response = $this->withToken($scenario['token'])
            ->getJson('/api/mobile/buyer/products?sort=price_high&per_page=10')
            ->assertOk();

        $this->assertSame([
            'total' => 2,
            'products' => [
                ['id' => $scenario['higher_active_price']->id, 'price_minor' => 12000, 'active_variant_count' => 2],
                ['id' => $scenario['lower_active_price']->id, 'price_minor' => 10000, 'active_variant_count' => 2],
            ],
        ], $this->sortedProductSummary($response), 'Price-high ordering must be the reverse of the active price values returned by the Mobile API.');
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
        return Category::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'parent_id' => $parentId,
                'name' => $name,
                'is_active' => true,
            ],
        );
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

    /** @return array{token: string, lower_active_price: Product, higher_active_price: Product} */
    private function variantPriceSortingScenario(): array
    {
        $category = $this->category('Electronics and Gadgets', 'electronics-and-gadgets');
        $store = $this->store($category);

        $higherActivePrice = $this->product($store, $category, ['name' => 'Higher Active Price Product']);
        $this->variant($higherActivePrice, [
            'name' => 'Active Higher Price',
            'price_minor' => 12000,
            'position' => 1,
        ]);
        $this->variant($higherActivePrice, [
            'name' => 'Active Secondary Price',
            'price_minor' => 8000,
            'is_active' => true,
            'position' => 2,
        ]);
        $this->variant($higherActivePrice, [
            'name' => 'Inactive Lower Price',
            'price_minor' => 1000,
            'is_active' => false,
            'position' => 3,
        ]);

        $lowerActivePrice = $this->product($store, $category, ['name' => 'Lower Active Price Product']);
        $this->variant($lowerActivePrice, [
            'name' => 'Active Lower Price',
            'price_minor' => 10000,
            'position' => 1,
        ]);
        $this->variant($lowerActivePrice, [
            'name' => 'Active Secondary Higher Price',
            'price_minor' => 15000,
            'is_active' => true,
            'position' => 2,
        ]);
        $this->variant($lowerActivePrice, [
            'name' => 'Inactive Higher Price',
            'price_minor' => 50000,
            'is_active' => false,
            'position' => 3,
        ]);

        return [
            'token' => $this->buyer()->createToken('variant-price-catalog-test')->plainTextToken,
            'lower_active_price' => $lowerActivePrice,
            'higher_active_price' => $higherActivePrice,
        ];
    }

    /** @return array{total: int, products: list<array{id: int, price_minor: int, active_variant_count: int}>} */
    private function sortedProductSummary(\Illuminate\Testing\TestResponse $response): array
    {
        return [
            'total' => (int) $response->json('meta.total'),
            'products' => collect($response->json('data'))
                ->map(fn (array $product): array => [
                    'id' => (int) $product['id'],
                    'price_minor' => (int) $product['price_minor'],
                    'active_variant_count' => count($product['variants']),
                ])
                ->all(),
        ];
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
