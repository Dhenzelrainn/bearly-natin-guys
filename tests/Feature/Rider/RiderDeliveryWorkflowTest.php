<?php

namespace Tests\Feature\Rider;

use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Models\Address;
use App\Models\Category;
use App\Models\DispatchBatch;
use App\Models\DeliveryAttempt;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Parcel;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RiderProfile;
use App\Models\RiderEarning;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use App\Notifications\WorkflowNotification;
use App\Enums\DeliveryAttemptOutcome;
use App\Services\CodCashCollectionService;
use App\Services\RiderDeliveryService;
use App\Services\ReturnRefundService;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
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

        $own['shipment']->sellerOrder()->firstOrFail()->items()->create([
            'product_name' => 'Rider Delivery Preview Product',
            'variant_name' => 'Black / Standard',
            'sku' => 'RIDER-DELIVERY-PREVIEW-001',
            'unit_price_minor' => 22500,
            'quantity' => 1,
            'discount_minor' => 0,
            'subtotal_minor' => 22500,
            'product_image_disk' => 'public',
            'product_image_path' => 'seller-products/rider-delivery-preview.png',
        ]);

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

            ->assertSee('Products in shipment')

            ->assertSee('Rider Delivery Preview Product')

            ->assertSee('seller-products/rider-delivery-preview.png', false)

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

    public function test_out_for_delivery_detail_prioritizes_handoff_and_collapses_failure_form(): void
    {
        $rider = $this->makeRider('DELIVERY-DETAIL-UI');
        $assignment = $this->makeDispatchAssignment(
            $rider,
            'DELIVERY-DETAIL-UI',
            1,
            20000,
            'Handoff Recipient'
        );

        app(RiderDeliveryService::class)->startDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user
        );

        $this
            ->actingAs($rider->user)
            ->get(
                route(
                    'rider.orders.delivery',
                    $assignment['shipment']->shipment_no
                )
            )
            ->assertOk()
            ->assertSee('Complete delivery')
            ->assertSee('Collect from recipient')
            ->assertSee('₱200.00')
            ->assertSee('Received by')
            ->assertSee('Delivery proof photo')
            ->assertSee('delivery-form', false)
            ->assertSee('class="delivery-failure"', false)
            ->assertSee('Report a failed attempt');
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

    public function test_logistics_monitoring_reflects_failed_attempt_and_retry(): void
    {
        $rider = $this->makeRider(
            'MONITOR-RETRY'
        );

        $assignment =
            $this->makeDispatchAssignment(
                $rider,
                'MONITOR-RETRY',
                1,
                0,
                'Monitoring Retry Recipient'
            );

        $service =
            app(RiderDeliveryService::class);

        $service->startDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user
        );

        $service->failDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user,
            'Recipient unavailable.',
            'First delivery attempt failed.'
        );

        $parcel =
            $assignment['parcels'][0];

        /*
        * Failure creates the first real attempt.
        */
        $this->assertSame(
            1,
            $parcel
                ->deliveryAttempts()
                ->count()
        );

        /*
        * Logistics must immediately see the failed
        * route and the persisted Rider attempt.
        */
        $this
            ->actingAs($this->logistics)
            ->get(
                route(
                    'logistics.dispatch.monitoring'
                )
            )
            ->assertOk()
            ->assertViewHas(
                'deliveries',
                function ($deliveries) use (
                    $assignment
                ): bool {
                    $delivery =
                        $deliveries->firstWhere(
                            'id',
                            $assignment[
                                'batch'
                            ]->batch_no
                        );

                    if ($delivery === null) {
                        return false;
                    }

                    $timelineLabels =
                        collect(
                            $delivery['timeline']
                        )->pluck('label');

                    return $delivery['status']
                            === 'DELIVERY_FAILED'
                        && $delivery['progress']
                            === 100
                        && $timelineLabels->contains(
                            'Delivery attempt #1: Failed'
                        );
                }
            )
            ->assertViewHas(
                'metrics',
                fn (array $metrics) =>
                    $metrics['exceptions'] === 1
                    && $metrics[
                        'out_for_delivery_parcels'
                    ] === 0
                    && $metrics[
                        'out_for_delivery_routes'
                    ] === 0
            );

        /*
        * Retry reopens the delivery, but retry itself
        * must not create delivery attempt #2.
        */
        $service->retryDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user
        );

        $this->assertSame(
            1,
            $parcel
                ->deliveryAttempts()
                ->count()
        );

        /*
        * Logistics must now see the route active again,
        * while retaining attempt #1 in the timeline.
        */
        $this
            ->actingAs($this->logistics)
            ->get(
                route(
                    'logistics.dispatch.monitoring'
                )
            )
            ->assertOk()
            ->assertViewHas(
                'deliveries',
                function ($deliveries) use (
                    $assignment
                ): bool {
                    $delivery =
                        $deliveries->firstWhere(
                            'id',
                            $assignment[
                                'batch'
                            ]->batch_no
                        );

                    if ($delivery === null) {
                        return false;
                    }

                    $timelineLabels =
                        collect(
                            $delivery['timeline']
                        )->pluck('label');

                    return $delivery['status']
                            === 'OUT_FOR_DELIVERY'
                        && $delivery['progress']
                            === 0
                        && $timelineLabels->contains(
                            'Delivery attempt #1: Failed'
                        );
                }
            )
            ->assertViewHas(
                'metrics',
                fn (array $metrics) =>
                    $metrics['exceptions'] === 0
                    && $metrics[
                        'out_for_delivery_parcels'
                    ] === 1
                    && $metrics[
                        'out_for_delivery_routes'
                    ] === 1
            );
    }

    public function test_rider_can_complete_delivery_with_private_photo_proof(): void
    {
        Storage::fake('local');
        Notification::fake();

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

        $completion = $service->completeDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user,
            $this->fakeProofPhoto('proof.png'),
            'Delivery Recipient',
            'Received in good condition.',
            14.0712,
            121.3250
        );
        $shipment = $completion->shipment;

        $this->assertFalse($completion->alreadyCompleted);

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

        $sellerOrder = $assignment['shipment']->fresh()->sellerOrder;
        $buyer = $sellerOrder->order->buyer;
        $sellerUser = $sellerOrder->store->sellerProfile->user;

        Notification::assertSentTo(
            $buyer,
            WorkflowNotification::class,
            fn (WorkflowNotification $notification): bool => $notification->eventKey() === 'buyer_shipment_updates'
                && $notification->title() === 'Order delivered',
        );

        Notification::assertSentTo(
            $sellerUser,
            WorkflowNotification::class,
            fn (WorkflowNotification $notification): bool => $notification->eventKey() === 'seller_shipment_updates',
        );
    }

    public function test_return_delivery_requires_seller_inspection_before_restock(): void
    {
        Storage::fake('local');

        $rider = $this->makeRider('RETURN-DELIVERY');
        $assignment = $this->makeDispatchAssignment(
            $rider,
            'RETURN-DELIVERY',
            1,
            0,
            'Seller Representative'
        );
        $sellerOrder = SellerOrder::query()->findOrFail($assignment['shipment']->seller_order_id);
        $store = Store::query()->with('sellerProfile.user')->findOrFail($sellerOrder->store_id);
        $seller = $store->sellerProfile->user;
        $sellerAddress = Address::query()->create([
            'user_id' => $seller->id,
            'label' => 'Seller Return Address',
            'recipient_name' => $seller->name,
            'phone' => '09170000002',
            'house_number' => '8',
            'street' => 'Seller Return Road',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
        ]);
        $assignment['shipment']->update([
            'purpose' => 'return',
            'destination_address_id' => $sellerAddress->id,
        ]);

        $order = Order::query()->findOrFail($sellerOrder->order_id);
        $returnRequest = ReturnRequest::query()->create([
            'return_no' => 'RR-RIDER-DELIVERY-001',
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
            'buyer_id' => $order->buyer_id,
            'store_id' => $store->id,
            'request_type' => 'return_refund',
            'reason_code' => 'item_arrived_damaged',
            'status' => 'return_in_transit',
            'requested_amount_minor' => 10000,
            'submitted_at' => now(),
        ]);
        $returnRequest->update(['return_shipment_id' => $assignment['shipment']->id]);

        $category = Category::query()->create([
            'name' => 'Return Workflow Test',
            'slug' => 'return-workflow-test',
        ]);
        $product = Product::query()->create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Returned Item',
            'slug' => 'returned-item',
            'product_status' => 'active',
            'compliance_status' => 'passed',
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'RETURN-ITEM-001',
            'name' => 'Standard',
            'price_minor' => 10000,
            'stock_on_hand' => 3,
            'is_active' => true,
        ]);
        $orderItem = OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name' => $product->name,
            'variant_name' => $variant->name,
            'sku' => $variant->sku,
            'unit_price_minor' => 10000,
            'quantity' => 1,
            'discount_minor' => 0,
            'subtotal_minor' => 10000,
        ]);
        $returnItem = ReturnItem::query()->create([
            'return_request_id' => $returnRequest->id,
            'order_item_id' => $orderItem->id,
            'quantity' => 1,
            'refund_amount_minor' => 10000,
        ]);

        $delivery = app(RiderDeliveryService::class);
        $delivery->startDelivery($assignment['shipment']->shipment_no, $rider->user);
        $completion = $delivery->completeDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user,
            $this->fakeProofPhoto('return-delivery.png'),
            'Seller Representative',
            'Returned parcel received by Seller.',
            null,
            null,
            app(ReturnRefundService::class),
        );
        $shipment = $completion->shipment;

        $this->assertFalse($completion->alreadyCompleted);

        $this->assertSame(ShipmentStatus::Delivered->value, $shipment->status);
        $this->assertSame('awaiting_inspection', $returnRequest->fresh()->status);
        $this->assertSame(3, (int) $variant->fresh()->stock_on_hand);
        $this->assertDatabaseHas('return_events', [
            'return_request_id' => $returnRequest->id,
            'event_type' => 'return_delivered_to_seller',
            'from_status' => 'return_in_transit',
            'to_status' => 'awaiting_inspection',
        ]);

        $inspected = app(ReturnRefundService::class)->inspectReturn(
            $seller,
            $returnRequest->id,
            [$returnItem->id => ['condition_code' => 'sellable', 'resolution' => 'restock']],
        );

        $this->assertSame('refund_pending', $inspected->status);
        $this->assertSame(4, (int) $variant->fresh()->stock_on_hand);
        $this->assertDatabaseHas('inventory_movements', [
            'variant_id' => $variant->id,
            'type' => 'return_restock',
            'quantity_delta' => 1,
            'balance_after' => 4,
            'reference_type' => ReturnRequest::class,
            'reference_id' => $returnRequest->id,
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
                        'http-proof.png',
                        6000,
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

    public function test_duplicate_delivery_confirmation_is_idempotent_without_duplicate_records(): void
    {
        Storage::fake('local');

        $rider = $this->makeRider('COMPLETE-ONCE');

        $assignment = $this->makeDispatchAssignment(
            $rider,
            'COMPLETE-ONCE',
            1,
            100000,
            'Complete Once Recipient'
        );

        Payment::query()->create([
            'payment_no' => 'PAY-RIDER-COMPLETE-ONCE',
            'order_id' => $assignment['shipment']->sellerOrder()->value('order_id'),
            'method' => 'cod',
            'amount_minor' => 100000,
            'status' => 'pending',
            'initiated_at' => now(),
        ]);

        $service = app(RiderDeliveryService::class);
        $codCash = app(CodCashCollectionService::class);

        $service->startDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user
        );

        $firstCompletion = $service->completeDelivery(
            shipmentNo: $assignment['shipment']->shipment_no,
            actor: $rider->user,
            proofPhoto: $this->fakeProofPhoto('first-proof.png'),
            recipientName: 'Complete Once Recipient',
            codCollectedAmountMinor: 100000,
            codCash: $codCash,
        );

        $this->assertFalse($firstCompletion->alreadyCompleted);
        $this->assertDatabaseCount('cod_cash_collections', 1);

        $this->assertDatabaseCount('delivery_attempts', 1);
        $this->assertDatabaseCount('delivery_proofs', 1);

        $eventsBeforeRepeat = $assignment['shipment']
            ->events()
            ->count();

        $filesBeforeRepeat = Storage::disk('local')
            ->allFiles('delivery-proofs');

        $this->assertCount(1, $filesBeforeRepeat);

        $duplicateCompletion = $service->completeDelivery(
            shipmentNo: $assignment['shipment']->shipment_no,
            actor: $rider->user,
            proofPhoto: $this->fakeProofPhoto('duplicate-proof.png'),
            recipientName: 'Complete Once Recipient',
            codCollectedAmountMinor: 100000,
            codCash: $codCash,
        );

        $this->assertTrue($duplicateCompletion->alreadyCompleted);
        $this->assertSame(
            ShipmentStatus::Delivered->value,
            $duplicateCompletion->shipment->status
        );

        $this->assertDatabaseCount('delivery_attempts', 1);
        $this->assertDatabaseCount('delivery_proofs', 1);
        $this->assertDatabaseCount('cod_cash_collections', 1);
        $this->assertSame(
            $eventsBeforeRepeat,
            $assignment['shipment']->events()->count()
        );

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

        $this->actingAs($rider->user)
            ->post(
                route(
                    'rider.orders.delivery.confirm',
                    $assignment['shipment']->shipment_no
                ),
                [
                    'recipient_name' => 'Complete Once Recipient',
                    'cod_collected_amount' => '1000.00',
                    'proof_photo' => $this->fakeProofPhoto('duplicate-route-proof.png'),
                ]
            )
            ->assertRedirect(
                route(
                    'rider.orders.delivery',
                    $assignment['shipment']->shipment_no
                )
            )
            ->assertSessionHas(
                'job_status',
                'This delivery was already completed. No extra proof, delivery attempt, COD collection, or tracking event was added.'
            );

        $this->assertDatabaseCount('delivery_attempts', 1);
        $this->assertDatabaseCount('delivery_proofs', 1);
        $this->assertDatabaseCount('cod_cash_collections', 1);
        $this->assertSame(
            $eventsBeforeRepeat,
            $assignment['shipment']->events()->count()
        );
        $this->assertSame(
            $filesBeforeRepeat,
            Storage::disk('local')->allFiles('delivery-proofs')
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

    public function test_rider_can_fail_own_out_for_delivery_assignment(): void
    {
        $rider = $this->makeRider('FAIL');

        $assignment = $this->makeDispatchAssignment(
            $rider,
            'FAIL',
            2,
            0,
            'Failed Recipient'
        );

        $service = app(RiderDeliveryService::class);

        $service->startDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user
        );

        $nextAttemptAt = now()->addDay();

        $shipment = $service->failDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user,
            'Recipient was unavailable.',
            'Called the recipient twice.',
            14.0712,
            121.3250,
            $nextAttemptAt
        );

        $this->assertSame(
            ShipmentStatus::DeliveryFailed->value,
            $shipment->status
        );

        foreach ($assignment['parcels'] as $parcel) {
            $this->assertDatabaseHas('parcels', [
                'id' => $parcel->id,
                'status' => ParcelStatus::Failed->value,
            ]);

            $attempt = $parcel
                ->deliveryAttempts()
                ->latest('id')
                ->firstOrFail();

            $this->assertSame(
                DeliveryAttemptOutcome::Failed->value,
                $attempt->outcome
            );

            $this->assertSame(
                1,
                $attempt->attempt_no
            );

            $this->assertSame(
                'Recipient was unavailable.',
                $attempt->failure_reason
            );

            $this->assertNotNull(
                $attempt->next_attempt_at
            );

            $this->assertDatabaseHas('shipment_events', [
                'shipment_id' => $assignment['shipment']->id,
                'parcel_id' => $parcel->id,
                'event_type' => 'parcel_delivery_failed',
                'from_status' => ParcelStatus::OutForDelivery->value,
                'to_status' => ParcelStatus::Failed->value,
                'actor_user_id' => $rider->user_id,
                'source' => 'rider',
            ]);
        }

        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $assignment['shipment']->id,
            'parcel_id' => null,
            'event_type' => 'shipment_delivery_failed',
            'to_status' => ShipmentStatus::DeliveryFailed->value,
            'actor_user_id' => $rider->user_id,
            'source' => 'rider',
        ]);
    }

    public function test_fail_delivery_route_records_failed_attempt(): void
    {
        $rider = $this->makeRider('FAIL-HTTP');

        $assignment = $this->makeDispatchAssignment(
            $rider,
            'FAIL-HTTP',
            1,
            0,
            'Failure Route Recipient'
        );

        app(RiderDeliveryService::class)->startDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user
        );

        $response = $this
            ->actingAs($rider->user)
            ->post(
                route(
                    'rider.orders.delivery.fail',
                    $assignment['shipment']->shipment_no
                ),
                [
                    'failure_reason' =>
                        'Customer could not be reached.',

                    'notes' =>
                        'Will retry tomorrow.',

                    'next_attempt_at' =>
                        now()
                            ->addDay()
                            ->format('Y-m-d H:i:s'),
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
                'Failed delivery attempt recorded.'
            );

        $this->assertDatabaseHas('parcels', [
            'id' => $assignment['parcels'][0]->id,
            'status' => ParcelStatus::Failed->value,
        ]);

        $this->assertDatabaseHas('delivery_attempts', [
            'parcel_id' => $assignment['parcels'][0]->id,
            'outcome' => DeliveryAttemptOutcome::Failed->value,
            'failure_reason' => 'Customer could not be reached.',
        ]);

        $this->assertDatabaseHas('shipments', [
            'id' => $assignment['shipment']->id,
            'status' => ShipmentStatus::DeliveryFailed->value,
        ]);
    }

    public function test_fail_delivery_requires_failure_reason(): void
    {
        $rider = $this->makeRider('FAIL-VALIDATE');

        $assignment = $this->makeDispatchAssignment(
            $rider,
            'FAIL-VALIDATE',
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
                    'rider.orders.delivery.fail',
                    $assignment['shipment']->shipment_no
                ),
                []
            )
            ->assertSessionHasErrors([
                'failure_reason',
            ]);

        $this->assertDatabaseCount(
            'delivery_attempts',
            0
        );

        $this->assertDatabaseHas('parcels', [
            'id' => $assignment['parcels'][0]->id,
            'status' => ParcelStatus::OutForDelivery->value,
        ]);
    }

    public function test_failed_delivery_cannot_be_failed_again(): void
    {
        $rider = $this->makeRider('FAIL-ONCE');

        $assignment = $this->makeDispatchAssignment(
            $rider,
            'FAIL-ONCE',
            1,
            0,
            'Fail Once Recipient'
        );

        $service = app(RiderDeliveryService::class);

        $service->startDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user
        );

        $service->failDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user,
            'Recipient unavailable.'
        );

        $this->assertDatabaseCount(
            'delivery_attempts',
            1
        );

        try {
            $service->failDelivery(
                $assignment['shipment']->shipment_no,
                $rider->user,
                'Another failure.'
            );

            $this->fail(
                'Expected repeat failure to be rejected.'
            );
        } catch (ConflictHttpException) {
            // Expected.
        }

        $this->assertDatabaseCount(
            'delivery_attempts',
            1
        );

        $this->assertSame(
            1,
            $assignment['shipment']
                ->events()
                ->where(
                    'event_type',
                    'shipment_delivery_failed'
                )
                ->count()
        );
    }

    public function test_split_shipment_becomes_failed_after_remaining_rider_completes(): void
    {
        Storage::fake('local');

        $riderA = $this->makeRider('FAIL-SPLIT-A');
        $riderB = $this->makeRider('FAIL-SPLIT-B');

        $assignment = $this->makeDispatchAssignment(
            $riderA,
            'FAIL-SPLIT',
            2,
            0,
            'Split Failure Recipient'
        );

        $firstParcel = $assignment['parcels'][0];
        $secondParcel = $assignment['parcels'][1];

        $assignment['batch']
            ->parcels()
            ->detach($secondParcel->id);

        $secondBatch = DispatchBatch::query()->create([
            'batch_no' => 'RIDER-BATCH-FAIL-SPLIT-B',
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

        $service->failDelivery(
            $assignment['shipment']->shipment_no,
            $riderA->user,
            'Recipient unavailable.'
        );

        $this->assertDatabaseHas('parcels', [
            'id' => $firstParcel->id,
            'status' => ParcelStatus::Failed->value,
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
            'event_type' => 'shipment_delivery_failed',
        ]);

        $service->completeDelivery(
            $assignment['shipment']->shipment_no,
            $riderB->user,
            $this->fakeProofPhoto(
                'split-failure-proof.png'
            ),
            'Split Failure Recipient'
        );

        $this->assertDatabaseHas('parcels', [
            'id' => $secondParcel->id,
            'status' => ParcelStatus::Delivered->value,
        ]);

        $this->assertDatabaseHas('shipments', [
            'id' => $assignment['shipment']->id,
            'status' => ShipmentStatus::DeliveryFailed->value,
        ]);

        $this->assertSame(
            1,
            $assignment['shipment']
                ->events()
                ->where(
                    'event_type',
                    'shipment_delivery_failed'
                )
                ->count()
        );
    }

    public function test_rider_can_retry_own_failed_delivery(): void
    {
        $rider = $this->makeRider('RETRY');

        $assignment = $this->makeDispatchAssignment(
            $rider,
            'RETRY',
            1,
            0,
            'Retry Recipient'
        );

        $service = app(RiderDeliveryService::class);

        $service->startDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user
        );

        $service->failDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user,
            'Recipient unavailable.'
        );

        $this->assertDatabaseCount(
            'delivery_attempts',
            1
        );

        $shipment = $service->retryDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user
        );

        $this->assertSame(
            ShipmentStatus::OutForDelivery->value,
            $shipment->status
        );

        $this->assertDatabaseHas('parcels', [
            'id' => $assignment['parcels'][0]->id,
            'status' => ParcelStatus::OutForDelivery->value,
        ]);

        /*
        * Retrying is not itself a delivery attempt.
        */
        $this->assertDatabaseCount(
            'delivery_attempts',
            1
        );

        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $assignment['shipment']->id,
            'parcel_id' => $assignment['parcels'][0]->id,
            'event_type' => 'parcel_delivery_retried',
            'from_status' => ParcelStatus::Failed->value,
            'to_status' => ParcelStatus::OutForDelivery->value,
            'actor_user_id' => $rider->user_id,
            'source' => 'rider',
        ]);

        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $assignment['shipment']->id,
            'parcel_id' => null,
            'event_type' => 'shipment_delivery_retried',
            'from_status' => ShipmentStatus::DeliveryFailed->value,
            'to_status' => ShipmentStatus::OutForDelivery->value,
            'actor_user_id' => $rider->user_id,
            'source' => 'rider',
        ]);
    }

    public function test_retry_allows_an_elapsed_schedule_and_the_exact_schedule_boundary(): void
    {
        Notification::fake();
        $now = Carbon::parse('2026-10-30 12:00:00', 'UTC');
        $this->travelTo($now);

        $rider = $this->makeRider('RETRY-BOUNDARY');
        $assignment = $this->makeDispatchAssignment(
            $rider,
            'RETRY-BOUNDARY',
            1,
            0,
            'Retry Boundary Recipient',
        );
        $service = app(RiderDeliveryService::class);

        $service->startDelivery($assignment['shipment']->shipment_no, $rider->user);
        $scheduledAt = now()->addMinute();
        $localScheduledAt = $scheduledAt->copy()->timezone('Asia/Manila');
        $service->failDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user,
            'Recipient unavailable.',
            nextAttemptAt: $localScheduledAt,
        );

        $storedSchedule = $assignment['parcels'][0]
            ->deliveryAttempts()
            ->latest('id')
            ->firstOrFail()
            ->next_attempt_at;
        $this->assertTrue($storedSchedule->copy()->utc()->equalTo($scheduledAt));

        $eligibility = $service->retryEligibility(
            $assignment['shipment']->shipment_no,
            $rider->user,
        );
        $this->assertFalse($eligibility['can_retry']);
        $this->assertSame(
            $scheduledAt->copy()->utc()->format('Y-m-d H:i:s'),
            $eligibility['retry_available_at']->format('Y-m-d H:i:s'),
        );

        $this->travelTo($scheduledAt);
        $this->assertTrue($service->retryEligibility(
            $assignment['shipment']->shipment_no,
            $rider->user,
        )['can_retry']);
        $service->retryDelivery($assignment['shipment']->shipment_no, $rider->user);

        $this->assertSame('out_for_delivery', $assignment['parcels'][0]->fresh()->status);
        $this->assertDatabaseCount('delivery_attempts', 1);
    }

    public function test_retry_allows_an_elapsed_schedule_but_blocks_a_future_schedule_without_changes(): void
    {
        Notification::fake();
        $rider = $this->makeRider('RETRY-SCHEDULE');
        $assignment = $this->makeDispatchAssignment(
            $rider,
            'RETRY-SCHEDULE',
            1,
            0,
            'Retry Schedule Recipient',
        );
        $service = app(RiderDeliveryService::class);
        $service->startDelivery($assignment['shipment']->shipment_no, $rider->user);
        $service->failDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user,
            'Recipient unavailable.',
        );

        DeliveryAttempt::query()->sole()->update([
            'next_attempt_at' => now()->subMinute(),
        ]);
        $this->assertTrue($service->retryEligibility(
            $assignment['shipment']->shipment_no,
            $rider->user,
        )['can_retry']);

        // Re-fail in an isolated second assignment to test a future schedule.
        $futureAssignment = $this->makeDispatchAssignment(
            $rider,
            'RETRY-FUTURE-SCHEDULE',
            1,
            0,
            'Future Retry Recipient',
        );
        $service->startDelivery($futureAssignment['shipment']->shipment_no, $rider->user);
        $futureAt = now()->addHour();
        $service->failDelivery(
            $futureAssignment['shipment']->shipment_no,
            $rider->user,
            'Recipient unavailable.',
            nextAttemptAt: $futureAt,
        );

        $eligibility = $service->retryEligibility(
            $futureAssignment['shipment']->shipment_no,
            $rider->user,
        );
        $this->assertFalse($eligibility['can_retry']);
        $this->assertSame(
            $futureAt->copy()->utc()->format('Y-m-d H:i:s'),
            $eligibility['retry_available_at']->format('Y-m-d H:i:s'),
        );

        try {
            $service->retryDelivery($futureAssignment['shipment']->shipment_no, $rider->user);
            $this->fail('Expected a future retry schedule to block the retry.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('Retry is scheduled for a later time.', $exception->getMessage());
        }

        $this->assertSame('failed', $futureAssignment['parcels'][0]->fresh()->status);
        $this->assertSame('delivery_failed', $futureAssignment['shipment']->fresh()->status);
        $this->assertSame(0, $futureAssignment['shipment']->events()->where('event_type', 'parcel_delivery_retried')->count());
    }

    public function test_retry_blocks_when_any_parcel_in_the_rider_group_is_not_due(): void
    {
        Notification::fake();
        $rider = $this->makeRider('RETRY-MULTI-SCHEDULE');
        $assignment = $this->makeDispatchAssignment(
            $rider,
            'RETRY-MULTI-SCHEDULE',
            2,
            0,
            'Multi Schedule Recipient',
        );
        $service = app(RiderDeliveryService::class);
        $service->startDelivery($assignment['shipment']->shipment_no, $rider->user);
        $service->failDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user,
            'Recipient unavailable.',
        );

        $assignment['parcels'][0]->deliveryAttempts()->latest('id')->firstOrFail()
            ->update(['next_attempt_at' => now()->subMinute()]);
        $futureAt = now()->addMinutes(20);
        $assignment['parcels'][1]->deliveryAttempts()->latest('id')->firstOrFail()
            ->update(['next_attempt_at' => $futureAt]);

        $eligibility = $service->retryEligibility(
            $assignment['shipment']->shipment_no,
            $rider->user,
        );
        $this->assertFalse($eligibility['can_retry']);
        $this->assertSame(
            $futureAt->copy()->utc()->format('Y-m-d H:i:s'),
            $eligibility['retry_available_at']->format('Y-m-d H:i:s'),
        );

        try {
            $service->retryDelivery($assignment['shipment']->shipment_no, $rider->user);
            $this->fail('Expected one future parcel schedule to block the grouped retry.');
        } catch (ConflictHttpException) {
            // Expected; neither parcel should transition.
        }

        $this->assertSame(
            ['failed', 'failed'],
            $assignment['parcels']->map(fn (Parcel $parcel) => $parcel->fresh()->status)->all(),
        );
        $this->assertSame(0, $assignment['shipment']->events()->where('event_type', 'parcel_delivery_retried')->count());
    }

    public function test_retry_rejects_terminal_shipment_and_terminal_parcel_states(): void
    {
        Notification::fake();
        $rider = $this->makeRider('RETRY-TERMINAL');
        $service = app(RiderDeliveryService::class);

        foreach ([
            ['suffix' => 'RETRY-DELIVERED', 'shipment' => ShipmentStatus::Delivered->value, 'parcel' => ParcelStatus::Failed->value],
            ['suffix' => 'RETRY-CANCELLED', 'shipment' => ShipmentStatus::Cancelled->value, 'parcel' => ParcelStatus::Failed->value],
            ['suffix' => 'RETRY-RTS', 'shipment' => ShipmentStatus::ReturnToSender->value, 'parcel' => ParcelStatus::Failed->value],
            ['suffix' => 'RETRY-RETURNED', 'shipment' => ShipmentStatus::DeliveryFailed->value, 'parcel' => ParcelStatus::Returned->value],
            ['suffix' => 'RETRY-LOST', 'shipment' => ShipmentStatus::DeliveryFailed->value, 'parcel' => ParcelStatus::Lost->value],
            ['suffix' => 'RETRY-DAMAGED', 'shipment' => ShipmentStatus::DeliveryFailed->value, 'parcel' => ParcelStatus::Damaged->value],
            ['suffix' => 'RETRY-DELIVERED-PARCEL', 'shipment' => ShipmentStatus::DeliveryFailed->value, 'parcel' => ParcelStatus::Delivered->value],
        ] as $case) {
            $assignment = $this->makeDispatchAssignment(
                $rider,
                $case['suffix'],
                1,
                0,
                'Terminal Retry Recipient',
            );
            $service->startDelivery($assignment['shipment']->shipment_no, $rider->user);
            $service->failDelivery($assignment['shipment']->shipment_no, $rider->user, 'Recipient unavailable.');
            $assignment['shipment']->update(['status' => $case['shipment']]);
            $assignment['parcels'][0]->update(['status' => $case['parcel']]);

            $this->assertFalse($service->retryEligibility(
                $assignment['shipment']->shipment_no,
                $rider->user,
            )['can_retry']);

            try {
                $service->retryDelivery($assignment['shipment']->shipment_no, $rider->user);
                $this->fail("Expected {$case['suffix']} to be rejected.");
            } catch (ConflictHttpException) {
                // Expected; terminal state must remain unchanged.
            }

            $this->assertSame($case['shipment'], $assignment['shipment']->fresh()->status);
            $this->assertSame($case['parcel'], $assignment['parcels'][0]->fresh()->status);
        }
    }

    public function test_shared_retry_service_requires_an_active_logistics_provider_and_owner(): void
    {
        Notification::fake();
        $rider = $this->makeRider('RETRY-PROVIDER-STATUS');
        $assignment = $this->makeDispatchAssignment(
            $rider,
            'RETRY-PROVIDER-STATUS',
            1,
            0,
            'Provider Eligibility Recipient',
        );
        $service = app(RiderDeliveryService::class);
        $service->startDelivery($assignment['shipment']->shipment_no, $rider->user);
        $service->failDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user,
            'Recipient unavailable.',
        );

        $this->logisticsProfile->update(['status' => 'inactive']);
        $this->assertFalse($service->retryEligibility(
            $assignment['shipment']->shipment_no,
            $rider->user,
        )['can_retry']);
        try {
            $service->retryDelivery($assignment['shipment']->shipment_no, $rider->user);
            $this->fail('Expected an inactive Logistics provider to be denied.');
        } catch (AuthorizationException) {
            // Expected.
        }

        $this->logisticsProfile->update(['status' => 'active']);
        $this->logistics->update(['status' => 'suspended']);
        $this->assertFalse($service->retryEligibility(
            $assignment['shipment']->shipment_no,
            $rider->user,
        )['can_retry']);
        try {
            $service->retryDelivery($assignment['shipment']->shipment_no, $rider->user);
            $this->fail('Expected a suspended Logistics owner to be denied.');
        } catch (AuthorizationException) {
            // Expected.
        }

        $this->assertSame('failed', $assignment['parcels'][0]->fresh()->status);
        $this->assertDatabaseCount('delivery_attempts', 1);
        $this->assertSame(0, $assignment['shipment']->events()
            ->where('event_type', 'parcel_delivery_retried')->count());
    }

    public function test_retry_delivery_route_reopens_failed_delivery(): void
    {
        $rider = $this->makeRider('RETRY-HTTP');

        $assignment = $this->makeDispatchAssignment(
            $rider,
            'RETRY-HTTP',
            1,
            0,
            'Retry Route Recipient'
        );

        $service = app(RiderDeliveryService::class);

        $service->startDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user
        );

        $service->failDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user,
            'No one was available.'
        );

        $response = $this
            ->actingAs($rider->user)
            ->post(
                route(
                    'rider.orders.delivery.retry',
                    $assignment['shipment']->shipment_no
                )
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
                'Delivery retry started.'
            );

        $this->assertDatabaseHas('parcels', [
            'id' => $assignment['parcels'][0]->id,
            'status' => ParcelStatus::OutForDelivery->value,
        ]);

        $this->assertDatabaseHas('shipments', [
            'id' => $assignment['shipment']->id,
            'status' => ShipmentStatus::OutForDelivery->value,
        ]);

        $this->assertDatabaseCount(
            'delivery_attempts',
            1
        );
    }

    public function test_web_retry_action_is_hidden_until_the_server_says_it_is_eligible(): void
    {
        Notification::fake();
        $rider = $this->makeRider('RETRY-WEB-SCHEDULE');
        $assignment = $this->makeDispatchAssignment(
            $rider,
            'RETRY-WEB-SCHEDULE',
            1,
            0,
            'Web Retry Schedule Recipient',
        );
        $service = app(RiderDeliveryService::class);
        $service->startDelivery($assignment['shipment']->shipment_no, $rider->user);
        $futureAt = now()->addHour();
        $service->failDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user,
            'Recipient unavailable.',
            nextAttemptAt: $futureAt,
        );

        $this->actingAs($rider->user)
            ->get(route('rider.orders.delivery', $assignment['shipment']->shipment_no))
            ->assertOk()
            ->assertSeeText('Retry is scheduled for a later time.')
            ->assertDontSee(route('rider.orders.delivery.retry', $assignment['shipment']->shipment_no));

        $assignment['parcels'][0]->deliveryAttempts()->latest('id')->firstOrFail()
            ->update(['next_attempt_at' => now()->subMinute()]);

        $this->get(route('rider.orders.delivery', $assignment['shipment']->shipment_no))
            ->assertOk()
            ->assertSeeText('Retry delivery');
    }

    public function test_retry_delivery_cannot_be_repeated_while_out_for_delivery(): void
    {
        $rider = $this->makeRider('RETRY-ONCE');

        $assignment = $this->makeDispatchAssignment(
            $rider,
            'RETRY-ONCE',
            1,
            0,
            'Retry Once Recipient'
        );

        $service = app(RiderDeliveryService::class);

        $service->startDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user
        );

        $service->failDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user,
            'Recipient unavailable.'
        );

        $service->retryDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user
        );

        try {
            $service->retryDelivery(
                $assignment['shipment']->shipment_no,
                $rider->user
            );

            $this->fail(
                'Expected repeated retry to be rejected.'
            );
        } catch (ConflictHttpException) {
            // Expected.
        }

        $this->assertDatabaseCount(
            'delivery_attempts',
            1
        );

        $this->assertSame(
            1,
            $assignment['shipment']
                ->events()
                ->where(
                    'event_type',
                    'shipment_delivery_retried'
                )
                ->count()
        );

        $this->assertSame(
            1,
            $assignment['shipment']
                ->events()
                ->where(
                    'event_type',
                    'parcel_delivery_retried'
                )
                ->count()
        );
    }

    public function test_rider_cannot_retry_another_riders_failed_delivery(): void
    {
        $assignedRider = $this->makeRider(
            'RETRY-OWNER'
        );

        $foreignRider = $this->makeRider(
            'RETRY-FOREIGN'
        );

        $assignment = $this->makeDispatchAssignment(
            $assignedRider,
            'RETRY-FOREIGN',
            1,
            0,
            'Foreign Retry Recipient'
        );

        $service = app(RiderDeliveryService::class);

        $service->startDelivery(
            $assignment['shipment']->shipment_no,
            $assignedRider->user
        );

        $service->failDelivery(
            $assignment['shipment']->shipment_no,
            $assignedRider->user,
            'Recipient unavailable.'
        );

        $this
            ->actingAs($foreignRider->user)
            ->post(
                route(
                    'rider.orders.delivery.retry',
                    $assignment['shipment']->shipment_no
                )
            )
            ->assertForbidden();

        $this->assertDatabaseHas('parcels', [
            'id' => $assignment['parcels'][0]->id,
            'status' => ParcelStatus::Failed->value,
        ]);

        $this->assertDatabaseHas('shipments', [
            'id' => $assignment['shipment']->id,
            'status' => ShipmentStatus::DeliveryFailed->value,
        ]);

        $this->assertDatabaseCount(
            'delivery_attempts',
            1
        );
    }

    public function test_failed_delivery_can_retry_then_complete_on_second_attempt(): void
    {
        Storage::fake('local');

        $rider = $this->makeRider(
            'RETRY-SUCCESS'
        );

        $assignment = $this->makeDispatchAssignment(
            $rider,
            'RETRY-SUCCESS',
            1,
            0,
            'Second Attempt Recipient'
        );

        $service = app(RiderDeliveryService::class);

        /*
        * Attempt #1 starts.
        */
        $service->startDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user
        );

        /*
        * Attempt #1 fails.
        */
        $service->failDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user,
            'Recipient unavailable on first visit.'
        );

        $firstAttempt = $assignment['parcels'][0]
            ->deliveryAttempts()
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(
            1,
            $firstAttempt->attempt_no
        );

        $this->assertSame(
            DeliveryAttemptOutcome::Failed->value,
            $firstAttempt->outcome
        );

        /*
        * Reopen the same delivery.
        * This must NOT create attempt #2 yet.
        */
        $service->retryDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user
        );

        $this->assertDatabaseCount(
            'delivery_attempts',
            1
        );

        /*
        * The second real customer handoff succeeds.
        * This is when attempt #2 should be created.
        */
        $completion = $service->completeDelivery(
            $assignment['shipment']->shipment_no,
            $rider->user,
            $this->fakeProofPhoto(
                'second-attempt-proof.png'
            ),
            'Second Attempt Recipient',
            'Delivered successfully on retry.'
        );
        $shipment = $completion->shipment;

        $this->assertFalse($completion->alreadyCompleted);

        $this->assertSame(
            ShipmentStatus::Delivered->value,
            $shipment->status
        );

        $this->assertDatabaseHas('parcels', [
            'id' => $assignment['parcels'][0]->id,
            'status' => ParcelStatus::Delivered->value,
        ]);

        $attempts = $assignment['parcels'][0]
            ->deliveryAttempts()
            ->orderBy('attempt_no')
            ->get();

        $this->assertCount(
            2,
            $attempts
        );

        $this->assertSame(
            1,
            $attempts[0]->attempt_no
        );

        $this->assertSame(
            DeliveryAttemptOutcome::Failed->value,
            $attempts[0]->outcome
        );

        $this->assertSame(
            2,
            $attempts[1]->attempt_no
        );

        $this->assertSame(
            DeliveryAttemptOutcome::Delivered->value,
            $attempts[1]->outcome
        );

        $this->assertDatabaseHas('delivery_proofs', [
            'delivery_attempt_id' => $attempts[1]->id,
            'type' => 'photo',
            'recipient_name' => 'Second Attempt Recipient',
            'uploaded_by' => $rider->user_id,
        ]);

        $this->assertSame(
            1,
            $assignment['shipment']
                ->events()
                ->where(
                    'event_type',
                    'shipment_delivery_failed'
                )
                ->count()
        );

        $this->assertSame(
            1,
            $assignment['shipment']
                ->events()
                ->where(
                    'event_type',
                    'shipment_delivery_retried'
                )
                ->count()
        );

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

    private function fakeProofPhoto(string $name = 'proof.png', int $sizeKilobytes = 0): UploadedFile
    {
        $contents = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );

        if ($contents === false) {
            throw new \RuntimeException('Unable to create fake proof image.');
        }

        $file = UploadedFile::fake()->createWithContent($name, $contents);
        if ($sizeKilobytes > 0) {
            $file->size($sizeKilobytes);
        }

        return $file;
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

    public function test_delivery_history_is_scoped_filtered_and_shows_recorded_earning(): void
    {
        Storage::fake('local');

        $rider = $this->makeRider('HISTORY-OWNER');
        $otherRider = $this->makeRider('HISTORY-OTHER');

        $assignment = $this->makeDispatchAssignment(
            $rider,
            'HISTORY-OWNER',
            1,
            0,
            'History Owner Recipient'
        );
        $assignment['shipment']->sellerOrder()->firstOrFail()->items()->create([
            'product_name' => 'History Product Preview',
            'variant_name' => 'Red / Medium',
            'sku' => 'HISTORY-PREVIEW-001',
            'unit_price_minor' => 14500,
            'quantity' => 2,
            'discount_minor' => 0,
            'subtotal_minor' => 29000,
            'product_image_disk' => 'public',
            'product_image_path' => 'seller-products/history-preview.png',
        ]);
        $otherAssignment = $this->makeDispatchAssignment(
            $otherRider,
            'HISTORY-OTHER',
            1,
            0,
            'Other Rider Recipient'
        );

        $deliveryService = app(RiderDeliveryService::class);

        foreach ([[$rider, $assignment, 'owner'], [$otherRider, $otherAssignment, 'other']] as [$assignedRider, $delivery, $suffix]) {
            $deliveryService->startDelivery(
                $delivery['shipment']->shipment_no,
                $assignedRider->user
            );

            $deliveryService->completeDelivery(
                $delivery['shipment']->shipment_no,
                $assignedRider->user,
                $this->fakeProofPhoto("history-{$suffix}.png"),
                $delivery['shipment']->sellerOrder()->firstOrFail()->order()->firstOrFail()->recipient_name
            );
        }

        $failedThisMonth = $this->makeDispatchAssignment(
            $rider,
            'HISTORY-FAILED-CURRENT',
            1,
            0,
            'Current Failed Recipient'
        );
        $deliveryService->startDelivery($failedThisMonth['shipment']->shipment_no, $rider->user);
        $deliveryService->failDelivery(
            $failedThisMonth['shipment']->shipment_no,
            $rider->user,
            'Recipient unavailable.'
        );

        $failedLastMonth = $this->makeDispatchAssignment(
            $rider,
            'HISTORY-FAILED-PAST',
            1,
            0,
            'Past Failed Recipient'
        );
        $deliveryService->startDelivery($failedLastMonth['shipment']->shipment_no, $rider->user);
        $deliveryService->failDelivery(
            $failedLastMonth['shipment']->shipment_no,
            $rider->user,
            'Recipient unavailable.'
        );
        $failedLastMonth['parcels']->first()->deliveryAttempts()->update([
            'attempted_at' => now()->subMonth()->startOfMonth()->addDay(),
        ]);

        $otherRiderFailure = $this->makeDispatchAssignment(
            $otherRider,
            'HISTORY-FAILED-OTHER',
            1,
            0,
            'Other Rider Failed Recipient'
        );
        $deliveryService->startDelivery($otherRiderFailure['shipment']->shipment_no, $otherRider->user);
        $deliveryService->failDelivery(
            $otherRiderFailure['shipment']->shipment_no,
            $otherRider->user,
            'Recipient unavailable.'
        );

        $attempt = $assignment['parcels']->first()
            ->deliveryAttempts()
            ->firstOrFail();

        RiderEarning::query()->create([
            'rider_profile_id' => $rider->id,
            'delivery_attempt_id' => $attempt->id,
            'type' => 'delivery',
            'amount_minor' => 7000,
            'status' => 'posted',
            'description' => 'Delivery earning',
            'earned_at' => now(),
            'posted_at' => now(),
        ]);

        $today = now()->toDateString();

        $this->actingAs($rider->user)
            ->get(route('rider.history.index', [
                'search' => $assignment['shipment']->shipment_no,
                'outcome' => 'delivered',
                'date_from' => $today,
                'date_to' => $today,
            ]))
            ->assertOk()
            ->assertSeeText($assignment['shipment']->shipment_no)
            ->assertSeeText('HISTORY-OWNER-1')
            ->assertSeeText('History Owner Recipient')
            ->assertSeeText('History Product Preview')
            ->assertSee('seller-products/history-preview.png', false)
            ->assertSee('data-history-products-list', false)
            ->assertSeeText('Rider Zone MAIN')
            ->assertSeeText('₱70.00')
            ->assertSeeText('Completed this month')
            ->assertSeeText('Failed attempts this month')
            ->assertSeeText('Recorded attempts that may need a retry')
            ->assertSeeText('Start date')
            ->assertSeeText('End date')
            ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['failed_attempts_this_month'] === 1)
            ->assertSee('Parcel Delivered')
            ->assertDontSeeText($otherAssignment['shipment']->shipment_no)
            ->assertDontSeeText('Other Rider Recipient');

        $this->actingAs($rider->user)
            ->get(route('rider.history.index'))
            ->assertOk()
            ->assertSeeText($assignment['shipment']->shipment_no)
            ->assertDontSeeText($otherAssignment['shipment']->shipment_no);

        $export = $this->actingAs($rider->user)
            ->get(route('rider.history.export', [
                'search' => $assignment['shipment']->shipment_no,
            ]));

        $export->assertOk();
        $this->assertStringContainsString(
            $assignment['shipment']->shipment_no,
            $export->streamedContent()
        );
        $this->assertStringContainsString('₱70.00', $export->streamedContent());
    }

    public function test_delivery_history_includes_canceled_batches_and_return_deliveries(): void
    {
        Storage::fake('local');

        $rider = $this->makeRider('HISTORY-OUTCOMES');
        $cancelled = $this->makeDispatchAssignment(
            $rider,
            'HISTORY-CANCELLED',
            1,
            0,
            'Canceled History Recipient'
        );
        $cancelled['batch']->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $returned = $this->makeDispatchAssignment(
            $rider,
            'HISTORY-RETURNED',
            1,
            0,
            'Return History Recipient'
        );
        $returned['shipment']->update(['purpose' => 'return']);

        $deliveryService = app(RiderDeliveryService::class);
        $deliveryService->startDelivery(
            $returned['shipment']->shipment_no,
            $rider->user
        );
        $deliveryService->completeDelivery(
            $returned['shipment']->shipment_no,
            $rider->user,
            $this->fakeProofPhoto('history-return.png'),
            'Seller Receiver'
        );

        $this->actingAs($rider->user)
            ->get(route('rider.history.index', ['outcome' => 'cancelled']))
            ->assertOk()
            ->assertSeeText($cancelled['shipment']->shipment_no)
            ->assertSeeText('Canceled')
            ->assertDontSeeText($returned['shipment']->shipment_no);

        $this->actingAs($rider->user)
            ->get(route('rider.history.index', ['outcome' => 'returned']))
            ->assertOk()
            ->assertSeeText($returned['shipment']->shipment_no)
            ->assertSeeText('Returned')
            ->assertSeeText('Return deliveries')
            ->assertDontSeeText($cancelled['shipment']->shipment_no);
    }

}
