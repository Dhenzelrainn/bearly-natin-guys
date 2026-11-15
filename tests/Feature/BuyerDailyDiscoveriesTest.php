<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Category;
use App\Models\MarketplaceCategory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\StorePromotion;
use App\Models\User;
use Database\Seeders\MarketplaceTaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuyerDailyDiscoveriesTest extends TestCase
{
    use RefreshDatabase;

    private Category $electronicsBusinessCategory;

    private Category $homeBusinessCategory;

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

        $this->electronicsBusinessCategory = Category::query()
            ->where('slug', 'electronics-and-gadgets')
            ->firstOrFail();
        $this->homeBusinessCategory = Category::query()
            ->where('slug', 'home-and-furniture')
            ->firstOrFail();

        app(MarketplaceTaxonomySeeder::class)->run();
    }

    public function test_home_view_all_links_to_a_buyer_only_daily_discoveries_page_with_full_pagination(): void
    {
        $buyer = $this->buyer();
        $seller = $this->seller('daily-discoveries-full-list', $this->electronicsBusinessCategory);
        $subcategory = $this->subcategory('electronics-and-gadgets');
        $publishedAt = now();

        for ($index = 1; $index <= 65; $index++) {
            $this->product(
                $seller,
                $this->electronicsBusinessCategory,
                (int) $subcategory->id,
                'Daily Discovery '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                159900,
                $publishedAt->copy()->subSeconds($index),
            );
        }

        $this->get(route('buyer.daily-discoveries.index'))
            ->assertRedirect(route('login'));

        $this->actingAs($buyer)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('id="view-all"', false)
            ->assertSee('href="'.route('buyer.daily-discoveries.index').'"', false)
            ->assertDontSee('<button class="text-button" id="view-all"', false);

        $this->actingAs($buyer)
            ->get(route('buyer.daily-discoveries.index'))
            ->assertOk()
            ->assertSee('Daily Discoveries')
            ->assertSee('Back to homepage')
            ->assertSee('href="'.route('home').'"', false)
            ->assertSee('65 products')
            ->assertSee('Page 1 of 4')
            ->assertSee('aria-label="Product filters"', false)
            ->assertSee('name="q"', false)
            ->assertSee('name="category"', false)
            ->assertSee('name="min"', false)
            ->assertSee('name="max"', false)
            ->assertSee('id="daily-discoveries-min-range"', false)
            ->assertSee('Under ₱500')
            ->assertSee('name="sort"', false)
            ->assertSee('Apply filters')
            ->assertSee('Daily Discovery 01')
            ->assertSee('Daily Discovery 20')
            ->assertDontSee('Daily Discovery 21')
            ->assertDontSee('home-campaign-showcase')
            ->assertDontSee('home-flash-deals')
            ->assertDontSee('home-trending-shell');

        $this->actingAs($buyer)
            ->get(route('buyer.daily-discoveries.index', ['page' => 4]))
            ->assertOk()
            ->assertSee('Page 4 of 4')
            ->assertSee('Daily Discovery 61')
            ->assertSee('Daily Discovery 65')
            ->assertDontSee('Daily Discovery 01');
    }

    public function test_daily_discoveries_filters_by_marketplace_category_and_effective_variant_price(): void
    {
        $buyer = $this->buyer();
        $electronicsSeller = $this->seller('daily-discoveries-electronics', $this->electronicsBusinessCategory);
        $electronicsSubcategory = $this->subcategory('electronics-and-gadgets');
        $homeSeller = $this->seller('daily-discoveries-home', $this->homeBusinessCategory);
        $homeSubcategory = $this->subcategory('home-and-garden');

        for ($index = 1; $index <= 22; $index++) {
            $this->product(
                $electronicsSeller,
                $this->electronicsBusinessCategory,
                (int) $electronicsSubcategory->id,
                'Filtered Electronics '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                50000 + ($index * 1000),
                now()->subSeconds($index),
            );
        }

        $cheapProduct = $this->product(
            $electronicsSeller,
            $this->electronicsBusinessCategory,
            (int) $electronicsSubcategory->id,
            'Below Range Electronics',
            49900,
        );
        $otherCategoryProduct = $this->product(
            $homeSeller,
            $this->homeBusinessCategory,
            (int) $homeSubcategory->id,
            'Other Category Home Product',
            55000,
        );

        $query = [
            'category' => 'electronics-and-gadgets',
            'min' => 500,
            'max' => 800,
            'sort' => 'price-low',
        ];

        $this->actingAs($buyer)
            ->get(route('buyer.daily-discoveries.index', $query))
            ->assertOk()
            ->assertSee('22 products')
            ->assertSee('Page 1 of 2')
            ->assertSee('Filtered Electronics 01')
            ->assertSee('Filtered Electronics 20')
            ->assertDontSee($cheapProduct->name)
            ->assertDontSee($otherCategoryProduct->name)
            ->assertSee(e(route('buyer.daily-discoveries.index', [...$query, 'page' => 2])), false);

        $this->actingAs($buyer)
            ->get(route('buyer.daily-discoveries.index', [...$query, 'page' => 2]))
            ->assertOk()
            ->assertSee('Filtered Electronics 21')
            ->assertSee('Filtered Electronics 22')
            ->assertDontSee('Filtered Electronics 01');

        $this->actingAs($buyer)
            ->get(route('buyer.daily-discoveries.index', [...$query, 'page' => 99]))
            ->assertRedirect(route('buyer.daily-discoveries.index', [...$query, 'page' => 2]));

        $discountedProduct = $this->product(
            $electronicsSeller,
            $this->electronicsBusinessCategory,
            (int) $electronicsSubcategory->id,
            'Daily Discounted Electronics',
            159900,
        );
        $regularPriceProduct = $this->product(
            $electronicsSeller,
            $this->electronicsBusinessCategory,
            (int) $electronicsSubcategory->id,
            'Daily Regular Price Electronics',
            159900,
        );
        $startsOn = now('Asia/Manila')->toDateString();
        $discount = StorePromotion::query()->create([
            'store_id' => $electronicsSeller['store']->id,
            'created_by' => $electronicsSeller['user']->id,
            'name' => 'Daily Discoveries test discount',
            'type' => StorePromotion::TYPE_PRODUCT_DISCOUNT,
            'status' => StorePromotion::STATUS_ACTIVE,
            'discount_type' => StorePromotion::DISCOUNT_PERCENT,
            'discount_percent' => 10,
            'buyer_campaign_name' => 'Daily Discoveries Markdown',
            'starts_on' => $startsOn,
            'ends_on' => now('Asia/Manila')->addDay()->toDateString(),
            'starts_time' => '00:00:00',
            'ends_time' => '23:59:59',
        ]);
        $discount->products()->attach($discountedProduct->id);

        $this->actingAs($buyer)
            ->get(route('buyer.daily-discoveries.index', [
                'category' => 'electronics-and-gadgets',
                'min' => 1400,
                'max' => 1500,
            ]))
            ->assertOk()
            ->assertSee($discountedProduct->name)
            ->assertSee('₱1,439.10')
            ->assertDontSee($regularPriceProduct->name);
    }

    public function test_daily_discoveries_shows_an_empty_state_when_no_products_match(): void
    {
        $this->actingAs($this->buyer())
            ->get(route('buyer.daily-discoveries.index', [
                'min' => 5000,
                'max' => 6000,
            ]))
            ->assertOk()
            ->assertSee('0 products')
            ->assertSee('No products match these filters')
            ->assertSee('Clear filters');
    }

    public function test_daily_discoveries_searches_and_filters_by_available_size_and_color_facets(): void
    {
        $buyer = $this->buyer();
        $seller = $this->seller('daily-discoveries-size-color', $this->electronicsBusinessCategory);
        $subcategory = $this->subcategory('electronics-and-gadgets');
        $matchingProduct = $this->product(
            $seller,
            $this->electronicsBusinessCategory,
            (int) $subcategory->id,
            'Facet Match Headphones',
            24900,
            options: ['size' => 'M', 'color' => 'Blue'],
        );
        $this->product(
            $seller,
            $this->electronicsBusinessCategory,
            (int) $subcategory->id,
            'Facet Other Headphones',
            25900,
            options: ['size' => 'M', 'color' => 'Blue'],
        );

        $this->actingAs($buyer)
            ->get(route('buyer.daily-discoveries.index', ['category' => 'electronics-and-gadgets']))
            ->assertOk()
            ->assertSee('name="size[]" value="M"', false)
            ->assertSee('name="color[]" value="Blue"', false);

        $this->actingAs($buyer)
            ->get(route('buyer.daily-discoveries.index', [
                'category' => 'electronics-and-gadgets',
                'q' => 'Facet Match',
                'size' => ['M'],
                'color' => ['Blue'],
            ]))
            ->assertOk()
            ->assertSee('Selected (4)')
            ->assertSee($matchingProduct->name)
            ->assertDontSee('Facet Other Headphones')
            ->assertSee('name="size[]" value="M" checked', false)
            ->assertSee('name="color[]" value="Blue" checked', false);
    }

    private function seller(string $suffix, Category $approvedCategory): array
    {
        $user = User::query()->create([
            'name' => 'Daily Discoveries Seller '.$suffix,
            'first_name' => 'Daily Discoveries',
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
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
        ]);
        $profile = SellerProfile::query()->create([
            'user_id' => $user->id,
            'legal_business_name' => 'Daily Discoveries Business '.$suffix,
            'approved_category_id' => $approvedCategory->id,
            'pickup_address_id' => $address->id,
            'standing_status' => 'good_standing',
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => 'Daily Discoveries Store '.$suffix,
            'slug' => $suffix.'-'.Str::lower(Str::random(6)),
            'publication_status' => 'published',
            'published_at' => now(),
        ]);

        return ['user' => $user, 'store' => $store];
    }

    private function subcategory(string $categorySlug)
    {
        return MarketplaceCategory::query()
            ->where('slug', $categorySlug)
            ->firstOrFail()
            ->subcategories()
            ->where('is_active', true)
            ->firstOrFail();
    }

    private function product(
        array $seller,
        Category $businessCategory,
        int $marketplaceSubcategoryId,
        string $name,
        int $priceMinor,
        mixed $publishedAt = null,
        array $options = [],
    ): Product {
        $product = Product::query()->create([
            'store_id' => $seller['store']->id,
            'category_id' => $businessCategory->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(7)),
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'published_at' => $publishedAt ?? now(),
            'marketplace_subcategory_id' => $marketplaceSubcategoryId,
        ]);

        ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'DAILY-'.Str::upper(Str::random(12)),
            'name' => 'Standard',
            'options' => $options,
            'price_minor' => $priceMinor,
            'stock_on_hand' => 5,
            'is_active' => true,
            'position' => 0,
        ]);

        return $product;
    }

    private function buyer(): User
    {
        return User::query()->create([
            'name' => 'Daily Discoveries Buyer',
            'first_name' => 'Daily Discoveries',
            'last_name' => 'Buyer',
            'email' => 'daily-discoveries-'.Str::lower(Str::random(8)).'@example.test',
            'role' => 'buyer',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
    }
}
