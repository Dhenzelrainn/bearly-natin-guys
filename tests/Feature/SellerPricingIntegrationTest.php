<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\StorePromotion;
use App\Models\User;
use App\Services\ProductPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
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

        $pricingResponse = $this->actingAs($seller)
            ->get(route('seller.products.pricing'))
            ->assertOk()
            ->assertSee('Persisted Pricing Product')
            ->assertDontSee('Private Foreign Product')
            ->assertDontSee('CLS-LINEN-SHIRT')
            ->assertDontSee('September Payday Sale')
            ->assertSee('No Store Vouchers yet.');
        preg_match('/<section class="pricing-summary-row"[^>]*>(.*?)<\/section>/s', $pricingResponse->getContent(), $summaryMatch);
        $this->assertArrayHasKey(1, $summaryMatch);
        $this->assertStringNotContainsString('Create promotion', $summaryMatch[1]);
        $this->assertStringNotContainsString('Create product discount', $summaryMatch[1]);
        $this->assertStringContainsString('Create product discount', $pricingResponse->getContent());
        $this->assertStringContainsString('Create store voucher', $pricingResponse->getContent());
        $this->assertStringContainsString(route('seller.promotions.product-discounts.create', ['product' => $product->id]), $pricingResponse->getContent());
        $this->assertFalse(Route::has('seller.products.pricing.update'));
        $this->assertFalse(Route::has('seller.products.pricing.voucher'));

        $this->actingAs($seller)
            ->get(route('seller.products'))
            ->assertOk()
            ->assertSee('Add Product');

        $this->actingAs($seller)
            ->get(route('seller.promotions.store-vouchers.create'))
            ->assertOk()
            ->assertSee('Store Vouchers are funded by your Store.')
            ->assertSee('Public claimable vouchers appear in My Vouchers; code-only vouchers can be applied at checkout.')
            ->assertSee('Save Promotion')
            ->assertSee(route('seller.promotions.store-vouchers.store'), false)
            ->assertSee('value="store_voucher"', false)
            ->assertSee('value="Store Voucher" readonly', false)
            ->assertDontSee('<option value="product_discount"', false)
            ->assertSee('manage scheduled Product Discounts on the Product Discounts tab in Pricing & Promotions.')
            ->assertSee('Persisted Pricing Product')
            ->assertDontSee('Private Foreign Product')
            ->assertDontSee('CLS-LINEN-SHIRT');
    }

    public function test_product_edit_does_not_expose_discount_and_preserves_it_when_regular_price_changes(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'product-discount-owner');
        $product = $this->product($store, $category, 'Discount Preservation Product', 'discount-preservation-product', 'draft');
        $product->update(['voucher_eligible' => true]);
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
            ->assertSee('Existing compare-at pricing is preserved.')
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
        $this->assertTrue((bool) $product->fresh()->voucher_eligible);
    }

    public function test_campaign_creation_persists_only_store_voucher_rules_and_is_store_scoped(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'promotion-owner');
        [, $otherStore] = $this->sellerAndStore($category, 'promotion-other');
        $voucherProduct = $this->product($store, $category, 'Campaign Voucher Product', 'campaign-voucher-product');
        $voucherProduct->update(['voucher_eligible' => true]);
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
            ->from(route('seller.promotions.store-vouchers.create'))
            ->post(route('seller.promotions.store-vouchers.store'), [
                'name' => 'Seller Payday Voucher',
                'type' => 'store_voucher',
                'discount_type' => 'fixed',
                'value' => '50.25',
                'start_date' => today()->addDays(3)->toDateString(),
                'end_date' => today()->addDays(5)->toDateString(),
                'products' => [$voucherProduct->id],
                'minimum_spend' => '250.75',
                'redemption_limit' => '500',
                'buyer_limit' => '2',
            ])
            ->assertRedirect(route('seller.products.pricing', ['tab' => 'store-vouchers']))
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
        $this->assertTrue((bool) $voucherPromotion->is_public);
        $this->assertTrue((bool) $voucherPromotion->is_claimable);
        $this->assertDatabaseHas('store_promotion_products', [
            'store_promotion_id' => $voucherPromotion->id,
            'product_id' => $voucherProduct->id,
        ]);
        $this->assertSame(8000, (int) $voucherVariant->fresh()->price_minor);

        $voucherResponse = $this->actingAs($seller)
            ->get(route('seller.products.pricing', ['tab' => 'store-vouchers']))
            ->assertOk();
        preg_match(
            '/<section class="pricing-panel-surface pricing-secondary-panel" data-pricing-panel="store-vouchers"[^>]*>(.*?)<section class="pricing-panel-surface pricing-secondary-panel" data-pricing-panel="campaigns"/s',
            $voucherResponse->getContent(),
            $voucherPanel,
        );
        $this->assertArrayHasKey(1, $voucherPanel);
        $this->assertStringContainsString('Seller Payday Voucher', $voucherPanel[1]);
        $this->assertStringNotContainsString('Legacy Product Discount', $voucherPanel[1]);
        $this->assertStringNotContainsString('15% off', $voucherPanel[1]);
        $this->assertStringContainsString('₱50.25 off', $voucherPanel[1]);
        $this->assertStringContainsString('₱250.75', $voucherPanel[1]);
        $this->assertStringContainsString('<td><span class="pricing-schedule">', $voucherPanel[1]);
        $this->assertStringNotContainsString('<td class="pricing-schedule">', $voucherPanel[1]);
        $this->assertStringContainsString('Scheduled', $voucherPanel[1]);
        $this->assertStringNotContainsString('Foreign Campaign Product', $voucherPanel[1]);
        $this->assertStringNotContainsString('Foreign Store Campaign', $voucherPanel[1]);

        $discountResponse = $this->actingAs($seller)
            ->get(route('seller.products.pricing', ['tab' => 'product-discounts']))
            ->assertOk();
        preg_match(
            '/<section class="pricing-panel-surface" data-pricing-panel="product-discounts"[^>]*>(.*?)<section class="pricing-panel-surface pricing-secondary-panel" data-pricing-panel="store-vouchers"/s',
            $discountResponse->getContent(),
            $discountPanel,
        );
        $this->assertArrayHasKey(1, $discountPanel);
        $this->assertStringContainsString('Legacy Product Discount', $discountPanel[1]);
        $this->assertStringContainsString('15% off', $discountPanel[1]);
        $this->assertStringNotContainsString('Seller Payday Voucher', $discountPanel[1]);
    }

    public function test_private_store_voucher_requires_code_and_is_not_claimable(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'private-store-voucher');
        $product = $this->product($store, $category, 'Private Store Voucher Product', 'private-store-voucher-product');
        $product->update(['voucher_eligible' => true]);
        $this->variant($product, 'PRIVATE-STORE-VOUCHER-001', 'Standard', 10000);
        $payload = [
            'name' => 'Private Code Voucher',
            'type' => StorePromotion::TYPE_STORE_VOUCHER,
            'code' => 'PRIVATE-SHOP-20',
            'discount_type' => StorePromotion::DISCOUNT_FIXED,
            'value' => '20.00',
            'start_date' => today()->toDateString(),
            'end_date' => today()->addDays(3)->toDateString(),
            'products' => [$product->id],
            'buyer_limit' => '1',
            'is_public' => '0',
            'is_claimable' => '0',
        ];

        $this->actingAs($seller)
            ->post(route('seller.promotions.store-vouchers.store'), $payload)
            ->assertRedirect(route('seller.products.pricing', ['tab' => 'store-vouchers']));

        $voucher = StorePromotion::query()->where('code', 'PRIVATE-SHOP-20')->firstOrFail();
        $this->assertFalse((bool) $voucher->is_public);
        $this->assertFalse((bool) $voucher->is_claimable);

        $this->actingAs($seller)
            ->post(route('seller.promotions.store-vouchers.store'), [
                ...$payload,
                'name' => 'Private Voucher Without Code',
                'code' => '',
            ])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, StorePromotion::query()->where('store_id', $store->id)->count());
    }

    public function test_store_voucher_name_is_generated_and_schedule_times_are_saved(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'generated-store-voucher');
        $product = $this->product($store, $category, 'Generated Voucher Product', 'generated-voucher-product');
        $product->update(['voucher_eligible' => true]);
        $this->variant($product, 'GENERATED-VOUCHER-001', 'Standard', 10000);
        $startDate = now('Asia/Manila')->addDay()->toDateString();
        $endDate = now('Asia/Manila')->addDays(2)->toDateString();

        $this->actingAs($seller)
            ->post(route('seller.promotions.store-vouchers.store'), [
                'type' => StorePromotion::TYPE_STORE_VOUCHER,
                'discount_type' => StorePromotion::DISCOUNT_FIXED,
                'value' => '15.50',
                'start_date' => $startDate,
                'start_time' => '09:15',
                'end_date' => $endDate,
                'end_time' => '18:45',
                'products' => [$product->id],
                'buyer_limit' => '1',
            ])
            ->assertRedirect(route('seller.products.pricing', ['tab' => 'store-vouchers']));

        $voucher = StorePromotion::query()->where('store_id', $store->id)->firstOrFail();
        $this->assertSame('₱15.50 off voucher', $voucher->name);
        $this->assertSame('09:15:00', $voucher->starts_time);
        $this->assertSame('18:45:59', $voucher->ends_time);
        $this->assertSame($startDate, $voucher->starts_on->toDateString());
        $this->assertSame($endDate, $voucher->ends_on->toDateString());

        $this->actingAs($seller)
            ->from(route('seller.promotions.store-vouchers.create'))
            ->post(route('seller.promotions.store-vouchers.store'), [
                'type' => StorePromotion::TYPE_STORE_VOUCHER,
                'discount_type' => StorePromotion::DISCOUNT_FIXED,
                'value' => '5.00',
                'start_date' => $startDate,
                'start_time' => '14:00',
                'end_date' => $startDate,
                'end_time' => '13:59',
                'products' => [$product->id],
                'buyer_limit' => '1',
            ])
            ->assertRedirect(route('seller.promotions.store-vouchers.create'))
            ->assertSessionHasErrors('end_time');

        $this->assertSame(1, StorePromotion::query()->where('store_id', $store->id)->count());
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
            'discount_type' => 'fixed',
            'value' => '0.00',
            'start_date' => today()->toDateString(),
            'end_date' => today()->addDay()->toDateString(),
            'products' => [$product->id],
            'buyer_limit' => '1',
        ];

        $this->actingAs($seller)
            ->from(route('seller.promotions.store-vouchers.create'))
            ->post(route('seller.promotions.store-vouchers.store'), $basePayload)
            ->assertRedirect(route('seller.promotions.store-vouchers.create'))
            ->assertSessionHasErrors('value');

        $this->actingAs($seller)
            ->from(route('seller.promotions.store-vouchers.create'))
            ->post(route('seller.promotions.store-vouchers.store'), [
                ...$basePayload,
                'value' => '10.00',
                'products' => [$foreignProduct->id],
            ])
            ->assertRedirect(route('seller.promotions.store-vouchers.create'))
            ->assertSessionHasErrors('products');

        $this->actingAs($seller)
            ->from(route('seller.promotions.store-vouchers.create'))
            ->post(route('seller.promotions.store-vouchers.store'), [
                ...$basePayload,
                'type' => 'product_discount',
                'value' => '10.00',
                'products' => [$product->id],
            ])
            ->assertRedirect(route('seller.promotions.store-vouchers.create'))
            ->assertSessionHasErrors('type');

        $this->assertSame(0, StorePromotion::query()->count());
        $this->assertSame((int) $store->id, (int) $product->fresh()->store_id);
    }

    public function test_active_product_discount_replaces_regular_discount_and_prevents_overlapping_offers(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'discount-replacement');
        [$otherSeller] = $this->sellerAndStore($category, 'discount-replacement-other');
        $product = $this->product($store, $category, 'Discount Replacement Product', 'discount-replacement-product');
        $variant = $this->variant($product, 'DISCOUNT-REPLACE-001', 'Standard', 8000, 5, true, 10000);
        $today = now('Asia/Manila')->toDateString();
        $end = now('Asia/Manila')->addDays(3)->toDateString();

        $this->actingAs($seller)
            ->post(route('seller.promotions.product-discounts.store'), [
                'name' => 'Scheduled price reduction',
                'buyer_campaign_name' => 'MacBook Drop Sale',
                'discount_percent' => 10,
                'start_date' => $today,
                'end_date' => $end,
                'products' => [$product->id],
            ])
            ->assertRedirect(route('seller.products.pricing', ['tab' => 'product-discounts']));

        $promotion = StorePromotion::query()->where('name', 'Scheduled price reduction')->firstOrFail();
        $this->assertSame('MacBook Drop Sale', $promotion->buyer_campaign_name);
        $resolved = app(ProductPricingService::class)->priceForVariant($product->fresh(), $variant->fresh());
        $this->assertSame(9000, $resolved['seller_unit_price_minor']);
        $this->assertSame('product_discount', $resolved['price_source']);
        $this->assertSame((int) $promotion->id, $resolved['product_discount_id']);

        $this->actingAs($seller)
            ->post(route('seller.promotions.product-discounts.store'), [
                'name' => 'Overlapping discount',
                'discount_percent' => 20,
                'start_date' => $today,
                'end_date' => $end,
                'products' => [$product->id],
            ])
            ->assertSessionHasErrors('products');

        $this->assertSame(1, StorePromotion::query()->where('type', StorePromotion::TYPE_PRODUCT_DISCOUNT)->count());

        $this->actingAs($otherSeller)
            ->patch(route('seller.promotions.product-discounts.pause', $promotion))
            ->assertNotFound();
    }

    public function test_product_discount_overlap_checks_include_start_and_end_times(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'discount-time-overlap');
        $product = $this->product($store, $category, 'Time Window Product', 'discount-time-overlap-product');
        $this->variant($product, 'DISCOUNT-TIME-OVERLAP-001', 'Standard', 10000);
        $date = now('Asia/Manila')->addDay()->toDateString();
        $base = [
            'discount_percent' => '10',
            'start_date' => $date,
            'end_date' => $date,
            'products' => [$product->id],
        ];

        $this->actingAs($seller)
            ->post(route('seller.promotions.product-discounts.store'), [
                ...$base,
                'name' => 'Morning deal',
                'start_time' => '09:00',
                'end_time' => '10:00',
            ])
            ->assertRedirect(route('seller.products.pricing', ['tab' => 'product-discounts']));

        $this->actingAs($seller)
            ->post(route('seller.promotions.product-discounts.store'), [
                ...$base,
                'name' => 'Late morning deal',
                'start_time' => '10:01',
                'end_time' => '11:00',
            ])
            ->assertRedirect(route('seller.products.pricing', ['tab' => 'product-discounts']));

        $this->actingAs($seller)
            ->post(route('seller.promotions.product-discounts.store'), [
                ...$base,
                'name' => 'Overlapping deal',
                'start_time' => '10:00',
                'end_time' => '10:30',
            ])
            ->assertSessionHasErrors('products');

        $this->assertSame(2, StorePromotion::query()
            ->where('store_id', $store->id)
            ->where('type', StorePromotion::TYPE_PRODUCT_DISCOUNT)
            ->count());
    }

    public function test_product_management_edits_preserve_distinct_variant_prices_and_legacy_sales(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'variant-price-preservation');
        $product = $this->product($store, $category, 'Variant Price Product', 'variant-price-product', 'draft');
        $product->update(['voucher_eligible' => true]);
        $small = $this->variant($product, 'VARIANT-PRICE-SMALL', 'Small', 8500, 3, true, 10000);
        $large = $this->variant($product, 'VARIANT-PRICE-LARGE', 'Large', 24000, 4, true, 30000);
        $payload = [
            'product_mode' => 'with_variants',
            'name' => $product->name,
            'category' => $category->name,
            'stock' => 7,
            'option_one_name' => 'Size',
            'option_one_values' => 'Small, Large',
            'option_two_name' => '',
            'option_two_values' => '',
            'payment_methods' => ['cod'],
            'variants' => [
                [
                    'label' => 'Small',
                    'sku' => $small->sku,
                    'price' => '100.00',
                    'stock' => '3',
                    'low_stock_threshold' => '5',
                    'package_weight_kg' => '0.250',
                    'package_length_cm' => '24',
                    'package_width_cm' => '18',
                    'package_height_cm' => '8',
                ],
                [
                    'label' => 'Large',
                    'sku' => $large->sku,
                    'price' => '300.00',
                    'stock' => '4',
                    'low_stock_threshold' => '5',
                    'package_weight_kg' => '0.350',
                    'package_length_cm' => '30',
                    'package_width_cm' => '20',
                    'package_height_cm' => '9',
                ],
            ],
            'intent' => 'draft',
        ];

        $editResponse = $this->actingAs($seller)
            ->get(route('seller.products.edit', $product))
            ->assertOk()
            ->assertSee('Regular price is set per SKU')
            ->assertDontSee('name="discount_percent"', false);
        $this->assertSame(1, preg_match(
            '/<input\b(?=[^>]*data-product-price)(?=[^>]*\bdisabled\b)[^>]*>/s',
            $editResponse->getContent(),
        ));

        $invalidPayload = $payload;
        $invalidPayload['variants'][1]['price'] = '';
        $this->actingAs($seller)
            ->from(route('seller.products.edit', $product))
            ->put(route('seller.products.update', $product), $invalidPayload)
            ->assertRedirect(route('seller.products.edit', $product))
            ->assertSessionHasErrors('variants.1.price');

        $this->actingAs($seller)
            ->put(route('seller.products.update', $product), $payload)
            ->assertRedirect(route('seller.products'));

        $this->assertSame(8500, (int) $small->fresh()->price_minor);
        $this->assertSame(10000, (int) $small->fresh()->compare_at_price_minor);
        $this->assertSame(24000, (int) $large->fresh()->price_minor);
        $this->assertSame(30000, (int) $large->fresh()->compare_at_price_minor);
        $this->assertTrue((bool) $product->fresh()->voucher_eligible);

        $this->actingAs($seller)
            ->put(route('seller.products.update', $product), [
                ...$payload,
                'variants' => [
                    [...$payload['variants'][0], 'price' => '120.00'],
                    $payload['variants'][1],
                ],
            ])
            ->assertRedirect(route('seller.products'));

        $this->assertSame(10200, (int) $small->fresh()->price_minor);
        $this->assertSame(12000, (int) $small->fresh()->compare_at_price_minor);
        $this->assertSame(24000, (int) $large->fresh()->price_minor);
        $this->assertSame(30000, (int) $large->fresh()->compare_at_price_minor);
        $this->assertTrue((bool) $product->fresh()->voucher_eligible);
    }

    public function test_scheduled_product_discount_replaces_legacy_sale_without_stacking(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'legacy-sale-replacement');
        $product = $this->product($store, $category, 'Legacy Sale Product', 'legacy-sale-product', 'active');
        $variant = $this->variant($product, 'LEGACY-SALE-001', 'Standard', 8500, 5, true, 10000);
        $today = now('Asia/Manila')->toDateString();
        $tomorrow = now('Asia/Manila')->addDay()->toDateString();

        $this->actingAs($seller)
            ->post(route('seller.promotions.product-discounts.store'), [
                'name' => 'Scheduled replacement',
                'discount_percent' => 10,
                'start_date' => $today,
                'end_date' => $tomorrow,
                'products' => [$product->id],
            ])
            ->assertRedirect(route('seller.products.pricing', ['tab' => 'product-discounts']));

        $promotion = StorePromotion::query()->where('name', 'Scheduled replacement')->firstOrFail();
        $activePrice = app(ProductPricingService::class)->priceForVariant($product->fresh(), $variant->fresh());
        $this->assertSame(9000, $activePrice['unit_price_minor']);
        $this->assertNotSame(7650, $activePrice['unit_price_minor']);

        $promotion->update([
            'ends_on' => now('Asia/Manila')->subDay()->toDateString(),
            'ends_time' => '23:59:59',
        ]);
        $afterPromotion = app(ProductPricingService::class)->priceForVariant($product->fresh(), $variant->fresh());
        $this->assertSame(8500, $afterPromotion['unit_price_minor']);
        $this->assertSame(10000, (int) $variant->fresh()->compare_at_price_minor);
    }

    public function test_store_voucher_picker_and_service_reject_global_ineligible_or_foreign_products(): void
    {
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'voucher-owner');
        [, $otherStore] = $this->sellerAndStore($category, 'voucher-other');
        $eligibleProduct = $this->product($store, $category, 'Eligible Voucher Product', 'eligible-voucher-product');
        $eligibleProduct->update(['voucher_eligible' => true]);
        $this->variant($eligibleProduct, 'VOUCHER-OWN-001', 'Standard', 10000);
        $ineligibleProduct = $this->product($store, $category, 'Ineligible Voucher Product', 'ineligible-voucher-product');
        $this->variant($ineligibleProduct, 'VOUCHER-INELIGIBLE-001', 'Standard', 9000);
        $unavailableProduct = $this->product($store, $category, 'Draft Voucher Product', 'draft-voucher-product', 'draft');
        $unavailableProduct->update(['voucher_eligible' => true]);
        $this->variant($unavailableProduct, 'VOUCHER-DRAFT-001', 'Standard', 8500);
        $foreignProduct = $this->product($otherStore, $category, 'Foreign Voucher Product', 'foreign-voucher-product');
        $foreignProduct->update(['voucher_eligible' => true]);
        $this->variant($foreignProduct, 'VOUCHER-FOREIGN-001', 'Standard', 10000);

        $this->actingAs($seller)
            ->get(route('seller.promotions.store-vouchers.create'))
            ->assertOk()
            ->assertSee('Not eligible for Store Vouchers')
            ->assertDontSee('Draft Voucher Product')
            ->assertSee('disabled data-promotion-product', false);

        $payload = [
            'type' => StorePromotion::TYPE_STORE_VOUCHER,
            'discount_type' => StorePromotion::DISCOUNT_FIXED,
            'value' => '10.00',
            'start_date' => today()->toDateString(),
            'end_date' => today()->addDay()->toDateString(),
            'buyer_limit' => '1',
        ];

        $this->actingAs($seller)
            ->from(route('seller.promotions.store-vouchers.create'))
            ->post(route('seller.promotions.store-vouchers.store'), [
                ...$payload,
                'products' => [$ineligibleProduct->id],
            ])
            ->assertRedirect(route('seller.promotions.store-vouchers.create'))
            ->assertSessionHasErrors('products');

        $this->actingAs($seller)
            ->from(route('seller.promotions.store-vouchers.create'))
            ->post(route('seller.promotions.store-vouchers.store'), [
                ...$payload,
                'products' => [$unavailableProduct->id],
            ])
            ->assertRedirect(route('seller.promotions.store-vouchers.create'))
            ->assertSessionHasErrors('products');

        $this->actingAs($seller)
            ->from(route('seller.promotions.store-vouchers.create'))
            ->post(route('seller.promotions.store-vouchers.store'), [
                ...$payload,
                'products' => [$foreignProduct->id],
            ])
            ->assertRedirect(route('seller.promotions.store-vouchers.create'))
            ->assertSessionHasErrors('products');

        $this->actingAs($seller)
            ->post(route('seller.promotions.store-vouchers.store'), [
                ...$payload,
                'products' => [$eligibleProduct->id],
            ])
            ->assertRedirect(route('seller.products.pricing', ['tab' => 'store-vouchers']));

        $voucher = StorePromotion::query()->where('store_id', $store->id)->firstOrFail();
        $this->assertDatabaseHas('store_promotion_products', [
            'store_promotion_id' => $voucher->id,
            'product_id' => $eligibleProduct->id,
        ]);
        $this->assertSame(1, $voucher->products()->count());

        $eligibleProduct->update(['voucher_eligible' => false]);
        $this->actingAs($seller)
            ->get(route('seller.promotions.store-vouchers.create'))
            ->assertOk()
            ->assertSee('data-promotion-select-all disabled', false)
            ->assertSee('form="promotion-create-form" disabled', false);
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
