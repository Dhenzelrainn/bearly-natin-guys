<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use App\Services\CheckoutService;
use App\Services\PostalCodeLookup;
use App\Services\ShippingQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrdersCheckoutIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_checkout_creates_one_order_with_store_orders_and_is_idempotent(): void
    {
        Notification::fake();

        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [$firstSeller, $firstStore] = $this->sellerAndStore($category, 'checkout-first');
        [$secondSeller, $secondStore] = $this->sellerAndStore($category, 'checkout-second');
        $firstVariant = $this->variant($this->product($firstStore, $category, 'First Item'), 'FIRST-001', 10001, 5);
        $secondVariant = $this->variant($this->product($secondStore, $category, 'Second Item'), 'SECOND-001', 2500, 5);
        $firstCartItem = $this->cartItem($buyer, $firstVariant, 2);
        $secondCartItem = $this->cartItem($buyer, $secondVariant, 1);
        $requestKey = (string) Str::uuid();
        $quoteToken = $this->quoteToken($buyer, [$firstCartItem->id, $secondCartItem->id], $destination->id);

        $response = $this->actingAs($buyer)->post(route('checkout.place'), [
            'checkout_request_key' => $requestKey,
            'address_id' => $destination->id,
            'cart_item_ids' => [$firstCartItem->id, $secondCartItem->id],
            'quote_token' => $quoteToken,
            'payment_method' => 'cod',
        ]);

        $order = Order::query()->with(['sellerOrders.items', 'payments'])->firstOrFail();
        $response->assertRedirect(route('checkout.confirmation', $order->order_no));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $order->checkout_request_fingerprint);
        $this->assertArrayNotHasKey('checkout_request_fingerprint', $order->toArray());
        $this->assertSame(2, $order->sellerOrders->count());
        $this->assertSame(22502, (int) $order->subtotal_minor);
        $this->assertSame(10000, (int) $order->shipping_fee_minor);
        $this->assertSame(32502, (int) $order->total_minor);
        $this->assertSame(
            [5000, 5000],
            $order->sellerOrders->pluck('shipping_fee_minor')->map(fn ($fee): int => (int) $fee)->all(),
        );
        $this->assertSame('processing', $order->status);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('cod', $order->payments->sole()->method);
        $this->assertSame('pending', $order->payments->sole()->status);
        $this->assertNull($order->payments->sole()->provider);
        $this->assertSame(2, $order->sellerOrders->sum(fn ($sellerOrder) => $sellerOrder->items->count()));
        // Checkout snapshots the purchase-time marketplace category on every
        // order item for the admin sales reports.
        $order->sellerOrders->flatMap->items->each(function ($item) use ($category): void {
            $this->assertSame($category->id, (int) $item->category_id_snapshot);
            $this->assertSame($category->name, $item->category_name_snapshot);
        });
        $this->assertSame(2, (int) $firstVariant->fresh()->stock_reserved);
        $this->assertSame(1, (int) $secondVariant->fresh()->stock_reserved);
        $this->assertSame(5, (int) $firstVariant->fresh()->stock_on_hand);
        $this->assertDatabaseMissing('cart_items', ['id' => $firstCartItem->id]);
        $this->assertDatabaseMissing('cart_items', ['id' => $secondCartItem->id]);
        $this->assertSame(2, InventoryMovement::query()->where('type', 'order_reserved')->count());

        $this->actingAs($buyer)->post(route('checkout.place'), [
            'checkout_request_key' => $requestKey,
            'address_id' => $destination->id,
            'cart_item_ids' => [$secondCartItem->id, $firstCartItem->id],
            'quote_token' => $quoteToken,
            'payment_method' => 'cod',
        ])->assertRedirect(route('checkout.confirmation', $order->order_no));

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(2, SellerOrder::query()->count());
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(2, InventoryMovement::query()->where('type', 'order_reserved')->count());

        Notification::assertSentTo(
            $firstSeller,
            WorkflowNotification::class,
            fn (WorkflowNotification $notification): bool => $notification->eventKey() === 'seller_order_updates',
        );
        Notification::assertSentTo(
            $secondSeller,
            WorkflowNotification::class,
            fn (WorkflowNotification $notification): bool => $notification->eventKey() === 'seller_order_updates',
        );
        Notification::assertSentToTimes($firstSeller, WorkflowNotification::class, 1);
        Notification::assertSentToTimes($secondSeller, WorkflowNotification::class, 1);
    }

    public function test_checkout_conflicting_retry_payloads_return_conflict_without_side_effects(): void
    {
        Notification::fake();

        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $otherDestination = $this->address($buyer, 'Biñan');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'checkout-conflict');
        $variant = $this->variant($this->product($store, $category, 'Conflict Item'), 'CONFLICT-001', 10000, 5);
        $otherVariant = $this->variant($this->product($store, $category, 'Other Conflict Item'), 'CONFLICT-002', 10000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 1);
        $otherCartItem = $this->cartItem($buyer, $otherVariant, 1);
        $requestKey = (string) Str::uuid();
        $quoteToken = $this->quoteToken($buyer, [$cartItem->id], $destination->id);
        $original = [
            'checkout_request_key' => $requestKey,
            'address_id' => $destination->id,
            'cart_item_ids' => [$cartItem->id],
            'quote_token' => $quoteToken,
            'payment_method' => 'cod',
            'seller_note' => 'Leave at the front gate',
        ];

        $this->actingAs($buyer)->post(route('checkout.place'), $original)->assertRedirect();

        foreach ([
            ['cart_item_ids' => [$otherCartItem->id]],
            ['address_id' => $otherDestination->id],
            ['seller_note' => 'Call before delivery'],
            ['platform_voucher_id' => 999],
            ['voucher_code' => 'DIFFERENT'],
        ] as $changedFields) {
            $this->actingAs($buyer)
                ->post(route('checkout.place'), array_merge($original, $changedFields))
                ->assertStatus(409);
        }

        // COD is the only supported payment method. A different method is
        // rejected by the existing request validation before placement.
        $this->actingAs($buyer)
            ->post(route('checkout.place'), array_merge($original, ['payment_method' => 'online']))
            ->assertSessionHasErrors('payment_method');

        try {
            app(CheckoutService::class)->place(
                $buyer,
                [$cartItem->id],
                (int) $destination->id,
                $requestKey,
                $quoteToken,
                'Leave at the front gate',
                [],
                'online',
            );
            $this->fail('A changed payment method must conflict with the existing checkout key.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, SellerOrder::query()->count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, InventoryMovement::query()->where('type', 'order_reserved')->count());
        $this->assertSame(1, (int) $variant->fresh()->stock_reserved);
        $this->assertDatabaseHas('cart_items', ['id' => $otherCartItem->id]);
    }

    public function test_identical_checkout_retry_returns_committed_order_after_quote_expiry(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'checkout-expired-retry');
        $variant = $this->variant($this->product($store, $category, 'Expired Retry Item'), 'RETRY-001', 10000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 1);
        $request = [
            'checkout_request_key' => (string) Str::uuid(),
            'address_id' => $destination->id,
            'cart_item_ids' => [$cartItem->id],
            'quote_token' => $this->quoteToken($buyer, [$cartItem->id], $destination->id),
            'payment_method' => 'cod',
        ];

        $this->actingAs($buyer)->post(route('checkout.place'), $request)->assertRedirect();
        $order = Order::query()->firstOrFail();

        $this->travel(11)->minutes();

        $this->actingAs($buyer)
            ->post(route('checkout.place'), $request)
            ->assertRedirect(route('checkout.confirmation', $order->order_no));

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(1, InventoryMovement::query()->where('type', 'order_reserved')->count());
    }

    public function test_legacy_order_without_fingerprint_conflicts_without_guessing(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'checkout-legacy-fingerprint');
        $variant = $this->variant($this->product($store, $category, 'Legacy Item'), 'LEGACY-001', 10000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 1);
        $request = [
            'checkout_request_key' => (string) Str::uuid(),
            'address_id' => $destination->id,
            'cart_item_ids' => [$cartItem->id],
            'quote_token' => $this->quoteToken($buyer, [$cartItem->id], $destination->id),
            'payment_method' => 'cod',
        ];

        $this->actingAs($buyer)->post(route('checkout.place'), $request)->assertRedirect();
        $order = Order::query()->firstOrFail();
        $order->update(['checkout_request_fingerprint' => null]);

        $this->actingAs($buyer)->post(route('checkout.place'), $request)->assertStatus(409);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, SellerOrder::query()->count());
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(1, InventoryMovement::query()->where('type', 'order_reserved')->count());
    }

    public function test_expired_quote_cannot_create_a_new_order_or_retain_a_fingerprint(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'checkout-expired-new');
        $variant = $this->variant($this->product($store, $category, 'Expired New Item'), 'EXPIRED-001', 10000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 1);
        $request = [
            'checkout_request_key' => (string) Str::uuid(),
            'address_id' => $destination->id,
            'cart_item_ids' => [$cartItem->id],
            'quote_token' => $this->quoteToken($buyer, [$cartItem->id], $destination->id),
            'payment_method' => 'cod',
        ];

        $this->travel(11)->minutes();

        $this->actingAs($buyer)
            ->post(route('checkout.place'), $request)
            ->assertSessionHasErrors('quote_token');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, InventoryMovement::query()->where('type', 'order_reserved')->count());
        $this->assertSame(0, (int) $variant->fresh()->stock_reserved);
        $this->assertDatabaseHas('cart_items', ['id' => $cartItem->id]);
    }

    public function test_checkout_request_keys_are_scoped_to_authenticated_buyer(): void
    {
        Notification::fake();

        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'checkout-buyer-scope');
        $variant = $this->variant($this->product($store, $category, 'Buyer Scope Item'), 'BUYER-SCOPE-001', 10000, 5);
        $requestKey = (string) Str::uuid();

        foreach (['buyer', 'buyer'] as $index => $role) {
            $buyer = $this->user($role);
            $destination = $this->address($buyer, 'Santa Rosa');
            $cartItem = $this->cartItem($buyer, $variant, 1);
            $request = [
                'checkout_request_key' => $requestKey,
                'address_id' => $destination->id,
                'cart_item_ids' => [$cartItem->id],
                'quote_token' => $this->quoteToken($buyer, [$cartItem->id], $destination->id),
                'payment_method' => 'cod',
            ];

            $this->actingAs($buyer)->post(route('checkout.place'), $request)->assertRedirect();
        }

        $this->assertSame(2, Order::query()->count());
        $this->assertSame(2, Order::query()->where('checkout_request_key', $requestKey)->pluck('buyer_id')->unique()->count());
    }

    public function test_quote_uses_combined_parcel_and_flat_fee_for_over_one_kg(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'checkout-heavy');
        $variant = $this->variant($this->product($store, $category, 'Heavy Parcel Item'), 'HEAVY-001', 799, 10, [10, 10, 10]);
        $cartItem = $this->cartItem($buyer, $variant, 8);

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, (int) $variant->fresh()->stock_reserved);

        $response = $this->actingAs($buyer)->postJson(route('checkout.quote'), [
            'address_id' => $destination->id,
            'cart_item_ids' => [$cartItem->id],
        ])->assertOk();

        $response->assertJsonPath('data.shipping_fee_minor', 5000)
            ->assertJsonPath('data.seller_orders.0.shipping_fee_minor', 5000)
            ->assertJsonPath('data.seller_orders.0.quote.pricing_rule', 'flat_per_seller_order')
            ->assertJsonPath('data.seller_orders.0.quote.billable_weight_kg', 2)
            ->assertJsonPath('data.seller_orders.0.quote.volumetric_divisor', 5000)
            ->assertJsonPath('data.seller_orders.0.quote.package_estimator', 'greedy_minimum_bounding_box_v1');
        $response->assertJsonPath('data.items.0.unit_price_minor', 799)
            ->assertJsonPath('data.items.0.quantity', 8)
            ->assertJsonPath('data.delivery_address.name', $buyer->name)
            ->assertJsonPath('data.delivery_address.city', 'Santa Rosa');
        $this->assertNotEmpty($response->json('data.quote_token'));
        $dimensions = $response->json('data.seller_orders.0.quote.parcel_dimensions_cm');
        $this->assertSame(8000.0, (float) $dimensions['length'] * (float) $dimensions['width'] * (float) $dimensions['height']);
    }

    public function test_checkout_rejects_foreign_cart_items_and_foreign_addresses(): void
    {
        $buyer = $this->user('buyer');
        $foreignBuyer = $this->user('other-buyer');
        $foreignAddress = $this->address($foreignBuyer, 'Santa Rosa');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'checkout-owner');
        $variant = $this->variant($this->product($store, $category, 'Owned Item'), 'OWNED-001', 10000, 5);
        $foreignCartItem = $this->cartItem($foreignBuyer, $variant, 1);
        $buyerAddress = $this->address($buyer, 'Santa Rosa');
        $buyersOwnVariant = $this->variant($this->product($store, $category, 'Buyers Own Item'), 'OWNED-002', 10000, 5);
        $buyerCartItem = $this->cartItem($buyer, $buyersOwnVariant, 1);

        $this->actingAs($buyer)->postJson(route('checkout.quote'), [
            'address_id' => $buyerAddress->id,
            'cart_item_ids' => [$foreignCartItem->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('cart_item_ids');

        $this->actingAs($buyer)->postJson(route('checkout.quote'), [
            'address_id' => $foreignAddress->id,
            'cart_item_ids' => [$buyerCartItem->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('address_id');
    }

    public function test_buyer_can_cancel_only_pending_seller_orders_and_reservation_is_released(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'checkout-cancel');
        $variant = $this->variant($this->product($store, $category, 'Cancelable Item'), 'CANCEL-001', 1299, 7);
        $cartItem = $this->cartItem($buyer, $variant, 3);
        $quoteToken = $this->quoteToken($buyer, [$cartItem->id], $destination->id);

        $this->actingAs($buyer)->post(route('checkout.place'), [
            'checkout_request_key' => (string) Str::uuid(),
            'address_id' => $destination->id,
            'cart_item_ids' => [$cartItem->id],
            'quote_token' => $quoteToken,
            'payment_method' => 'cod',
        ])->assertRedirect();
        $order = Order::query()->firstOrFail();
        $this->assertSame(3, (int) $variant->fresh()->stock_reserved);

        $this->actingAs($buyer)->post(route('checkout.cancel', $order->id), [
            'reason' => 'Changed my mind',
        ])->assertRedirect(route('checkout.confirmation', $order->order_no));

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('cancelled', $order->sellerOrders()->sole()->status);
        $this->assertSame(0, (int) $variant->fresh()->stock_reserved);
        $this->assertSame(7, (int) $variant->fresh()->stock_on_hand);
        $this->assertDatabaseHas('inventory_movements', [
            'variant_id' => $variant->id,
            'type' => 'order_reservation_released',
            'reserved_delta' => -3,
            'reserved_balance_after' => 0,
            'created_by' => $buyer->id,
        ]);

        // Buyer cancellations notify the owning store through their own
        // registry event instead of the shared order-updates event.
        $cancellation = $seller->notifications()
            ->where('data->event', 'seller_order_cancelled')
            ->first();

        $this->assertNotNull($cancellation);
        $this->assertSame(
            (int) $order->sellerOrders()->sole()->id,
            (int) $cancellation->data['meta']['seller_order_id'],
        );
        $this->assertStringContainsString('Changed my mind', $cancellation->data['body']);

        $this->actingAs($buyer)->post(route('checkout.cancel', $order->id), [
            'reason' => 'Retry',
        ])->assertConflict();
    }

    public function test_seller_orders_are_persisted_and_scoped_to_the_authenticated_store(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [$firstSeller, $firstStore] = $this->sellerAndStore($category, 'orders-seller-first');
        [, $secondStore] = $this->sellerAndStore($category, 'orders-seller-second');
        $firstVariant = $this->variant($this->product($firstStore, $category, 'First Seller Item'), 'SELLER-FIRST-001', 1000, 5);
        $secondVariant = $this->variant($this->product($secondStore, $category, 'Second Seller Item'), 'SELLER-SECOND-001', 2000, 5);
        $firstItem = $this->cartItem($buyer, $firstVariant, 1);
        $secondItem = $this->cartItem($buyer, $secondVariant, 1);
        $quoteToken = $this->quoteToken($buyer, [$firstItem->id, $secondItem->id], $destination->id);

        $this->actingAs($buyer)->post(route('checkout.place'), [
            'checkout_request_key' => (string) Str::uuid(),
            'address_id' => $destination->id,
            'cart_item_ids' => [$firstItem->id, $secondItem->id],
            'quote_token' => $quoteToken,
            'payment_method' => 'cod',
        ])->assertRedirect();

        $sellerOrders = Order::query()->firstOrFail()->sellerOrders()->orderBy('store_id')->get();
        $this->actingAs($firstSeller)
            ->get(route('seller.orders'))
            ->assertOk()
            ->assertSee($sellerOrders->first()->seller_order_no)
            ->assertSee('First Seller Item')
            ->assertDontSee($sellerOrders->last()->seller_order_no)
            ->assertDontSee('Second Seller Item')
            ->assertSee('data-bearly-action-modal', false)
            ->assertSee('data-order-transition-action="confirm"', false)
            ->assertSee('Pickup Requests')
            ->assertSee('Logistics verifies the request and assigns a Rider.');
    }

    public function test_seller_can_advance_its_order_and_each_transition_is_audited(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'orders-transitions');
        $this->logisticsProviderFor('0403422000');
        $provider = $this->logisticsProviderFor((string) $destination->psgc_city_code);
        $variant = $this->variant($this->product($store, $category, 'Transition Item'), 'TRANSITION-001', 2500, 4);
        $cartItem = $this->cartItem($buyer, $variant, 2);
        $quoteToken = $this->quoteToken($buyer, [$cartItem->id], $destination->id);

        $this->actingAs($buyer)->post(route('checkout.place'), [
            'checkout_request_key' => (string) Str::uuid(),
            'address_id' => $destination->id,
            'cart_item_ids' => [$cartItem->id],
            'quote_token' => $quoteToken,
            'payment_method' => 'cod',
        ])->assertRedirect();

        $sellerOrder = Order::query()->firstOrFail()->sellerOrders()->sole();
        $endpoint = route('seller.orders.status', $sellerOrder->id);

        $this->actingAs($seller)->patchJson($endpoint, ['action' => 'confirm'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('message', 'Order confirmed. You can now prepare it.');
        $this->assertNotNull($sellerOrder->fresh()->confirmed_at);

        $this->actingAs($seller)->patchJson($endpoint, ['action' => 'start_preparing'])
            ->assertOk()
            ->assertJsonPath('data.status', 'packing')
            ->assertJsonPath('data.redirect_to', route('seller.fulfillment.waybills', ['status' => 'ready']));
        $this->assertNotNull($sellerOrder->fresh()->preparing_at);
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseCount('waybills', 1);
        $this->assertDatabaseCount('parcels', 1);

        $this->actingAs($seller)->patchJson($endpoint, ['action' => 'start_preparing'])
            ->assertConflict();

        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseCount('waybills', 1);
        $this->assertDatabaseCount('parcels', 1);

        $shipment = $sellerOrder->fresh()->shipments()->sole();
        $this->assertSame($provider->id, $shipment->logistics_profile_id);
        $this->assertSame($provider->id, $shipment->origin_logistics_profile_id);
        $this->assertSame('pending', $shipment->status);
        $this->assertSame('generated', $shipment->waybill->status);
        $this->assertNull($shipment->waybill->printed_at);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $shipment->id,
            'event_type' => 'waybill_generated',
            'to_status' => 'pending',
            'actor_user_id' => $seller->id,
            'source' => 'seller',
        ]);

        $this->actingAs($seller)->patchJson($endpoint, ['action' => 'mark_ready'])
            ->assertConflict();

        $shipment->waybill->update(['printed_at' => now()]);

        $this->actingAs($seller)->patchJson($endpoint, ['action' => 'mark_ready'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_for_pickup')
            ->assertJsonPath('data.redirect_to', route('seller.orders', ['status' => 'ready-pickup']));
        $this->assertNotNull($sellerOrder->fresh()->ready_at);
        $this->assertSame('ready_for_pickup', $shipment->fresh()->status);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $shipment->id,
            'event_type' => 'shipment_ready_for_pickup',
            'to_status' => 'ready_for_pickup',
        ]);

        $this->assertDatabaseCount('seller_order_status_histories', 3);
        $this->assertDatabaseHas('seller_order_status_histories', [
            'seller_order_id' => $sellerOrder->id,
            'from_status' => 'pending',
            'to_status' => 'accepted',
            'actor_user_id' => $seller->id,
            'reason' => 'Seller confirmed that this order can be fulfilled.',
        ]);
        $this->assertDatabaseHas('seller_order_status_histories', [
            'seller_order_id' => $sellerOrder->id,
            'from_status' => 'packing',
            'to_status' => 'ready_for_pickup',
            'actor_user_id' => $seller->id,
            'reason' => 'Seller completed the packing checklist and marked the order ready for pickup.',
        ]);
    }

    public function test_seller_status_actions_are_store_scoped_and_reject_invalid_or_replayed_transitions(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [$firstSeller, $firstStore] = $this->sellerAndStore($category, 'orders-transition-first');
        [, $secondStore] = $this->sellerAndStore($category, 'orders-transition-second');
        $firstVariant = $this->variant($this->product($firstStore, $category, 'First Transition Item'), 'TRANSITION-FIRST-001', 1000, 4);
        $secondVariant = $this->variant($this->product($secondStore, $category, 'Second Transition Item'), 'TRANSITION-SECOND-001', 1500, 4);
        $firstCartItem = $this->cartItem($buyer, $firstVariant, 1);
        $secondCartItem = $this->cartItem($buyer, $secondVariant, 1);
        $quoteToken = $this->quoteToken($buyer, [$firstCartItem->id, $secondCartItem->id], $destination->id);

        $this->actingAs($buyer)->post(route('checkout.place'), [
            'checkout_request_key' => (string) Str::uuid(),
            'address_id' => $destination->id,
            'cart_item_ids' => [$firstCartItem->id, $secondCartItem->id],
            'quote_token' => $quoteToken,
            'payment_method' => 'cod',
        ])->assertRedirect();

        $sellerOrders = Order::query()->firstOrFail()->sellerOrders()->orderBy('store_id')->get();
        $ownedOrder = $sellerOrders->firstWhere('store_id', $firstStore->id);
        $foreignOrder = $sellerOrders->firstWhere('store_id', $secondStore->id);

        $this->actingAs($buyer)->patchJson(route('seller.orders.status', $ownedOrder->id), [
            'action' => 'confirm',
        ])->assertForbidden();

        $this->actingAs($firstSeller)->patchJson(route('seller.orders.status', $foreignOrder->id), [
            'action' => 'confirm',
        ])->assertNotFound();

        $this->actingAs($firstSeller)->patchJson(route('seller.orders.status', $ownedOrder->id), [
            'action' => 'cancel',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->actingAs($firstSeller)->patchJson(route('seller.orders.status', $ownedOrder->id), [
            'action' => 'start_preparing',
        ])->assertConflict();
        $this->assertSame('pending', $ownedOrder->fresh()->status);
        $this->assertDatabaseCount('seller_order_status_histories', 0);

        $this->actingAs($firstSeller)->patchJson(route('seller.orders.status', $ownedOrder->id), [
            'action' => 'confirm',
        ])->assertOk();
        $this->actingAs($firstSeller)->patchJson(route('seller.orders.status', $ownedOrder->id), [
            'action' => 'cancel',
            'reason' => 'Changed fulfillment decision',
        ])->assertConflict();
        $this->actingAs($firstSeller)->patchJson(route('seller.orders.status', $ownedOrder->id), [
            'action' => 'confirm',
        ])->assertConflict();

        $this->assertSame('accepted', $ownedOrder->fresh()->status);
        $this->assertDatabaseCount('seller_order_status_histories', 1);
    }

    public function test_seller_cancellation_releases_only_its_stock_and_reprices_pending_multi_store_cod(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [$firstSeller, $firstStore] = $this->sellerAndStore($category, 'orders-cancel-first');
        [, $secondStore] = $this->sellerAndStore($category, 'orders-cancel-second');
        $firstVariant = $this->variant($this->product($firstStore, $category, 'First Cancel Item'), 'CANCEL-FIRST-001', 1200, 5);
        $secondVariant = $this->variant($this->product($secondStore, $category, 'Second Cancel Item'), 'CANCEL-SECOND-001', 1800, 5);
        $firstCartItem = $this->cartItem($buyer, $firstVariant, 2);
        $secondCartItem = $this->cartItem($buyer, $secondVariant, 1);
        $quoteToken = $this->quoteToken($buyer, [$firstCartItem->id, $secondCartItem->id], $destination->id);

        $this->actingAs($buyer)->post(route('checkout.place'), [
            'checkout_request_key' => (string) Str::uuid(),
            'address_id' => $destination->id,
            'cart_item_ids' => [$firstCartItem->id, $secondCartItem->id],
            'quote_token' => $quoteToken,
            'payment_method' => 'cod',
        ])->assertRedirect();

        $order = Order::query()->with('payments')->firstOrFail();
        $sellerOrders = $order->sellerOrders()->orderBy('store_id')->get();
        $cancelledSellerOrder = $sellerOrders->firstWhere('store_id', $firstStore->id);
        $remainingSellerOrder = $sellerOrders->firstWhere('store_id', $secondStore->id);
        $reason = 'Unable to fulfill this item';

        $this->actingAs($firstSeller)->patchJson(route('seller.orders.status', $cancelledSellerOrder->id), [
            'action' => 'cancel',
            'reason' => $reason,
        ])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('message', 'Seller Order cancelled and its reserved stock released.');

        $order->refresh();
        $payment = $order->payments()->sole();
        $this->assertSame('processing', $order->status);
        $this->assertSame((int) $remainingSellerOrder->total_minor, (int) $order->total_minor);
        $this->assertSame((int) $remainingSellerOrder->total_minor, (int) $payment->amount_minor);
        $this->assertSame('pending', $payment->status);
        $this->assertSame(0, (int) $firstVariant->fresh()->stock_reserved);
        $this->assertSame(1, (int) $secondVariant->fresh()->stock_reserved);
        $this->assertDatabaseHas('inventory_movements', [
            'variant_id' => $firstVariant->id,
            'type' => 'order_reservation_released',
            'reserved_delta' => -2,
            'reserved_balance_after' => 0,
            'reference_type' => SellerOrder::class,
            'reference_id' => $cancelledSellerOrder->id,
            'created_by' => $firstSeller->id,
        ]);
        $this->assertDatabaseHas('seller_order_status_histories', [
            'seller_order_id' => $cancelledSellerOrder->id,
            'from_status' => 'pending',
            'to_status' => 'cancelled',
            'actor_user_id' => $firstSeller->id,
            'reason' => $reason,
        ]);

        $this->actingAs($buyer)->post(route('checkout.cancel', $order->id), [
            'reason' => 'Please cancel the remaining items',
        ])->assertRedirect(route('checkout.confirmation', $order->order_no));

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('cancelled', $order->fresh()->payment_status);
        $this->assertSame('cancelled', $remainingSellerOrder->fresh()->status);
        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertSame(0, (int) $secondVariant->fresh()->stock_reserved);
        $this->assertDatabaseHas('seller_order_status_histories', [
            'seller_order_id' => $remainingSellerOrder->id,
            'from_status' => 'pending',
            'to_status' => 'cancelled',
            'actor_user_id' => $buyer->id,
            'reason' => 'Please cancel the remaining items',
        ]);
        $this->assertSame(2, InventoryMovement::query()->where('type', 'order_reservation_released')->count());
    }

    public function test_seller_cancellation_rolls_back_if_any_reserved_sku_cannot_be_released(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'orders-cancel-reservation-check');
        $product = $this->product($store, $category, 'Reservation Check Item');
        $firstVariant = $this->variant($product, 'CANCEL-SAFE-001', 1000, 5);
        $secondVariant = $this->variant($product, 'CANCEL-SAFE-002', 1500, 5);
        $firstCartItem = $this->cartItem($buyer, $firstVariant, 1);
        $secondCartItem = $this->cartItem($buyer, $secondVariant, 1);
        $quoteToken = $this->quoteToken($buyer, [$firstCartItem->id, $secondCartItem->id], $destination->id);

        $this->actingAs($buyer)->post(route('checkout.place'), [
            'checkout_request_key' => (string) Str::uuid(),
            'address_id' => $destination->id,
            'cart_item_ids' => [$firstCartItem->id, $secondCartItem->id],
            'quote_token' => $quoteToken,
            'payment_method' => 'cod',
        ])->assertRedirect();

        $sellerOrder = Order::query()->firstOrFail()->sellerOrders()->sole();
        ProductVariant::query()->whereKey($secondVariant->id)->update(['stock_reserved' => 0]);
        $this->assertCount(2, $sellerOrder->items()->get());
        $this->assertSame(0, (int) $secondVariant->fresh()->stock_reserved);

        $this->actingAs($seller)->patchJson(route('seller.orders.status', $sellerOrder->id), [
            'action' => 'cancel',
            'reason' => 'Unable to fulfill this item',
        ])->assertConflict();

        $this->assertSame('pending', $sellerOrder->fresh()->status);
        $this->assertSame(1, (int) $firstVariant->fresh()->stock_reserved);
        $this->assertSame(0, (int) $secondVariant->fresh()->stock_reserved);
        $this->assertSame(0, InventoryMovement::query()->where('type', 'order_reservation_released')->count());
        $this->assertDatabaseCount('seller_order_status_histories', 0);
        $this->assertSame('pending', Order::query()->firstOrFail()->payments()->sole()->status);
    }

    public function test_seller_cancellation_of_the_only_seller_order_cancels_the_marketplace_payment(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'orders-cancel-only');
        $variant = $this->variant($this->product($store, $category, 'Only Cancel Item'), 'CANCEL-ONLY-001', 900, 3);
        $cartItem = $this->cartItem($buyer, $variant, 1);
        $quoteToken = $this->quoteToken($buyer, [$cartItem->id], $destination->id);

        $this->actingAs($buyer)->post(route('checkout.place'), [
            'checkout_request_key' => (string) Str::uuid(),
            'address_id' => $destination->id,
            'cart_item_ids' => [$cartItem->id],
            'quote_token' => $quoteToken,
            'payment_method' => 'cod',
        ])->assertRedirect();

        $order = Order::query()->with('payments')->firstOrFail();
        $sellerOrder = $order->sellerOrders()->sole();
        $endpoint = route('seller.orders.status', $sellerOrder->id);

        $this->actingAs($seller)->patchJson($endpoint, [
            'action' => 'cancel',
            'reason' => 'Seller cannot fulfill',
        ])->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('cancelled', $order->fresh()->payment_status);
        $this->assertSame(0, (int) $order->fresh()->total_minor);
        $this->assertSame('cancelled', $order->payments()->sole()->status);
        $this->assertSame(0, (int) $variant->fresh()->stock_reserved);

        $this->actingAs($seller)->patchJson($endpoint, [
            'action' => 'cancel',
            'reason' => 'Retry cancellation',
        ])->assertConflict();
        $this->assertDatabaseCount('seller_order_status_histories', 1);
    }

    public function test_checkout_snapshots_variation_and_fallback_images_for_seller_orders(): void
    {
        Storage::fake('public');

        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [$seller, $store] = $this->sellerAndStore($category, 'orders-image-snapshots');

        $exactProduct = $this->product($store, $category, 'Exact SKU Image Item');
        $exactVariant = $this->variant($exactProduct, 'IMAGE-EXACT-001', 1000, 5);
        $exactVariant->update(['name' => 'Small / Black', 'options' => ['Size' => 'Small', 'Color' => 'Black']]);
        $this->productImage($exactProduct, null, 'seller-products/exact-primary.jpg', 'product_image', true);
        $exactSkuImage = $this->productImage($exactProduct, $exactVariant, 'seller-products/exact-sku.jpg', 'variant_sku');

        $legacyProduct = $this->product($store, $category, 'Legacy Color Image Item');
        $legacyVariant = $this->variant($legacyProduct, 'IMAGE-LEGACY-001', 1200, 5);
        $legacyVariant->update(['name' => 'Small / Navy', 'options' => ['Size' => 'Small', 'Color' => 'Navy']]);
        $legacySibling = $this->variant($legacyProduct, 'IMAGE-LEGACY-002', 1200, 5);
        $legacySibling->update(['name' => 'Large / Navy', 'options' => ['Size' => 'Large', 'Color' => 'Navy']]);
        $this->productImage($legacyProduct, null, 'seller-products/legacy-primary.jpg', 'product_image', true);
        $this->productImage($legacyProduct, $legacySibling, 'seller-products/legacy-navy.jpg', 'variant');

        $primaryProduct = $this->product($store, $category, 'Primary Image Fallback Item');
        $primaryVariant = $this->variant($primaryProduct, 'IMAGE-PRIMARY-001', 1400, 5);
        $primaryVariant->update(['name' => 'One Size / Red', 'options' => ['Size' => 'One Size', 'Color' => 'Red']]);
        $this->productImage($primaryProduct, null, 'seller-products/primary-fallback.jpg', 'product_image', true);

        $cartItems = [
            $this->cartItem($buyer, $exactVariant, 2),
            $this->cartItem($buyer, $legacyVariant, 1),
            $this->cartItem($buyer, $primaryVariant, 1),
        ];
        $cartItemIds = collect($cartItems)->pluck('id')->all();
        $quoteToken = $this->quoteToken($buyer, $cartItemIds, $destination->id);

        $this->actingAs($buyer)->post(route('checkout.place'), [
            'checkout_request_key' => (string) Str::uuid(),
            'address_id' => $destination->id,
            'cart_item_ids' => $cartItemIds,
            'quote_token' => $quoteToken,
            'payment_method' => 'cod',
        ])->assertRedirect();

        $sellerOrder = Order::query()->firstOrFail()->sellerOrders()->sole();
        $itemsByProduct = $sellerOrder->items()->get()->keyBy('product_id');
        $this->assertSame('seller-products/exact-sku.jpg', $itemsByProduct->get($exactProduct->id)->product_image_path);
        $this->assertSame('seller-products/legacy-navy.jpg', $itemsByProduct->get($legacyProduct->id)->product_image_path);
        $this->assertSame('seller-products/primary-fallback.jpg', $itemsByProduct->get($primaryProduct->id)->product_image_path);

        $response = $this->actingAs($seller)->get(route('seller.orders'))
            ->assertOk()
            ->assertSee('exact-sku.jpg')
            ->assertSee('legacy-navy.jpg')
            ->assertSee('primary-fallback.jpg');

        preg_match('/data-order-items-json="([^"]*)"/', $response->getContent(), $itemAttribute);
        $this->assertNotEmpty($itemAttribute);
        // The browser decodes attributes once. Double-escaped JSON must fail this check.
        $modalItems = json_decode(html_entity_decode($itemAttribute[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);
        $exactModalItem = collect($modalItems)->firstWhere('sku', 'IMAGE-EXACT-001');
        $this->assertSame('Exact SKU Image Item', $exactModalItem['name']);
        $this->assertSame('Small / Black', $exactModalItem['variant']);
        $this->assertSame(2, $exactModalItem['quantity']);
        $this->assertSame(1000, $exactModalItem['unit_price_minor']);
        $this->assertSame(2000, $exactModalItem['subtotal_minor']);
        $this->assertStringContainsString('exact-sku.jpg', $exactModalItem['image']);

        $this->actingAs($seller)->put(route('seller.products.update', $exactProduct), [
            'product_mode' => 'with_variants',
            'name' => $exactProduct->name,
            'category' => $category->name,
            'price' => '10.00',
            'stock' => 5,
            'option_one_name' => 'Size',
            'option_one_values' => 'Small',
            'option_two_name' => 'Color',
            'option_two_values' => 'Black',
            'variants' => [[
                'label' => 'Small / Black',
                'sku' => $exactVariant->sku,
                'price' => '10.00',
                'stock' => 5,
                'package_weight_kg' => '0.250',
                'package_length_cm' => '10',
                'package_width_cm' => '10',
                'package_height_cm' => '10',
            ]],
            'remove_product_images' => [$exactSkuImage->id],
            'intent' => 'draft',
        ])->assertRedirect(route('seller.products'));

        $this->assertDatabaseMissing('product_images', ['id' => $exactSkuImage->id]);
        Storage::disk('public')->assertExists('seller-products/exact-sku.jpg');
        $this->actingAs($seller)->get(route('seller.orders'))
            ->assertOk()
            ->assertSee('exact-sku.jpg');

        // Orders created before the snapshot fields existed still resolve their current catalog image.
        $itemsByProduct->get($primaryProduct->id)->update([
            'product_image_disk' => null,
            'product_image_path' => null,
        ]);

        $this->actingAs($seller)->get(route('seller.orders'))
            ->assertOk()
            ->assertSee('primary-fallback.jpg');
    }

    public function test_checkout_rejects_a_quote_after_its_price_or_shipping_terms_change(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'checkout-stale-quote');
        $variant = $this->variant($this->product($store, $category, 'Fresh Quote Item'), 'FRESH-001', 5000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 1);

        $oldRateQuote = $this->quoteToken($buyer, [$cartItem->id], $destination->id);
        SystemSetting::query()->updateOrCreate(
            ['key' => 'shipping.seller_order_fee_minor'],
            ['value' => 6000, 'value_type' => 'integer', 'is_public' => false],
        );

        $this->actingAs($buyer)->postJson(route('checkout.place'), [
            'checkout_request_key' => (string) Str::uuid(),
            'address_id' => $destination->id,
            'cart_item_ids' => [$cartItem->id],
            'quote_token' => $oldRateQuote,
            'payment_method' => 'cod',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('quote_token');

        $freshRateQuote = $this->quoteToken($buyer, [$cartItem->id], $destination->id);
        $variant->update(['price_minor' => 5200]);

        $this->actingAs($buyer)->postJson(route('checkout.place'), [
            'checkout_request_key' => (string) Str::uuid(),
            'address_id' => $destination->id,
            'cart_item_ids' => [$cartItem->id],
            'quote_token' => $freshRateQuote,
            'payment_method' => 'cod',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('quote_token');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, (int) $variant->fresh()->stock_reserved);
        $this->assertDatabaseHas('cart_items', ['id' => $cartItem->id]);
    }

    public function test_checkout_revalidates_stock_visibility_and_legacy_package_data(): void
    {
        $buyer = $this->user('buyer');
        $destination = $this->address($buyer, 'Santa Rosa');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'checkout-revalidation');
        $product = $this->product($store, $category, 'Revalidated Item');
        $variant = $this->variant($product, 'REVALIDATE-001', 5000, 5);
        $cartItem = $this->cartItem($buyer, $variant, 2);

        $stockQuote = $this->quoteToken($buyer, [$cartItem->id], $destination->id);
        $variant->update(['stock_reserved' => 4]);
        $this->actingAs($buyer)->postJson(route('checkout.place'), [
            'checkout_request_key' => (string) Str::uuid(),
            'address_id' => $destination->id,
            'cart_item_ids' => [$cartItem->id],
            'quote_token' => $stockQuote,
            'payment_method' => 'cod',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('cart_item_ids');

        $variant->update(['stock_reserved' => 0]);
        $visibilityQuote = $this->quoteToken($buyer, [$cartItem->id], $destination->id);
        $product->update(['product_status' => 'draft']);
        $this->actingAs($buyer)->postJson(route('checkout.place'), [
            'checkout_request_key' => (string) Str::uuid(),
            'address_id' => $destination->id,
            'cart_item_ids' => [$cartItem->id],
            'quote_token' => $visibilityQuote,
            'payment_method' => 'cod',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('cart_item_ids');

        $product->update(['product_status' => 'active']);
        $variant->update(['package_weight_kg' => null]);
        $this->actingAs($buyer)->postJson(route('checkout.quote'), [
            'address_id' => $destination->id,
            'cart_item_ids' => [$cartItem->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('shipping');

        $this->assertSame(0, Order::query()->count());
        $this->assertDatabaseHas('cart_items', ['id' => $cartItem->id]);
    }

    public function test_shipping_route_tiers_use_city_province_and_island_group(): void
    {
        $seller = $this->user('seller');
        $buyer = $this->user('buyer');
        $origin = $this->address($seller, 'Santa Rosa');
        $nearbyCity = $this->address($buyer, 'Santa Rosa');
        $sameProvince = $this->address($buyer, 'San Pablo City', 'Laguna', '043403000', '0434');
        $nearbyProvince = $this->address($buyer, 'Batangas City', 'Batangas', '041010000', '0410');
        $interIsland = $this->address($buyer, 'Cebu City', 'Cebu', '072217000', '0722');
        $shipping = app(ShippingQuoteService::class);

        $this->assertSame('nearby_delivery', $shipping->routeTier($origin, $nearbyCity));
        $this->assertSame('same_province', $shipping->routeTier($origin, $sameProvince));
        $this->assertSame('nearby_provinces', $shipping->routeTier($origin, $nearbyProvince));
        $this->assertSame('inter_island', $shipping->routeTier($origin, $interIsland));
    }

    public function test_flat_seller_order_fee_is_independent_of_route_and_billable_weight(): void
    {
        $buyer = $this->user('buyer');
        $category = $this->category();
        [, $store] = $this->sellerAndStore($category, 'checkout-flat-route');
        $variant = $this->variant(
            $this->product($store, $category, 'Heavy Flat Fee Item'),
            'FLAT-001',
            5000,
            5,
            [20, 20, 20],
        );
        $variant->update(['package_weight_kg' => 2.0]);
        $cartItem = $this->cartItem($buyer, $variant, 1);
        $destinations = [
            ['nearby_delivery', $this->address($buyer, 'Santa Rosa')],
            ['same_province', $this->address($buyer, 'San Pablo City', 'Laguna', null, '0434')],
            ['nearby_provinces', $this->address($buyer, 'Batangas City', 'Batangas', null, '0410')],
            ['inter_island', $this->address($buyer, 'Cebu City', 'Cebu', null, '0722')],
        ];

        foreach ($destinations as [$routeTier, $destination]) {
            $this->actingAs($buyer)->postJson(route('checkout.quote'), [
                'address_id' => $destination->id,
                'cart_item_ids' => [$cartItem->id],
            ])->assertOk()
                ->assertJsonPath('data.shipping_fee_minor', 5000)
                ->assertJsonPath('data.seller_orders.0.shipping_fee_minor', 5000)
                ->assertJsonPath('data.seller_orders.0.quote.route_tier', $routeTier)
                ->assertJsonPath('data.seller_orders.0.quote.billable_weight_kg', 2);
        }
    }

    public function test_admin_can_update_flat_seller_order_fee_and_create_audit_attribution(): void
    {
        $buyer = $this->user('buyer');
        $this->actingAs($buyer)->get(route('admin.shipping-rates'))->assertForbidden();

        $admin = $this->user('admin');
        $this->actingAs($admin)
            ->get(route('admin.shipping-rates'))
            ->assertOk()
            ->assertSee('Standard fee per Seller Order')
            ->assertSee('value="50.00"', false)
            ->assertDontSee('additional_kg_surcharge', false);

        $this->actingAs($admin)->put(route('admin.shipping-rates.update'), [
            'seller_order_fee' => '50.00',
            'volumetric_divisor' => 4500,
            'reason' => 'Set standard Seller Order shipping fee',
        ])->assertRedirect(route('admin.shipping-rates'));

        $this->assertSame(5000, (int) SystemSetting::query()->where('key', 'shipping.seller_order_fee_minor')->value('value'));
        $this->assertSame(4500, (int) SystemSetting::query()->where('key', 'shipping.volumetric_divisor')->value('value'));
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'action' => 'shipping_rates.updated',
            'module' => 'shipping',
            'description' => 'Admin updated Bearly shipping configuration: Set standard Seller Order shipping fee',
        ]);
    }

    private function user(string $role): User
    {
        $sequence = ++$this->sequence;

        return User::query()->create([
            'name' => Str::headline($role).' '.$sequence,
            'first_name' => ucfirst($role),
            'last_name' => 'Orders '.$sequence,
            'email' => "{$role}-{$sequence}@example.test",
            'role' => $role,
            'status' => 'active',
            'password' => Hash::make('Password123'),
        ]);
    }

    private function category(): Category
    {
        $slug = 'orders-category-'.(++$this->sequence);

        return Category::query()->create(['name' => Str::headline($slug), 'slug' => $slug]);
    }

    /** @return array{0: User, 1: Store} */
    private function logisticsProviderFor(string $cityCode): LogisticsProfile
    {
        $user = $this->user('logistics');

        return LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => 'Bearly Test Logistics Inc.',
            'display_name' => 'Bearly Test Logistics',
            'contact_phone' => '+639171234567',
            'psgc_city_code' => $cityCode,
            'status' => 'active',
        ]);
    }

    private function sellerAndStore(Category $category, string $slug): array
    {
        $seller = $this->user('seller');
        $pickup = $this->address($seller, 'Santa Rosa');
        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Orders Seller '.$this->sequence,
            'approved_category_id' => $category->id,
            'pickup_address_id' => $pickup->id,
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => Str::headline($slug),
            'slug' => $slug.'-'.$this->sequence,
            'publication_status' => 'published',
        ]);

        return [$seller->fresh('sellerProfile.store'), $store];
    }

    private function address(
        User $user,
        string $city,
        string $province = 'Laguna',
        ?string $cityCode = null,
        ?string $provinceCode = null,
    ): Address {
        return Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Home',
            'recipient_name' => $user->name,
            'phone' => '+639171234567',
            'street' => '10 Market Street',
            'barangay' => 'Balibago',
            'city_municipality' => $city,
            'province' => $province,
            'postal_code' => '4026',
            'psgc_province_code' => $provinceCode ?? '0434',
            'psgc_city_code' => $cityCode ?? app(PostalCodeLookup::class)
                ->canonicalCityCode(null, $province, $city),
            'is_default_shipping' => true,
        ]);
    }

    private function product(Store $store, Category $category, string $name): Product
    {
        $slug = Str::slug($name).'-'.(++$this->sequence);

        return Product::query()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => $slug,
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'payment_methods' => ['cod', 'online'],
        ]);
    }

    private function variant(Product $product, string $sku, int $priceMinor, int $stock, array $dimensions = [10, 10, 10]): ProductVariant
    {
        return ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => $sku,
            'name' => 'Default',
            'price_minor' => $priceMinor,
            'stock_on_hand' => $stock,
            'stock_reserved' => 0,
            'low_stock_threshold' => 5,
            'package_weight_kg' => 0.25,
            'package_length_cm' => $dimensions[0],
            'package_width_cm' => $dimensions[1],
            'package_height_cm' => $dimensions[2],
            'is_active' => true,
        ]);
    }

    private function productImage(
        Product $product,
        ?ProductVariant $variant,
        string $path,
        string $purpose,
        bool $primary = false,
    ): ProductImage {
        Storage::disk('public')->put($path, 'image fixture');

        return ProductImage::query()->create([
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'path' => $path,
            'disk' => 'public',
            'purpose' => $purpose,
            'is_primary' => $primary,
            'position' => 0,
        ]);
    }

    private function cartItem(User $buyer, ProductVariant $variant, int $quantity): CartItem
    {
        $cart = Cart::query()->firstOrCreate(['user_id' => $buyer->id, 'status' => 'active']);

        return CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
            'selected' => true,
        ]);
    }

    private function quoteToken(User $buyer, array $cartItemIds, int $addressId): string
    {
        $response = $this->actingAs($buyer)->postJson(route('checkout.quote'), [
            'address_id' => $addressId,
            'cart_item_ids' => $cartItemIds,
        ])->assertOk();

        return (string) $response->json('data.quote_token');
    }
}
