<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\PlatformPromotion;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\StorePromotion;
use App\Models\User;
use App\Services\PromotionService;
use App\Services\PostalCodeLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileBuyerCheckoutQuoteApiTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_buyer_receives_a_server_priced_curated_quote_without_business_record_changes(): void
    {
        $buyer = $this->user('buyer');
        $otherBuyer = $this->user('buyer');
        $destination = $this->address($buyer);
        $fixture = $this->productFixture('Quote Item', 1250);
        $cartItem = $this->cartItem($buyer, $fixture['variant'], 2);
        $token = $this->token($buyer);
        $tables = [
            'orders',
            'seller_orders',
            'order_items',
            'payments',
            'inventory_movements',
            'shipments',
            'promotion_claims',
            'promotion_redemptions',
            'cart_items',
        ];
        $beforeCounts = collect($tables)->mapWithKeys(fn (string $table): array => [$table => \DB::table($table)->count()]);
        $stockBefore = $fixture['variant']->fresh()->only(['stock_on_hand', 'stock_reserved']);

        $response = $this->withToken($token)
            ->postJson('/api/mobile/buyer/checkout/quote', [
                'buyer_id' => $otherBuyer->id,
                'address_id' => $destination->id,
                'cart_item_ids' => [$cartItem->id],
                'payment_method' => 'cod',
                'unit_price_minor' => 1,
                'subtotal_minor' => 1,
                'total_minor' => 1,
            ])->assertOk()
            ->assertJsonPath('data.currency', 'PHP')
            ->assertJsonPath('data.items.0.cart_item_id', $cartItem->id)
            ->assertJsonPath('data.items.0.product_name', 'Quote Item')
            ->assertJsonPath('data.items.0.variant_name', 'Default option')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unit_price_minor', 1250)
            ->assertJsonPath('data.items.0.subtotal_minor', 2500)
            ->assertJsonPath('data.items.0.total_minor', 2500)
            ->assertJsonPath('data.seller_orders.0.subtotal_minor', 2500)
            ->assertJsonPath('data.seller_orders.0.shipping_fee_minor', 5000)
            ->assertJsonPath('data.seller_orders.0.total_minor', 7500)
            ->assertJsonPath('data.subtotal_minor', 2500)
            ->assertJsonPath('data.total_minor', 7500)
            ->assertJsonPath('data.payment_method', 'cod')
            ->assertJsonPath('data.available_payment_methods.0', 'cod')
            ->assertJsonPath('data.delivery_address.name', $buyer->name)
            ->assertJsonPath('data.delivery_address.id', $destination->id)
            ->assertJsonPath('message', 'Checkout quote generated.')
            ->assertJsonMissingPath('data.items.0.sku')
            ->assertJsonMissingPath('data.items.0.seller_unit_price_minor')
            ->assertJsonMissingPath('data.items.0.campaign_funding')
            ->assertJsonMissingPath('data.seller_orders.0.quote')
            ->assertJsonMissingPath('data.buyer_id')
            ->assertJsonMissingPath('data.seller_subtotal_minor')
            ->assertJsonMissing(['legal_business_name' => 'Private Legal Seller Name']);

        $this->assertNotEmpty($response->json('data.quote_token'));
        $this->assertNotEmpty($response->json('data.quote_expires_at'));

        $this->withToken($token)->postJson('/api/mobile/buyer/checkout/quote', [
            'address_id' => $destination->id,
            'cart_item_ids' => [$cartItem->id],
        ])->assertOk();

        foreach ($beforeCounts as $table => $count) {
            $this->assertSame($count, \DB::table($table)->count(), "The quote changed {$table}.");
        }
        $this->assertSame($stockBefore, $fixture['variant']->fresh()->only(['stock_on_hand', 'stock_reserved']));
    }

    public function test_quote_uses_the_existing_promotion_calculation_and_returns_safe_discount_summary(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer);
        $fixture = $this->productFixture('Voucher Item', 10000);
        $cartItem = $this->cartItem($buyer, $fixture['variant'], 1);
        PlatformPromotion::query()->create([
            'name' => 'Bearly Quote ₱5 Off',
            'code' => 'QUOTE5',
            'kind' => PlatformPromotion::KIND_VOUCHER,
            'funding_type' => PlatformPromotion::FUNDING_PLATFORM,
            'discount_type' => PlatformPromotion::DISCOUNT_FIXED,
            'status' => PlatformPromotion::STATUS_ACTIVE,
            'value_minor' => 500,
            'minimum_spend_minor' => 0,
            'starts_on' => now('Asia/Manila')->toDateString(),
            'ends_on' => now('Asia/Manila')->addDays(5)->toDateString(),
            'per_buyer_limit' => 1,
            'is_claimable' => false,
            'is_public' => true,
        ]);

        $response = $this->withToken($this->token($buyer))
            ->postJson('/api/mobile/buyer/checkout/quote', [
                'address_id' => $destination->id,
                'cart_item_ids' => [$cartItem->id],
                'voucher_code' => 'quote5',
            ])->assertOk()
            ->assertJsonPath('data.subtotal_minor', 10000)
            ->assertJsonPath('data.platform_discount_minor', 500)
            ->assertJsonPath('data.discount_minor', 500)
            ->assertJsonPath('data.total_minor', 14500)
            ->assertJsonPath('data.promotion_selection.voucher_code', 'QUOTE5')
            ->assertJsonPath('data.applied_promotions.0.name', 'Bearly Quote ₱5 Off')
            ->assertJsonPath('data.applied_promotions.0.discount_minor', 500)
            ->assertJsonMissingPath('data.applied_promotions.0.funding_type')
            ->assertJsonMissingPath('data.promotions');

        $this->assertNotEmpty($response->json('data.quote_token'));
        $this->assertDatabaseCount('promotion_claims', 0);
        $this->assertDatabaseCount('promotion_redemptions', 0);
    }

    public function test_mobile_quote_accepts_claimed_platform_voucher_with_store_promo_code(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer);
        $fixture = $this->productFixture('Stacked Mobile Voucher Item', 10000);
        $cartItem = $this->cartItem($buyer, $fixture['variant'], 1);
        $platformVoucher = PlatformPromotion::query()->create([
            'name' => 'Claimable Mobile Bearly voucher',
            'code' => null,
            'kind' => PlatformPromotion::KIND_VOUCHER,
            'funding_type' => PlatformPromotion::FUNDING_PLATFORM,
            'discount_type' => PlatformPromotion::DISCOUNT_FIXED,
            'status' => PlatformPromotion::STATUS_ACTIVE,
            'value_minor' => 500,
            'minimum_spend_minor' => 0,
            'starts_on' => now('Asia/Manila')->toDateString(),
            'ends_on' => now('Asia/Manila')->addDays(5)->toDateString(),
            'per_buyer_limit' => 1,
            'is_claimable' => true,
            'is_public' => true,
        ]);
        $storeCode = StorePromotion::query()->create([
            'store_id' => $fixture['store']->id,
            'name' => 'Mobile Store promo code',
            'code' => 'MOBILE-STORE-600',
            'type' => StorePromotion::TYPE_STORE_VOUCHER,
            'status' => StorePromotion::STATUS_ACTIVE,
            'funding_type' => StorePromotion::FUNDING_SELLER,
            'discount_type' => StorePromotion::DISCOUNT_FIXED,
            'discount_percent' => null,
            'voucher_amount_minor' => 600,
            'minimum_spend_minor' => 0,
            'starts_on' => now('Asia/Manila')->toDateString(),
            'ends_on' => now('Asia/Manila')->addDays(5)->toDateString(),
            'per_buyer_limit' => 1,
            'is_claimable' => false,
            'is_public' => true,
        ]);
        $storeCode->products()->attach($fixture['product']->id);
        app(PromotionService::class)->claim($buyer, ['platform_promotion_id' => $platformVoucher->id]);

        $response = $this->withToken($this->token($buyer))
            ->postJson('/api/mobile/buyer/checkout/quote', [
                'address_id' => $destination->id,
                'cart_item_ids' => [$cartItem->id],
                'platform_voucher_id' => $platformVoucher->id,
                'voucher_code' => $storeCode->code,
                'payment_method' => 'cod',
            ])->assertOk()
            ->assertJsonPath('data.subtotal_minor', 10000)
            ->assertJsonPath('data.seller_discount_minor', 600)
            ->assertJsonPath('data.platform_discount_minor', 500)
            ->assertJsonPath('data.total_minor', 13900)
            ->assertJsonPath('data.promotion_selection.platform_voucher_id', $platformVoucher->id)
            ->assertJsonPath('data.promotion_selection.voucher_code', 'MOBILE-STORE-600')
            ->assertJsonCount(2, 'data.applied_promotions');

        $this->assertSame(['platform', 'store'], collect($response->json('data.applied_promotions'))
            ->pluck('source')
            ->sort()
            ->values()
            ->all());
        $this->assertDatabaseCount('promotion_claims', 1);
        $this->assertDatabaseCount('promotion_redemptions', 0);
    }

    public function test_quote_rejects_foreign_cart_items_foreign_or_deleted_addresses_and_invalid_selections(): void
    {
        $buyer = $this->user('buyer');
        $otherBuyer = $this->user('buyer');
        $destination = $this->address($buyer);
        $foreignAddress = $this->address($otherBuyer);
        $deletedAddress = $this->address($buyer);
        $deletedAddress->delete();
        $fixture = $this->productFixture('Owned Item', 1000);
        $ownItem = $this->cartItem($buyer, $fixture['variant'], 1);
        $foreignItem = $this->cartItem($otherBuyer, $fixture['variant'], 1);
        $token = $this->token($buyer);

        $this->withToken($token)->postJson('/api/mobile/buyer/checkout/quote', [
            'address_id' => $destination->id,
            'cart_item_ids' => [$foreignItem->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('cart_item_ids');

        $this->withToken($token)->postJson('/api/mobile/buyer/checkout/quote', [
            'address_id' => $foreignAddress->id,
            'cart_item_ids' => [$ownItem->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('address_id');

        $this->withToken($token)->postJson('/api/mobile/buyer/checkout/quote', [
            'address_id' => $deletedAddress->id,
            'cart_item_ids' => [$ownItem->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('address_id');

        $this->withToken($token)->postJson('/api/mobile/buyer/checkout/quote', [
            'address_id' => $destination->id,
            'cart_item_ids' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('cart_item_ids');

        $this->withToken($token)->postJson('/api/mobile/buyer/checkout/quote', [
            'address_id' => $destination->id,
            'cart_item_ids' => [$ownItem->id, $ownItem->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('cart_item_ids.1');

        $ownItem->update(['quantity' => 5]);
        $this->withToken($token)->postJson('/api/mobile/buyer/checkout/quote', [
            'address_id' => $destination->id,
            'cart_item_ids' => [$ownItem->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('cart_item_ids');
    }

    public function test_quote_requires_authentication(): void
    {
        $this->postJson('/api/mobile/buyer/checkout/quote', [])->assertUnauthorized();
    }

    public function test_quote_denies_rider_accounts(): void
    {
        $rider = $this->user('rider');

        $this->withToken($this->token($rider))
            ->postJson('/api/mobile/buyer/checkout/quote', [])->assertForbidden();
    }

    public function test_quote_denies_non_mobile_seller_accounts(): void
    {
        $seller = $this->user('seller');

        $this->withToken($this->token($seller))
            ->postJson('/api/mobile/buyer/checkout/quote', [])->assertForbidden();
    }

    public function test_quote_denies_inactive_buyer_accounts(): void
    {
        $inactiveBuyer = $this->user('buyer', 'suspended');

        $this->withToken($this->token($inactiveBuyer))
            ->postJson('/api/mobile/buyer/checkout/quote', [])->assertForbidden();
    }

    public function test_quote_rejects_unsupported_payment_methods_and_items_without_cod(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer);
        $fixture = $this->productFixture('COD Item', 1000);
        $cartItem = $this->cartItem($buyer, $fixture['variant'], 1);
        $token = $this->token($buyer);

        $this->withToken($token)->postJson('/api/mobile/buyer/checkout/quote', [
            'address_id' => $destination->id,
            'cart_item_ids' => [$cartItem->id],
            'payment_method' => 'online',
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_method');

        $fixture['product']->update(['payment_methods' => ['online']]);
        $this->withToken($token)->postJson('/api/mobile/buyer/checkout/quote', [
            'address_id' => $destination->id,
            'cart_item_ids' => [$cartItem->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
    }

    public function test_quote_groups_items_from_multiple_sellers_without_exposing_shipping_internals(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer);
        $first = $this->productFixture('First Seller Item', 1500);
        $second = $this->productFixture('Second Seller Item', 2500);
        $firstItem = $this->cartItem($buyer, $first['variant'], 2);
        $secondItem = $this->cartItem($buyer, $second['variant'], 1);

        $response = $this->withToken($this->token($buyer))
            ->postJson('/api/mobile/buyer/checkout/quote', [
                'address_id' => $destination->id,
                'cart_item_ids' => [$secondItem->id, $firstItem->id],
            ])->assertOk()
            ->assertJsonCount(2, 'data.seller_orders')
            ->assertJsonPath('data.subtotal_minor', 5500)
            ->assertJsonPath('data.shipping_fee_minor', 10000)
            ->assertJsonPath('data.total_minor', 15500)
            ->assertJsonMissingPath('data.seller_orders.0.quote')
            ->assertJsonMissingPath('data.seller_orders.1.quote');

        $sellerOrderIds = collect($response->json('data.seller_orders'))
            ->pluck('store_id')
            ->sort()
            ->values()
            ->all();
        $this->assertSame(
            collect([$first['store']->id, $second['store']->id])->sort()->values()->all(),
            $sellerOrderIds,
        );
    }

    private function user(string $role, string $status = 'active'): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => $status,
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('mobile-checkout-quote-test')->plainTextToken;
    }

    private function address(User $user): Address
    {
        return Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Home',
            'recipient_name' => $user->name,
            'phone' => '+639171234567',
            'street' => '10 Market Street',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
            'psgc_province_code' => '0434',
            'psgc_city_code' => app(PostalCodeLookup::class)
                ->canonicalCityCode(null, 'Laguna', 'Santa Rosa'),
            'is_default_shipping' => true,
        ]);
    }

    /** @return array{product: Product, variant: ProductVariant, store: Store} */
    private function productFixture(string $name, int $priceMinor): array
    {
        $sequence = ++$this->sequence;
        $category = Category::query()->create([
            'name' => "Quote Category {$sequence}",
            'slug' => "quote-category-{$sequence}",
        ]);
        $seller = $this->user('seller');
        $pickup = $this->address($seller);
        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Private Legal Seller Name',
            'approved_category_id' => $category->id,
            'pickup_address_id' => $pickup->id,
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => "Public Store {$sequence}",
            'slug' => "public-store-{$sequence}",
            'publication_status' => 'published',
        ]);
        $product = Product::query()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name)."-{$sequence}",
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'voucher_eligible' => true,
            'payment_methods' => ['cod', 'online'],
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => "PRIVATE-QUOTE-SKU-{$sequence}",
            'name' => 'Default option',
            'options' => ['Size' => 'Regular'],
            'price_minor' => $priceMinor,
            'stock_on_hand' => 5,
            'stock_reserved' => 1,
            'package_weight_kg' => 0.25,
            'package_length_cm' => 10,
            'package_width_cm' => 10,
            'package_height_cm' => 10,
            'is_active' => true,
        ]);

        return compact('product', 'variant', 'store');
    }

    private function cartItem(User $buyer, ProductVariant $variant, int $quantity): CartItem
    {
        $cart = Cart::query()->firstOrCreate([
            'user_id' => $buyer->id,
            'status' => 'active',
        ]);

        return CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
            'selected' => true,
        ]);
    }
}
