<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceSubcategory;
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
use App\Models\Wishlist;
use App\Services\ProductReviewService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuyerRankingDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_home_trending_tabs_show_real_rank_metrics_wishlist_state_images_and_verified_ratings(): void
    {
        $buyer = $this->user('buyer', 'ranking-home-buyer');
        $otherActiveBuyer = $this->user('buyer', 'ranking-home-other');
        $inactiveBuyer = $this->user('buyer', 'ranking-home-inactive', 'suspended');
        $electronics = $this->businessCategory('electronics-and-gadgets');
        $electronicsSubcategory = $this->marketplaceSubcategory('electronics-and-gadgets');
        [, $store] = $this->sellerAndStore($electronics, 'ranking-home-electronics');

        [$mostSaved, $mostSavedVariant] = $this->product(
            $store,
            $electronics,
            $electronicsSubcategory,
            'Most Saved Headphones',
            imagePath: 'seller-products/ranking/most-saved-headphones.jpg',
        );
        [$bestSeller, $bestSellerVariant] = $this->product(
            $store,
            $electronics,
            $electronicsSubcategory,
            'Completed Seller Best Seller',
            imagePath: 'seller-products/ranking/best-seller.jpg',
        );
        [$unranked] = $this->product($store, $electronics, $electronicsSubcategory, 'No Ranking Activity Product');
        [$onlineOnly, $onlineOnlyVariant] = $this->product(
            $store,
            $electronics,
            $electronicsSubcategory,
            'Online-only Saved Device',
            paymentMethods: ['online'],
        );
        [$outOfStock, $outOfStockVariant] = $this->product(
            $store,
            $electronics,
            $electronicsSubcategory,
            'Out-of-stock Saved Device',
            stock: 0,
        );

        $this->actingAs($buyer)
            ->postJson(route('wishlist.toggle'), ['product_id' => $mostSaved->id])
            ->assertOk()
            ->assertJsonPath('data.is_wishlisted', true);
        $this->save($otherActiveBuyer, $mostSaved);
        $this->save($inactiveBuyer, $mostSaved);
        $this->save($buyer, $bestSeller);
        $this->save($buyer, $onlineOnly);
        $this->save($buyer, $outOfStock);

        $completedReviewItem = $this->sellerOrderItem($buyer, $store, $bestSeller, $bestSellerVariant, 4, 'completed');
        $hiddenReviewItem = $this->sellerOrderItem($buyer, $store, $bestSeller, $bestSellerVariant, 2, 'completed');
        $this->sellerOrderItem($buyer, $store, $mostSaved, $mostSavedVariant, 90, 'pending');
        $this->sellerOrderItem($buyer, $store, $onlineOnly, $onlineOnlyVariant, 100, 'completed');
        $this->sellerOrderItem($buyer, $store, $outOfStock, $outOfStockVariant, 100, 'completed');

        $reviews = app(ProductReviewService::class);
        $reviews->submitReview($buyer, (int) $completedReviewItem->id, 5, 'Verified five-star review.');
        $hiddenReview = $reviews->submitReview($buyer, (int) $hiddenReviewItem->id, 1, 'Hidden one-star review.');
        $hiddenReview->update(['is_visible' => false]);

        $this->app->detectEnvironment(static fn (): string => 'local');
        config([
            'filesystems.disks.public.driver' => 'local',
            'image_urls.local_http_hosts' => ['127.0.0.1', 'localhost'],
        ]);
        $home = $this->withServerVariables([
            'HTTP_HOST' => '127.0.0.1:8011',
            'SERVER_PORT' => '8011',
            'HTTPS' => 'off',
        ])->actingAs($buyer)
            ->get('http://127.0.0.1:8011/home')
            ->assertOk()
            ->assertSee('Trending on Bearly')
            ->assertSee('Popular Picks')
            ->assertSee('Best Sellers')
            ->assertSee('Most Saved Headphones')
            ->assertSee('Completed Seller Best Seller')
            ->assertSee('2 Buyer saves')
            ->assertSee('6 completed units sold')
            ->assertSee('5.0')
            ->assertSee('1 review')
            ->assertSee('data-home-ranking-tab="popular"', false)
            ->assertSee('data-home-ranking-tab="top_sales"', false)
            ->assertSee('href="'.route('top-products').'"', false)
            ->assertSee('href="'.route('top-sales').'"', false)
            ->assertSee('seller-products/ranking/most-saved-headphones.jpg')
            ->assertSee('src="http://127.0.0.1:8011/storage/seller-products/ranking/most-saved-headphones.jpg"', false)
            ->assertSee('aria-pressed="true"', false)
            ->assertDontSee('1.0 out of 5 from 1 verified review')
            ->assertDontSee('0.0 out of 5')
            ->assertDontSee('monthly');

        $this->assertSame(2, substr_count($home->getContent(), 'aria-label="5.0 out of 5 from 1 verified review"'));
        $this->assertSame(1, ProductReview::query()->where('product_id', $bestSeller->id)->where('is_visible', true)->count());
        $homeHtml = $home->getContent();
        $popularPanelStart = strpos($homeHtml, 'id="home-ranking-panel-popular"');
        $salesPanelStart = strpos($homeHtml, 'id="home-ranking-panel-top-sales"');
        $this->assertNotFalse($popularPanelStart);
        $this->assertNotFalse($salesPanelStart);
        $popularPanelHtml = substr($homeHtml, $popularPanelStart, $salesPanelStart - $popularPanelStart);
        $this->assertLessThan(
            (int) strpos($popularPanelHtml, $bestSeller->name),
            (int) strpos($popularPanelHtml, $mostSaved->name),
            'Popular Picks must be ordered by active-Buyer saves.',
        );
        foreach ([$unranked, $onlineOnly, $outOfStock] as $excludedProduct) {
            $this->assertStringNotContainsString(
                'data-home-ranking-product-id="'.$excludedProduct->id.'"',
                $home->getContent(),
                'Products without ranking activity, COD eligibility, or available stock must not enter the ranking cards.',
            );
        }

        $popularPage = $this->actingAs($buyer)
            ->get(route('top-products', ['category' => 'electronics-and-gadgets']))
            ->assertOk()
            ->assertSee('₱120.00')
            ->assertSee('aria-label="5.0 out of 5 from 1 verified review"', false)
            ->assertDontSee('1.0 out of 5 from 1 verified review');
        $this->assertSame(1, substr_count($popularPage->getContent(), 'class="ranking-product-rating"'));
        $this->assertLessThan(
            (int) strpos($popularPage->getContent(), $bestSeller->name),
            (int) strpos($popularPage->getContent(), $mostSaved->name),
            'Top Products must use active-Buyer save ranking order.',
        );

        $salesPage = $this->actingAs($buyer)
            ->get(route('top-sales', ['category' => 'electronics-and-gadgets']))
            ->assertOk()
            ->assertSee('aria-label="5.0 out of 5 from 1 verified review"', false)
            ->assertDontSee('1.0 out of 5 from 1 verified review')
            ->assertDontSee($mostSaved->name);
        $this->assertSame(1, substr_count($salesPage->getContent(), 'class="ranking-product-rating"'));
    }

    public function test_ranking_pages_use_canonical_categories_sql_pagination_and_orderable_ranked_products(): void
    {
        $buyer = $this->user('buyer', 'ranking-pages-buyer');
        $inactiveBuyer = $this->user('buyer', 'ranking-pages-inactive', 'suspended');
        $electronics = $this->businessCategory('electronics-and-gadgets');
        $electronicsSubcategory = $this->marketplaceSubcategory('electronics-and-gadgets');
        [, $electronicsStore] = $this->sellerAndStore($electronics, 'ranking-pages-electronics');
        $electronicsProducts = [];

        for ($index = 1; $index <= 26; $index++) {
            [$product, $variant] = $this->product(
                $electronicsStore,
                $electronics,
                $electronicsSubcategory,
                'Ranked Electronic Product '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            );
            $electronicsProducts[] = [$product, $variant];
            $this->save($buyer, $product);
        }

        $this->save($inactiveBuyer, $electronicsProducts[0][0]);

        $healthCategory = $this->businessCategory('beauty-and-personal-care');
        $healthSubcategory = $this->marketplaceSubcategory('health-and-beauty');
        [, $healthStore] = $this->sellerAndStore($healthCategory, 'ranking-pages-health');
        [$healthProduct, $healthVariant] = $this->product(
            $healthStore,
            $healthCategory,
            $healthSubcategory,
            'Ranked Health Product',
        );
        $this->save($buyer, $healthProduct);

        [$onlineOnly, $onlineOnlyVariant] = $this->product(
            $electronicsStore,
            $electronics,
            $electronicsSubcategory,
            'Filtered Online Ranking Product',
            paymentMethods: ['online'],
        );
        [$outOfStock, $outOfStockVariant] = $this->product(
            $electronicsStore,
            $electronics,
            $electronicsSubcategory,
            'Filtered Out-of-stock Ranking Product',
            stock: 0,
        );
        $this->save($buyer, $onlineOnly);
        $this->save($buyer, $outOfStock);

        $this->sellerOrderItem($buyer, $electronicsStore, $electronicsProducts[1][0], $electronicsProducts[1][1], 7, 'completed');
        $this->sellerOrderItem($buyer, $electronicsStore, $electronicsProducts[0][0], $electronicsProducts[0][1], 3, 'completed');
        $this->sellerOrderItem($buyer, $electronicsStore, $electronicsProducts[2][0], $electronicsProducts[2][1], 90, 'pending');
        $this->sellerOrderItem($buyer, $healthStore, $healthProduct, $healthVariant, 4, 'completed');
        $this->sellerOrderItem($buyer, $electronicsStore, $onlineOnly, $onlineOnlyVariant, 100, 'completed');
        $this->sellerOrderItem($buyer, $electronicsStore, $outOfStock, $outOfStockVariant, 100, 'completed');

        $popularPageTwo = $this->actingAs($buyer)
            ->get(route('top-products', ['category' => 'electronics-and-gadgets', 'page' => 2]))
            ->assertOk()
            ->assertSee('Ranked Electronic Product 25')
            ->assertSee('Ranked Electronic Product 26')
            ->assertSee('#25')
            ->assertDontSee('Ranked Electronic Product 01')
            ->assertDontSee($onlineOnly->name)
            ->assertDontSee($outOfStock->name)
            ->assertSee('href="'.route('top-products', ['category' => 'health-and-beauty']).'"', false)
            ->assertSee('href="'.route('top-products', ['category' => 'home-and-garden']).'"', false);
        $this->assertSame(26, $popularPageTwo->viewData('rankingProducts')->total());

        $emptyCategoryPage = $this->actingAs($buyer)
            ->get(route('top-products', ['category' => 'home-and-garden']))
            ->assertOk()
            ->assertSee('No Popular Picks in Home and Garden yet')
            ->assertDontSee('class="top-product-card ranking-product-card', false);
        $this->assertSame(0, $emptyCategoryPage->viewData('rankingProducts')->total());

        $healthPopular = $this->actingAs($buyer)
            ->get(route('top-products', ['category' => 'health-and-beauty']))
            ->assertOk()
            ->assertSee($healthProduct->name)
            ->assertDontSee($electronicsProducts[0][0]->name);
        $this->assertSame(1, $healthPopular->viewData('rankingProducts')->total());

        $salesPage = $this->actingAs($buyer)
            ->get(route('top-sales', ['category' => 'electronics-and-gadgets']))
            ->assertOk()
            ->assertSee('Ranked Electronic Product 02')
            ->assertSee('#1')
            ->assertSee('7 completed units sold')
            ->assertSee('Ranked Electronic Product 01')
            ->assertDontSee($onlineOnly->name)
            ->assertDontSee($outOfStock->name)
            ->assertDontSee($healthProduct->name);
        $this->assertSame(2, $salesPage->viewData('rankingProducts')->total());

        $healthSales = $this->actingAs($buyer)
            ->get(route('top-sales', ['category' => 'health-and-beauty']))
            ->assertOk()
            ->assertSee($healthProduct->name)
            ->assertSee('4 completed units sold')
            ->assertDontSee($electronicsProducts[1][0]->name);
        $this->assertSame(1, $healthSales->viewData('rankingProducts')->total());
    }

    private function businessCategory(string $slug): Category
    {
        return Category::query()->where('slug', $slug)->firstOrFail();
    }

    private function marketplaceSubcategory(string $marketplaceCategorySlug): MarketplaceSubcategory
    {
        $marketplaceCategory = MarketplaceCategory::query()
            ->where('slug', $marketplaceCategorySlug)
            ->where('is_active', true)
            ->firstOrFail();

        return MarketplaceSubcategory::query()
            ->where('marketplace_category_id', $marketplaceCategory->id)
            ->where('is_active', true)
            ->orderBy('position')
            ->firstOrFail();
    }

    private function sellerAndStore(Category $businessCategory, string $slug): array
    {
        $seller = $this->user('seller', $slug.'-seller');
        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => Str::headline($slug).' Business',
            'approved_category_id' => $businessCategory->id,
            'standing_status' => 'good_standing',
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => Str::headline($slug).' Store',
            'slug' => $slug.'-'.$profile->id,
            'publication_status' => 'published',
            'published_at' => now(),
        ]);

        return [$seller, $store];
    }

    private function product(
        Store $store,
        Category $businessCategory,
        MarketplaceSubcategory $subcategory,
        string $name,
        array $paymentMethods = ['cod'],
        int $stock = 8,
        int $priceMinor = 12000,
        ?string $imagePath = null,
    ): array {
        $product = Product::query()->create([
            'store_id' => $store->id,
            'category_id' => $businessCategory->id,
            'marketplace_subcategory_id' => $subcategory->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(7)),
            'description' => $name.' listing description.',
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'payment_methods' => $paymentMethods,
            'published_at' => now(),
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'RANK-'.Str::upper(Str::random(10)),
            'name' => 'Standard',
            'price_minor' => $priceMinor,
            'stock_on_hand' => $stock,
            'stock_reserved' => 0,
            'is_active' => true,
        ]);

        if ($imagePath !== null) {
            ProductImage::query()->create([
                'product_id' => $product->id,
                'path' => $imagePath,
                'disk' => 'public',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1024,
                'purpose' => 'product_image',
                'alt_text' => $name,
                'position' => 0,
                'is_primary' => true,
            ]);
        }

        return [$product, $variant];
    }

    private function save(User $buyer, Product $product): void
    {
        Wishlist::query()->create([
            'user_id' => $buyer->id,
            'product_id' => $product->id,
        ]);
    }

    private function sellerOrderItem(
        User $buyer,
        Store $store,
        Product $product,
        ProductVariant $variant,
        int $quantity,
        string $sellerOrderStatus,
    ): OrderItem {
        $subtotal = (int) $variant->price_minor * $quantity;
        $completed = $sellerOrderStatus === 'completed';
        $order = Order::query()->create([
            'order_no' => 'BRY-RANK-'.Str::upper(Str::random(12)),
            'buyer_id' => $buyer->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => '+639171234567',
            'address_line' => '1 Ranking Street',
            'barangay' => 'Poblacion',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'currency' => 'PHP',
            'subtotal_minor' => $subtotal,
            'shipping_fee_minor' => 0,
            'discount_minor' => 0,
            'total_minor' => $subtotal,
            'status' => $completed ? 'fulfilled' : 'processing',
            'payment_status' => $completed ? 'paid' : 'unpaid',
            'placed_at' => now()->subDay(),
            'completed_at' => $completed ? now() : null,
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => 'SO-RANK-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => $sellerOrderStatus,
            'subtotal_minor' => $subtotal,
            'discount_minor' => 0,
            'shipping_fee_minor' => 0,
            'total_minor' => $subtotal,
            'completed_at' => $completed ? now() : null,
        ]);

        return OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name' => $product->name,
            'variant_name' => $variant->name,
            'sku' => $variant->sku,
            'unit_price_minor' => $variant->price_minor,
            'quantity' => $quantity,
            'discount_minor' => 0,
            'subtotal_minor' => $subtotal,
        ]);
    }

    private function user(string $role, string $prefix, string $status = 'active'): User
    {
        return User::query()->create([
            'name' => Str::headline($prefix),
            'first_name' => Str::headline($prefix),
            'last_name' => Str::headline($role),
            'email' => $prefix.'-'.Str::uuid().'@example.test',
            'role' => $role,
            'status' => $status,
            'password' => Hash::make('Password123'),
        ]);
    }
}
