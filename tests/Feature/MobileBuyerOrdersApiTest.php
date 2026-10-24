<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\DeliveryAttempt;
use App\Models\InventoryMovement;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Payment;
use App\Models\PickupAssignment;
use App\Models\PickupRequest;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\SellerOrder;
use App\Models\SellerOrderStatusHistory;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileBuyerOrdersApiTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_order_history_is_owned_paginated_and_sorted_newest_first(): void
    {
        $buyer = $this->buyer('history');
        $otherBuyer = $this->buyer('other-history');
        $store = $this->store('history');
        $old = $this->order($buyer, 'old');
        $new = $this->order($buyer, 'new');
        $foreign = $this->order($otherBuyer, 'foreign');
        $this->sellerOrder($old, $store, 'old');
        $this->sellerOrder($new, $store, 'new');
        $this->sellerOrder($foreign, $store, 'foreign');
        $old->update(['created_at' => Carbon::parse('2026-10-01 10:00:00')]);
        $new->update(['created_at' => Carbon::parse('2026-10-02 10:00:00')]);

        $response = $this->mobile($buyer)
            ->getJson('/api/mobile/buyer/orders?per_page=1')
            ->assertOk()
            ->assertJsonPath('message', 'Orders retrieved successfully.')
            ->assertJsonPath('data.0.id', $new->id)
            ->assertJsonPath('data.0.order_reference', $new->order_no)
            ->assertJsonPath('data.0.total_minor', 20000)
            ->assertJsonPath('data.0.currency', 'PHP')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.cod_payment.status', 'pending');

        $this->assertNotSame($foreign->id, $response->json('data.0.id'));
        $this->assertNotEmpty($response->json('data.0.placed_at'));
        $this->assertNotNull($response->json('links.next'));
        $this->mobile($buyer)
            ->getJson('/api/mobile/buyer/orders?per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('data.0.id', $old->id);

        $otherBuyerResponse = $this->mobile($otherBuyer)
            ->getJson('/api/mobile/buyer/orders')
            ->assertOk();
        $this->assertSame(1, $otherBuyerResponse->json('meta.total'));
        $this->assertSame($foreign->id, $otherBuyerResponse->json('data.0.id'));

        $emptyBuyer = $this->buyer('empty-history');
        $this->mobile($emptyBuyer)
            ->getJson('/api/mobile/buyer/orders')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_order_details_return_only_purchase_snapshots_and_separate_seller_groups(): void
    {
        $buyer = $this->buyer('detail');
        $firstStore = $this->store('detail-first');
        $secondStore = $this->store('detail-second');
        $order = $this->order($buyer, 'detail');
        $order->update([
            'platform_discount_minor' => 500,
            'campaign_platform_discount_minor' => 250,
            'platform_shipping_discount_minor' => 100,
        ]);
        $firstSellerOrder = $this->sellerOrder($order, $firstStore, 'detail-first', 'cancelled');
        $firstSellerOrder->items()->firstOrFail()->update([
            'product_name' => 'Historical Bear Plush',
            'variant_name' => 'Honey / Large',
            'options' => ['Color' => 'Honey', 'Size' => 'Large'],
            'product_image_disk' => 'public',
            'product_image_path' => 'orders/snapshot-bear.png',
            'discount_minor' => 100,
            'platform_discount_minor' => 200,
            'campaign_platform_discount_minor' => 50,
        ]);
        $secondSellerOrder = $this->sellerOrder($order, $secondStore, 'detail-second', 'pending');
        $secondSellerOrder->items()->firstOrFail()->update([
            'product_name' => 'Private image snapshot',
            'product_image_disk' => 'local',
            'product_image_path' => 'private/buyer-document.png',
        ]);
        $this->orderAddress($buyer)->update(['street' => 'Changed Street']);

        $data = $this->mobile($buyer)
            ->getJson("/api/mobile/buyer/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.cod_payment.status', 'pending')
            ->assertJsonPath('data.platform_discount_minor', 500)
            ->assertJsonPath('data.campaign_platform_discount_minor', 250)
            ->assertJsonPath('data.delivery_address.address_line', '20 Buyer Street')
            ->assertJsonPath('data.seller_orders.0.id', $firstSellerOrder->id)
            ->assertJsonPath('data.seller_orders.0.status', 'cancelled')
            ->assertJsonPath('data.seller_orders.0.items.0.product_name', 'Historical Bear Plush')
            ->assertJsonPath('data.seller_orders.0.items.0.variant_name', 'Honey / Large')
            ->assertJsonPath('data.seller_orders.0.items.0.quantity', 1)
            ->assertJsonPath('data.seller_orders.0.items.0.unit_price_minor', 16600)
            ->assertJsonPath('data.seller_orders.0.items.0.total_minor', 16250)
            ->assertJsonPath('data.seller_orders.1.id', $secondSellerOrder->id)
            ->assertJsonPath('data.seller_orders.1.items.0.product_image_url', null)
            ->assertJsonMissing(['buyer_id' => $buyer->id])
            ->assertJsonMissing(['checkout_request_fingerprint' => 'sensitive']);

        $this->assertStringContainsString('orders/snapshot-bear.png', $data->json('data.seller_orders.0.items.0.product_image_url'));
        $this->assertSame('20 Buyer Street', $data->json('data.delivery_address.address_line'));
        $this->assertFalse($data->json('data.buyer_actions.can_confirm_receipt'));
        $this->assertFalse($data->json('data.buyer_actions.receipt_confirmed'));
        $this->assertSame('pending', $data->json('data.payments.0.status'));
    }

    public function test_tracking_is_empty_until_outbound_shipments_exist_and_hides_non_owned_orders(): void
    {
        $buyer = $this->buyer('pending-tracking');
        $otherBuyer = $this->buyer('foreign-tracking');
        $store = $this->store('pending-tracking');
        $order = $this->order($buyer, 'pending-tracking');
        $this->sellerOrder($order, $store, 'pending-tracking');
        $foreign = $this->order($otherBuyer, 'foreign-tracking');

        $this->mobile($buyer)
            ->getJson("/api/mobile/buyer/orders/{$order->id}/tracking")
            ->assertOk()
            ->assertJsonPath('data.tracking_state', 'pending')
            ->assertJsonPath('data.shipment_count', 0)
            ->assertJsonPath('data.seller_orders.0.shipments', []);

        $this->mobile($buyer)->getJson("/api/mobile/buyer/orders/{$foreign->id}")->assertNotFound();
        $this->mobile($buyer)->getJson("/api/mobile/buyer/orders/{$foreign->id}/tracking")->assertNotFound();
        $this->mobile($buyer)->getJson('/api/mobile/buyer/orders/999999')->assertNotFound();
    }

    public function test_tracking_uses_persisted_seller_and_shipment_events_without_leaking_internal_fields(): void
    {
        $buyer = $this->buyer('tracking-events');
        $firstStore = $this->store('tracking-first');
        $secondStore = $this->store('tracking-second');
        $provider = $this->provider('tracking-events');
        $order = $this->order($buyer, 'tracking-events');
        $firstSellerOrder = $this->sellerOrder($order, $firstStore, 'tracking-first', 'handed_to_logistics');
        $secondSellerOrder = $this->sellerOrder($order, $secondStore, 'tracking-second', 'cancelled');
        $shipment = $this->shipment($firstSellerOrder, $provider, 'tracking-events', 'delivered');

        SellerOrderStatusHistory::query()->create([
            'seller_order_id' => $firstSellerOrder->id,
            'from_status' => 'ready_for_pickup',
            'to_status' => 'handed_to_logistics',
            'actor_user_id' => $provider['user']->id,
            'reason' => 'Restricted staff note not for buyer display.',
            'occurred_at' => Carbon::parse('2026-10-22 09:00:00'),
        ]);
        ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'event_type' => 'received_at_center',
            'from_status' => 'picked_up',
            'to_status' => 'at_sorting_center',
            'source' => 'logistics',
            'notes' => 'Private routing note',
            'metadata' => ['logistics_profile_id' => 12345, 'credential' => 'do-not-send'],
            'occurred_at' => Carbon::parse('2026-10-22 10:00:00'),
        ]);
        ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'event_type' => 'shipment_delivered',
            'from_status' => 'out_for_delivery',
            'to_status' => 'delivered',
            'source' => 'rider',
            'notes' => 'Private recipient note',
            'occurred_at' => Carbon::parse('2026-10-23 11:00:00'),
        ]);

        $tracking = $this->mobile($buyer)
            ->getJson("/api/mobile/buyer/orders/{$order->id}/tracking")
            ->assertOk()
            ->assertJsonPath('data.tracking_state', 'available')
            ->assertJsonPath('data.order_status', 'processing')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.buyer_receipt_confirmed', false)
            ->assertJsonPath('data.seller_orders.0.status', 'handed_to_logistics')
            ->assertJsonPath('data.seller_orders.0.shipments.0.status', 'delivered')
            ->assertJsonPath('data.seller_orders.0.shipments.0.events.0.event_type', 'received_at_center')
            ->assertJsonPath('data.seller_orders.0.shipments.0.events.1.event_type', 'shipment_delivered')
            ->assertJsonPath('data.seller_orders.1.shipments', [])
            ->assertJsonMissing(['Restricted staff note not for buyer display.'])
            ->assertJsonMissing(['Private routing note'])
            ->assertJsonMissing(['Private recipient note'])
            ->assertJsonMissing(['do-not-send'])
            ->assertJsonMissingPath('data.seller_orders.0.shipments.0.events.0.metadata');

        $this->assertNotEmpty($tracking->json('data.seller_orders.0.status_history.0.occurred_at'));
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame('handed_to_logistics', $firstSellerOrder->fresh()->status);
        $this->assertSame('delivered', $shipment->fresh()->status);
    }

    public function test_tracking_keeps_rider_pickup_and_delivery_attempts_separate_and_private(): void
    {
        $buyer = $this->buyer('rider-milestones');
        $store = $this->store('rider-milestones');
        $provider = $this->provider('rider-milestones');
        $order = $this->order($buyer, 'rider-milestones');
        $sellerOrder = $this->sellerOrder($order, $store, 'rider-milestones', 'handed_to_logistics');
        $shipment = $this->shipment($sellerOrder, $provider, 'rider-milestones', 'delivered');
        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => 'WB-'.Str::upper(Str::random(12)),
            'scan_token' => hash('sha256', Str::random(32)),
            'barcode_value' => 'BAR-'.Str::upper(Str::random(12)),
            'piece_count' => 1,
            'total_weight_kg' => 1.25,
            'generated_at' => now(),
        ]);
        $parcel = Parcel::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_id' => $waybill->id,
            'parcel_no' => 'PAR-'.Str::upper(Str::random(12)),
            'piece_sequence' => 1,
            'weight_kg' => 1.25,
            'status' => 'delivered',
        ]);
        $pickup = PickupRequest::query()->create([
            'pickup_no' => 'PICK-'.Str::upper(Str::random(12)),
            'store_id' => $store->id,
            'logistics_profile_id' => $provider['profile']->id,
            'pickup_address_id' => $store->sellerProfile->pickup_address_id,
            'status' => 'completed',
            'requested_date' => today(),
            'window_start' => now()->subHours(3),
            'window_end' => now()->subHours(2),
            'completed_at' => now()->subHours(2),
            'seller_instructions' => 'Private seller pickup instruction.',
        ]);
        $pickup->parcels()->attach($parcel->id);
        $rider = $this->userWithRole('rider', 'active', 'milestone-rider');
        $riderProfile = RiderProfile::query()->create([
            'user_id' => $rider->id,
            'logistics_profile_id' => $provider['profile']->id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'PRIVATE-PLATE',
            'availability_status' => 'available',
            'verification_status' => 'approved',
        ]);
        PickupAssignment::query()->create([
            'pickup_request_id' => $pickup->id,
            'rider_profile_id' => $riderProfile->id,
            'status' => 'picked_up',
            'assigned_at' => now()->subHours(3),
            'accepted_at' => now()->subHours(3)->addMinutes(5),
            'arrived_at' => now()->subHours(3)->addMinutes(20),
            'picked_up_at' => now()->subHours(3)->addMinutes(30),
            'notes' => 'Private Rider contact and pickup note.',
        ]);
        DeliveryAttempt::query()->create([
            'parcel_id' => $parcel->id,
            'rider_profile_id' => $riderProfile->id,
            'attempt_no' => 1,
            'outcome' => 'delivered',
            'notes' => 'Private recipient signature details.',
            'latitude' => 14.1234567,
            'longitude' => 121.1234567,
            'attempted_at' => now()->subHour(),
        ]);

        $response = $this->mobile($buyer)
            ->getJson("/api/mobile/buyer/orders/{$order->id}/tracking")
            ->assertOk()
            ->assertJsonPath('data.seller_orders.0.shipments.0.waybill_reference', $waybill->waybill_no)
            ->assertJsonPath('data.seller_orders.0.shipments.0.pickup_assignments.0.status', 'picked_up')
            ->assertJsonPath('data.seller_orders.0.shipments.0.delivery_attempts.0.outcome', 'delivered')
            ->assertJsonMissing(['Private seller pickup instruction.'])
            ->assertJsonMissing(['Private Rider contact and pickup note.'])
            ->assertJsonMissing(['Private recipient signature details.'])
            ->assertJsonMissing(['PRIVATE-PLATE'])
            ->assertJsonMissing(['latitude' => 14.1234567])
            ->assertJsonMissing(['longitude' => 121.1234567])
            ->assertJsonMissingPath('data.seller_orders.0.shipments.0.pickup_assignments.0.rider_profile_id');

        $pickupAt = $response->json('data.seller_orders.0.shipments.0.pickup_assignments.0.picked_up_at');
        $deliveredAt = $response->json('data.seller_orders.0.shipments.0.delivery_attempts.0.attempted_at');
        $this->assertNotSame($pickupAt, $deliveredAt);
        $this->assertFalse($response->json('data.buyer_receipt_confirmed'));
    }

    public function test_return_and_refund_states_are_reported_without_relabeling_order_completion(): void
    {
        $buyer = $this->buyer('return-refund');
        $store = $this->store('return-refund');
        $order = $this->order($buyer, 'return-refund');
        $order->update(['status' => 'fulfilled', 'payment_status' => 'paid', 'completed_at' => now()]);
        $order->payments()->update(['status' => 'paid', 'paid_at' => now()]);
        $sellerOrder = $this->sellerOrder($order, $store, 'return-refund', 'completed');
        $sellerOrder->update(['completed_at' => now()]);
        $return = ReturnRequest::query()->create([
            'return_no' => 'RET-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
            'buyer_id' => $buyer->id,
            'store_id' => $store->id,
            'request_type' => 'refund_only',
            'reason_code' => 'damaged',
            'status' => 'refund_pending',
            'requested_amount_minor' => 5000,
            'submitted_at' => now()->subDay(),
        ]);
        Refund::query()->create([
            'refund_no' => 'RF-'.Str::upper(Str::random(12)),
            'return_request_id' => $return->id,
            'payment_id' => $order->payments()->sole()->id,
            'order_id' => $order->id,
            'amount_minor' => 4000,
            'status' => 'completed',
            'method' => 'cash',
            'provider_reference' => 'private-refund-provider-reference',
            'failure_reason' => 'Internal refund reconciliation note.',
            'completed_at' => now(),
        ]);

        $this->mobile($buyer)
            ->getJson("/api/mobile/buyer/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'fulfilled')
            ->assertJsonPath('data.seller_orders.0.status', 'completed')
            ->assertJsonPath('data.seller_orders.0.returns.0.status', 'refund_pending')
            ->assertJsonPath('data.seller_orders.0.returns.0.refunds.0.status', 'completed')
            ->assertJsonPath('data.seller_orders.0.returns.0.refunds.0.amount_minor', 4000)
            ->assertJsonMissing(['private-refund-provider-reference'])
            ->assertJsonMissing(['Internal refund reconciliation note.']);
    }

    public function test_get_endpoints_do_not_mutate_orders_payments_shipments_or_send_notifications(): void
    {
        Notification::fake();
        $buyer = $this->buyer('read-only');
        $store = $this->store('read-only');
        $provider = $this->provider('read-only');
        $order = $this->order($buyer, 'read-only');
        $sellerOrder = $this->sellerOrder($order, $store, 'read-only', 'handed_to_logistics');
        $shipment = $this->shipment($sellerOrder, $provider, 'read-only', 'delivered');
        $snapshot = [
            $order->status,
            $order->payment_status,
            $order->payments()->sole()->status,
            $sellerOrder->status,
            $shipment->status,
            ShipmentEvent::query()->count(),
            InventoryMovement::query()->count(),
        ];

        $this->mobile($buyer)->getJson('/api/mobile/buyer/orders')->assertOk();
        $this->mobile($buyer)->getJson("/api/mobile/buyer/orders/{$order->id}")->assertOk();
        $this->mobile($buyer)->getJson("/api/mobile/buyer/orders/{$order->id}/tracking")->assertOk();

        $this->assertSame($snapshot, [
            $order->fresh()->status,
            $order->fresh()->payment_status,
            $order->payments()->sole()->status,
            $sellerOrder->fresh()->status,
            $shipment->fresh()->status,
            ShipmentEvent::query()->count(),
            InventoryMovement::query()->count(),
        ]);
        Notification::assertNothingSent();
    }

    public function test_order_endpoints_require_active_buyer_authentication(): void
    {
        $this->getJson('/api/mobile/buyer/orders')->assertUnauthorized();
        $this->getJson('/api/mobile/buyer/orders/1')->assertUnauthorized();
        $this->getJson('/api/mobile/buyer/orders/1/tracking')->assertUnauthorized();

        $seller = $this->userWithRole('seller');
        $this->mobile($seller)->getJson('/api/mobile/buyer/orders')->assertForbidden();

        $suspendedBuyer = $this->buyer('suspended', 'suspended');
        $token = $suspendedBuyer->createToken('orders-ineligible-test')->plainTextToken;
        $this->withToken($token)->getJson('/api/mobile/buyer/orders')->assertForbidden();
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => hash('sha256', $token)]);
    }

    private function buyer(string $suffix, string $status = 'active'): User
    {
        return $this->userWithRole('buyer', $status, $suffix);
    }

    private function userWithRole(string $role, string $status = 'active', ?string $suffix = null): User
    {
        $suffix ??= 'fixture-'.(++$this->sequence);
        $user = User::factory()->create([
            'name' => ucfirst($role).' '.$suffix,
            'email' => 'mobile-orders-'.Str::slug($role.'-'.$suffix).'-'.(++$this->sequence).'@example.test',
            'role' => $role,
            'status' => $status,
        ]);
        $roleId = Role::query()->where('name', $role)->value('id');
        if ($roleId) {
            $user->roles()->syncWithoutDetaching([$roleId]);
        }

        return $user;
    }

    private function mobile(User $user)
    {
        Auth::forgetGuards();

        return $this->withToken($user->createToken('mobile-orders-test')->plainTextToken);
    }

    private function store(string $suffix): Store
    {
        $seller = $this->userWithRole('seller', 'active', $suffix);
        $address = $this->address($seller, 'Seller Pickup');
        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => 'Seller Business '.$suffix,
            'pickup_address_id' => $address->id,
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => 'Store '.$suffix,
            'slug' => 'mobile-orders-'.Str::slug($suffix).'-'.(++$this->sequence),
            'contact_phone' => '+639171234567',
            'publication_status' => 'published',
            'published_at' => now(),
        ]);

        return $store;
    }

    /** @return array{user: User, profile: LogisticsProfile} */
    private function provider(string $suffix): array
    {
        $user = $this->userWithRole('logistics', 'active', $suffix);
        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => 'Logistics '.$suffix,
            'display_name' => 'Provider '.$suffix,
            'contact_phone' => '+639171234568',
            'status' => 'active',
        ]);

        return compact('user', 'profile');
    }

    private function address(User $owner, string $label = 'Home'): Address
    {
        return Address::query()->create([
            'user_id' => $owner->id,
            'label' => $label,
            'recipient_name' => $owner->name,
            'phone' => '+639171234567',
            'house_number' => '20',
            'street' => 'Buyer Street',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
        ]);
    }

    private function orderAddress(User $buyer): Address
    {
        return $buyer->addresses()->firstOrFail();
    }

    private function order(User $buyer, string $suffix): Order
    {
        $address = $this->address($buyer);
        $order = Order::query()->create([
            'order_no' => 'BRY-'.Str::upper(Str::random(12)),
            'buyer_id' => $buyer->id,
            'shipping_address_id' => $address->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => $address->phone,
            'address_line' => '20 Buyer Street',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
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
            'failure_reason' => 'Internal payment note must not be exposed.',
            'provider_reference' => 'private-provider-reference',
            'metadata' => ['private' => 'metadata'],
        ]);

        return $order;
    }

    private function sellerOrder(Order $order, Store $store, string $suffix, string $status = 'pending'): SellerOrder
    {
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => 'SO-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => $status,
            'subtotal_minor' => 16600,
            'discount_minor' => 0,
            'shipping_fee_minor' => 3400,
            'total_minor' => 20000,
        ]);
        $sellerOrder->items()->create([
            'product_name' => 'Snapshot Product '.$suffix,
            'variant_name' => 'Blue / Medium',
            'sku' => 'SNAPSHOT-'.Str::upper(Str::random(8)),
            'options' => ['Color' => 'Blue', 'Size' => 'Medium'],
            'unit_price_minor' => 16600,
            'quantity' => 1,
            'discount_minor' => 0,
            'subtotal_minor' => 16600,
        ]);

        return $sellerOrder;
    }

    /** @param array{user: User, profile: LogisticsProfile} $provider */
    private function shipment(SellerOrder $sellerOrder, array $provider, string $suffix, string $status): Shipment
    {
        return Shipment::query()->create([
            'shipment_no' => 'SHIP-'.Str::slug($suffix).'-'.Str::upper(Str::random(8)),
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider['profile']->id,
            'purpose' => 'outbound',
            'cod_amount_minor' => (int) $sellerOrder->total_minor,
            'status' => $status,
        ]);
    }
}
