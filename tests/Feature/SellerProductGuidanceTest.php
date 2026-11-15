<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceSubcategory;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SellerProductGuidanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_form_uses_electronics_specific_name_and_description_guidance(): void
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'electronics-and-gadgets'],
            ['name' => 'Electronics and Gadgets', 'is_active' => true],
        );
        [$seller] = $this->sellerAndStore($category, 'electronics-guidance');

        $this->actingAs($seller)
            ->get(route('seller.products.create'))
            ->assertOk()
            ->assertSee('placeholder="e.g. Android Smartphone 128GB"', false)
            ->assertSee('Include the device type/model and a key specification such as storage or screen size.')
            ->assertSee('placeholder="Describe the model, storage, compatibility, included accessories, and warranty details if applicable."', false)
            ->assertDontSee('Classic Linen Shirt');
    }

    public function test_selected_marketplace_category_refines_business_category_guidance(): void
    {
        $businessCategory = Category::query()->firstOrCreate(
            ['slug' => 'fashion-and-apparel'],
            ['name' => 'Fashion and Apparel', 'is_active' => true],
        );
        [$seller, $store] = $this->sellerAndStore($businessCategory, 'apparel-guidance');
        $womenCategory = MarketplaceCategory::query()->firstOrCreate(
            ['slug' => 'women-s-apparel'],
            ['name' => "Women's Apparel", 'is_active' => true, 'position' => 1],
        );
        DB::table('marketplace_category_business_categories')->updateOrInsert(
            [
                'marketplace_category_id' => $womenCategory->id,
                'category_id' => $businessCategory->id,
            ],
            ['created_at' => now(), 'updated_at' => now()],
        );
        $dresses = MarketplaceSubcategory::query()->firstOrCreate(
            [
                'marketplace_category_id' => $womenCategory->id,
                'slug' => 'dresses-skirts',
            ],
            [
                'name' => 'Dresses & Skirts',
                'name_key' => Str::lower('Dresses & Skirts'),
                'is_active' => true,
                'position' => 1,
            ],
        );

        $this->actingAs($seller)
            ->get(route('seller.products.create'))
            ->assertOk()
            ->assertSee('placeholder="e.g. Cotton Apparel Item with Size/Style"', false);

        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $businessCategory->id,
            'marketplace_subcategory_id' => $dresses->id,
            'name' => 'Summer Dress',
            'slug' => 'summer-dress-guidance',
            'product_status' => 'draft',
            'compliance_status' => 'clear',
        ]);

        $this->actingAs($seller)
            ->get(route('seller.products.edit', $product))
            ->assertOk()
            ->assertSee('placeholder="e.g. Women’s Linen Midi Dress"', false)
            ->assertSee('data-name-placeholder="e.g. Women’s Linen Midi Dress"', false)
            ->assertSee('placeholder="Describe the fabric, fit, available sizing or measurements, and care instructions."', false)
            ->assertSee('Add a size guide or garment measurements when available.');
    }

    /** @return array{0: User, 1: Store} */
    private function sellerAndStore(Category $category, string $slug): array
    {
        $seller = User::create([
            'name' => Str::headline($slug),
            'first_name' => 'Guidance',
            'last_name' => Str::headline($slug),
            'email' => $slug.'@example.test',
            'role' => 'seller',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
        $profile = SellerProfile::create([
            'user_id' => $seller->id,
            'legal_business_name' => Str::headline($slug),
            'approved_category_id' => $category->id,
        ]);
        $store = Store::create([
            'seller_profile_id' => $profile->id,
            'name' => Str::headline($slug).' Store',
            'slug' => $slug.'-store',
            'publication_status' => 'draft',
        ]);

        return [$seller->fresh('sellerProfile'), $store];
    }
}
