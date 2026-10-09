<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\StorePromotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SellerPricingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private int $userSequence = 0;

    public function test_pricing_and_promotion_form_use_only_real_store_records(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'pricing-owner');
        [, $otherStore] = $this->sellerAndStore($category, 'pricing-other');
        $product = $this->product($store, $category, 'Persisted Pricing Product', 'persisted-pricing-product');
        $this->variant($product, 'PRICE-OWN-001', 'Standard', 129900, 0, true, 129900);
        $foreignProduct = $this->product($otherStore, $category, 'Private Foreign Product', 'private-foreign-product');
        $this->variant($foreignProduct, 'PRICE-FOREIGN-001', 'Standard', 8700);

        $this->actingAs($seller)
            ->get(route('seller.products.pricing'))
            ->assertOk()
            ->assertSee('Persisted Pricing Product')
            ->assertDontSee('Private Foreign Product')
            ->assertDontSee('CLS-LINEN-SHIRT')
            ->assertDontSee('September Payday Sale')
            ->assertSee('No saved promotion campaigns yet.')
            ->assertSee('Create promotion')
            ->assertSee(route('seller.products.pricing.update', $product), false)
            ->assertSee('placeholder="0"', false)
            ->assertSee('data-price-variant-count>0</strong> active SKU variants', false);

        $this->actingAs($seller)
            ->get(route('seller.products.pricing.create'))
            ->assertOk()
            ->assertSee('Saved campaigns are currently for Seller Center tracking only')
            ->assertSee('Save Promotion')
            ->assertSee(route('seller.products.pricing.store'), false)
            ->assertSee('value="store_voucher"', false)
            ->assertSee('value="Store Voucher" readonly', false)
            ->assertDontSee('<option value="product_discount"', false)
            ->assertSee('Manage product discounts in Product Pricing.')
            ->assertSee('Persisted Pricing Product')
            ->assertDontSee('Private Foreign Product')
            ->assertDontSee('CLS-LINEN-SHIRT');
    }

    public function test_product_edit_does_not_expose_discount_and_preserves_it_when_regular_price_changes(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'product-discount-owner');
        $product = $this->product($store, $category, 'Discount Preservation Product', 'discount-preservation-product', 'draft');
        $variant = $this->variant($product, 'DISCOUNT-PRESERVE-001', 'Default', 8500, 4, true, 10000);

        $this->actingAs($seller)
            ->get(route('seller.products.create'))
            ->assertOk()
            ->assertSee('data-product-discount-percent="0"', false)
            ->assertDontSee('name="discount_percent"', false);

        $this->actingAs($seller)
            ->get(route('seller.products.edit', $product))
            ->assertOk()
            ->assertSee('data-product-discount-percent="15"', false)
            ->assertSee('Existing seller discount: 15%.')
            ->assertDontSee('name="discount_percent"', false)
            ->assertDontSee('data-clear-product-discount', false);

        $this->actingAs($seller)
            ->put(route('seller.products.update', $product), [
                'product_mode' => 'single',
                'name' => $product->name,
                'category' => $category->name,
                'sku' => $variant->sku,
                'price' => '200.00',
                'stock' => 4,
                'low_stock_threshold' => 5,
                'package_weight_kg' => '0.250',
                'package_length_cm' => '24',
                'package_width_cm' => '18',
                'package_height_cm' => '8',
                'payment_methods' => ['cod'],
                'intent' => 'draft',
            ])
            ->assertRedirect(route('seller.products'));

        $this->assertSame(17000, (int) $variant->fresh()->price_minor);
        $this->assertSame(20000, (int) $variant->fresh()->compare_at_price_minor);
    }

    public function test_campaign_creation_persists_only_store_voucher_rules_and_is_store_scoped(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'promotion-owner');
        [, $otherStore] = $this->sellerAndStore($category, 'promotion-other');
        $voucherProduct = $this->product($store, $category, 'Campaign Voucher Product', 'campaign-voucher-product');
        $voucherVariant = $this->variant($voucherProduct, 'CAMPAIGN-VOUCHER-001', 'Standard', 8000);
        $foreignProduct = $this->product($otherStore, $category, 'Foreign Campaign Product', 'foreign-campaign-product');
        $this->variant($foreignProduct, 'CAMPAIGN-FOREIGN-001', 'Standard', 5000);
        $legacyPromotion = $store->promotions()->create([
            'name' => 'Legacy Product Discount',
            'type' => 'product_discount',
            'discount_percent' => 15,
            'starts_on' => today()->toDateString(),
            'ends_on' => today()->addDay()->toDateString(),
            'minimum_spend_minor' => 0,
            'per_buyer_limit' => 1,
        ]);
        $legacyPromotion->products()->attach($voucherProduct->id);
        $otherStore->promotions()->create([
            'name' => 'Foreign Store Campaign',
            'type' => 'product_discount',
            'discount_percent' => 10,
            'starts_on' => today()->toDateString(),
            'ends_on' => today()->addDay()->toDateString(),
            'minimum_spend_minor' => 0,
            'per_buyer_limit' => 1,
        ]);

        $this->actingAs($seller)
            ->from(route('seller.products.pricing.create'))
            ->post(route('seller.products.pricing.store'), [
                'name' => 'Seller Payday Voucher',
                'type' => 'store_voucher',
                'value' => '50.25',
                'start_date' => today()->addDays(3)->toDateString(),
                'end_date' => today()->addDays(5)->toDateString(),
                'products' => [$voucherProduct->id],
                'minimum_spend' => '250.75',
                'redemption_limit' => '500',
                'buyer_limit' => '2',
            ])
            ->assertRedirect(route('seller.products.pricing', ['tab' => 'campaigns']))
            ->assertSessionHas('success');

        $voucherPromotion = StorePromotion::query()
            ->where('name', 'Seller Payday Voucher')
            ->firstOrFail();
        $this->assertSame((int) $store->id, (int) $voucherPromotion->store_id);
        $this->assertSame((int) $seller->id, (int) $voucherPromotion->created_by);
        $this->assertSame('store_voucher', $voucherPromotion->type);
        $this->assertNull($voucherPromotion->discount_percent);
        $this->assertSame(5025, (int) $voucherPromotion->voucher_amount_minor);
        $this->assertSame(25075, (int) $voucherPromotion->minimum_spend_minor);
        $this->assertSame(500, (int) $voucherPromotion->redemption_limit);
        $this->assertSame(2, (int) $voucherPromotion->per_buyer_limit);
        $this->assertDatabaseHas('store_promotion_products', [
            'store_promotion_id' => $voucherPromotion->id,
            'product_id' => $voucherProduct->id,
        ]);
        $this->assertSame(8000, (int) $voucherVariant->fresh()->price_minor);

        $this->actingAs($seller)
            ->get(route('seller.products.pricing', ['tab' => 'campaigns']))
            ->assertOk()
            ->assertSee('Seller Payday Voucher')
            ->assertSee('Legacy Product Discount')
            ->assertSee('15% off')
            ->assertSee('₱50.25 voucher')
            ->assertSee('Min. spend ₱250.75')
            ->assertSee('Scheduled')
            ->assertDontSee('Foreign Campaign Product')
            ->assertDontSee('Foreign Store Campaign');
    }

    public function test_campaign_validation_rejects_invalid_offers_and_foreign_products(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'promotion-validation-owner');
        [, $otherStore] = $this->sellerAndStore($category, 'promotion-validation-other');
        $product = $this->product($store, $category, 'Promotion Validation Product', 'promotion-validation-product');
        $this->variant($product, 'PROMOTION-VALIDATION-001', 'Standard', 10000);
        $foreignProduct = $this->product($otherStore, $category, 'Foreign Validation Product', 'foreign-validation-product');
        $this->variant($foreignProduct, 'PROMOTION-VALIDATION-FOREIGN-001', 'Standard', 10000);
        $basePayload = [
            'name' => 'Invalid Seller Campaign',
            'type' => 'store_voucher',
            'value' => '0.00',
            'start_date' => today()->toDateString(),
            'end_date' => today()->addDay()->toDateString(),
            'products' => [$product->id],
            'buyer_limit' => '1',
        ];

        $this->actingAs($seller)
            ->from(route('seller.products.pricing.create'))
            ->post(route('seller.products.pricing.store'), $basePayload)
            ->assertRedirect(route('seller.products.pricing.create'))
            ->assertSessionHasErrors('value');

        $this->actingAs($seller)
            ->from(route('seller.products.pricing.create'))
            ->post(route('seller.products.pricing.store'), [
                ...$basePayload,
                'value' => '10.00',
                'products' => [$foreignProduct->id],
            ])
            ->assertRedirect(route('seller.products.pricing.create'))
            ->assertSessionHasErrors('products');

        $this->actingAs($seller)
            ->from(route('seller.products.pricing.create'))
            ->post(route('seller.products.pricing.store'), [
                ...$basePayload,
                'type' => 'product_discount',
                'value' => '10.00',
                'products' => [$product->id],
            ])
            ->assertRedirect(route('seller.products.pricing.create'))
            ->assertSessionHasErrors('type');

        $this->assertSame(0, StorePromotion::query()->count());
        $this->assertSame((int) $store->id, (int) $product->fresh()->store_id);
    }

    public function test_price_update_persists_centavo_prices_to_all_and_only_active_variants(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'pricing-update');
        $product = $this->product($store, $category, 'Variant Pricing Product', 'variant-pricing-product');
        $small = $this->variant($product, 'PRICE-VARIANT-SMALL', 'Small', 9000, 3, true, 10000);
        $large = $this->variant($product, 'PRICE-VARIANT-LARGE', 'Large', 18750, 5);
        $inactive = $this->variant($product, 'PRICE-VARIANT-OLD', 'Inactive', 5000, 0, false, 7000);
        $small->update(['stock_reserved' => 3]);
        $large->update(['stock_reserved' => 5]);

        $this->actingAs($seller)
            ->patchJson(route('seller.products.pricing.update', $product), [
                'regular_price' => '200.55',
                'discount_percent' => 15,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Product pricing saved.')
            ->assertJsonPath('data.product_id', $product->id)
            ->assertJsonPath('data.regular_price_minor', 20055)
            ->assertJsonPath('data.sale_price_minor', 17047)
            ->assertJsonPath('data.discount_percent', 15)
            ->assertJsonPath('data.active_variant_count', 2);

        foreach ([$small, $large] as $variant) {
            $this->assertDatabaseHas('product_variants', [
                'id' => $variant->id,
                'price_minor' => 17047,
                'compare_at_price_minor' => 20055,
            ]);
        }

        $this->assertDatabaseHas('product_variants', [
            'id' => $inactive->id,
            'price_minor' => 5000,
            'compare_at_price_minor' => 7000,
        ]);
        $this->assertSame(3, (int) $small->fresh()->stock_on_hand);
        $this->assertSame(5, (int) $large->fresh()->stock_reserved);
        $this->assertSame(0, InventoryMovement::query()->count());

        $this->actingAs($seller)
            ->patchJson(route('seller.products.pricing.update', $product), [
                'regular_price' => '200.55',
                'discount_percent' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('data.sale_price_minor', 20055);

        $this->assertDatabaseHas('product_variants', [
            'id' => $small->id,
            'price_minor' => 20055,
            'compare_at_price_minor' => null,
        ]);
    }

    public function test_price_update_validates_values_and_rejects_products_without_active_skus(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'pricing-validation');
        $product = $this->product($store, $category, 'Price Validation Product', 'price-validation-product');
        $variant = $this->variant($product, 'PRICE-VALIDATION-001', 'Standard', 10000);
        $noActiveSku = $this->product($store, $category, 'No Active SKU Product', 'no-active-sku-product');
        $this->variant($noActiveSku, 'PRICE-NO-ACTIVE-001', 'Inactive', 10000, 0, false);

        foreach ([
            ['regular_price' => '1.999', 'discount_percent' => 10],
            ['regular_price' => '-10.00', 'discount_percent' => 10],
            ['regular_price' => '100.00', 'discount_percent' => 91],
        ] as $payload) {
            $this->actingAs($seller)
                ->patchJson(route('seller.products.pricing.update', $product), $payload)
                ->assertUnprocessable();
        }

        $this->actingAs($seller)
            ->patchJson(route('seller.products.pricing.update', $noActiveSku), [
                'regular_price' => '100.00',
                'discount_percent' => 10,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product');

        $this->assertSame(10000, (int) $variant->fresh()->price_minor);
    }

    public function test_empty_discount_input_is_saved_as_zero_percent(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'empty-discount');
        $product = $this->product($store, $category, 'Empty Discount Product', 'empty-discount-product');
        $variant = $this->variant($product, 'EMPTY-DISCOUNT-001', 'Standard', 8500, 2, true, 10000);

        $this->actingAs($seller)
            ->patchJson(route('seller.products.pricing.update', $product), [
                'regular_price' => '150.00',
                'discount_percent' => '',
            ])
            ->assertOk()
            ->assertJsonPath('data.discount_percent', 0)
            ->assertJsonPath('data.regular_price_minor', 15000)
            ->assertJsonPath('data.sale_price_minor', 15000);

        $this->assertSame(15000, (int) $variant->fresh()->price_minor);
        $this->assertNull($variant->fresh()->compare_at_price_minor);
    }

    public function test_archived_products_cannot_be_repriced_and_keep_their_saved_discount(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'archived-pricing');
        $product = $this->product($store, $category, 'Archived Price Product', 'archived-price-product', 'archived');
        $variant = $this->variant($product, 'ARCHIVED-PRICE-001', 'Standard', 8500, 4, true, 10000);

        $this->actingAs($seller)
            ->get(route('seller.products.pricing'))
            ->assertOk()
            ->assertSee('Restore to edit')
            ->assertDontSee('data-pricing-url="'.route('seller.products.pricing.update', $product).'"', false);

        $this->actingAs($seller)
            ->patchJson(route('seller.products.pricing.update', $product), [
                'regular_price' => '200.00',
                'discount_percent' => 10,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product');

        $this->assertSame(8500, (int) $variant->fresh()->price_minor);
        $this->assertSame(10000, (int) $variant->fresh()->compare_at_price_minor);
    }

    public function test_voucher_eligibility_is_persistent_and_mutations_are_store_scoped(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'voucher-owner');
        [$otherSeller, $otherStore] = $this->sellerAndStore($category, 'voucher-other');
        $product = $this->product($store, $category, 'Voucher Product', 'voucher-product');
        $this->variant($product, 'VOUCHER-OWN-001', 'Standard', 10000);
        $foreignProduct = $this->product($otherStore, $category, 'Foreign Voucher Product', 'foreign-voucher-product');
        $this->variant($foreignProduct, 'VOUCHER-FOREIGN-001', 'Standard', 10000);

        $this->actingAs($seller)
            ->patchJson(route('seller.products.pricing.voucher', $product), ['voucher_eligible' => true])
            ->assertOk()
            ->assertJsonPath('data.voucher_eligible', true);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'voucher_eligible' => true,
        ]);

        $this->actingAs($seller)
            ->patchJson(route('seller.products.pricing.update', $foreignProduct), [
                'regular_price' => '1.00',
                'discount_percent' => 0,
            ])
            ->assertNotFound();

        $this->actingAs($seller)
            ->patchJson(route('seller.products.pricing.voucher', $foreignProduct), ['voucher_eligible' => false])
            ->assertNotFound();

        $this->assertFalse((bool) $foreignProduct->fresh()->voucher_eligible);

        $archivedProduct = $this->product($store, $category, 'Archived Voucher Product', 'archived-voucher-product', 'archived');
        $this->variant($archivedProduct, 'VOUCHER-ARCHIVED-001', 'Standard', 10000);

        $this->actingAs($seller)
            ->patchJson(route('seller.products.pricing.voucher', $archivedProduct), ['voucher_eligible' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product');

        $this->actingAs($otherSeller)
            ->patchJson(route('seller.products.pricing.update', $product), [
                'regular_price' => '1.00',
                'discount_percent' => 0,
            ])
            ->assertNotFound();
    }

    private function category(): Category
    {
        return Category::create([
            'name' => 'Pricing Category',
            'slug' => 'pricing-category',
        ]);
    }

    /** @return array{0: User, 1: Store} */
    private function sellerAndStore(Category $category, string $slug): array
    {
        $sequence = ++$this->userSequence;
        $seller = User::create([
            'name' => 'Seller '.$sequence,
            'first_name' => 'Pricing',
            'last_name' => 'Seller '.$sequence,
            'email' => "{$slug}-{$sequence}@example.test",
            'role' => 'seller',
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
        $profile = SellerProfile::create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Pricing Business '.$sequence,
            'approved_category_id' => $category->id,
            'approved_at' => now(),
        ]);
        $store = Store::create([
            'seller_profile_id' => $profile->id,
            'name' => 'Pricing Store '.$sequence,
            'slug' => "{$slug}-{$sequence}",
            'publication_status' => 'draft',
        ]);

        return [$seller->fresh('sellerProfile'), $store];
    }

    private function product(Store $store, Category $category, string $name, string $slug, string $status = 'active'): Product
    {
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
        int $priceMinor,
        int $stock = 0,
        bool $active = true,
        ?int $compareAtPriceMinor = null,
    ): ProductVariant {
        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'name' => $name,
            'price_minor' => $priceMinor,
            'compare_at_price_minor' => $compareAtPriceMinor,
            'stock_on_hand' => $stock,
            'stock_reserved' => 0,
            'low_stock_threshold' => 5,
            'is_active' => $active,
        ]);
    }
}
