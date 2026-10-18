<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Address;
use App\Models\AuditLog;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PickupRequest;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use App\Notifications\WorkflowNotification;
use App\Services\SellerPickupRequestService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class SellerPickupRequestIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_seller_can_submit_multiple_ready_orders_and_logistics_sees_the_real_pickup(): void
    {
        [$seller, $store] = $this->seller();
        $provider = $this->provider('main');
        Notification::fake();
        $sellerOrders = [
            $this->readySellerOrder($store, 'first'),
            $this->readySellerOrder($store, 'second'),
        ];

        $this->actingAs($seller)
            ->get(route('seller.fulfillment.pickups'))
            ->assertOk()
            ->assertSee($sellerOrders[0]->seller_order_no)
            ->assertSee($sellerOrders[1]->seller_order_no)
            ->assertSee('name="order_ids[]"', false)
            ->assertSee('waybill generated on submission');

        $this->actingAs($seller)
            ->post(route('seller.fulfillment.pickups.store'), [
                'order_ids' => [$sellerOrders[0]->id, $sellerOrders[1]->id],
                'logistics_profile_id' => $provider['profile']->id,
                'requested_date' => today()->addDay()->toDateString(),
                'pickup_window' => '10:00-12:00',
                'instructions' => 'Use the side entrance.',
            ])
            ->assertRedirect(route('seller.fulfillment.pickups'))
            ->assertSessionHas('success');

        $pickup = PickupRequest::query()->with('parcels')->sole();
        $this->assertSame('requested', $pickup->status);
        $this->assertSame($store->id, (int) $pickup->store_id);
        $this->assertSame($provider['profile']->id, (int) $pickup->logistics_profile_id);
        $this->assertSame(2, $pickup->parcels->count());
        $this->assertSame('Use the side entrance.', $pickup->seller_instructions);
        $this->assertSame(['ready_for_pickup', 'ready_for_pickup'], array_map(fn (SellerOrder $order): string => $order->fresh()->status, $sellerOrders));

        Notification::assertSentTo(
            $provider['user'],
            WorkflowNotification::class,
            fn (WorkflowNotification $notification): bool => $notification->eventKey() === 'logistics_pickup_requested'
                && $notification->meta()['pickup_request_id'] === $pickup->id,
        );

        $shipments = Shipment::query()->orderBy('seller_order_id')->get();
        $this->assertCount(2, $shipments);
        foreach ($sellerOrders as $index => $sellerOrder) {
            $shipment = $shipments[$index];
            $this->assertSame($sellerOrder->id, (int) $shipment->seller_order_id);
            $this->assertSame($provider['profile']->id, (int) $shipment->logistics_profile_id);
            $this->assertSame('ready_for_pickup', $shipment->status);
            $this->assertSame(12345, (int) $shipment->cod_amount_minor);

            $waybill = Waybill::query()->where('shipment_id', $shipment->id)->sole();
            $this->assertSame('generated', $waybill->status);
            $this->assertSame(1.25, (float) $waybill->total_weight_kg);
            $this->assertSame(32.0, (float) $waybill->length_cm);
            $this->assertSame(64, strlen($waybill->scan_token));
            $this->assertDatabaseHas('parcels', [
                'shipment_id' => $shipment->id,
                'waybill_id' => $waybill->id,
                'status' => 'created',
            ]);
            $this->assertDatabaseHas('shipment_events', [
                'shipment_id' => $shipment->id,
                'event_type' => 'shipment_ready_for_pickup',
                'to_status' => 'ready_for_pickup',
                'actor_user_id' => $seller->id,
                'source' => 'seller',
            ]);
        }

        $this->actingAs($provider['user'])
            ->get(route('logistics.pickups.index'))
            ->assertOk()
            ->assertSee($pickup->pickup_no)
            ->assertSee($store->name)
            ->assertSee($sellerOrders[0]->seller_order_no)
            ->assertSee($sellerOrders[1]->seller_order_no)
            ->assertSeeHtml('<span><small>Total parcels</small><strong>2</strong></span>')
            ->assertSee('Pending');

        $this->actingAs($provider['user'])
            ->post(route('logistics.pickups.verify', $pickup))
            ->assertRedirect();

        $rider = $this->rider($provider, 'main');
        $this->actingAs($provider['user'])
            ->post(route('logistics.pickups.assign', $pickup), [
                'rider_profile_id' => $rider->id,
            ])
            ->assertRedirect();

        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.accept', $pickup->pickup_no))
            ->assertRedirect();
        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.confirm', $pickup->pickup_no))
            ->assertRedirect();

        $this->assertSame('scheduled', $pickup->fresh()->status);
        $this->assertSame('picked_up', $pickup->fresh('latestAssignment')->latestAssignment->status);
        $this->assertSame('picked_up', Shipment::query()->orderBy('seller_order_id')->firstOrFail()->status);
        $firstParcel = $pickup->fresh('parcels')->parcels->first();
        $this->assertNotNull($firstParcel);
        $this->assertSame('picked_up', $firstParcel->status);
        $this->assertSame(['handed_to_logistics', 'handed_to_logistics'], array_map(fn (SellerOrder $order): string => $order->fresh()->status, $sellerOrders));
        foreach ($sellerOrders as $sellerOrder) {
            $this->assertNotNull($sellerOrder->fresh()->handed_over_at);
            $this->assertDatabaseHas('seller_order_status_histories', [
                'seller_order_id' => $sellerOrder->id,
                'from_status' => 'ready_for_pickup',
                'to_status' => 'handed_to_logistics',
                'actor_user_id' => $rider->user_id,
            ]);
        }

        $this->actingAs($seller)
            ->get(route('seller.fulfillment.pickups'))
            ->assertOk()
            ->assertSee($pickup->pickup_no)
            ->assertSee('Rider Collected')
            ->assertSee('No orders are currently eligible for a new pickup request.');
    }

    public function test_seller_can_save_optional_coordinates_for_the_registered_pickup_address(): void
    {
        [$seller] = $this->seller();
        $address = $seller->sellerProfile->pickupAddress;

        $this->actingAs($seller)
            ->patch(route('seller.store.pickup.coordinates'), [
                'latitude' => 14.3121,
                'longitude' => 121.1112,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Pickup map pin saved.');

        $this->assertSame(14.3121, (float) $address->fresh()->latitude);
        $this->assertSame(121.1112, (float) $address->fresh()->longitude);

        $this->actingAs($seller)
            ->patchJson(route('seller.store.pickup.coordinates'), [
                'latitude' => 14.3121,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('longitude');
    }

    public function test_seller_cannot_submit_another_store_order_or_a_non_ready_order(): void
    {
        [$seller, $store] = $this->seller();
        [, $foreignStore] = $this->seller();
        $provider = $this->provider('scope');
        $ownedOrder = $this->readySellerOrder($store, 'owned');
        $foreignOrder = $this->readySellerOrder($foreignStore, 'foreign');

        $payload = [
            'order_ids' => [$foreignOrder->id],
            'logistics_profile_id' => $provider['profile']->id,
            'requested_date' => today()->addDay()->toDateString(),
            'pickup_window' => '13:00-15:00',
        ];

        $this->actingAs($seller)
            ->post(route('seller.fulfillment.pickups.store'), $payload)
            ->assertNotFound();

        $payload['order_ids'] = [$ownedOrder->id];
        $ownedOrder->update(['status' => 'packing']);

        $this->actingAs($seller)
            ->post(route('seller.fulfillment.pickups.store'), $payload)
            ->assertConflict();

        $this->assertDatabaseCount('pickup_requests', 0);
        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseCount('waybills', 0);
        $this->assertDatabaseCount('parcels', 0);
    }

    public function test_replaying_a_pickup_submission_cannot_duplicate_fulfillment_records(): void
    {
        [$seller, $store] = $this->seller();
        $provider = $this->provider('replay');
        $sellerOrder = $this->readySellerOrder($store, 'replay');
        $payload = [
            'order_ids' => [$sellerOrder->id],
            'logistics_profile_id' => $provider['profile']->id,
            'requested_date' => today()->addDay()->toDateString(),
            'pickup_window' => '15:00-17:00',
        ];

        $this->actingAs($seller)
            ->post(route('seller.fulfillment.pickups.store'), $payload)
            ->assertRedirect();

        $this->actingAs($seller)
            ->post(route('seller.fulfillment.pickups.store'), $payload)
            ->assertConflict();

        $this->assertDatabaseCount('pickup_requests', 1);
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseCount('waybills', 1);
        $this->assertDatabaseCount('parcels', 1);
        $this->assertDatabaseCount('shipment_events', 1);
    }

    public function test_seller_order_tracking_shows_live_delivery_without_marking_it_completed(): void
    {
        [$seller, $store] = $this->seller();
        $provider = $this->provider('live-tracking');
        $sellerOrder = $this->readySellerOrder($store, 'live-tracking');
        $shipment = Shipment::query()->create([
            'shipment_no' => 'LIVE-'.Str::upper(Str::random(12)),
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider['profile']->id,
            'status' => 'delivered',
        ]);
        ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'event_type' => 'shipment_delivered',
            'from_status' => 'out_for_delivery',
            'to_status' => 'delivered',
            'actor_user_id' => $provider['user']->id,
            'source' => 'rider',
            'occurred_at' => now(),
        ]);

        $this->actingAs($seller)
            ->get(route('seller.orders'))
            ->assertOk()
            ->assertSee('Delivered')
            ->assertSee('Awaiting Buyer confirmation')
            ->assertSee(route('seller.orders.tracking', $sellerOrder->id), false);

        $this->actingAs($seller)
            ->getJson(route('seller.orders.tracking', $sellerOrder->id))
            ->assertOk()
            ->assertJsonPath('data.seller_order_status', 'ready_for_pickup')
            ->assertJsonPath('data.status_label', 'Delivered')
            ->assertJsonPath('data.status_detail', 'Awaiting Buyer confirmation')
            ->assertJsonPath('data.canonical_status', 'DELIVERED')
            ->assertJsonPath('data.status_key', 'shipped')
            ->assertJsonPath('data.responsibility', 'Buyer')
            ->assertJsonPath('data.timeline_history.0.label', 'Delivered to recipient');

        $this->assertSame('ready_for_pickup', $sellerOrder->fresh()->status);

        [, $foreignStore] = $this->seller();
        $foreignOrder = $this->readySellerOrder($foreignStore, 'foreign-tracking');

        $this->actingAs($seller)
            ->getJson(route('seller.orders.tracking', $foreignOrder->id))
            ->assertNotFound();
    }

    public function test_seller_tracking_prefers_most_advanced_active_shipment(): void
    {
        [$seller, $store] = $this->seller();
        $provider = $this->provider('multi-shipment');
        $sellerOrder = $this->readySellerOrder($store, 'multi-shipment');
        $lagging = Shipment::query()->create([
            'shipment_no' => 'LAGGING-'.Str::upper(Str::random(12)),
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider['profile']->id,
            'status' => 'picked_up',
        ]);
        $leading = Shipment::query()->create([
            'shipment_no' => 'LEADING-'.Str::upper(Str::random(12)),
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider['profile']->id,
            'status' => 'out_for_delivery',
        ]);

        $this->actingAs($seller)
            ->getJson(route('seller.orders.tracking', $sellerOrder->id))
            ->assertOk()
            ->assertJsonPath('data.canonical_status', 'OUT_FOR_DELIVERY')
            ->assertJsonPath('data.status_label', 'Out for Delivery')
            ->assertJsonPath('data.status_key', 'shipped');

        $leading->update(['status' => 'delivered']);
        ShipmentEvent::query()->create([
            'shipment_id' => $leading->id,
            'event_type' => 'shipment_delivered',
            'from_status' => 'out_for_delivery',
            'to_status' => 'delivered',
            'actor_user_id' => $provider['user']->id,
            'source' => 'rider',
            'occurred_at' => now(),
        ]);

        // The delivered leg must not mask the shipment still in transit.
        $this->actingAs($seller)
            ->getJson(route('seller.orders.tracking', $sellerOrder->id))
            ->assertOk()
            ->assertJsonPath('data.canonical_status', 'PICKED_UP')
            ->assertJsonPath('data.status_label', 'Picked Up');

        $this->assertSame('ready_for_pickup', $sellerOrder->fresh()->status);
        $this->assertSame('picked_up', $lagging->fresh()->status);
    }

    public function test_seller_waybills_are_store_scoped_and_pdf_print_actions_are_audited(): void
    {
        [$seller, $store] = $this->seller();
        [$foreignSeller, $foreignStore] = $this->seller();
        $provider = $this->provider('waybills');
        $ownedOrder = $this->readySellerOrder($store, 'waybill-owned');
        $foreignOrder = $this->readySellerOrder($foreignStore, 'waybill-foreign');
        $pickupRequests = app(SellerPickupRequestService::class);
        $pickupDate = today()->addDay()->toDateString();

        $pickupRequests->create($seller, $provider['profile']->id, [$ownedOrder->id], $pickupDate, '10:00-12:00');
        $pickupRequests->create($foreignSeller, $provider['profile']->id, [$foreignOrder->id], $pickupDate, '13:00-15:00');

        $ownedWaybill = Waybill::query()
            ->whereHas('shipment.sellerOrder', fn ($query) => $query->whereKey($ownedOrder->id))
            ->sole();
        $foreignWaybill = Waybill::query()
            ->whereHas('shipment.sellerOrder', fn ($query) => $query->whereKey($foreignOrder->id))
            ->sole();

        $this->actingAs($seller)
            ->get(route('seller.fulfillment.waybills'))
            ->assertOk()
            ->assertSee($ownedWaybill->waybill_no)
            ->assertDontSee($foreignWaybill->waybill_no)
            ->assertSee($ownedOrder->seller_order_no)
            ->assertSee('Download PDF');

        $this->actingAs($seller)
            ->post(route('seller.fulfillment.waybills.printed', $ownedWaybill))
            ->assertConflict();
        $this->assertSame(0, (int) $ownedWaybill->fresh()->print_count);

        $pdfResponse = $this->actingAs($seller)
            ->post(route('seller.fulfillment.waybills.pdf'), [
                'waybill_ids' => [$ownedWaybill->id],
            ])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $pdf = $pdfResponse->getContent();
        $this->assertIsString($pdf);
        $this->assertStringStartsWith('%PDF-', $pdf);
        preg_match_all('/\/MediaBox\s*\[([^\]]+)\]/', $pdf, $mediaBoxMatches);
        $this->assertNotEmpty($mediaBoxMatches[1], 'The PDF must include an explicit page size.');
        preg_match_all('/\/Type\s*\/Page\b/', $pdf, $pageMatches);
        $this->assertCount(1, $pageMatches[0], 'One waybill must render on exactly one PDF page.');
        $mediaBox = preg_split('/\s+/', trim(end($mediaBoxMatches[1])));
        $this->assertSame([0.0, 0.0, 288.0, 432.0], array_map('floatval', $mediaBox));
        $this->assertStringContainsString('/DCTDecode', $pdf, 'The official Bearly logo should be embedded as a JPEG without requiring GD.');
        $pdfContent = $this->decodedPdfStreams($pdf);
        preg_match_all('/0\.000 0\.000 0\.000 rg\s+[\d.]+\s+[\d.]+\s+[\d.]+\s+[\d.]+\s+re f/', $pdfContent, $barcodeBars);
        $this->assertGreaterThan(40, count($barcodeBars[0]), 'The PDF must draw the Code 128 bars, not just show its text.');
        $this->assertStringContainsString("C\0O\0D", $pdfContent, 'The COD section must remain on the single label page.');
        $this->assertStringNotContainsString($ownedWaybill->scan_token, $pdf);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $seller->id,
            'action' => 'seller_waybill.pdf_generated',
            'auditable_type' => Waybill::class,
            'auditable_id' => $ownedWaybill->id,
        ]);

        $this->actingAs($seller)
            ->post(route('seller.fulfillment.waybills.printed', $ownedWaybill))
            ->assertRedirect(route('seller.fulfillment.waybills'));
        $this->assertSame(1, (int) $ownedWaybill->fresh()->print_count);
        $this->assertNotNull($ownedWaybill->fresh()->printed_at);

        $this->actingAs($seller)
            ->post(route('seller.fulfillment.waybills.printed', $ownedWaybill))
            ->assertRedirect(route('seller.fulfillment.waybills'));
        $this->assertSame(2, (int) $ownedWaybill->fresh()->print_count);
        $this->assertSame(2, AuditLog::query()
            ->where('action', 'seller_waybill.print_confirmed')
            ->where('auditable_id', $ownedWaybill->id)
            ->count());

        $this->actingAs($seller)
            ->post(route('seller.fulfillment.waybills.pdf'), [
                'waybill_ids' => [$foreignWaybill->id],
            ])
            ->assertNotFound();
        $this->actingAs($seller)
            ->post(route('seller.fulfillment.waybills.printed', $foreignWaybill))
            ->assertNotFound();

        $ownedWaybill->update(['status' => 'voided', 'voided_at' => now()]);
        $this->actingAs($seller)
            ->post(route('seller.fulfillment.waybills.pdf'), [
                'waybill_ids' => [$ownedWaybill->id],
            ])
            ->assertConflict();
        $this->actingAs($seller)
            ->post(route('seller.fulfillment.waybills.printed', $ownedWaybill))
            ->assertConflict();
    }

    private function decodedPdfStreams(string $pdf): string
    {
        preg_match_all('/<<(.*?)>>\s*stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams, PREG_SET_ORDER);
        $content = '';

        foreach ($streams as $stream) {
            $bytes = $stream[2];

            if (str_contains($stream[1], '/FlateDecode')) {
                $decoded = @gzuncompress($bytes);
                if ($decoded === false) {
                    continue;
                }
                $bytes = $decoded;
            }

            $content .= $bytes;
        }

        return $content;
    }

    /** @return array{0: User, 1: Store} */
    private function seller(): array
    {
        $suffix = ++$this->sequence;
        $user = User::factory()->create([
            'name' => "Seller {$suffix}",
            'email' => "seller-{$suffix}@example.test",
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
            'slug' => "seller-store-{$suffix}",
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
            'email' => 'logistics-'.Str::slug($suffix).'@example.test',
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

    /** @param array{user: User, profile: LogisticsProfile} $provider */
    private function rider(array $provider, string $suffix): RiderProfile
    {
        $user = User::factory()->create([
            'name' => "Rider {$suffix}",
            'email' => 'rider-'.Str::slug($suffix).'-'.(++$this->sequence).'@example.test',
            'role' => UserRole::Rider->value,
            'status' => 'active',
            'logistics_id' => $provider['user']->id,
        ]);
        $user->roles()->syncWithoutDetaching([
            Role::query()->where('name', UserRole::Rider->value)->value('id'),
        ]);

        return RiderProfile::query()->create([
            'user_id' => $user->id,
            'logistics_profile_id' => $provider['profile']->id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'RIDER-'.strtoupper($suffix),
            'availability_status' => 'available',
            'verification_status' => 'approved',
        ]);
    }

    private function readySellerOrder(Store $store, string $suffix): SellerOrder
    {
        $buyer = User::factory()->create([
            'name' => "Buyer {$suffix}",
            'email' => 'buyer-'.Str::slug($suffix).'-'.(++$this->sequence).'@example.test',
            'role' => UserRole::Buyer->value,
            'status' => 'active',
        ]);
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
            'subtotal_minor' => 8945,
            'shipping_fee_minor' => 3400,
            'discount_minor' => 0,
            'total_minor' => 12345,
            'status' => 'processing',
            'payment_status' => 'unpaid',
            'placed_at' => now(),
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => 'SO-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => 'ready_for_pickup',
            'subtotal_minor' => 8945,
            'discount_minor' => 0,
            'shipping_fee_minor' => 3400,
            'total_minor' => 12345,
            'ready_at' => now(),
            'delivery_provider_key' => 'bearly_logistics',
            'shipping_quote' => [
                'provider_key' => 'bearly_logistics',
                'actual_weight_kg' => 1.25,
                'billable_weight_kg' => 1,
                'parcel_dimensions_cm' => [
                    'length' => 32,
                    'width' => 20,
                    'height' => 10,
                ],
            ],
        ]);
        $sellerOrder->items()->create([
            'product_name' => 'Snapshot Product',
            'variant_name' => 'Blue / Medium',
            'sku' => 'SNAPSHOT-'.Str::upper(Str::random(8)),
            'unit_price_minor' => 8945,
            'quantity' => 1,
            'discount_minor' => 0,
            'subtotal_minor' => 8945,
        ]);
        Payment::query()->create([
            'payment_no' => 'PAY-'.Str::upper(Str::random(12)),
            'order_id' => $order->id,
            'method' => 'cod',
            'amount_minor' => 12345,
            'status' => 'pending',
            'initiated_at' => now(),
        ]);

        return $sellerOrder;
    }
}
