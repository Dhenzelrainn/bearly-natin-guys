<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceSubcategory;
use App\Models\MarketplaceSubcategoryRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\StoreSubcategory;
use App\Models\User;
use App\Services\BuyerCatalogService;
use Database\Seeders\MarketplaceTaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MarketplaceTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    private array $businessCategories = [
        'fashion-and-apparel' => 'Fashion and Apparel',
        'home-and-furniture' => 'Home and Furniture',
        'electronics-and-gadgets' => 'Electronics and Gadgets',
        'jewelry-and-watches' => 'Jewelry and Watches',
        'beauty-and-personal-care' => 'Beauty and Personal Care',
        'food-and-gourmet' => 'Food and Gourmet',
        'sports-and-outdoors' => 'Sports and Outdoors',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->businessCategories as $slug => $name) {
            Category::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'is_active' => true],
            );
        }

        app(MarketplaceTaxonomySeeder::class)->run();
    }

    public function test_admin_only_can_manage_canonical_subcategories_and_deactivate_without_deleting(): void
    {
        $admin = $this->user('admin');
        $seller = $this->seller('fashion-and-apparel');
        $women = $this->marketplaceCategory('women-s-apparel');

        $this->actingAs($seller)
            ->get(route('admin.marketplace-taxonomy.index'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.marketplace-taxonomy.index'))
            ->assertOk()
            ->assertSee('Marketplace Taxonomy')
            ->assertSee('Canonical subcategories');

        $this->actingAs($admin)
            ->post(route('admin.marketplace-taxonomy.subcategories.store'), [
                'marketplace_category_id' => $women->id,
                'name' => '  Occasion   Wear  ',
                'position' => 40,
                'is_active' => false,
                'created_by_user_id' => $seller->id,
            ])
            ->assertRedirect(route('admin.marketplace-taxonomy.index'));

        $subcategory = MarketplaceSubcategory::query()
            ->where('marketplace_category_id', $women->id)
            ->where('name_key', 'occasion wear')
            ->firstOrFail();

        $this->assertSame('Occasion Wear', $subcategory->name);
        $this->assertSame('occasion-wear', $subcategory->slug);
        $this->assertSame($admin->id, $subcategory->created_by_user_id);
        $this->assertTrue($subcategory->is_active);

        $this->actingAs($admin)
            ->from(route('admin.marketplace-taxonomy.index'))
            ->post(route('admin.marketplace-taxonomy.subcategories.store'), [
                'marketplace_category_id' => $women->id,
                'name' => 'occasion    WEAR',
            ])
            ->assertRedirect(route('admin.marketplace-taxonomy.index'))
            ->assertSessionHasErrors('name');

        $this->actingAs($admin)
            ->patch(route('admin.marketplace-taxonomy.subcategories.update', $subcategory), [
                'name' => 'Special Occasion Wear',
                'position' => 7,
                'is_active' => false,
            ])
            ->assertRedirect(route('admin.marketplace-taxonomy.index'));

        $this->assertDatabaseHas('marketplace_subcategories', [
            'id' => $subcategory->id,
            'name' => 'Special Occasion Wear',
            'name_key' => 'special occasion wear',
            'slug' => 'occasion-wear',
            'position' => 7,
            'is_active' => false,
            'updated_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'marketplace_taxonomy',
            'action' => 'marketplace_subcategory.updated',
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_seller_requests_are_owned_scoped_and_admin_can_reuse_approve_or_reject_with_audit(): void
    {
        $sellerA = $this->seller('fashion-and-apparel', 'taxonomy-a');
        $sellerB = $this->seller('fashion-and-apparel', 'taxonomy-b');
        $admin = $this->user('admin');
        $women = $this->marketplaceCategory('women-s-apparel');
        $men = $this->marketplaceCategory('men-s-apparel');
        $existing = $this->marketplaceSubcategory('women-s-apparel', 'Dresses & Skirts');

        $this->actingAs($sellerA)
            ->post(route('seller.products.subcategory-requests.store'), [
                'suggested_name' => '  Dresses   & Skirts ',
                'reason' => 'We need this Buyer-facing grouping for our dresses.',
                'marketplace_category_id' => $men->id,
                'business_category_id' => Category::query()->where('slug', 'electronics-and-gadgets')->value('id'),
            ])
            ->assertRedirect(route('seller.products.subcategory-requests.index'));

        $reuseRequest = MarketplaceSubcategoryRequest::query()->where('seller_profile_id', $sellerA->sellerProfile->id)->firstOrFail();
        $this->assertSame((int) $sellerA->sellerProfile->approved_category_id, (int) $reuseRequest->business_category_id);
        $this->assertSame((int) $sellerA->sellerProfile->store->id, (int) $reuseRequest->store_id);
        $this->assertSame('pending_review', $reuseRequest->status);

        $this->actingAs($sellerB)
            ->from(route('seller.products.subcategory-requests.create'))
            ->post(route('seller.products.subcategory-requests.store'), [
                'suggested_name' => 'Dresses &   SKIRTS',
                'reason' => 'A duplicate request must be prevented.',
            ])
            ->assertRedirect(route('seller.products.subcategory-requests.create'))
            ->assertSessionHasErrors('suggested_name');

        $this->actingAs($sellerB)
            ->post(route('seller.products.subcategory-requests.store'), [
                'suggested_name' => 'Tailored Linen Sets',
                'reason' => 'This group will distinguish tailored sets for Buyers.',
            ])
            ->assertRedirect(route('seller.products.subcategory-requests.index'));
        $approveRequest = MarketplaceSubcategoryRequest::query()->where('seller_profile_id', $sellerB->sellerProfile->id)->firstOrFail();

        $this->actingAs($sellerA)
            ->post(route('seller.products.subcategory-requests.store'), [
                'suggested_name' => 'Seasonal Edit',
                'reason' => 'This group will help Buyers find seasonal releases.',
            ])
            ->assertRedirect(route('seller.products.subcategory-requests.index'));
        $rejectRequest = MarketplaceSubcategoryRequest::query()
            ->where('seller_profile_id', $sellerA->sellerProfile->id)
            ->where('suggested_name', 'Seasonal Edit')
            ->firstOrFail();

        $this->actingAs($sellerA)
            ->get(route('seller.products.subcategory-requests.index'))
            ->assertOk()
            ->assertSee('Dresses &amp; Skirts', false)
            ->assertSee('Seasonal Edit')
            ->assertDontSee('Tailored Linen Sets');

        $this->actingAs($sellerB)
            ->patch(route('admin.marketplace-taxonomy.requests.decide', $reuseRequest), [
                'decision' => 'reuse',
                'marketplace_subcategory_id' => $existing->id,
                'marketplace_category_id' => $men->id,
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->patch(route('admin.marketplace-taxonomy.requests.decide', $reuseRequest), [
                'decision' => 'reuse',
                'marketplace_subcategory_id' => $existing->id,
            ])
            ->assertRedirect(route('admin.marketplace-taxonomy.index'));

        $this->assertDatabaseHas('marketplace_subcategory_requests', [
            'id' => $reuseRequest->id,
            'status' => 'approved',
            'marketplace_category_id' => $women->id,
            'marketplace_subcategory_id' => $existing->id,
            'reviewed_by_user_id' => $admin->id,
            'decision_reason' => null,
        ]);
        $this->assertNotNull($reuseRequest->fresh()->reviewed_at);

        $this->actingAs($admin)
            ->patch(route('admin.marketplace-taxonomy.requests.decide', $approveRequest), [
                'decision' => 'approve',
                'marketplace_category_id' => $men->id,
                'decision_reason' => 'Placed in Men’s Apparel after review.',
            ])
            ->assertRedirect(route('admin.marketplace-taxonomy.index'));

        $createdSubcategory = MarketplaceSubcategory::query()
            ->where('marketplace_category_id', $men->id)
            ->where('name_key', 'tailored linen sets')
            ->firstOrFail();
        $this->assertDatabaseHas('marketplace_subcategory_requests', [
            'id' => $approveRequest->id,
            'status' => 'approved',
            'marketplace_category_id' => $men->id,
            'marketplace_subcategory_id' => $createdSubcategory->id,
            'reviewed_by_user_id' => $admin->id,
            'decision_reason' => 'Placed in Men’s Apparel after review.',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.marketplace-taxonomy.requests.decide', $rejectRequest), [
                'decision' => 'reject',
                'decision_reason' => 'This request duplicates an existing Buyer grouping.',
            ])
            ->assertRedirect(route('admin.marketplace-taxonomy.index'));

        $this->assertDatabaseHas('marketplace_subcategory_requests', [
            'id' => $rejectRequest->id,
            'status' => 'rejected',
            'marketplace_category_id' => null,
            'marketplace_subcategory_id' => null,
            'reviewed_by_user_id' => $admin->id,
            'decision_reason' => 'This request duplicates an existing Buyer grouping.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'marketplace_taxonomy',
            'action' => 'marketplace_subcategory_request.reuse',
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_seller_product_selection_rejects_inactive_foreign_parent_and_foreign_store_ids(): void
    {
        $seller = $this->seller('fashion-and-apparel', 'selection-owner');
        $otherSeller = $this->seller('fashion-and-apparel', 'selection-other');
        $activeSubcategory = $this->marketplaceSubcategory('women-s-apparel', 'Dresses & Skirts');
        $inactiveSubcategory = $this->marketplaceSubcategory('women-s-apparel', 'Tops & Blouses');
        $inactiveSubcategory->update(['is_active' => false]);
        $foreignParentSubcategory = $this->marketplaceSubcategory('electronics-and-gadgets', 'Phones & Tablets');
        $foreignStoreSection = StoreSubcategory::query()->create([
            'store_id' => $otherSeller->sellerProfile->store->id,
            'parent_category_id' => $otherSeller->sellerProfile->approved_category_id,
            'name' => 'Other Store Only',
            'slug' => 'other-store-only',
            'is_active' => true,
        ]);
        $payload = $this->validProductPayload($seller->sellerProfile->approvedCategory->name);

        foreach ([
            ['name' => 'Inactive Canonical Selection', 'marketplace_subcategory_id' => $inactiveSubcategory->id],
            ['name' => 'Foreign Parent Selection', 'marketplace_subcategory_id' => $foreignParentSubcategory->id],
            ['name' => 'Foreign Store Section Selection', 'marketplace_subcategory_id' => $activeSubcategory->id, 'subcategory_id' => $foreignStoreSection->id],
        ] as $invalidSelection) {
            $response = $this->actingAs($seller)
                ->from(route('seller.products.create'))
                ->post(route('seller.products.add'), array_merge($payload, $invalidSelection));

            $response->assertRedirect(route('seller.products.create'));
            if (($invalidSelection['name'] ?? '') === 'Foreign Store Section Selection') {
                $response->assertSessionHasErrors('subcategory_id');
            } else {
                $response->assertSessionHasErrors('marketplace_subcategory_id');
            }
        }

        $this->assertDatabaseMissing('products', ['name' => 'Inactive Canonical Selection']);
        $this->assertDatabaseMissing('products', ['name' => 'Foreign Parent Selection']);
        $this->assertDatabaseMissing('products', ['name' => 'Foreign Store Section Selection']);
    }

    public function test_buyer_category_uses_canonical_ids_hides_empty_or_inactive_subcategories_and_keeps_store_sections_store_scoped(): void
    {
        $seller = $this->seller('beauty-and-personal-care', 'buyer-taxonomy');
        $store = $seller->sellerProfile->store;
        $skinCare = $this->marketplaceSubcategory('health-and-beauty', 'Skincare');
        $inactive = $this->marketplaceSubcategory('health-and-beauty', 'Makeup & Nail Care');
        $inactive->update(['is_active' => false]);
        $storeSection = StoreSubcategory::query()->create([
            'store_id' => $store->id,
            'parent_category_id' => $seller->sellerProfile->approved_category_id,
            'name' => 'Private Vendor Set',
            'slug' => 'private-vendor-set',
            'is_active' => true,
        ]);

        $classified = $this->visibleProduct($seller, 'Canonical Skin Product', $skinCare, $storeSection);
        $this->visibleProduct($seller, 'Inactive Classified Product', $inactive);
        $this->visibleProduct($seller, 'Legacy Unambiguous Product', null);

        $buyer = $this->user('buyer');
        $categoryPage = $this->actingAs($buyer)
            ->get(route('products.index', ['category' => 'health-and-beauty']))
            ->assertOk()
            ->assertSee('Canonical Skin Product')
            ->assertSee('Legacy Unambiguous Product')
            ->assertDontSee('Inactive Classified Product')
            ->assertSee('All items')
            ->assertSee('data-sub="skincare"', false)
            ->assertSee('data-subcategory-id="'.$skinCare->id.'"', false)
            ->assertDontSee('data-sub="makeup-nail-care"', false)
            ->assertDontSee('Haircare')
            ->assertDontSee('Private Vendor Set');

        $this->assertStringContainsString(
            '"marketplace_subcategory_slug":"skincare"',
            $categoryPage->getContent(),
        );

        $this->actingAs($buyer)
            ->get(route('products.show', $classified))
            ->assertOk()
            ->assertSee(route('products.index', ['category' => 'health-and-beauty']), false)
            ->assertSee('Health and Beauty');

        $this->actingAs($buyer)
            ->get(route('stores.show', $store->slug))
            ->assertOk()
            ->assertSee('Shop by Store section')
            ->assertSee('Private Vendor Set')
            ->assertSee('Store section · Private Vendor Set');

        $this->actingAs($buyer)
            ->get(route('products.index', ['search' => 'Private Vendor Set']))
            ->assertOk()
            ->assertDontSee('Canonical Skin Product');

        $fashionSeller = $this->seller('fashion-and-apparel', 'ambiguous-legacy');
        $fashionProduct = $this->visibleProduct($fashionSeller, 'Ambiguous Fashion Legacy Product', null);
        foreach (['women-s-apparel', 'men-s-apparel'] as $branch) {
            $this->actingAs($buyer)
                ->get(route('products.index', ['category' => $branch]))
                ->assertOk()
                ->assertDontSee($fashionProduct->name);
        }
    }

    public function test_buyer_search_and_related_products_stay_within_canonical_men_and_women_branches(): void
    {
        $seller = $this->seller('fashion-and-apparel', 'branch-search');
        $menSubcategory = $this->marketplaceSubcategory('men-s-apparel', 'Casual Shirts & Pants');
        $womenSubcategory = $this->marketplaceSubcategory('women-s-apparel', 'Tops & Blouses');
        $menProduct = $this->visibleProduct($seller, 'BranchSearch Men Shirt', $menSubcategory);
        $this->visibleProduct($seller, 'BranchSearch Men Jacket', $menSubcategory);
        $womenProduct = $this->visibleProduct($seller, 'BranchSearch Women Blouse', $womenSubcategory);
        $legacyProduct = $this->visibleProduct($seller, 'BranchSearch Unassigned Legacy', null);
        $buyer = $this->user('buyer', 'branch-search');

        $this->actingAs($buyer)
            ->get(route('products.index', ['search' => 'BranchSearch', 'category' => 'men-s-apparel']))
            ->assertOk()
            ->assertSee('BranchSearch Men Shirt')
            ->assertSee('BranchSearch Men Jacket')
            ->assertDontSee('BranchSearch Women Blouse')
            ->assertDontSee('BranchSearch Unassigned Legacy');

        $this->actingAs($buyer)
            ->get(route('products.index', ['search' => 'BranchSearch', 'category' => 'women-s-apparel']))
            ->assertOk()
            ->assertSee('BranchSearch Women Blouse')
            ->assertDontSee('BranchSearch Men Shirt')
            ->assertDontSee('BranchSearch Unassigned Legacy');

        $this->actingAs($buyer)
            ->get(route('products.show', $menProduct))
            ->assertOk()
            ->assertSee('BranchSearch Men Jacket')
            ->assertDontSee('BranchSearch Women Blouse')
            ->assertDontSee($legacyProduct->name)
            ->assertDontSee($womenProduct->name);
    }

    public function test_live_electronics_subcategory_filter_is_rendered_and_filters_by_canonical_id_with_database_pagination(): void
    {
        $seller = $this->seller('electronics-and-gadgets', 'buyer-electronics-filter');
        $phones = $this->marketplaceSubcategory('electronics-and-gadgets', 'Phones & Tablets');
        $audio = $this->marketplaceSubcategory('electronics-and-gadgets', 'Audio & Entertainment');
        $phoneProduct = $this->visibleProduct($seller, 'Canonical Filter Phone', $phones);
        $this->visibleProduct($seller, 'Canonical Filter Headphones', $audio);
        $this->visibleProduct($seller, 'Unassigned Electronics Listing', null);
        $buyer = $this->user('buyer', 'buyer-electronics-filter');

        $page = $this->actingAs($buyer)
            ->get(route('products.index', ['category' => 'electronics-and-gadgets']))
            ->assertOk()
            ->assertSee('data-live-catalog="true"', false)
            ->assertSee('data-canonical-marketplace-taxonomy="true"', false)
            ->assertSee('data-sub="phones-tablets"', false)
            ->assertSee('data-subcategory-id="'.$phones->id.'"', false)
            ->assertSee('Canonical Filter Phone')
            ->assertSee('Canonical Filter Headphones');

        $this->actingAs($buyer)
            ->get(route('products.index', ['category' => 'electronics-and-gadgets', 'sub' => $phones->slug]))
            ->assertOk()
            ->assertSee($phoneProduct->name)
            ->assertDontSee('Canonical Filter Headphones')
            ->assertDontSee('Unassigned Electronics Listing');

        $catalog = app(BuyerCatalogService::class);
        $firstPage = $catalog->publishedProductsForMarketplaceCategory($this->marketplaceCategory('electronics-and-gadgets'), null, 2);
        $this->assertSame(3, $firstPage->total());
        $this->assertCount(2, $firstPage->items());
        $this->assertTrue($firstPage->hasMorePages());

        $homePreview = $catalog->publishedProducts(limit: 2);
        $this->assertCount(2, $homePreview);
    }

    public function test_store_products_are_database_paginated_and_store_section_filters_survive_page_changes(): void
    {
        $seller = $this->seller('electronics-and-gadgets', 'store-pagination');
        $store = $seller->sellerProfile->store;
        $storeSection = StoreSubcategory::query()->create([
            'store_id' => $store->id,
            'parent_category_id' => $seller->sellerProfile->approved_category_id,
            'name' => 'Store Pagination Section',
            'slug' => 'store-pagination-section',
            'is_active' => true,
        ]);

        for ($index = 1; $index <= 25; $index++) {
            $this->visibleProduct($seller, 'Store Pagination Product '.$index, null, $storeSection);
        }

        $buyer = $this->user('buyer', 'store-pagination');

        $this->actingAs($buyer)
            ->get(route('stores.show', $store->slug))
            ->assertOk()
            ->assertSee('24 shown · 25 in this store')
            ->assertSee('Store Pagination Product 25')
            ->assertSee('Shop by Store section')
            ->assertSee(route('stores.show', ['store' => $store->slug, 'subcategory' => $storeSection->id]), false);

        $this->actingAs($buyer)
            ->get(route('stores.show', ['store' => $store->slug, 'subcategory' => $storeSection->id, 'page' => 2]))
            ->assertOk()
            ->assertSee('1 shown · 25 in this store')
            ->assertSee('Store Pagination Product 1')
            ->assertSee('Page 2 of 2');
    }

    public function test_existing_product_business_category_and_store_section_assignment_survive_edits_without_text_backfill(): void
    {
        $seller = $this->seller('electronics-and-gadgets', 'preservation');
        $store = $seller->sellerProfile->store;
        $storeSection = StoreSubcategory::query()->create([
            'store_id' => $store->id,
            'parent_category_id' => $seller->sellerProfile->approved_category_id,
            'name' => 'Audio & Entertainment',
            'slug' => 'audio-entertainment',
            'is_active' => true,
        ]);
        $canonical = $this->marketplaceSubcategory('electronics-and-gadgets', 'Audio & Entertainment');
        $legacy = Product::query()->create([
            'store_id' => $store->id,
            'category_id' => $seller->sellerProfile->approved_category_id,
            'subcategory_id' => $storeSection->id,
            'marketplace_subcategory_id' => $canonical->id,
            'name' => 'Preserved Taxonomy Product',
            'slug' => 'preserved-taxonomy-product',
            'product_status' => 'draft',
            'compliance_status' => 'clear',
        ]);
        ProductVariant::query()->create([
            'product_id' => $legacy->id,
            'sku' => 'PRESERVED-TAXONOMY-001',
            'name' => 'Default',
            'price_minor' => 15000,
            'stock_on_hand' => 5,
            'is_active' => true,
        ]);

        $unassignedLegacy = Product::query()->create([
            'store_id' => $store->id,
            'category_id' => $seller->sellerProfile->approved_category_id,
            'subcategory_id' => $storeSection->id,
            'name' => 'Unassigned Legacy Product',
            'slug' => 'unassigned-legacy-product',
            'product_status' => 'draft',
            'compliance_status' => 'clear',
        ]);

        app(MarketplaceTaxonomySeeder::class)->run();

        $this->assertDatabaseHas('products', [
            'id' => $unassignedLegacy->id,
            'category_id' => $seller->sellerProfile->approved_category_id,
            'subcategory_id' => $storeSection->id,
            'marketplace_subcategory_id' => null,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $legacy->id,
            'category_id' => $seller->sellerProfile->approved_category_id,
            'subcategory_id' => $storeSection->id,
            'marketplace_subcategory_id' => $canonical->id,
        ]);

        $this->actingAs($seller)
            ->put(route('seller.products.update', $legacy), $this->validProductPayload(
                $seller->sellerProfile->approvedCategory->name,
                [
                    'name' => 'Preserved Taxonomy Product Updated',
                    'subcategory_id' => $storeSection->id,
                    'sku' => 'PRESERVED-TAXONOMY-001',
                ],
            ))
            ->assertRedirect('/seller/products');

        $this->assertDatabaseHas('products', [
            'id' => $legacy->id,
            'category_id' => $seller->sellerProfile->approved_category_id,
            'subcategory_id' => $storeSection->id,
            'marketplace_subcategory_id' => $canonical->id,
            'name' => 'Preserved Taxonomy Product Updated',
        ]);
        $this->assertDatabaseHas('store_subcategories', [
            'id' => $storeSection->id,
            'name' => 'Audio & Entertainment',
            'store_id' => $store->id,
        ]);
    }

    private function user(string $role, ?string $suffix = null): User
    {
        static $sequence = 0;
        $sequence++;

        return User::query()->create([
            'name' => ucfirst($role).' Taxonomy User',
            'first_name' => ucfirst($role),
            'last_name' => 'Taxonomy',
            'email' => ($suffix ?? $role).'-taxonomy-'.$sequence.'@example.test',
            'role' => $role,
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
    }

    private function seller(string $businessCategorySlug, ?string $suffix = null): User
    {
        $seller = $this->user('seller', $suffix);
        $businessCategory = Category::query()->where('slug', $businessCategorySlug)->firstOrFail();
        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => $seller->name.' Business',
            'approved_category_id' => $businessCategory->id,
            'approved_at' => now(),
        ]);
        Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => $seller->name.' Store',
            'slug' => ($suffix ?? 'seller').'-taxonomy-store-'.$seller->id,
            'publication_status' => 'published',
            'published_at' => now(),
        ]);

        return $seller->fresh(['sellerProfile.store', 'sellerProfile.approvedCategory']);
    }

    private function marketplaceCategory(string $slug): MarketplaceCategory
    {
        return MarketplaceCategory::query()->where('slug', $slug)->firstOrFail();
    }

    private function marketplaceSubcategory(string $categorySlug, string $name): MarketplaceSubcategory
    {
        return MarketplaceSubcategory::query()
            ->where('marketplace_category_id', $this->marketplaceCategory($categorySlug)->id)
            ->where('name_key', MarketplaceTaxonomySeeder::nameKey($name))
            ->firstOrFail();
    }

    private function visibleProduct(
        User $seller,
        string $name,
        ?MarketplaceSubcategory $marketplaceSubcategory,
        ?StoreSubcategory $storeSubcategory = null,
    ): Product {
        $category = $seller->sellerProfile->approvedCategory;
        $product = Product::query()->create([
            'store_id' => $seller->sellerProfile->store->id,
            'category_id' => $category->id,
            'subcategory_id' => $storeSubcategory?->id,
            'marketplace_subcategory_id' => $marketplaceSubcategory?->id,
            'name' => $name,
            'slug' => str($name)->slug().'-'.$seller->id,
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'published_at' => now(),
        ]);
        ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => str($name)->upper()->slug('-').'-'.$seller->id,
            'name' => 'Default',
            'price_minor' => 10000,
            'stock_on_hand' => 5,
            'is_active' => true,
        ]);

        return $product;
    }

    private function validProductPayload(string $categoryName, array $overrides = []): array
    {
        return array_merge([
            'product_mode' => 'single',
            'name' => 'Taxonomy Test Product',
            'category' => $categoryName,
            'price' => '100.00',
            'sku' => 'TAXONOMY-TEST-'.uniqid(),
            'stock' => 5,
            'low_stock_threshold' => 2,
            'package_weight_kg' => '0.250',
            'package_length_cm' => '24',
            'package_width_cm' => '18',
            'package_height_cm' => '8',
            'intent' => 'draft',
        ], $overrides);
    }
}
