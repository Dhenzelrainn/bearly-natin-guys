<?php

namespace Tests\Feature\Seller;

use App\Models\Address;
use App\Models\DispatchBatch;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Parcel;
use App\Models\PickupAssignment;
use App\Models\PickupRequest;
use App\Models\RiderProfile;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\SortingCenter;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerShipmentTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracking_page_is_seller_scoped_and_excludes_return_and_pre_handover_shipments(): void
    {
        $this->get(route('seller.fulfillment.tracking'))->assertRedirect(route('login'));

        [$sellerA, , $storeA] = $this->sellerFixture('Alpha Seller', 'alpha-seller');
        [, , $storeB] = $this->sellerFixture('Beta Seller', 'beta-seller');

        $this->makeShipment($storeA, 'ALPHA', 'at_sorting_center');
        $this->makeShipment($storeB, 'BETA', 'out_for_delivery');
        $this->makeShipment($storeA, 'ALPHA-RET', 'at_sorting_center', purpose: 'return');
        $this->makeShipment($storeA, 'ALPHA-PRE', 'ready_for_pickup');

        $this->actingAs($sellerA)
            ->get(route('seller.fulfillment.tracking'))
            ->assertOk()
            ->assertSee('BRLY-ALPHA')
            ->assertSee('Customer ALPHA')
            ->assertSee('San Pablo City, Laguna')
            ->assertSee('At Sorting Center')
            ->assertDontSee('BRLY-BETA')
            ->assertDontSee('Customer BETA')
            ->assertDontSee('BRLY-ALPHA-RET')
            ->assertDontSee('BRLY-ALPHA-PRE');

        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);
        $this->actingAs($buyer)->get(route('seller.fulfillment.tracking'))->assertForbidden();
    }

    public function test_tab_counts_and_attention_banner_follow_persisted_shipment_statuses(): void
    {
        [$seller, , $store] = $this->sellerFixture('Count Seller', 'count-seller');

        $this->makeShipment($store, 'COUNT-S1', 'at_sorting_center');
        $this->makeShipment($store, 'COUNT-S2', 'at_sorting_center');
        $this->makeShipment($store, 'COUNT-T1', 'sorted');
        $this->makeShipment($store, 'COUNT-T2', 'dispatched');
        $this->makeShipment($store, 'COUNT-D1', 'out_for_delivery');
        $this->makeShipment($store, 'COUNT-D2', 'delivered');
        $this->makeShipment($store, 'COUNT-F1', 'delivery_failed');
        $this->makeShipment($store, 'COUNT-F2', 'return_to_sender');

        $this->actingAs($seller)
            ->get(route('seller.fulfillment.tracking'))
            ->assertOk()
            ->assertSee('All Shipments <span>8</span>', false)
            ->assertSee('Sorting Center <span>2</span>', false)
            ->assertSee('Sorted / In Transit <span>2</span>', false)
            ->assertSee('Out for Delivery <span>1</span>', false)
            ->assertSee('Delivered <span>1</span>', false)
            ->assertSee('Needs Attention <span>2</span>', false)
            ->assertSee('shipments need attention')
            ->assertSee('Dispatched to Rider')
            ->assertSee('Returning to Seller');
    }

    public function test_latest_update_eta_and_rider_fallbacks_use_persisted_records_only(): void
    {
        [$seller, , $store] = $this->sellerFixture('Fallback Seller', 'fallback-seller');

        $withEvents = $this->makeShipment($store, 'FB-EV', 'out_for_delivery');
        $occurredAt = CarbonImmutable::now()->startOfDay()->addHours(10)->addMinutes(15);

        $withEvents['shipment']->events()->create([
            'parcel_id' => $withEvents['parcel']->id,
            'event_type' => 'received_at_center',
            'to_status' => 'at_sorting_center',
            'source' => 'logistics',
            'notes' => 'Received at Santa Rosa Sorting Center.',
            'occurred_at' => $occurredAt->subHours(3),
        ]);
        $withEvents['shipment']->events()->create([
            'parcel_id' => $withEvents['parcel']->id,
            'event_type' => 'shipment_out_for_delivery',
            'to_status' => 'out_for_delivery',
            'source' => 'rider',
            'notes' => 'Rider is delivering the parcel.',
            'occurred_at' => $occurredAt,
        ]);

        $this->makeShipment($store, 'FB-NOEV', 'sorted');

        $this->actingAs($seller)
            ->get(route('seller.fulfillment.tracking'))
            ->assertOk()
            ->assertSee('Out for Delivery')
            ->assertSee('Rider is delivering the parcel.')
            ->assertSee('Received at Santa Rosa Sorting Center.')
            ->assertSee('Today · 10:15 AM')
            ->assertSee('Not provided')
            ->assertSee('Awaiting assignment')
            ->assertSee('No tracking scans recorded yet')
            ->assertSee('data-tracking-timeline', false)
            ->assertSee('data-lucide="package"', false)
            ->assertSee('data-lucide="user"', false)
            ->assertSee('data-lucide="map-pin"', false)
            ->assertSee('data-lucide="calendar-days"', false);
    }

    public function test_authoritative_riders_come_from_pickup_assignments_and_dispatch_batches(): void
    {
        [$seller, , $store] = $this->sellerFixture('Rider Seller', 'rider-seller');
        $logistics = $this->logisticsProfile('Bearly Logistics');

        $pickupShipment = $this->makeShipment($store, 'RIDE-PICK', 'picked_up', logisticsProfile: $logistics);
        $pickupRequest = $this->pickupRequest($store, $logistics, 'PU-PICK');
        $pickupRequest->parcels()->attach($pickupShipment['parcel']->id, ['added_at' => now()]);
        PickupAssignment::query()->create([
            'pickup_request_id' => $pickupRequest->id,
            'rider_profile_id' => $this->rider('Paolo Reyes', $logistics)->id,
            'status' => 'picked_up',
            'assigned_at' => now()->subHours(3),
            'picked_up_at' => now()->subHours(2),
        ]);

        // The newest assignment is cancelled: no pickup rider may be shown.
        $cancelledShipment = $this->makeShipment($store, 'RIDE-CAN', 'picked_up', logisticsProfile: $logistics);
        $cancelledRequest = $this->pickupRequest($store, $logistics, 'PU-CAN');
        $cancelledRequest->parcels()->attach($cancelledShipment['parcel']->id, ['added_at' => now()]);
        PickupAssignment::query()->create([
            'pickup_request_id' => $cancelledRequest->id,
            'rider_profile_id' => $this->rider('Ghost Rider', $logistics)->id,
            'status' => 'cancelled',
            'assigned_at' => now()->subHour(),
            'cancelled_at' => now(),
        ]);

        // A dispatched delivery batch outranks the pickup rider.
        $deliveryShipment = $this->makeShipment($store, 'RIDE-DEL', 'out_for_delivery', logisticsProfile: $logistics);
        $deliveryRequest = $this->pickupRequest($store, $logistics, 'PU-DEL');
        $deliveryRequest->parcels()->attach($deliveryShipment['parcel']->id, ['added_at' => now()]);
        PickupAssignment::query()->create([
            'pickup_request_id' => $deliveryRequest->id,
            'rider_profile_id' => $this->rider('Pickup Only', $logistics)->id,
            'status' => 'completed',
            'assigned_at' => now()->subHours(6),
            'picked_up_at' => now()->subHours(5),
            'completed_at' => now()->subHours(4),
        ]);

        $batch = DispatchBatch::query()->create([
            'batch_no' => 'BATCH-RIDE-DEL',
            'sorting_center_id' => $this->sortingCenter($logistics, 'RIDE')->id,
            'rider_profile_id' => $this->rider('John Ramos', $logistics)->id,
            'status' => 'dispatched',
            'prepared_at' => now()->subHour(),
            'assigned_at' => now()->subHour(),
            'dispatched_at' => now()->subHour(),
        ]);
        $batch->parcels()->attach($deliveryShipment['parcel']->id, ['sequence' => 1, 'loaded_at' => now()]);

        $this->actingAs($seller)
            ->get(route('seller.fulfillment.tracking'))
            ->assertOk()
            ->assertSee('Paolo Reyes')
            ->assertSee('Pickup rider')
            ->assertSee('John Ramos')
            ->assertSee('Delivery rider')
            ->assertDontSee('Ghost Rider')
            ->assertDontSee('Pickup Only');
    }

    public function test_empty_state_renders_when_no_shipments_are_trackable(): void
    {
        [$seller, , $store] = $this->sellerFixture('Empty Seller', 'empty-seller');

        // Pre-handover shipments stay on the Pickup Requests page.
        $this->makeShipment($store, 'EMPTY-PRE', 'ready_for_pickup');

        $this->actingAs($seller)
            ->get(route('seller.fulfillment.tracking'))
            ->assertOk()
            ->assertSee('All Shipments <span>0</span>', false)
            ->assertSee('No shipments to track yet')
            ->assertSee('Outbound parcels appear here after pickup handover to logistics.')
            ->assertDontSee('No matching shipments')
            ->assertDontSee('needs attention');
    }

    public function test_admin_override_only_statuses_remain_visible_in_the_correct_buckets(): void
    {
        [$seller, , $store] = $this->sellerFixture('Override Seller', 'override-seller');

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $inTransit = $this->makeShipment($store, 'OVR-TRANSIT', 'at_sorting_center');
        $exception = $this->makeShipment($store, 'OVR-EXCEPTION', 'at_sorting_center');

        $this->actingAs($admin)
            ->patch(route('admin.fulfillment.override', $inTransit['shipment']), [
                'status' => 'in_transit',
                'note' => 'Rerouted by an administrator.',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->patch(route('admin.fulfillment.override', $exception['shipment']), [
                'status' => 'exception',
                'note' => 'Held for administrator review.',
            ])
            ->assertRedirect();

        $this->assertSame('in_transit', $inTransit['shipment']->fresh()->status);
        $this->assertSame('exception', $exception['shipment']->fresh()->status);

        $this->actingAs($seller)
            ->get(route('seller.fulfillment.tracking'))
            ->assertOk()
            ->assertSee('BRLY-OVR-TRANSIT')
            ->assertSee('BRLY-OVR-EXCEPTION')
            ->assertSee('fulfillment-status is-transit"', false)
            ->assertSee('fulfillment-status is-failed"', false)
            ->assertSee('Sorted / In Transit <span>1</span>', false)
            ->assertSee('Needs Attention <span>1</span>', false)
            ->assertSee('Exception')
            ->assertSee('Admin Override');
    }

    public function test_delivered_proof_panel_no_longer_offers_a_fake_view_action(): void
    {
        [$seller, , $store] = $this->sellerFixture('Proof Seller', 'proof-seller');

        $this->makeShipment($store, 'PROOF', 'delivered');

        $this->actingAs($seller)
            ->get(route('seller.fulfillment.tracking'))
            ->assertOk()
            ->assertSee('Proof of delivery recorded')
            ->assertDontSee('View Proof')
            ->assertDontSee('data-fulfillment-demo', false);
    }

    public function test_tracking_issue_report_uses_the_persisted_support_ticket_workflow(): void
    {
        [$seller, , $store] = $this->sellerFixture('Support Seller', 'support-seller');
        $fixture = $this->makeShipment($store, 'SUPPORT', 'out_for_delivery');

        $this->actingAs($seller)
            ->get(route('seller.fulfillment.tracking'))
            ->assertOk()
            ->assertSee('action="'.route('contact.store').'"', false)
            ->assertSee('data-shipment-report-form', false)
            ->assertSee('name="topic" value="Delivery &amp; Tracking"', false)
            ->assertSee('File attachments are not available in this form yet.');

        $response = $this->actingAs($seller)
            ->postJson(route('contact.store'), [
                'user_id' => 999_999,
                'name' => $seller->name,
                'email' => $seller->email,
                'role' => 'Seller',
                'topic' => 'Delivery & Tracking',
                'reference' => $fixture['waybill']->waybill_no,
                'subject' => 'Shipment issue: '.$fixture['waybill']->waybill_no,
                'message' => 'Issue type: No tracking update. The latest scan has not appeared.',
            ]);

        $response->assertCreated();
        $this->assertStringStartsWith('TKT-', (string) $response->json('data.ticket_no'));

        $this->assertDatabaseHas('support_tickets', [
            'user_id' => $seller->id,
            'topic' => 'Delivery & Tracking',
            'reference' => $fixture['waybill']->waybill_no,
            'subject' => 'Shipment issue: '.$fixture['waybill']->waybill_no,
        ]);
    }

    public function test_rows_show_the_product_thumbnail_and_item_count(): void
    {
        [$seller, , $store] = $this->sellerFixture('Catalog Seller', 'catalog-seller');

        $fixture = $this->makeShipment($store, 'CAT', 'sorted');

        OrderItem::query()->create([
            'seller_order_id' => $fixture['sellerOrder']->id,
            'product_name' => 'Handmade Rattan Basket',
            'variant_name' => 'Natural',
            'sku' => 'RATTAN-NAT',
            'unit_price_minor' => 10_000,
            'quantity' => 1,
            'subtotal_minor' => 10_000,
            'product_image_disk' => 'public',
            'product_image_path' => '/storage/products/rattan-basket.jpg',
        ]);
        OrderItem::query()->create([
            'seller_order_id' => $fixture['sellerOrder']->id,
            'product_name' => 'Woven Placemat',
            'variant_name' => 'Cream',
            'sku' => 'PLACE-CREAM',
            'unit_price_minor' => 5_000,
            'quantity' => 1,
            'subtotal_minor' => 5_000,
        ]);

        $this->actingAs($seller)
            ->get(route('seller.fulfillment.tracking'))
            ->assertOk()
            ->assertSee('/storage/products/rattan-basket.jpg')
            ->assertSee('title="Handmade Rattan Basket"', false)
            ->assertSee('· 2 items')
            ->assertSee('handmade rattan basket', false)
            ->assertSee('woven placemat', false);
    }

    /**
     * @return array{0: User, 1: SellerProfile, 2: Store}
     */
    private function sellerFixture(string $name, string $slug): array
    {
        $seller = User::factory()->create([
            'name' => $name,
            'role' => 'seller',
            'status' => 'active',
        ]);
        $profile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => $name,
            'standing_status' => 'good_standing',
            'approved_at' => now(),
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => $name,
            'slug' => $slug,
            'publication_status' => 'published',
        ]);

        return [$seller, $profile, $store];
    }

    /**
     * @return array{shipment: Shipment, waybill: Waybill, parcel: Parcel, sellerOrder: SellerOrder}
     */
    private function makeShipment(
        Store $store,
        string $suffix,
        string $status,
        string $purpose = 'outbound',
        ?LogisticsProfile $logisticsProfile = null,
    ): array {
        $logisticsProfile ??= $this->logisticsProfile('Bearly Logistics');

        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);

        $order = Order::query()->create([
            'order_no' => 'ORD-'.$suffix,
            'buyer_id' => $buyer->id,
            'recipient_name' => 'Customer '.$suffix,
            'recipient_phone' => '09171234567',
            'address_line' => '1 Tracking Street',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
            'subtotal_minor' => 10_000,
            'shipping_fee_minor' => 0,
            'discount_minor' => 0,
            'total_minor' => 10_000,
            'status' => 'processing',
            'payment_status' => 'paid',
        ]);

        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => 'SO-'.$suffix,
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => 'handed_to_logistics',
            'subtotal_minor' => 10_000,
            'total_minor' => 10_000,
        ]);

        $shipment = Shipment::query()->create([
            'shipment_no' => 'SHP-'.$suffix,
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $logisticsProfile->id,
            'origin_logistics_profile_id' => $logisticsProfile->id,
            'purpose' => $purpose,
            'status' => $status,
            'shipping_fee_minor' => 0,
            'cod_amount_minor' => 0,
        ]);

        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => 'BRLY-'.$suffix,
            'scan_token' => hash('sha256', 'scan-'.$suffix),
            'barcode_value' => 'BAR-'.$suffix,
            'piece_count' => 1,
            'total_weight_kg' => 1.250,
            'status' => 'generated',
            'generated_at' => now(),
        ]);

        $parcel = Parcel::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_id' => $waybill->id,
            'parcel_no' => 'PCL-'.$suffix,
            'piece_sequence' => 1,
            'weight_kg' => 1.250,
            'status' => 'picked_up',
        ]);

        return compact('shipment', 'waybill', 'parcel', 'sellerOrder');
    }

    private function logisticsProfile(string $name): LogisticsProfile
    {
        $user = User::factory()->create(['role' => 'logistics', 'status' => 'active']);

        return LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => $name,
            'display_name' => $name,
            'contact_phone' => '09170000000',
            'status' => 'active',
        ]);
    }

    private function rider(string $name, LogisticsProfile $profile): RiderProfile
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'rider', 'status' => 'active']);

        return RiderProfile::query()->create([
            'user_id' => $user->id,
            'logistics_profile_id' => $profile->id,
            'vehicle_type' => 'motorcycle',
            'verification_status' => 'verified',
        ]);
    }

    private function pickupRequest(Store $store, LogisticsProfile $profile, string $suffix): PickupRequest
    {
        return PickupRequest::query()->create([
            'pickup_no' => $suffix,
            'store_id' => $store->id,
            'logistics_profile_id' => $profile->id,
            'pickup_address_id' => $this->address($suffix)->id,
            'status' => 'picked_up',
            'requested_date' => now()->toDateString(),
            'window_start' => now()->subHours(4),
            'window_end' => now()->subHours(3),
        ]);
    }

    private function sortingCenter(LogisticsProfile $profile, string $suffix): SortingCenter
    {
        return SortingCenter::query()->create([
            'logistics_profile_id' => $profile->id,
            'address_id' => $this->address($suffix)->id,
            'name' => 'Center '.$suffix,
            'code' => 'CTR-'.$suffix,
            'status' => 'active',
        ]);
    }

    private function address(string $suffix): Address
    {
        return Address::query()->create([
            'recipient_name' => 'Address '.$suffix,
            'phone' => '09170000001',
            'house_number' => '12',
            'street' => 'Tracking Street',
            'barangay' => 'Balibago',
            'city_municipality' => 'Santa Rosa',
            'province' => 'Laguna',
        ]);
    }
}
