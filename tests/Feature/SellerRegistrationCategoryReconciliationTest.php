<?php

namespace Tests\Feature;

use App\Models\AccountApplication;
use App\Models\Category;
use App\Models\MarketplaceCategory;
use App\Models\Product;
use App\Models\Role;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\StoreSubcategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MarketplaceTaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SellerRegistrationCategoryReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private array $buyerTaxonomy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->buyerTaxonomy = json_decode(
            file_get_contents(resource_path('data/buyer-categories.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    public function test_all_buyer_categories_are_registration_options_and_map_to_the_matching_existing_roots(): void
    {
        $registrationCategories = Category::query()
            ->where('is_registration_option', true)
            ->where('is_active', true)
            ->with('marketplaceCategories')
            ->get()
            ->sortBy(fn (Category $category): int => (int) $category->marketplaceCategories->first()->position)
            ->values();

        $this->assertSame(
            collect($this->buyerTaxonomy)->pluck('slug')->all(),
            $registrationCategories->map(fn (Category $category): string => $category->marketplaceCategories->sole()->slug)->all(),
        );
        $this->assertCount(12, $registrationCategories);
        $this->assertSame(18, Category::query()->count());
        $this->assertDatabaseCount('marketplace_categories', 12);
        $this->assertDatabaseCount('marketplace_subcategories', 72);
        $this->assertDatabaseCount('marketplace_category_business_categories', 16);

        foreach ($this->buyerTaxonomy as $entry) {
            $businessCategory = $registrationCategories->first(
                fn (Category $category): bool => $category->marketplaceCategories->sole()->slug === $entry['slug'],
            );
            $buyerRoot = MarketplaceCategory::query()->where('slug', $entry['slug'])->firstOrFail();

            $this->assertNotNull($businessCategory);
            $this->assertSame($entry['name'], $businessCategory->name);
            $this->assertSame($buyerRoot->id, $businessCategory->marketplaceCategories->sole()->id);
            $this->assertSame(
                count($entry['subcategories']),
                $buyerRoot->subcategories()->where('is_active', true)->count(),
                $entry['slug'].' canonical subcategories',
            );
            $this->assertSame(
                collect($entry['subcategories'])
                    ->map(fn (string $name): string => MarketplaceTaxonomySeeder::nameKey($name))
                    ->all(),
                $buyerRoot->subcategories()->where('is_active', true)->orderBy('position')->pluck('name_key')->all(),
                $entry['slug'].' exact canonical subcategories',
            );
        }
    }

    public function test_legacy_categories_and_mappings_remain_separate_and_unmapped_legacy_roots_stay_unmapped(): void
    {
        $expectedLegacy = [
            ['fashion-and-apparel', 'Fashion and Apparel', false],
            ['jewelry-and-watches', 'Jewelry and Watches', true],
            ['electronics-and-gadgets', 'Electronics and Gadgets', true],
            ['home-and-furniture', 'Home and Furniture', false],
            ['beauty-and-personal-care', 'Beauty and Personal Care', false],
            ['food-and-gourmet', 'Food and Gourmet', true],
            ['sports-and-outdoors', 'Sports and Outdoors', true],
            ['books-and-stationery', 'Books and Stationery', false],
            ['automotive-and-parts', 'Automotive and Parts', false],
            ['toys-and-hobbies', 'Toys and Hobbies', false],
        ];

        foreach ($expectedLegacy as [$slug, $name, $isRegistrationOption]) {
            $category = Category::query()->where('slug', $slug)->firstOrFail();
            $this->assertSame($name, $category->name);
            $this->assertTrue($category->is_active);
            $this->assertSame($isRegistrationOption, $category->is_registration_option);
        }

        $fashion = Category::query()->where('slug', 'fashion-and-apparel')->firstOrFail();
        $this->assertSame(
            ['men-s-apparel', 'women-s-apparel'],
            $fashion->marketplaceCategories()->orderBy('slug')->pluck('slug')->all(),
        );

        foreach (['books-and-stationery', 'automotive-and-parts', 'toys-and-hobbies'] as $unmappedLegacySlug) {
            $legacy = Category::query()->where('slug', $unmappedLegacySlug)->firstOrFail();
            $this->assertSame(0, $legacy->marketplaceCategories()->count(), $unmappedLegacySlug.' legacy mapping');
        }
    }

    public function test_rerunning_the_additive_data_migration_preserves_legacy_approval_and_product_assignments(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'business_category' => 'Electronics and Gadgets',
        ]);
        $electronics = Category::query()->where('slug', 'electronics-and-gadgets')->firstOrFail();
        $application = AccountApplication::query()->create([
            'application_no' => 'BRLY-SEL-LEGACY-001',
            'user_id' => $seller->id,
            'requested_role_id' => Role::query()->where('name', 'seller')->value('id'),
            'business_name' => 'Legacy Electronics Seller',
            'business_category_id' => $electronics->id,
            'status' => 'approved',
            'submitted_at' => now()->subDay(),
            'decided_at' => now()->subDay(),
        ]);
        $legacyUnlinkedSeller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'business_category' => 'Toys and Hobbies',
        ]);
        $legacyUnlinkedApplication = AccountApplication::query()->create([
            'application_no' => 'BRLY-SEL-LEGACY-NULL-001',
            'user_id' => $legacyUnlinkedSeller->id,
            'requested_role_id' => Role::query()->where('name', 'seller')->value('id'),
            'business_name' => 'Legacy Unlinked Seller',
            'business_category_id' => null,
            'status' => 'approved',
            'submitted_at' => now()->subDay(),
            'decided_at' => now()->subDay(),
        ]);
        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'application_id' => $application->id,
            'legal_business_name' => 'Legacy Electronics Seller',
            'approved_category_id' => $electronics->id,
            'approved_at' => now()->subDay(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => 'Legacy Electronics Store',
            'slug' => 'legacy-electronics-store',
            'publication_status' => 'draft',
        ]);
        $storeSection = StoreSubcategory::query()->create([
            'store_id' => $store->id,
            'parent_category_id' => $electronics->id,
            'name' => 'Phones & Tablets',
            'slug' => 'phones-tablets-legacy-store',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'store_id' => $store->id,
            'category_id' => $electronics->id,
            'subcategory_id' => $storeSection->id,
            'name' => 'Legacy Unclassified Phone',
            'slug' => 'legacy-unclassified-phone',
            'product_status' => 'draft',
            'compliance_status' => 'clear',
        ]);

        $legacyMappings = DB::table('marketplace_category_business_categories')
            ->orderBy('category_id')
            ->orderBy('marketplace_category_id')
            ->get(['category_id', 'marketplace_category_id'])
            ->map(fn ($mapping): array => [(int) $mapping->category_id, (int) $mapping->marketplace_category_id])
            ->all();

        $migration = require database_path('migrations/2026_10_23_000005_reconcile_seller_registration_categories.php');
        $migration->up();

        $this->assertDatabaseHas('seller_profiles', [
            'id' => $profile->id,
            'approved_category_id' => $electronics->id,
            'application_id' => $application->id,
        ]);
        $this->assertDatabaseHas('account_applications', [
            'id' => $application->id,
            'business_category_id' => $electronics->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $legacyUnlinkedSeller->id,
            'business_category' => 'Toys and Hobbies',
        ]);
        $this->assertDatabaseHas('account_applications', [
            'id' => $legacyUnlinkedApplication->id,
            'business_category_id' => null,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'category_id' => $electronics->id,
            'subcategory_id' => $storeSection->id,
            'marketplace_subcategory_id' => null,
        ]);
        $this->assertDatabaseHas('store_subcategories', [
            'id' => $storeSection->id,
            'store_id' => $store->id,
            'name' => 'Phones & Tablets',
        ]);

        $this->assertSame(
            $legacyMappings,
            DB::table('marketplace_category_business_categories')
                ->orderBy('category_id')
                ->orderBy('marketplace_category_id')
                ->get(['category_id', 'marketplace_category_id'])
                ->map(fn ($mapping): array => [(int) $mapping->category_id, (int) $mapping->marketplace_category_id])
                ->all(),
        );

        try {
            $migration->down();
            $this->fail('The additive Seller-registration reconciliation migration must not be rolled back.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('forward-only', $exception->getMessage());
        }

        $this->assertSame(72, DB::table('marketplace_subcategories')->count());
        $this->assertDatabaseHas('categories', [
            'id' => $electronics->id,
            'is_registration_option' => true,
        ]);
    }
}
