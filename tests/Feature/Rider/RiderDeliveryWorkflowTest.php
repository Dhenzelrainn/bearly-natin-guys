<?php

namespace Tests\Feature\Rider;

use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Models\Address;
use App\Models\DispatchBatch;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\RiderProfile;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use App\Enums\DeliveryAttemptOutcome;
use App\Services\RiderDeliveryService;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class RiderDeliveryWorkflowTest extends TestCase

{

    use RefreshDatabase;

    private User $logistics;

    private LogisticsProfile $logisticsProfile;

    private SortingCenter $center;

    private SortingZone $zone;

    protected function setUp(): void

    {

        parent::setUp();

        [

            $this->logistics,

            $this->logisticsProfile,

            $this->center,

        ] = $this->makeLogisticsProvider();

        $this->zone = $this->makeZone(

            $this->center,

            'MAIN'

        );

    }

    public function test_delivery_attempt_is_recorded_for_each_owned_parcel(): void
    {
        $rider = $this->makeRider(
            'ATTEMPT'
        );

        $assignment =
            $this->makeDispatchAssignment(
                $rider,
                'ATTEMPT',
                2,
                0,
                'Attempt Recipient'
            );

        $service =
            app(
                RiderDeliveryService::class
            );

        $service->startDelivery(
            $assignment[
                'shipment'
            ]->shipment_no,
            $rider->user
        );

        $attempts =
            $service->recordAttempt(
                $assignment[
                    'shipment'
                ]->shipment_no,
                $rider->user,
                DeliveryAttemptOutcome::Delivered,
                notes: 'Recipient was present.',
                latitude: 14.0712,
                longitude: 121.3250
            );

        $this->assertCount(
            2,
            $attempts
        );

        foreach (
            $assignment['parcels']
            as $parcel
        ) {
            $this->assertDatabaseHas(
                'delivery_attempts',
                [
                    'parcel_id' =>
                        $parcel->id,

                    'dispatch_batch_id' =>
                        $assignment[
                            'batch'
                        ]->id,

                    'rider_profile_id' =>
                        $rider->id,

                    'attempt_no' =>
                        1,

                    'outcome' =>
                        DeliveryAttemptOutcome::Delivered
                            ->value,

                    'failure_reason' =>
                        null,
                ]
            );
        }

        /*
        * Phase 6C records history only.
        * Final state transition belongs to 6D.
        */
        foreach (
            $assignment['parcels']
            as $parcel
        ) {
            $this->assertDatabaseHas(
                'parcels',
                [
                    'id' =>
                        $parcel->id,

                    'status' =>
                        ParcelStatus::OutForDelivery
                            ->value,
                ]
            );
        }
    }

    public function test_delivery_attempt_numbers_increment_per_parcel(): void
    {
        $rider = $this->makeRider(
            'NUMBER'
        );

        $assignment =
            $this->makeDispatchAssignment(
                $rider,
                'NUMBER',
                1,
                0,
                'Attempt Number Recipient'
            );

        $service =
            app(
                RiderDeliveryService::class
            );

        $service->startDelivery(
            $assignment[
                'shipment'
            ]->shipment_no,
            $rider->user
        );

        $service->recordAttempt(
            $assignment[
                'shipment'
            ]->shipment_no,
            $rider->user,
            DeliveryAttemptOutcome::Failed,
            failureReason: 'Recipient unavailable'
        );

        $service->recordAttempt(
            $assignment[
                'shipment'
            ]->shipment_no,
            $rider->user,
            DeliveryAttemptOutcome::Failed,
            failureReason: 'Recipient unavailable again'
        );

        $parcel =
            $assignment[
                'parcels'
            ]->first();

        $this->assertDatabaseHas(
            'delivery_attempts',
            [
                'parcel_id' =>
                    $parcel->id,

                'attempt_no' =>
                    1,
            ]
        );

        $this->assertDatabaseHas(
            'delivery_attempts',
            [
                'parcel_id' =>
                    $parcel->id,

                'attempt_no' =>
                    2,
            ]
        );

        $this->assertSame(
            [
                1,
                2,
            ],
            $parcel
                ->deliveryAttempts()
                ->orderBy(
                    'attempt_no'
                )
                ->pluck(
                    'attempt_no'
                )
                ->all()
        );
    }

    public function test_failed_delivery_attempt_requires_failure_reason(): void
    {
        $rider = $this->makeRider(
            'REASON'
        );

        $assignment =
            $this->makeDispatchAssignment(
                $rider,
                'REASON',
                1,
                0,
                'Failure Reason Recipient'
            );

        $service =
            app(
                RiderDeliveryService::class
            );

        $service->startDelivery(
            $assignment[
                'shipment'
            ]->shipment_no,
            $rider->user
        );

        $this->expectException(
            InvalidArgumentException::class
        );

        $service->recordAttempt(
            $assignment[
                'shipment'
            ]->shipment_no,
            $rider->user,
            DeliveryAttemptOutcome::Failed
        );
    }

    public function test_delivery_attempt_requires_out_for_delivery_parcels(): void
    {
        $rider = $this->makeRider(
            'STATE'
        );

        $assignment =
            $this->makeDispatchAssignment(
                $rider,
                'STATE',
                1,
                0,
                'Wrong State Recipient'
            );

        $this->expectException(
            ConflictHttpException::class
        );

        app(
            RiderDeliveryService::class
        )->recordAttempt(
            $assignment[
                'shipment'
            ]->shipment_no,
            $rider->user,
            DeliveryAttemptOutcome::Delivered
        );
    }

    public function test_rider_cannot_record_attempt_for_foreign_assignment(): void
    {
        $riderA = $this->makeRider(
            'ATTEMPT-A'
        );

        $riderB = $this->makeRider(
            'ATTEMPT-B'
        );

        $assignment =
            $this->makeDispatchAssignment(
                $riderB,
                'ATTEMPT-FOREIGN',
                1,
                0,
                'Foreign Attempt Recipient'
            );

        $service =
            app(
                RiderDeliveryService::class
            );

        $service->startDelivery(
            $assignment[
                'shipment'
            ]->shipment_no,
            $riderB->user
        );

        $this->expectException(
            AuthorizationException::class
        );

        $service->recordAttempt(
            $assignment[
                'shipment'
            ]->shipment_no,
            $riderA->user,
            DeliveryAttemptOutcome::Delivered
        );
    }

    public function test_rider_dashboard_shows_only_own_dispatch_assignments(): void

    {

        $riderA = $this->makeRider(

            'A'

        );

        $riderB = $this->makeRider(

            'B'

        );

        $own = $this->makeDispatchAssignment(

            $riderA,

            'OWN',

            2,

            129000,

            'Own Recipient'

        );

        $foreign = $this->makeDispatchAssignment(

            $riderB,

            'FOREIGN',

            1,

            50000,

            'Foreign Recipient'

        );

        $response = $this

            ->actingAs($riderA->user)

            ->get(

                route(

                    'rider.dashboard.deliveries'

                )

            );

        $response

            ->assertOk()

            ->assertSee('Own Recipient')

            ->assertSee(

                $own['shipment']->shipment_no

            )

            ->assertSee(

                $own['waybill']->waybill_no

            )

            ->assertSee(

                $own['batch']->batch_no

            )

            ->assertSee(

                $this->zone->code

            )

            ->assertSee('1,290.00')

            ->assertDontSee('Foreign Recipient')

            ->assertDontSee(

                $foreign['shipment']->shipment_no

            )

            ->assertDontSee(

                $foreign['waybill']->waybill_no

            );

    }

    public function test_multiple_parcels_in_one_shipment_are_one_delivery_stop(): void

    {

        $rider = $this->makeRider(

            'GROUP'

        );

        $assignment =

            $this->makeDispatchAssignment(

                $rider,

                'GROUP',

                3,

                0,

                'Grouped Recipient'

            );

        $response = $this

            ->actingAs($rider->user)

            ->get(

                route(

                    'rider.dashboard.deliveries'

                )

            );

        $response

            ->assertOk()

            ->assertViewHas(

                'deliveries',

                function ($deliveries) use (

                    $assignment

                ) {

                    if ($deliveries->count() !== 1) {

                        return false;

                    }

                    $delivery =

                        $deliveries->first();

                    return

                        $delivery['id']

                            === $assignment[

                                'shipment'

                            ]->shipment_no

                        && $delivery[

                            'parcel_count'

                        ] === 3

                        && $delivery[

                            'parcels'

                        ]->count() === 3;

                }

            );

    }

    public function test_dashboard_uses_real_fulfillment_data(): void

    {

        $rider = $this->makeRider(

            'DATA'

        );

        $assignment =

            $this->makeDispatchAssignment(

                $rider,

                'DATA',

                2,

                87550,

                'Maria Santos'

            );

        $response = $this

            ->actingAs($rider->user)

            ->get(

                route(

                    'rider.dashboard.deliveries'

                )

            );

        $response

            ->assertOk()

            ->assertViewHas(

                'deliveries',

                function ($deliveries) use (

                    $assignment

                ) {

                    $delivery =

                        $deliveries->first();

                    return

                        $delivery !== null

                        && $delivery[

                            'customer'

                        ] === 'Maria Santos'

                        && $delivery[

                            'contact'

                        ] === '09171234567'

                        && str_contains(

                            $delivery[

                                'address'

                            ],

                            '123 Delivery Street'

                        )

                        && $delivery[

                            'waybill'

                        ] === $assignment[

                            'waybill'

                        ]->waybill_no

                        && $delivery[

                            'zone'

                        ] === $this

                            ->zone

                            ->code

                        && $delivery[

                            'batch_no'

                        ] === $assignment[

                            'batch'

                        ]->batch_no

                        && $delivery[

                            'cod_minor'

                        ] === 87550

                        && $delivery[

                            'payment_status'

                        ] === 'unpaid'

                        && $delivery[

                            'status'

                        ] === 'Assigned';

                }

            );

    }

    public function test_rider_can_open_only_own_delivery_detail(): void

    {

        $riderA = $this->makeRider(

            'DETAIL-A'

        );

        $riderB = $this->makeRider(

            'DETAIL-B'

        );

        $own = $this->makeDispatchAssignment(

            $riderA,

            'DETAIL-OWN',

            2,

            32000,

            'Rider A Recipient'

        );

        $foreign = $this->makeDispatchAssignment(

            $riderB,

            'DETAIL-FOREIGN',

            1,

            0,

            'Rider B Recipient'

        );

        $this

            ->actingAs($riderA->user)

            ->get(

                route(

                    'rider.orders.delivery',

                    $own[

                        'shipment'

                    ]->shipment_no

                )

            )

            ->assertOk()

            ->assertSee('Start delivery')

            ->assertSee(
                route(
                    'rider.orders.delivery.start',
                    $own['shipment']->shipment_no
                ),
                false
            )

            ->assertViewHas(

                'job',

                function (

                    array $job

                ) use ($own) {

                    return

                        $job['id']

                            === $own[

                                'shipment'

                            ]->shipment_no

                        && $job['customer']

                            === 'Rider A Recipient'

                        && $job[

                            'parcel_count'

                        ] === 2

                        && $job[

                            'batch_no'

                        ] === $own[

                            'batch'

                        ]->batch_no;

                }

            );

        $this

            ->actingAs($riderA->user)

            ->get(

                route(

                    'rider.orders.delivery',

                    $foreign[

                        'shipment'

                    ]->shipment_no

                )

            )

            ->assertNotFound();

    }

    public function test_dashboard_is_zero_safe_without_assignments(): void

    {

        $rider = $this->makeRider(

            'EMPTY'

        );

        $response = $this

            ->actingAs($rider->user)

            ->get(

                route(

                    'rider.dashboard.deliveries'

                )

            );

        $response

            ->assertOk()

            ->assertSee(

                'No delivery assignments are'

            )

            ->assertViewHas(

                'deliveries',

                fn ($deliveries) =>

                    $deliveries->isEmpty()

            )

            ->assertViewHas(

                'metrics',

                fn (array $metrics) =>

                    $metrics[

                        'assigned_parcels'

                    ] === 0

                    && $metrics[

                        'out_for_delivery'

                    ] === 0

                    && $metrics[

                        'active_stops'

                    ] === 0

                    && $metrics[

                        'cod_minor'

                    ] === 0

            );

    }

    public function test_read_only_delivery_pages_do_not_mutate_fulfillment_state(): void

    {

        $rider = $this->makeRider(

            'READONLY'

        );

        $assignment =

            $this->makeDispatchAssignment(

                $rider,

                'READONLY',

                2,

                10000,

                'Read Only Recipient'

            );

        $this->assertDatabaseCount(

            'delivery_attempts',

            0

        );

        $this

            ->actingAs($rider->user)

            ->get(

                route(

                    'rider.dashboard.deliveries'

                )

            )

            ->assertOk();

        $this

            ->actingAs($rider->user)

            ->get(

                route(

                    'rider.orders.delivery',

                    $assignment[

                        'shipment'

                    ]->shipment_no

                )

            )

            ->assertOk();

        $this->assertDatabaseCount(

            'delivery_attempts',

            0

        );

        $this->assertDatabaseHas(

            'shipments',

            [

                'id' =>

                    $assignment[

                        'shipment'

                    ]->id,

                'status' =>

                    ShipmentStatus::Dispatched

                        ->value,

            ]

        );

        foreach (

            $assignment['parcels']

            as $parcel

        ) {

            $this->assertDatabaseHas(

                'parcels',

                [

                    'id' =>

                        $parcel->id,

                    'status' =>

                        ParcelStatus::Dispatched

                            ->value,

                ]

            );

        }

        $this->assertDatabaseHas(

            'dispatch_batches',

            [

                'id' =>

                    $assignment[

                        'batch'

                    ]->id,

                'status' =>

                    'dispatched',

            ]

        );

    }

    public function test_rider_can_start_own_dispatched_delivery(): void

    {

        $rider = $this->makeRider(

            'START'

        );

        $assignment =

            $this->makeDispatchAssignment(

                $rider,

                'START',

                2,

                45000,

                'Start Delivery Recipient'

            );

        $response = $this

            ->actingAs($rider->user)

            ->post(

                route(

                    'rider.orders.delivery.start',

                    $assignment[

                        'shipment'

                    ]->shipment_no

                )

            );

        $response

            ->assertRedirect(

                route(

                    'rider.orders.delivery',

                    $assignment[

                        'shipment'

                    ]->shipment_no

                )

            )

            ->assertSessionHas(

                'job_status'

            );

        $this->assertDatabaseHas(

            'shipments',

            [

                'id' =>

                    $assignment[

                        'shipment'

                    ]->id,

                'status' =>

                    ShipmentStatus::OutForDelivery

                        ->value,

            ]

        );

        foreach (

            $assignment['parcels']

            as $parcel

        ) {

            $this->assertDatabaseHas(

                'parcels',

                [

                    'id' =>

                        $parcel->id,

                    'status' =>

                        ParcelStatus::OutForDelivery

                            ->value,

                ]

            );

            $this->assertDatabaseHas(

                'shipment_events',

                [

                    'shipment_id' =>

                        $assignment[

                            'shipment'

                        ]->id,

                    'parcel_id' =>

                        $parcel->id,

                    'event_type' =>

                        'parcel_out_for_delivery',

                    'from_status' =>

                        ParcelStatus::Dispatched

                            ->value,

                    'to_status' =>

                        ParcelStatus::OutForDelivery

                            ->value,

                    'actor_user_id' =>

                        $rider->user_id,

                    'source' =>

                        'rider',

                ]

            );

        }

        $this->assertDatabaseHas(

            'shipment_events',

            [

                'shipment_id' =>

                    $assignment[

                        'shipment'

                    ]->id,

                'parcel_id' =>

                    null,

                'event_type' =>

                    'shipment_out_for_delivery',

                'from_status' =>

                    ShipmentStatus::Dispatched

                        ->value,

                'to_status' =>

                    ShipmentStatus::OutForDelivery

                        ->value,

                'actor_user_id' =>

                    $rider->user_id,

                'source' =>

                    'rider',

            ]

        );

        $this->assertDatabaseCount(

            'delivery_attempts',

            0

        );

    }

    public function test_rider_cannot_start_another_riders_delivery(): void

    {

        $riderA = $this->makeRider(

            'FOREIGN-A'

        );

        $riderB = $this->makeRider(

            'FOREIGN-B'

        );

        $assignment =

            $this->makeDispatchAssignment(

                $riderB,

                'FOREIGN-START',

                1,

                0,

                'Foreign Delivery Recipient'

            );

        $this

            ->actingAs($riderA->user)

            ->post(

                route(

                    'rider.orders.delivery.start',

                    $assignment[

                        'shipment'

                    ]->shipment_no

                )

            )

            ->assertForbidden();

        $this->assertDatabaseHas(

            'shipments',

            [

                'id' =>

                    $assignment[

                        'shipment'

                    ]->id,

                'status' =>

                    ShipmentStatus::Dispatched

                        ->value,

            ]

        );

        foreach (

            $assignment['parcels']

            as $parcel

        ) {

            $this->assertDatabaseHas(

                'parcels',

                [

                    'id' =>

                        $parcel->id,

                    'status' =>

                        ParcelStatus::Dispatched

                            ->value,

                ]

            );

        }

        $this->assertDatabaseMissing(

            'shipment_events',

            [

                'shipment_id' =>

                    $assignment[

                        'shipment'

                    ]->id,

                'event_type' =>

                    'parcel_out_for_delivery',

            ]

        );

    }

    public function test_start_delivery_cannot_be_repeated_or_duplicate_events(): void

    {

        $rider = $this->makeRider(

            'REPEAT'

        );

        $assignment =

            $this->makeDispatchAssignment(

                $rider,

                'REPEAT',

                2,

                0,

                'Repeat Recipient'

            );

        $url = route(

            'rider.orders.delivery.start',

            $assignment[

                'shipment'

            ]->shipment_no

        );

        $this

            ->actingAs($rider->user)

            ->post($url)

            ->assertRedirect();

        $parcelEventCount =

            $assignment[

                'shipment'

            ]

                ->events()

                ->where(

                    'event_type',

                    'parcel_out_for_delivery'

                )

                ->count();

        $shipmentEventCount =

            $assignment[

                'shipment'

            ]

                ->events()

                ->where(

                    'event_type',

                    'shipment_out_for_delivery'

                )

                ->count();

        $this->assertSame(

            2,

            $parcelEventCount

        );

        $this->assertSame(

            1,

            $shipmentEventCount

        );

        $this

            ->actingAs($rider->user)

            ->post($url)

            ->assertStatus(409);

        $this->assertSame(

            2,

            $assignment[

                'shipment'

            ]

                ->events()

                ->where(

                    'event_type',

                    'parcel_out_for_delivery'

                )

                ->count()

        );

        $this->assertSame(

            1,

            $assignment[

                'shipment'

            ]

                ->events()

                ->where(

                    'event_type',

                    'shipment_out_for_delivery'

                )

                ->count()

        );

        $this->assertDatabaseCount(

            'delivery_attempts',

            0

        );

    }

    public function test_shipment_waits_until_every_dispatched_parcel_starts_delivery(): void

    {

        $riderA = $this->makeRider(

            'SPLIT-A'

        );

        $riderB = $this->makeRider(

            'SPLIT-B'

        );

        $assignment =

            $this->makeDispatchAssignment(

                $riderA,

                'SPLIT',

                2,

                0,

                'Split Shipment Recipient'

            );

        $firstParcel =

            $assignment[

                'parcels'

            ][0];

        $secondParcel =

            $assignment[

                'parcels'

            ][1];

        $assignment[

            'batch'

        ]

            ->parcels()

            ->detach(

                $secondParcel->id

            );

        $secondBatch =

            DispatchBatch::query()->create([

                'batch_no' =>

                    'RIDER-BATCH-SPLIT-B',

                'sorting_center_id' =>

                    $this->center->id,

                'sorting_zone_id' =>

                    $this->zone->id,

                'rider_profile_id' =>

                    $riderB->id,

                'status' =>

                    'dispatched',

                'prepared_by' =>

                    $this

                        ->logistics

                        ->id,

                'prepared_at' =>

                    now(),

                'assigned_at' =>

                    now(),

                'dispatched_at' =>

                    now(),

            ]);

        $secondBatch

            ->parcels()

            ->attach(

                $secondParcel->id,

                [

                    'sequence' =>

                        1,

                    'loaded_at' =>

                        now(),

                ]

            );

        $this

            ->actingAs($riderA->user)

            ->post(

                route(

                    'rider.orders.delivery.start',

                    $assignment[

                        'shipment'

                    ]->shipment_no

                )

            )

            ->assertRedirect();

        $this->assertDatabaseHas(

            'parcels',

            [

                'id' =>

                    $firstParcel->id,

                'status' =>

                    ParcelStatus::OutForDelivery

                        ->value,

            ]

        );

        $this->assertDatabaseHas(

            'parcels',

            [

                'id' =>

                    $secondParcel->id,

                'status' =>

                    ParcelStatus::Dispatched

                        ->value,

            ]

        );

        $this->assertDatabaseHas(

            'shipments',

            [

                'id' =>

                    $assignment[

                        'shipment'

                    ]->id,

                'status' =>

                    ShipmentStatus::Dispatched

                        ->value,

            ]

        );

        $this->assertDatabaseMissing(

            'shipment_events',

            [

                'shipment_id' =>

                    $assignment[

                        'shipment'

                    ]->id,

                'event_type' =>

                    'shipment_out_for_delivery',

            ]

        );

        $this

            ->actingAs($riderB->user)

            ->post(

                route(

                    'rider.orders.delivery.start',

                    $assignment[

                        'shipment'

                    ]->shipment_no

                )

            )

            ->assertRedirect();

        $this->assertDatabaseHas(

            'parcels',

            [

                'id' =>

                    $secondParcel->id,

                'status' =>

                    ParcelStatus::OutForDelivery

                        ->value,

            ]

        );

        $this->assertDatabaseHas(

            'shipments',

            [

                'id' =>

                    $assignment[

                        'shipment'

                    ]->id,

                'status' =>

                    ShipmentStatus::OutForDelivery

                        ->value,

            ]

        );

        $this->assertDatabaseHas(

            'shipment_events',

            [

                'shipment_id' =>

                    $assignment[

                        'shipment'

                    ]->id,

                'event_type' =>

                    'shipment_out_for_delivery',

                'actor_user_id' =>

                    $riderB->user_id,

                'source' =>

                    'rider',

            ]

        );

        $this->assertDatabaseCount(

            'delivery_attempts',

            0

        );

    }

    public function test_logistics_monitoring_reflects_rider_started_delivery(): void

    {

        $rider = $this->makeRider(

            'MONITOR'

        );

        $assignment =

            $this->makeDispatchAssignment(

                $rider,

                'MONITOR',

                1,

                0,

                'Monitoring Recipient'

            );

        $this

            ->actingAs($rider->user)

            ->post(

                route(

                    'rider.orders.delivery.start',

                    $assignment[

                        'shipment'

                    ]->shipment_no

                )

            )

            ->assertRedirect();

        $response = $this

            ->actingAs(

                $this->logistics

            )

            ->get(

                route(

                    'logistics.dispatch.monitoring'

                )

            );

        $response

            ->assertOk()

            ->assertSee(

                $assignment[

                    'batch'

                ]->batch_no

            )

            ->assertSee(

                'OUT_FOR_DELIVERY'

            );

    }

    public function test_rider_can_complete_delivery_with_private_photo_proof(): void
    {
        Storage::fake('local');

        $rider = $this->makeRider('COMPLETE');

        $assignment = $this->makeDispatchAssignment(
            $rider,
            'COMPLETE',
            2,
            0,
            'Delivery Recipient'
        );

        $service = app(RiderDeliveryService::class);

        $service->startDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user
        );

        $shipment = $service->completeDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user,
            $this->fakeProofPhoto('proof.png'),
            'Delivery Recipient',
            'Received in good condition.',
            14.0712,
            121.3250
        );

        $this->assertSame(
            ShipmentStatus::Delivered->value,
            $shipment->status
        );

        foreach ($assignment['parcels'] as $parcel) {
            $this->assertDatabaseHas('parcels', [
                'id' => $parcel->id,
                'status' => ParcelStatus::Delivered->value,
            ]);

            $attempt = $parcel
                ->deliveryAttempts()
                ->where(
                    'outcome',
                    DeliveryAttemptOutcome::Delivered->value
                )
                ->latest('id')
                ->firstOrFail();

            $this->assertSame(1, $attempt->attempt_no);

            $this->assertDatabaseHas('delivery_proofs', [
                'delivery_attempt_id' => $attempt->id,
                'type' => 'photo',
                'recipient_name' => 'Delivery Recipient',
                'uploaded_by' => $rider->user_id,
            ]);

            $proof = $attempt->proofs()->sole();

            $this->assertNotNull($proof->file_path);

            Storage::disk('local')->assertExists(
                $proof->file_path
            );

            $this->assertDatabaseHas('shipment_events', [
                'shipment_id' => $assignment['shipment']->id,
                'parcel_id' => $parcel->id,
                'event_type' => 'parcel_delivered',
                'from_status' => ParcelStatus::OutForDelivery->value,
                'to_status' => ParcelStatus::Delivered->value,
                'actor_user_id' => $rider->user_id,
                'source' => 'rider',
            ]);
        }

        $this->assertDatabaseHas('shipments', [
            'id' => $assignment['shipment']->id,
            'status' => ShipmentStatus::Delivered->value,
        ]);

        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $assignment['shipment']->id,
            'parcel_id' => null,
            'event_type' => 'shipment_delivered',
            'to_status' => ShipmentStatus::Delivered->value,
            'actor_user_id' => $rider->user_id,
            'source' => 'rider',
        ]);
    }

    public function test_confirm_delivery_route_validates_and_completes_delivery(): void
    {
        Storage::fake('local');

        $rider = $this->makeRider('HTTP-COMPLETE');

        $assignment = $this->makeDispatchAssignment(
            $rider,
            'HTTP-COMPLETE',
            1,
            0,
            'HTTP Recipient'
        );

        app(RiderDeliveryService::class)->startDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user
        );

        $response = $this
            ->actingAs($rider->user)
            ->post(
                route(
                    'rider.orders.delivery.confirm',
                    $assignment['shipment']->shipment_no
                ),
                [
                    'recipient_name' => 'HTTP Recipient',
                    'proof_photo' => $this->fakeProofPhoto(
                        'http-proof.png'
                    ),
                    'notes' => 'Handed to recipient.',
                ]
            );

        $response
            ->assertRedirect(
                route(
                    'rider.orders.delivery',
                    $assignment['shipment']->shipment_no
                )
            )
            ->assertSessionHas(
                'job_status',
                'Delivery completed successfully.'
            );

        $this->assertDatabaseHas('shipments', [
            'id' => $assignment['shipment']->id,
            'status' => ShipmentStatus::Delivered->value,
        ]);

        $this->assertDatabaseCount('delivery_proofs', 1);
    }

    public function test_confirm_delivery_requires_recipient_and_photo(): void
    {
        $rider = $this->makeRider('VALIDATE');

        $assignment = $this->makeDispatchAssignment(
            $rider,
            'VALIDATE',
            1,
            0,
            'Validation Recipient'
        );

        app(RiderDeliveryService::class)->startDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user
        );

        $this
            ->actingAs($rider->user)
            ->post(
                route(
                    'rider.orders.delivery.confirm',
                    $assignment['shipment']->shipment_no
                ),
                []
            )
            ->assertSessionHasErrors([
                'recipient_name',
                'proof_photo',
            ]);

        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertDatabaseCount('delivery_proofs', 0);
    }

    public function test_completed_delivery_cannot_be_completed_again(): void
    {
        Storage::fake('local');

        $rider = $this->makeRider('COMPLETE-ONCE');

        $assignment = $this->makeDispatchAssignment(
            $rider,
            'COMPLETE-ONCE',
            1,
            0,
            'Complete Once Recipient'
        );

        $service = app(RiderDeliveryService::class);

        $service->startDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user
        );

        $service->completeDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user,
            $this->fakeProofPhoto('first-proof.png'),
            'Complete Once Recipient'
        );

        $this->assertDatabaseCount('delivery_attempts', 1);
        $this->assertDatabaseCount('delivery_proofs', 1);

        $filesBeforeRepeat = Storage::disk('local')
            ->allFiles('delivery-proofs');

        $this->assertCount(1, $filesBeforeRepeat);

        try {
            $service->completeDelivery(
                $assignment['shipment']->shipment_no,
                $rider->user,
                $this->fakeProofPhoto('duplicate-proof.png'),
                'Complete Once Recipient'
            );

            $this->fail(
                'Expected repeat completion to be rejected.'
            );
        } catch (ConflictHttpException) {
            // Expected.
        }

        $this->assertDatabaseCount('delivery_attempts', 1);
        $this->assertDatabaseCount('delivery_proofs', 1);

        $this->assertSame(
            1,
            $assignment['shipment']
                ->events()
                ->where(
                    'event_type',
                    'shipment_delivered'
                )
                ->count()
        );

        $filesAfterRepeat = Storage::disk('local')
            ->allFiles('delivery-proofs');

        $this->assertSame(
            $filesBeforeRepeat,
            $filesAfterRepeat
        );
    }

    public function test_shipment_is_delivered_only_after_all_riders_complete_their_parcels(): void
    {
        Storage::fake('local');

        $riderA = $this->makeRider('DELIVER-SPLIT-A');
        $riderB = $this->makeRider('DELIVER-SPLIT-B');

        $assignment = $this->makeDispatchAssignment(
            $riderA,
            'DELIVER-SPLIT',
            2,
            0,
            'Split Delivery Recipient'
        );

        $firstParcel = $assignment['parcels'][0];
        $secondParcel = $assignment['parcels'][1];

        $assignment['batch']
            ->parcels()
            ->detach($secondParcel->id);

        $secondBatch = DispatchBatch::query()->create([
            'batch_no' => 'RIDER-BATCH-DELIVER-SPLIT-B',
            'sorting_center_id' => $this->center->id,
            'sorting_zone_id' => $this->zone->id,
            'rider_profile_id' => $riderB->id,
            'status' => 'dispatched',
            'prepared_by' => $this->logistics->id,
            'prepared_at' => now(),
            'assigned_at' => now(),
            'dispatched_at' => now(),
        ]);

        $secondBatch->parcels()->attach(
            $secondParcel->id,
            [
                'sequence' => 1,
                'loaded_at' => now(),
            ]
        );

        $service = app(RiderDeliveryService::class);

        $service->startDelivery(
            $assignment['shipment']->shipment_no,
            $riderA->user
        );

        $service->startDelivery(
            $assignment['shipment']->shipment_no,
            $riderB->user
        );

        $service->completeDelivery(
            $assignment['shipment']->shipment_no,
            $riderA->user,
            $this->fakeProofPhoto('rider-a-proof.png'),
            'Split Recipient'
        );

        $this->assertDatabaseHas('parcels', [
            'id' => $firstParcel->id,
            'status' => ParcelStatus::Delivered->value,
        ]);

        $this->assertDatabaseHas('parcels', [
            'id' => $secondParcel->id,
            'status' => ParcelStatus::OutForDelivery->value,
        ]);

        $this->assertDatabaseHas('shipments', [
            'id' => $assignment['shipment']->id,
            'status' => ShipmentStatus::OutForDelivery->value,
        ]);

        $this->assertDatabaseMissing('shipment_events', [
            'shipment_id' => $assignment['shipment']->id,
            'event_type' => 'shipment_delivered',
        ]);

        $service->completeDelivery(
            $assignment['shipment']->shipment_no,
            $riderB->user,
            $this->fakeProofPhoto('rider-b-proof.png'),
            'Split Recipient'
        );

        $this->assertDatabaseHas('shipments', [
            'id' => $assignment['shipment']->id,
            'status' => ShipmentStatus::Delivered->value,
        ]);

        $this->assertSame(
            1,
            $assignment['shipment']
                ->events()
                ->where(
                    'event_type',
                    'shipment_delivered'
                )
                ->count()
        );
    }

    private function fakeProofPhoto(string $name = 'proof.png'): UploadedFile
    {
        $contents = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );

        if ($contents === false) {
            throw new \RuntimeException('Unable to create fake proof image.');
        }

        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    private function makeLogisticsProvider(): array

    {

        $user = User::factory()->create([

            'name' =>

                'Rider Test Logistics',

            'first_name' =>

                'Rider',

            'last_name' =>

                'Logistics',

            'email' =>

                'rider-read-logistics@example.test',

            'role' =>

                'logistics',

            'status' =>

                'active',

        ]);

        $profile =

            LogisticsProfile::query()->create([

                'user_id' =>

                    $user->id,

                'legal_name' =>

                    'Rider Test Logistics Inc.',

                'display_name' =>

                    'Rider Test Logistics',

                'contact_phone' =>

                    '09170000001',

                'status' =>

                    'active',

            ]);

        $address =

            Address::query()->create([

                'user_id' =>

                    $user->id,

                'label' =>

                    'Rider Test Center',

                'recipient_name' =>

                    $user->name,

                'phone' =>

                    $profile->contact_phone,

                'street' =>

                    '1 Logistics Road',

                'barangay' =>

                    'San Rafael',

                'city_municipality' =>

                    'San Pablo City',

                'province' =>

                    'Laguna',

                'postal_code' =>

                    '4000',

            ]);

        $center =

            SortingCenter::query()->create([

                'logistics_profile_id' =>

                    $profile->id,

                'address_id' =>

                    $address->id,

                'name' =>

                    'Rider Test Sorting Center',

                'code' =>

                    'RIDER-TEST-CENTER',

                'contact_phone' =>

                    $profile->contact_phone,

                'status' =>

                    'active',

            ]);

        return [

            $user,

            $profile,

            $center,

        ];

    }

    private function makeZone(

        SortingCenter $center,

        string $suffix

    ): SortingZone {

        return SortingZone::query()->create([

            'sorting_center_id' =>

                $center->id,

            'code' =>

                "RIDER-ZONE-{$suffix}",

            'name' =>

                "Rider Zone {$suffix}",

            'status' =>

                'active',

        ]);

    }

    private function makeRider(

        string $suffix

    ): RiderProfile {

        $slug =

            strtolower(

                str_replace(

                    '_',

                    '-',

                    $suffix

                )

            );

        $user = User::factory()->create([

            'name' =>

                "Read Rider {$suffix}",

            'email' =>

                "read-rider-{$slug}@example.test",

            'role' =>

                'rider',

            'status' =>

                'active',

            'logistics_id' =>

                $this->logistics->id,

            'vehicle_type' =>

                'Motorcycle',

            'plate_number' =>

                "READ-{$suffix}",

        ]);

        return RiderProfile::query()->create([

            'user_id' =>

                $user->id,

            'logistics_profile_id' =>

                $this

                    ->logisticsProfile

                    ->id,

            'home_sorting_center_id' =>

                $this->center->id,

            'current_zone_id' =>

                $this->zone->id,

            'vehicle_type' =>

                'Motorcycle',

            'plate_number' =>

                "READ-{$suffix}",

            'parcel_capacity' =>

                20,

            'availability_status' =>

                'available',

            'verification_status' =>

                'approved',

        ]);

    }

    /**
     * @return array{
     *     batch: DispatchBatch,
     *     shipment: Shipment,
     *     waybill: Waybill,
     *     parcels: \Illuminate\Support\Collection<int, Parcel>
     * }
     */
    private function makeDispatchAssignment(

        RiderProfile $rider,

        string $suffix,

        int $parcelCount,

        int $codMinor,

        string $recipientName

    ): array {

        $buyer = User::factory()->create([

            'role' =>

                'buyer',

            'status' =>

                'active',

        ]);

        $seller = User::factory()->create([

            'role' =>

                'seller',

            'status' =>

                'active',

        ]);

        $sellerProfile =

            SellerProfile::query()->create([

                'user_id' =>

                    $seller->id,

                'legal_business_name' =>

                    "Rider Seller {$suffix}",

                'standing_status' =>

                    'good_standing',

            ]);

        $store = Store::query()->create([

            'seller_profile_id' =>

                $sellerProfile->id,

            'name' =>

                "Rider Store {$suffix}",

            'slug' =>

                'rider-store-'

                . strtolower($suffix),

            'publication_status' =>

                'published',

        ]);

        $order = Order::query()->create([

            'order_no' =>

                "RIDER-ORDER-{$suffix}",

            'buyer_id' =>

                $buyer->id,

            'recipient_name' =>

                $recipientName,

            'recipient_phone' =>

                '09171234567',

            'address_line' =>

                '123 Delivery Street',

            'barangay' =>

                'San Rafael',

            'city_municipality' =>

                'San Pablo City',

            'province' =>

                'Laguna',

            'postal_code' =>

                '4000',

            'subtotal_minor' =>

                100000,

            'total_minor' =>

                100000,

            'status' =>

                'processing',

            'payment_status' =>

                $codMinor > 0

                    ? 'unpaid'

                    : 'paid',

        ]);

        $sellerOrder =

            SellerOrder::query()->create([

                'seller_order_no' =>

                    "RIDER-SO-{$suffix}",

                'order_id' =>

                    $order->id,

                'store_id' =>

                    $store->id,

                'status' =>

                    'ready_for_pickup',

                'subtotal_minor' =>

                    100000,

                'total_minor' =>

                    100000,

            ]);

        $shipment =

            Shipment::query()->create([

                'shipment_no' =>

                    "RIDER-SHIP-{$suffix}",

                'seller_order_id' =>

                    $sellerOrder->id,

                'logistics_profile_id' =>

                    $this

                        ->logisticsProfile

                        ->id,

                'status' =>

                    ShipmentStatus::Dispatched

                        ->value,

                'shipping_fee_minor' =>

                    0,

                'cod_amount_minor' =>

                    $codMinor,

            ]);

        $waybill =

            Waybill::query()->create([

                'shipment_id' =>

                    $shipment->id,

                'waybill_no' =>

                    "RIDER-WB-{$suffix}",

                'scan_token' =>

                    hash(

                        'sha256',

                        "rider-scan-{$suffix}"

                    ),

                'barcode_value' =>

                    "RIDER-BARCODE-{$suffix}",

                'piece_count' =>

                    $parcelCount,

                'total_weight_kg' =>

                    1.250 * $parcelCount,

                'status' =>

                    'generated',

                'generated_at' =>

                    now(),

            ]);

        $parcels = collect();

        for (

            $sequence = 1;

            $sequence <= $parcelCount;

            $sequence++

        ) {

            $parcels->push(

                Parcel::query()->create([

                    'shipment_id' =>

                        $shipment->id,

                    'waybill_id' =>

                        $waybill->id,

                    'parcel_no' =>

                        "RIDER-PARCEL-{$suffix}-{$sequence}",

                    'piece_sequence' =>

                        $sequence,

                    'weight_kg' =>

                        1.250,

                    'size_class' =>

                        'small',

                    'status' =>

                        ParcelStatus::Dispatched

                            ->value,

                    'current_sorting_center_id' =>

                        $this->center->id,

                    'current_zone_id' =>

                        $this->zone->id,

                    'last_event_at' =>

                        now(),

                ])

            );

        }

        $batch =

            DispatchBatch::query()->create([

                'batch_no' =>

                    "RIDER-BATCH-{$suffix}",

                'sorting_center_id' =>

                    $this->center->id,

                'sorting_zone_id' =>

                    $this->zone->id,

                'rider_profile_id' =>

                    $rider->id,

                'status' =>

                    'dispatched',

                'prepared_by' =>

                    $this->logistics->id,

                'prepared_at' =>

                    now(),

                'assigned_at' =>

                    now(),

                'dispatched_at' =>

                    now(),

            ]);

        foreach (

            $parcels as $index => $parcel

        ) {

            $batch

                ->parcels()

                ->attach(

                    $parcel->id,

                    [

                        'sequence' =>

                            $index + 1,

                        'loaded_at' =>

                            now(),

                    ]

                );

        }

        return [

            'batch' =>

                $batch,

            'shipment' =>

                $shipment,

            'waybill' =>

                $waybill,

            'parcels' =>

                $parcels,

        ];

    }

}
