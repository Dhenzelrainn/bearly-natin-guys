<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuyerCatalogSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_by_store_name_shows_the_visible_store_and_its_relevant_products(): void
    {
        $category = $this->category();
        $matchingStore = $this->store($category, 'Dhenzel Gadgets');
        $unrelatedStore = $this->store($category, 'Everyday Audio');
        $draftStore = $this->store($category, 'Dhenzel Gadgets Draft', 'draft');
        $suspendedStore = $this->store($category, 'Dhenzel Gadgets Suspended', standingStatus: 'suspended');

        $this->product($category, $matchingStore, 'Wireless Headphones');
        $this->product($category, $unrelatedStore, 'Everyday Bluetooth Speaker');
        $this->product($category, $draftStore, 'Draft Store Headphones');
        $this->product($category, $suspendedStore, 'Suspended Store Headphones');

        $response = $this->actingAs($this->buyer())
            ->get(route('products.index', ['search' => 'Dhenzel Gadgets']));

        $response->assertOk()
            ->assertSee('Stores matching your search')
            ->assertSee('Dhenzel Gadgets')
            ->assertSee('Wireless Headphones')
            ->assertSee(route('stores.show', ['store' => $matchingStore->slug]), false)
            ->assertDontSee('Everyday Bluetooth Speaker')
            ->assertDontSee('Dhenzel Gadgets Draft')
            ->assertDontSee('Draft Store Headphones')
            ->assertDontSee('Dhenzel Gadgets Suspended')
            ->assertDontSee('Suspended Store Headphones');
    }

    public function test_search_accepts_the_legacy_q_parameter_and_finds_products(): void
    {
        $category = $this->category();
        $store = $this->store($category, 'Audio Corner');
        $this->product($category, $store, 'Noise Cancelling Headphones');

        $this->actingAs($this->buyer())
            ->get(route('products.index', ['q' => 'Noise Cancelling']))
            ->assertOk()
            ->assertSee('Noise Cancelling Headphones')
            ->assertSee('value="Noise Cancelling"', false)
            ->assertDontSee('Stores matching your search');
    }

    public function test_buyer_catalog_uses_only_supported_cod_orderability(): void
    {
        $category = $this->category();
        $store = $this->store($category, 'COD and Online Store');
        $codProduct = $this->product($category, $store, 'COD Supported Product');
        $codProduct->update(['payment_methods' => ['cod', 'online']]);
        $onlineOnly = $this->product($category, $store, 'Unsupported Online Only Product');
        $onlineOnly->update(['payment_methods' => ['online']]);
        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->get(route('products.index', ['search' => 'COD Supported Product']))
            ->assertOk()
            ->assertSee('COD Supported Product')
            ->assertDontSee('Online payment');

        $this->actingAs($buyer)
            ->get(route('products.index', ['search' => 'Unsupported Online Only Product']))
            ->assertOk()
            ->assertSee('0 results')
            ->assertDontSee('href="'.route('products.show', $onlineOnly).'"', false);

        $this->actingAs($buyer)
            ->get(route('products.show', $codProduct))
            ->assertOk()
            ->assertSee('Cash on Delivery')
            ->assertDontSee('Online payment');

        $this->actingAs($buyer)
            ->get(route('products.show', $onlineOnly))
            ->assertNotFound();

        $this->actingAs($buyer)
            ->postJson(route('cart.add'), [
                'product_variant_id' => $onlineOnly->variants()->value('id'),
                'quantity' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_variant_id');
    }

    public function test_product_detail_store_count_matches_the_cod_orderable_store_catalog(): void
    {
        $category = $this->category();
        $store = $this->store($category, 'Store Count Orderability');
        $orderableProduct = $this->product($category, $store, 'Store Count COD Product');
        $orderableProduct->update(['payment_methods' => ['cod']]);
        $onlineOnlyProduct = $this->product($category, $store, 'Store Count Online Product');
        $onlineOnlyProduct->update(['payment_methods' => ['online']]);
        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->get(route('stores.show', $store->slug))
            ->assertOk()
            ->assertSee('1 shown · 1 in this store')
            ->assertSee($orderableProduct->name)
            ->assertDontSee($onlineOnlyProduct->name);

        $this->actingAs($buyer)
            ->get(route('products.show', $orderableProduct))
            ->assertOk()
            ->assertSee('<span><strong>1</strong><small>Products</small></span>', false)
            ->assertDontSee('<span><strong>2</strong><small>Products</small></span>', false);
    }

    public function test_homepage_search_form_submits_to_the_live_catalog_search(): void
    {
        $this->actingAs($this->buyer())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('action="'.route('products.index').'"', false)
            ->assertSee('method="get"', false)
            ->assertSee('name="search"', false)
            ->assertSee('name="category"', false);
    }

    private function category(): Category
    {
        return Category::query()->where('slug', 'electronics-and-gadgets')->firstOrFail();
    }

    private function store(
        Category $category,
        string $name,
        string $publicationStatus = 'published',
        string $standingStatus = 'good_standing',
        string $userStatus = 'active',
    ): Store {
        $seller = User::create([
            'name' => $name.' Seller',
            'first_name' => $name,
            'last_name' => 'Seller',
            'email' => Str::uuid().'@example.test',
            'role' => 'seller',
            'status' => $userStatus,
            'password' => Hash::make('Password123'),
        ]);

        $profile = SellerProfile::create([
            'user_id' => $seller->id,
            'legal_business_name' => $name.' Business',
            'approved_category_id' => $category->id,
            'standing_status' => $standingStatus,
            'approved_at' => now(),
        ]);

        return Store::create([
            'seller_profile_id' => $profile->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'publication_status' => $publicationStatus,
            'published_at' => $publicationStatus === 'published' ? now() : null,
        ]);
    }

    private function product(Category $category, Store $store, string $name): Product
    {
        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'description' => 'A quality everyday product.',
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'published_at' => now(),
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => Str::upper(Str::random(14)),
            'name' => 'Standard',
            'price_minor' => 159900,
            'stock_on_hand' => 5,
            'stock_reserved' => 0,
            'is_active' => true,
        ]);

        return $product;
    }

    private function buyer(): User
    {
        return User::create([
            'name' => 'Search Buyer',
            'first_name' => 'Search',
            'last_name' => 'Buyer',
            'email' => Str::uuid().'@example.test',
            'role' => 'buyer',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
    }
}
