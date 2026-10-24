<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\SellerOrder;
use App\Models\SellerOrderStatusHistory;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use App\Services\CodCashCollectionService;
use App\Services\NotificationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class MobileBuyerConfirmReceiptApiTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_buyer_can_confirm_and_replay_without_rewriting_timestamp_or_repeating_effects(): void
    {
        $buyer = $this->user(UserRole::Buyer, 'confirm');
        [$seller, $store] = $this->seller();
        $provider = $this->provider();
        $order = $this->marketplaceOrder($buyer, 'confirm');
        $sellerOrder = $this->sellerOrder($order, $store, 'handed_to_logistics');
        $shipment = $this->shipment($sellerOrder, $provider, 'delivered');
        $this->collectCod($shipment, $provider);

        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('sendAfterCommit')
            ->once()
            ->withArgs(fn (User $recipient, WorkflowNotification $notice): bool => $recipient->is($seller)
                && $notice->eventKey() === 'seller_shipment_updates');
        $this->app->instance(NotificationService::class, $notifications);

        $route = route('mobile.buyer.orders.confirm-receipt', $order->id);
        $first = $this->actingAs($buyer)->postJson($route)->assertOk()
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.order_reference', $order->order_no)
            ->assertJsonPath('data.status', 'fulfilled')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.receipt_confirmed', true)
            ->assertJsonPath('data.already_confirmed', false)
            ->assertJsonPath('data.seller_orders.0.status', 'completed');

        $originalCompletedAt = $first->json('data.completed_at');
        $this->assertNotEmpty($originalCompletedAt);
        $this->assertSame('fulfilled', $order->fresh()->status);
        $this->assertSame('completed', $sellerOrder->fresh()->status);
        $this->assertSame(1, $sellerOrder->statusHistory()->count());
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame('delivered', $shipment->fresh()->status);

        $this->travel(2)->hours();
        $this->actingAs($buyer)->postJson($route)->assertOk()
            ->assertJsonPath('data.completed_at', $originalCompletedAt)
            ->assertJsonPath('data.already_confirmed', true)
            ->assertJsonPath('message', 'Order receipt was already confirmed.');

        $this->assertSame($originalCompletedAt, Carbon::parse($order->fresh()->completed_at)->toIso8601String());
        $this->assertSame(1, $sellerOrder->statusHistory()->count());
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame('delivered', $shipment->fresh()->status);
    }

    public function test_successful_multi_seller_confirmation_completes_every_active_seller_order(): void
    {
        $buyer = $this->user(UserRole::Buyer, 'multi');
        [, $firstStore] = $this->seller();
        [, $secondStore] = $this->seller();
        $provider = $this->provider();
        $order = $this->marketplaceOrder($buyer, 'multi');
        $firstSellerOrder = $this->sellerOrder($order, $firstStore, 'handed_to_logistics', 10000);
        $secondSellerOrder = $this->sellerOrder($order, $secondStore, 'handed_to_logistics', 10000);
        $firstShipment = $this->shipment($firstSellerOrder, $provider, 'delivered');
        $secondShipment = $this->shipment($secondSellerOrder, $provider, 'delivered');
        $this->collectCod($firstShipment, $provider);
        $this->collectCod($secondShipment, $provider);

        $response = $this->actingAs($buyer)
            ->postJson(route('mobile.buyer.orders.confirm-receipt', $order->id))
            ->assertOk()
            ->assertJsonPath('data.receipt_confirmed', true);

        $this->assertCount(2, $response->json('data.seller_orders'));
        $this->assertSame('completed', $firstSellerOrder->fresh()->status);
        $this->assertSame('completed', $secondSellerOrder->fresh()->status);
        $this->assertSame(1, $firstSellerOrder->statusHistory()->count());
        $this->assertSame(1, $secondSellerOrder->statusHistory()->count());
    }

    public function test_authentication_role_ownership_and_numeric_order_routes_are_enforced(): void
    {
        $owner = $this->user(UserRole::Buyer, 'owner');
        $otherBuyer = $this->user(UserRole::Buyer, 'other');
        [, $store] = $this->seller();
        $order = $this->marketplaceOrder($owner, 'private');
        $this->sellerOrder($order, $store, 'handed_to_logistics');
        $route = route('mobile.buyer.orders.confirm-receipt', $order->id);

        $this->postJson($route)->assertUnauthorized();
        $this->actingAs($otherBuyer)->postJson($route)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'order_not_found');
        $this->actingAs($owner)->postJson('/api/mobile/buyer/orders/not-numeric/confirm-receipt')
            ->assertNotFound();

        foreach ([UserRole::Rider, UserRole::Seller, UserRole::Logistics, UserRole::Admin] as $role) {
            $this->actingAs($this->user($role, strtolower($role->value)))
                ->postJson($route)
                ->assertForbidden();
        }

        $inactiveBuyer = $this->user(UserRole::Buyer, 'inactive', 'suspended');
        $this->actingAs($inactiveBuyer)->postJson($route)->assertForbidden();

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_undelivered_seller_order_is_rejected_without_mutation(): void
    {
        $buyer = $this->user(UserRole::Buyer, 'transit');
        [, $store] = $this->seller();
        $provider = $this->provider();
        $order = $this->marketplaceOrder($buyer, 'transit');
        $order->payments()->update(['method' => 'card']);
        $sellerOrder = $this->sellerOrder($order, $store, 'ready_for_pickup');
        $shipment = $this->shipment($sellerOrder, $provider, 'out_for_delivery');

        $this->actingAs($buyer)
            ->postJson(route('mobile.buyer.orders.confirm-receipt', $order->id))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'order_not_fully_delivered');

        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame('ready_for_pickup', $sellerOrder->fresh()->status);
        $this->assertNull($order->fresh()->completed_at);
        $this->assertSame(0, SellerOrderStatusHistory::query()->count());
        $this->assertSame('out_for_delivery', $shipment->fresh()->status);
    }

    public function test_cod_requires_capture_and_all_required_rider_cash_collection_evidence(): void
    {
        $buyer = $this->user(UserRole::Buyer, 'cash-proof');
        [, $store] = $this->seller();
        $provider = $this->provider();
        $order = $this->marketplaceOrder($buyer, 'cash-proof');
        $sellerOrder = $this->sellerOrder($order, $store, 'handed_to_logistics');
        $this->shipment($sellerOrder, $provider, 'delivered');

        $this->actingAs($buyer)
            ->postJson(route('mobile.buyer.orders.confirm-receipt', $order->id))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'cod_cash_collection_incomplete');

        $this->assertSame('pending', $order->payments()->sole()->status);
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame('handed_to_logistics', $sellerOrder->fresh()->status);
        $this->assertSame(0, SellerOrderStatusHistory::query()->count());
    }

    public function test_cancelled_or_inactive_orders_are_rejected(): void
    {
        $buyer = $this->user(UserRole::Buyer, 'blocked');
        [, $store] = $this->seller();
        $order = $this->marketplaceOrder($buyer, 'blocked');
        $order->payments()->update(['method' => 'card']);
        $sellerOrder = $this->sellerOrder($order, $store, 'cancelled');

        $this->actingAs($buyer)
            ->postJson(route('mobile.buyer.orders.confirm-receipt', $order->id))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'no_active_seller_orders');

        $sellerOrder->update(['status' => 'handed_to_logistics']);
        $order->update(['status' => 'cancelled']);
        $this->actingAs($buyer)
            ->postJson(route('mobile.buyer.orders.confirm-receipt', $order->id))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'order_cancelled');

        $this->assertSame('handed_to_logistics', $sellerOrder->fresh()->status);
        $this->assertNull($order->fresh()->completed_at);
    }

    public function test_historical_completed_order_keeps_its_timestamp_on_replay(): void
    {
        $buyer = $this->user(UserRole::Buyer, 'legacy');
        [, $store] = $this->seller();
        $provider = $this->provider();
        $order = $this->marketplaceOrder($buyer, 'legacy');
        $sellerOrder = $this->sellerOrder($order, $store, 'completed');
        $shipment = $this->shipment($sellerOrder, $provider, 'delivered');
        $this->collectCod($shipment, $provider);

        $originalCompletedAt = '2024-01-02 03:04:05';
        $sellerOrder->update(['completed_at' => $originalCompletedAt]);
        $order->update([
            'status' => 'fulfilled',
            'payment_status' => 'paid',
            'completed_at' => $originalCompletedAt,
        ]);
        SellerOrderStatusHistory::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'from_status' => 'handed_to_logistics',
            'to_status' => 'completed',
            'actor_user_id' => $buyer->id,
            'reason' => 'Legacy completion',
            'occurred_at' => $originalCompletedAt,
        ]);

        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldNotReceive('sendAfterCommit');
        $this->app->instance(NotificationService::class, $notifications);

        $this->actingAs($buyer)
            ->postJson(route('mobile.buyer.orders.confirm-receipt', $order->id))
            ->assertOk()
            ->assertJsonPath('data.already_confirmed', true)
            ->assertJsonPath('data.completed_at', '2024-01-02T03:04:05+00:00');

        $this->assertSame('2024-01-02 03:04:05', $order->fresh()->completed_at);
        $this->assertSame(1, $sellerOrder->statusHistory()->count());
        $this->assertSame(1, $order->payments()->count());
    }

    public function test_unexpected_failure_rolls_back_partial_confirmation_changes(): void
    {
        $buyer = $this->user(UserRole::Buyer, 'rollback');
        [, $store] = $this->seller();
        $provider = $this->provider();
        $order = $this->marketplaceOrder($buyer, 'rollback');
        $sellerOrder = $this->sellerOrder($order, $store, 'handed_to_logistics');
        $shipment = $this->shipment($sellerOrder, $provider, 'delivered');
        $this->collectCod($shipment, $provider);
        $originalPaymentStatus = $order->fresh()->payment_status;

        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('sendAfterCommit')
            ->once()
            ->andThrow(new \RuntimeException('test-only downstream failure'));
        $this->app->instance(NotificationService::class, $notifications);

        $this->actingAs($buyer)
            ->postJson(route('mobile.buyer.orders.confirm-receipt', $order->id))
            ->assertServerError()
            ->assertJsonPath('error.code', 'receipt_confirmation_failed');

        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame($originalPaymentStatus, $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->completed_at);
        $this->assertSame('handed_to_logistics', $sellerOrder->fresh()->status);
        $this->assertNull($sellerOrder->fresh()->completed_at);
        $this->assertSame(0, $sellerOrder->statusHistory()->count());
    }

    private function user(UserRole $role, string $suffix, string $status = 'active'): User
    {
        $user = User::factory()->create([
            'name' => ucfirst($role->value).' '.(++$this->sequence),
            'email' => 'mobile-confirm-'.$role->value.'-'.Str::slug($suffix).'-'.$this->sequence.'@example.test',
            'role' => $role->value,
            'status' => $status,
        ]);
        $roleId = Role::query()->where('name', $role->value)->value('id');
        if ($roleId) {
            $user->roles()->syncWithoutDetaching([$roleId]);
        }

        return $user;
    }

    /** @return array{0: User, 1: Store} */
    private function seller(): array
    {
        $user = $this->user(UserRole::Seller, 'store');
        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Pickup',
            'recipient_name' => $user->name,
            'phone' => '+639171234567',
            'house_number' => '12',
            'street' => 'Market Street',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
            'is_default_pickup' => true,
        ]);
        $profile = SellerProfile::query()->create([
            'user_id' => $user->id,
            'legal_business_name' => 'Test Seller Business '.$user->id,
            'pickup_address_id' => $address->id,
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => 'Test Seller Store '.$user->id,
            'slug' => 'mobile-confirm-store-'.$user->id,
            'contact_phone' => '+639171234567',
            'publication_status' => 'published',
            'published_at' => now(),
        ]);

        return [$user, $store];
    }

    /** @return array{user: User, profile: LogisticsProfile} */
    private function provider(): array
    {
        $user = $this->user(UserRole::Logistics, 'provider');
        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => 'Test Logistics '.$user->id,
            'display_name' => 'Test Logistics',
            'contact_phone' => '+639171234567',
            'status' => 'active',
        ]);

        return compact('user', 'profile');
    }

    private function marketplaceOrder(User $buyer, string $suffix): Order
    {
        $address = Address::query()->create([
            'user_id' => $buyer->id,
            'label' => 'Home',
            'recipient_name' => $buyer->name,
            'phone' => '+639171234568',
            'house_number' => '20',
            'street' => 'Buyer Street',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
        ]);
        $order = Order::query()->create([
            'order_no' => 'BRY-'.Str::upper(Str::random(12)),
            'buyer_id' => $buyer->id,
            'shipping_address_id' => $address->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => $address->phone,
            'address_line' => '20 Buyer Street',
            'barangay' => $address->barangay,
            'city_municipality' => $address->city_municipality,
            'province' => $address->province,
            'postal_code' => $address->postal_code,
            'currency' => 'PHP',
            'subtotal_minor' => 16600,
            'shipping_fee_minor' => 3400,
            'discount_minor' => 0,
            'total_minor' => 20000,
            'status' => 'processing',
            'payment_status' => 'unpaid',
            'placed_at' => now(),
        ]);
        Payment::query()->create([
            'payment_no' => 'PAY-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'method' => 'cod',
            'amount_minor' => 20000,
            'status' => 'pending',
            'initiated_at' => now(),
        ]);

        return $order;
    }

    private function sellerOrder(Order $order, Store $store, string $status, int $totalMinor = 20000): SellerOrder
    {
        return SellerOrder::query()->create([
            'seller_order_no' => 'SO-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => $status,
            'subtotal_minor' => $totalMinor,
            'discount_minor' => 0,
            'shipping_fee_minor' => 0,
            'total_minor' => $totalMinor,
            'ready_at' => now(),
        ]);
    }

    /** @param array{user: User, profile: LogisticsProfile} $provider */
    private function shipment(SellerOrder $sellerOrder, array $provider, string $status): Shipment
    {
        return Shipment::query()->create([
            'shipment_no' => 'MOBILE-CONFIRM-'.Str::upper(Str::random(12)),
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider['profile']->id,
            'purpose' => 'outbound',
            'status' => $status,
            'cod_amount_minor' => (int) $sellerOrder->total_minor,
        ]);
    }

    /** @param array{user: User, profile: LogisticsProfile} $provider */
    private function collectCod(Shipment $shipment, array $provider): void
    {
        $rider = $this->user(UserRole::Rider, 'cash-collector');
        RiderProfile::query()->create([
            'user_id' => $rider->id,
            'logistics_profile_id' => $provider['profile']->id,
            'vehicle_type' => 'motorcycle',
            'availability_status' => 'available',
            'verification_status' => 'approved',
        ]);

        app(CodCashCollectionService::class)->recordRiderCollection(
            $shipment,
            $rider,
            (int) $shipment->cod_amount_minor,
        );
    }
}
