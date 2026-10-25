<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\StorePromotion;
use App\Models\User;
use App\Services\BuyerCatalogService;
use App\Services\ProductPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BuyerDiscountDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_seller_discounts_render_across_buyer_product_surfaces(): void
    {
        $category = Category::query()->where('slug', 'electronics-and-gadgets')->firstOrFail();
        $seller = User::create([
            'name' => 'Discount Seller',
            'first_name' => 'Discount',
            'last_name' => 'Seller',
            'email' => 'discount-seller@example.test',
            'role' => 'seller',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);

        $profile = SellerProfile::create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Discount Seller Business',
            'approved_category_id' => $category->id,
            'standing_status' => 'good_standing',
            'approved_at' => now(),
        ]);

        $store = Store::create([
            'seller_profile_id' => $profile->id,
            'name' => 'Discount Store',
            'slug' => 'discount-store',
            'publication_status' => 'published',
        ]);

        $buyer = User::create([
            'name' => 'Discount Buyer',
            'first_name' => 'Discount',
            'last_name' => 'Buyer',
            'email' => 'discount-buyer@example.test',
            'role' => 'buyer',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);

        $this->actingAs($buyer);

        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'MacBook Air M2',
            'slug' => 'macbook-air-m2',
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'published_at' => now(),
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'MACBOOK-AIR-M2-256',
            'name' => 'Midnight / 256 GB',
            'options' => ['Color' => 'Midnight', 'Storage' => '256 GB'],
            'price_minor' => 4_000_000,
            'compare_at_price_minor' => 5_000_000,
            'stock_on_hand' => 5,
            'is_active' => true,
            'position' => 0,
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'MACBOOK-AIR-M2-512',
            'name' => 'Silver / 512 GB',
            'options' => ['Color' => 'Silver', 'Storage' => '512 GB'],
            'price_minor' => 6_000_000,
            'compare_at_price_minor' => null,
            'stock_on_hand' => 3,
            'is_active' => true,
            'position' => 1,
        ]);

        $relatedProduct = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Related Discounted Item',
            'slug' => 'related-discounted-item',
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'published_at' => now()->subMinute(),
        ]);

        ProductVariant::create([
            'product_id' => $relatedProduct->id,
            'sku' => 'RELATED-ITEM-001',
            'name' => 'Standard',
            'options' => ['Color' => 'White'],
            'price_minor' => 300_000,
            'compare_at_price_minor' => 400_000,
            'stock_on_hand' => 8,
            'is_active' => true,
            'position' => 0,
        ]);

        $this->get('/home')
            ->assertOk()
            ->assertSee('MacBook Air M2')
            ->assertSee('"discount_percent":20', false)
            ->assertSee('"regular_price":50000', false);

        $this->get('/products?category=electronics-and-gadgets')
            ->assertOk()
            ->assertSee('data-live-catalog="true"', false)
            ->assertSee('Live products and prices from Bearly sellers')
            ->assertSee('MacBook Air M2')
            ->assertSee('"discount_percent":20', false)
            ->assertSee('"regular_price":50000', false);

        $this->get('/products?q=MacBook')
            ->assertOk()
            ->assertSee('20% OFF')
            ->assertSee('₱50,000.00')
            ->assertSee('₱40,000.00');

        $this->get(route('stores.show', ['store' => $store->slug]))
            ->assertOk()
            ->assertSee('20% OFF')
            ->assertSee('25% OFF')
            ->assertSee('data-regular-price="50000"', false)
            ->assertSee('data-discount="20"', false)
            ->assertSee('data-discount="0"', false);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('20% OFF')
            ->assertSee('₱50,000.00')
            ->assertSee('data-detail-price-caption hidden>', false)
            ->assertSee('"discount_percent":20', false)
            ->assertSee('"discount_percent":0', false)
            ->assertSee('Related Discounted Item')
            ->assertSee('25% OFF');
    }

    public function test_product_without_an_active_discount_keeps_the_discount_meta_hidden(): void
    {
        $category = Category::query()->where('slug', 'books-and-media')->firstOrFail();

        $seller = User::create([
            'name' => 'Regular Price Seller',
            'first_name' => 'Regular',
            'last_name' => 'Seller',
            'email' => 'regular-price-seller@example.test',
            'role' => 'seller',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);

        $profile = SellerProfile::create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Regular Price Seller Business',
            'approved_category_id' => $category->id,
            'standing_status' => 'good_standing',
            'approved_at' => now(),
        ]);

        $store = Store::create([
            'seller_profile_id' => $profile->id,
            'name' => 'Regular Price Store',
            'slug' => 'regular-price-store',
            'publication_status' => 'published',
        ]);

        $buyer = User::create([
            'name' => 'Regular Price Buyer',
            'first_name' => 'Regular',
            'last_name' => 'Buyer',
            'email' => 'regular-price-buyer@example.test',
            'role' => 'buyer',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);

        $this->actingAs($buyer);

        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Regular Price Book',
            'slug' => 'regular-price-book',
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'published_at' => now(),
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'REGULAR-BOOK-001',
            'name' => 'Paperback',
            'options' => ['Format' => 'Paperback'],
            'price_minor' => 120_000,
            'compare_at_price_minor' => null,
            'stock_on_hand' => 6,
            'is_active' => true,
            'position' => 0,
        ]);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('data-price-meta hidden>', false)
            ->assertSee('data-detail-price-caption>Current variation price', false)
            ->assertDontSee('% OFF');
    }

    public function test_only_currently_active_product_discounts_reach_buyer_pricing(): void
    {
        $category = Category::query()->where('slug', 'electronics-and-gadgets')->firstOrFail();

        $seller = User::create([
            'name' => 'Promotion Seller',
            'first_name' => 'Promotion',
            'last_name' => 'Seller',
            'email' => 'promotion-seller@example.test',
            'role' => 'seller',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);

        $profile = SellerProfile::create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Promotion Seller Business',
            'approved_category_id' => $category->id,
            'standing_status' => 'good_standing',
            'approved_at' => now(),
        ]);

        $store = Store::create([
            'seller_profile_id' => $profile->id,
            'name' => 'Promotion Store',
            'slug' => 'promotion-store',
            'publication_status' => 'published',
        ]);

        $buyer = User::create([
            'name' => 'Promotion Buyer',
            'first_name' => 'Promotion',
            'last_name' => 'Buyer',
            'email' => 'promotion-buyer@example.test',
            'role' => 'buyer',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
        $this->actingAs($buyer);

        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Promotion Test Product',
            'slug' => 'promotion-test-product',
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'published_at' => now(),
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'PROMO-001',
            'name' => 'Standard',
            'options' => ['Color' => 'Black'],
            'price_minor' => 120_000,
            'compare_at_price_minor' => null,
            'stock_on_hand' => 10,
            'is_active' => true,
            'position' => 0,
        ]);

        $startsAt = now('Asia/Manila')->startOfMinute()->subMinutes(2);
        $endsAt = $startsAt->copy()->addMinutes(15);
        $promotion = StorePromotion::create([
            'store_id' => $store->id,
            'created_by' => $seller->id,
            'name' => 'Current product discount',
            'type' => StorePromotion::TYPE_PRODUCT_DISCOUNT,
            'status' => StorePromotion::STATUS_ACTIVE,
            'discount_type' => StorePromotion::DISCOUNT_PERCENT,
            'discount_percent' => 20,
            'buyer_campaign_name' => 'MacBook Drop Sale',
            'starts_on' => $startsAt->toDateString(),
            'ends_on' => $endsAt->toDateString(),
            'starts_time' => $startsAt->format('H:i:s'),
            'ends_time' => $endsAt->format('H:i').':59',
        ]);
        $promotion->products()->attach($product->id);

        $activeCard = (new BuyerCatalogService(new ProductPricingService))
            ->cards(collect([$product]))[0];

        $this->assertSame(960, $activeCard['price']);
        $this->assertSame(1200, $activeCard['regular_price']);
        $this->assertSame(20, $activeCard['discount_percent']);
        $this->assertSame('product_discount', $activeCard['price_source']);
        $this->assertSame('MacBook Drop Sale', $activeCard['product_discount_name']);
        $this->assertSame($promotion->startsAt()->toIso8601String(), $activeCard['product_discount_starts_at']);
        $this->assertSame($promotion->endsAt()->toIso8601String(), $activeCard['product_discount_ends_at']);

        $beforeStart = (new ProductPricingService)->priceForVariant(
            $product->fresh(),
            $variant->fresh(),
            at: $promotion->startsAt()->copy()->subSecond(),
        );
        $atStart = (new ProductPricingService)->priceForVariant(
            $product->fresh(),
            $variant->fresh(),
            at: $promotion->startsAt(),
        );
        $atEnd = (new ProductPricingService)->priceForVariant(
            $product->fresh(),
            $variant->fresh(),
            at: $promotion->endsAt(),
        );
        $afterEnd = (new ProductPricingService)->priceForVariant(
            $product->fresh(),
            $variant->fresh(),
            at: $promotion->endsAt()->copy()->addSecond(),
        );

        $this->assertSame('regular', $beforeStart['price_source']);
        $this->assertSame('product_discount', $atStart['price_source']);
        $this->assertSame('product_discount', $atEnd['price_source']);
        $this->assertSame('regular', $afterEnd['price_source']);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('MacBook Drop Sale')
            ->assertSee('PHT');

        $promotion->update([
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addWeek()->toDateString(),
        ]);

        $scheduledCard = (new BuyerCatalogService(new ProductPricingService))
            ->cards(collect([$product]))[0];

        $this->assertSame(1200, $scheduledCard['price']);
        $this->assertSame(0, $scheduledCard['discount_percent']);
        $this->assertSame('regular', $scheduledCard['price_source']);

        $promotion->update([
            'starts_on' => now()->subWeek()->toDateString(),
            'ends_on' => now()->subDay()->toDateString(),
        ]);

        $expiredCard = (new BuyerCatalogService(new ProductPricingService))
            ->cards(collect([$product]))[0];

        $this->assertSame(1200, $expiredCard['price']);
        $this->assertSame(0, $expiredCard['discount_percent']);
        $this->assertSame('regular', $expiredCard['price_source']);
    }
}
