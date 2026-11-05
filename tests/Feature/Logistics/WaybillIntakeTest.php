<?php

namespace Tests\Feature\Logistics;

use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\LinehaulRun;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\ShipmentTransfer;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\PickupAssignment;
use App\Models\PickupRequest;
use App\Models\RiderProfile;
use App\Models\RiderEarning;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use App\Services\ParcelIntakeService;
use App\Services\LinehaulRunService;
use App\Services\ShipmentHandoffService;
use App\Services\RiderShiftService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class WaybillIntakeTest extends TestCase
{
    use RefreshDatabase;

    private User $logisticsA;
    private User $logisticsB;
    private LogisticsProfile $profileA;
    private LogisticsProfile $profileB;
    private SortingCenter $centerA;
    private SortingCenter $centerB;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->logisticsA, $this->profileA, $this->centerA] =
            $this->makeLogisticsProvider('A');

        [$this->logisticsB, $this->profileB, $this->centerB] =
            $this->makeLogisticsProvider('B');
    }

    public function test_sorting_center_intake_completes_a_picked_up_pickup(): void
    {
        $record =
            $this->makeShipmentFor(
                $this->profileA,
                'PICKUP-COMPLETE'
            );

        $pickup =
            $this->makePickedUpPickup(
                $record,
                'PICKUP-COMPLETE'
            );

        app(ParcelIntakeService::class)
            ->receive(
                $record['waybill'],
                $this->centerA,
                $this->logisticsA,
                'manual'
            );

        $this->assertSame(
            ParcelStatus::Received->value,
            $record['parcel']
                ->fresh()
                ->status
        );

        $this->assertSame(
            ShipmentStatus::AtSortingCenter->value,
            $record['shipment']
                ->fresh()
                ->status
        );

        $this->assertSame(
            'completed',
            $pickup['request']
                ->fresh()
                ->status
        );

        $this->assertNotNull(
            $pickup['request']
                ->fresh()
                ->completed_at
        );

        $this->assertSame(
            'completed',
            $pickup['assignment']
                ->fresh()
                ->status
        );

        $this->assertNotNull(
            $pickup['assignment']
                ->fresh()
                ->completed_at
        );
    }

    public function test_pickup_completes_only_after_every_attached_parcel_reaches_center(): void
    {
        $first =
            $this->makeShipmentFor(
                $this->profileA,
                'PICKUP-MULTI-A'
            );

        $second =
            $this->makeShipmentFor(
                $this->profileA,
                'PICKUP-MULTI-B'
            );

        /*
        * Both fulfillment records represent parcels
        * collected from the same seller/store.
        */
        $second['shipment']
            ->sellerOrder
            ->update([
                'store_id' =>
                    $first['shipment']
                        ->sellerOrder
                        ->store_id,
            ]);

        $pickup =
            $this->makePickedUpPickup(
                $first,
                'PICKUP-MULTI'
            );

        $pickup['request']
            ->parcels()
            ->attach(
                $second['parcel']->id
            );

        /*
        * First waybill reaches the center.
        */
        app(ParcelIntakeService::class)
            ->receive(
                $first['waybill'],
                $this->centerA,
                $this->logisticsA,
                'manual'
            );

        $this->assertSame(
            ParcelStatus::Received->value,
            $first['parcel']
                ->fresh()
                ->status
        );

        $this->assertSame(
            ParcelStatus::PickedUp->value,
            $second['parcel']
                ->fresh()
                ->status
        );

        /*
        * Pickup remains open because another attached
        * parcel still has no received_at_center event.
        */
        $this->assertSame(
            'scheduled',
            $pickup['request']
                ->fresh()
                ->status
        );

        $this->assertNull(
            $pickup['request']
                ->fresh()
                ->completed_at
        );

        $this->assertSame(
            'picked_up',
            $pickup['assignment']
                ->fresh()
                ->status
        );

        $this->assertNull(
            $pickup['assignment']
                ->fresh()
                ->completed_at
        );

        /*
        * Second waybill reaches the Sorting Center.
        */
        app(ParcelIntakeService::class)
            ->receive(
                $second['waybill'],
                $this->centerA,
                $this->logisticsA,
                'manual'
            );

        $this->assertSame(
            'completed',
            $pickup['request']
                ->fresh()
                ->status
        );

        $this->assertNotNull(
            $pickup['request']
                ->fresh()
                ->completed_at
        );

        $this->assertSame(
            'completed',
            $pickup['assignment']
                ->fresh()
                ->status
        );

        $this->assertNotNull(
            $pickup['assignment']
                ->fresh()
                ->completed_at
        );
    }

    public function test_intake_does_not_complete_pickup_before_rider_collection(): void
    {
        $record =
            $this->makeShipmentFor(
                $this->profileA,
                'PICKUP-NOT-COLLECTED'
            );

        $pickup =
            $this->makePickedUpPickup(
                $record,
                'PICKUP-NOT-COLLECTED'
            );

        $pickup['assignment']
            ->update([
                'status' =>
                    'accepted',

                'picked_up_at' =>
                    null,
            ]);

        app(ParcelIntakeService::class)
            ->receive(
                $record['waybill'],
                $this->centerA,
                $this->logisticsA,
                'manual'
            );

        $this->assertSame(
            'scheduled',
            $pickup['request']
                ->fresh()
                ->status
        );

        $this->assertNull(
            $pickup['request']
                ->fresh()
                ->completed_at
        );

        $this->assertSame(
            'accepted',
            $pickup['assignment']
                ->fresh()
                ->status
        );

        $this->assertNull(
            $pickup['assignment']
                ->fresh()
                ->completed_at
        );
    }

    public function test_logistics_can_look_up_its_own_waybill(): void
    {
        ['waybill' => $waybill] =
            $this->makeShipmentFor($this->profileA, 'A1');

        $this->actingAs($this->logisticsA)
            ->getJson(
                route(
                    'logistics.waybills.lookup',
                    $waybill->waybill_no
                )
            )
            ->assertOk()
            ->assertJsonPath(
                'data.waybill_no',
                $waybill->waybill_no
            )
            ->assertJsonPath(
                'data.status',
                ShipmentStatus::PickedUp->value
            );
    }

    public function test_logistics_cannot_look_up_another_providers_waybill(): void
    {
        ['waybill' => $waybill] =
            $this->makeShipmentFor($this->profileB, 'B1');

        $this->actingAs($this->logisticsA)
            ->getJson(
                route(
                    'logistics.waybills.lookup',
                    $waybill->waybill_no
                )
            )
            ->assertNotFound();
    }

    public function test_logistics_can_receive_its_own_parcel_into_its_own_center(): void
    {
        [
            'shipment' => $shipment,
            'waybill' => $waybill,
            'parcel' => $parcel,
        ] = $this->makeShipmentFor(
            $this->profileA,
            'A2'
        );

        $this->actingAs($this->logisticsA)
            ->postJson(
                route(
                    'logistics.waybills.receive',
                    $waybill->waybill_no
                ),
                [
                    'sorting_center_id' => $this->centerA->id,
                    'scan_method' => 'manual',
                ]
            )
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Parcel received at sorting center.'
            );

        $this->assertDatabaseHas('parcels', [
            'id' => $parcel->id,
            'status' => ParcelStatus::Received->value,
            'current_sorting_center_id' => $this->centerA->id,
        ]);

        $this->assertDatabaseHas('shipments', [
            'id' => $shipment->id,
            'status' => ShipmentStatus::AtSortingCenter->value,
        ]);

        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $shipment->id,
            'parcel_id' => $parcel->id,
            'event_type' => 'received_at_center',
            'from_status' => ParcelStatus::PickedUp->value,
            'to_status' => ParcelStatus::Received->value,
            'sorting_center_id' => $this->centerA->id,
            'actor_user_id' => $this->logisticsA->id,
            'source' => 'scan',
            'scan_method' => 'manual',
        ]);
    }

    public function test_logistics_cannot_receive_into_another_providers_center(): void
    {
        ['waybill' => $waybill] =
            $this->makeShipmentFor($this->profileA, 'A3');

        $this->actingAs($this->logisticsA)
            ->postJson(
                route(
                    'logistics.waybills.receive',
                    $waybill->waybill_no
                ),
                [
                    'sorting_center_id' => $this->centerB->id,
                    'scan_method' => 'manual',
                ]
            )
            ->assertNotFound();
    }

    public function test_logistics_cannot_receive_another_providers_shipment(): void
    {
        ['waybill' => $waybill] =
            $this->makeShipmentFor($this->profileB, 'B2');

        $this->actingAs($this->logisticsA)
            ->postJson(
                route(
                    'logistics.waybills.receive',
                    $waybill->waybill_no
                ),
                [
                    'sorting_center_id' => $this->centerA->id,
                    'scan_method' => 'manual',
                ]
            )
            ->assertNotFound();
    }

    public function test_duplicate_intake_returns_conflict(): void
    {
        ['waybill' => $waybill] =
            $this->makeShipmentFor($this->profileA, 'A4');

        $url = route(
            'logistics.waybills.receive',
            $waybill->waybill_no
        );

        $this->actingAs($this->logisticsA)
            ->postJson($url, [
                'sorting_center_id' => $this->centerA->id,
                'scan_method' => 'barcode',
            ])
            ->assertOk();

        $this->actingAs($this->logisticsA)
            ->postJson($url, [
                'sorting_center_id' => $this->centerA->id,
                'scan_method' => 'barcode',
            ])
            ->assertStatus(409);
    }

    public function test_intake_rejects_an_inactive_owned_sorting_center(): void
    {
        ['waybill' => $waybill] =
            $this->makeShipmentFor($this->profileA, 'INACTIVE');
        $this->centerA->update(['status' => 'inactive']);

        $this->actingAs($this->logisticsA)
            ->postJson(
                route(
                    'logistics.waybills.receive',
                    $waybill->waybill_no
                ),
                [
                    'sorting_center_id' => $this->centerA->id,
                    'scan_method' => 'manual',
                ]
            )
            ->assertStatus(409);
    }

    public function test_service_rejects_foreign_shipment_even_when_called_directly(): void
    {
        ['waybill' => $waybill] =
            $this->makeShipmentFor($this->profileB, 'B3');

        $this->expectException(
            AuthorizationException::class
        );

        app(ParcelIntakeService::class)->receive(
            $waybill,
            $this->centerA,
            $this->logisticsA,
            'manual'
        );
    }

    public function test_service_rejects_foreign_sorting_center_even_when_called_directly(): void
    {
        ['waybill' => $waybill] =
            $this->makeShipmentFor($this->profileA, 'A5');

        $this->expectException(
            AuthorizationException::class
        );

        app(ParcelIntakeService::class)->receive(
            $waybill,
            $this->centerB,
            $this->logisticsA,
            'manual'
        );
    }

    public function test_logistics_can_receive_a_waybill_from_the_intake_form(): void
    {
        [
            'waybill' => $waybill,
            'parcel' => $parcel,
        ] = $this->makeShipmentFor($this->profileA, 'FORM');

        $this->actingAs($this->logisticsA)
            ->post(
                route('logistics.sorting.incoming.receive'),
                [
                    'identifier' => $waybill->barcode_value,
                    'sorting_center_id' => $this->centerA->id,
                ]
            )
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('parcels', [
            'id' => $parcel->id,
            'status' => ParcelStatus::Received->value,
            'current_sorting_center_id' => $this->centerA->id,
        ]);
    }

    public function test_intake_ledger_only_displays_the_current_providers_parcels(): void
    {
        $own = $this->makeShipmentFor($this->profileA, 'LEDGER-OWN');
        $foreign = $this->makeShipmentFor($this->profileB, 'LEDGER-FOREIGN');
        $own['shipment']->sellerOrder()->firstOrFail()->items()->create([
            'product_name' => 'Ledger Preview Product',
            'variant_name' => 'Navy / Medium',
            'sku' => 'LEDGER-PREVIEW-001',
            'unit_price_minor' => 12500,
            'quantity' => 2,
            'discount_minor' => 0,
            'subtotal_minor' => 25000,
            'product_image_disk' => 'public',
            'product_image_path' => 'seller-products/ledger-preview.png',
        ]);

        app(ParcelIntakeService::class)->receive(
            $own['waybill'],
            $this->centerA,
            $this->logisticsA,
            'manual'
        );
        app(ParcelIntakeService::class)->receive(
            $foreign['waybill'],
            $this->centerB,
            $this->logisticsB,
            'manual'
        );

        $this->actingAs($this->logisticsA)
            ->get(route('logistics.sorting.incoming'))
            ->assertOk()
            ->assertSee($own['waybill']->waybill_no)
            ->assertSee('Products in shipment')
            ->assertSee('Ledger Preview Product')
            ->assertSee('seller-products/ledger-preview.png', false)
            ->assertSee('Store LEDGER-OWN')
            ->assertDontSee($foreign['waybill']->waybill_no)
            ->assertDontSee('Store LEDGER-FOREIGN')
            ->assertDontSee('data-incoming-form', false)
            ->assertDontSee('data-send-sorting', false);
    }

    public function test_sorting_queue_only_displays_owned_parcels_and_zones(): void
    {
        $own = $this->makeShipmentFor($this->profileA, 'QUEUE-OWN');
        $foreign = $this->makeShipmentFor($this->profileB, 'QUEUE-FOREIGN');
        $ownZone = $this->makeZone($this->centerA, 'OWN-ZONE');
        $foreignZone = $this->makeZone($this->centerB, 'FOREIGN-ZONE');

        app(ParcelIntakeService::class)->receive(
            $own['waybill'],
            $this->centerA,
            $this->logisticsA,
            'manual'
        );
        app(ParcelIntakeService::class)->receive(
            $foreign['waybill'],
            $this->centerB,
            $this->logisticsB,
            'manual'
        );

        $this->actingAs($this->logisticsA)
            ->get(route('logistics.sorting.center'))
            ->assertOk()
            ->assertSee($own['parcel']->parcel_no)
            ->assertSee($ownZone->code)
            ->assertDontSee($foreign['parcel']->parcel_no)
            ->assertDontSee($foreignZone->code)
            ->assertDontSee('data-sort-parcel', false)
            ->assertDontSee('data-set-status', false);
    }

    public function test_sorting_center_directs_cross_city_parcels_to_city_handoffs(): void
    {
        $record = $this->makeCrossCityShipment('CROSS-CITY-GUIDE');
        [$courierUser, $courier] = $this->makeCourier('CROSS-CITY-GUIDE');

        $this->actingAs($this->logisticsA)
            ->get(route('logistics.sorting.center'))
            ->assertOk()
            ->assertSee($record['waybill']->waybill_no)
            ->assertSee('Transfer required')
            ->assertSee('Transfer to '.$this->profileB->display_name)
            ->assertSee('Go to City Handoffs')
            ->assertSee(route('logistics.transfers.index'), false)
            ->assertDontSee('Review coverage');

        $this->actingAs($this->logisticsA)
            ->get(route('logistics.transfers.index'))
            ->assertOk()
            ->assertSee($record['waybill']->waybill_no)
            ->assertSee($this->profileB->display_name)
            ->assertSee('Build a Courier manifest')
            ->assertSee('name="courier_profile_id"', false)
            ->assertSee('name="shipment_ids[]"', false)
            ->assertSee('Assign manifest')
            ->assertSee($courierUser->name)
            ->assertDontSee('Send handoff');
    }

    public function test_city_handoffs_explains_when_destination_has_no_active_logistics_account(): void
    {
        $record = $this->makeCrossCityShipment('CROSS-CITY-NO-PROVIDER');
        $this->profileB->update(['status' => 'inactive']);
        $issue = 'No active Logistics account is registered for Pila, Laguna.';

        $this->actingAs($this->logisticsA)
            ->get(route('logistics.sorting.center'))
            ->assertOk()
            ->assertSee($record['waybill']->waybill_no)
            ->assertSee('Handoff unavailable')
            ->assertSee($issue);

        $this->actingAs($this->logisticsA)
            ->get(route('logistics.transfers.index'))
            ->assertOk()
            ->assertSee('Needs destination Logistics')
            ->assertSee($record['waybill']->waybill_no)
            ->assertSee($issue);
    }

    public function test_logistics_can_sort_an_owned_received_parcel(): void
    {
        $record = $this->makeShipmentFor($this->profileA, 'SORT');
        $zone = $this->makeZone($this->centerA, 'SORT-ZONE');

        app(ParcelIntakeService::class)->receive(
            $record['waybill'],
            $this->centerA,
            $this->logisticsA,
            'manual'
        );

        $this->actingAs($this->logisticsA)
            ->patch(
                route(
                    'logistics.sorting.parcels.update',
                    $record['parcel']
                ),
                ['sorting_zone_id' => $zone->id]
            )
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('parcels', [
            'id' => $record['parcel']->id,
            'status' => ParcelStatus::Sorted->value,
            'current_zone_id' => $zone->id,
        ]);
        $this->assertDatabaseHas('shipments', [
            'id' => $record['shipment']->id,
            'status' => ShipmentStatus::Sorted->value,
        ]);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $record['shipment']->id,
            'parcel_id' => $record['parcel']->id,
            'event_type' => 'parcel_sorted',
            'sorting_center_id' => $this->centerA->id,
            'sorting_zone_id' => $zone->id,
            'actor_user_id' => $this->logisticsA->id,
            'source' => 'logistics',
        ]);
    }

    public function test_logistics_cannot_sort_a_parcel_into_a_zone_that_does_not_cover_its_barangay(): void
    {
        $record = $this->makeShipmentFor($this->profileA, 'ZONE-MISMATCH');
        $zone = $this->makeZone($this->centerA, 'PILA-MISMATCH');
        $zone->update([
            'destination_rules' => [
                'province' => 'Laguna',
                'city_municipality' => 'Pila',
                'barangays' => ['Pinagbayanan'],
            ],
        ]);

        app(ParcelIntakeService::class)->receive(
            $record['waybill'],
            $this->centerA,
            $this->logisticsA,
            'manual'
        );

        $this->actingAs($this->logisticsA)
            ->patch(
                route('logistics.sorting.parcels.update', $record['parcel']),
                ['sorting_zone_id' => $zone->id]
            )
            ->assertStatus(409);

        $this->assertSame(
            ParcelStatus::Received->value,
            $record['parcel']->fresh()->status
        );
        $this->assertDatabaseMissing('shipment_events', [
            'shipment_id' => $record['shipment']->id,
            'event_type' => 'parcel_sorted',
        ]);
    }

    public function test_shipment_is_only_sorted_after_every_parcel_is_sorted(): void
    {
        $record = $this->makeShipmentFor($this->profileA, 'MULTI');
        $secondParcel = Parcel::query()->create([
            'shipment_id' => $record['shipment']->id,
            'waybill_id' => $record['waybill']->id,
            'parcel_no' => 'PARCEL-MULTI-2',
            'piece_sequence' => 2,
            'weight_kg' => 0.750,
            'status' => ParcelStatus::PickedUp->value,
        ]);
        $record['waybill']->update([
            'piece_count' => 2,
            'total_weight_kg' => 2.000,
        ]);
        $zone = $this->makeZone($this->centerA, 'MULTI-ZONE');

        app(ParcelIntakeService::class)->receive(
            $record['waybill'],
            $this->centerA,
            $this->logisticsA,
            'manual'
        );

        $this->actingAs($this->logisticsA)
            ->patch(
                route('logistics.sorting.parcels.update', $record['parcel']),
                ['sorting_zone_id' => $zone->id]
            )
            ->assertRedirect();

        $this->assertSame(
            ShipmentStatus::AtSortingCenter->value,
            $record['shipment']->fresh()->status
        );

        $this->actingAs($this->logisticsA)
            ->patch(
                route('logistics.sorting.parcels.update', $secondParcel),
                ['sorting_zone_id' => $zone->id]
            )
            ->assertRedirect();

        $this->assertSame(
            ShipmentStatus::Sorted->value,
            $record['shipment']->fresh()->status
        );
    }

    public function test_sorting_rejects_foreign_parcels_and_wrong_center_zones(): void
    {
        $own = $this->makeShipmentFor($this->profileA, 'SECURE-OWN');
        $foreign = $this->makeShipmentFor($this->profileB, 'SECURE-FOREIGN');
        $ownZone = $this->makeZone($this->centerA, 'SECURE-OWN-ZONE');
        $foreignZone = $this->makeZone($this->centerB, 'SECURE-FOREIGN-ZONE');

        app(ParcelIntakeService::class)->receive(
            $own['waybill'],
            $this->centerA,
            $this->logisticsA,
            'manual'
        );
        app(ParcelIntakeService::class)->receive(
            $foreign['waybill'],
            $this->centerB,
            $this->logisticsB,
            'manual'
        );

        $this->actingAs($this->logisticsA)
            ->patch(
                route('logistics.sorting.parcels.update', $foreign['parcel']),
                ['sorting_zone_id' => $ownZone->id]
            )
            ->assertNotFound();

        $this->actingAs($this->logisticsA)
            ->patch(
                route('logistics.sorting.parcels.update', $own['parcel']),
                ['sorting_zone_id' => $foreignZone->id]
            )
            ->assertNotFound();

        $this->assertSame(
            ParcelStatus::Received->value,
            $own['parcel']->fresh()->status
        );
        $this->assertSame(
            ParcelStatus::Received->value,
            $foreign['parcel']->fresh()->status
        );
    }

    public function test_cross_city_handoff_changes_custody_only_after_destination_acceptance(): void
    {
        $record = $this->makeShipmentFor($this->profileA, 'CITY-HANDOFF');
        $record['shipment']->update([
            'status' => ShipmentStatus::AtSortingCenter->value,
            'origin_logistics_profile_id' => $this->profileA->id,
            'destination_city_code' => $this->profileB->psgc_city_code,
        ]);
        $record['shipment']->sellerOrder->order->update([
            'city_municipality' => 'Pila',
            'psgc_city_code' => $this->profileB->psgc_city_code,
        ]);
        $record['parcel']->update([
            'status' => ParcelStatus::Received->value,
            'current_sorting_center_id' => $this->centerA->id,
        ]);

        [$courierUser, $courier] = $this->makeCourier('CITY-HANDOFF');

        $this->actingAs($this->logisticsA)
            ->post(route('logistics.transfers.manifests.store'), [
                'courier_profile_id' => $courier->id,
                'shipment_ids' => [$record['shipment']->id],
            ])
            ->assertRedirect(route('logistics.transfers.index'))
            ->assertSessionHasNoErrors();

        $run = LinehaulRun::query()->firstOrFail();
        $this->assertSame('assigned', $run->status);
        $this->assertSame(ShipmentStatus::AtSortingCenter->value, $record['shipment']->fresh()->status);
        $this->assertSame(ParcelStatus::Received->value, $record['parcel']->fresh()->status);
        $this->assertDatabaseMissing('shipment_transfers', [
            'shipment_id' => $record['shipment']->id,
        ]);

        $this->actingAs($courierUser)
            ->post(route('rider.courier.runs.start-loading', $run))
            ->assertRedirect();
        $this->actingAs($courierUser)
            ->post(route('rider.courier.runs.loaded', $run))
            ->assertRedirect();
        $this->actingAs($courierUser)
            ->post(route('rider.courier.runs.depart', $run))
            ->assertRedirect();

        $transfer = ShipmentTransfer::query()
            ->where('shipment_id', $record['shipment']->id)
            ->firstOrFail();

        $this->assertSame('sent', $transfer->status);
        $this->assertSame($this->profileA->id, $record['shipment']->fresh()->logistics_profile_id);
        $this->assertSame(ShipmentStatus::HandoffPending->value, $record['shipment']->fresh()->status);
        $this->assertSame(ParcelStatus::InTransit->value, $record['parcel']->fresh()->status);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $record['shipment']->id,
            'parcel_id' => $record['parcel']->id,
            'event_type' => 'logistics_handoff_sent',
        ]);

        $this->actingAs($this->logisticsB)
            ->post(route('logistics.transfers.accept'), [
                'identifier' => $record['waybill']->waybill_no,
                'scan_method' => 'barcode',
            ])
            ->assertStatus(409);

        $this->actingAs($courierUser)
            ->post(route('rider.courier.runs.arrive', $run))
            ->assertRedirect();
        $this->assertSame('arrived', $run->fresh()->status);

        $this->actingAs($this->logisticsB)
            ->get(route('logistics.transfers.index'))
            ->assertOk()
            ->assertSee($record['waybill']->waybill_no)
            ->assertSee('Accept an incoming handoff')
            ->assertSee('Sorting Center B')
            ->assertDontSee('name="sorting_center_id"', false);

        $this->actingAs($this->logisticsB)
            ->from(route('logistics.transfers.index'))
            ->post(route('logistics.transfers.accept'), [
                'identifier' => $record['waybill']->waybill_no,
                'sorting_center_id' => $this->centerA->id,
                'scan_method' => 'barcode',
            ])
            ->assertRedirect(route('logistics.transfers.index'))
            ->assertSessionHasErrors('sorting_center_id');

        $this->assertSame('sent', $transfer->fresh()->status);

        $this->actingAs($this->logisticsB)
            ->post(route('logistics.transfers.accept'), [
                'identifier' => $record['waybill']->waybill_no,
                'scan_method' => 'barcode',
            ])
            ->assertRedirect(route('logistics.transfers.index'))
            ->assertSessionHasNoErrors();

        $accepted = ShipmentTransfer::query()
            ->where('shipment_id', $record['shipment']->id)
            ->firstOrFail();

        $this->assertSame('accepted', $accepted->status);
        $this->assertSame($this->centerB->id, $accepted->to_sorting_center_id);
        $this->assertSame($this->profileB->id, $record['shipment']->fresh()->logistics_profile_id);
        $this->assertSame($this->profileA->id, $record['shipment']->fresh()->origin_logistics_profile_id);
        $this->assertSame(ShipmentStatus::AtSortingCenter->value, $record['shipment']->fresh()->status);
        $this->assertSame(ParcelStatus::Received->value, $record['parcel']->fresh()->status);
        $this->assertSame($this->centerB->id, $record['parcel']->fresh()->current_sorting_center_id);
        $this->assertNull($record['parcel']->fresh()->current_zone_id);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $record['shipment']->id,
            'parcel_id' => $record['parcel']->id,
            'event_type' => 'logistics_handoff_accepted',
            'scan_method' => 'barcode',
        ]);
        $this->assertSame(2, $record['shipment']->events()->count());
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseHas('linehaul_run_events', [
            'linehaul_run_id' => $run->id,
            'event_type' => 'completed',
            'actor_user_id' => $this->logisticsB->id,
        ]);
    }

    public function test_reverse_shipment_handoff_uses_its_immutable_seller_destination_city(): void
    {
        $record = $this->makeShipmentFor($this->profileA, 'RETURN-CITY-HANDOFF');
        $record['shipment']->update([
            'purpose' => 'return',
            'status' => ShipmentStatus::AtSortingCenter->value,
            'origin_logistics_profile_id' => $this->profileA->id,
            'destination_city_code' => $this->profileB->psgc_city_code,
            'destination_address_snapshot' => [
                'recipient_name' => 'Seller',
                'phone' => '09170000003',
                'street' => 'Seller Road',
                'barangay' => 'Pinagbayanan',
                'city_municipality' => 'Pila',
                'province' => 'Laguna',
                'postal_code' => '4010',
                'psgc_city_code' => $this->profileB->psgc_city_code,
            ],
        ]);
        $record['parcel']->update([
            'status' => ParcelStatus::Received->value,
            'current_sorting_center_id' => $this->centerA->id,
        ]);

        [$courierUser, $courier] = $this->makeCourier('RETURN-CITY-HANDOFF');
        $runs = app(LinehaulRunService::class);
        $run = $runs->assign([$record['shipment']->id], $courier->id, $this->logisticsA);
        $runs->startLoading($run, $courierUser);
        $runs->markLoaded($run, $courierUser);
        $runs->depart($run, $courierUser);
        $transfer = ShipmentTransfer::query()->where('shipment_id', $record['shipment']->id)->firstOrFail();

        $this->assertSame($this->profileB->id, $transfer->to_logistics_profile_id);
        $this->assertSame($this->profileB->psgc_city_code, $transfer->shipment->destination_city_code);
    }

    public function test_linehaul_manifest_rejects_parcel_load_above_verified_capacity(): void
    {
        $first = $this->makeCrossCityShipment('CAPACITY-CITY-A');
        $second = $this->makeCrossCityShipment('CAPACITY-CITY-B');
        [, $courier] = $this->makeCourier('CAPACITY-CITY');
        $courier->update(['linehaul_parcel_capacity' => 1]);

        try {
            app(LinehaulRunService::class)->assign(
                [$first['shipment']->id, $second['shipment']->id],
                $courier->id,
                $this->logisticsA,
            );
            $this->fail('The manifest must not exceed the Logistics-verified parcel capacity.');
        } catch (ConflictHttpException) {
            $this->assertDatabaseCount('linehaul_runs', 0);
            $this->assertDatabaseCount('linehaul_run_shipments', 0);
            $this->assertSame(ParcelStatus::Received->value, $first['parcel']->fresh()->status);
            $this->assertSame(ParcelStatus::Received->value, $second['parcel']->fresh()->status);
        }
    }

    public function test_linehaul_manifest_rejects_a_courier_from_another_provider(): void
    {
        $record = $this->makeCrossCityShipment('FOREIGN-COURIER');
        [, $courier] = $this->makeCourier('FOREIGN-COURIER');
        $courier->update(['logistics_profile_id' => $this->profileB->id]);

        try {
            app(LinehaulRunService::class)->assign(
                [$record['shipment']->id],
                $courier->id,
                $this->logisticsA,
            );
            $this->fail('A Logistics provider must not assign a Courier belonging to another provider.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('linehaul_runs', 0);
            $this->assertDatabaseMissing('linehaul_run_shipments', [
                'shipment_id' => $record['shipment']->id,
            ]);
        }
    }

    public function test_manifested_shipment_cannot_be_assigned_to_a_second_run(): void
    {
        $record = $this->makeCrossCityShipment('DUPLICATE-MANIFEST');
        [$firstUser, $firstCourier] = $this->makeCourier('DUPLICATE-FIRST');
        [, $secondCourier] = $this->makeCourier('DUPLICATE-SECOND');
        $runs = app(LinehaulRunService::class);
        $runs->assign([$record['shipment']->id], $firstCourier->id, $this->logisticsA);

        try {
            $runs->assign([$record['shipment']->id], $secondCourier->id, $this->logisticsA);
            $this->fail('A shipment must not be duplicated across Courier manifests.');
        } catch (ConflictHttpException) {
            $this->assertDatabaseCount('linehaul_runs', 1);
            $this->assertDatabaseCount('linehaul_run_shipments', 1);
        }

        $run = LinehaulRun::query()->firstOrFail();
        $runs->reportException($run, $firstUser, 'Vehicle issue at loading bay');
        $this->assertSame('exception', $run->fresh()->status);
        $this->assertDatabaseHas('linehaul_run_events', [
            'linehaul_run_id' => $run->id,
            'event_type' => 'exception_reported',
            'reason' => 'Vehicle issue at loading bay',
        ]);

        $runs->resolveException($run, $this->logisticsA, 'Replacement vehicle confirmed');
        $this->assertSame('assigned', $run->fresh()->status);
        $this->assertNull($run->fresh()->status_before_exception);
    }

    public function test_direct_city_handoff_send_is_blocked_without_changing_parcels(): void
    {
        $record = $this->makeCrossCityShipment('DIRECT-HANDOFF-BLOCKED');

        try {
            app(ShipmentHandoffService::class)->send($record['shipment'], $this->logisticsA);
            $this->fail('A city handoff must be sent only by a departed Courier manifest.');
        } catch (ConflictHttpException) {
            $this->assertSame(ShipmentStatus::AtSortingCenter->value, $record['shipment']->fresh()->status);
            $this->assertSame(ParcelStatus::Received->value, $record['parcel']->fresh()->status);
            $this->assertDatabaseMissing('shipment_transfers', [
                'shipment_id' => $record['shipment']->id,
            ]);
        }
    }

    public function test_only_destination_logistics_can_accept_a_pending_handoff_and_acceptance_is_single_use(): void
    {
        $record = $this->makeShipmentFor($this->profileA, 'CITY-HANDOFF-OWNERSHIP');
        $record['shipment']->update([
            'status' => ShipmentStatus::AtSortingCenter->value,
            'origin_logistics_profile_id' => $this->profileA->id,
            'destination_city_code' => $this->profileB->psgc_city_code,
        ]);
        $record['shipment']->sellerOrder->order->update([
            'city_municipality' => 'Pila',
            'psgc_city_code' => $this->profileB->psgc_city_code,
        ]);
        $record['parcel']->update([
            'status' => ParcelStatus::Received->value,
            'current_sorting_center_id' => $this->centerA->id,
        ]);

        [$courierUser, $courier] = $this->makeCourier('CITY-HANDOFF-OWNERSHIP');
        $runs = app(LinehaulRunService::class);
        $run = $runs->assign([$record['shipment']->id], $courier->id, $this->logisticsA);
        $runs->startLoading($run, $courierUser);
        $runs->markLoaded($run, $courierUser);
        $runs->depart($run, $courierUser);
        $runs->arrive($run, $courierUser);
        $service = app(ShipmentHandoffService::class);

        try {
            $service->accept($record['waybill']->waybill_no, $this->logisticsA);
            $this->fail('Origin Logistics must not accept its outgoing handoff.');
        } catch (AuthorizationException) {
            $this->assertSame(ShipmentStatus::HandoffPending->value, $record['shipment']->fresh()->status);
        }

        $service->accept($record['waybill']->waybill_no, $this->logisticsB);

        $this->expectException(ConflictHttpException::class);
        $service->accept($record['waybill']->waybill_no, $this->logisticsB);
    }

    public function test_courier_linehaul_history_is_scoped_to_verified_courier_and_denies_local_rider(): void
    {
        $ownRecord = $this->makeCrossCityShipment('COURIER-HISTORY-OWN');
        $otherRecord = $this->makeCrossCityShipment('COURIER-HISTORY-OTHER');
        [$courierUser, $courier] = $this->makeCourier('COURIER-HISTORY-OWN');
        [, $otherCourier] = $this->makeCourier('COURIER-HISTORY-OTHER');

        $runs = app(LinehaulRunService::class);
        $ownRun = $runs->assign([$ownRecord['shipment']->id], $courier->id, $this->logisticsA);
        $otherRun = $runs->assign([$otherRecord['shipment']->id], $otherCourier->id, $this->logisticsA);

        $this->actingAs($courierUser)
            ->get(route('rider.courier.history.index'))
            ->assertOk()
            ->assertSee('Line-haul History')
            ->assertSee('href="'.route('rider.courier.history.index').'" class="ops-nav-link is-active"', false)
            ->assertSee($ownRun->run_no)
            ->assertSee($ownRecord['waybill']->waybill_no)
            ->assertDontSee($otherRun->run_no);

        $localRiderUser = User::factory()->create([
            'name' => 'Local Rider History Test',
            'email' => 'local-rider-history-test@example.test',
            'role' => 'rider',
            'status' => 'active',
            'logistics_id' => $this->logisticsA->id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'LOCAL-HISTORY-TEST',
        ]);
        RiderProfile::query()->create([
            'user_id' => $localRiderUser->id,
            'logistics_profile_id' => $this->profileA->id,
            'home_sorting_center_id' => $this->centerA->id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'LOCAL-HISTORY-TEST',
            'work_type' => 'local',
            'availability_status' => 'available',
            'verification_status' => 'approved',
        ]);

        $this->actingAs($localRiderUser)
            ->get(route('rider.courier.history.index'))
            ->assertForbidden();
    }

    public function test_courier_check_in_and_check_out_are_reported_in_philippine_time(): void
    {
        [$courierUser, $courier] = $this->makeCourier('PHT-ATTENDANCE');
        $this->travelTo(Carbon::parse('2026-11-04 06:45:00', 'UTC'));

        $this->from(route('rider.courier.dashboard'))
            ->actingAs($courierUser)
            ->post(route('rider.attendance.check-in'))
            ->assertRedirect(route('rider.courier.dashboard'))
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, '2:45 PM PHT'));

        $shift = $courier->shifts()->sole();
        $this->assertSame('06:45', $shift->checked_in_at?->format('H:i'));
        $this->assertSame('14:45', $shift->checkedInAtPht()?->format('H:i'));

        $this->actingAs($courierUser)
            ->get(route('rider.courier.dashboard'))
            ->assertOk()
            ->assertSee('Checked in Nov 4, 2026 · 2:45 PM PHT');

        $this->travelTo(Carbon::parse('2026-11-04 07:45:00', 'UTC'));
        $this->from(route('rider.courier.dashboard'))
            ->actingAs($courierUser)
            ->post(route('rider.attendance.check-out'))
            ->assertRedirect(route('rider.courier.dashboard'))
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, '3:45 PM PHT'));

        $shift->refresh();
        $this->assertSame('15:45', $shift->checkedOutAtPht()?->format('H:i'));
    }

    public function test_courier_daily_pay_requires_a_closed_shift_and_full_destination_acceptance(): void
    {
        $first = $this->makeCrossCityShipment('DAILY-PAY-MANIFEST-A');
        $second = $this->makeCrossCityShipment('DAILY-PAY-MANIFEST-B');
        [$courierUser, $courier] = $this->makeCourier('DAILY-PAY-MANIFEST');
        $this->travelTo(Carbon::parse('2026-11-03 20:00:00', 'Asia/Manila'));
        $shiftService = app(RiderShiftService::class);
        $shiftService->checkIn($courier, $courierUser);

        $runs = app(LinehaulRunService::class);
        $run = $runs->assign(
            [$first['shipment']->id, $second['shipment']->id],
            $courier->id,
            $this->logisticsA,
        );
        $runs->startLoading($run, $courierUser);
        $runs->markLoaded($run, $courierUser);
        $runs->depart($run, $courierUser);
        $runs->arrive($run, $courierUser);
        $shift = $shiftService->checkOut($courier, $courierUser);

        // Courier arrival and a closed shift are not enough while even one
        // shipment remains unaccepted at the destination.
        $this->assertDatabaseMissing('rider_earnings', [
            'rider_profile_id' => $courier->id,
            'type' => 'courier_daily',
        ]);

        $handoffs = app(ShipmentHandoffService::class);
        $handoffs->accept($first['waybill']->waybill_no, $this->logisticsB);

        $this->assertSame('arrived', $run->fresh()->status);
        $this->assertDatabaseMissing('rider_earnings', [
            'rider_profile_id' => $courier->id,
            'type' => 'courier_daily',
        ]);

        // Acceptance may happen the next calendar day; credit remains on the
        // shift's work date, while earned_at reflects when eligibility closed.
        $this->travelTo(Carbon::parse('2026-11-04 01:00:00', 'Asia/Manila'));
        $handoffs->accept($second['waybill']->waybill_no, $this->logisticsB);

        $earning = RiderEarning::query()
            ->where('rider_profile_id', $courier->id)
            ->where('type', 'courier_daily')
            ->sole();

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('posted', $earning->status);
        $this->assertSame(65000, (int) $earning->amount_minor);
        $this->assertSame(65000, (int) $earning->rate_minor);
        $this->assertSame((int) $shift->id, (int) $earning->rider_shift_id);
        $this->assertSame((int) $run->id, (int) $earning->linehaul_run_id);
        $this->assertSame($shift->checked_in_at->timezone('Asia/Manila')->toDateString(), $earning->work_date->toDateString());
        $this->assertTrue($earning->earned_at->greaterThanOrEqualTo($run->fresh()->completed_at));

        $riderSummary = app(\App\Services\RiderEarningsService::class)->summary($courier);
        $this->assertSame(0, $riderSummary['today']['amount_minor']);
        $this->assertSame(65000, $riderSummary['week']['amount_minor']);
        $this->assertSame(65000, $riderSummary['month']['amount_minor']);

        $dailyEarnings = app(\App\Services\CourierDailyEarningService::class);
        $dailyEarnings->reconcileShift($shift->fresh());
        $dailyEarnings->reconcileRun($run->fresh());

        $this->assertSame(1, RiderEarning::query()
            ->where('rider_profile_id', $courier->id)
            ->where('type', 'courier_daily')
            ->count());

        $migration = require database_path('migrations/2026_11_04_000001_add_courier_daily_earnings.php');

        try {
            $migration->down();
            $this->fail('Rollback must preserve Courier daily earnings history.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Courier daily earning history exists', $exception->getMessage());
        }

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('rider_earnings', 'work_date'));

    }

    public function test_courier_daily_pay_is_reconciled_at_check_out_and_only_once_for_multiple_runs(): void
    {
        $first = $this->makeCrossCityShipment('DAILY-PAY-RUN-FIRST');
        $second = $this->makeCrossCityShipment('DAILY-PAY-RUN-SECOND');
        [$courierUser, $courier] = $this->makeCourier('DAILY-PAY-MULTIPLE-RUNS');
        $shiftService = app(RiderShiftService::class);
        $shiftService->checkIn($courier, $courierUser);
        $runs = app(LinehaulRunService::class);

        $firstRun = $runs->assign([$first['shipment']->id], $courier->id, $this->logisticsA);
        $this->completeRunAtDestination($firstRun, $courierUser, $first['waybill']->waybill_no);

        $this->assertDatabaseMissing('rider_earnings', [
            'rider_profile_id' => $courier->id,
            'type' => 'courier_daily',
        ]);

        // A second run assigned inside the same shift also qualifies, but one
        // work date must still produce only one daily ledger row.
        $secondRun = $runs->assign([$second['shipment']->id], $courier->id, $this->logisticsA);
        $runs->startLoading($secondRun, $courierUser);
        $runs->markLoaded($secondRun, $courierUser);
        $runs->depart($secondRun, $courierUser);
        $runs->arrive($secondRun, $courierUser);

        $shift = $shiftService->checkOut($courier, $courierUser);
        $earning = RiderEarning::query()
            ->where('rider_profile_id', $courier->id)
            ->where('type', 'courier_daily')
            ->sole();

        $this->assertSame(65000, (int) $earning->amount_minor);
        $this->assertSame((int) $firstRun->id, (int) $earning->linehaul_run_id);

        app(ShipmentHandoffService::class)->accept($second['waybill']->waybill_no, $this->logisticsB);
        app(\App\Services\CourierDailyEarningService::class)->reconcileShift($shift->fresh());

        $this->assertSame(1, RiderEarning::query()
            ->where('rider_profile_id', $courier->id)
            ->where('type', 'courier_daily')
            ->count());

        // Another qualifying shift on the same work date must not create a
        // second daily earning.
        $secondShift = $shiftService->checkIn($courier, $courierUser);
        $third = $this->makeCrossCityShipment('DAILY-PAY-SECOND-SHIFT');
        $thirdRun = $runs->assign([$third['shipment']->id], $courier->id, $this->logisticsA);
        $runs->startLoading($thirdRun, $courierUser);
        $runs->markLoaded($thirdRun, $courierUser);
        $runs->depart($thirdRun, $courierUser);
        $runs->arrive($thirdRun, $courierUser);
        $shiftService->checkOut($courier, $courierUser);
        app(ShipmentHandoffService::class)->accept($third['waybill']->waybill_no, $this->logisticsB);

        $this->assertSame($earning->work_date->toDateString(), $secondShift->checked_in_at->timezone('Asia/Manila')->toDateString());
        $this->assertSame(1, RiderEarning::query()
            ->where('rider_profile_id', $courier->id)
            ->where('type', 'courier_daily')
            ->count());
    }

    public function test_attendance_without_a_qualifying_assigned_run_does_not_create_courier_daily_pay(): void
    {
        [$attendingCourierUser, $attendingCourier] = $this->makeCourier('DAILY-PAY-ATTENDANCE-ONLY');
        $shifts = app(RiderShiftService::class);
        $shifts->checkIn($attendingCourier, $attendingCourierUser);
        $shifts->checkOut($attendingCourier, $attendingCourierUser);

        $this->assertDatabaseMissing('rider_earnings', [
            'rider_profile_id' => $attendingCourier->id,
            'type' => 'courier_daily',
        ]);

        $record = $this->makeCrossCityShipment('DAILY-PAY-WITHOUT-SHIFT');
        [$unattendedCourierUser, $unattendedCourier] = $this->makeCourier('DAILY-PAY-WITHOUT-SHIFT');
        $run = app(LinehaulRunService::class)->assign(
            [$record['shipment']->id],
            $unattendedCourier->id,
            $this->logisticsA,
        );
        $this->completeRunAtDestination($run, $unattendedCourierUser, $record['waybill']->waybill_no);

        $this->assertDatabaseMissing('rider_earnings', [
            'rider_profile_id' => $unattendedCourier->id,
            'type' => 'courier_daily',
        ]);
    }

    public function test_logistics_can_record_the_actual_courier_daily_payout_and_only_for_its_own_courier(): void
    {
        $record = $this->makeCrossCityShipment('DAILY-PAY-PAYOUT');
        [$courierUser, $courier] = $this->makeCourier('DAILY-PAY-PAYOUT');
        $shifts = app(RiderShiftService::class);
        $shifts->checkIn($courier, $courierUser);
        $run = app(LinehaulRunService::class)->assign([$record['shipment']->id], $courier->id, $this->logisticsA);
        $this->completeRunAtDestination($run, $courierUser, $record['waybill']->waybill_no);
        $shifts->checkOut($courier, $courierUser);

        $this->actingAs($this->logisticsB)
            ->post(route('logistics.settlements.payout-batches.store'), [
                'rider_profile_id' => $courier->id,
            ])
            ->assertNotFound();

        $this->actingAs($this->logisticsA)
            ->post(route('logistics.settlements.payout-batches.store'), [
                'rider_profile_id' => $courier->id,
                'notes' => 'Courier daily earnings payout.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $batch = \App\Models\RiderPayoutBatch::query()->sole();
        $this->assertSame(65000, (int) $batch->total_minor);

        $this->actingAs($this->logisticsA)
            ->post(route('logistics.settlements.payout-batches.approve', $batch->id))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($this->logisticsA)
            ->post(route('logistics.settlements.payout-batches.record-paid', $batch->id), [
                'method' => 'gcash',
                'reference' => 'GCASH-COURIER-650',
                'paid_on' => today()->toDateString(),
                'reason' => 'Paid the Courier daily earning.',
                'payout_confirmed' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('rider_earnings', [
            'rider_profile_id' => $courier->id,
            'type' => 'courier_daily',
            'amount_minor' => 65000,
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('rider_earning_payouts', [
            'logistics_profile_id' => $this->profileA->id,
            'amount_minor' => 65000,
            'method' => 'gcash',
            'reference' => 'GCASH-COURIER-650',
        ]);

        $this->actingAs($courierUser)
            ->get(route('rider.earnings.index'))
            ->assertOk()
            ->assertSee('₱650.00')
            ->assertSee('GCASH-COURIER-650');
    }

    private function completeRunAtDestination(LinehaulRun $run, User $courier, string $waybillNo): void
    {
        $runs = app(LinehaulRunService::class);
        $runs->startLoading($run, $courier);
        $runs->markLoaded($run, $courier);
        $runs->depart($run, $courier);
        $runs->arrive($run, $courier);
        app(ShipmentHandoffService::class)->accept($waybillNo, $this->logisticsB);
    }

    /** @return array{0: User, 1: LogisticsProfile, 2: SortingCenter} */
    private function makeLogisticsProvider(
        string $suffix
    ): array {
        $user = User::factory()->create([
            'name' => "Logistics {$suffix}",
            'first_name' => 'Logistics',
            'last_name' => $suffix,
            'email' => "logistics-{$suffix}@example.test",
            'role' => 'logistics',
            'status' => 'active',
        ]);

        $cityCode = $suffix === 'A' ? '0403424000' : '0403422000';
        $cityName = $suffix === 'A' ? 'City of San Pablo' : 'Pila';
        $postalCode = $suffix === 'A' ? '4000' : '4010';

        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => "Logistics {$suffix} Incorporated",
            'display_name' => "Logistics {$suffix}",
            'contact_phone' => '0917000000'
                . ($suffix === 'A' ? '1' : '2'),
            'psgc_city_code' => $cityCode,
            'status' => 'active',
        ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Sorting center',
            'recipient_name' => $user->name,
            'phone' => $profile->contact_phone,
            'street' => "{$suffix} Logistics Road",
            'barangay' => 'San Rafael',
            'city_municipality' => $cityName,
            'province' => 'Laguna',
            'postal_code' => $postalCode,
            'psgc_city_code' => $cityCode,
        ]);

        $center = SortingCenter::query()->create([
            'logistics_profile_id' => $profile->id,
            'address_id' => $address->id,
            'name' => "Sorting Center {$suffix}",
            'code' => "CENTER-{$suffix}",
            'contact_phone' => $profile->contact_phone,
            'status' => 'active',
        ]);

        return [$user, $profile, $center];
    }

    private function makeCourier(string $suffix): array
    {
        $user = User::factory()->create([
            'name' => "Courier {$suffix}",
            'email' => 'courier-'.strtolower($suffix).'@example.test',
            'role' => 'rider',
            'status' => 'active',
            'logistics_id' => $this->logisticsA->id,
            'vehicle_type' => 'Van',
            'plate_number' => 'COURIER-'.$suffix,
        ]);
        $profile = RiderProfile::query()->create([
            'user_id' => $user->id,
            'logistics_profile_id' => $this->profileA->id,
            'home_sorting_center_id' => $this->centerA->id,
            'vehicle_type' => 'Van',
            'plate_number' => 'COURIER-'.$suffix,
            'work_type' => 'courier',
            'linehaul_parcel_capacity' => 10,
            'courier_vehicle_verified_by' => $this->logisticsA->id,
            'courier_vehicle_verified_at' => now(),
            'availability_status' => 'available',
            'verification_status' => 'approved',
        ]);

        return [$user, $profile];
    }

    private function makeZone(
        SortingCenter $center,
        string $code
    ): SortingZone {
        return SortingZone::query()->create([
            'sorting_center_id' => $center->id,
            'name' => str_replace('-', ' ', $code),
            'code' => $code,
            'destination_rules' => [
                'province' => 'Laguna',
                'city_municipality' => 'San Pablo City',
                'psgc_city_code' => '0403424000',
                'barangays' => ['San Rafael'],
            ],
            'status' => 'active',
        ]);
    }

    private function makePickedUpPickup(
        array $record,
        string $suffix
    ): array {
        $riderUser =
            User::factory()->create([
                'name' =>
                    "Intake Rider {$suffix}",

                'email' =>
                    'intake-rider-'
                    .strtolower($suffix)
                    .'@example.test',

                'role' =>
                    'rider',

                'status' =>
                    'active',

                'logistics_id' =>
                    $this->logisticsA->id,

                'vehicle_type' =>
                    'Motorcycle',

                'plate_number' =>
                    "INT-{$suffix}",
            ]);

        $rider =
            RiderProfile::query()
                ->create([
                    'user_id' =>
                        $riderUser->id,

                    'logistics_profile_id' =>
                        $this->profileA->id,

                    'home_sorting_center_id' =>
                        $this->centerA->id,

                    'vehicle_type' =>
                        'Motorcycle',

                    'plate_number' =>
                        "INT-{$suffix}",

                    'availability_status' =>
                        'available',

                    'verification_status' =>
                        'approved',
                ]);

        $pickupAddress =
            Address::query()->create([
                'user_id' =>
                    $riderUser->id,

                'label' =>
                    'Seller Pickup',

                'recipient_name' =>
                    "Seller {$suffix}",

                'phone' =>
                    '09171111111',

                'street' =>
                    "{$suffix} Pickup Street",

                'barangay' =>
                    'San Rafael',

                'city_municipality' =>
                    'San Pablo City',

                'province' =>
                    'Laguna',

                'postal_code' =>
                    '4000',
            ]);

        $request =
            PickupRequest::query()
                ->create([
                    'pickup_no' =>
                        "INTAKE-PICKUP-{$suffix}",

                    'store_id' =>
                        $record['shipment']
                            ->sellerOrder
                            ->store_id,

                    'logistics_profile_id' =>
                        $this->profileA->id,

                    'pickup_address_id' =>
                        $pickupAddress->id,

                    'status' =>
                        'scheduled',

                    'requested_date' =>
                        today(),

                    'window_start' =>
                        now()->subHour(),

                    'window_end' =>
                        now()->addHour(),

                    'seller_instructions' =>
                        'Release parcels to assigned Rider.',
                ]);

        $request
            ->parcels()
            ->attach(
                $record['parcel']->id
            );

        $assignment =
            PickupAssignment::query()
                ->create([
                    'pickup_request_id' =>
                        $request->id,

                    'rider_profile_id' =>
                        $rider->id,

                    'status' =>
                        'picked_up',

                    'assigned_by' =>
                        $this->logisticsA->id,

                    'assigned_at' =>
                        now()->subHours(2),

                    'accepted_at' =>
                        now()->subHour(),

                    'picked_up_at' =>
                        now()->subMinutes(30),
                ]);

        return compact(
            'request',
            'assignment',
            'rider'
        );
    }

    /**
     * @return array{
     *     shipment: Shipment,
     *     waybill: Waybill,
     *     parcel: Parcel
     * }
     */
    private function makeCrossCityShipment(string $suffix): array
    {
        $record = $this->makeShipmentFor($this->profileA, $suffix);

        $record['shipment']->sellerOrder->order->update([
            'barangay' => 'Pinagbayanan',
            'city_municipality' => 'Pila',
            'province' => 'Laguna',
            'postal_code' => '4010',
            'psgc_city_code' => $this->profileB->psgc_city_code,
        ]);

        $record['shipment']->update([
            'destination_city_code' => $this->profileB->psgc_city_code,
        ]);

        app(ParcelIntakeService::class)->receive(
            $record['waybill'],
            $this->centerA,
            $this->logisticsA,
            'manual'
        );

        return $record;
    }

    /**
     * @return array{
     *     shipment: Shipment,
     *     waybill: Waybill,
     *     parcel: Parcel
     * }
     */
    private function makeShipmentFor(
        LogisticsProfile $profile,
        string $suffix
    ): array {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
        ]);

        $sellerProfile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => "Seller {$suffix}",
            'standing_status' => 'good_standing',
        ]);

        $store = Store::query()->create([
            'seller_profile_id' => $sellerProfile->id,
            'name' => "Store {$suffix}",
            'slug' => 'store-' . strtolower($suffix),
            'publication_status' => 'published',
        ]);

        $order = Order::query()->create([
            'order_no' => "ORDER-{$suffix}",
            'buyer_id' => $buyer->id,
            'recipient_name' => 'Test Buyer',
            'recipient_phone' => '09171234567',
            'address_line' => '123 Test Street',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
            'psgc_city_code' => '0403424000',
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
            'status' => 'processing',
            'payment_status' => 'paid',
        ]);

        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => "SELLER-ORDER-{$suffix}",
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => 'ready_for_pickup',
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
        ]);

        $shipment = Shipment::query()->create([
            'shipment_no' => "SHIP-{$suffix}",
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $profile->id,
            'origin_logistics_profile_id' => $profile->id,
            'destination_city_code' => $order->psgc_city_code,
            'status' => ShipmentStatus::PickedUp->value,
            'shipping_fee_minor' => 0,
            'cod_amount_minor' => 0,
        ]);

        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => "WB-{$suffix}",
            'scan_token' => hash(
                'sha256',
                "scan-{$suffix}"
            ),
            'barcode_value' => "BARCODE-{$suffix}",
            'piece_count' => 1,
            'total_weight_kg' => 1.250,
            'status' => 'generated',
            'generated_at' => now(),
        ]);

        $parcel = Parcel::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_id' => $waybill->id,
            'parcel_no' => "PARCEL-{$suffix}",
            'piece_sequence' => 1,
            'weight_kg' => 1.250,
            'status' => ParcelStatus::PickedUp->value,
        ]);

        return compact(
            'shipment',
            'waybill',
            'parcel'
        );
    }
}
