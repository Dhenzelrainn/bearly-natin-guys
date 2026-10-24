<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\CodCashCollection;
use App\Models\DeliveryAttempt;
use App\Models\DispatchBatch;
use App\Models\InventoryMovement;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Payment;
use App\Models\PickupAssignment;
use App\Models\PickupRequest;
use App\Models\PlatformCommission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\SellerOrder;
use App\Models\SellerOrderStatusHistory;
use App\Models\SellerProfile;
use App\Models\SellerTransaction;
use App\Models\SellerWalletTransaction;
use App\Models\Shipment;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use App\Notifications\WorkflowNotification;
use App\Services\PostalCodeLookup;
use App\Services\SellerWalletService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class LocalCodCrossRoleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    /**
     * Open and verify the configured test connection before RefreshDatabase
     * can run migrations. The process environment is explicit in the test
     * command, and a cached or overridden database configuration fails closed.
     */
    public function createApplication()
    {
        foreach ([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
        ] as $key => $expected) {
            if (getenv($key) !== $expected) {
                throw new RuntimeException("M4E.1A tests require {$key}={$expected}; refusing to boot the test application.");
            }
        }

        $app = parent::createApplication();
        $config = $app['config'];
        $sqlite = $config->get('database.connections.sqlite', []);

        if ($config->get('app.env') !== 'testing'
            || $config->get('database.default') !== 'sqlite'
            || ($sqlite['database'] ?? null) !== ':memory:'
            || filled($sqlite['url'] ?? null)) {
            throw new RuntimeException('M4E.1A tests require the effective database configuration to be SQLite :memory:; refusing to run migrations.');
        }

        $connection = $app['db']->connection();
        if ($connection->getDriverName() !== 'sqlite'
            || $connection->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('M4E.1A tests could not verify a SQLite :memory: PDO connection; refusing to run migrations.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        Storage::fake('local');
    }

    public function test_mobile_cod_placement_replay_and_conflicting_retry_keep_one_reservation(): void
    {
        $scenario = $this->scenario();
        $scenario = $this->placeOrder($scenario);

        $first = $this->withToken($scenario['token'])
            ->postJson('/api/mobile/buyer/checkout/place', $scenario['placement_request'])
            ->assertOk()
            ->assertJsonPath('data.id', $scenario['order']->id);

        $this->assertSame($scenario['order']->order_no, $first->json('data.order_reference'));

        $changedRequest = $scenario['placement_request'];
        $changedRequest['seller_note'] = 'Leave at a different entrance.';
        $this->withToken($scenario['token'])
            ->postJson('/api/mobile/buyer/checkout/place', $changedRequest)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'checkout_request_conflict');

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, SellerOrder::query()->count());
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, InventoryMovement::query()->where('type', 'order_reserved')->count());
        $this->assertSame(1, (int) $scenario['variant']->fresh()->stock_reserved);
        $this->assertSame(5, (int) $scenario['variant']->fresh()->stock_on_hand);
        $this->assertDatabaseMissing('cart_items', ['id' => $scenario['cart_item']->id]);
        $this->assertDatabaseHas('cart_items', ['id' => $scenario['unselected_item']->id]);
        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseCount('waybills', 0);
        $this->assertDatabaseCount('parcels', 0);
    }

    public function test_local_cod_order_completes_across_roles_then_finance_releases_wallet_after_the_hold(): void
    {
        $scenario = $this->placeOrder($this->scenario());

        $this->assertReceiptIsBlockedBeforeDelivery($scenario);
        $scenario = $this->deliverLocally($scenario);

        $order = $scenario['order']->fresh();
        $sellerOrder = $scenario['seller_order']->fresh();
        $shipment = $scenario['shipment']->fresh();
        $collection = CodCashCollection::query()->where('shipment_id', $shipment->id)->sole();
        $payment = Payment::query()->where('order_id', $order->id)->sole();
        $commission = PlatformCommission::query()->where('seller_order_id', $sellerOrder->id)->where('type', 'sale')->sole();
        $sellerTransaction = SellerTransaction::query()->where('seller_order_id', $sellerOrder->id)->where('type', 'sale')->sole();

        $this->assertSame('delivered', $shipment->status);
        $this->assertSame('handed_to_logistics', $sellerOrder->status, 'Buyer confirmation, not delivery, completes the Seller Order.');
        $this->assertSame('collected', $collection->status);
        $this->assertSame((int) $shipment->cod_amount_minor, (int) $collection->amount_due_minor);
        $this->assertSame((int) $shipment->cod_amount_minor, (int) $collection->amount_collected_minor);
        $this->assertSame('paid', $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('finalized', $commission->status);
        $this->assertSame('posted', $sellerTransaction->status);
        $this->assertSame(1, SellerWalletTransaction::query()
            ->where('seller_transaction_id', $sellerTransaction->id)
            ->where('type', 'sale_earning_pending')
            ->count());
        $this->assertNull($collection->logistics_reference);
        $this->assertNull($collection->finance_remittance_reference);
        $this->assertNull($collection->finance_reference);
        $this->assertSame(0, $collection->events()->whereIn('event_type', [
            'cash_received_by_logistics',
            'cash_remitted_to_finance',
            'cash_reconciled_by_finance',
        ])->count());

        $this->actingAs($scenario['buyer'])
            ->getJson('/api/mobile/buyer/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.buyer_actions.can_confirm_receipt', true);

        $historyCountBeforeConfirmation = SellerOrderStatusHistory::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->count();
        $completionNoticesBefore = $this->workflowNotificationCount($scenario['seller'], 'seller_shipment_updates');

        $confirmation = $this->actingAs($scenario['buyer'])
            ->postJson('/api/mobile/buyer/orders/'.$order->id.'/confirm-receipt')
            ->assertOk()
            ->assertJsonPath('data.status', 'fulfilled')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.receipt_confirmed', true)
            ->assertJsonPath('data.already_confirmed', false)
            ->assertJsonPath('data.seller_orders.0.status', 'completed');

        $completedAt = $confirmation->json('data.completed_at');
        $this->assertNotEmpty($completedAt);
        $this->assertSame('fulfilled', $order->fresh()->status);
        $this->assertSame('completed', $sellerOrder->fresh()->status);
        $this->assertNotNull($sellerOrder->fresh()->completed_at);
        $this->assertSame($historyCountBeforeConfirmation + 1, SellerOrderStatusHistory::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->count());
        $this->assertSame($completionNoticesBefore + 1, $this->workflowNotificationCount($scenario['seller'], 'seller_shipment_updates'));

        $wallet = app(SellerWalletService::class)->refreshForSeller($scenario['seller_profile']);
        $this->assertSame(0, (int) $wallet->available_balance_minor);
        $this->assertSame((int) $sellerTransaction->amount_minor, (int) $wallet->pending_balance_minor);

        // The return window is measured from the Buyer-confirmed SellerOrder completion.
        $this->travel(8)->days();
        $wallet = app(SellerWalletService::class)->refreshForSeller($scenario['seller_profile']);
        $this->assertSame(0, (int) $wallet->available_balance_minor, 'Elapsed time alone cannot release unreconciled COD earnings.');
        $this->assertSame((int) $sellerTransaction->amount_minor, (int) $wallet->pending_balance_minor);

        $this->reconcileCashWithFinance($scenario, $collection);

        $wallet = app(SellerWalletService::class)->refreshForSeller($scenario['seller_profile']);
        $this->assertSame((int) $sellerTransaction->amount_minor, (int) $wallet->available_balance_minor);
        $this->assertSame(0, (int) $wallet->pending_balance_minor);
        $availableMovementCount = SellerWalletTransaction::query()
            ->where('seller_transaction_id', $sellerTransaction->id)
            ->where('type', 'sale_earning_available')
            ->count();
        $this->assertSame(1, $availableMovementCount);

        $timestampBeforeReplay = $order->fresh()->completed_at;
        $historyCountBeforeReplay = SellerOrderStatusHistory::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->count();
        $paymentCountBeforeReplay = Payment::query()->where('order_id', $order->id)->count();
        $commissionCountBeforeReplay = PlatformCommission::query()->where('seller_order_id', $sellerOrder->id)->count();
        $completionNoticesAfterFirst = $this->workflowNotificationCount($scenario['seller'], 'seller_shipment_updates');

        $this->actingAs($scenario['buyer'])
            ->postJson('/api/mobile/buyer/orders/'.$order->id.'/confirm-receipt')
            ->assertOk()
            ->assertJsonPath('data.already_confirmed', true)
            ->assertJsonPath('data.completed_at', $completedAt);

        $this->assertEquals($timestampBeforeReplay, $order->fresh()->completed_at);
        $this->assertSame($historyCountBeforeReplay, SellerOrderStatusHistory::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->count());
        $this->assertSame($paymentCountBeforeReplay, Payment::query()->where('order_id', $order->id)->count());
        $this->assertSame($commissionCountBeforeReplay, PlatformCommission::query()->where('seller_order_id', $sellerOrder->id)->count());
        $this->assertSame($completionNoticesAfterFirst, $this->workflowNotificationCount($scenario['seller'], 'seller_shipment_updates'));

        app(SellerWalletService::class)->refreshForSeller($scenario['seller_profile']);
        $this->assertSame(1, SellerWalletTransaction::query()
            ->where('seller_transaction_id', $sellerTransaction->id)
            ->where('type', 'sale_earning_available')
            ->count());
    }

    public function test_uncovered_destination_barangay_is_rejected_without_transfer_or_status_mutation(): void
    {
        $scenario = $this->placeOrder($this->scenario());
        $scenario = $this->receiveAtCenter($scenario);

        $uncoveredZone = SortingZone::query()->create([
            'sorting_center_id' => $scenario['center']->id,
            'name' => 'Uncovered Barangay Zone',
            'code' => 'M4E1A-UNCOVERED-'.$scenario['center']->id,
            'destination_rules' => [
                'province' => 'Laguna',
                'city_municipality' => 'Pila',
                'barangays' => ['Pinagbayanan'],
            ],
            'status' => 'active',
        ]);

        $this->actingAs($scenario['logistics_user'])
            ->patch(route('logistics.sorting.parcels.update', $scenario['parcel']), [
                'sorting_zone_id' => $uncoveredZone->id,
            ])
            ->assertConflict();

        $this->assertSame('received', $scenario['parcel']->fresh()->status);
        $this->assertNull($scenario['parcel']->fresh()->current_zone_id);
        $this->assertSame('at_sorting_center', $scenario['shipment']->fresh()->status);
        $this->assertSame(0, $scenario['shipment']->events()->where('event_type', 'parcel_sorted')->count());
        $this->assertSame(0, DB::table('shipment_transfers')->where('shipment_id', $scenario['shipment']->id)->count());
        $this->assertSame($scenario['logistics_profile']->id, (int) $scenario['shipment']->fresh()->logistics_profile_id);
    }

    public function test_open_return_case_blocks_reconciled_cod_wallet_release_after_the_return_window(): void
    {
        $scenario = $this->placeOrder($this->scenario());
        $scenario = $this->deliverLocally($scenario);
        $order = $scenario['order']->fresh();
        $sellerOrder = $scenario['seller_order']->fresh();
        $payment = Payment::query()->where('order_id', $order->id)->sole();
        $sellerTransaction = SellerTransaction::query()->where('seller_order_id', $sellerOrder->id)->where('type', 'sale')->sole();
        $collection = CodCashCollection::query()->where('shipment_id', $scenario['shipment']->id)->sole();

        $this->actingAs($scenario['buyer'])
            ->postJson('/api/mobile/buyer/orders/'.$order->id.'/confirm-receipt')
            ->assertOk();
        $this->reconcileCashWithFinance($scenario, $collection);

        ReturnRequest::query()->create([
            'return_no' => 'M4E1A-RETURN-'.Str::upper(Str::random(10)),
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
            'buyer_id' => $scenario['buyer']->id,
            'store_id' => $scenario['store']->id,
            'request_type' => 'refund_only',
            'reason_code' => 'item_arrived_damaged',
            'status' => 'refund_pending',
            'requested_amount_minor' => 1000,
            'submitted_at' => now(),
        ]);

        $wallet = app(SellerWalletService::class)->refreshForSeller($scenario['seller_profile']);
        $this->assertSame(0, (int) $wallet->available_balance_minor);
        $this->assertSame((int) $sellerTransaction->amount_minor, (int) $wallet->pending_balance_minor);

        $this->travel(8)->days();
        $wallet = app(SellerWalletService::class)->refreshForSeller($scenario['seller_profile']);
        $this->assertSame(0, (int) $wallet->available_balance_minor);
        $this->assertSame((int) $sellerTransaction->amount_minor, (int) $wallet->pending_balance_minor);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('reconciled', $collection->fresh()->status);
        $this->assertSame('refund_pending', ReturnRequest::query()->where('seller_order_id', $sellerOrder->id)->sole()->status);
        $this->assertSame(0, SellerWalletTransaction::query()
            ->where('seller_transaction_id', $sellerTransaction->id)
            ->where('type', 'sale_earning_available')
            ->count());
    }

    /** @return array<string, mixed> */
    private function scenario(): array
    {
        $suffix = Str::lower(Str::random(10));
        $buyer = $this->actor('buyer', "M4E Buyer {$suffix}");
        $buyerAddress = $this->address($buyer, 'Buyer Home', true, false);
        $seller = $this->actor('seller', "M4E Seller {$suffix}");
        $sellerAddress = $this->address($seller, 'Seller Pickup', false, true);
        $cityCode = (string) app(PostalCodeLookup::class)->canonicalCityCode(null, 'Laguna', 'Santa Rosa');

        $category = Category::query()->create([
            'name' => 'M4E Local Category '.$suffix,
            'slug' => 'm4e-local-category-'.$suffix,
        ]);
        $sellerProfile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => 'M4E Local Seller '.$suffix,
            'approved_category_id' => $category->id,
            'pickup_address_id' => $sellerAddress->id,
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $sellerProfile->id,
            'name' => 'M4E Local Store '.$suffix,
            'slug' => 'm4e-local-store-'.$suffix,
            'contact_phone' => '+639171234567',
            'publication_status' => 'published',
            'published_at' => now(),
        ]);

        $product = Product::query()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'M4E Local COD Product '.$suffix,
            'slug' => 'm4e-local-cod-product-'.$suffix,
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'voucher_eligible' => true,
            'payment_methods' => ['cod'],
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'M4E-'.Str::upper($suffix),
            'name' => 'Regular size / Blue',
            'options' => ['Size' => 'Regular', 'Color' => 'Blue'],
            'price_minor' => 19900,
            'stock_on_hand' => 5,
            'stock_reserved' => 0,
            'package_weight_kg' => 0.5,
            'package_length_cm' => 20,
            'package_width_cm' => 15,
            'package_height_cm' => 10,
            'is_active' => true,
        ]);

        $unselectedProduct = Product::query()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'M4E Unselected Product '.$suffix,
            'slug' => 'm4e-unselected-product-'.$suffix,
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'voucher_eligible' => true,
            'payment_methods' => ['cod'],
        ]);
        $unselectedVariant = ProductVariant::query()->create([
            'product_id' => $unselectedProduct->id,
            'sku' => 'M4E-UNSELECTED-'.Str::upper($suffix),
            'name' => 'Unselected variant',
            'options' => ['Size' => 'One size'],
            'price_minor' => 5000,
            'stock_on_hand' => 4,
            'stock_reserved' => 0,
            'package_weight_kg' => 0.25,
            'is_active' => true,
        ]);

        $cart = Cart::query()->create(['user_id' => $buyer->id, 'status' => 'active']);
        $cartItem = CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'selected' => true,
        ]);
        $unselectedItem = CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_variant_id' => $unselectedVariant->id,
            'quantity' => 1,
            'selected' => true,
        ]);

        $logisticsUser = $this->actor('logistics', "M4E Logistics {$suffix}");
        $logisticsProfile = LogisticsProfile::query()->create([
            'user_id' => $logisticsUser->id,
            'legal_name' => 'M4E Logistics '.$suffix,
            'display_name' => 'M4E Logistics '.$suffix,
            'contact_phone' => '+639171234568',
            'psgc_city_code' => $cityCode,
            'status' => 'active',
        ]);
        $centerAddress = $this->address($logisticsUser, 'Sorting Center', false, false);
        $center = SortingCenter::query()->create([
            'logistics_profile_id' => $logisticsProfile->id,
            'address_id' => $centerAddress->id,
            'name' => 'M4E Sorting Center '.$suffix,
            'code' => 'M4E-CENTER-'.Str::upper($suffix),
            'contact_phone' => '+639171234568',
            'status' => 'active',
        ]);
        $zone = SortingZone::query()->create([
            'sorting_center_id' => $center->id,
            'name' => 'Santa Rosa Balibago Zone '.$suffix,
            'code' => 'M4E-ZONE-'.Str::upper($suffix),
            'destination_rules' => [
                'province' => 'Laguna',
                'city_municipality' => 'Santa Rosa',
                'psgc_city_code' => $cityCode,
                'barangays' => ['Balibago'],
            ],
            'status' => 'active',
        ]);

        $pickupRider = $this->rider($logisticsUser, $logisticsProfile, $center, $zone, 'Pickup '.$suffix);
        $deliveryRider = $this->rider($logisticsUser, $logisticsProfile, $center, $zone, 'Delivery '.$suffix);
        $admin = $this->actor('admin', "M4E Finance {$suffix}");

        return [
            'admin' => $admin,
            'buyer' => $buyer,
            'buyer_address' => $buyerAddress,
            'cart_item' => $cartItem,
            'category' => $category,
            'center' => $center,
            'delivery_rider' => $deliveryRider,
            'logistics_profile' => $logisticsProfile,
            'logistics_user' => $logisticsUser,
            'pickup_rider' => $pickupRider,
            'product' => $product,
            'seller' => $seller,
            'seller_address' => $sellerAddress,
            'seller_profile' => $sellerProfile,
            'store' => $store,
            'unselected_item' => $unselectedItem,
            'variant' => $variant,
            'zone' => $zone,
        ];
    }

    private function placeOrder(array $scenario): array
    {
        $scenario['token'] = $scenario['buyer']->createToken('m4e1a-cross-role-test')->plainTextToken;
        $quote = $this->withToken($scenario['token'])
            ->postJson('/api/mobile/buyer/checkout/quote', [
                'address_id' => $scenario['buyer_address']->id,
                'cart_item_ids' => [$scenario['cart_item']->id],
                'payment_method' => 'cod',
            ])
            ->assertOk()
            ->json('data');

        $scenario['placement_request'] = [
            'checkout_request_key' => (string) Str::uuid(),
            'quote_token' => $quote['quote_token'],
            'address_id' => $scenario['buyer_address']->id,
            'cart_item_ids' => [$scenario['cart_item']->id],
            'payment_method' => 'cod',
        ];

        $response = $this->withToken($scenario['token'])
            ->postJson('/api/mobile/buyer/checkout/place', $scenario['placement_request'])
            ->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.payment_method', 'cod')
            ->assertJsonPath('data.payment_status', 'unpaid');

        $scenario['order'] = Order::query()
            ->with(['sellerOrders.items', 'payments'])
            ->findOrFail($response->json('data.id'));
        $scenario['seller_order'] = $scenario['order']->sellerOrders()->sole();

        $this->assertSame('pending', $scenario['seller_order']->status);
        $this->assertSame('pending', $scenario['order']->payments->sole()->status);
        $this->assertSame(1, (int) $scenario['variant']->fresh()->stock_reserved);
        $this->assertSame(5, (int) $scenario['variant']->fresh()->stock_on_hand);
        $this->assertDatabaseMissing('cart_items', ['id' => $scenario['cart_item']->id]);
        $this->assertDatabaseHas('cart_items', ['id' => $scenario['unselected_item']->id]);
        $this->assertSame(1, InventoryMovement::query()->where('type', 'order_reserved')->count());
        $this->assertSame(0, Shipment::query()->count());

        return $scenario;
    }

    private function assertReceiptIsBlockedBeforeDelivery(array $scenario): void
    {
        $this->withToken($scenario['token'])
            ->getJson('/api/mobile/buyer/orders/'.$scenario['order']->id)
            ->assertOk()
            ->assertJsonPath('data.buyer_actions.can_confirm_receipt', false);

        $this->withToken($scenario['token'])
            ->postJson('/api/mobile/buyer/orders/'.$scenario['order']->id.'/confirm-receipt')
            ->assertStatus(409);

        $this->assertSame('processing', $scenario['order']->fresh()->status);
        $this->assertSame('pending', $scenario['order']->payments()->sole()->status);
        $this->assertSame('pending', $scenario['seller_order']->fresh()->status);
        $this->assertNull($scenario['order']->fresh()->completed_at);
        $this->assertNull($scenario['seller_order']->fresh()->completed_at);
        $this->assertSame(0, SellerOrderStatusHistory::query()->count());
    }

    private function deliverLocally(array $scenario): array
    {
        $scenario = $this->receiveAtCenter($scenario);

        $this->actingAs($scenario['logistics_user'])
            ->patch(route('logistics.sorting.parcels.update', $scenario['parcel']), [
                'sorting_zone_id' => $scenario['zone']->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $scenario['parcel']->refresh();
        $scenario['shipment']->refresh();
        $this->assertSame('sorted', $scenario['parcel']->status);
        $this->assertSame($scenario['zone']->id, (int) $scenario['parcel']->current_zone_id);
        $this->assertSame('sorted', $scenario['shipment']->status);
        $this->assertSame($scenario['logistics_profile']->id, (int) $scenario['shipment']->logistics_profile_id);
        $this->assertSame(0, DB::table('shipment_transfers')->where('shipment_id', $scenario['shipment']->id)->count());

        $this->actingAs($scenario['logistics_user'])
            ->post(route('logistics.dispatch.store', $scenario['zone']), [
                'rider_profile_id' => $scenario['delivery_rider']->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $scenario['batch'] = DispatchBatch::query()->with('parcels')->sole();
        $scenario['parcel']->refresh();
        $scenario['shipment']->refresh();
        $this->assertSame('dispatched', $scenario['batch']->status);
        $this->assertSame($scenario['delivery_rider']->id, (int) $scenario['batch']->rider_profile_id);
        $this->assertSame('dispatched', $scenario['parcel']->status);
        $this->assertSame('dispatched', $scenario['shipment']->status);
        $this->assertNotSame($scenario['pickup_rider']->id, $scenario['batch']->rider_profile_id);

        $this->actingAs($scenario['delivery_rider']->user)
            ->post(route('rider.orders.delivery.start', $scenario['shipment']->shipment_no))
            ->assertRedirect();

        $codAmount = number_format(((int) $scenario['shipment']->cod_amount_minor) / 100, 2, '.', '');
        $this->actingAs($scenario['delivery_rider']->user)
            ->post(route('rider.orders.delivery.confirm', $scenario['shipment']->shipment_no), [
                'recipient_name' => $scenario['order']->recipient_name,
                'proof_photo' => $this->fakeProofPhoto('m4e-delivery-proof.png'),
                'cod_collected_amount' => $codAmount,
                'notes' => 'Local COD delivered to the recipient.',
            ])
            ->assertRedirect(route('rider.orders.delivery', $scenario['shipment']->shipment_no))
            ->assertSessionHas('job_status', 'Delivery completed successfully.');

        $scenario['shipment']->refresh();
        $scenario['parcel']->refresh();
        $scenario['batch']->refresh();
        $this->assertSame('delivered', $scenario['shipment']->status);
        $this->assertSame('delivered', $scenario['parcel']->status);
        $this->assertSame($scenario['logistics_profile']->id, (int) $scenario['shipment']->logistics_profile_id);
        $this->assertSame(0, DB::table('shipment_transfers')->where('shipment_id', $scenario['shipment']->id)->count());
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $scenario['shipment']->id,
            'event_type' => 'shipment_dispatched',
            'source' => 'logistics',
        ]);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $scenario['shipment']->id,
            'event_type' => 'shipment_out_for_delivery',
            'source' => 'rider',
        ]);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $scenario['shipment']->id,
            'event_type' => 'shipment_delivered',
            'source' => 'rider',
        ]);

        $attempt = DeliveryAttempt::query()
            ->where('parcel_id', $scenario['parcel']->id)
            ->where('outcome', 'delivered')
            ->sole();
        $proof = $attempt->proofs()->sole();
        $this->assertSame($scenario['order']->recipient_name, $proof->recipient_name);
        $this->assertSame($scenario['delivery_rider']->user_id, (int) $proof->uploaded_by);
        $this->assertNotEmpty($proof->file_path);
        Storage::disk('local')->assertExists($proof->file_path);

        $scenario['collection'] = CodCashCollection::query()
            ->where('shipment_id', $scenario['shipment']->id)
            ->sole();
        $scenario['order']->refresh();
        $scenario['seller_order']->refresh();
        $this->assertSame('collected', $scenario['collection']->status);
        $this->assertSame((int) $scenario['shipment']->cod_amount_minor, (int) $scenario['collection']->amount_collected_minor);
        $this->assertSame('paid', $scenario['order']->payment_status);
        $this->assertSame('paid', $scenario['order']->payments()->sole()->status);
        $this->assertNotNull($scenario['order']->payments()->sole()->paid_at);
        $this->assertSame('handed_to_logistics', $scenario['seller_order']->status);
        $this->assertGreaterThan(0, $this->workflowNotificationCount($scenario['buyer'], 'buyer_shipment_updates'));
        $this->assertGreaterThan(0, $this->workflowNotificationCount($scenario['seller'], 'seller_shipment_updates'));

        return $scenario;
    }

    private function receiveAtCenter(array $scenario): array
    {
        $this->actingAs($scenario['seller'])
            ->patchJson(route('seller.orders.status', $scenario['seller_order']->id), ['action' => 'confirm'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->actingAs($scenario['seller'])
            ->patchJson(route('seller.orders.status', $scenario['seller_order']->id), ['action' => 'start_preparing'])
            ->assertOk()
            ->assertJsonPath('data.status', 'packing');

        $scenario['shipment'] = $scenario['seller_order']->fresh()->shipments()->sole();
        $scenario['waybill'] = Waybill::query()->where('shipment_id', $scenario['shipment']->id)->sole();
        $scenario['parcel'] = Parcel::query()->where('shipment_id', $scenario['shipment']->id)->sole();

        $this->assertSame(1, Shipment::query()->where('seller_order_id', $scenario['seller_order']->id)->count());
        $this->assertSame(1, Waybill::query()->where('shipment_id', $scenario['shipment']->id)->count());
        $this->assertSame(1, Parcel::query()->where('shipment_id', $scenario['shipment']->id)->count());
        $this->assertSame($scenario['logistics_profile']->id, (int) $scenario['shipment']->origin_logistics_profile_id);
        $this->assertSame((int) $scenario['seller_order']->total_minor, (int) $scenario['shipment']->cod_amount_minor);

        $this->actingAs($scenario['seller'])
            ->post(route('seller.fulfillment.waybills.pdf'), ['waybill_ids' => [$scenario['waybill']->id]])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($scenario['seller'])
            ->post(route('seller.fulfillment.waybills.printed', $scenario['waybill']))
            ->assertRedirect(route('seller.fulfillment.waybills'));
        $this->assertNotNull($scenario['waybill']->fresh()->printed_at);

        $statusRoute = route('seller.orders.status', $scenario['seller_order']->id);
        $this->actingAs($scenario['seller'])
            ->patchJson($statusRoute, ['action' => 'mark_ready'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_for_pickup');
        $this->assertSame('ready_for_pickup', $scenario['shipment']->fresh()->status);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $scenario['shipment']->id,
            'event_type' => 'shipment_ready_for_pickup',
            'to_status' => 'ready_for_pickup',
            'source' => 'seller',
        ]);

        $pickupPayload = [
            'order_ids' => [$scenario['seller_order']->id],
            'requested_date' => today()->addDay()->toDateString(),
            'pickup_window' => '10:00-12:00',
            'instructions' => 'Collect the sealed local COD parcel.',
        ];
        $this->actingAs($scenario['seller'])
            ->post(route('seller.fulfillment.pickups.store'), $pickupPayload)
            ->assertRedirect();
        $scenario['pickup'] = PickupRequest::query()->with('parcels')->sole();
        $this->assertSame('requested', $scenario['pickup']->status);
        $this->assertSame($scenario['logistics_profile']->id, (int) $scenario['pickup']->logistics_profile_id);
        $this->assertCount(1, $scenario['pickup']->parcels);

        $this->actingAs($scenario['seller'])
            ->post(route('seller.fulfillment.pickups.store'), $pickupPayload)
            ->assertConflict();
        $this->assertSame(1, PickupRequest::query()->count());

        $this->actingAs($scenario['logistics_user'])
            ->post(route('logistics.pickups.verify', $scenario['pickup']))
            ->assertRedirect();
        $this->actingAs($scenario['logistics_user'])
            ->post(route('logistics.pickups.assign', $scenario['pickup']), [
                'rider_profile_id' => $scenario['pickup_rider']->id,
            ])
            ->assertRedirect();

        $scenario['pickup_assignment'] = PickupAssignment::query()
            ->where('pickup_request_id', $scenario['pickup']->id)
            ->sole();
        $this->assertSame($scenario['pickup_rider']->id, (int) $scenario['pickup_assignment']->rider_profile_id);
        $this->assertSame('scheduled', $scenario['pickup']->fresh()->status);

        $this->actingAs($scenario['pickup_rider']->user)
            ->post(route('rider.orders.pickup.accept', $scenario['pickup']->pickup_no))
            ->assertRedirect();
        $this->actingAs($scenario['pickup_rider']->user)
            ->post(route('rider.orders.pickup.confirm', $scenario['pickup']->pickup_no), [
                'waybill_identifier' => 'WRONG-WAYBILL-'.$scenario['pickup']->pickup_no,
                'scan_method' => 'barcode',
            ])
            ->assertConflict();
        $this->assertSame('accepted', $scenario['pickup_assignment']->fresh()->status);
        $this->assertSame('created', $scenario['parcel']->fresh()->status);

        $this->actingAs($scenario['pickup_rider']->user)
            ->post(route('rider.orders.pickup.confirm', $scenario['pickup']->pickup_no), [
                'waybill_identifier' => $scenario['waybill']->waybill_no,
                'scan_method' => 'barcode',
            ])
            ->assertRedirect(route('rider.orders.pickup', $scenario['pickup']->pickup_no));
        $this->assertSame('picked_up', $scenario['pickup_assignment']->fresh()->status);
        $this->assertSame('scheduled', $scenario['pickup']->fresh()->status);
        $this->assertSame('picked_up', $scenario['parcel']->fresh()->status);
        $this->assertSame('picked_up', $scenario['shipment']->fresh()->status);

        $this->actingAs($scenario['logistics_user'])
            ->postJson(route('logistics.waybills.receive', $scenario['waybill']->waybill_no), [
                'sorting_center_id' => $scenario['center']->id,
                'scan_method' => 'manual',
            ])
            ->assertOk();

        $scenario['parcel']->refresh();
        $scenario['shipment']->refresh();
        $scenario['pickup']->refresh();
        $scenario['pickup_assignment']->refresh();
        $this->assertSame('received', $scenario['parcel']->status);
        $this->assertSame($scenario['center']->id, (int) $scenario['parcel']->current_sorting_center_id);
        $this->assertSame('at_sorting_center', $scenario['shipment']->status);
        $this->assertSame('completed', $scenario['pickup']->status);
        $this->assertNotNull($scenario['pickup']->completed_at);
        $this->assertSame('completed', $scenario['pickup_assignment']->status);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $scenario['shipment']->id,
            'parcel_id' => $scenario['parcel']->id,
            'event_type' => 'received_at_center',
            'to_status' => 'received',
            'sorting_center_id' => $scenario['center']->id,
            'source' => 'scan',
        ]);

        return $scenario;
    }

    private function reconcileCashWithFinance(array $scenario, CodCashCollection $collection): void
    {
        $amount = number_format(((int) $collection->amount_collected_minor) / 100, 2, '.', '');
        $wrongAmount = number_format((((int) $collection->amount_collected_minor) + 100) / 100, 2, '.', '');

        $this->actingAs($scenario['seller'])
            ->post(route('logistics.cash.cod.receive', $collection), ['actual_amount' => $amount])
            ->assertForbidden();
        $this->actingAs($scenario['logistics_user'])
            ->post(route('logistics.cash.cod.receive', $collection), ['actual_amount' => $wrongAmount])
            ->assertSessionHasErrors('received_amount');
        $this->assertSame('collected', $collection->fresh()->status);

        $this->actingAs($scenario['logistics_user'])
            ->post(route('logistics.cash.cod.receive', $collection), ['actual_amount' => $amount])
            ->assertRedirect();
        $collection->refresh();
        $this->assertSame('received_by_logistics', $collection->status);
        $this->assertSame((int) $collection->amount_collected_minor, (int) $collection->amount_received_by_logistics_minor);
        $this->assertNotEmpty($collection->logistics_reference);
        $this->assertSame(1, $collection->events()->where('event_type', 'cash_received_by_logistics')->count());

        $this->actingAs($scenario['logistics_user'])
            ->post(route('logistics.cash.cod.receive', $collection), ['actual_amount' => $amount])
            ->assertRedirect();
        $this->assertSame(1, $collection->fresh()->events()->where('event_type', 'cash_received_by_logistics')->count());

        $this->actingAs($scenario['logistics_user'])
            ->post(route('logistics.cash.cod.remit', $collection), ['actual_amount' => $wrongAmount])
            ->assertSessionHasErrors('amount');
        $this->assertSame('received_by_logistics', $collection->fresh()->status);

        $this->actingAs($scenario['logistics_user'])
            ->post(route('logistics.cash.cod.remit', $collection), ['actual_amount' => $amount])
            ->assertRedirect();
        $collection->refresh();
        $this->assertSame('remitted_to_finance', $collection->status);
        $this->assertSame((int) $collection->amount_collected_minor, (int) $collection->amount_remitted_to_finance_minor);
        $this->assertNotEmpty($collection->finance_remittance_reference);
        $this->assertSame(1, $collection->events()->where('event_type', 'cash_remitted_to_finance')->count());

        $this->actingAs($scenario['logistics_user'])
            ->post(route('admin.payments.cod.reconcile', $collection), [
                'received_amount' => $amount,
                'cash_received_confirmed' => '1',
            ])
            ->assertForbidden();
        $this->actingAs($scenario['admin'])
            ->post(route('admin.payments.cod.reconcile', $collection), [
                'received_amount' => $wrongAmount,
                'cash_received_confirmed' => '1',
            ])
            ->assertSessionHasErrors('received_amount');
        $this->assertSame('remitted_to_finance', $collection->fresh()->status);

        $this->actingAs($scenario['admin'])
            ->post(route('admin.payments.cod.reconcile', $collection), [
                'received_amount' => $amount,
                'cash_received_confirmed' => '1',
            ])
            ->assertRedirect();
        $collection->refresh();
        $this->assertSame('reconciled', $collection->status);
        $this->assertSame((int) $collection->amount_collected_minor, (int) $collection->amount_reconciled_minor);
        $this->assertNotEmpty($collection->finance_reference);
        $this->assertMatchesRegularExpression('/^BFR-\d{4}-\d{8,}$/', $collection->finance_reference);
        $this->assertSame(1, $collection->events()->where('event_type', 'cash_reconciled_by_finance')->count());

        $financeReference = $collection->finance_reference;
        $this->actingAs($scenario['admin'])
            ->post(route('admin.payments.cod.reconcile', $collection), [
                'received_amount' => $amount,
                'cash_received_confirmed' => '1',
            ])
            ->assertRedirect();
        $this->assertSame($financeReference, $collection->fresh()->finance_reference);
        $this->assertSame(1, $collection->fresh()->events()->where('event_type', 'cash_reconciled_by_finance')->count());
        $this->assertSame(1, PlatformCommission::query()
            ->where('seller_order_id', $collection->seller_order_id)
            ->where('type', 'sale')
            ->count());
    }

    private function actor(string $role, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => 'm4e1a-'.$role.'-'.Str::lower(Str::random(14)).'@example.test',
            'role' => $role,
            'status' => 'active',
        ]);
        $roleId = Role::query()->where('name', $role)->value('id');
        if ($roleId) {
            $user->roles()->syncWithoutDetaching([$roleId]);
        }

        return $user;
    }

    private function address(User $user, string $label, bool $shipping, bool $pickup): Address
    {
        $cityCode = (string) app(PostalCodeLookup::class)->canonicalCityCode(null, 'Laguna', 'Santa Rosa');

        return Address::query()->create([
            'user_id' => $user->id,
            'label' => $label,
            'recipient_name' => $user->name,
            'phone' => '+639171234567',
            'street' => '10 M4E Market Street',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
            'psgc_province_code' => '0434',
            'psgc_city_code' => $cityCode,
            'is_default_shipping' => $shipping,
            'is_default_pickup' => $pickup,
        ]);
    }

    private function rider(
        User $logisticsUser,
        LogisticsProfile $profile,
        SortingCenter $center,
        SortingZone $zone,
        string $label,
    ): RiderProfile {
        $rider = $this->actor('rider', 'M4E '.$label);
        $rider->update(['logistics_id' => $logisticsUser->id]);

        return RiderProfile::query()->create([
            'user_id' => $rider->id,
            'logistics_profile_id' => $profile->id,
            'home_sorting_center_id' => $center->id,
            'current_zone_id' => $zone->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'M4E-'.Str::upper(Str::random(8)),
            'parcel_capacity' => 5,
            'availability_status' => 'available',
            'verification_status' => 'approved',
        ]);
    }

    private function workflowNotificationCount(User $recipient, string $eventKey): int
    {
        return Notification::sent($recipient, WorkflowNotification::class)
            ->filter(fn (WorkflowNotification $notification): bool => $notification->eventKey() === $eventKey)
            ->count();
    }

    private function fakeProofPhoto(string $name): UploadedFile
    {
        $contents = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );

        if ($contents === false) {
            throw new RuntimeException('Unable to create the isolated delivery proof fixture.');
        }

        return UploadedFile::fake()->createWithContent($name, $contents);
    }

}
