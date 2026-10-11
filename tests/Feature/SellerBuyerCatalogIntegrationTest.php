<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ComplianceRule;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\StoreSubcategory;
use App\Models\User;
use App\Services\BuyerCatalogService;
use App\Services\ComplianceScanner;
use App\Services\SellerProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class SellerBuyerCatalogIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_publish_persists_a_compliant_product_for_buyer_catalog(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Integration Store',
            'slug' => 'integration-store',
            'contact_email' => $seller->email,
            'contact_phone' => '+639171234567',
            'publication_status' => 'published',
        ]);

        $this->actingAs($seller)
            ->post('/seller/products', [
                'name' => 'Live Integration Shirt',
                'category' => $category->name,
                'description' => 'A persisted seller product.',
                'new_subcategory_name' => 'Summer Shorts',
                'sku' => 'LIVE-SHIRT-001',
                'price' => '1299.00',
                'discount_percent' => 10,
                'voucher_eligible' => 1,
                'stock' => 4,
                'package_weight_kg' => '0.250',
                'package_length_cm' => '24',
                'package_width_cm' => '18',
                'package_height_cm' => '8',
                'low_stock_threshold' => 2,
                'option_one_name' => 'Size',
                'option_one_values' => 'Medium',
                'option_two_name' => 'Color',
                'option_two_values' => 'Olive',
                'variants' => [[
                    'label' => 'Medium / Olive',
                    'sku' => 'LIVE-SHIRT-001',
                    'price' => '1299.00',
                    'stock' => 4,
                    'package_weight_kg' => '0.250',
                    'package_length_cm' => '24',
                    'package_width_cm' => '18',
                    'package_height_cm' => '8',
                ]],
                'intent' => 'publish',
            ])
            ->assertRedirect('/seller/products');

        $product = Product::query()->where('store_id', $store->id)->firstOrFail();
        $subcategory = StoreSubcategory::query()
            ->where('store_id', $store->id)
            ->where('name', 'Summer Shorts')
            ->firstOrFail();
        $variant = $product->variants()->firstOrFail();

        $this->assertSame($category->id, $product->category_id);
        $this->assertSame($subcategory->id, $product->subcategory_id);
        $this->assertSame('Summer Shorts', $subcategory->name);
        $this->assertSame('Summer Shorts', $product->subcategory->name);
        $this->assertDatabaseMissing('categories', ['name' => 'Summer Shorts']);
        $this->assertSame('active', $product->product_status);
        $this->assertSame('clear', $product->compliance_status);
        $this->assertSame(129900, $variant->price_minor);
        $this->assertNull($variant->compare_at_price_minor);
        $this->assertDatabaseHas('inventory_movements', [
            'variant_id' => $variant->id,
            'quantity_delta' => 4,
            'balance_after' => 4,
        ]);

        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->get('/home')
            ->assertOk()
            ->assertSee('Flash Deals')
            ->assertSee('Live Integration Shirt')
            ->assertDontSee('Summer Shorts');

        $this->actingAs($buyer)
            ->get('/products?category=men-s-apparel')
            ->assertOk()
            ->assertSee('Live Integration Shirt')
            ->assertDontSee('Summer Shorts');

        $this->actingAs($buyer)
            ->get('/products/'.$product->id)
            ->assertOk()
            ->assertSee('Live Integration Shirt')
            ->assertSee('Integration Store');

        $this->actingAs($buyer)
            ->get('/stores/'.$store->slug)
            ->assertOk()
            ->assertSee('Live Integration Shirt')
            ->assertSee('Summer Shorts')
            ->assertSee(route('stores.show', ['store' => $store->slug, 'subcategory' => $subcategory->id]), false);

        $this->actingAs($buyer)
            ->get(route('stores.show', ['store' => $store->slug, 'subcategory' => $subcategory->id]))
            ->assertOk()
            ->assertSee('Live Integration Shirt')
            ->assertSee('Summer Shorts');

        $this->actingAs($buyer)
            ->postJson('/cart/add', [
                'product_variant_id' => $variant->id,
                'quantity' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.cart_count', 1);
    }

    public function test_seller_can_select_or_create_a_subcategory_only_for_their_own_store(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $otherSeller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Seller Subcategory Store',
            'slug' => 'seller-subcategory-store',
            'publication_status' => 'draft',
        ]);
        $otherStore = Store::create([
            'seller_profile_id' => $otherSeller->sellerProfile->id,
            'name' => 'Other Subcategory Store',
            'slug' => 'other-subcategory-store',
            'publication_status' => 'draft',
        ]);

        $this->actingAs($seller)
            ->get('/seller/products/create')
            ->assertOk()
            ->assertSee('Store subcategory')
            ->assertSee('Add a new subcategory')
            ->assertSee('Dresses &amp; Skirts', false)
            ->assertSee('Suits &amp; Blazers', false)
            ->assertSee('No subcategory');

        $this->assertSame(11, StoreSubcategory::query()->where('store_id', $store->id)->count());
        $this->assertDatabaseHas('store_subcategories', [
            'store_id' => $store->id,
            'parent_category_id' => $category->id,
            'name' => 'Dresses & Skirts',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('store_subcategories', [
            'store_id' => $store->id,
            'parent_category_id' => $category->id,
            'name' => 'Suits & Blazers',
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('categories', ['name' => 'Dresses & Skirts']);

        $basePayload = [
            'product_mode' => 'single',
            'category' => $category->name,
            'price' => '100.00',
            'stock' => 5,
            'package_weight_kg' => '0.250',
            'package_length_cm' => '24',
            'package_width_cm' => '18',
            'package_height_cm' => '8',
            'low_stock_threshold' => 2,
            'intent' => 'draft',
        ];

        $this->actingAs($seller)
            ->post('/seller/products', $basePayload + [
                'name' => 'First Summer Item',
                'new_subcategory_name' => 'Summer Shorts',
            ])
            ->assertRedirect('/seller/products');

        $subcategory = StoreSubcategory::query()
            ->where('store_id', $store->id)
            ->where('name', 'Summer Shorts')
            ->firstOrFail();
        $firstProduct = Product::query()->where('name', 'First Summer Item')->firstOrFail();
        $this->assertSame($category->id, $firstProduct->category_id);
        $this->assertSame($subcategory->id, $firstProduct->subcategory_id);
        $this->assertSame('summer-shorts', $subcategory->slug);

        $this->actingAs($seller)
            ->get(route('seller.products.edit', $firstProduct))
            ->assertOk()
            ->assertSee('value="'.$subcategory->id.'" selected', false)
            ->assertSee('Summer Shorts');

        $this->actingAs($seller)
            ->post('/seller/products', $basePayload + [
                'name' => 'Second Summer Item',
                'new_subcategory_name' => ' summer   shorts ',
            ])
            ->assertRedirect('/seller/products');

        $secondProduct = Product::query()->where('name', 'Second Summer Item')->firstOrFail();
        $this->assertSame($subcategory->id, $secondProduct->subcategory_id);
        $this->assertSame(12, StoreSubcategory::query()->where('store_id', $store->id)->count());
        $this->assertDatabaseMissing('categories', ['name' => 'Summer Shorts']);

        $this->actingAs($otherSeller)
            ->post('/seller/products', $basePayload + [
                'name' => 'Other Seller Summer Item',
                'new_subcategory_name' => 'Summer Shorts',
            ])
            ->assertRedirect('/seller/products');

        $otherSubcategory = StoreSubcategory::query()
            ->where('store_id', $otherStore->id)
            ->where('name', 'Summer Shorts')
            ->firstOrFail();
        $this->assertNotSame($subcategory->id, $otherSubcategory->id);
        $this->assertSame('Summer Shorts', $otherSubcategory->name);
    }

    public function test_it3a_defaults_follow_the_approved_category_and_remain_store_scoped(): void
    {
        foreach ([
            [
                'name' => 'Home and Furniture',
                'slug' => 'home-and-furniture',
                'expected_count' => 12,
                'sample_name' => 'Office Desks & Chairs',
            ],
            [
                'name' => 'Pet Supplies',
                'slug' => 'pet-supplies',
                'expected_count' => 6,
                'sample_name' => 'Dog Food & Treats',
            ],
        ] as $index => $categoryData) {
            $category = Category::create([
                'name' => $categoryData['name'],
                'slug' => $categoryData['slug'],
            ]);
            $seller = $this->seller($category);
            $store = Store::create([
                'seller_profile_id' => $seller->sellerProfile->id,
                'name' => 'Mapped Category Store '.$index,
                'slug' => 'mapped-category-store-'.$index,
                'publication_status' => 'draft',
            ]);

            $subcategories = app(SellerProductService::class)->subcategoriesFor($seller);

            $this->assertCount($categoryData['expected_count'], $subcategories);
            $this->assertTrue($subcategories->contains('name', $categoryData['sample_name']));
            $this->assertTrue($subcategories->every(
                fn (StoreSubcategory $subcategory): bool => (int) $subcategory->store_id === (int) $store->id
                    && (int) $subcategory->parent_category_id === (int) $category->id,
            ));
        }

        $this->assertDatabaseMissing('categories', ['name' => 'Office Desks & Chairs']);
        $this->assertDatabaseMissing('categories', ['name' => 'Dog Food & Treats']);
    }

    public function test_seller_cannot_assign_another_stores_subcategory_or_submit_both_subcategory_inputs(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $otherSeller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Owned Subcategory Store',
            'slug' => 'owned-subcategory-store',
            'publication_status' => 'draft',
        ]);
        $otherStore = Store::create([
            'seller_profile_id' => $otherSeller->sellerProfile->id,
            'name' => 'Foreign Subcategory Store',
            'slug' => 'foreign-subcategory-store',
            'publication_status' => 'draft',
        ]);
        $foreignSubcategory = StoreSubcategory::create([
            'store_id' => $otherStore->id,
            'parent_category_id' => $category->id,
            'name' => 'Foreign Category',
            'slug' => 'foreign-category',
            'is_active' => true,
        ]);
        $ownedSubcategory = StoreSubcategory::create([
            'store_id' => $store->id,
            'parent_category_id' => $category->id,
            'name' => 'Owned Category',
            'slug' => 'owned-category',
            'is_active' => true,
        ]);
        $payload = [
            'product_mode' => 'single',
            'name' => 'Tampered Subcategory Product',
            'category' => $category->name,
            'subcategory_id' => $foreignSubcategory->id,
            'price' => '100.00',
            'stock' => 1,
            'package_weight_kg' => '0.250',
            'package_length_cm' => '24',
            'package_width_cm' => '18',
            'package_height_cm' => '8',
            'low_stock_threshold' => 1,
            'intent' => 'draft',
        ];

        $this->actingAs($seller)
            ->from('/seller/products/create')
            ->post('/seller/products', $payload)
            ->assertRedirect('/seller/products/create')
            ->assertSessionHasErrors('subcategory_id');

        $this->actingAs($seller)
            ->from('/seller/products/create')
            ->post('/seller/products', array_replace($payload, [
                'subcategory_id' => $ownedSubcategory->id,
                'new_subcategory_name' => 'Another Name',
            ]))
            ->assertRedirect('/seller/products/create')
            ->assertSessionHasErrors('new_subcategory_name');

        $this->assertDatabaseMissing('products', ['name' => 'Tampered Subcategory Product']);
        $this->assertSame(0, $store->products()->count());
    }

    public function test_updating_a_product_adds_a_variant_without_replacing_existing_variants(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Variant Matrix Store',
            'slug' => 'variant-matrix-store',
            'contact_email' => $seller->email,
            'contact_phone' => '+639171234567',
            'publication_status' => 'published',
        ]);

        $this->actingAs($seller)
            ->post('/seller/products', [
                'product_mode' => 'with_variants',
                'name' => 'Variant Matrix Shirt',
                'category' => $category->name,
                'description' => 'A product with additive size and color variants.',
                'price' => '300.00',
                'stock' => 13,
                'low_stock_threshold' => 5,
                'option_one_name' => 'Size',
                'option_one_values' => 'Large',
                'option_two_name' => 'Color',
                'option_two_values' => 'Black',
                'variants' => [[
                    'label' => 'Large / Black',
                    'sku' => 'VAR-LARGE-BLACK',
                    'price' => '300.00',
                    'stock' => 13,
                    'package_weight_kg' => '0.250',
                    'package_length_cm' => '24',
                    'package_width_cm' => '18',
                    'package_height_cm' => '8',
                ]],
                'intent' => 'publish',
            ])
            ->assertRedirect('/seller/products');

        $product = Product::query()->where('store_id', $store->id)->firstOrFail();

        $this->actingAs($seller)
            ->put('/seller/products/'.$product->id, [
                // Product type is inferred from persisted variants; edits cannot convert it.
                'product_mode' => 'single',
                'name' => 'Variant Matrix Shirt',
                'category' => $category->name,
                'description' => 'A product with additive size and color variants.',
                'sku' => 'VAR-SMALL-RED',
                'price' => '300.00',
                'stock' => 7,
                'low_stock_threshold' => 5,
                'option_one_name' => 'Size',
                'option_one_values' => 'Small',
                'option_two_name' => 'Color',
                'option_two_values' => 'Red',
                'variants' => [[
                    'label' => 'Small / Red',
                    'sku' => 'VAR-SMALL-RED',
                    'price' => '300.00',
                    'stock' => 7,
                    'package_weight_kg' => '0.250',
                    'package_length_cm' => '24',
                    'package_width_cm' => '18',
                    'package_height_cm' => '8',
                ]],
                'intent' => 'publish',
            ])
            ->assertRedirect('/seller/products');

        $variants = $product->fresh()->variants()
            ->where('is_active', true)
            ->orderBy('position')
            ->get();

        $this->assertCount(2, $variants);
        $this->assertSame(['Large / Black', 'Small / Red'], $variants->pluck('name')->all());
        $this->assertSame([13, 7], $variants->pluck('stock_on_hand')->all());

        $this->actingAs($seller)
            ->withSession(['_old_input' => ['product_mode' => 'single']])
            ->get(route('seller.products.edit', $product))
            ->assertOk()
            ->assertSee('Managed per variant')
            ->assertSee('With Variants')
            ->assertSee('Small \/ Red', false)
            ->assertSee('Large \/ Black', false)
            ->assertSee('value="with_variants" data-product-mode-value', false)
            ->assertSee('<div data-variant-configuration >', false)
            ->assertDontSee('name="discount_percent"', false);

        $this->actingAs($seller)
            ->get('/seller/products')
            ->assertOk()
            ->assertSee('Variant SKUs managed per combination')
            ->assertSee('2 variants')
            ->assertSee('Small / Red')
            ->assertSee('Large / Black');

        $this->actingAs($this->buyer())
            ->get('/products/'.$product->id)
            ->assertOk()
            ->assertSee('Small / Red')
            ->assertSee('Large / Black');
    }

    public function test_repeated_variant_labels_get_unique_skus_and_legacy_generated_skus_are_replaced(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Generated SKU Store',
            'slug' => 'generated-sku-store',
            'publication_status' => 'draft',
        ]);

        $basePayload = [
            'product_mode' => 'with_variants',
            'category' => $category->name,
            'price' => '300.00',
            'stock' => 0,
            'low_stock_threshold' => 5,
            'option_one_name' => 'Size',
            'option_one_values' => 'Small',
            'option_two_name' => 'Color',
            'option_two_values' => 'Black',
            'variants' => [[
                'label' => 'Small / Black',
                'price' => '300.00',
                'stock' => 0,
                'package_weight_kg' => '0.250',
                'package_length_cm' => '24',
                'package_width_cm' => '18',
                'package_height_cm' => '8',
            ]],
            'payment_methods' => ['cod'],
            'intent' => 'draft',
        ];

        $this->actingAs($seller)
            ->post('/seller/products', array_replace($basePayload, [
                'name' => 'First Black Shirt',
                'variants' => [[
                    'label' => 'Small / Black',
                    'sku' => 'VAR-SMALL-BLACK',
                    'price' => '300.00',
                    'stock' => 0,
                    'package_weight_kg' => '0.250',
                    'package_length_cm' => '24',
                    'package_width_cm' => '18',
                    'package_height_cm' => '8',
                ]],
            ]))
            ->assertRedirect('/seller/products');

        $firstProduct = Product::query()->where('store_id', $store->id)->where('name', 'First Black Shirt')->firstOrFail();

        $this->actingAs($seller)
            ->post('/seller/products', array_replace($basePayload, [
                'name' => 'Second Black Shirt',
                'variants' => [[
                    'label' => 'Small / Black',
                    'sku' => 'VAR-SMALL-BLACK',
                    'price' => '300.00',
                    'stock' => 0,
                    'package_weight_kg' => '0.250',
                    'package_length_cm' => '24',
                    'package_width_cm' => '18',
                    'package_height_cm' => '8',
                ]],
            ]))
            ->assertRedirect('/seller/products');

        $secondProduct = Product::query()->where('store_id', $store->id)->where('name', 'Second Black Shirt')->firstOrFail();
        $this->actingAs($seller)
            ->post('/seller/products', $basePayload + ['name' => 'Third Black Shirt'])
            ->assertRedirect('/seller/products');

        $thirdProduct = Product::query()->where('store_id', $store->id)->where('name', 'Third Black Shirt')->firstOrFail();
        $firstSku = $firstProduct->variants()->where('is_active', true)->value('sku');
        $secondSku = $secondProduct->variants()->where('is_active', true)->value('sku');
        $thirdSku = $thirdProduct->variants()->where('is_active', true)->value('sku');

        $this->assertSame('VAR-SMALL-BLACK', $firstSku);
        $this->assertNotSame($firstSku, $secondSku);
        $this->assertNotSame($firstSku, $thirdSku);
        $this->assertNotSame($secondSku, $thirdSku);
        $this->assertStringStartsWith('BRLY-'.$secondProduct->id.'-01-', $secondSku);
        $this->assertStringStartsWith('BRLY-'.$thirdProduct->id.'-01-', $thirdSku);
        $this->assertSame(0, (int) $secondProduct->variants()->where('is_active', true)->value('stock_on_hand'));
        $this->assertSame(0, (int) $thirdProduct->variants()->where('is_active', true)->value('stock_on_hand'));
    }

    public function test_variant_labels_are_rejected_when_they_duplicate_case_insensitively(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Duplicate Variant Label Store',
            'slug' => 'duplicate-variant-label-store',
            'publication_status' => 'draft',
        ]);

        $this->actingAs($seller)
            ->from('/seller/products/create')
            ->post('/seller/products', [
                'product_mode' => 'with_variants',
                'name' => 'Duplicate Label Product',
                'category' => $category->name,
                'price' => '100.00',
                'stock' => 2,
                'package_weight_kg' => '0.250',
                'package_length_cm' => '24',
                'package_width_cm' => '18',
                'package_height_cm' => '8',
                'low_stock_threshold' => 1,
                'option_one_name' => 'Size',
                'option_one_values' => 'Small',
                'variants' => [
                    ['label' => 'Small / Black', 'sku' => 'DUPLICATE-LABEL-1', 'price' => '100.00', 'stock' => 1],
                    ['label' => 'small / black', 'sku' => 'DUPLICATE-LABEL-2', 'price' => '100.00', 'stock' => 1],
                ],
                'payment_methods' => ['cod'],
                'intent' => 'draft',
            ])
            ->assertRedirect('/seller/products/create')
            ->assertSessionHasErrors('variants.1.label');

        $this->assertDatabaseMissing('products', [
            'store_id' => $store->id,
            'name' => 'Duplicate Label Product',
        ]);
    }

    public function test_seller_cannot_reduce_stock_below_reserved_units(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Reserved Stock Store',
            'slug' => 'reserved-stock-store',
            'publication_status' => 'draft',
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Reserved Stock Product',
            'slug' => 'reserved-stock-product',
            'product_status' => 'draft',
            'compliance_status' => 'clear',
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'RESERVED-STOCK-001',
            'name' => 'Default',
            'price_minor' => 10000,
            'stock_on_hand' => 5,
            'stock_reserved' => 3,
            'is_active' => true,
        ]);

        $this->actingAs($seller)
            ->put(route('seller.products.update', $product), [
                'product_mode' => 'single',
                'name' => $product->name,
                'category' => $category->name,
                'price' => '100.00',
                'sku' => $variant->sku,
                'stock' => 2,
                'package_weight_kg' => '0.250',
                'package_length_cm' => '24',
                'package_width_cm' => '18',
                'package_height_cm' => '8',
                'low_stock_threshold' => 1,
                'payment_methods' => ['cod'],
                'intent' => 'draft',
            ])
            ->assertSessionHasErrors('stock');

        $this->assertSame(5, (int) $variant->fresh()->stock_on_hand);
        $this->assertSame(3, (int) $variant->fresh()->stock_reserved);
    }

    public function test_archiving_and_restoring_preserves_the_previous_product_status(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Archive Restore Store',
            'slug' => 'archive-restore-store',
            'publication_status' => 'published',
        ]);
        $publishedAt = now()->subDays(4)->startOfSecond();
        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Archive Restore Product',
            'slug' => 'archive-restore-product',
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'published_at' => $publishedAt,
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'ARCHIVE-RESTORE-001',
            'name' => 'Default',
            'price_minor' => 10000,
            'stock_on_hand' => 3,
            'is_active' => true,
        ]);

        $this->actingAs($seller)
            ->patch(route('seller.products.archive', $product))
            ->assertRedirect('/seller/products');

        $archived = $product->fresh();
        $this->assertSame('archived', $archived->product_status);
        $this->assertSame('active', $archived->archived_previous_status);
        $this->assertSame($publishedAt->timestamp, $archived->archived_previous_published_at->timestamp);
        $this->assertNull($archived->published_at);

        $this->actingAs($seller)
            ->patch(route('seller.products.archive', $product))
            ->assertRedirect('/seller/products');

        $restored = $product->fresh();
        $this->assertSame('active', $restored->product_status);
        $this->assertSame($publishedAt->timestamp, $restored->published_at->timestamp);
        $this->assertNull($restored->archived_previous_status);
        $this->assertNull($restored->archived_previous_published_at);
    }

    public function test_product_management_filters_by_status_and_subcategory_and_keeps_filter_after_restore(): void
    {
        $parentCategory = $this->category();
        $seller = $this->seller($parentCategory);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Product Filter Store',
            'slug' => 'product-filter-store',
            'publication_status' => 'draft',
        ]);
        $tops = StoreSubcategory::create([
            'store_id' => $store->id,
            'parent_category_id' => $parentCategory->id,
            'name' => 'Tops',
            'slug' => 'tops',
        ]);
        $home = StoreSubcategory::create([
            'store_id' => $store->id,
            'parent_category_id' => $parentCategory->id,
            'name' => 'Home Goods',
            'slug' => 'home-goods',
        ]);
        $publishedAt = now()->subDays(2)->startOfSecond();

        $createProduct = function (string $name, string $slug, StoreSubcategory $subcategory, string $status) use ($store, $parentCategory, $publishedAt): Product {
            $product = Product::create([
                'store_id' => $store->id,
                'category_id' => $parentCategory->id,
                'subcategory_id' => $subcategory->id,
                'name' => $name,
                'slug' => $slug,
                'product_status' => $status,
                'compliance_status' => 'clear',
                'published_at' => $status === 'active' ? $publishedAt : null,
                'archived_at' => $status === 'archived' ? now() : null,
                'archived_previous_status' => $status === 'archived' ? 'active' : null,
                'archived_previous_published_at' => $status === 'archived' ? $publishedAt : null,
            ]);

            ProductVariant::create([
                'product_id' => $product->id,
                'sku' => strtoupper(str_replace('-', '', $slug)).'-001',
                'name' => 'Default',
                'price_minor' => 10000,
                'stock_on_hand' => 3,
                'is_active' => true,
            ]);

            return $product;
        };

        $archived = $createProduct('Archived Tops Product', 'archived-tops-product', $tops, 'archived');
        $createProduct('Active Tops Product', 'active-tops-product', $tops, 'active');
        $createProduct('Draft Home Product', 'draft-home-product', $home, 'draft');

        $filteredProducts = $this->actingAs($seller)
            ->get('/seller/products?subcategory='.$tops->id.'&status=Archived')
            ->assertOk();

        $filteredProducts
            ->assertSee('Archived Tops Product')
            ->assertDontSee('Active Tops Product')
            ->assertDontSee('Draft Home Product')
            ->assertSee('<span>Subcategory</span>', false)
            ->assertSee('All Subcategories')
            ->assertSee('action="'.e(route('seller.products.archive', [
                'product' => $archived->id,
                'subcategory' => $tops->id,
                'status' => 'Archived',
            ])).'"', false);

        $this->actingAs($seller)
            ->patch(route('seller.products.archive', [
                'product' => $archived->id,
                'subcategory' => $tops->id,
                'status' => 'Archived',
            ]))
            ->assertRedirect(route('seller.products', ['subcategory' => $tops->id, 'status' => 'Archived']))
            ->assertSessionHas('success', 'Product restored to its previous status.');

        $this->assertSame('active', $archived->fresh()->product_status);

        $this->actingAs($seller)
            ->get('/seller/products?subcategory='.$tops->id.'&status=Archived')
            ->assertOk()
            ->assertSee('Product restored to its previous status.')
            ->assertSee('No matching products')
            ->assertDontSee('Archived Tops Product');
    }

    public function test_restore_validation_reason_is_shown_when_previous_active_state_is_no_longer_compliant(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Blocked Restore Store',
            'slug' => 'blocked-restore-store',
            'publication_status' => 'draft',
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Needs Compliance Review',
            'slug' => 'needs-compliance-review',
            'product_status' => 'archived',
            'compliance_status' => 'flagged',
            'archived_at' => now(),
            'archived_previous_status' => 'active',
            'archived_previous_published_at' => now()->subDay(),
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'NEEDS-COMPLIANCE-001',
            'name' => 'Default',
            'price_minor' => 10000,
            'stock_on_hand' => 3,
            'is_active' => true,
        ]);

        $this->actingAs($seller)
            ->from('/seller/products?status=Archived')
            ->patch(route('seller.products.archive', [
                'product' => $product->id,
                'status' => 'Archived',
            ]))
            ->assertSessionHasErrors('product');

        $this->actingAs($seller)
            ->get('/seller/products?status=Archived')
            ->assertOk()
            ->assertSee('This product cannot be restored as active because its compliance status is no longer clear.');

        $this->assertSame('archived', $product->fresh()->product_status);
    }

    public function test_product_management_is_paginated_searches_all_active_variant_skus_and_uses_disk_urls(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Paginated Product Store',
            'slug' => 'paginated-product-store',
            'publication_status' => 'draft',
        ]);

        config(['filesystems.disks.product-cdn' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/product-cdn'),
            'url' => 'https://cdn.example.test/catalog',
            'visibility' => 'public',
            'throw' => false,
        ]]);

        for ($index = 1; $index <= 26; $index++) {
            $number = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $product = Product::create([
                'store_id' => $store->id,
                'category_id' => $category->id,
                'name' => 'Listing Product '.$number,
                'slug' => 'listing-product-'.$number,
                'product_status' => 'active',
                'compliance_status' => 'clear',
            ]);

            ProductVariant::create([
                'product_id' => $product->id,
                'sku' => 'LISTING-'.$number.'-A',
                'name' => 'Small / Black',
                'options' => ['Size' => 'Small', 'Color' => 'Black'],
                'price_minor' => 10000,
                'stock_on_hand' => $index === 1 ? 2 : 10,
                'stock_reserved' => $index === 1 ? 1 : 0,
                'low_stock_threshold' => 5,
                'is_active' => true,
                'position' => 1,
            ]);

            if ($index === 1) {
                ProductVariant::create([
                    'product_id' => $product->id,
                    'sku' => 'LISTING-01-SECOND-SKU',
                    'name' => 'Large / Black',
                    'options' => ['Size' => 'Large', 'Color' => 'Black'],
                    'price_minor' => 10000,
                    'stock_on_hand' => 2,
                    'stock_reserved' => 1,
                    'low_stock_threshold' => 5,
                    'is_active' => true,
                    'position' => 2,
                ]);
            }

            if ($index === 26) {
                ProductImage::create([
                    'product_id' => $product->id,
                    'path' => 'products/listing-product-26.jpg',
                    'disk' => 'product-cdn',
                    'mime_type' => 'image/jpeg',
                    'size_bytes' => 1024,
                    'purpose' => 'primary',
                    'alt_text' => $product->name,
                    'position' => 0,
                    'is_primary' => true,
                ]);
            }
        }

        $this->actingAs($seller)
            ->get('/seller/products')
            ->assertOk()
            ->assertSee('Listing Product 26')
            ->assertDontSee('Listing Product 01')
            ->assertSee('Showing 1–25 of 26 products')
            ->assertSee('src="https://cdn.example.test/catalog/products/listing-product-26.jpg"', false);

        $this->actingAs($seller)
            ->get('/seller/products?page=2')
            ->assertOk()
            ->assertSee('Listing Product 01')
            ->assertDontSee('Listing Product 26');

        $this->actingAs($seller)
            ->get('/seller/products?search=LISTING-01-SECOND-SKU')
            ->assertOk()
            ->assertSee('Listing Product 01')
            ->assertDontSee('Listing Product 02');

        $this->assertSame(
            'https://cdn.example.test/catalog/products/listing-product-26.jpg',
            app(BuyerCatalogService::class)->card($product)['image'],
        );

        $listing = app(SellerProductService::class)->productManagementListing($seller);
        $this->assertSame(26, $listing['counts']['all']);
        $this->assertSame(26, $listing['counts']['active']);
        $this->assertSame(1, $listing['counts']['low']);
        $allProductsListing = app(SellerProductService::class)->productManagementListing($seller, perPage: 30);
        $this->assertSame(2, $allProductsListing['products']->getCollection()
            ->firstWhere('name', 'Listing Product 01')['stock']);
        $this->assertSame(4, app(SellerProductService::class)->formPayload(
            Product::query()->where('name', 'Listing Product 01')->firstOrFail(),
        )['stock']);
    }

    public function test_uploaded_product_images_are_removed_if_the_product_transaction_rolls_back(): void
    {
        Storage::fake('public');

        $category = $this->category();
        $seller = $this->seller($category);
        Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Image Rollback Store',
            'slug' => 'image-rollback-store',
            'publication_status' => 'draft',
        ]);

        $scanner = \Mockery::mock(ComplianceScanner::class);
        $scanner->shouldReceive('scan')
            ->once()
            ->andThrow(new RuntimeException('Simulated compliance scanner failure.'));
        $this->app->instance(ComplianceScanner::class, $scanner);

        try {
            app(SellerProductService::class)->save($seller, [
                'product_mode' => 'single',
                'name' => 'Image Rollback Product',
                'price' => '100.00',
                'stock' => 2,
                'low_stock_threshold' => 1,
                'package_weight_kg' => '0.250',
                'package_length_cm' => '24',
                'package_width_cm' => '18',
                'package_height_cm' => '8',
                'discount_percent' => 0,
                'payment_methods' => ['cod'],
                'intent' => 'draft',
            ], UploadedFile::fake()->create('rollback-product.jpg', 100, 'image/jpeg'), []);

            $this->fail('The simulated compliance failure should abort the product transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated compliance scanner failure.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('products', ['name' => 'Image Rollback Product']);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_single_product_mode_persists_one_default_variant_and_keeps_type_on_edit(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Single Product Store',
            'slug' => 'single-product-store',
            'publication_status' => 'published',
        ]);

        $this->actingAs($seller)
            ->post('/seller/products', [
                'product_mode' => 'single',
                'name' => 'Single Product Mug',
                'category' => $category->name,
                'description' => 'One offer with one stock balance.',
                'sku' => 'SINGLE-MUG-001',
                'price' => '250.00',
                'stock' => 8,
                'low_stock_threshold' => 2,
                'package_weight_kg' => '0.250',
                'package_length_cm' => '24',
                'package_width_cm' => '18',
                'package_height_cm' => '8',
                'payment_methods' => ['cod'],
                'intent' => 'draft',
            ])
            ->assertRedirect('/seller/products');

        $product = Product::query()->where('store_id', $store->id)->firstOrFail();
        $variant = $product->variants()->where('is_active', true)->firstOrFail();

        $this->assertSame(['cod'], $product->payment_methods);
        $this->assertSame('Default', $variant->name);
        $this->assertSame('SINGLE-MUG-001', $variant->sku);
        $this->assertSame(25000, (int) $variant->compare_at_price_minor ?: (int) $variant->price_minor);
        $this->assertSame(8, (int) $variant->stock_on_hand);
        $this->assertSame(1, $product->variants()->where('is_active', true)->count());

        $this->actingAs($seller)
            ->get(route('seller.products.edit', $product))
            ->assertOk()
            ->assertSee('Single Product')
            ->assertSee('value="single" data-product-mode-value', false)
            ->assertSee('Cash on Delivery')
            ->assertDontSee('With Variants</strong><small>Separate SKU');

        $this->actingAs($seller)
            ->put('/seller/products/'.$product->id, [
                'product_mode' => 'with_variants',
                'name' => 'Single Product Mug',
                'category' => $category->name,
                'description' => 'One offer with one stock balance.',
                'sku' => 'SINGLE-MUG-002',
                'price' => '275.00',
                'stock' => 5,
                'low_stock_threshold' => 2,
                'package_weight_kg' => '0.300',
                'package_length_cm' => '25',
                'package_width_cm' => '18',
                'package_height_cm' => '9',
                'payment_methods' => ['online'],
                'intent' => 'draft',
            ])
            ->assertRedirect('/seller/products');

        $product->refresh();
        $variant->refresh();

        $this->assertSame(['online'], $product->payment_methods);
        $this->assertSame('SINGLE-MUG-002', $variant->sku);
        $this->assertSame(27500, (int) $variant->price_minor);
        $this->assertSame(5, (int) $variant->stock_on_hand);
        $this->assertSame(1, $product->variants()->where('is_active', true)->count());
        $this->assertSame(2, $variant->movements()->count());
    }

    public function test_product_payment_methods_accept_only_cod_and_online_and_require_one_choice(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);

        $this->actingAs($seller)
            ->get('/seller/products/create')
            ->assertOk()
            ->assertDontSee('name="payment_methods[]" value="cod" checked', false)
            ->assertDontSee('name="payment_methods[]" value="online" checked', false)
            ->assertDontSee('Choose which payment methods buyers may use for this product.')
            ->assertDontSee('Seller preferences only; online payment processing and checkout enforcement are not active yet.')
            ->assertDontSee('Preview only. Commission is finalized when a successful order becomes')
            ->assertSee('data-payment-method-client-error', false)
            ->assertSee('data-variant-client-error', false);

        foreach ([
            ['methods' => [], 'error' => 'payment_methods'],
            ['methods' => ['wallet'], 'error' => 'payment_methods.0'],
        ] as $case) {
            $this->actingAs($seller)
                ->from('/seller/products/create')
                ->post('/seller/products', [
                    'product_mode' => 'single',
                    'name' => 'Invalid Payment Product',
                    'category' => $category->name,
                    'price' => '100.00',
                    'stock' => 1,
                    'low_stock_threshold' => 1,
                    'payment_methods_present' => 1,
                    'payment_methods' => $case['methods'],
                    'intent' => 'draft',
                ])
                ->assertRedirect('/seller/products/create')
                ->assertSessionHasErrors($case['error']);
        }

        $this->actingAs($seller)
            ->from('/seller/products/create')
            ->post('/seller/products', [
                'product_mode' => 'single',
                'name' => 'No Payment Method Product',
                'category' => $category->name,
                'price' => '100.00',
                'stock' => 1,
                'low_stock_threshold' => 1,
                'payment_methods_present' => 1,
                'intent' => 'draft',
            ])
            ->assertRedirect('/seller/products/create')
            ->assertSessionHasErrors('payment_methods');

        $this->assertDatabaseMissing('products', ['name' => 'Invalid Payment Product']);
        $this->assertDatabaseMissing('products', ['name' => 'No Payment Method Product']);
    }

    public function test_variant_images_are_saved_per_sku_and_follow_buyer_variant_selection(): void
    {
        Storage::fake('public');

        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Variation Image Store',
            'slug' => 'variation-image-store',
            'contact_email' => $seller->email,
            'contact_phone' => '+639171234567',
            'publication_status' => 'published',
        ]);

        $this->actingAs($seller)
            ->get('/seller/products/create')
            ->assertOk()
            ->assertSee('Variant Images')
            ->assertSee('data-variant-color-images', false)
            ->assertSee('data-variant-color-image-input', false)
            ->assertSee('name="variant_color_images[]"', false)
            ->assertSee('data-variant-color-image-uploads', false);

        $this->actingAs($seller)
            ->post('/seller/products', [
                'product_mode' => 'with_variants',
                'name' => 'Variation Image Shirt',
                'category' => $category->name,
                'description' => 'A product with variation-specific images.',
                'price' => '300.00',
                'stock' => 12,
                'low_stock_threshold' => 2,
                'option_one_name' => 'Size',
                'option_one_values' => 'Small, Large',
                'option_two_name' => 'Color',
                'option_two_values' => 'Red, Blue',
                'variants' => [
                    ['label' => 'Small / Red', 'sku' => 'COLOR-SM-RED', 'price' => '300.00', 'stock' => 3, 'package_weight_kg' => '0.250', 'package_length_cm' => '24', 'package_width_cm' => '18', 'package_height_cm' => '8'],
                    ['label' => 'Large / Red', 'sku' => 'COLOR-LG-RED', 'price' => '300.00', 'stock' => 4, 'package_weight_kg' => '0.250', 'package_length_cm' => '24', 'package_width_cm' => '18', 'package_height_cm' => '8'],
                    ['label' => 'Small / Blue', 'sku' => 'COLOR-SM-BLUE', 'price' => '300.00', 'stock' => 5, 'package_weight_kg' => '0.250', 'package_length_cm' => '24', 'package_width_cm' => '18', 'package_height_cm' => '8'],
                ],
                'intent' => 'publish',
            ])
            ->assertRedirect('/seller/products');

        $product = Product::query()->where('store_id', $store->id)->firstOrFail();
        $smallRedVariant = $product->variants()->where('name', 'Small / Red')->firstOrFail();
        $legacyColorFile = UploadedFile::fake()->create('legacy-red-shirt.png', 10, 'image/png');
        $legacyColorImage = $product->images()->create([
            'variant_id' => $smallRedVariant->id,
            'path' => $legacyColorFile->store('seller-products/variants', 'public'),
            'disk' => 'public',
            'mime_type' => 'image/png',
            'size_bytes' => $legacyColorFile->getSize(),
            'purpose' => 'variant',
            'alt_text' => 'Variation Image Shirt - Red',
            'position' => $smallRedVariant->position,
            'is_primary' => false,
        ]);

        $this->actingAs($seller)
            ->put('/seller/products/'.$product->id, [
                'product_mode' => 'with_variants',
                'name' => 'Variation Image Shirt',
                'category' => $category->name,
                'description' => 'A product with variation-specific images.',
                'price' => '300.00',
                'stock' => 12,
                'low_stock_threshold' => 2,
                'option_one_name' => 'Size',
                'option_one_values' => 'Small, Large',
                'option_two_name' => 'Color',
                'option_two_values' => 'Red, Blue',
                'variants' => [
                    ['label' => 'Small / Red', 'sku' => 'COLOR-SM-RED', 'price' => '300.00', 'stock' => 3, 'package_weight_kg' => '0.250', 'package_length_cm' => '24', 'package_width_cm' => '18', 'package_height_cm' => '8'],
                    ['label' => 'Large / Red', 'sku' => 'COLOR-LG-RED', 'price' => '300.00', 'stock' => 4, 'package_weight_kg' => '0.250', 'package_length_cm' => '24', 'package_width_cm' => '18', 'package_height_cm' => '8'],
                    ['label' => 'Small / Blue', 'sku' => 'COLOR-SM-BLUE', 'price' => '300.00', 'stock' => 5, 'package_weight_kg' => '0.250', 'package_length_cm' => '24', 'package_width_cm' => '18', 'package_height_cm' => '8'],
                ],
                'image' => UploadedFile::fake()->create('shirt-primary.jpg', 100, 'image/jpeg'),
                'gallery_images' => [
                    UploadedFile::fake()->create('shirt-gallery.jpg', 100, 'image/jpeg'),
                ],
                'variant_images' => [
                    0 => UploadedFile::fake()->create('small-red-shirt.jpg', 100, 'image/jpeg'),
                    1 => UploadedFile::fake()->create('large-red-shirt.jpg', 100, 'image/jpeg'),
                    2 => UploadedFile::fake()->create('small-blue-shirt.jpg', 100, 'image/jpeg'),
                ],
                'payment_methods' => ['online'],
                'intent' => 'publish',
            ])
            ->assertRedirect('/seller/products');

        $product->load(['category', 'store', 'variants', 'images']);
        $variantImages = $product->images()->whereNotNull('variant_id')->where('purpose', 'variant_sku')->orderBy('position')->get();
        $primaryAndGalleryImages = $product->images()->whereNull('variant_id')->orderByDesc('is_primary')->get();
        $mediaPreview = app(SellerProductService::class)->formPayload($product)['media_preview'];

        $this->assertCount(3, $variantImages);
        $this->assertCount(2, $primaryAndGalleryImages);
        $this->assertSame(['variant_sku', 'variant_sku', 'variant_sku'], $variantImages->pluck('purpose')->all());
        $this->assertSame(['public'], $variantImages->pluck('disk')->unique()->values()->all());
        $this->assertDatabaseHas('product_images', ['id' => $legacyColorImage->id, 'purpose' => 'variant']);
        $this->assertSame(['primary', 'gallery'], $primaryAndGalleryImages->pluck('purpose')->all());
        $this->assertNotEmpty($primaryAndGalleryImages[0]->mime_type);
        $this->assertGreaterThan(0, (int) $primaryAndGalleryImages[0]->size_bytes);
        $this->assertTrue(Storage::disk('public')->exists($variantImages[0]->path));
        $this->assertTrue(Storage::disk('public')->exists($variantImages[1]->path));
        $this->assertTrue(Storage::disk('public')->exists($variantImages[2]->path));
        $this->assertTrue(Storage::disk('public')->exists($primaryAndGalleryImages[0]->path));
        $this->assertTrue(Storage::disk('public')->exists($primaryAndGalleryImages[1]->path));
        $this->assertCount(6, $mediaPreview);
        $this->assertSame(['primary', 'gallery', 'variant', 'variant', 'variant', 'variant'], array_column($mediaPreview, 'kind'));
        $this->assertContains(Storage::disk('public')->url($primaryAndGalleryImages[0]->path), array_column($mediaPreview, 'url'));
        $this->assertContains(Storage::disk('public')->url($variantImages[0]->path), array_column($mediaPreview, 'url'));

        $catalog = app(BuyerCatalogService::class)->card($product);
        $smallRed = collect($catalog['variants'])->firstWhere('name', 'Small / Red');
        $largeRed = collect($catalog['variants'])->firstWhere('name', 'Large / Red');
        $smallBlue = collect($catalog['variants'])->firstWhere('name', 'Small / Blue');

        $this->assertNotEmpty($smallRed['image']);
        $this->assertNotEmpty($largeRed['image']);
        $this->assertNotSame($smallRed['image'], $largeRed['image']);
        $this->assertNotEmpty($smallBlue['image']);
        $this->assertNotSame($smallRed['image'], $smallBlue['image']);

        $this->actingAs($seller)
            ->get(route('seller.products.edit', $product))
            ->assertOk()
            ->assertSee('Product Media')
            ->assertSee('Image gallery')
            ->assertSee('6 images')
            ->assertSee('data-product-media-remove', false)
            ->assertSee('data-product-media-thumbnails', false)
            ->assertSee('data-image-id=', false)
            ->assertSee($mediaPreview[0]['url'], false)
            ->assertSee('src="'.$mediaPreview[0]['url'].'" alt="Primary image"', false)
            ->assertSee('Variant Images')
            ->assertSee('data-variant-color-images', false)
            ->assertSee('name="variant_color_images[]"', false)
            ->assertSee('data-variant-color-image-uploads', false)
            ->assertSee('data-variant-matrix', false)
            ->assertSee('data-variant-rows', false)
            ->assertDontSee('data-variant-product-image', false)
            ->assertSee('Product listing')
            ->assertSee('Accepted payment methods')
            ->assertSee('data-buyer-preview-variant', false)
            ->assertSee('data-saved-product-media', false)
            ->assertSee('variant_label', false)
            ->assertDontSee('data-product-image-preview', false)
            ->assertSee('Red')
            ->assertSee('Blue')
            ->assertSee('data-existing-variants', false);

        $buyerProductResponse = $this->actingAs($this->buyer())
            ->get('/products/'.$product->id)
            ->assertOk()
            ->assertSee('Small / Red')
            ->assertSee('Large / Red')
            ->assertSee('Small / Blue')
            ->assertSee($smallRed['image'], false)
            ->assertSee('data-detail-thumbnail', false)
            ->assertSee('aria-label="View Red variant image (Small / Red)"', false)
            ->assertSee('data-image-src="'.$smallRed['image'].'"', false)
            ->assertSee('data-variant-id="'.$smallRed['id'].'"', false)
            ->assertSee('aria-label="View Blue variant image (Small / Blue)"', false)
            ->assertSee('data-image-src="'.$smallBlue['image'].'"', false)
            ->assertSee('data-variant-id="'.$smallBlue['id'].'"', false)
            ->assertSee('Online');

        $largeRedImage = $variantImages->firstWhere('variant_id', $largeRed['id']);
        $largeRedImage->delete();
        Storage::disk('public')->delete($largeRedImage->path);

        $legacyCatalog = app(BuyerCatalogService::class)->card($product->fresh(['variants', 'images']));
        $legacyLargeRed = collect($legacyCatalog['variants'])->firstWhere('name', 'Large / Red');
        $this->assertSame(Storage::disk('public')->url($legacyColorImage->path), $legacyLargeRed['image']);

        $this->assertSame(['online'], $product->fresh()->payment_methods);

        $redImage = $variantImages->firstOrFail();
        $this->actingAs($seller)
            ->put(route('seller.products.update', $product), [
                'product_mode' => 'with_variants',
                'name' => 'Variation Image Shirt',
                'category' => $category->name,
                'description' => 'A product with variation-specific images.',
                'price' => '300.00',
                'stock' => 12,
                'low_stock_threshold' => 2,
                'option_one_name' => 'Size',
                'option_one_values' => 'Small, Large',
                'option_two_name' => 'Color',
                'option_two_values' => 'Red, Blue',
                'variants' => [
                    ['label' => 'Small / Red', 'sku' => 'COLOR-SM-RED', 'price' => '300.00', 'stock' => 3, 'package_weight_kg' => '0.250', 'package_length_cm' => '24', 'package_width_cm' => '18', 'package_height_cm' => '8'],
                    ['label' => 'Large / Red', 'sku' => 'COLOR-LG-RED', 'price' => '300.00', 'stock' => 4, 'package_weight_kg' => '0.250', 'package_length_cm' => '24', 'package_width_cm' => '18', 'package_height_cm' => '8'],
                    ['label' => 'Small / Blue', 'sku' => 'COLOR-SM-BLUE', 'price' => '300.00', 'stock' => 5, 'package_weight_kg' => '0.250', 'package_length_cm' => '24', 'package_width_cm' => '18', 'package_height_cm' => '8'],
                ],
                'payment_methods' => ['online'],
                'remove_product_images' => [$redImage->id],
                'intent' => 'draft',
            ])
            ->assertRedirect('/seller/products');

        $this->assertDatabaseMissing('product_images', ['id' => $redImage->id]);
        $this->assertDatabaseHas('product_images', ['id' => $variantImages->last()->id]);
    }

    public function test_variant_color_images_are_uploaded_once_and_shared_by_matching_skus(): void
    {
        Storage::fake('public');

        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Shared Color Image Store',
            'slug' => 'shared-color-image-store',
            'publication_status' => 'published',
        ]);
        $payload = [
            'product_mode' => 'with_variants',
            'name' => 'Shared Color Image Product',
            'category' => $category->name,
            'description' => 'One photo per color shared by matching SKUs.',
            'price' => '300.00',
            'stock' => 10,
            'low_stock_threshold' => 2,
            'option_one_name' => 'Size',
            'option_one_values' => 'Small, Large',
            'option_two_name' => 'Color',
            'option_two_values' => 'Red, Blue',
            'variants' => [
                ['label' => 'Small / Red', 'sku' => 'SHARED-SM-RED', 'price' => '300.00', 'stock' => 2, 'package_weight_kg' => '0.250', 'package_length_cm' => '24', 'package_width_cm' => '18', 'package_height_cm' => '8'],
                ['label' => 'Large / Red', 'sku' => 'SHARED-LG-RED', 'price' => '300.00', 'stock' => 3, 'package_weight_kg' => '0.250', 'package_length_cm' => '24', 'package_width_cm' => '18', 'package_height_cm' => '8'],
                ['label' => 'Small / Blue', 'sku' => 'SHARED-SM-BLUE', 'price' => '300.00', 'stock' => 2, 'package_weight_kg' => '0.250', 'package_length_cm' => '24', 'package_width_cm' => '18', 'package_height_cm' => '8'],
                ['label' => 'Large / Blue', 'sku' => 'SHARED-LG-BLUE', 'price' => '300.00', 'stock' => 3, 'package_weight_kg' => '0.250', 'package_length_cm' => '24', 'package_width_cm' => '18', 'package_height_cm' => '8'],
            ],
            'payment_methods' => ['online'],
            'intent' => 'publish',
        ];

        $this->actingAs($seller)
            ->post('/seller/products', $payload)
            ->assertRedirect('/seller/products');

        $product = Product::query()->where('store_id', $store->id)->firstOrFail();

        $this->actingAs($seller)
            ->put(route('seller.products.update', $product), array_merge($payload, [
                'variant_images' => [
                    0 => UploadedFile::fake()->create('small-red-sku.jpg', 100, 'image/jpeg'),
                ],
                'variant_color_images' => [
                    0 => UploadedFile::fake()->create('red-shirt.jpg', 100, 'image/jpeg'),
                    1 => UploadedFile::fake()->create('blue-shirt.jpg', 100, 'image/jpeg'),
                ],
                'variant_image_colors' => [0 => 'Red', 1 => 'Blue'],
            ]))
            ->assertRedirect('/seller/products');

        $colorImages = $product->images()
            ->where('purpose', 'variant_color')
            ->with('variant')
            ->get();
        $this->assertCount(2, $colorImages);
        $this->assertSame(['Blue', 'Red'], $colorImages->map(
            fn (ProductImage $image): string => collect((array) $image->variant->options)
                ->first(fn ($value, $name): bool => strtolower((string) $name) === 'color')
        )->sort()->values()->all());

        foreach ($colorImages as $image) {
            $this->assertSame('public', $image->disk);
            $this->assertTrue(Storage::disk('public')->exists($image->path));
        }

        $catalog = app(BuyerCatalogService::class)->card($product->fresh(['variants', 'images']));
        $smallRed = collect($catalog['variants'])->firstWhere('name', 'Small / Red');
        $largeRed = collect($catalog['variants'])->firstWhere('name', 'Large / Red');
        $smallBlue = collect($catalog['variants'])->firstWhere('name', 'Small / Blue');
        $largeBlue = collect($catalog['variants'])->firstWhere('name', 'Large / Blue');

        $this->assertSame($smallRed['image'], $largeRed['image']);
        $this->assertSame($smallBlue['image'], $largeBlue['image']);
        $this->assertNotSame($smallRed['image'], $smallBlue['image']);
        $redSharedImage = $colorImages->first(fn (ProductImage $image): bool => collect((array) $image->variant->options)
            ->first(fn ($value, $name): bool => strtolower((string) $name) === 'color') === 'Red'
        );
        $this->assertSame(Storage::disk('public')->url($redSharedImage->path), $smallRed['image']);
        $smallRedVariant = $product->variants()->where('name', 'Small / Red')->firstOrFail();
        $orderImage = app(BuyerCatalogService::class)->orderImageRecord($product->fresh('images'), $smallRedVariant);
        $this->assertSame('variant_color', $orderImage?->purpose);

        $formPayload = app(SellerProductService::class)->formPayload($product->fresh(['variants', 'images']));
        $sharedMedia = collect($formPayload['media_preview'])->where('color_shared', true);
        $this->assertCount(2, $sharedMedia);
        $this->assertSame(['Blue', 'Red'], $sharedMedia->pluck('color')->sort()->values()->all());

        $this->actingAs($seller)
            ->get(route('seller.products.edit', $product))
            ->assertOk()
            ->assertSee('Variant Images')
            ->assertSee('data-color-shared="true"', false)
            ->assertSee('name="variant_color_images[]"', false)
            ->assertSee('data-variant-color-image-uploads', false);
    }

    public function test_seller_can_remove_saved_product_photos_only_from_their_own_product(): void
    {
        Storage::fake('public');

        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Product Photo Removal Store',
            'slug' => 'product-photo-removal-store',
            'publication_status' => 'draft',
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Photo Removal Product',
            'slug' => 'photo-removal-product',
            'product_status' => 'draft',
            'compliance_status' => 'clear',
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'PHOTO-REMOVE-001',
            'name' => 'Default',
            'price_minor' => 10000,
            'stock_on_hand' => 4,
            'is_active' => true,
        ]);

        $primaryPath = UploadedFile::fake()->create('primary.jpg', 100, 'image/jpeg')->store('seller-products', 'public');
        $galleryPath = UploadedFile::fake()->create('gallery.jpg', 100, 'image/jpeg')->store('seller-products', 'public');
        $retainedPath = UploadedFile::fake()->create('retained.jpg', 100, 'image/jpeg')->store('seller-products', 'public');
        $primaryImage = ProductImage::create([
            'product_id' => $product->id,
            'path' => $primaryPath,
            'alt_text' => 'Primary image',
            'position' => 0,
            'is_primary' => true,
        ]);
        $galleryImage = ProductImage::create([
            'product_id' => $product->id,
            'path' => $galleryPath,
            'alt_text' => 'Gallery image',
            'position' => 1,
            'is_primary' => false,
        ]);
        $retainedImage = ProductImage::create([
            'product_id' => $product->id,
            'path' => $retainedPath,
            'alt_text' => 'Retained image',
            'position' => 2,
            'is_primary' => false,
        ]);

        $otherSeller = $this->seller($category);
        $otherStore = Store::create([
            'seller_profile_id' => $otherSeller->sellerProfile->id,
            'name' => 'Other Seller Photo Store',
            'slug' => 'other-seller-photo-store',
            'publication_status' => 'draft',
        ]);
        $otherProduct = Product::create([
            'store_id' => $otherStore->id,
            'category_id' => $category->id,
            'name' => 'Other Seller Product',
            'slug' => 'other-seller-product-photo',
            'product_status' => 'draft',
            'compliance_status' => 'clear',
        ]);
        $foreignImage = ProductImage::create([
            'product_id' => $otherProduct->id,
            'path' => UploadedFile::fake()->create('foreign.jpg', 100, 'image/jpeg')->store('seller-products', 'public'),
            'alt_text' => 'Other seller image',
            'position' => 0,
            'is_primary' => true,
        ]);

        $payload = [
            'product_mode' => 'single',
            'name' => 'Photo Removal Product',
            'category' => $category->name,
            'price' => '100.00',
            'stock' => 4,
            'package_weight_kg' => '0.250',
            'package_length_cm' => '24',
            'package_width_cm' => '18',
            'package_height_cm' => '8',
            'low_stock_threshold' => 2,
            'intent' => 'draft',
        ];

        $this->actingAs($seller)
            ->from(route('seller.products.edit', $product))
            ->put(route('seller.products.update', $product), $payload + [
                'remove_product_images' => [$primaryImage->id, $foreignImage->id],
            ])
            ->assertRedirect(route('seller.products.edit', $product))
            ->assertSessionHasErrors('remove_product_images.1');

        $this->assertDatabaseHas('product_images', ['id' => $primaryImage->id]);
        $this->assertDatabaseHas('product_images', ['id' => $foreignImage->id]);

        $this->actingAs($seller)
            ->put(route('seller.products.update', $product), $payload + [
                'remove_product_images' => [$primaryImage->id, $galleryImage->id],
            ])
            ->assertRedirect('/seller/products');

        $this->assertDatabaseMissing('product_images', ['id' => $primaryImage->id]);
        $this->assertDatabaseMissing('product_images', ['id' => $galleryImage->id]);
        $this->assertDatabaseHas('product_images', ['id' => $retainedImage->id]);
        $this->assertDatabaseHas('product_images', ['id' => $foreignImage->id]);
    }

    public function test_compliance_scan_blocks_a_prohibited_publish(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Compliance Store',
            'slug' => 'compliance-store',
            'publication_status' => 'published',
        ]);
        ComplianceRule::create([
            'code' => 'TEST_BLOCKED_TERM',
            'name' => 'Blocked test term',
            'rule_type' => 'keyword',
            'target_field' => 'name_description',
            'pattern' => 'prohibited weapon',
            'severity' => 'critical',
            'default_action' => 'block',
            'is_active' => true,
        ]);

        $this->actingAs($seller)
            ->post('/seller/products', [
                'name' => 'Prohibited Weapon Listing',
                'category' => $category->name,
                'description' => '',
                'price' => '100.00',
                'stock' => 1,
                'low_stock_threshold' => 1,
                'package_weight_kg' => '0.250',
                'package_length_cm' => '24',
                'package_width_cm' => '18',
                'package_height_cm' => '8',
                'intent' => 'publish',
            ])
            ->assertRedirect('/seller/products');

        $product = Product::where('store_id', $store->id)->firstOrFail();

        $this->assertSame('blocked', $product->product_status);
        $this->assertSame('blocked', $product->compliance_status);
        $this->assertDatabaseHas('product_violations', [
            'product_id' => $product->id,
            'status' => 'flagged',
            'violation_type' => 'TEST_BLOCKED_TERM',
        ]);
    }

    public function test_seller_publication_requires_readiness_and_persists_visibility(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Publication Store',
            'slug' => 'publication-store',
            'contact_email' => $seller->email,
            'contact_phone' => '+639171234567',
            'description' => 'A complete store description.',
            'logo_path' => 'seller-store/logo.png',
            'banner_path' => 'seller-store/banner.png',
            'publication_status' => 'draft',
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Publication Product',
            'slug' => 'publication-product',
            'product_status' => 'active',
            'compliance_status' => 'clear',
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'PUBLICATION-001',
            'name' => 'Default',
            'price_minor' => 10000,
            'stock_on_hand' => 2,
            'is_active' => true,
        ]);
        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->get('/stores/'.$store->slug)
            ->assertNotFound();

        $this->actingAs($seller)
            ->postJson('/seller/store/publication', ['published' => true])
            ->assertOk()
            ->assertJsonPath('data.published', true);

        $publishedAt = $store->fresh()->published_at;

        $this->actingAs($seller)
            ->postJson('/seller/store/publication', ['published' => true])
            ->assertOk()
            ->assertJsonPath('data.published', true);

        $this->assertSame($publishedAt, $store->fresh()->published_at);

        $this->assertDatabaseHas('stores', [
            'id' => $store->id,
            'publication_status' => 'published',
        ]);

        $this->actingAs($buyer)
            ->get('/stores/'.$store->slug)
            ->assertOk();

        $this->actingAs($seller)
            ->postJson('/seller/store/publication', ['published' => false])
            ->assertOk()
            ->assertJsonPath('data.published', false);

        $this->assertDatabaseHas('stores', [
            'id' => $store->id,
            'publication_status' => 'draft',
            'published_at' => null,
        ]);

        $this->actingAs($buyer)
            ->get('/stores/'.$store->slug)
            ->assertNotFound();

        $this->actingAs($seller)
            ->postJson('/seller/store/publication', ['published' => false])
            ->assertOk()
            ->assertJsonPath('data.published', false);
    }

    public function test_incomplete_store_publication_is_rejected_by_the_publication_service(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Incomplete Publication Store',
            'slug' => 'incomplete-publication-store',
            'contact_email' => $seller->email,
            'contact_phone' => '+639171234567',
            'description' => 'Description is present.',
            'logo_path' => 'seller-store/logo.png',
            'publication_status' => 'draft',
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Eligible Publication Product',
            'slug' => 'eligible-publication-product',
            'product_status' => 'active',
            'compliance_status' => 'clear',
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'INCOMPLETE-PUBLICATION-001',
            'name' => 'Default',
            'price_minor' => 10000,
            'stock_on_hand' => 2,
            'is_active' => true,
        ]);

        $this->actingAs($seller)
            ->postJson('/seller/store/publication', ['published' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Complete every store publication requirement before publishing.');

        $this->assertDatabaseHas('stores', [
            'id' => $store->id,
            'publication_status' => 'draft',
            'published_at' => null,
        ]);
    }

    public function test_publication_is_bound_to_the_authenticated_sellers_store(): void
    {
        $category = $this->category();
        $sellerA = $this->seller($category);
        $sellerB = $this->seller($category);
        $storeA = Store::create([
            'seller_profile_id' => $sellerA->sellerProfile->id,
            'name' => 'Seller A Publication Store',
            'slug' => 'seller-a-publication-store',
            'contact_email' => $sellerA->email,
            'contact_phone' => '+639171234567',
            'description' => 'Seller A description.',
            'logo_path' => 'seller-store/a-logo.png',
            'banner_path' => 'seller-store/a-banner.png',
            'publication_status' => 'draft',
        ]);
        $product = Product::create([
            'store_id' => $storeA->id,
            'category_id' => $category->id,
            'name' => 'Seller A Eligible Product',
            'slug' => 'seller-a-eligible-product',
            'product_status' => 'active',
            'compliance_status' => 'clear',
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SELLER-A-PUBLISH-001',
            'name' => 'Default',
            'price_minor' => 10000,
            'stock_on_hand' => 2,
            'is_active' => true,
        ]);
        $storeB = Store::create([
            'seller_profile_id' => $sellerB->sellerProfile->id,
            'name' => 'Seller B Publication Store',
            'slug' => 'seller-b-publication-store',
            'publication_status' => 'draft',
        ]);

        $this->actingAs($sellerA)
            ->postJson('/seller/store/publication', ['published' => true])
            ->assertOk()
            ->assertJsonPath('data.slug', $storeA->slug);

        $this->assertSame('published', $storeA->fresh()->publication_status);
        $this->assertSame('draft', $storeB->fresh()->publication_status);
    }

    public function test_suspended_seller_store_is_hidden_from_buyers(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Suspended Seller Store',
            'slug' => 'suspended-seller-store',
            'publication_status' => 'published',
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Suspended Seller Product',
            'slug' => 'suspended-seller-product',
            'product_status' => 'active',
            'compliance_status' => 'clear',
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SUSPENDED-SELLER-001',
            'name' => 'Default',
            'price_minor' => 10000,
            'stock_on_hand' => 2,
            'is_active' => true,
        ]);
        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->get('/stores/'.$store->slug)
            ->assertOk();

        $cartItemId = $this->actingAs($buyer)
            ->postJson('/cart/add', [
                'product_variant_id' => $product->variants()->firstOrFail()->id,
                'quantity' => 1,
            ])
            ->assertOk()
            ->json('data.item.id');

        $seller->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($buyer)
            ->get('/stores/'.$store->slug)
            ->assertNotFound();

        $this->actingAs($buyer)
            ->get('/products/'.$product->id)
            ->assertNotFound();

        $this->actingAs($buyer)
            ->postJson('/cart/add', [
                'product_variant_id' => $product->variants()->firstOrFail()->id,
                'quantity' => 1,
            ])
            ->assertNotFound();

        $this->actingAs($buyer)
            ->patchJson('/cart/'.$cartItemId, ['quantity' => 1])
            ->assertNotFound();

        $this->actingAs($seller)
            ->postJson('/seller/store/publication', ['published' => false])
            ->assertForbidden();
    }

    public function test_buyer_does_not_expose_a_product_without_an_active_variant(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Unavailable Store',
            'slug' => 'unavailable-store',
            'publication_status' => 'published',
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Unavailable Product',
            'slug' => 'unavailable-product',
            'product_status' => 'active',
            'compliance_status' => 'clear',
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'UNAVAILABLE-001',
            'name' => 'Default',
            'price_minor' => 10000,
            'stock_on_hand' => 5,
            'is_active' => false,
        ]);

        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->get('/products/'.$product->id)
            ->assertNotFound();

        $this->actingAs($buyer)
            ->postJson('/wishlist/toggle', ['product_id' => $product->id])
            ->assertNotFound();
    }

    public function test_seller_product_mutations_are_scoped_to_the_authenticated_store(): void
    {
        $category = $this->category();
        $sellerA = $this->seller($category);
        $sellerB = $this->seller($category);
        $storeB = Store::create([
            'seller_profile_id' => $sellerB->sellerProfile->id,
            'name' => 'Seller B Store',
            'slug' => 'seller-b-store',
            'publication_status' => 'published',
        ]);
        $product = Product::create([
            'store_id' => $storeB->id,
            'category_id' => $category->id,
            'name' => 'Seller B Product',
            'slug' => 'seller-b-product',
            'product_status' => 'active',
            'compliance_status' => 'clear',
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SELLER-B-001',
            'name' => 'Default',
            'price_minor' => 10000,
            'stock_on_hand' => 5,
            'is_active' => true,
        ]);

        $payload = [
            'name' => 'Tampered Product',
            'category' => $category->name,
            'description' => 'This update must not cross Store ownership.',
            'price' => '100.00',
            'stock' => 5,
            'low_stock_threshold' => 1,
            'intent' => 'draft',
        ];

        $this->actingAs($sellerA)
            ->put('/seller/products/'.$product->id, $payload)
            ->assertNotFound();

        $this->actingAs($sellerA)
            ->patch('/seller/products/'.$product->id.'/archive')
            ->assertNotFound();

        $this->assertSame('Seller B Product', $product->fresh()->name);
        $this->assertSame('active', $product->fresh()->product_status);
    }

    public function test_seller_cannot_revive_a_product_removed_by_platform_moderation(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Moderated Store',
            'slug' => 'moderated-store',
            'publication_status' => 'published',
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Removed By Admin',
            'slug' => 'removed-by-admin',
            'product_status' => 'blocked',
            'compliance_status' => 'blocked',
            'removed_at' => now(),
            'removed_reason' => 'Policy violation',
        ]);
        $payload = [
            'name' => 'Attempted Revival',
            'category' => $category->name,
            'description' => 'This product must remain unavailable.',
            'price' => '100.00',
            'stock' => 1,
            'package_weight_kg' => '0.250',
            'package_length_cm' => '24',
            'package_width_cm' => '18',
            'package_height_cm' => '8',
            'low_stock_threshold' => 1,
            'intent' => 'publish',
        ];

        $this->actingAs($seller)
            ->from('/seller/products/'.$product->id.'/edit')
            ->put('/seller/products/'.$product->id, $payload)
            ->assertRedirect('/seller/products/'.$product->id.'/edit')
            ->assertSessionHasErrors('product');

        $this->actingAs($seller)
            ->from('/seller/products')
            ->patch('/seller/products/'.$product->id.'/archive')
            ->assertRedirect('/seller/products')
            ->assertSessionHasErrors('product');

        $this->assertSame('Removed By Admin', $product->fresh()->name);
        $this->assertSame('blocked', $product->fresh()->product_status);
    }

    public function test_seller_product_category_must_match_the_approved_category(): void
    {
        $approvedCategory = $this->category();
        $otherCategory = Category::create([
            'name' => 'Electronics and Gadgets',
            'slug' => 'electronics-and-gadgets',
        ]);
        $seller = $this->seller($approvedCategory);

        $this->actingAs($seller)
            ->from('/seller/products/create')
            ->post('/seller/products', [
                'name' => 'Wrong Category Product',
                'category' => $otherCategory->name,
                'price' => '100.00',
                'stock' => 1,
                'low_stock_threshold' => 1,
                'intent' => 'draft',
            ])
            ->assertRedirect('/seller/products/create')
            ->assertSessionHasErrors('category');

        $this->assertDatabaseMissing('products', ['name' => 'Wrong Category Product']);
    }

    public function test_buyer_hides_ineligible_product_statuses_and_removed_products(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Eligibility Store',
            'slug' => 'eligibility-store',
            'publication_status' => 'published',
        ]);
        $statuses = [
            ['draft', 'clear'],
            ['pending_review', 'pending_scan'],
            ['flagged', 'flagged'],
            ['blocked', 'blocked'],
            ['archived', 'clear'],
        ];
        $products = [];

        foreach ($statuses as [$productStatus, $complianceStatus]) {
            $product = Product::create([
                'store_id' => $store->id,
                'category_id' => $category->id,
                'name' => ucfirst(str_replace('_', ' ', $productStatus)).' Product',
                'slug' => $productStatus.'-eligibility-product',
                'product_status' => $productStatus,
                'compliance_status' => $complianceStatus,
            ]);
            ProductVariant::create([
                'product_id' => $product->id,
                'sku' => 'ELIGIBILITY-'.strtoupper($productStatus),
                'name' => 'Default',
                'price_minor' => 10000,
                'stock_on_hand' => 5,
                'is_active' => true,
            ]);
            $products[] = $product;
        }

        $removedProduct = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Removed Product',
            'slug' => 'removed-eligibility-product',
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'removed_at' => now(),
            'removed_reason' => 'Moderation removal',
        ]);
        ProductVariant::create([
            'product_id' => $removedProduct->id,
            'sku' => 'ELIGIBILITY-REMOVED',
            'name' => 'Default',
            'price_minor' => 10000,
            'stock_on_hand' => 5,
            'is_active' => true,
        ]);
        $products[] = $removedProduct;
        $buyer = $this->buyer();

        foreach ($products as $product) {
            $this->actingAs($buyer)
                ->get('/products/'.$product->id)
                ->assertNotFound();
        }
    }

    public function test_store_first_publication_requires_available_stock_but_zero_stock_products_remain_visible_later(): void
    {
        $category = $this->category();
        $seller = $this->seller($category);
        $store = Store::create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'name' => 'Stock Eligibility Store',
            'slug' => 'stock-eligibility-store',
            'contact_email' => $seller->email,
            'contact_phone' => '+639171234567',
            'description' => 'A complete store description.',
            'logo_path' => 'seller-store/logo.png',
            'banner_path' => 'seller-store/banner.png',
            'publication_status' => 'draft',
        ]);
        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Stock Checked Product',
            'slug' => 'stock-checked-product',
            'product_status' => 'active',
            'compliance_status' => 'clear',
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'STOCK-CHECK-001',
            'name' => 'Default',
            'price_minor' => 10000,
            'stock_on_hand' => 0,
            'stock_reserved' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($seller)
            ->postJson('/seller/store/publication', ['published' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Complete every store publication requirement before publishing.');

        $this->assertSame('draft', $store->fresh()->publication_status);

        $variant->update(['stock_on_hand' => 1]);

        $this->actingAs($seller)
            ->postJson('/seller/store/publication', ['published' => true])
            ->assertOk()
            ->assertJsonPath('data.published', true);

        $variant->update(['stock_on_hand' => 0]);
        $buyer = $this->buyer();

        $this->actingAs($buyer)
            ->get('/products/'.$product->id)
            ->assertOk()
            ->assertSee('Stock Checked Product');

        $this->actingAs($buyer)
            ->get('/stores/'.$store->slug)
            ->assertOk()
            ->assertSee('Stock Checked Product');
    }

    private function category(): Category
    {
        return Category::create([
            'name' => 'Fashion and Apparel',
            'slug' => 'fashion-and-apparel',
        ]);
    }

    private function seller(Category $category): User
    {
        $seller = User::create([
            'name' => 'Integration Seller',
            'first_name' => 'Integration',
            'last_name' => 'Seller',
            'email' => 'seller-'.uniqid().'@example.test',
            'role' => 'seller',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);

        SellerProfile::create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Integration Seller Business',
            'approved_category_id' => $category->id,
            'approved_at' => now(),
        ]);

        return $seller->fresh('sellerProfile');
    }

    private function buyer(): User
    {
        return User::create([
            'name' => 'Integration Buyer',
            'first_name' => 'Integration',
            'last_name' => 'Buyer',
            'email' => 'buyer-'.uniqid().'@example.test',
            'role' => 'buyer',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
    }
}
