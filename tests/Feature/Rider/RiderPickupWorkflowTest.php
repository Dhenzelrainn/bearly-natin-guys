<?php

namespace Tests\Feature\Rider;

use App\Enums\AccountStatus;
use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Events\RiderLocationUpdated;
use App\Events\RiderOperationalStatusUpdated;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\PickupAssignment;
use App\Models\PickupRequest;
use App\Models\ReturnRequest;
use App\Models\RiderEarning;
use App\Models\RiderPayoutBatch;
use App\Models\RiderProfile;
use App\Models\RiderShift;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use App\Services\RiderPayoutBatchService;
use App\Services\RiderPickupEarningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RiderPickupWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepted_rider_can_load_geoapify_road_route_from_own_shared_location(): void
    {
        $provider = $this->makeProvider('ROAD-ROUTE');
        $rider = $this->makeRider($provider, 'ROAD-ROUTE');
        $seller = $this->makeSeller('ROAD-ROUTE');
        $seller['address']->update([
            'latitude' => 14.6000,
            'longitude' => 120.9900,
        ]);
        $record = $this->makePickupAssignment($provider, $seller, $rider, 'ROAD-ROUTE');
        $apiKey = 'geoapify-route-test-key';
        config()->set('maps.geoapify_api_key', $apiKey);

        Http::fake([
            'https://api.geoapify.com/v1/routing*' => Http::response([
                'type' => 'FeatureCollection',
                'features' => [[
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'LineString',
                        'coordinates' => [[120.9842, 14.5995], [120.9870, 14.6000], [120.9900, 14.6000]],
                    ],
                    'properties' => [],
                ]],
            ]),
        ]);

        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.accept', $record['pickup']->pickup_no))
            ->assertRedirect();

        $this->actingAs($rider->user)
            ->get(route('rider.orders.pickup', $record['pickup']->pickup_no))
            ->assertOk()
            ->assertSee('data-pickup-route-enabled="true"', false)
            ->assertSee(route('rider.orders.pickup.route', $record['pickup']->pickup_no), false)
            ->assertSee('class="pickup-route-legend"', false);

        $this->actingAs($rider->user)
            ->postJson(route('rider.location.update'), [
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'accuracy_meters' => 12.4,
            ])
            ->assertOk();

        $this->travel(45)->seconds();

        $this->actingAs($rider->user)
            ->getJson(route('rider.orders.pickup.route', $record['pickup']->pickup_no))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('message', 'Pickup road route loaded.')
            ->assertJsonPath('data.route.geometry.type', 'LineString')
            ->assertJsonPath('data.route.geometry.coordinates.0.0', 120.9842)
            ->assertJsonPath('data.rider.latitude', 14.5995)
            ->assertJsonPath('data.rider.location_age_seconds', 45)
            ->assertJsonPath('data.pickup.longitude', 120.99)
            ->assertJsonPath('data.max_usable_accuracy_meters', 100)
            ->assertDontSee($apiKey);

        Http::assertSent(fn (HttpRequest $request): bool => str_starts_with(
            $request->url(),
            'https://api.geoapify.com/v1/routing'
        )
            && $request['mode'] === 'motorcycle'
            && $request['format'] === 'geojson'
            && $request['apiKey'] === $apiKey
            && $request['waypoints'] === '14.5995000,120.9842000|14.6000000,120.9900000');
        Http::assertSentCount(1);
    }

    public function test_pickup_route_requires_an_owned_accepted_assignment(): void
    {
        $provider = $this->makeProvider('ROAD-ROUTE-AUTH');
        $rider = $this->makeRider($provider, 'ROAD-ROUTE-AUTH');
        $otherRider = $this->makeRider($provider, 'ROAD-ROUTE-OTHER');
        $seller = $this->makeSeller('ROAD-ROUTE-AUTH');
        $record = $this->makePickupAssignment($provider, $seller, $rider, 'ROAD-ROUTE-AUTH');
        config()->set('maps.geoapify_api_key', 'geoapify-route-test-key');
        Http::fake();

        $this->actingAs($rider->user)
            ->get(route('rider.orders.pickup', $record['pickup']->pickup_no))
            ->assertOk()
            ->assertSee('data-pickup-route-enabled="false"', false)
            ->assertSee('data-pickup-status="assigned"', false)
            ->assertSee('class="pickup-route-legend"', false)
            ->assertSee('Your shared Rider location');

        $this->actingAs($rider->user)
            ->getJson(route('rider.orders.pickup.route', $record['pickup']->pickup_no))
            ->assertStatus(409);

        $this->actingAs($otherRider->user)
            ->getJson(route('rider.orders.pickup.route', $record['pickup']->pickup_no))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_pickup_route_requires_fresh_accurate_gps_and_saved_pickup_pin(): void
    {
        $provider = $this->makeProvider('ROAD-ROUTE-GPS');
        $rider = $this->makeRider($provider, 'ROAD-ROUTE-GPS');
        $seller = $this->makeSeller('ROAD-ROUTE-GPS');
        $seller['address']->update([
            'latitude' => 14.6000,
            'longitude' => 120.9900,
        ]);
        $record = $this->makePickupAssignment($provider, $seller, $rider, 'ROAD-ROUTE-GPS');
        config()->set('maps.geoapify_api_key', 'geoapify-route-test-key');
        Http::fake();

        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.accept', $record['pickup']->pickup_no))
            ->assertRedirect();

        $this->actingAs($rider->user)
            ->getJson(route('rider.orders.pickup.route', $record['pickup']->pickup_no))
            ->assertStatus(409);

        $this->actingAs($rider->user)
            ->postJson(route('rider.location.update'), [
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'accuracy_meters' => 101,
            ])
            ->assertOk();

        $this->actingAs($rider->user)
            ->getJson(route('rider.orders.pickup.route', $record['pickup']->pickup_no))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Share a fresh Rider location with accuracy of ±100 m or better to calculate the road route.');

        $this->actingAs($rider->user)
            ->postJson(route('rider.location.update'), [
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'accuracy_meters' => 12,
            ])
            ->assertOk();

        $this->travel(181)->seconds();

        $this->actingAs($rider->user)
            ->getJson(route('rider.orders.pickup.route', $record['pickup']->pickup_no))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Share a fresh Rider location with accuracy of ±100 m or better to calculate the road route.');

        Http::assertNothingSent();

        $seller['address']->update([
            'latitude' => null,
            'longitude' => null,
        ]);
        $unpinned = $this->makePickupAssignment($provider, $seller, $rider, 'ROAD-ROUTE-NO-PIN');
        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.accept', $unpinned['pickup']->pickup_no))
            ->assertRedirect();
        $this->actingAs($rider->user)
            ->postJson(route('rider.location.update'), [
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'accuracy_meters' => 12,
            ])
            ->assertOk();

        $this->actingAs($rider->user)
            ->getJson(route('rider.orders.pickup.route', $unpinned['pickup']->pickup_no))
            ->assertStatus(409)
            ->assertJsonPath('message', 'A saved pickup map pin is required. The approximate address map cannot be used for a road route.');

        Http::assertNothingSent();
    }

    public function test_pickup_route_provider_failure_does_not_return_route_data(): void
    {
        $provider = $this->makeProvider('ROAD-ROUTE-FAILURE');
        $rider = $this->makeRider($provider, 'ROAD-ROUTE-FAILURE');
        $seller = $this->makeSeller('ROAD-ROUTE-FAILURE');
        $seller['address']->update([
            'latitude' => 14.6000,
            'longitude' => 120.9900,
        ]);
        $record = $this->makePickupAssignment($provider, $seller, $rider, 'ROAD-ROUTE-FAILURE');
        config()->set('maps.geoapify_api_key', 'geoapify-route-test-key');
        Http::fake([
            'https://api.geoapify.com/v1/routing*' => Http::response(['message' => 'provider failure'], 503),
        ]);

        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.accept', $record['pickup']->pickup_no))
            ->assertRedirect();
        $this->actingAs($rider->user)
            ->postJson(route('rider.location.update'), [
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'accuracy_meters' => 12,
            ])
            ->assertOk();

        $this->actingAs($rider->user)
            ->getJson(route('rider.orders.pickup.route', $record['pickup']->pickup_no))
            ->assertStatus(502)
            ->assertJsonMissingPath('data.route')
            ->assertDontSee('geoapify-route-test-key');
    }

    public function test_pickup_route_is_discarded_if_assignment_is_confirmed_during_routing(): void
    {
        $provider = $this->makeProvider('ROAD-ROUTE-RACE-STATUS');
        $rider = $this->makeRider($provider, 'ROAD-ROUTE-RACE-STATUS');
        $seller = $this->makeSeller('ROAD-ROUTE-RACE-STATUS');
        $seller['address']->update([
            'latitude' => 14.6000,
            'longitude' => 120.9900,
        ]);
        $record = $this->makePickupAssignment($provider, $seller, $rider, 'ROAD-ROUTE-RACE-STATUS');
        config()->set('maps.geoapify_api_key', 'geoapify-route-test-key');

        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.accept', $record['pickup']->pickup_no))
            ->assertRedirect();
        $this->actingAs($rider->user)
            ->postJson(route('rider.location.update'), [
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'accuracy_meters' => 12,
            ])
            ->assertOk();

        Http::fake(function (HttpRequest $request) use ($record) {
            $record['assignment']->update(['status' => 'picked_up']);

            return Http::response([
                'type' => 'FeatureCollection',
                'features' => [[
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'LineString',
                        'coordinates' => [[120.9842, 14.5995], [120.9900, 14.6000]],
                    ],
                ]],
            ]);
        });

        $this->actingAs($rider->user)
            ->getJson(route('rider.orders.pickup.route', $record['pickup']->pickup_no))
            ->assertStatus(409)
            ->assertJsonPath('message', 'The pickup assignment changed while calculating the road route.')
            ->assertJsonMissingPath('data.route');
    }

    public function test_pickup_route_is_discarded_if_location_sharing_stops_during_routing(): void
    {
        $provider = $this->makeProvider('ROAD-ROUTE-RACE-GPS');
        $rider = $this->makeRider($provider, 'ROAD-ROUTE-RACE-GPS');
        $seller = $this->makeSeller('ROAD-ROUTE-RACE-GPS');
        $seller['address']->update([
            'latitude' => 14.6000,
            'longitude' => 120.9900,
        ]);
        $record = $this->makePickupAssignment($provider, $seller, $rider, 'ROAD-ROUTE-RACE-GPS');
        config()->set('maps.geoapify_api_key', 'geoapify-route-test-key');

        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.accept', $record['pickup']->pickup_no))
            ->assertRedirect();
        $this->actingAs($rider->user)
            ->postJson(route('rider.location.update'), [
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'accuracy_meters' => 12,
            ])
            ->assertOk();

        Http::fake(function (HttpRequest $request) use ($rider) {
            $rider->liveLocation()->delete();

            return Http::response([
                'type' => 'FeatureCollection',
                'features' => [[
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'LineString',
                        'coordinates' => [[120.9842, 14.5995], [120.9900, 14.6000]],
                    ],
                ]],
            ]);
        });

        $this->actingAs($rider->user)
            ->getJson(route('rider.orders.pickup.route', $record['pickup']->pickup_no))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Rider sharing stopped or the live GPS fix became stale while calculating the route.')
            ->assertJsonMissingPath('data.route');
    }

    public function test_pickup_route_is_unavailable_without_geoapify_configuration(): void
    {
        $provider = $this->makeProvider('ROAD-ROUTE-NO-KEY');
        $rider = $this->makeRider($provider, 'ROAD-ROUTE-NO-KEY');
        $seller = $this->makeSeller('ROAD-ROUTE-NO-KEY');
        $seller['address']->update([
            'latitude' => 14.6000,
            'longitude' => 120.9900,
        ]);
        $record = $this->makePickupAssignment($provider, $seller, $rider, 'ROAD-ROUTE-NO-KEY');
        config()->set('maps.geoapify_api_key', null);
        Http::fake();

        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.accept', $record['pickup']->pickup_no))
            ->assertRedirect();
        $this->actingAs($rider->user)
            ->postJson(route('rider.location.update'), [
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'accuracy_meters' => 12,
            ])
            ->assertOk();

        $this->actingAs($rider->user)
            ->getJson(route('rider.orders.pickup.route', $record['pickup']->pickup_no))
            ->assertStatus(503)
            ->assertJsonPath('message', 'Road routing is not configured. The pickup location is still available on the map.');

        Http::assertNothingSent();
    }

    public function test_rider_can_confirm_own_accepted_pickup(): void
    {
        $provider =
            $this->makeProvider('PICKUP');

        $rider =
            $this->makeRider(
                $provider,
                'PICKUP'
            );

        $seller =
            $this->makeSeller('PICKUP');

        $record =
            $this->makePickupAssignment(
                $provider,
                $seller,
                $rider,
                'PICKUP'
            );

        $this
            ->actingAs($rider->user)
            ->post(
                route(
                    'rider.orders.pickup.accept',
                    $record['pickup']->pickup_no
                )
            )
            ->assertRedirect();

        $this
            ->actingAs($rider->user)
            ->post(
                route(
                    'rider.orders.pickup.confirm',
                    $record['pickup']->pickup_no
                ),
                [
                    'waybill_identifier' => $record['waybill']->waybill_no,
                    'scan_method' => 'barcode',
                ]
            )
            ->assertRedirect(
                route(
                    'rider.orders.pickup',
                    $record['pickup']->pickup_no
                )
            )
            ->assertSessionHas(
                'job_status',
                'Pickup confirmed. Parcels are now marked as picked up.'
            );

        $assignment =
            $record['assignment']->fresh();

        $this->assertSame(
            'picked_up',
            $assignment->status
        );

        $this->assertNotNull(
            $assignment->accepted_at
        );

        $this->assertNotNull(
            $assignment->picked_up_at
        );

        /*
        * Pickup Request is NOT complete until
        * Sorting Center intake in Phase 1D.
        */
        $this->assertSame(
            'scheduled',
            $record['pickup']
                ->fresh()
                ->status
        );

        $this->assertNull(
            $record['pickup']
                ->fresh()
                ->completed_at
        );

        $this->assertSame(
            ParcelStatus::PickedUp->value,
            $record['parcel']
                ->fresh()
                ->status
        );

        $this->assertSame(
            ShipmentStatus::PickedUp->value,
            $record['shipment']
                ->fresh()
                ->status
        );

        $this->assertDatabaseHas(
            'shipment_events',
            [
                'shipment_id' => $record['shipment']->id,

                'parcel_id' => $record['parcel']->id,

                'event_type' => 'parcel_picked_up',

                'to_status' => ParcelStatus::PickedUp->value,

                'actor_user_id' => $rider->user_id,

                'source' => 'rider',
            ]
        );

        $this->assertDatabaseHas(
            'shipment_events',
            [
                'shipment_id' => $record['shipment']->id,

                'parcel_id' => null,

                'event_type' => 'shipment_picked_up',

                'to_status' => ShipmentStatus::PickedUp->value,

                'actor_user_id' => $rider->user_id,

                'source' => 'rider',
            ]
        );

        $this
            ->actingAs($rider->user)
            ->get(
                route(
                    'rider.orders.pickup',
                    $record['pickup']->pickup_no
                )
            )
            ->assertOk()
            ->assertSeeText('Parcels collected.')
            ->assertSeeText('assigned Sorting Center.')
            ->assertSeeText('Logistics will scan and')
            ->assertSeeText('record each parcel in Incoming Parcels')
            ->assertSeeText('receiving the handoff.')
            ->assertSee('data-destination-map', false)
            ->assertSee('data-map-barangay="San Rafael"', false)
            ->assertSee('data-map-city="San Pablo City"', false)
            ->assertSee('data-map-forward-geocode-url="'.route('maps.forward-geocode').'"', false)
            ->assertSee('id="pickup-map-status"', false);
    }

    public function test_rider_pickup_moves_return_case_into_transit(): void
    {
        $provider = $this->makeProvider('RETURN-PICKUP');
        $rider = $this->makeRider($provider, 'RETURN-PICKUP');
        $seller = $this->makeSeller('RETURN-PICKUP');
        $record = $this->makePickupAssignment($provider, $seller, $rider, 'RETURN-PICKUP');

        $returnRequest = ReturnRequest::query()->create([
            'return_no' => 'RR-RIDER-PICKUP-001',
            'order_id' => $record['order']->id,
            'seller_order_id' => $record['sellerOrder']->id,
            'buyer_id' => $record['order']->buyer_id,
            'store_id' => $seller['store']->id,
            'request_type' => 'return_refund',
            'reason_code' => 'other',
            'status' => 'return_pickup_requested',
            'requested_amount_minor' => 10000,
            'submitted_at' => now(),
        ]);
        $record['assignment']->delete();
        $record['pickup']->update([
            'return_request_id' => $returnRequest->id,
            'status' => 'requested',
            'pickup_address_snapshot' => [
                'recipient_name' => $record['order']->recipient_name,
                'phone' => $record['order']->recipient_phone,
                'street' => $record['order']->address_line,
                'barangay' => $record['order']->barangay,
                'city_municipality' => $record['order']->city_municipality,
                'province' => $record['order']->province,
                'postal_code' => $record['order']->postal_code,
            ],
        ]);
        $record['shipment']->update([
            'purpose' => 'return',
            'status' => ShipmentStatus::ReadyForPickup->value,
        ]);
        $returnRequest->update(['return_shipment_id' => $record['shipment']->id]);

        $this->actingAs($provider['user'])
            ->post(route('logistics.pickups.verify', $record['pickup']->id))
            ->assertRedirect();

        $this->actingAs($provider['user'])
            ->post(route('logistics.pickups.assign', $record['pickup']->id), [
                'rider_profile_id' => $rider->id,
            ])
            ->assertRedirect();

        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.accept', $record['pickup']->pickup_no))
            ->assertRedirect();

        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.confirm', $record['pickup']->pickup_no), [
                'waybill_identifier' => $record['parcel']->parcel_no,
                'scan_method' => 'camera',
            ])
            ->assertRedirect();

        $this->assertSame('return_in_transit', $returnRequest->fresh()->status);
        $this->assertDatabaseHas('return_events', [
            'return_request_id' => $returnRequest->id,
            'event_type' => 'return_picked_up',
            'from_status' => 'return_pickup_requested',
            'to_status' => 'return_in_transit',
            'actor_user_id' => $rider->user_id,
        ]);
        $this->assertSame(
            0,
            RiderEarning::query()->count(),
            'Return pickups do not receive Seller outbound pickup earnings.',
        );
    }

    public function test_pickup_earnings_use_saved_size_class_and_attendance_is_reporting_only(): void
    {
        $provider = $this->makeProvider('COMPENSATION');
        $rider = $this->makeRider($provider, 'COMPENSATION');
        $seller = $this->makeSeller('COMPENSATION');
        $regular = $this->makePickupAssignment($provider, $seller, $rider, 'COMPENSATION-REGULAR');

        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.accept', $regular['pickup']->pickup_no))
            ->assertRedirect();
        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.confirm', $regular['pickup']->pickup_no), [
                'waybill_identifier' => $regular['waybill']->waybill_no,
                'scan_method' => 'barcode',
            ])
            ->assertRedirect();

        $regularEarning = RiderEarning::query()->sole();
        $this->assertSame('pickup_parcel', $regularEarning->type);
        $this->assertSame('posted', $regularEarning->status);
        $this->assertSame(1500, (int) $regularEarning->amount_minor);
        $this->assertSame(1500, (int) $regularEarning->rate_minor);
        $this->assertSame('small', $regularEarning->size_class_snapshot);
        $this->assertNull($regularEarning->rider_shift_id, 'A missing attendance punch must not suppress a collected pickup earning.');

        $duplicate = app(RiderPickupEarningService::class)->recordForPickup(
            $regular['assignment']->fresh(),
            collect([$regular['parcel']->fresh('shipment')]),
            Carbon::parse($regular['assignment']->fresh()->picked_up_at),
        );
        $this->assertCount(1, $duplicate);
        $this->assertSame($regularEarning->id, $duplicate->sole()->id);
        $this->assertSame(1, RiderEarning::query()->count());

        $this->travelTo(Carbon::parse('2026-11-04 06:45:00', 'UTC'));
        $this->scanAttendanceQr($rider->user, $provider['user'])->assertOk();
        $shift = $rider->shifts()->whereNull('checked_out_at')->sole();

        $bulky = $this->makePickupAssignment($provider, $seller, $rider, 'COMPENSATION-BULKY');
        $bulky['parcel']->update(['size_class' => 'bulky']);
        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.accept', $bulky['pickup']->pickup_no))
            ->assertRedirect();
        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.confirm', $bulky['pickup']->pickup_no), [
                'waybill_identifier' => $bulky['waybill']->waybill_no,
                'scan_method' => 'barcode',
            ])
            ->assertRedirect();
        $this->scanAttendanceQr($rider->user, $provider['user'])->assertOk();

        $bulkyEarning = RiderEarning::query()->where('parcel_id', $bulky['parcel']->id)->sole();
        $this->assertSame('posted', $bulkyEarning->status);
        $this->assertSame(3000, (int) $bulkyEarning->amount_minor);
        $this->assertSame(3000, (int) $bulkyEarning->rate_minor);
        $this->assertSame('bulky', $bulkyEarning->size_class_snapshot);
        $this->assertSame((int) $shift->id, (int) $bulkyEarning->rider_shift_id);

        $this->actingAs($rider->user)
            ->get(route('rider.earnings.index'))
            ->assertOk()
            ->assertSee('Earnings by attendance shift')
            ->assertSee('No attendance shift recorded')
            ->assertSee('Saved size: small')
            ->assertSee('Saved size: bulky')
            ->assertSee('₱15.00')
            ->assertSee('₱30.00')
            ->assertSee('Shift · Nov 4, 2026 · 2:45 PM PHT')
            ->assertSee('Checked out 2:45 PM PHT');
    }

    public function test_unknown_pickup_size_is_held_until_provider_classifies_it(): void
    {
        $provider = $this->makeProvider('COMPENSATION-REVIEW');
        $rider = $this->makeRider($provider, 'COMPENSATION-REVIEW');
        $seller = $this->makeSeller('COMPENSATION-REVIEW');
        $record = $this->makePickupAssignment($provider, $seller, $rider, 'COMPENSATION-UNKNOWN');
        $record['parcel']->update(['size_class' => 'unlisted']);

        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.accept', $record['pickup']->pickup_no))
            ->assertRedirect();
        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.confirm', $record['pickup']->pickup_no), [
                'waybill_identifier' => $record['waybill']->waybill_no,
                'scan_method' => 'barcode',
            ])
            ->assertRedirect();

        $earning = RiderEarning::query()->sole();
        $this->assertSame('held', $earning->status);
        $this->assertSame(0, (int) $earning->amount_minor);
        $this->assertNull($earning->rate_minor);
        $this->assertSame('unlisted', $earning->size_class_snapshot);
        $this->assertCount(0, app(RiderPayoutBatchService::class)
            ->eligibleEarningsFor($provider['user'], $rider));

        $classified = app(RiderPickupEarningService::class)->classifyHeldEarning(
            $earning,
            'bulky',
            $provider['user'],
        );
        $this->assertSame('posted', $classified->status);
        $this->assertSame(3000, (int) $classified->amount_minor);
        $this->assertSame(3000, (int) $classified->rate_minor);
        $this->assertSame('bulky', $classified->size_class_snapshot);
        $this->assertSame('bulky', $record['parcel']->fresh()->size_class);
    }

    public function test_rider_can_confirm_pickup_using_the_printed_parcel_barcode(): void
    {
        $provider = $this->makeProvider('PRINTED-BARCODE');
        $rider = $this->makeRider($provider, 'PRINTED-BARCODE');
        $seller = $this->makeSeller('PRINTED-BARCODE');
        $record = $this->makePickupAssignment($provider, $seller, $rider, 'PRINTED-BARCODE');

        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.accept', $record['pickup']->pickup_no))
            ->assertRedirect();

        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.confirm', $record['pickup']->pickup_no), [
                'waybill_identifier' => $record['parcel']->parcel_no,
                'scan_method' => 'camera',
            ])
            ->assertRedirect(route('rider.orders.pickup', $record['pickup']->pickup_no));

        $this->assertSame('picked_up', $record['assignment']->fresh()->status);
        $this->assertSame('picked_up', $record['parcel']->fresh()->status);
        $event = $record['shipment']->events()
            ->where('parcel_id', $record['parcel']->id)
            ->where('event_type', 'parcel_picked_up')
            ->sole();
        $this->assertSame('camera', $event->metadata['scan_method']);
    }

    public function test_rider_cannot_confirm_pickup_without_a_waybill_or_parcel_code(): void
    {
        $provider = $this->makeProvider('SCAN-REQUIRED');
        $rider = $this->makeRider($provider, 'SCAN-REQUIRED');
        $seller = $this->makeSeller('SCAN-REQUIRED');
        $record = $this->makePickupAssignment($provider, $seller, $rider, 'SCAN-REQUIRED');

        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.accept', $record['pickup']->pickup_no))
            ->assertRedirect();

        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.confirm', $record['pickup']->pickup_no), [])
            ->assertSessionHasErrors('waybill_identifier');

        $this->assertSame('accepted', $record['assignment']->fresh()->status);
        $this->assertSame('ready_for_pickup', $record['parcel']->fresh()->status);
    }

    public function test_logistics_batch_consolidates_pickup_earnings_across_assignments_and_shifts(): void
    {
        $provider = $this->makeProvider('MULTI-SHIFT-BATCH');
        $rider = $this->makeRider($provider, 'MULTI-SHIFT-BATCH');
        $seller = $this->makeSeller('MULTI-SHIFT-BATCH');
        $earnings = collect();

        foreach (['MULTI-SHIFT-FIRST', 'MULTI-SHIFT-SECOND'] as $suffix) {
            $record = $this->makePickupAssignment($provider, $seller, $rider, $suffix);

            $this->scanAttendanceQr($rider->user, $provider['user'])->assertOk();
            $shift = $rider->shifts()->whereNull('checked_out_at')->sole();

            $this->actingAs($rider->user)
                ->post(route('rider.orders.pickup.accept', $record['pickup']->pickup_no))
                ->assertRedirect();
            $this->actingAs($rider->user)
                ->post(route('rider.orders.pickup.confirm', $record['pickup']->pickup_no), [
                    'waybill_identifier' => $record['waybill']->waybill_no,
                    'scan_method' => 'barcode',
                ])
                ->assertRedirect();
            $this->scanAttendanceQr($rider->user, $provider['user'])->assertOk();

            $earning = RiderEarning::query()->where('parcel_id', $record['parcel']->id)->sole();
            $this->assertSame((int) $shift->id, (int) $earning->rider_shift_id);
            $earnings->push($earning);
        }

        $this->assertSame(2, $earnings->pluck('pickup_assignment_id')->unique()->count());
        $this->assertSame(2, $earnings->pluck('rider_shift_id')->unique()->count());

        $this->actingAs($provider['user'])
            ->post(route('logistics.settlements.payout-batches.store'), [
                'rider_profile_id' => $rider->id,
                'notes' => 'Consolidate separate pickup shifts.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $batch = RiderPayoutBatch::query()->sole();
        $this->assertSame(3000, (int) $batch->total_minor);
        $this->assertDatabaseCount('rider_payout_batch_items', 2);
        foreach ($earnings as $earning) {
            $this->assertDatabaseHas('rider_payout_batch_items', [
                'rider_payout_batch_id' => $batch->id,
                'rider_earning_id' => $earning->id,
                'amount_minor' => 1500,
                'earning_type' => 'pickup_parcel',
            ]);
        }
    }

    public function test_rider_compensation_migration_refuses_to_drop_payout_history(): void
    {
        $provider = $this->makeProvider('ROLLBACK-GUARD');
        $rider = $this->makeRider($provider, 'ROLLBACK-GUARD');
        $batch = RiderPayoutBatch::query()->create([
            'batch_no' => 'RPB-ROLLBACK-GUARD',
            'logistics_profile_id' => $provider['profile']->id,
            'rider_profile_id' => $rider->id,
            'status' => 'pending_review',
            'total_minor' => 0,
        ]);
        $migration = require database_path('migrations/2026_11_03_000001_add_flexible_rider_assignment_and_payouts.php');

        try {
            $migration->down();
            $this->fail('Rollback must not erase Rider payout history.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('preserve it before rolling back', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('rider_payout_batches'));
        $this->assertDatabaseHas('rider_payout_batches', [
            'id' => $batch->id,
            'batch_no' => 'RPB-ROLLBACK-GUARD',
        ]);
    }

    public function test_pickup_cannot_be_confirmed_before_acceptance(): void
    {
        $provider =
            $this->makeProvider(
                'CONFIRM-EARLY'
            );

        $rider =
            $this->makeRider(
                $provider,
                'CONFIRM-EARLY'
            );

        $seller =
            $this->makeSeller(
                'CONFIRM-EARLY'
            );

        $record =
            $this->makePickupAssignment(
                $provider,
                $seller,
                $rider,
                'CONFIRM-EARLY'
            );

        $this
            ->actingAs($rider->user)
            ->post(
                route(
                    'rider.orders.pickup.confirm',
                    $record['pickup']->pickup_no
                ),
                [
                    'waybill_identifier' => $record['parcel']->parcel_no,
                    'scan_method' => 'camera',
                ]
            )
            ->assertRedirect(route('rider.orders.pickup', $record['pickup']->pickup_no))
            ->assertSessionHas('pickup_error', 'This pickup assignment cannot be confirmed in its current state.');

        $this->assertSame(
            'assigned',
            $record['assignment']
                ->fresh()
                ->status
        );

        $this->assertNull(
            $record['assignment']
                ->fresh()
                ->picked_up_at
        );

        $this->assertSame(
            ShipmentStatus::PickupAssigned->value,
            $record['shipment']
                ->fresh()
                ->status
        );
    }

    public function test_rider_cannot_confirm_another_riders_pickup(): void
    {
        $provider =
            $this->makeProvider(
                'CONFIRM-FOREIGN'
            );

        $assignedRider =
            $this->makeRider(
                $provider,
                'CONFIRM-OWNER'
            );

        $foreignRider =
            $this->makeRider(
                $provider,
                'CONFIRM-OTHER'
            );

        $seller =
            $this->makeSeller(
                'CONFIRM-FOREIGN'
            );

        $record =
            $this->makePickupAssignment(
                $provider,
                $seller,
                $assignedRider,
                'CONFIRM-FOREIGN'
            );

        $this
            ->actingAs(
                $assignedRider->user
            )
            ->post(
                route(
                    'rider.orders.pickup.accept',
                    $record['pickup']->pickup_no
                )
            )
            ->assertRedirect();

        $this
            ->actingAs(
                $foreignRider->user
            )
            ->post(
                route(
                    'rider.orders.pickup.confirm',
                    $record['pickup']->pickup_no
                ),
                [
                    'waybill_identifier' => $record['parcel']->parcel_no,
                    'scan_method' => 'camera',
                ]
            )
            ->assertNotFound();

        $this->assertSame(
            'accepted',
            $record['assignment']
                ->fresh()
                ->status
        );

        $this->assertSame(
            ShipmentStatus::PickupAssigned->value,
            $record['shipment']
                ->fresh()
                ->status
        );
    }

    public function test_pickup_confirmation_cannot_be_repeated(): void
    {
        $provider =
            $this->makeProvider(
                'CONFIRM-ONCE'
            );

        $rider =
            $this->makeRider(
                $provider,
                'CONFIRM-ONCE'
            );

        $seller =
            $this->makeSeller(
                'CONFIRM-ONCE'
            );

        $record =
            $this->makePickupAssignment(
                $provider,
                $seller,
                $rider,
                'CONFIRM-ONCE'
            );

        $this
            ->actingAs($rider->user)
            ->post(
                route(
                    'rider.orders.pickup.accept',
                    $record['pickup']->pickup_no
                )
            )
            ->assertRedirect();

        $url = route(
            'rider.orders.pickup.confirm',
            $record['pickup']->pickup_no
        );

        $this
            ->actingAs($rider->user)
            ->post($url, [
                'waybill_identifier' => $record['parcel']->parcel_no,
                'scan_method' => 'camera',
            ])
            ->assertRedirect();

        $pickedUpAt =
            $record['assignment']
                ->fresh()
                ->picked_up_at;

        $this
            ->actingAs($rider->user)
            ->post($url, [
                'waybill_identifier' => $record['parcel']->parcel_no,
                'scan_method' => 'camera',
            ])
            ->assertRedirect(route('rider.orders.pickup', $record['pickup']->pickup_no))
            ->assertSessionHas('pickup_error', 'This pickup assignment cannot be confirmed in its current state.');

        $assignment =
            $record['assignment']->fresh();

        $this->assertSame(
            'picked_up',
            $assignment->status
        );

        $this->assertTrue(
            $assignment
                ->picked_up_at
                ->equalTo($pickedUpAt)
        );

        $this->assertSame(
            1,
            $record['shipment']
                ->events()
                ->where(
                    'event_type',
                    'parcel_picked_up'
                )
                ->count()
        );

        $this->assertSame(
            1,
            $record['shipment']
                ->events()
                ->where(
                    'event_type',
                    'shipment_picked_up'
                )
                ->count()
        );
    }

    public function test_rider_can_accept_own_assigned_pickup(): void
    {
        $provider =
            $this->makeProvider('ACCEPT');

        $rider =
            $this->makeRider(
                $provider,
                'ACCEPT'
            );

        $seller =
            $this->makeSeller('ACCEPT');

        $record =
            $this->makePickupAssignment(
                $provider,
                $seller,
                $rider,
                'ACCEPT'
            );

        $this
            ->actingAs($rider->user)
            ->post(
                route(
                    'rider.orders.pickup.accept',
                    $record['pickup']->pickup_no
                )
            )
            ->assertRedirect(
                route(
                    'rider.orders.pickup',
                    $record['pickup']->pickup_no
                )
            )
            ->assertSessionHas(
                'job_status',
                'Pickup assignment accepted.'
            );

        $assignment =
            $record['assignment']->fresh();

        $this->assertSame(
            'accepted',
            $assignment->status
        );

        $this->assertNotNull(
            $assignment->accepted_at
        );

        $this->assertSame(
            'scheduled',
            $record['pickup']
                ->fresh()
                ->status
        );

        $this->assertSame(
            ShipmentStatus::PickupAssigned->value,
            $record['shipment']
                ->fresh()
                ->status
        );

        $this->assertSame(
            'ready_for_pickup',
            $record['parcel']
                ->fresh()
                ->status
        );
    }

    public function test_rider_cannot_accept_another_riders_pickup(): void
    {
        $provider =
            $this->makeProvider('ACCEPT-FOREIGN');

        $assignedRider =
            $this->makeRider(
                $provider,
                'ACCEPT-OWNER'
            );

        $foreignRider =
            $this->makeRider(
                $provider,
                'ACCEPT-OTHER'
            );

        $seller =
            $this->makeSeller(
                'ACCEPT-FOREIGN'
            );

        $record =
            $this->makePickupAssignment(
                $provider,
                $seller,
                $assignedRider,
                'ACCEPT-FOREIGN'
            );

        $this
            ->actingAs(
                $foreignRider->user
            )
            ->post(
                route(
                    'rider.orders.pickup.accept',
                    $record['pickup']->pickup_no
                )
            )
            ->assertNotFound();

        $this->assertSame(
            'assigned',
            $record['assignment']
                ->fresh()
                ->status
        );

        $this->assertNull(
            $record['assignment']
                ->fresh()
                ->accepted_at
        );
    }

    public function test_pickup_acceptance_is_idempotent_when_repeated(): void
    {
        $provider =
            $this->makeProvider('ACCEPT-ONCE');

        $rider =
            $this->makeRider(
                $provider,
                'ACCEPT-ONCE'
            );

        $seller =
            $this->makeSeller(
                'ACCEPT-ONCE'
            );

        $record =
            $this->makePickupAssignment(
                $provider,
                $seller,
                $rider,
                'ACCEPT-ONCE'
            );

        $url = route(
            'rider.orders.pickup.accept',
            $record['pickup']->pickup_no
        );

        $this
            ->actingAs($rider->user)
            ->post($url)
            ->assertRedirect();

        $acceptedAt =
            $record['assignment']
                ->fresh()
                ->accepted_at;

        $this
            ->actingAs($rider->user)
            ->post($url)
            ->assertRedirect(
                route(
                    'rider.orders.pickup',
                    $record['pickup']->pickup_no
                )
            )
            ->assertSessionHas(
                'job_status',
                'Pickup assignment accepted.'
            );

        $assignment =
            $record['assignment']->fresh();

        $this->assertSame(
            'accepted',
            $assignment->status
        );

        $this->assertTrue(
            $assignment
                ->accepted_at
                ->equalTo($acceptedAt)
        );
    }

    public function test_rider_dashboard_lists_only_own_active_pickup_assignments(): void
    {
        $provider =
            $this->makeProvider('OWN');

        $rider =
            $this->makeRider(
                $provider,
                'OWN'
            );

        $foreignRider =
            $this->makeRider(
                $provider,
                'FOREIGN'
            );

        $seller =
            $this->makeSeller('OWN');

        $own =
            $this->makePickupAssignment(
                $provider,
                $seller,
                $rider,
                'OWN'
            );

        $foreign =
            $this->makePickupAssignment(
                $provider,
                $seller,
                $foreignRider,
                'FOREIGN'
            );

        $completed =
            $this->makePickupAssignment(
                $provider,
                $seller,
                $rider,
                'COMPLETED',
                'completed'
            );

        $response = $this
            ->actingAs($rider->user)
            ->get(
                route(
                    'rider.dashboard.pickups'
                )
            );

        $response
            ->assertOk()
            ->assertSee(
                $own['pickup']->pickup_no
            )
            ->assertSee(
                $seller['store']->name
            )
            ->assertDontSee(
                $foreign['pickup']->pickup_no
            )
            ->assertDontSee(
                $completed['pickup']->pickup_no
            )
            ->assertDontSee('PU-24091')
            ->assertDontSee(
                'Available nearby'
            )
            ->assertDontSee(
                'Accept job'
            );
    }

    public function test_rider_can_view_own_pickup_with_real_manifest(): void
    {
        $provider =
            $this->makeProvider('DETAIL');

        $rider =
            $this->makeRider(
                $provider,
                'DETAIL'
            );

        $seller =
            $this->makeSeller('DETAIL');

        $record =
            $this->makePickupAssignment(
                $provider,
                $seller,
                $rider,
                'DETAIL',
                'accepted'
            );
        $record['sellerOrder']->items()->create([
            'product_name' => 'Rider Pickup Preview Product',
            'variant_name' => 'Green / One Size',
            'sku' => 'RIDER-PICKUP-PREVIEW-001',
            'unit_price_minor' => 9900,
            'quantity' => 1,
            'discount_minor' => 0,
            'subtotal_minor' => 9900,
            'product_image_disk' => 'public',
            'product_image_path' => 'seller-products/rider-pickup-preview.png',
        ]);

        $this
            ->actingAs($rider->user)
            ->get(
                route(
                    'rider.orders.pickup',
                    $record['pickup']
                        ->pickup_no
                )
            )
            ->assertOk()
            ->assertSee(
                $record['pickup']
                    ->pickup_no
            )
            ->assertSee(
                $seller['store']->name
            )
            ->assertSee(
                $record['parcel']
                    ->parcel_no
            )
            ->assertSee(
                $record['waybill']
                    ->waybill_no
            )
            ->assertSee(
                $record['waybill']
                    ->barcode_value
            )
            ->assertSee('rider-waybill-expected', false)
            ->assertSee('data-waybill-scan-form', false)
            ->assertSee('Printed Parcel barcode (Code 128):')
            ->assertSee('data-waybill-identifier', false)
            ->assertSee('Match the parcel waybill')
            ->assertSee('Rider Pickup Preview Product')
            ->assertSee('seller-products/rider-pickup-preview.png', false)
            ->assertSee(
                'Use the loading bay.'
            )
            ->assertSee('data-single-submit', false);
    }

    public function test_rider_pickup_detail_keeps_map_address_when_the_address_is_soft_deleted(): void
    {
        $provider = $this->makeProvider('SOFT-DELETED-MAP');
        $rider = $this->makeRider($provider, 'SOFT-DELETED-MAP');
        $seller = $this->makeSeller('SOFT-DELETED-MAP');
        $record = $this->makePickupAssignment($provider, $seller, $rider, 'SOFT-DELETED-MAP');

        $seller['address']->delete();

        $this->actingAs($rider->user)
            ->get(route('rider.orders.pickup', $record['pickup']->pickup_no))
            ->assertOk()
            ->assertSee('San Rafael')
            ->assertSee('data-map-barangay="San Rafael"', false)
            ->assertSee('data-map-city="San Pablo City"', false)
            ->assertSee('data-map-forward-geocode-url="'.route('maps.forward-geocode').'"', false);
    }

    public function test_rider_cannot_confirm_pickup_with_a_parcel_barcode_or_waybill_from_another_assignment(): void
    {
        $provider = $this->makeProvider('SCAN-SCOPE');
        $rider = $this->makeRider($provider, 'SCAN-SCOPE');
        $seller = $this->makeSeller('SCAN-SCOPE');
        $record = $this->makePickupAssignment($provider, $seller, $rider, 'SCAN-SCOPE');
        $other = $this->makePickupAssignment($provider, $seller, $rider, 'SCAN-OTHER');

        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.accept', $record['pickup']->pickup_no))
            ->assertRedirect();

        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.confirm', $record['pickup']->pickup_no), [
                'waybill_identifier' => $other['parcel']->parcel_no,
                'scan_method' => 'camera',
            ])
            ->assertRedirect(route('rider.orders.pickup', $record['pickup']->pickup_no))
            ->assertSessionHas('pickup_error', 'The scanned Parcel barcode or Waybill reference does not belong to this Rider pickup assignment.');

        $this->actingAs($rider->user)
            ->post(route('rider.orders.pickup.confirm', $record['pickup']->pickup_no), [
                'waybill_identifier' => $other['waybill']->waybill_no,
                'scan_method' => 'barcode',
            ])
            ->assertRedirect(route('rider.orders.pickup', $record['pickup']->pickup_no))
            ->assertSessionHas('pickup_error', 'The scanned Parcel barcode or Waybill reference does not belong to this Rider pickup assignment.');

        $this->actingAs($rider->user)
            ->get(route('rider.orders.pickup', $record['pickup']->pickup_no))
            ->assertOk()
            ->assertSee('The scanned Parcel barcode or Waybill reference does not belong to this Rider pickup assignment.')
            ->assertSee('value="'.$other['waybill']->waybill_no.'"', false);

        $this->assertSame('accepted', $record['assignment']->fresh()->status);
        $this->assertSame('ready_for_pickup', $record['parcel']->fresh()->status);
    }

    public function test_rider_can_reopen_completed_pickup_as_read_only_after_sorting_center_intake(): void
    {
        $provider = $this->makeProvider('COMPLETED-DETAIL');
        $rider = $this->makeRider(
            $provider,
            'COMPLETED-DETAIL'
        );
        $seller = $this->makeSeller('COMPLETED-DETAIL');
        $record = $this->makePickupAssignment(
            $provider,
            $seller,
            $rider,
            'COMPLETED-DETAIL',
            'completed'
        );

        $record['parcel']->update([
            'status' => ParcelStatus::Received->value,
        ]);
        $record['shipment']->update([
            'status' => ShipmentStatus::AtSortingCenter->value,
        ]);

        $this
            ->actingAs($rider->user)
            ->get(
                route(
                    'rider.orders.pickup',
                    $record['pickup']->pickup_no
                )
            )
            ->assertOk()
            ->assertSee('Completed')
            ->assertSee($seller['store']->name)
            ->assertSee($record['parcel']->parcel_no)
            ->assertSee('Pickup complete. Logistics received all parcels');
    }

    public function test_rider_cannot_view_another_riders_pickup(): void
    {
        $provider =
            $this->makeProvider('FOREIGN');

        $assignedRider =
            $this->makeRider(
                $provider,
                'ASSIGNED'
            );

        $foreignRider =
            $this->makeRider(
                $provider,
                'OTHER'
            );

        $seller =
            $this->makeSeller('FOREIGN');

        $record =
            $this->makePickupAssignment(
                $provider,
                $seller,
                $assignedRider,
                'FOREIGN'
            );

        $this
            ->actingAs(
                $foreignRider->user
            )
            ->get(
                route(
                    'rider.orders.pickup',
                    $record['pickup']
                        ->pickup_no
                )
            )
            ->assertNotFound();
    }

    public function test_rider_without_profile_gets_empty_pickup_dashboard(): void
    {
        $user =
            User::factory()->create([
                'name' => 'Rider Without Profile',

                'email' => 'rider-no-profile@example.test',

                'role' => UserRole::Rider->value,

                'status' => AccountStatus::Active->value,
            ]);

        $this
            ->actingAs($user)
            ->get(
                route(
                    'rider.dashboard.pickups'
                )
            )
            ->assertOk()
            ->assertSee(
                'No active pickup assignments.'
            );
    }

    public function test_approved_rider_can_control_availability_and_logistics_only_lists_available_riders(): void
    {
        Event::fake([RiderOperationalStatusUpdated::class]);

        $provider = $this->makeProvider('AVAILABILITY');
        $riderProfile = $this->makeRider(
            $provider,
            'AVAILABILITY'
        );
        $riderProfile->update([
            'availability_status' => 'offline',
        ]);

        $this
            ->actingAs($riderProfile->user)
            ->get(route('rider.dashboard.pickups'))
            ->assertOk()
            ->assertSee('Off duty')
            ->assertSee('Accept new assignments')
            ->assertSee('data-rider-account-alert', false)
            ->assertSee('data-rider-availability-form', false)
            ->assertSee('data-rider-availability-submit', false);

        $this
            ->from(route('rider.dashboard.pickups'))
            ->actingAs($riderProfile->user)
            ->patch(
                route('rider.availability.update'),
                [
                    'availability_status' => 'available',
                    'verification_status' => 'pending',
                    'logistics_profile_id' => 999999,
                ]
            )
            ->assertRedirect(route('rider.dashboard.pickups'))
            ->assertSessionHas(
                'success',
                'You are now available for new local pickup and delivery assignments.'
            );

        Event::assertDispatched(
            RiderOperationalStatusUpdated::class,
            fn (RiderOperationalStatusUpdated $event): bool => $event->broadcastWith()['rider_user_id']
                === $riderProfile->user_id
                && $event->broadcastWith()['account_status']
                    === AccountStatus::Active->value
                && $event->broadcastWith()['availability_status']
                    === 'available',
        );

        $this->assertDatabaseHas('rider_profiles', [
            'id' => $riderProfile->id,
            'availability_status' => 'available',
            'verification_status' => 'approved',
            'logistics_profile_id' => $provider['profile']->id,
        ]);

        $this
            ->actingAs($provider['user'])
            ->get(route('logistics.pickups.index'))
            ->assertOk()
            ->assertViewHas(
                'availableRiders',
                fn (array $riders): bool => collect($riders)
                    ->contains(
                        fn (array $rider): bool => $rider['id'] === $riderProfile->id
                    )
            );

        $this
            ->actingAs($provider['user'])
            ->get(route('logistics.riders.index'))
            ->assertOk()
            ->assertSee('Availability')
            ->assertSee('Available')
            ->assertViewHas(
                'riders',
                function (array $riders) use (
                    $riderProfile
                ): bool {
                    $rider = collect($riders)->firstWhere(
                        'profile_id',
                        $riderProfile->id
                    );

                    return $rider !== null
                        && $rider['availability'] === 'Available'
                        && $rider['availability_status'] === 'available';
                }
            );

        $this
            ->from(route('rider.dashboard.pickups'))
            ->actingAs($riderProfile->user)
            ->patch(
                route('rider.availability.update'),
                ['availability_status' => 'offline']
            )
            ->assertRedirect(route('rider.dashboard.pickups'));

        $this->assertDatabaseHas('rider_profiles', [
            'id' => $riderProfile->id,
            'availability_status' => 'offline',
        ]);

        $this
            ->actingAs($provider['user'])
            ->get(route('logistics.pickups.index'))
            ->assertOk()
            ->assertViewHas(
                'availableRiders',
                fn (array $riders): bool => collect($riders)
                    ->doesntContain(
                        fn (array $rider): bool => $rider['id'] === $riderProfile->id
                    )
            );

        $this
            ->actingAs($provider['user'])
            ->get(route('logistics.riders.index'))
            ->assertOk()
            ->assertSee('Off duty')
            ->assertViewHas(
                'riders',
                function (array $riders) use (
                    $riderProfile
                ): bool {
                    $rider = collect($riders)->firstWhere(
                        'profile_id',
                        $riderProfile->id
                    );

                    return $rider !== null
                        && $rider['availability'] === 'Off duty';
                }
            );
    }

    public function test_local_rider_attendance_and_assignment_availability_are_independent(): void
    {
        Event::fake([RiderOperationalStatusUpdated::class]);

        $provider = $this->makeProvider('ATTENDANCE-AVAILABILITY');
        $rider = $this->makeRider($provider, 'ATTENDANCE-AVAILABILITY');
        $rider->update(['availability_status' => 'offline']);
        $dashboard = route('rider.dashboard.pickups');

        $this->actingAs($rider->user)
            ->get($dashboard)
            ->assertOk()
            ->assertSee('Accept new assignments')
            ->assertSee('Attendance record')
            ->assertSee('View attendance history');

        $this->from($dashboard)
            ->actingAs($rider->user)
            ->patch(route('rider.availability.update'), [
                'availability_status' => 'available',
            ])
            ->assertRedirect($dashboard)
            ->assertSessionHas(
                'success',
                'You are now available for new local pickup and delivery assignments.',
            );

        $this->assertSame(0, $rider->shifts()->count(), 'Becoming available must not check the Rider in.');

        $this->scanAttendanceQr($rider->user, $provider['user'])->assertOk();

        $this->assertDatabaseHas('rider_profiles', [
            'id' => $rider->id,
            'availability_status' => 'available',
        ]);
        $shift = $rider->shifts()->whereNull('checked_out_at')->sole();

        $this->from($dashboard)
            ->actingAs($rider->user)
            ->patch(route('rider.availability.update'), [
                'availability_status' => 'offline',
            ])
            ->assertRedirect($dashboard)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('rider_shifts', [
            'id' => $shift->id,
            'checked_out_at' => null,
        ]);

        $this->scanAttendanceQr($rider->user, $provider['user'])->assertOk();

        $this->assertDatabaseHas('rider_profiles', [
            'id' => $rider->id,
            'availability_status' => 'offline',
        ]);
        $this->assertNotNull(RiderShift::query()->findOrFail($shift->id)->checked_out_at);
    }

    public function test_local_rider_attendance_history_is_pht_paginated_and_scoped_to_own_shifts(): void
    {
        $provider = $this->makeProvider('ATTENDANCE-HISTORY');
        $rider = $this->makeRider($provider, 'ATTENDANCE-HISTORY');
        $otherRider = $this->makeRider($provider, 'ATTENDANCE-HISTORY-OTHER');
        $firstCheckIn = Carbon::parse('2026-10-01 16:00:00', 'UTC');

        for ($index = 0; $index < 21; $index++) {
            $checkedInAt = $firstCheckIn->copy()->addDays($index);

            $rider->shifts()->create([
                'checked_in_at' => $checkedInAt,
                'checked_out_at' => $index === 20 ? null : $checkedInAt->copy()->addHours(8),
                'check_in_source' => 'rider',
                'check_out_source' => $index === 20 ? null : 'rider',
            ]);
        }

        $otherRider->shifts()->create([
            'checked_in_at' => Carbon::parse('2030-12-31 08:00:00', 'UTC'),
            'checked_out_at' => Carbon::parse('2030-12-31 16:00:00', 'UTC'),
            'check_in_source' => 'rider',
            'check_out_source' => 'rider',
        ]);

        $this->actingAs($rider->user)
            ->get(route('rider.attendance.history'))
            ->assertOk()
            ->assertSee('Philippine Time (PHT / UTC+8)')
            ->assertSee('Showing 1–20 of 21 shifts')
            ->assertSee('Oct 22, 2026')
            ->assertSee('12:00 AM')
            ->assertSee('Still checked in')
            ->assertSee('Open shift')
            ->assertSee('Closed')
            ->assertDontSee('Dec 31, 2030');

        $this->actingAs($rider->user)
            ->get(route('rider.attendance.history', ['page' => 2]))
            ->assertOk()
            ->assertSee('Showing 21–21 of 21 shifts')
            ->assertSee('Oct 2, 2026')
            ->assertDontSee('Oct 22, 2026')
            ->assertDontSee('Still checked in')
            ->assertDontSee('Open shift')
            ->assertDontSee('Dec 31, 2030');
    }

    public function test_courier_dashboard_uses_live_availability_control_hooks(): void
    {
        $provider = $this->makeProvider('COURIER-AVAILABILITY');
        $riderProfile = $this->makeRider($provider, 'COURIER-AVAILABILITY');
        $riderProfile->update([
            'work_type' => 'courier',
            'courier_vehicle_verified_by' => $provider['user']->id,
            'courier_vehicle_verified_at' => now(),
            'linehaul_parcel_capacity' => 20,
            'availability_status' => 'offline',
        ]);

        $this
            ->actingAs($riderProfile->user)
            ->get(route('rider.courier.dashboard'))
            ->assertOk()
            ->assertSee('href="'.route('rider.courier.dashboard').'" class="ops-brand"', false)
            ->assertSee('Courier runs')
            ->assertSee('₱81.25 per hour · max ₱650.00 per workday')
            ->assertSee('Earnings & payouts', false)
            ->assertSee('Open camera to scan QR')
            ->assertSee('Messages')
            ->assertSee('Notifications')
            ->assertSee('Account')
            ->assertSee('Logout')
            ->assertDontSee('Items for Pickup')
            ->assertDontSee('Items for Delivery')
            ->assertDontSee('Share live location')
            ->assertDontSee('data-rider-location-status', false)
            ->assertSee('Off duty')
            ->assertSee('Set available')
            ->assertSee('data-rider-availability-control', false)
            ->assertSee('data-rider-availability-badge', false)
            ->assertSee('data-rider-availability-form', false)
            ->assertSee('data-rider-account-alert', false);

        $this->actingAs($riderProfile->user)
            ->get(route('rider.dashboard'))
            ->assertRedirect(route('rider.courier.dashboard'));
    }

    public function test_couriers_are_redirected_from_local_rider_pages_and_blocked_from_local_actions_and_gps(): void
    {
        $provider = $this->makeProvider('COURIER-LOCAL-GUARD');
        $riderProfile = $this->makeRider($provider, 'COURIER-LOCAL-GUARD');
        $riderProfile->update([
            'work_type' => 'courier',
            'courier_vehicle_verified_by' => $provider['user']->id,
            'courier_vehicle_verified_at' => now(),
            'linehaul_parcel_capacity' => 20,
        ]);

        $courierDashboard = route('rider.courier.dashboard');

        foreach ([
            route('rider.dashboard.pickups'),
            route('rider.attendance.history'),
            route('rider.dashboard.deliveries'),
            route('rider.refund-cash.index'),
            route('rider.orders.pickup', 'UNKNOWN-COURIER-PICKUP'),
            route('rider.orders.delivery', 123),
            route('rider.history.index'),
            route('rider.history.export'),
        ] as $localPage) {
            $this->actingAs($riderProfile->user)
                ->get($localPage)
                ->assertRedirect($courierDashboard);
        }

        foreach ([
            route('rider.orders.pickup.accept', 'UNKNOWN-COURIER-PICKUP'),
            route('rider.orders.delivery.start', 123),
        ] as $localAction) {
            $this->actingAs($riderProfile->user)
                ->post($localAction)
                ->assertRedirect($courierDashboard);
        }

        $this->actingAs($riderProfile->user)
            ->get(route('rider.earnings.index'))
            ->assertOk()
            ->assertSee('Courier ledger')
            ->assertSee('Courier hourly pay');

        $this->actingAs($riderProfile->user)
            ->get(route('rider.earnings.status-version'))
            ->assertOk();

        $this->actingAs($riderProfile->user)
            ->get(route('rider.earnings.export'))
            ->assertOk();

        $this->scanAttendanceQr($riderProfile->user, $provider['user'])->assertOk();

        $this->assertDatabaseHas('rider_shifts', [
            'rider_profile_id' => $riderProfile->id,
            'checked_out_at' => null,
        ]);

        $this->scanAttendanceQr($riderProfile->user, $provider['user'])->assertOk();

        $this->assertDatabaseHas('rider_shifts', [
            'rider_profile_id' => $riderProfile->id,
            'check_out_source' => 'qr',
        ]);
        $this->assertDatabaseMissing('rider_earnings', [
            'rider_profile_id' => $riderProfile->id,
            'type' => 'courier_daily',
        ]);

        $this->actingAs($riderProfile->user)
            ->postJson(route('rider.location.update'), [
                'latitude' => 14.5995,
                'longitude' => 120.9842,
            ])
            ->assertStatus(409);

        $this->assertDatabaseMissing('rider_locations', [
            'rider_profile_id' => $riderProfile->id,
        ]);
    }

    public function test_local_rider_dashboard_still_defaults_to_pickups(): void
    {
        $provider = $this->makeProvider('LOCAL-DASHBOARD-DEFAULT');
        $riderProfile = $this->makeRider($provider, 'LOCAL-DASHBOARD-DEFAULT');

        $this->actingAs($riderProfile->user)
            ->get(route('rider.dashboard'))
            ->assertRedirect(route('rider.dashboard.pickups'));
    }

    public function test_rider_cannot_set_availability_before_profile_approval_or_use_other_states(): void
    {
        $provider = $this->makeProvider('AVAILABILITY-GATE');
        $riderProfile = $this->makeRider(
            $provider,
            'AVAILABILITY-GATE'
        );

        $riderProfile->update([
            'availability_status' => 'offline',
            'verification_status' => 'pending',
        ]);

        $this
            ->actingAs($riderProfile->user)
            ->patch(
                route('rider.availability.update'),
                ['availability_status' => 'available']
            )
            ->assertForbidden();

        $riderProfile->update([
            'verification_status' => 'approved',
        ]);

        $this
            ->actingAs($riderProfile->user)
            ->patchJson(
                route('rider.availability.update'),
                ['availability_status' => 'busy']
            )
            ->assertUnprocessable();

        $this->assertDatabaseHas('rider_profiles', [
            'id' => $riderProfile->id,
            'availability_status' => 'offline',
            'verification_status' => 'approved',
        ]);
    }

    public function test_available_rider_can_publish_location_and_off_duty_clears_it(): void
    {
        Event::fake([RiderLocationUpdated::class]);

        $provider = $this->makeProvider('LOCATION');
        $riderProfile = $this->makeRider($provider, 'LOCATION');

        $this->actingAs($riderProfile->user)
            ->postJson(route('rider.location.update'), [
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'accuracy_meters' => 12.4,
            ])
            ->assertOk()
            ->assertJsonPath('data.latitude', 14.5995)
            ->assertJsonPath('data.longitude', 120.9842)
            ->assertJsonPath('data.accuracy_meters', 12.4)
            ->assertJsonPath('message', 'Live location updated.');

        Event::assertDispatched(
            RiderLocationUpdated::class,
            fn (RiderLocationUpdated $event): bool => $event->logisticsUserId === (int) $provider['user']->id
                && $event->riderUserId === (int) $riderProfile->user_id
                && ! $event->locationCleared
                && $event->updatedAt !== '',
        );

        $this->assertDatabaseHas('rider_locations', [
            'rider_profile_id' => $riderProfile->id,
            'latitude' => 14.5995,
            'longitude' => 120.9842,
        ]);

        $this->actingAs($riderProfile->user)
            ->from(route('rider.dashboard.pickups'))
            ->patch(route('rider.availability.update'), [
                'availability_status' => 'offline',
            ])
            ->assertRedirect(route('rider.dashboard.pickups'));

        $this->assertDatabaseMissing('rider_locations', [
            'rider_profile_id' => $riderProfile->id,
        ]);

        Event::assertDispatched(
            RiderLocationUpdated::class,
            fn (RiderLocationUpdated $event): bool => $event->logisticsUserId === (int) $provider['user']->id
                && $event->riderUserId === (int) $riderProfile->user_id
                && $event->locationCleared,
        );
    }

    public function test_location_updates_require_an_approved_available_rider(): void
    {
        $provider = $this->makeProvider('LOCATION-GATE');
        $riderProfile = $this->makeRider($provider, 'LOCATION-GATE');
        $riderProfile->update(['availability_status' => 'offline']);

        $this->actingAs($riderProfile->user)
            ->postJson(route('rider.location.update'), [
                'latitude' => 14.5995,
                'longitude' => 120.9842,
            ])
            ->assertStatus(409);

        $riderProfile->update([
            'availability_status' => 'available',
            'verification_status' => 'pending',
        ]);

        $this->actingAs($riderProfile->user)
            ->postJson(route('rider.location.update'), [
                'latitude' => 14.5995,
                'longitude' => 120.9842,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('rider_locations', [
            'rider_profile_id' => $riderProfile->id,
        ]);
    }

    private function makeProvider(
        string $suffix
    ): array {
        $user =
            User::factory()->create([
                'name' => "Logistics {$suffix}",

                'email' => 'rider-pickup-logistics-'
                    .strtolower($suffix)
                    .'@example.test',

                'role' => UserRole::Logistics->value,

                'status' => AccountStatus::Active->value,

                'business_name' => "Logistics {$suffix}",
            ]);

        $profile =
            LogisticsProfile::query()
                ->create([
                    'user_id' => $user->id,

                    'legal_name' => "Logistics {$suffix} Inc.",

                    'display_name' => "Logistics {$suffix}",

                    'contact_phone' => '09170000001',

                    'status' => 'active',
                ]);

        return compact(
            'user',
            'profile'
        );
    }

    private function scanAttendanceQr(User $rider, User $logistics): \Illuminate\Testing\TestResponse
    {
        $challengeResponse = $this->actingAs($logistics)
            ->postJson(route('logistics.rider-attendance.challenge'));
        $challengeResponse->assertOk();

        return $this->actingAs($rider)->postJson(route('rider.attendance.scan'), [
            'qr_payload' => $challengeResponse->json('data.challenge'),
        ]);
    }

    private function makeRider(
        array $provider,
        string $suffix
    ): RiderProfile {
        $user =
            User::factory()->create([
                'name' => "Pickup Rider {$suffix}",

                'email' => 'rider-pickup-'
                    .strtolower($suffix)
                    .'@example.test',

                'role' => UserRole::Rider->value,

                'status' => AccountStatus::Active->value,

                'logistics_id' => $provider['user']->id,

                'vehicle_type' => 'Motorcycle',

                'plate_number' => "RP-{$suffix}",
            ]);

        return RiderProfile::query()
            ->create([
                'user_id' => $user->id,

                'logistics_profile_id' => $provider['profile']->id,

                'vehicle_type' => 'Motorcycle',

                'plate_number' => "RP-{$suffix}",

                'availability_status' => 'available',

                'verification_status' => 'approved',
            ]);
    }

    private function makeSeller(
        string $suffix
    ): array {
        $user =
            User::factory()->create([
                'name' => "Pickup Seller {$suffix}",

                'email' => 'rider-pickup-seller-'
                    .strtolower($suffix)
                    .'@example.test',

                'role' => UserRole::Seller->value,

                'status' => AccountStatus::Active->value,

                'contact_number' => '09171111111',
            ]);

        $address =
            Address::query()->create([
                'user_id' => $user->id,

                'label' => 'Pickup',

                'recipient_name' => $user->name,

                'phone' => '09171111111',

                'house_number' => '12',

                'street' => "Pickup {$suffix} Street",

                'barangay' => 'San Rafael',

                'city_municipality' => 'San Pablo City',

                'province' => 'Laguna',

                'postal_code' => '4000',

                'is_default_pickup' => true,
            ]);

        $profile =
            SellerProfile::query()->create([
                'user_id' => $user->id,

                'legal_business_name' => "Pickup Seller {$suffix}",

                'pickup_address_id' => $address->id,

                'approved_at' => now(),
            ]);

        $store =
            Store::query()->create([
                'seller_profile_id' => $profile->id,

                'name' => "Pickup Store {$suffix}",

                'slug' => 'pickup-store-'
                    .strtolower($suffix),

                'contact_phone' => '09171111111',

                'publication_status' => 'published',

                'published_at' => now(),
            ]);

        return compact(
            'user',
            'address',
            'profile',
            'store'
        );
    }

    private function makePickupAssignment(
        array $provider,
        array $seller,
        RiderProfile $rider,
        string $suffix,
        string $assignmentStatus = 'assigned'
    ): array {
        $buyer =
            User::factory()->create([
                'email' => 'rider-pickup-buyer-'
                    .strtolower($suffix)
                    .'@example.test',

                'role' => UserRole::Buyer->value,

                'status' => AccountStatus::Active->value,
            ]);

        $order =
            Order::query()->create([
                'order_no' => "RIDER-PICKUP-ORDER-{$suffix}",

                'buyer_id' => $buyer->id,

                'recipient_name' => $buyer->name,

                'recipient_phone' => '09172222222',

                'address_line' => 'Buyer Street',

                'barangay' => 'San Roque',

                'city_municipality' => 'San Pablo City',

                'province' => 'Laguna',

                'postal_code' => '4000',

                'subtotal_minor' => 10000,

                'total_minor' => 10000,

                'status' => 'processing',

                'payment_status' => 'paid',
            ]);

        $sellerOrder =
            SellerOrder::query()->create([
                'seller_order_no' => "RIDER-PICKUP-SO-{$suffix}",

                'order_id' => $order->id,

                'store_id' => $seller['store']->id,

                'status' => 'ready_for_pickup',

                'subtotal_minor' => 10000,

                'total_minor' => 10000,

                'ready_at' => now(),
            ]);

        $shipment =
            Shipment::query()->create([
                'shipment_no' => "RIDER-PICKUP-SHIP-{$suffix}",

                'seller_order_id' => $sellerOrder->id,

                'logistics_profile_id' => $provider['profile']->id,

                'origin_address_id' => $seller['address']->id,

                'purpose' => 'outbound',

                'status' => ShipmentStatus::PickupAssigned->value,

                'shipping_fee_minor' => 0,

                'cod_amount_minor' => 0,
            ]);

        $waybill =
            Waybill::query()->create([
                'shipment_id' => $shipment->id,

                'waybill_no' => "RIDER-PICKUP-WB-{$suffix}",

                'scan_token' => hash(
                    'sha256',
                    "rider-pickup-scan-{$suffix}"
                ),

                'barcode_value' => "RIDER-PICKUP-BARCODE-{$suffix}",

                'piece_count' => 1,

                'total_weight_kg' => 1.250,

                'status' => 'printed',

                'generated_at' => now(),

                'printed_at' => now(),

                'print_count' => 1,
            ]);

        $parcel =
            Parcel::query()->create([
                'shipment_id' => $shipment->id,

                'waybill_id' => $waybill->id,

                'parcel_no' => "RIDER-PICKUP-PARCEL-{$suffix}",

                'piece_sequence' => 1,

                'weight_kg' => 1.250,

                'size_class' => 'small',

                'status' => 'ready_for_pickup',
            ]);

        $pickup =
            PickupRequest::query()->create([
                'pickup_no' => "RIDER-PICKUP-{$suffix}",

                'store_id' => $seller['store']->id,

                'logistics_profile_id' => $provider['profile']->id,

                'pickup_address_id' => $seller['address']->id,

                'status' => $assignmentStatus === 'completed'
                        ? 'completed'
                        : 'scheduled',

                'requested_date' => today(),

                'window_start' => now()->addHour(),

                'window_end' => now()->addHours(2),

                'seller_instructions' => 'Use the loading bay.',

                'completed_at' => $assignmentStatus === 'completed'
                        ? now()
                        : null,
            ]);

        $pickup
            ->parcels()
            ->attach(
                $parcel->id
            );

        $assignment =
            PickupAssignment::query()
                ->create([
                    'pickup_request_id' => $pickup->id,

                    'rider_profile_id' => $rider->id,

                    'status' => $assignmentStatus,

                    'assigned_by' => $provider['user']->id,

                    'assigned_at' => now(),

                    'notes' => 'Return parcels to the assigned sorting center.',

                    'completed_at' => $assignmentStatus === 'completed'
                            ? now()
                            : null,
                ]);

        return compact(
            'order',
            'sellerOrder',
            'shipment',
            'waybill',
            'parcel',
            'pickup',
            'assignment'
        );
    }
}
