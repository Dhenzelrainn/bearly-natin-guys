<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\StoreSubcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SellerInventoryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_page_uses_store_variants_and_scoped_movement_history(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'inventory-owner');
        [$otherSeller, $otherStore] = $this->sellerAndStore($category, 'inventory-other');
        $tops = $this->subcategory($store, $category, 'Tops');
        $foreignSubcategory = $this->subcategory($otherStore, $category, 'Private subcategory');

        $shirt = $this->product($store, $category, 'Inventory Shirt', 'inventory-shirt');
        $shirt->update(['subcategory_id' => $tops->id]);
        $inStock = $this->variant($shirt, 'INV-SHIRT-M', 'Medium', 12, 3, 5);
        $lowStock = $this->variant($shirt, 'INV-SHIRT-L', 'Large', 6, 2, 5);
        $soldOutProduct = $this->product($store, $category, 'Sold Out Item', 'sold-out-item');
        $soldOut = $this->variant($soldOutProduct, 'INV-SOLD-OUT', 'Default', 2, 2, 4);
        $archivedProduct = $this->product($store, $category, 'Archived Item', 'archived-item', 'archived');
        $archived = $this->variant($archivedProduct, 'INV-ARCHIVED', 'Default', 9, 0, 3);
        $foreignProduct = $this->product($otherStore, $category, 'Private Other Store Item', 'private-other-item');
        $foreignProduct->update(['subcategory_id' => $foreignSubcategory->id]);
        $foreignVariant = $this->variant($foreignProduct, 'INV-PRIVATE', 'Default', 40, 0, 5);

        InventoryMovement::create([
            'variant_id' => $lowStock->id,
            'type' => 'adjustment',
            'quantity_delta' => -1,
            'balance_after' => 6,
            'reason' => 'Manual stock count',
            'created_by' => $seller->id,
            'occurred_at' => now(),
        ]);
        InventoryMovement::create([
            'variant_id' => $foreignVariant->id,
            'type' => 'adjustment',
            'quantity_delta' => 4,
            'balance_after' => 40,
            'reason' => 'Private movement',
            'created_by' => $otherSeller->id,
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($seller)
            ->get(route('seller.inventory'))
            ->assertOk();

        $response
            ->assertSee('<strong>3</strong>', false)
            ->assertSee('<strong>13</strong>', false)
            ->assertSee('2 SKU(s) need restocking')
            ->assertSee('data-inventory-tab="needs-restocking"', false)
            ->assertSee('Needs Restocking')
            ->assertSee('INV-SHIRT-M')
            ->assertSee('INV-SHIRT-L')
            ->assertSee('INV-SOLD-OUT')
            ->assertSee('INV-ARCHIVED')
            ->assertSee('Filter by subcategory')
            ->assertSee('All Subcategories')
            ->assertSee('Tops')
            ->assertSee('data-subcategory="'.$tops->id.'"', false)
            ->assertSee('Uncategorized')
            ->assertSee('Manual stock count')
            ->assertSee('Inventory Owner')
            ->assertDontSee('INV-PRIVATE')
            ->assertDontSee('Private Other Store Item')
            ->assertDontSee('Private subcategory')
            ->assertDontSee('Private movement');

        preg_match('/<button\\b(?=[^>]*data-inventory-bulk="set-threshold")[^>]*>/s', $response->getContent(), $thresholdButton);
        $this->assertNotEmpty($thresholdButton);
        $this->assertStringNotContainsString('disabled', $thresholdButton[0]);

        $this->assertDatabaseHas('product_variants', [
            'id' => $inStock->id,
            'stock_on_hand' => 12,
        ]);
        $this->assertDatabaseHas('product_variants', [
            'id' => $soldOut->id,
            'stock_on_hand' => 2,
        ]);
        $this->assertDatabaseHas('product_variants', [
            'id' => $archived->id,
            'stock_on_hand' => 9,
        ]);
    }

    public function test_stock_adjustment_persists_balance_and_auditable_movement(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'inventory-adjust');
        $product = $this->product($store, $category, 'Adjustment Item', 'adjustment-item');
        $variant = $this->variant($product, 'INV-ADJUST-1', 'Default', 10, 2, 4);

        $this->actingAs($seller)
            ->from(route('seller.inventory'))
            ->post(route('seller.inventory.adjustments'), [
                'inventory_action' => 'adjust',
                'variant_ids' => [$variant->id],
                'mode' => 'add',
                'quantity' => 3,
                'reason' => 'new_stock_received',
                'reason_note' => 'Invoice INV-44',
            ])
            ->assertRedirect(route('seller.inventory'))
            ->assertSessionHas('success', 'Stock adjusted and recorded for 1 SKU(s).');

        $this->assertSame(13, (int) $variant->fresh()->stock_on_hand);
        $this->assertSame(11, $variant->fresh()->available_stock);
        $this->assertDatabaseHas('inventory_movements', [
            'variant_id' => $variant->id,
            'type' => 'adjustment',
            'quantity_delta' => 3,
            'balance_after' => 13,
            'reason' => 'New stock received — Invoice INV-44',
            'created_by' => $seller->id,
        ]);

        $this->actingAs($seller)
            ->get(route('seller.inventory'))
            ->assertOk()
            ->assertSee('13')
            ->assertSee('New stock received — Invoice INV-44');
    }

    public function test_bulk_adjustment_is_atomic_and_cannot_reduce_stock_below_reservations(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'inventory-bulk');
        $product = $this->product($store, $category, 'Bulk Adjustment Item', 'bulk-adjustment-item');
        $availableVariant = $this->variant($product, 'INV-BULK-1', 'First', 8, 0, 3);
        $reservedVariant = $this->variant($product, 'INV-BULK-2', 'Second', 4, 3, 3);

        $this->actingAs($seller)
            ->from(route('seller.inventory'))
            ->post(route('seller.inventory.adjustments'), [
                'inventory_action' => 'adjust',
                'variant_ids' => [$availableVariant->id, $reservedVariant->id],
                'mode' => 'remove',
                'quantity' => 2,
                'reason' => 'damaged_item',
            ])
            ->assertRedirect(route('seller.inventory'))
            ->assertSessionHasErrors('quantity');

        $this->assertSame(8, (int) $availableVariant->fresh()->stock_on_hand);
        $this->assertSame(4, (int) $reservedVariant->fresh()->stock_on_hand);
        $this->assertSame(0, InventoryMovement::query()->count());
    }

    public function test_inventory_mutations_are_store_scoped_and_thresholds_persist(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'inventory-threshold-owner');
        [, $otherStore] = $this->sellerAndStore($category, 'inventory-threshold-other');
        $ownProduct = $this->product($store, $category, 'Threshold Item', 'threshold-item');
        $ownVariant = $this->variant($ownProduct, 'INV-THRESHOLD-1', 'Default', 15, 2, 5);
        $ownVariantTwo = $this->variant($ownProduct, 'INV-THRESHOLD-3', 'Large', 7, 1, 5);
        $foreignProduct = $this->product($otherStore, $category, 'Foreign Threshold Item', 'foreign-threshold-item');
        $foreignVariant = $this->variant($foreignProduct, 'INV-THRESHOLD-2', 'Default', 15, 0, 5);

        $this->actingAs($seller)
            ->from(route('seller.inventory'))
            ->post(route('seller.inventory.thresholds'), [
                'inventory_action' => 'threshold',
                'variant_ids' => [$ownVariant->id, $ownVariantTwo->id],
                'threshold' => 12,
            ])
            ->assertRedirect(route('seller.inventory'))
            ->assertSessionHas('success', 'Low-stock threshold saved for 2 SKU(s).');

        $this->assertSame(12, (int) $ownVariant->fresh()->low_stock_threshold);
        $this->assertSame(12, (int) $ownVariantTwo->fresh()->low_stock_threshold);
        $this->assertSame(0, InventoryMovement::query()->count());

        $this->actingAs($seller)
            ->from(route('seller.inventory'))
            ->post(route('seller.inventory.adjustments'), [
                'inventory_action' => 'adjust',
                'variant_ids' => [$ownVariant->id, $foreignVariant->id],
                'mode' => 'add',
                'quantity' => 2,
                'reason' => 'new_stock_received',
            ])
            ->assertNotFound();

        $this->assertSame(15, (int) $ownVariant->fresh()->stock_on_hand);
        $this->assertSame(15, (int) $foreignVariant->fresh()->stock_on_hand);
        $this->assertSame(0, InventoryMovement::query()->count());
    }

    public function test_inventory_thresholds_show_in_product_editor_and_mixed_thresholds_survive_edits(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'inventory-editor-threshold');
        $product = $this->product($store, $category, 'Threshold Editor Item', 'threshold-editor-item');
        $small = $this->variant($product, 'INV-EDITOR-SMALL', 'Small', 13, 0, 5);
        $large = $this->variant($product, 'INV-EDITOR-LARGE', 'Large', 13, 0, 5);

        $this->actingAs($seller)
            ->from(route('seller.inventory'))
            ->post(route('seller.inventory.thresholds'), [
                'inventory_action' => 'threshold',
                'variant_ids' => [$small->id, $large->id],
                'threshold' => 7,
            ])
            ->assertRedirect(route('seller.inventory'));

        $uniformResponse = $this->actingAs($seller)
            ->get(route('seller.products.edit', $product))
            ->assertOk()
            ->assertSee('Stock and alert per variation')
            ->assertSee('"low_stock_threshold":7', false);
        $this->assertSame(2, substr_count($uniformResponse->getContent(), '"low_stock_threshold":7'));

        $this->actingAs($seller)
            ->from(route('seller.inventory'))
            ->post(route('seller.inventory.thresholds'), [
                'inventory_action' => 'threshold',
                'variant_ids' => [$small->id],
                'threshold' => 9,
            ])
            ->assertRedirect(route('seller.inventory'));

        $mixedResponse = $this->actingAs($seller)
            ->get(route('seller.products.edit', $product))
            ->assertOk()
            ->assertSee('Stock and alert per variation')
            ->assertSee('"low_stock_threshold":9', false)
            ->assertSee('"low_stock_threshold":7', false)
            ->assertDontSee('Varies by SKU')
            ->assertDontSee('Saved low-stock threshold by SKU:');
        preg_match('/<label\\b(?=[^>]*data-product-global-threshold)(?=[^>]*\\bhidden\\b)[^>]*>/', $mixedResponse->getContent(), $thresholdControl);
        $this->assertNotEmpty($thresholdControl);

        $this->actingAs($seller)
            ->put(route('seller.products.update', $product), [
                'product_mode' => 'with_variants',
                'name' => 'Threshold Editor Item',
                'category' => $category->name,
                'description' => 'Preserve per-SKU thresholds on unrelated edits.',
                'price' => '100.00',
                'payment_methods' => ['cod'],
                'payment_methods_present' => 1,
                'stock' => 26,
                'option_one_name' => 'Size',
                'option_one_values' => 'Small, Large',
                'option_two_name' => '',
                'option_two_values' => '',
                'variants' => [
                    ['label' => 'Small', 'sku' => 'INV-EDITOR-SMALL', 'price' => '100.00', 'stock' => 13, 'low_stock_threshold' => 11, 'package_weight_kg' => '0.250', 'package_length_cm' => '24', 'package_width_cm' => '18', 'package_height_cm' => '8'],
                    ['label' => 'Large', 'sku' => 'INV-EDITOR-LARGE', 'price' => '100.00', 'stock' => 13, 'low_stock_threshold' => 7, 'package_weight_kg' => '0.250', 'package_length_cm' => '24', 'package_width_cm' => '18', 'package_height_cm' => '8'],
                ],
                'intent' => 'draft',
            ])
            ->assertRedirect(route('seller.products'));

        $this->assertSame(11, (int) $small->fresh()->low_stock_threshold);
        $this->assertSame(7, (int) $large->fresh()->low_stock_threshold);
    }

    private function category(): Category
    {
        return Category::create([
            'name' => 'Inventory Test Category',
            'slug' => 'inventory-test-category',
        ]);
    }

    private function subcategory(Store $store, Category $category, string $name): StoreSubcategory
    {
        return StoreSubcategory::create([
            'store_id' => $store->id,
            'parent_category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }

    /** @return array{0: User, 1: Store} */
    private function sellerAndStore(Category $category, string $slug): array
    {
        $seller = User::create([
            'name' => Str::headline($slug),
            'first_name' => 'Inventory',
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

    private function product(
        Store $store,
        Category $category,
        string $name,
        string $slug,
        string $status = 'active',
    ): Product {
        return Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => $slug,
            'product_status' => $status,
            'compliance_status' => 'clear',
        ]);
    }

    private function variant(
        Product $product,
        string $sku,
        string $name,
        int $onHand,
        int $reserved,
        int $threshold,
    ): ProductVariant {
        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'name' => $name,
            'price_minor' => 10000,
            'stock_on_hand' => $onHand,
            'stock_reserved' => $reserved,
            'low_stock_threshold' => $threshold,
            'is_active' => true,
        ]);
    }
}
