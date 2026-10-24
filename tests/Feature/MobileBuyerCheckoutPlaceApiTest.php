<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\PlatformPromotion;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PromotionRedemption;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use App\Services\PostalCodeLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileBuyerCheckoutPlaceApiTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_buyer_places_a_curated_multi_seller_cod_order_without_premature_fulfillment(): void
    {
        Notification::fake();

        $buyer = $this->user('buyer');
        $otherBuyer = $this->user('buyer');
        $address = $this->address($buyer);
        $first = $this->productFixture('First Mobile Order Item', 1250);
        $second = $this->productFixture('Second Mobile Order Item', 2500);
        $unselected = $this->productFixture('Unselected Mobile Item', 500);
        $first['variant']->update(['stock_reserved' => 0]);
        $second['variant']->update(['stock_reserved' => 0]);
        $unselected['variant']->update(['stock_reserved' => 0]);
        $firstItem = $this->cartItem($buyer, $first['variant'], 2);
        $secondItem = $this->cartItem($buyer, $second['variant'], 1);
        $unselectedItem = $this->cartItem($buyer, $unselected['variant'], 1);
        $quote = $this->quote($buyer, $address, [$secondItem->id, $firstItem->id]);
        $request = $this->placementRequest($quote, $address, [$secondItem->id, $firstItem->id]);
        $request['buyer_id'] = $otherBuyer->id;
        $request['unit_price_minor'] = 1;
        $request['shipping_fee_minor'] = 1;
        $request['total_minor'] = 1;

        $response = $this->withToken($this->token($buyer))
            ->postJson('/api/mobile/buyer/checkout/place', $request)
            ->assertOk()
            ->assertJsonPath('message', 'Order placed.')
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.payment_method', 'cod')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.initial_payment_state', 'pending')
            ->assertJsonPath('data.currency', 'PHP')
            ->assertJsonPath('data.subtotal_minor', $quote['subtotal_minor'])
            ->assertJsonPath('data.shipping_fee_minor', $quote['shipping_fee_minor'])
            ->assertJsonPath('data.total_minor', $quote['total_minor'])
            ->assertJsonPath('data.seller_orders.0.status', 'pending')
            ->assertJsonMissingPath('data.checkout_request_fingerprint')
            ->assertJsonMissingPath('data.buyer_id')
            ->assertJsonMissingPath('data.recipient_name')
            ->assertJsonMissingPath('data.seller_orders.0.shipping_quote')
            ->assertJsonMissingPath('data.seller_orders.0.total_minor');

        $order = Order::query()->with(['sellerOrders.items', 'payments'])->sole();
        $this->assertSame($buyer->id, (int) $order->buyer_id);
        $this->assertSame((int) $order->id, $response->json('data.id'));
        $this->assertSame($order->order_no, $response->json('data.order_reference'));
        $this->assertNotEmpty($response->json('data.created_at'));
        $this->assertNotEmpty($response->json('data.placed_at'));
        $this->assertSame($buyer->name, $order->recipient_name);
        $this->assertSame(2, $order->sellerOrders->count());
        $this->assertSame(2, SellerOrder::query()->count());
        $this->assertSame(1, $order->payments->count());
        $this->assertSame('pending', $order->payments->sole()->status);
        $this->assertSame('cod', $order->payments->sole()->method);
        $this->assertSame(2, (int) $first['variant']->fresh()->stock_reserved);
        $this->assertSame(1, (int) $second['variant']->fresh()->stock_reserved);
        $this->assertSame(5, (int) $first['variant']->fresh()->stock_on_hand);
        $this->assertSame(2, InventoryMovement::query()->where('type', 'order_reserved')->count());
        $this->assertDatabaseMissing('cart_items', ['id' => $firstItem->id]);
        $this->assertDatabaseMissing('cart_items', ['id' => $secondItem->id]);
        $this->assertDatabaseHas('cart_items', ['id' => $unselectedItem->id]);
        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseCount('waybills', 0);
        $this->assertDatabaseCount('parcels', 0);
        $this->assertDatabaseCount('pickup_requests', 0);
        foreach ([$first['store'], $second['store']] as $store) {
            $this->assertTrue(SellerOrder::query()
                ->where('order_id', $order->id)
                ->where('store_id', $store->id)
                ->exists());
        }
        $this->assertSame(
            $order->sellerOrders->sortBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            collect($response->json('data.seller_orders'))->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        );

        foreach ([$first['store'], $second['store']] as $store) {
            $seller = User::query()->findOrFail($store->sellerProfile->user_id);
            Notification::assertSentToTimes($seller, WorkflowNotification::class, 1);
        }
    }

    public function test_identical_mobile_retry_returns_same_order_without_repeating_voucher_or_order_effects(): void
    {
        Notification::fake();

        $buyer = $this->user('buyer');
        $address = $this->address($buyer);
        $fixture = $this->productFixture('Voucher Retry Item', 10000);
        $fixture['variant']->update(['stock_reserved' => 0]);
        $cartItem = $this->cartItem($buyer, $fixture['variant'], 1);
        $this->platformVoucher('MOBILE5');
        $quote = $this->quote($buyer, $address, [$cartItem->id], ['voucher_code' => 'mobile5']);
        $request = $this->placementRequest($quote, $address, [$cartItem->id], ['voucher_code' => 'mobile5']);

        $first = $this->withToken($this->token($buyer))
            ->postJson('/api/mobile/buyer/checkout/place', $request)
            ->assertOk();
        $orderId = $first->json('data.id');

        $request['voucher_code'] = 'MOBILE5';
        $retry = $this->withToken($this->token($buyer))
            ->postJson('/api/mobile/buyer/checkout/place', $request)
            ->assertOk()
            ->assertJsonPath('data.id', $orderId);

        $this->assertSame($first->json('data.order_reference'), $retry->json('data.order_reference'));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, SellerOrder::query()->count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, InventoryMovement::query()->where('type', 'order_reserved')->count());
        $this->assertSame(1, PromotionRedemption::query()->count());
        $seller = User::query()->findOrFail($fixture['store']->sellerProfile->user_id);
        Notification::assertSentToTimes($seller, WorkflowNotification::class, 1);
    }

    public function test_mobile_conflicts_cover_changed_checkout_inputs_and_legacy_fingerprints(): void
    {
        $buyer = $this->user('buyer');
        $address = $this->address($buyer);
        $otherAddress = $this->address($buyer, 'Biñan');
        $first = $this->productFixture('Conflict Item', 5000);
        $second = $this->productFixture('Changed Cart Item', 3500);
        $first['variant']->update(['stock_reserved' => 0]);
        $second['variant']->update(['stock_reserved' => 0]);
        $cartItem = $this->cartItem($buyer, $first['variant'], 1);
        $otherCartItem = $this->cartItem($buyer, $second['variant'], 1);
        $quote = $this->quote($buyer, $address, [$cartItem->id]);
        $freshQuote = $this->quote($buyer, $address, [$cartItem->id]);
        $this->assertNotSame($quote['quote_token'], $freshQuote['quote_token']);
        $request = $this->placementRequest($quote, $address, [$cartItem->id], [
            'seller_note' => 'Leave by the door',
        ]);

        $this->withToken($this->token($buyer))
            ->postJson('/api/mobile/buyer/checkout/place', $request)
            ->assertOk();

        foreach ([
            ['cart_item_ids' => [$otherCartItem->id]],
            ['address_id' => $otherAddress->id],
            ['seller_note' => 'Call before delivery'],
            ['voucher_code' => 'DIFFERENT'],
            ['store_voucher_id' => 999],
        ] as $changedFields) {
            $this->withToken($this->token($buyer))
                ->postJson('/api/mobile/buyer/checkout/place', array_merge($request, $changedFields))
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'checkout_request_conflict');
        }

        $this->withToken($this->token($buyer))
            ->postJson('/api/mobile/buyer/checkout/place', array_merge($request, ['quote_token' => $freshQuote['quote_token']]))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'checkout_request_conflict');

        $order = Order::query()->sole();
        $order->update(['checkout_request_fingerprint' => null]);
        $this->withToken($this->token($buyer))
            ->postJson('/api/mobile/buyer/checkout/place', $request)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'checkout_request_conflict');

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, SellerOrder::query()->count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, InventoryMovement::query()->where('type', 'order_reserved')->count());
    }

    public function test_mobile_retry_after_expiry_returns_committed_order_but_expired_new_quote_fails(): void
    {
        $buyer = $this->user('buyer');
        $address = $this->address($buyer);
        $fixture = $this->productFixture('Committed Expiry Item', 2000);
        $secondFixture = $this->productFixture('Uncommitted Expiry Item', 1800);
        $fixture['variant']->update(['stock_reserved' => 0]);
        $secondFixture['variant']->update(['stock_reserved' => 0]);
        $firstItem = $this->cartItem($buyer, $fixture['variant'], 1);
        $secondItem = $this->cartItem($buyer, $secondFixture['variant'], 1);
        $firstQuote = $this->quote($buyer, $address, [$firstItem->id]);
        $secondQuote = $this->quote($buyer, $address, [$secondItem->id]);
        $firstRequest = $this->placementRequest($firstQuote, $address, [$firstItem->id]);
        $secondRequest = $this->placementRequest($secondQuote, $address, [$secondItem->id]);

        $firstResponse = $this->withToken($this->token($buyer))
            ->postJson('/api/mobile/buyer/checkout/place', $firstRequest)
            ->assertOk();
        $this->travel(11)->minutes();

        $this->withToken($this->token($buyer))
            ->postJson('/api/mobile/buyer/checkout/place', $firstRequest)
            ->assertOk()
            ->assertJsonPath('data.id', $firstResponse->json('data.id'));
        $this->withToken($this->token($buyer))
            ->postJson('/api/mobile/buyer/checkout/place', $secondRequest)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quote_token');

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, InventoryMovement::query()->where('type', 'order_reserved')->count());
        $this->assertDatabaseHas('cart_items', ['id' => $secondItem->id]);
    }

    public function test_mobile_checkout_enforces_authentication_eligibility_and_buyer_ownership(): void
    {
        $this->postJson('/api/mobile/buyer/checkout/place', [])->assertUnauthorized();

        foreach ([
            [$this->user('seller'), 403],
            [$this->user('rider'), 403],
            [$this->user('buyer', 'suspended'), 403],
        ] as [$user, $status]) {
            $this->withToken($this->token($user))
                ->postJson('/api/mobile/buyer/checkout/place', [])
                ->assertStatus($status);
        }
    }

    public function test_mobile_checkout_enforces_buyer_ownership_and_request_validation(): void
    {
        $buyer = $this->user('buyer');
        $otherBuyer = $this->user('buyer');
        $address = $this->address($buyer);
        $foreignAddress = $this->address($otherBuyer);
        $fixture = $this->productFixture('Ownership Item', 1000);
        $fixture['variant']->update(['stock_reserved' => 0]);
        $ownCartItem = $this->cartItem($buyer, $fixture['variant'], 1);
        $foreignCartItem = $this->cartItem($otherBuyer, $fixture['variant'], 1);
        $request = [
            'checkout_request_key' => (string) Str::uuid(),
            'quote_token' => 'not-a-valid-token',
            'address_id' => $address->id,
            'cart_item_ids' => [$ownCartItem->id],
            'payment_method' => 'cod',
        ];

        $this->withToken($this->token($buyer))
            ->postJson('/api/mobile/buyer/checkout/place', array_merge($request, ['address_id' => $foreignAddress->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address_id');
        $this->withToken($this->token($buyer))
            ->postJson('/api/mobile/buyer/checkout/place', array_merge($request, ['cart_item_ids' => [$foreignCartItem->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cart_item_ids');
        $this->withToken($this->token($buyer))
            ->postJson('/api/mobile/buyer/checkout/place', array_merge($request, ['payment_method' => 'online']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');
        $this->withToken($this->token($buyer))
            ->postJson('/api/mobile/buyer/checkout/place', array_merge($request, ['cart_item_ids' => [$ownCartItem->id, $ownCartItem->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cart_item_ids.1');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, InventoryMovement::query()->where('type', 'order_reserved')->count());
    }

    public function test_invalid_stale_and_unavailable_checkout_requests_return_validation_errors_without_partial_orders(): void
    {
        $buyer = $this->user('buyer');
        $address = $this->address($buyer);
        $fixture = $this->productFixture('Stale Mobile Item', 1500);
        $fixture['variant']->update(['stock_reserved' => 0]);
        $cartItem = $this->cartItem($buyer, $fixture['variant'], 2);
        $token = $this->token($buyer);
        $invalidRequest = [
            'checkout_request_key' => (string) Str::uuid(),
            'quote_token' => 'invalid-quote-token',
            'address_id' => $address->id,
            'cart_item_ids' => [$cartItem->id],
            'payment_method' => 'cod',
        ];

        $this->withToken($token)
            ->postJson('/api/mobile/buyer/checkout/place', $invalidRequest)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quote_token');

        $staleQuote = $this->quote($buyer, $address, [$cartItem->id]);
        $fixture['variant']->update(['price_minor' => 1800]);
        $staleRequest = $this->placementRequest($staleQuote, $address, [$cartItem->id]);
        $this->withToken($token)
            ->postJson('/api/mobile/buyer/checkout/place', $staleRequest)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quote_token');

        $staleShippingQuote = $this->quote($buyer, $address, [$cartItem->id]);
        SystemSetting::query()->updateOrCreate(
            ['key' => 'shipping.seller_order_fee_minor'],
            ['value' => 6000, 'value_type' => 'integer', 'is_public' => false],
        );
        $this->withToken($token)
            ->postJson('/api/mobile/buyer/checkout/place', $this->placementRequest($staleShippingQuote, $address, [$cartItem->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quote_token');

        $availableQuote = $this->quote($buyer, $address, [$cartItem->id]);
        $fixture['variant']->update(['stock_reserved' => 4]);
        $unavailableRequest = $this->placementRequest($availableQuote, $address, [$cartItem->id]);
        $this->withToken($token)
            ->postJson('/api/mobile/buyer/checkout/place', $unavailableRequest)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cart_item_ids');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, SellerOrder::query()->count());
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, InventoryMovement::query()->where('type', 'order_reserved')->count());
        $this->assertDatabaseHas('cart_items', ['id' => $cartItem->id]);
    }

    public function test_mobile_checkout_place_is_rate_limited_with_json_response(): void
    {
        $buyer = $this->user('buyer');
        $token = $this->token($buyer);

        for ($request = 0; $request < 10; $request++) {
            $this->withToken($token)
                ->postJson('/api/mobile/buyer/checkout/place', [])
                ->assertUnprocessable();
        }

        $this->withToken($token)
            ->postJson('/api/mobile/buyer/checkout/place', [])
            ->assertTooManyRequests()
            ->assertJsonStructure(['message']);
    }

    public function test_database_failure_rolls_back_all_mobile_checkout_effects_and_returns_safe_json(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());

        $buyer = $this->user('buyer');
        $address = $this->address($buyer);
        $fixture = $this->productFixture('Rollback Mobile Item', 2400);
        $fixture['variant']->update(['stock_reserved' => 0]);
        $cartItem = $this->cartItem($buyer, $fixture['variant'], 1);
        $quote = $this->quote($buyer, $address, [$cartItem->id]);
        $request = $this->placementRequest($quote, $address, [$cartItem->id]);
        DB::statement("CREATE TRIGGER fail_mobile_checkout_payment BEFORE INSERT ON payments BEGIN SELECT RAISE(ABORT, 'injected payment failure'); END");

        $this->withToken($this->token($buyer))
            ->postJson('/api/mobile/buyer/checkout/place', $request)
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'checkout_placement_failed')
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('sql');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, SellerOrder::query()->count());
        $this->assertSame(0, DB::table('order_items')->count());
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, InventoryMovement::query()->where('type', 'order_reserved')->count());
        $this->assertSame(0, (int) $fixture['variant']->fresh()->stock_reserved);
        $this->assertDatabaseHas('cart_items', ['id' => $cartItem->id]);
    }

    private function quote(User $buyer, Address $address, array $cartItemIds, array $selection = []): array
    {
        return $this->withToken($this->token($buyer))
            ->postJson('/api/mobile/buyer/checkout/quote', array_merge([
                'address_id' => $address->id,
                'cart_item_ids' => $cartItemIds,
                'payment_method' => 'cod',
            ], $selection))
            ->assertOk()
            ->json('data');
    }

    private function placementRequest(array $quote, Address $address, array $cartItemIds, array $overrides = []): array
    {
        return array_merge([
            'checkout_request_key' => (string) Str::uuid(),
            'quote_token' => $quote['quote_token'],
            'address_id' => $address->id,
            'cart_item_ids' => $cartItemIds,
            'payment_method' => 'cod',
        ], $overrides);
    }

    private function platformVoucher(string $code): PlatformPromotion
    {
        return PlatformPromotion::query()->create([
            'name' => 'Mobile checkout '.$code,
            'code' => $code,
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
        return $user->createToken('mobile-checkout-place-test')->plainTextToken;
    }

    private function address(User $user, string $city = 'Santa Rosa'): Address
    {
        return Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Home',
            'recipient_name' => $user->name,
            'phone' => '+639171234567',
            'street' => '10 Market Street',
            'barangay' => 'Balibago',
            'city_municipality' => $city,
            'province' => 'Laguna',
            'postal_code' => '4026',
            'psgc_province_code' => '0434',
            'psgc_city_code' => app(PostalCodeLookup::class)
                ->canonicalCityCode(null, 'Laguna', $city),
            'is_default_shipping' => true,
        ]);
    }

    /** @return array{product: Product, variant: ProductVariant, store: Store} */
    private function productFixture(string $name, int $priceMinor): array
    {
        $sequence = ++$this->sequence;
        $category = Category::query()->create([
            'name' => "Mobile Order Category {$sequence}",
            'slug' => "mobile-order-category-{$sequence}",
        ]);
        $seller = $this->user('seller');
        $pickup = $this->address($seller);
        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => "Private Seller {$sequence}",
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
            'sku' => "PRIVATE-SKU-{$sequence}",
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
