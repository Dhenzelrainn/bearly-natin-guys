<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Address;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryProof;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Payment;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use App\Services\CodCashCollectionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuyerPurchasesDataTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    public function test_buyer_purchases_list_their_own_orders_with_real_status_buckets(): void
    {
        $buyer = $this->buyer('buckets');
        [, $store] = $this->seller();
        $provider = $this->provider('buckets');

        $delivered = $this->marketplaceOrder($buyer, 'buckets-delivered');
        $deliveredSellerOrder = $this->sellerOrder($delivered, $store, 'buckets-delivered', 'handed_to_logistics');
        $deliveredShipment = $this->shipment($deliveredSellerOrder, $provider, 'buckets-delivered', 'delivered');
        $this->collectCod($deliveredShipment, $provider, 'buckets-delivered');
        ShipmentEvent::query()->create([
            'shipment_id' => $deliveredShipment->id,
            'event_type' => 'shipment_delivered',
            'from_status' => 'out_for_delivery',
            'to_status' => 'delivered',
            'actor_user_id' => $provider['user']->id,
            'source' => 'rider',
            'occurred_at' => Carbon::parse('2026-10-12 08:30:00'),
        ]);
        ShipmentEvent::query()->create([
            'shipment_id' => $deliveredShipment->id,
            'event_type' => 'admin_override',
            'from_status' => 'out_for_delivery',
            'to_status' => 'delivered',
            'actor_user_id' => $provider['user']->id,
            'source' => 'admin',
            'notes' => 'Internal reason: manual delivery exception review.',
            'occurred_at' => Carbon::parse('2026-10-12 08:29:00'),
        ]);
        $deliveredSellerOrder->items()->firstOrFail()->update([
            'product_image_disk' => 'public',
            'product_image_path' => 'seller-products/variants/snapshot-test.png',
        ]);

        $preparing = $this->marketplaceOrder($buyer, 'buckets-preparing');
        $this->sellerOrder($preparing, $store, 'buckets-preparing', 'pending');

        $response = $this->actingAs($buyer)
            ->getJson(route('buyer.purchases.data'))
            ->assertOk()
            ->assertJsonPath('message', 'Purchases retrieved successfully.');

        $data = $response->json('data');
        $this->assertCount(2, $data);

        // Newest first.
        $this->assertSame($preparing->order_no, $data[0]['id']);
        $this->assertSame('to-ship', $data[0]['status']);
        $this->assertSame('To Ship', $data[0]['statusLabel']);
        $this->assertFalse($data[0]['can_confirm']);
        $this->assertNull($data[0]['confirm_url']);
        $this->assertSame(route('checkout.cancel', $preparing->id), $data[0]['cancel_url']);

        $this->assertSame($delivered->order_no, $data[1]['id']);
        $this->assertSame('to-receive', $data[1]['status']);
        $this->assertSame('To Receive', $data[1]['statusLabel']);
        $this->assertTrue($data[1]['can_confirm']);
        $this->assertSame(route('checkout.confirm-receipt', $delivered->id), $data[1]['confirm_url']);
        $this->assertNull($data[1]['cancel_url']);
        $this->assertSame(20000, $data[1]['total_minor']);
        $this->assertSame('Snapshot Product', $data[1]['items'][0]['name']);
        $this->assertSame(1, $data[1]['items'][0]['qty']);
        $this->assertStringContainsString('seller-products/variants/snapshot-test.png', $data[1]['items'][0]['image']);
        $this->assertSame(route('stores.show', $store->slug, false), $data[1]['items'][0]['store_url']);
        $this->assertSame('delivered', $data[1]['delivery_status']);
        $this->assertSame('Delivered', $data[1]['trackingStatus']);
        $this->assertNotContains('Internal reason: manual delivery exception review.', array_column($data[1]['trackingEvents'], 'note'));
        $this->assertSame('Oct 12, 2026 · 8:30 AM', $data[1]['delivered_at_display']);
        $this->assertSame(route('checkout.confirmation', $delivered->order_no), $data[1]['details_url']);
        $this->assertNotEmpty($data[1]['shop']);
    }

    public function test_purchases_show_completed_and_cancelled_buckets(): void
    {
        $buyer = $this->buyer('states');
        [, $store] = $this->seller();
        $provider = $this->provider('states');

        $completed = $this->marketplaceOrder($buyer, 'states-done');
        $completedSellerOrder = $this->sellerOrder($completed, $store, 'states-done', 'completed');
        $completedShipment = $this->shipment($completedSellerOrder, $provider, 'states-done', 'delivered');
        $this->collectCod($completedShipment, $provider, 'states-done');
        $completed->update([
            'status' => 'fulfilled',
            'payment_status' => 'paid',
            'completed_at' => $completedSellerOrder->completed_at ?? now(),
        ]);

        $cancelled = $this->marketplaceOrder($buyer, 'states-cancelled');
        $cancelled->update(['status' => 'cancelled']);

        $data = $this->actingAs($buyer)
            ->getJson(route('buyer.purchases.data'))
            ->assertOk()
            ->json('data');

        $byId = collect($data)->keyBy('id');
        $this->assertSame('completed', $byId[$completed->order_no]['status']);
        $this->assertFalse($byId[$completed->order_no]['can_confirm']);
        $this->assertSame('delivered', $byId[$completed->order_no]['delivery_status']);
        $this->assertSame('Delivered', $byId[$completed->order_no]['trackingStatus']);
        $this->assertSame('cancelled', $byId[$cancelled->order_no]['status']);
    }

    public function test_multi_seller_delivery_waits_for_every_active_outbound_shipment_and_cancelled_sellers_do_not_block_cancel_visibility(): void
    {
        $buyer = $this->buyer('multi-seller-state');
        [, $deliveredStore] = $this->seller();
        [, $pendingStore] = $this->seller();
        [, $cancelledStore] = $this->seller();
        $provider = $this->provider('multi-seller-state');

        $partial = $this->marketplaceOrder($buyer, 'multi-seller-partial');
        $deliveredSellerOrder = $this->sellerOrder($partial, $deliveredStore, 'multi-seller-delivered', 'handed_to_logistics');
        $deliveredSellerOrder->update(['total_minor' => 10000]);
        $pendingSellerOrder = $this->sellerOrder($partial, $pendingStore, 'multi-seller-pending', 'pending');
        $pendingSellerOrder->update(['total_minor' => 0]);
        $cancelledSellerOrder = $this->sellerOrder($partial, $cancelledStore, 'multi-seller-cancelled', 'cancelled');
        $cancelledSellerOrder->update(['total_minor' => 0]);
        $partial->update(['total_minor' => 10000]);
        $partial->payments()->update(['amount_minor' => 10000]);
        $deliveredShipment = $this->shipment($deliveredSellerOrder, $provider, 'multi-seller-partial', 'delivered');
        $this->collectCod($deliveredShipment, $provider, 'multi-seller-partial');

        $partialPayload = collect($this->actingAs($buyer)
            ->getJson(route('buyer.purchases.data'))
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $partial->order_no);

        $this->assertSame('preparing', $partialPayload['delivery_status']);
        $this->assertNotSame('Delivered', $partialPayload['trackingStatus']);
        $this->assertFalse($partialPayload['can_confirm']);
        $this->assertNull($partialPayload['confirm_url']);
        $this->assertSame('delivered', collect($partialPayload['seller_orders'])->firstWhere('id', $deliveredSellerOrder->id)['delivery_status']);
        $this->assertSame('preparing', collect($partialPayload['seller_orders'])->firstWhere('id', $pendingSellerOrder->id)['delivery_status']);
        $this->assertSame('cancelled', collect($partialPayload['seller_orders'])->firstWhere('id', $cancelledSellerOrder->id)['delivery_status']);

        $this->actingAs($buyer)
            ->post(route('checkout.confirm-receipt', $partial->id))
            ->assertStatus(409);

        $inTransit = $this->marketplaceOrder($buyer, 'multi-seller-transit');
        $inTransitFirstOrder = $this->sellerOrder($inTransit, $deliveredStore, 'multi-seller-transit-delivered', 'handed_to_logistics');
        $inTransitFirstOrder->update(['total_minor' => 10000]);
        $inTransitSecondOrder = $this->sellerOrder($inTransit, $pendingStore, 'multi-seller-transit-pending', 'handed_to_logistics');
        $inTransitSecondOrder->update(['total_minor' => 10000]);
        $inTransit->update(['total_minor' => 20000]);
        $inTransit->payments()->update(['amount_minor' => 20000]);
        $deliveredParcel = $this->shipment($inTransitFirstOrder, $provider, 'multi-seller-transit-delivered', 'delivered');
        $transitParcel = $this->shipment($inTransitSecondOrder, $provider, 'multi-seller-transit-pending', 'out_for_delivery');
        $this->collectCod($deliveredParcel, $provider, 'multi-seller-transit-delivered');
        $this->collectCod($transitParcel, $provider, 'multi-seller-transit-pending');

        $transitPayload = collect($this->actingAs($buyer)
            ->getJson(route('buyer.purchases.data'))
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $inTransit->order_no);

        $this->assertSame('out_for_delivery', $transitPayload['delivery_status']);
        $this->assertSame('Out for Delivery', $transitPayload['trackingStatus']);
        $this->assertFalse($transitPayload['can_confirm']);
        $this->assertNotSame('Delivered', $transitPayload['trackingStatus']);
        $this->actingAs($buyer)
            ->post(route('checkout.confirm-receipt', $inTransit->id))
            ->assertStatus(409);

        $cancellable = $this->marketplaceOrder($buyer, 'multi-seller-cancellable');
        $alreadyCancelled = $this->sellerOrder($cancellable, $deliveredStore, 'already-cancelled', 'cancelled');
        $stillPending = $this->sellerOrder($cancellable, $pendingStore, 'still-pending', 'pending');

        $cancellablePayload = collect($this->actingAs($buyer)
            ->getJson(route('buyer.purchases.data'))
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $cancellable->order_no);

        $this->assertTrue($cancellablePayload['can_cancel']);
        $this->assertSame(route('checkout.cancel', $cancellable->id), $cancellablePayload['cancel_url']);
        $this->assertSame('cancelled', $alreadyCancelled->fresh()->status);
        $this->assertSame('pending', $stillPending->fresh()->status);
    }

    public function test_purchases_never_expose_another_buyers_orders(): void
    {
        $buyer = $this->buyer('owner');
        $intruder = $this->buyer('intruder');
        [, $store] = $this->seller();
        $order = $this->marketplaceOrder($buyer, 'private');
        $this->sellerOrder($order, $store, 'private', 'pending');

        $intruderData = $this->actingAs($intruder)
            ->getJson(route('buyer.purchases.data'))
            ->assertOk()
            ->json('data');
        $this->assertSame([], $intruderData);

        $this->assertSame('pending', $order->fresh()->sellerOrders()->sole()->status);
    }

    public function test_purchases_require_authentication(): void
    {
        $this->getJson(route('buyer.purchases.data'))->assertUnauthorized();
    }

    private function buyer(string $suffix): User
    {
        $user = User::factory()->create([
            'name' => "Buyer {$suffix}",
            'email' => 'purchases-buyer-'.Str::slug($suffix).'-'.(++$this->sequence).'@example.test',
            'role' => UserRole::Buyer->value,
            'status' => 'active',
        ]);
        $user->roles()->syncWithoutDetaching([
            Role::query()->where('name', UserRole::Buyer->value)->value('id'),
        ]);

        return $user;
    }

    /** @return array{0: User, 1: Store} */
    private function seller(): array
    {
        $suffix = ++$this->sequence;
        $user = User::factory()->create([
            'name' => "Seller {$suffix}",
            'email' => "purchases-seller-{$suffix}@example.test",
            'role' => UserRole::Seller->value,
            'status' => 'active',
        ]);
        $user->roles()->syncWithoutDetaching([
            Role::query()->where('name', UserRole::Seller->value)->value('id'),
        ]);

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
            'legal_business_name' => "Seller Business {$suffix}",
            'pickup_address_id' => $address->id,
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => "Seller Store {$suffix}",
            'slug' => "purchases-seller-store-{$suffix}",
            'contact_phone' => '+639171234567',
            'publication_status' => 'published',
            'published_at' => now(),
        ]);

        return [$user, $store];
    }

    /** @return array{user: User, profile: LogisticsProfile} */
    private function provider(string $suffix): array
    {
        $user = User::factory()->create([
            'name' => "Logistics {$suffix}",
            'email' => 'purchases-logistics-'.Str::slug($suffix).'@example.test',
            'role' => UserRole::Logistics->value,
            'status' => 'active',
        ]);
        $user->roles()->syncWithoutDetaching([
            Role::query()->where('name', UserRole::Logistics->value)->value('id'),
        ]);

        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => "Logistics {$suffix} Inc.",
            'display_name' => "Logistics {$suffix}",
            'contact_phone' => '+639171234567',
            'status' => 'active',
        ]);

        return compact('user', 'profile');
    }

    private function marketplaceOrder(User $buyer, string $suffix): Order
    {
        $destination = Address::query()->create([
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
            'shipping_address_id' => $destination->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => $destination->phone,
            'address_line' => '20 Buyer Street',
            'barangay' => $destination->barangay,
            'city_municipality' => $destination->city_municipality,
            'province' => $destination->province,
            'postal_code' => $destination->postal_code,
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

    private function sellerOrder(Order $order, Store $store, string $suffix, string $status): SellerOrder
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
            'ready_at' => now(),
        ]);
        $sellerOrder->items()->create([
            'product_name' => 'Snapshot Product',
            'variant_name' => 'Blue / Medium',
            'sku' => 'SNAPSHOT-'.Str::upper(Str::random(8)),
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
        $shipment = Shipment::query()->create([
            'shipment_no' => 'PURCHASES-'.Str::slug($suffix).'-'.Str::upper(Str::random(8)),
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider['profile']->id,
            'purpose' => 'outbound',
            'cod_amount_minor' => (int) $sellerOrder->total_minor,
            'status' => $status,
        ]);

        if ($status === 'delivered') {
            $this->attachDeliveredEvidence($shipment, $sellerOrder, $provider);
        }

        return $shipment;
    }

    /** @param array{user: User, profile: LogisticsProfile} $provider */
    private function attachDeliveredEvidence(Shipment $shipment, SellerOrder $sellerOrder, array $provider): void
    {
        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => 'PURCHASES-WB-'.Str::upper(Str::random(12)),
            'scan_token' => hash('sha256', Str::random(64)),
            'barcode_value' => 'PURCHASES-BC-'.Str::upper(Str::random(12)),
            'piece_count' => 1,
            'total_weight_kg' => 1,
            'generated_at' => now(),
        ]);
        $parcel = Parcel::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_id' => $waybill->id,
            'parcel_no' => 'PURCHASES-PARCEL-'.Str::upper(Str::random(12)),
            'piece_sequence' => 1,
            'weight_kg' => 1,
            'status' => 'delivered',
            'last_event_at' => now(),
        ]);
        $rider = User::factory()->create([
            'name' => 'Buyer purchase proof Rider',
            'email' => 'purchases-proof-rider-'.Str::upper(Str::random(12)).'@example.test',
            'role' => UserRole::Rider->value,
            'status' => 'active',
        ]);
        $riderProfile = RiderProfile::query()->create([
            'user_id' => $rider->id,
            'logistics_profile_id' => $provider['profile']->id,
            'vehicle_type' => 'motorcycle',
            'availability_status' => 'available',
            'verification_status' => 'approved',
        ]);
        $attempt = DeliveryAttempt::query()->create([
            'parcel_id' => $parcel->id,
            'rider_profile_id' => $riderProfile->id,
            'attempt_no' => 1,
            'outcome' => 'delivered',
            'attempted_at' => now(),
        ]);
        $proofPath = 'delivery-proofs/purchases-'.Str::lower(Str::random(16)).'.webp';
        Storage::disk('local')->put($proofPath, 'test-only persisted delivery photo fixture');
        DeliveryProof::query()->create([
            'delivery_attempt_id' => $attempt->id,
            'type' => 'photo',
            'file_path' => $proofPath,
            'recipient_name' => (string) $sellerOrder->order->recipient_name,
            'captured_at' => now(),
            'uploaded_by' => $rider->id,
        ]);
    }

    /** @param array{user: User, profile: LogisticsProfile} $provider */
    private function collectCod(Shipment $shipment, array $provider, string $suffix): void
    {
        $rider = User::factory()->create([
            'name' => "Rider {$suffix}",
            'email' => 'purchases-rider-'.Str::slug($suffix).'-'.(++$this->sequence).'@example.test',
            'role' => UserRole::Rider->value,
            'status' => 'active',
        ]);
        $rider->roles()->syncWithoutDetaching([
            Role::query()->where('name', UserRole::Rider->value)->value('id'),
        ]);
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
