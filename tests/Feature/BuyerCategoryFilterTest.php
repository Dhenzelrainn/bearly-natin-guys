<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Category;
use App\Models\MarketplaceCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\PromotionRedemption;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\StorePromotion;
use App\Models\User;
use Database\Seeders\MarketplaceTaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuyerCategoryFilterTest extends TestCase
{
    use RefreshDatabase;

    private Category $businessCategory;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'fashion-and-apparel' => 'Fashion and Apparel',
            'home-and-furniture' => 'Home and Furniture',
            'electronics-and-gadgets' => 'Electronics and Gadgets',
            'jewelry-and-watches' => 'Jewelry and Watches',
            'beauty-and-personal-care' => 'Beauty and Personal Care',
            'food-and-gourmet' => 'Food and Gourmet',
            'sports-and-outdoors' => 'Sports and Outdoors',
        ] as $slug => $name) {
            Category::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'is_active' => true],
            );
        }

        $this->businessCategory = Category::query()->where('slug', 'electronics-and-gadgets')->firstOrFail();
        app(MarketplaceTaxonomySeeder::class)->run();
    }

    public function test_category_facets_cover_all_products_and_variant_filters_match_one_real_sku(): void
    {
        $seller = $this->seller('facet-seller', 'Santa Rosa', 'Laguna');
        $buyer = $this->buyer();
        $marketplaceCategory = MarketplaceCategory::query()
            ->where('slug', 'electronics-and-gadgets')
            ->firstOrFail();
        $subcategory = $marketplaceCategory->subcategories()
            ->where('is_active', true)
            ->firstOrFail();

        $lateFacetProduct = $this->product($seller, 'Older Cerulean Listing', [
            ['name' => 'Cerulean One Size', 'options' => ['Size' => 'One Size', 'Color' => 'Cerulean'], 'price_minor' => 259900],
        ], publishedAt: now()->subDays(10), marketplaceSubcategoryId: (int) $subcategory->id);
        $combinedProduct = $this->product($seller, 'Variant Combination Product', [
            ['name' => 'Small Red', 'options' => ['Size' => 'Small', 'Color' => 'Red'], 'price_minor' => 49900],
            ['name' => 'Large Blue', 'options' => ['Size' => 'Large', 'Color' => 'Blue'], 'price_minor' => 159900],
        ]);

        for ($index = 1; $index <= 21; $index++) {
            $this->product($seller, 'Catalog Listing '.$index, [
                ['name' => 'Standard', 'options' => [], 'price_minor' => 10000],
            ]);
        }

        $this->actingAs($buyer)
            ->getJson(route('products.category-data', ['category' => 'electronics-and-gadgets']))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 23)
            ->assertJsonPath('data.pagination.per_page', 20)
            ->assertJsonPath('data.pagination.has_more', true)
            ->assertJsonPath('data.facets.colors.0', 'Blue')
            ->assertJsonFragment(['One Size'])
            ->assertJsonPath('data.facets.conditions', ['New'])
            ->assertJsonFragment(['Cerulean']);

        $this->actingAs($buyer)
            ->getJson(route('products.category-data', [
                'category' => 'electronics-and-gadgets',
                'page' => 2,
            ]))
            ->assertOk()
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.total', 23)
            ->assertJsonCount(3, 'data.products');

        $this->actingAs($buyer)
            ->getJson(route('products.category-data', [
                'category' => 'electronics-and-gadgets',
                'color' => ['Cerulean'],
            ]))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.products.0.id', $lateFacetProduct->id);

        $this->actingAs($buyer)
            ->getJson('/products/category-data?category=electronics-and-gadgets&color%5B%5D=Cerulean')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.products.0.id', $lateFacetProduct->id);

        $this->actingAs($buyer)
            ->getJson(route('products.category-data', [
                'category' => 'electronics-and-gadgets',
                'sub' => $subcategory->slug,
            ]))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.products.0.id', $lateFacetProduct->id);

        $this->actingAs($buyer)
            ->getJson(route('products.category-data', [
                'category' => 'electronics-and-gadgets',
                'size' => ['Large'],
                'color' => ['Red'],
            ]))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0);

        $this->actingAs($buyer)
            ->getJson(route('products.category-data', [
                'category' => 'electronics-and-gadgets',
                'size' => ['Large'],
                'color' => ['Blue'],
                'min' => 1000,
                'max' => 2000,
            ]))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.products.0.id', $combinedProduct->id)
            ->assertJsonPath('data.products.0.price_minor', 159900);

        $this->actingAs($buyer)
            ->getJson(route('products.category-data', [
                'category' => 'electronics-and-gadgets',
                'max' => 100,
            ]))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 21);
    }

    public function test_voucher_rating_location_and_condition_filters_use_buyer_visible_data(): void
    {
        $seller = $this->seller('offer-seller', 'Santa Rosa', 'Laguna');
        $otherSeller = $this->seller('other-offer-seller', 'Makati', 'Metro Manila');
        $buyer = $this->buyer();

        $eligible = $this->product($seller, 'Public Voucher Product', [
            ['name' => 'Standard', 'options' => ['Color' => 'Amber'], 'price_minor' => 120000],
        ], voucherEligible: true);
        $privateVoucherProduct = $this->product($seller, 'Private Voucher Product', [
            ['name' => 'Standard', 'options' => [], 'price_minor' => 80000],
        ], voucherEligible: true);
        $expiredVoucherProduct = $this->product($seller, 'Expired Voucher Product', [
            ['name' => 'Standard', 'options' => [], 'price_minor' => 80000],
        ], voucherEligible: true);
        $inactiveVoucherProduct = $this->product($seller, 'Inactive Voucher Product', [
            ['name' => 'Standard', 'options' => [], 'price_minor' => 80000],
        ], voucherEligible: true);
        $fullyRedeemedVoucherProduct = $this->product($seller, 'Fully Redeemed Voucher Product', [
            ['name' => 'Standard', 'options' => [], 'price_minor' => 80000],
        ], voucherEligible: true);
        $hiddenRatingProduct = $this->product($otherSeller, 'Hidden Rating Product', [
            ['name' => 'Standard', 'options' => [], 'price_minor' => 80000],
        ]);

        $this->voucher($seller['store'], $eligible, public: true, claimable: true);
        $this->voucher($seller['store'], $privateVoucherProduct, public: false, claimable: false);
        $this->voucher(
            $seller['store'],
            $expiredVoucherProduct,
            public: true,
            claimable: true,
            endsOn: now('Asia/Manila')->subDay()->toDateString(),
        );
        $this->voucher(
            $seller['store'],
            $inactiveVoucherProduct,
            public: true,
            claimable: true,
            status: StorePromotion::STATUS_PAUSED,
        );
        $limitedVoucher = $this->voucher(
            $seller['store'],
            $fullyRedeemedVoucherProduct,
            public: true,
            claimable: true,
            redemptionLimit: 1,
        );

        $eligibleReview = $this->createVisibleReview($buyer, $seller['store'], $eligible, 5);
        $this->createVisibleReview($buyer, $otherSeller['store'], $hiddenRatingProduct, 5, visible: false);
        PromotionRedemption::query()->create([
            'order_id' => $eligibleReview->order_id,
            'buyer_id' => $buyer->id,
            'store_promotion_id' => $limitedVoucher->id,
            'name' => $limitedVoucher->name,
            'kind' => StorePromotion::TYPE_STORE_VOUCHER,
            'funding_type' => StorePromotion::FUNDING_SELLER,
            'discount_type' => StorePromotion::DISCOUNT_FIXED,
            'discount_minor' => 10000,
            'shipping_discount_minor' => 0,
            'status' => PromotionRedemption::STATUS_REDEEMED,
            'reserved_at' => now(),
            'redeemed_at' => now(),
        ]);

        $this->actingAs($buyer)
            ->getJson(route('products.category-data', [
                'category' => 'electronics-and-gadgets',
                'voucher' => 1,
                'rating' => 4,
                'location' => ['Santa Rosa, Laguna'],
                'condition' => ['New'],
            ]))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.products.0.id', $eligible->id)
            ->assertJsonPath('data.products.0.rating', 5)
            ->assertJsonPath('data.products.0.location', 'Santa Rosa, Laguna')
            ->assertJsonPath('data.products.0.voucher', true)
            ->assertJsonPath('data.products.0.condition', 'New')
            ->assertJsonPath('data.facets.locations.0', 'Makati, Metro Manila');

        $this->actingAs($buyer)
            ->getJson(route('products.category-data', [
                'category' => 'electronics-and-gadgets',
                'rating' => 4,
                'location' => ['Makati, Metro Manila'],
            ]))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0);
    }

    public function test_category_filter_endpoint_requires_an_active_buyer_and_valid_category(): void
    {
        $this->getJson(route('products.category-data', ['category' => 'electronics-and-gadgets']))
            ->assertUnauthorized();

        $this->actingAs($this->buyer())
            ->getJson(route('products.category-data', ['category' => 'not-a-buyer-category']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');
    }

    /** @return array{user: User, store: Store} */
    private function seller(string $suffix, string $city, string $province): array
    {
        $user = User::query()->create([
            'name' => 'Category Filter Seller '.$suffix,
            'first_name' => 'Category Filter',
            'last_name' => 'Seller',
            'email' => $suffix.'-'.Str::lower(Str::random(8)).'@example.test',
            'role' => 'seller',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Pickup',
            'recipient_name' => $user->name,
            'phone' => '+639171234567',
            'street' => 'Private Street',
            'barangay' => 'Central',
            'city_municipality' => $city,
            'province' => $province,
        ]);
        $profile = SellerProfile::query()->create([
            'user_id' => $user->id,
            'legal_business_name' => 'Category Filter Business '.$suffix,
            'approved_category_id' => $this->businessCategory->id,
            'pickup_address_id' => $address->id,
            'standing_status' => 'good_standing',
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => 'Category Filter Store '.$suffix,
            'slug' => $suffix.'-'.Str::lower(Str::random(6)),
            'publication_status' => 'published',
            'published_at' => now(),
        ]);

        return ['user' => $user, 'store' => $store];
    }

    /** @param array<int, array{name: string, options: array<string, string>, price_minor: int}> $variants */
    private function product(
        array $seller,
        string $name,
        array $variants,
        bool $voucherEligible = false,
        mixed $publishedAt = null,
        ?int $marketplaceSubcategoryId = null,
    ): Product {
        $product = Product::query()->create([
            'store_id' => $seller['store']->id,
            'category_id' => $this->businessCategory->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(7)),
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'voucher_eligible' => $voucherEligible,
            'published_at' => $publishedAt ?? now(),
            'marketplace_subcategory_id' => $marketplaceSubcategoryId,
        ]);

        foreach ($variants as $index => $variant) {
            ProductVariant::query()->create([
                'product_id' => $product->id,
                'sku' => 'CAT-'.Str::upper(Str::random(12)),
                'name' => $variant['name'],
                'options' => $variant['options'],
                'price_minor' => $variant['price_minor'],
                'stock_on_hand' => 5,
                'is_active' => true,
                'position' => $index,
            ]);
        }

        return $product;
    }

    private function buyer(): User
    {
        return User::query()->create([
            'name' => 'Category Filter Buyer',
            'first_name' => 'Category Filter',
            'last_name' => 'Buyer',
            'email' => 'category-filter-'.Str::lower(Str::random(8)).'@example.test',
            'role' => 'buyer',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
    }

    private function voucher(
        Store $store,
        Product $product,
        bool $public,
        bool $claimable,
        string $status = StorePromotion::STATUS_ACTIVE,
        ?string $endsOn = null,
        ?int $redemptionLimit = null,
    ): StorePromotion {
        $promotion = StorePromotion::query()->create([
            'store_id' => $store->id,
            'name' => 'Category filter voucher',
            'type' => StorePromotion::TYPE_STORE_VOUCHER,
            'discount_type' => StorePromotion::DISCOUNT_FIXED,
            'voucher_amount_minor' => 10000,
            'starts_on' => now('Asia/Manila')->toDateString(),
            'ends_on' => $endsOn ?? now('Asia/Manila')->addDays(2)->toDateString(),
            'redemption_limit' => $redemptionLimit,
            'starts_time' => '00:00:00',
            'ends_time' => '23:59:59',
            'status' => $status,
            'is_public' => $public,
            'is_claimable' => $claimable,
        ]);
        $promotion->products()->attach($product->id);

        return $promotion;
    }

    private function createVisibleReview(
        User $buyer,
        Store $store,
        Product $product,
        int $rating,
        bool $visible = true,
    ): ProductReview {
        $address = Address::query()->create([
            'user_id' => $buyer->id,
            'label' => 'Home',
            'recipient_name' => $buyer->name,
            'phone' => '+639171234568',
            'street' => 'Buyer Street',
            'barangay' => 'Central',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
        ]);
        $order = Order::query()->create([
            'order_no' => 'BRY-'.Str::upper(Str::random(12)),
            'buyer_id' => $buyer->id,
            'shipping_address_id' => $address->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => $address->phone,
            'address_line' => 'Buyer Street',
            'barangay' => 'Central',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'subtotal_minor' => 120000,
            'total_minor' => 120000,
            'status' => 'fulfilled',
            'payment_status' => 'paid',
            'placed_at' => now(),
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => 'SO-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => 'completed',
            'subtotal_minor' => 120000,
            'total_minor' => 120000,
            'completed_at' => now(),
        ]);
        $variant = $product->variants()->firstOrFail();
        $item = OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name' => $product->name,
            'variant_name' => $variant->name,
            'sku' => $variant->sku,
            'unit_price_minor' => (int) $variant->price_minor,
            'quantity' => 1,
            'subtotal_minor' => (int) $variant->price_minor,
        ]);

        return ProductReview::query()->create([
            'buyer_id' => $buyer->id,
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
            'order_item_id' => $item->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'store_id' => $store->id,
            'rating' => $rating,
            'comment' => 'Category filter review.',
            'is_visible' => $visible,
        ]);
    }
}
