<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Address;
use App\Models\DispatchBatch;
use App\Models\LogisticsLocation;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Payment;
use App\Models\RiderLocation;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\SortingCenter;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use App\Services\CodCashCollectionService;
use App\Services\CheckoutService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
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

    public function test_accepted_seller_order_hides_cancel_action_and_explains_why(): void
    {
        $buyer = $this->buyer('accepted-cancellation');
        [, $store] = $this->seller();
        $order = $this->marketplaceOrder($buyer, 'accepted-cancellation');
        $this->sellerOrder($order, $store, 'accepted-cancellation', 'accepted');

        $purchase = collect($this->actingAs($buyer)
            ->getJson(route('buyer.purchases.data'))
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $order->order_no);

        $this->assertFalse($purchase['can_cancel']);
        $this->assertNull($purchase['cancel_url']);
        $this->assertSame(
            CheckoutService::BUYER_CANCELLATION_UNAVAILABLE_MESSAGE,
            $purchase['cancel_unavailable_message'],
        );

        $this->actingAs($buyer)
            ->get(route('checkout.confirmation', $order->order_no))
            ->assertOk()
            ->assertSee(CheckoutService::BUYER_CANCELLATION_UNAVAILABLE_MESSAGE)
            ->assertDontSee('Cancel order before Seller processing');
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

    public function test_to_ship_orders_show_real_shipment_progress_as_current_status(): void
    {
        $buyer = $this->buyer('current-status-progress');
        [, $store] = $this->seller();
        $provider = $this->provider('current-status-progress');

        $inTransit = $this->marketplaceOrder($buyer, 'current-status-progress');
        $inTransitSellerOrder = $this->sellerOrder($inTransit, $store, 'current-status-progress', 'handed_to_logistics');
        $this->shipment($inTransitSellerOrder, $provider, 'current-status-progress', 'dispatched');

        $payload = collect($this->actingAs($buyer)
            ->getJson(route('buyer.purchases.data'))
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $inTransit->order_no);

        $this->assertSame('to-ship', $payload['status']);
        $this->assertSame('in_transit', $payload['delivery_status']);
        $this->assertSame('In Transit', $payload['trackingStatus']);
    }

    public function test_buyer_tracking_payload_keeps_multi_seller_shipments_separate(): void
    {
        $buyer = $this->buyer('shipment-tracking');
        [, $firstStore] = $this->seller();
        [, $secondStore] = $this->seller();
        $provider = $this->provider('shipment-tracking');
        $order = $this->marketplaceOrder($buyer, 'shipment-tracking');

        $firstSellerOrder = $this->sellerOrder($order, $firstStore, 'tracking-first', 'handed_to_logistics');
        $secondSellerOrder = $this->sellerOrder($order, $secondStore, 'tracking-second', 'handed_to_logistics');
        $firstShipment = $this->shipment($firstSellerOrder, $provider, 'tracking-first', 'out_for_delivery');
        $secondShipment = $this->shipment($secondSellerOrder, $provider, 'tracking-second', 'delivered');

        ShipmentEvent::query()->create([
            'shipment_id' => $firstShipment->id,
            'event_type' => 'shipment_out_for_delivery',
            'from_status' => 'dispatched',
            'to_status' => 'out_for_delivery',
            'source' => 'rider',
            'occurred_at' => now(),
        ]);
        ShipmentEvent::query()->create([
            'shipment_id' => $secondShipment->id,
            'event_type' => 'shipment_delivered',
            'from_status' => 'out_for_delivery',
            'to_status' => 'delivered',
            'source' => 'rider',
            'occurred_at' => now(),
        ]);

        $purchase = collect($this->actingAs($buyer)
            ->getJson(route('buyer.purchases.data'))
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $order->order_no);

        $this->assertCount(2, $purchase['trackingShipments']);
        $shipments = collect($purchase['trackingShipments'])->keyBy('number');
        $this->assertSame('out_for_delivery', $shipments[$firstShipment->shipment_no]['status']);
        $this->assertSame('Out for delivery', $shipments[$firstShipment->shipment_no]['statusLabel']);
        $this->assertSame($firstStore->name, $shipments[$firstShipment->shipment_no]['store']);
        $this->assertSame(1, count($shipments[$firstShipment->shipment_no]['events']));
        $this->assertSame('delivered', $shipments[$secondShipment->shipment_no]['status']);
        $this->assertSame(route('buyer.shipments.live-location', ['shipmentNo' => $firstShipment->shipment_no], false), $shipments[$firstShipment->shipment_no]['live_location_url']);
        $this->assertArrayNotHasKey('latitude', $shipments[$firstShipment->shipment_no]);
    }

    public function test_buyer_live_tracking_returns_only_rounded_latest_locations_from_assigned_local_riders(): void
    {
        $this->travelTo(Carbon::parse('2026-11-07 10:00:00'));

        $buyer = $this->buyer('live-tracking-owner');
        $otherBuyer = $this->buyer('live-tracking-other');
        [, $store] = $this->seller();
        $provider = $this->provider('live-tracking');
        $order = $this->marketplaceOrder($buyer, 'live-tracking');
        $sellerOrder = $this->sellerOrder($order, $store, 'live-tracking', 'handed_to_logistics');
        $shipment = $this->shipment($sellerOrder, $provider, 'live-tracking', 'out_for_delivery');

        $assignedRiders = [
            $this->trackingRider($provider, 'tracking-assigned-a'),
            $this->trackingRider($provider, 'tracking-assigned-b'),
        ];
        $unassignedRider = $this->trackingRider($provider, 'tracking-unassigned');
        $courier = $this->trackingRider($provider, 'tracking-courier', 'courier');
        $completedBatchRider = $this->trackingRider($provider, 'tracking-completed-batch');
        $foreignProvider = $this->provider('live-tracking-foreign');
        $foreignRider = $this->trackingRider($foreignProvider, 'tracking-foreign-provider');
        $assignedParcels = $this->assignTrackingParcels(
            $shipment,
            $provider,
            [...$assignedRiders, $courier, $completedBatchRider, $foreignRider],
            'tracking-live',
        );
        $completedBatchParcel = $assignedParcels[3];
        $completedBatchParcel->dispatchBatches()->firstOrFail()->update(['status' => 'completed']);

        RiderLocation::query()->create([
            'rider_profile_id' => $assignedRiders[0]->id,
            'latitude' => 14.5995123,
            'longitude' => 120.9842211,
            'accuracy_meters' => 100,
            'last_seen_at' => now()->subSeconds(180),
        ]);
        RiderLocation::query()->create([
            'rider_profile_id' => $assignedRiders[1]->id,
            'latitude' => 14.60051,
            'longitude' => 120.98551,
            'accuracy_meters' => 8.5,
            'last_seen_at' => now()->subSeconds(20),
        ]);
        RiderLocation::query()->create([
            'rider_profile_id' => $unassignedRider->id,
            'latitude' => 14.62,
            'longitude' => 120.99,
            'accuracy_meters' => 5,
            'last_seen_at' => now(),
        ]);
        foreach ([$courier, $completedBatchRider, $foreignRider] as $index => $excludedRider) {
            RiderLocation::query()->create([
                'rider_profile_id' => $excludedRider->id,
                'latitude' => 14.63 + ($index / 100),
                'longitude' => 120.99 + ($index / 100),
                'accuracy_meters' => 5,
                'last_seen_at' => now(),
            ]);
        }

        $response = $this->actingAs($buyer)
            ->getJson(route('buyer.shipments.live-location', ['shipmentNo' => $shipment->shipment_no]))
            ->assertOk()
            ->assertJsonPath('data.shipment_status', 'out_for_delivery')
            ->assertJsonPath('data.available', true);

        $locations = collect($response->json('data.locations'));
        $this->assertCount(2, $locations);
        $points = $locations->map(fn (array $location): array => [$location['latitude'], $location['longitude']])->all();
        $this->assertContains([14.6, 120.984], $points);
        $this->assertContains([14.601, 120.986], $points);
        $this->assertNotContains([14.62, 120.99], $points);
        $this->assertNotContains([14.63, 120.99], $points);
        $this->assertNotContains([14.64, 121], $points);
        $this->assertNotContains([14.65, 121.01], $points);
        $this->assertContains(0, $locations->pluck('fresh_for_seconds')->all());
        $this->assertContains(158, $locations->pluck('fresh_for_seconds')->all());
        $this->assertArrayNotHasKey('rider_profile_id', $locations->first());
        $this->assertArrayNotHasKey('user_id', $locations->first());
        $this->assertArrayNotHasKey('accuracy_meters', $locations->first());
        $this->assertNotContains([14.5995123, 120.9842211], $points);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->app['auth']->forgetGuards();
        $this->getJson(route('buyer.shipments.live-location', ['shipmentNo' => $shipment->shipment_no]))
            ->assertUnauthorized();

        $this->actingAs($otherBuyer)
            ->getJson(route('buyer.shipments.live-location', ['shipmentNo' => $shipment->shipment_no]))
            ->assertNotFound();

        $this->actingAs($buyer)->getJson(route('buyer.shipments.live-location', ['shipmentNo' => 'UNKNOWN-SHIPMENT']))
            ->assertNotFound();
    }

    public function test_live_location_is_unavailable_outside_active_delivery_and_for_stale_or_inaccurate_fixes(): void
    {
        $this->travelTo(Carbon::parse('2026-11-07 11:00:00'));

        $buyer = $this->buyer('live-tracking-gates');
        [, $store] = $this->seller();
        $provider = $this->provider('live-tracking-gates');
        $order = $this->marketplaceOrder($buyer, 'live-tracking-gates');
        $sellerOrder = $this->sellerOrder($order, $store, 'live-tracking-gates', 'handed_to_logistics');
        $shipment = $this->shipment($sellerOrder, $provider, 'live-tracking-gates', 'dispatched');
        $rider = $this->trackingRider($provider, 'tracking-gates');
        $parcels = $this->assignTrackingParcels($shipment, $provider, [$rider], 'tracking-gates');
        $location = RiderLocation::query()->create([
            'rider_profile_id' => $rider->id,
            'latitude' => 14.55,
            'longitude' => 120.98,
            'accuracy_meters' => 5,
            'last_seen_at' => now(),
        ]);
        $url = route('buyer.shipments.live-location', ['shipmentNo' => $shipment->shipment_no]);

        $this->actingAs($buyer)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.locations', []);

        $shipment->update(['status' => 'out_for_delivery']);
        $parcels->each(fn (Parcel $parcel) => $parcel->update(['status' => 'out_for_delivery']));

        $location->update(['last_seen_at' => now()->subSeconds(181)]);
        $this->actingAs($buyer)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.locations', []);

        $location->update(['last_seen_at' => now(), 'accuracy_meters' => 100.01]);
        $this->actingAs($buyer)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.locations', []);

        $location->update(['accuracy_meters' => null]);
        $this->actingAs($buyer)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.locations', []);

        $location->update(['accuracy_meters' => 100, 'last_seen_at' => now()->subSeconds(180)]);
        $this->actingAs($buyer)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.available', true);

        $shipment->update(['status' => 'delivered']);
        $parcels->each(fn (Parcel $parcel) => $parcel->update(['status' => 'delivered']));
        $this->actingAs($buyer)
            ->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.shipment_status', 'delivered')
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.locations', []);
    }

    public function test_buyer_route_map_returns_scoped_rounded_pins_without_road_geometry_when_routing_is_unconfigured(): void
    {
        config(['maps.geoapify_api_key' => null]);

        $buyer = $this->buyer('route-map');
        [, $store] = $this->seller();
        $provider = $this->provider('route-map');

        $storeOwner = SellerProfile::query()->findOrFail($store->seller_profile_id);
        $pickupAddress = Address::query()
            ->where('user_id', $storeOwner->user_id)
            ->where('is_default_pickup', true)
            ->firstOrFail();
        $pickupAddress->update(['latitude' => 14.123456, 'longitude' => 121.654321]);

        $centerAddress = Address::query()->create([
            'user_id' => $provider['user']->id,
            'label' => 'Sorting hub',
            'recipient_name' => $provider['user']->name,
            'phone' => '+639171234569',
            'house_number' => '7',
            'street' => 'Hub Road',
            'barangay' => 'Banaba',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
            'latitude' => 14.111111,
            'longitude' => 121.222222,
        ]);
        $center = SortingCenter::query()->create([
            'logistics_profile_id' => $provider['profile']->id,
            'address_id' => $centerAddress->id,
            'name' => 'Santa Rosa Hub',
            'code' => 'BRM-'.Str::upper(Str::random(8)),
            'status' => 'active',
        ]);
        LogisticsLocation::query()->create([
            'logistics_profile_id' => $provider['profile']->id,
            'latitude' => 14.777777,
            'longitude' => 121.888888,
            'accuracy_meters' => 50,
            'last_seen_at' => now(),
        ]);

        $order = $this->marketplaceOrder($buyer, 'route-map');
        $order->update(['delivery_latitude' => 14.987654, 'delivery_longitude' => 121.345678]);

        $sellerOrder = $this->sellerOrder($order, $store, 'route-map', 'handed_to_logistics');
        $shipment = $this->shipment($sellerOrder, $provider, 'route-map', 'dispatched');
        $shipment->update(['origin_address_id' => $pickupAddress->id]);
        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => 'ROUTE-WB-'.Str::upper(Str::random(12)),
            'scan_token' => hash('sha256', Str::random(32)),
            'barcode_value' => 'ROUTE-BAR-'.Str::upper(Str::random(12)),
            'piece_count' => 1,
            'total_weight_kg' => 1,
            'status' => 'generated',
            'generated_at' => now(),
        ]);
        Parcel::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_id' => $waybill->id,
            'parcel_no' => 'ROUTE-PARCEL-'.Str::upper(Str::random(10)),
            'piece_sequence' => 1,
            'weight_kg' => 1,
            'status' => 'at_sorting_center',
            'current_sorting_center_id' => $center->id,
            'last_event_at' => now(),
        ]);

        $data = $this->actingAs($buyer)
            ->getJson(route('buyer.orders.route-map', $order->order_no))
            ->assertOk()
            ->json('data');

        $this->assertSame([
            'latitude' => 14.988,
            'longitude' => 121.346,
        ], $data['pins']['buyer']);
        $this->assertSame($store->name, $data['pins']['sellers'][0]['label']);
        $this->assertSame(14.123, $data['pins']['sellers'][0]['latitude']);
        $this->assertSame(121.654, $data['pins']['sellers'][0]['longitude']);
        $this->assertSame('Santa Rosa Hub', $data['pins']['hubs'][0]['label']);
        $this->assertSame(14.111, $data['pins']['hubs'][0]['latitude']);
        $this->assertSame([], $data['legs']);

        // Only order-level pins are exposed: no rider identity or coordinate history.
        $this->assertArrayNotHasKey('rider', $data['pins']);
        $this->assertArrayNotHasKey('logistics_location', $data['pins']);
        $this->assertCount(3, $data['pins']);
    }

    public function test_route_map_denies_other_buyers_and_empty_pins_for_cancelled_orders(): void
    {
        config(['maps.geoapify_api_key' => null]);

        $buyer = $this->buyer('route-owner');
        $intruder = $this->buyer('route-intruder');
        [, $store] = $this->seller();

        $order = $this->marketplaceOrder($buyer, 'route-owner');
        $sellerOrder = $this->sellerOrder($order, $store, 'route-owner', 'pending');

        $this->actingAs($intruder)
            ->getJson(route('buyer.orders.route-map', $order->order_no))
            ->assertNotFound();

        $order->update(['status' => 'cancelled']);
        $sellerOrder->update(['status' => 'cancelled']);

        $data = $this->actingAs($buyer)
            ->getJson(route('buyer.orders.route-map', $order->order_no))
            ->assertOk()
            ->json('data');

        $this->assertNull($data['pins']['buyer']);
        $this->assertSame([], $data['pins']['sellers']);
        $this->assertSame([], $data['pins']['hubs']);
        $this->assertSame([], $data['legs']);
    }

    public function test_route_map_geocodes_addresses_without_saved_pins_through_openstreetmap(): void
    {
        config([
            'maps.maptiler_api_key' => null,
            'maps.geoapify_api_key' => null,
        ]);
        Http::fake([
            '*Hubville*' => Http::response([[
                'lat' => '14.21106000',
                'lon' => '121.88831000',
                'display_name' => 'Hubville, Hub City, Laguna, Philippines',
                'type' => 'administrative',
                'address' => ['country_code' => 'ph'],
            ]]),
            '*Storeville*' => Http::response([[
                'lat' => '14.31216000',
                'lon' => '121.55556000',
                'display_name' => 'Storeville, Santa Rosa, Laguna, Philippines',
                'type' => 'administrative',
                'address' => ['country_code' => 'ph'],
            ]]),
            '*Balibago*' => Http::response([[
                'lat' => '14.22226000',
                'lon' => '121.33327000',
                'display_name' => 'Balibago, Santa Rosa, Laguna, Philippines',
                'type' => 'administrative',
                'address' => ['country_code' => 'ph'],
            ]]),
        ]);

        $buyer = $this->buyer('route-geo');
        [, $store] = $this->seller();
        $provider = $this->provider('route-geo');

        $storeOwner = SellerProfile::query()->findOrFail($store->seller_profile_id);
        $pickupAddress = Address::query()
            ->where('user_id', $storeOwner->user_id)
            ->where('is_default_pickup', true)
            ->firstOrFail();
        $pickupAddress->update(['barangay' => 'Storeville']);

        $centerAddress = Address::query()->create([
            'user_id' => $provider['user']->id,
            'label' => 'Sorting hub',
            'recipient_name' => $provider['user']->name,
            'phone' => '+639171234569',
            'house_number' => '7',
            'street' => 'Hub Road',
            'barangay' => 'Hubville',
            'city_municipality' => 'Hub City',
            'province' => 'Laguna',
            'postal_code' => '4026',
        ]);
        $center = SortingCenter::query()->create([
            'logistics_profile_id' => $provider['profile']->id,
            'address_id' => $centerAddress->id,
            'name' => 'Santa Rosa Hub',
            'code' => 'BRM-'.Str::upper(Str::random(8)),
            'status' => 'active',
        ]);

        $order = $this->marketplaceOrder($buyer, 'route-geo');
        $sellerOrder = $this->sellerOrder($order, $store, 'route-geo', 'handed_to_logistics');
        $shipment = $this->shipment($sellerOrder, $provider, 'route-geo', 'dispatched');
        $shipment->update(['origin_address_id' => $pickupAddress->id]);
        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => 'GEOCODE-WB-'.Str::upper(Str::random(12)),
            'scan_token' => hash('sha256', Str::random(32)),
            'barcode_value' => 'GEOCODE-BAR-'.Str::upper(Str::random(12)),
            'piece_count' => 1,
            'total_weight_kg' => 1,
            'status' => 'generated',
            'generated_at' => now(),
        ]);
        Parcel::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_id' => $waybill->id,
            'parcel_no' => 'GEOCODE-PARCEL-'.Str::upper(Str::random(10)),
            'piece_sequence' => 1,
            'weight_kg' => 1,
            'status' => 'at_sorting_center',
            'current_sorting_center_id' => $center->id,
            'last_event_at' => now(),
        ]);

        $data = $this->actingAs($buyer)
            ->getJson(route('buyer.orders.route-map', $order->order_no))
            ->assertOk()
            ->json('data');

        $this->assertSame(14.211, $data['pins']['hubs'][0]['latitude']);
        $this->assertSame(121.888, $data['pins']['hubs'][0]['longitude']);
        $this->assertSame('Santa Rosa Hub', $data['pins']['hubs'][0]['label']);
        $this->assertSame(14.312, $data['pins']['sellers'][0]['latitude']);
        $this->assertSame(121.556, $data['pins']['sellers'][0]['longitude']);
        $this->assertSame($store->name, $data['pins']['sellers'][0]['label']);
        $this->assertSame(14.222, $data['pins']['buyer']['latitude']);
        $this->assertSame(121.333, $data['pins']['buyer']['longitude']);

        // Only Philippine results with locality tokens are accepted, and the
        // per-address results are cached for later order requests.
        $this->actingAs($buyer)
            ->getJson(route('buyer.orders.route-map', $order->order_no))
            ->assertOk()
            ->json('data');

        Http::assertSentCount(3);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://nominatim.openstreetmap.org/search')
            && (! isset($request->data()['street']))
            && (string) $request->data()['countrycodes'] === 'ph'
            && isset($request->headers()['User-Agent']));
    }

    public function test_route_map_omits_pins_when_geocoding_fails(): void
    {
        config([
            'maps.maptiler_api_key' => null,
            'maps.geoapify_api_key' => null,
        ]);
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([], 503),
        ]);

        $buyer = $this->buyer('route-geofail');
        [, $store] = $this->seller();
        $provider = $this->provider('route-geofail');

        $storeOwner = SellerProfile::query()->findOrFail($store->seller_profile_id);
        $pickupAddress = Address::query()
            ->where('user_id', $storeOwner->user_id)
            ->where('is_default_pickup', true)
            ->firstOrFail();

        $centerAddress = Address::query()->create([
            'user_id' => $provider['user']->id,
            'label' => 'Sorting hub',
            'recipient_name' => $provider['user']->name,
            'phone' => '+639171234569',
            'house_number' => '7',
            'street' => 'Hub Road',
            'barangay' => 'Banaba',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
        ]);
        $center = SortingCenter::query()->create([
            'logistics_profile_id' => $provider['profile']->id,
            'address_id' => $centerAddress->id,
            'name' => 'Santa Rosa Hub',
            'code' => 'BRM-'.Str::upper(Str::random(8)),
            'status' => 'active',
        ]);

        $order = $this->marketplaceOrder($buyer, 'route-geofail');
        $sellerOrder = $this->sellerOrder($order, $store, 'route-geofail', 'handed_to_logistics');
        $shipment = $this->shipment($sellerOrder, $provider, 'route-geofail', 'dispatched');
        $shipment->update(['origin_address_id' => $pickupAddress->id]);
        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => 'GEOFAIL-WB-'.Str::upper(Str::random(12)),
            'scan_token' => hash('sha256', Str::random(32)),
            'barcode_value' => 'GEOFAIL-BAR-'.Str::upper(Str::random(12)),
            'piece_count' => 1,
            'total_weight_kg' => 1,
            'status' => 'generated',
            'generated_at' => now(),
        ]);
        Parcel::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_id' => $waybill->id,
            'parcel_no' => 'GEOFAIL-PARCEL-'.Str::upper(Str::random(10)),
            'piece_sequence' => 1,
            'weight_kg' => 1,
            'status' => 'at_sorting_center',
            'current_sorting_center_id' => $center->id,
            'last_event_at' => now(),
        ]);

        $data = $this->actingAs($buyer)
            ->getJson(route('buyer.orders.route-map', $order->order_no))
            ->assertOk()
            ->json('data');

        $this->assertNull($data['pins']['buyer']);
        $this->assertSame([], $data['pins']['sellers']);
        $this->assertSame([], $data['pins']['hubs']);
        $this->assertSame([], $data['legs']);

        // Failed lookups are cached briefly so provider outages do not slow
        // down follow-up requests on the same addresses.
        $this->actingAs($buyer)
            ->getJson(route('buyer.orders.route-map', $order->order_no))
            ->assertOk()
            ->json('data');

        Http::assertSentCount(3);
    }

    public function test_route_map_shows_only_the_sorting_center_that_handled_the_parcel(): void
    {
        config([
            'maps.maptiler_api_key' => null,
            'maps.geoapify_api_key' => null,
        ]);

        $buyer = $this->buyer('route-hub');
        [, $store] = $this->seller();
        $originProvider = $this->provider('route-hub-origin');
        $handlingProvider = $this->provider('route-hub-handling');

        $storeOwner = SellerProfile::query()->findOrFail($store->seller_profile_id);
        $pickupAddress = Address::query()
            ->where('user_id', $storeOwner->user_id)
            ->where('is_default_pickup', true)
            ->firstOrFail();
        $pickupAddress->update(['latitude' => 14.202178, 'longitude' => 121.346054]);

        $originCenterAddress = Address::query()->create([
            'user_id' => $originProvider['user']->id,
            'label' => 'Origin hub',
            'recipient_name' => $originProvider['user']->name,
            'phone' => '+639171234569',
            'house_number' => '1',
            'street' => 'Origin Road',
            'barangay' => 'Bagumbayan',
            'city_municipality' => 'Santa Cruz',
            'province' => 'Laguna',
            'postal_code' => '4009',
            'latitude' => 14.428001,
            'longitude' => 121.415011,
        ]);
        SortingCenter::query()->create([
            'logistics_profile_id' => $originProvider['profile']->id,
            'address_id' => $originCenterAddress->id,
            'name' => 'Origin Center',
            'code' => 'BRM-'.Str::upper(Str::random(8)),
            'status' => 'active',
        ]);

        $primaryCenterAddress = Address::query()->create([
            'user_id' => $handlingProvider['user']->id,
            'label' => 'Primary hub',
            'recipient_name' => $handlingProvider['user']->name,
            'phone' => '+639171234569',
            'house_number' => '2',
            'street' => 'Primary Road',
            'barangay' => 'Pansol',
            'city_municipality' => 'Pila',
            'province' => 'Laguna',
            'postal_code' => '4010',
            'latitude' => 14.238012,
            'longitude' => 121.362013,
        ]);
        $handlingCenter = SortingCenter::query()->create([
            'logistics_profile_id' => $handlingProvider['profile']->id,
            'address_id' => $primaryCenterAddress->id,
            'name' => 'Handling Center',
            'code' => 'BRM-'.Str::upper(Str::random(8)),
            'status' => 'active',
        ]);

        $order = $this->marketplaceOrder($buyer, 'route-hub');
        $order->update(['delivery_latitude' => 14.988001, 'delivery_longitude' => 121.346002]);

        $sellerOrder = $this->sellerOrder($order, $store, 'route-hub', 'handed_to_logistics');
        $shipment = $this->shipment($sellerOrder, $handlingProvider, 'route-hub', 'dispatched');
        $shipment->update([
            'origin_address_id' => $pickupAddress->id,
            'origin_logistics_profile_id' => $originProvider['profile']->id,
        ]);

        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => 'HUB-WB-'.Str::upper(Str::random(12)),
            'scan_token' => hash('sha256', Str::random(32)),
            'barcode_value' => 'HUB-BAR-'.Str::upper(Str::random(12)),
            'piece_count' => 1,
            'total_weight_kg' => 1,
            'status' => 'generated',
            'generated_at' => now(),
        ]);
        Parcel::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_id' => $waybill->id,
            'parcel_no' => 'HUB-PARCEL-'.Str::upper(Str::random(10)),
            'piece_sequence' => 1,
            'weight_kg' => 1,
            'status' => 'in_transit',
            'current_sorting_center_id' => $handlingCenter->id,
            'last_event_at' => now(),
        ]);

        $data = $this->actingAs($buyer)
            ->getJson(route('buyer.orders.route-map', $order->order_no))
            ->assertOk()
            ->json('data');

        // Only the center that actually handled the parcel appears: the
        // origin provider's hub stays off the buyer's map.
        $this->assertCount(1, $data['pins']['hubs']);
        $this->assertSame('Handling Center', $data['pins']['hubs'][0]['label']);
        $this->assertSame(14.238, $data['pins']['hubs'][0]['latitude']);
        $this->assertSame(121.362, $data['pins']['hubs'][0]['longitude']);
    }

    public function test_route_map_does_not_guess_a_logistics_center_before_parcel_intake(): void
    {
        config([
            'maps.maptiler_api_key' => null,
            'maps.geoapify_api_key' => null,
        ]);

        $buyer = $this->buyer('route-hub-pre');
        [, $store] = $this->seller();
        $originProvider = $this->provider('route-hub-pre-origin');
        $handlingProvider = $this->provider('route-hub-pre-hand');

        $originCenterAddress = Address::query()->create([
            'user_id' => $originProvider['user']->id,
            'label' => 'Origin hub',
            'recipient_name' => $originProvider['user']->name,
            'phone' => '+639171234569',
            'house_number' => '1',
            'street' => 'Origin Road',
            'barangay' => 'Bagumbayan',
            'city_municipality' => 'Santa Cruz',
            'province' => 'Laguna',
            'postal_code' => '4009',
            'latitude' => 14.428001,
            'longitude' => 121.415011,
        ]);
        SortingCenter::query()->create([
            'logistics_profile_id' => $originProvider['profile']->id,
            'address_id' => $originCenterAddress->id,
            'name' => 'Origin Center',
            'code' => 'BRM-'.Str::upper(Str::random(8)),
            'status' => 'active',
        ]);

        $primaryCenterAddress = Address::query()->create([
            'user_id' => $handlingProvider['user']->id,
            'label' => 'Primary hub',
            'recipient_name' => $handlingProvider['user']->name,
            'phone' => '+639171234569',
            'house_number' => '2',
            'street' => 'Primary Road',
            'barangay' => 'Pansol',
            'city_municipality' => 'Pila',
            'province' => 'Laguna',
            'postal_code' => '4010',
            'latitude' => 14.238012,
            'longitude' => 121.362013,
        ]);
        SortingCenter::query()->create([
            'logistics_profile_id' => $handlingProvider['profile']->id,
            'address_id' => $primaryCenterAddress->id,
            'name' => 'Primary Center',
            'code' => 'BRM-'.Str::upper(Str::random(8)),
            'status' => 'active',
        ]);

        $order = $this->marketplaceOrder($buyer, 'route-hub-pre');
        $order->update(['delivery_latitude' => 14.988001, 'delivery_longitude' => 121.346002]);

        $sellerOrder = $this->sellerOrder($order, $store, 'route-hub-pre', 'handed_to_logistics');
        $shipment = $this->shipment($sellerOrder, $handlingProvider, 'route-hub-pre', 'ready_for_pickup');
        $shipment->update(['origin_logistics_profile_id' => $originProvider['profile']->id]);

        $data = $this->actingAs($buyer)
            ->getJson(route('buyer.orders.route-map', $order->order_no))
            ->assertOk()
            ->json('data');

        // No parcel has been received or sorted yet, so neither the origin
        // hub nor the handling provider's default center is shown.
        $this->assertSame([], $data['pins']['hubs']);
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
        $this->getJson(route('buyer.orders.route-map', 'BRY-SOMEORDER'))->assertUnauthorized();
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
        return Shipment::query()->create([
            'shipment_no' => 'PURCHASES-'.Str::slug($suffix).'-'.Str::upper(Str::random(8)),
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider['profile']->id,
            'purpose' => 'outbound',
            'cod_amount_minor' => (int) $sellerOrder->total_minor,
            'status' => $status,
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

        $codCash = app(CodCashCollectionService::class);
        $collection = $codCash->recordRiderCollection(
            $shipment,
            $rider,
            (int) $shipment->cod_amount_minor,
        );
        $amountMinor = (int) $shipment->cod_amount_minor;
        $codCash->receiveAtLogistics($provider['user'], (int) $collection->id, $amountMinor);
        $codCash->remitToFinance($provider['user'], (int) $collection->id, $amountMinor);

        $finance = User::factory()->create([
            'name' => "Finance {$suffix}",
            'email' => 'purchases-finance-'.Str::slug($suffix).'-'.(++$this->sequence).'@example.test',
            'role' => UserRole::Admin->value,
            'status' => 'active',
        ]);
        $finance->roles()->syncWithoutDetaching([
            Role::query()->where('name', UserRole::Admin->value)->value('id'),
        ]);
        $codCash->reconcileAtFinance($finance, (int) $collection->id, $amountMinor);
    }

    /** @param array{user: User, profile: LogisticsProfile} $provider */
    private function trackingRider(array $provider, string $suffix, string $workType = 'local'): RiderProfile
    {
        $user = User::factory()->create([
            'name' => "Tracking Rider {$suffix}",
            'email' => 'tracking-rider-'.Str::slug($suffix).'-'.(++$this->sequence).'@example.test',
            'role' => UserRole::Rider->value,
            'status' => 'active',
        ]);
        $user->roles()->syncWithoutDetaching([
            Role::query()->where('name', UserRole::Rider->value)->value('id'),
        ]);

        return RiderProfile::query()->create([
            'user_id' => $user->id,
            'logistics_profile_id' => $provider['profile']->id,
            'vehicle_type' => 'Motorcycle',
            'work_type' => $workType,
            'specialization' => 'both',
            'availability_status' => 'available',
            'verification_status' => 'approved',
        ]);
    }

    /**
     * @param array{user: User, profile: LogisticsProfile} $provider
     * @param array<int, RiderProfile> $riders
     * @return Collection<int, Parcel>
     */
    private function assignTrackingParcels(Shipment $shipment, array $provider, array $riders, string $suffix): Collection
    {
        $centerAddress = Address::query()->create([
            'user_id' => $provider['user']->id,
            'label' => "Tracking center {$suffix}",
            'recipient_name' => $provider['user']->name,
            'phone' => $provider['profile']->contact_phone,
            'house_number' => '10',
            'street' => 'Tracking Road',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
            'postal_code' => '4026',
        ]);
        $center = SortingCenter::query()->create([
            'logistics_profile_id' => $provider['profile']->id,
            'address_id' => $centerAddress->id,
            'name' => "Tracking Center {$suffix}",
            'code' => 'TRACK-'.Str::upper(Str::random(10)),
            'status' => 'active',
        ]);

        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => 'TRACK-WB-'.Str::upper(Str::random(12)),
            'scan_token' => hash('sha256', Str::random(32)),
            'barcode_value' => 'TRACK-BAR-'.Str::upper(Str::random(12)),
            'piece_count' => count($riders),
            'total_weight_kg' => (float) count($riders),
            'status' => 'generated',
            'generated_at' => now(),
        ]);

        $parcels = collect();
        foreach ($riders as $index => $rider) {
            $sequence = $index + 1;
            $parcel = Parcel::query()->create([
                'shipment_id' => $shipment->id,
                'waybill_id' => $waybill->id,
                'parcel_no' => 'TRACK-PARCEL-'.Str::upper(Str::random(10)),
                'piece_sequence' => $sequence,
                'weight_kg' => 1,
                'status' => 'out_for_delivery',
                'current_sorting_center_id' => $center->id,
                'last_event_at' => now(),
            ]);
            $batch = DispatchBatch::query()->create([
                'batch_no' => 'TRACK-BATCH-'.Str::upper(Str::random(10)),
                'sorting_center_id' => $center->id,
                'rider_profile_id' => $rider->id,
                'status' => 'dispatched',
                'prepared_by' => $provider['user']->id,
                'prepared_at' => now(),
                'assigned_at' => now(),
                'dispatched_at' => now(),
            ]);
            $batch->parcels()->attach($parcel->id, [
                'sequence' => $sequence,
                'loaded_at' => now(),
            ]);
            $parcels->push($parcel);
        }

        return $parcels;
    }
}
