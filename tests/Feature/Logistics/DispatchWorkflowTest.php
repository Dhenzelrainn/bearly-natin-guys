<?php

namespace Tests\Feature\Logistics;

use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Models\Address;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryProof;
use App\Models\DispatchBatch;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\RiderProfile;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use App\Services\DispatchService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class DispatchWorkflowTest extends TestCase
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

        [
            $this->logisticsA,
            $this->profileA,
            $this->centerA,
        ] = $this->makeLogisticsProvider('A');

        [
            $this->logisticsB,
            $this->profileB,
            $this->centerB,
        ] = $this->makeLogisticsProvider('B');
    }

    public function test_dashboard_recent_activity_uses_real_owned_fulfillment_events(): void
    {
        $ownZone = $this->makeZone(
            $this->centerA,
            'DASH-ACTIVITY-OWN'
        );

        $foreignZone = $this->makeZone(
            $this->centerB,
            'DASH-ACTIVITY-FOREIGN'
        );

        $own =
            $this->makeReadyShipmentFor(
                $this->profileA,
                $this->centerA,
                $ownZone,
                'DASH-ACTIVITY-OWN'
            );

        ShipmentEvent::query()->create([
            'shipment_id' =>
                $own['shipment']->id,

            'parcel_id' =>
                $own['parcel']->id,

            'event_type' =>
                'received_at_center',

            'from_status' =>
                ParcelStatus::PickedUp->value,

            'to_status' =>
                ParcelStatus::Received->value,

            'sorting_center_id' =>
                $this->centerA->id,

            'sorting_zone_id' =>
                $ownZone->id,

            'actor_user_id' =>
                $this->logisticsA->id,

            'source' =>
                'logistics',

            'notes' =>
                'Owned dashboard activity.',

            'occurred_at' =>
                now(),
        ]);

        $foreign =
            $this->makeReadyShipmentFor(
                $this->profileB,
                $this->centerB,
                $foreignZone,
                'DASH-ACTIVITY-FOREIGN'
            );

        ShipmentEvent::query()->create([
            'shipment_id' =>
                $foreign['shipment']->id,

            'parcel_id' =>
                $foreign['parcel']->id,

            'event_type' =>
                'received_at_center',

            'from_status' =>
                ParcelStatus::PickedUp->value,

            'to_status' =>
                ParcelStatus::Received->value,

            'sorting_center_id' =>
                $this->centerB->id,

            'sorting_zone_id' =>
                $foreignZone->id,

            'actor_user_id' =>
                $this->logisticsB->id,

            'source' =>
                'logistics',

            'notes' =>
                'Foreign dashboard activity.',

            'occurred_at' =>
                now()->subMinute(),
        ]);

        $this
            ->actingAs($this->logisticsA)
            ->get(route('logistics.dashboard'))
            ->assertOk()
            ->assertViewHas(
                'activity',
                function (array $activity): bool {
                    $details = collect(
                        $activity
                    )->pluck('detail');

                    return $details->contains(
                        fn (string $detail) =>
                            str_contains(
                                $detail,
                                'Owned dashboard activity.'
                            )
                    )
                        && ! $details->contains(
                            fn (string $detail) =>
                                str_contains(
                                    $detail,
                                    'Foreign dashboard activity.'
                                )
                        );
                }
            )
            ->assertSee(
                'Parcel received at center'
            )
            ->assertSee(
                'Owned dashboard activity.'
            )
            ->assertDontSee(
                'Foreign dashboard activity.'
            );
    }

    public function test_dashboard_uses_real_owned_sorting_exception_count(): void
    {
        $ownZone = $this->makeZone(
            $this->centerA,
            'DASH-EXCEPTION-OWN'
        );

        $foreignZone = $this->makeZone(
            $this->centerB,
            'DASH-EXCEPTION-FOREIGN'
        );

        /*
        * These two are real sorting exceptions.
        */
        $failed =
            $this->makeReadyShipmentFor(
                $this->profileA,
                $this->centerA,
                $ownZone,
                'DASH-EXCEPTION-FAILED'
            );

        $failed['parcel']->update([
            'status' =>
                ParcelStatus::Failed->value,
        ]);

        $damaged =
            $this->makeReadyShipmentFor(
                $this->profileA,
                $this->centerA,
                $ownZone,
                'DASH-EXCEPTION-DAMAGED'
            );

        $damaged['parcel']->update([
            'status' =>
                ParcelStatus::Damaged->value,
        ]);

        /*
        * Returned is intentionally NOT a sorting
        * exception under the existing rules.
        */
        $returned =
            $this->makeReadyShipmentFor(
                $this->profileA,
                $this->centerA,
                $ownZone,
                'DASH-EXCEPTION-RETURNED'
            );

        $returned['parcel']->update([
            'status' =>
                ParcelStatus::Returned->value,
        ]);

        /*
        * Foreign provider exception must not count.
        */
        $foreign =
            $this->makeReadyShipmentFor(
                $this->profileB,
                $this->centerB,
                $foreignZone,
                'DASH-EXCEPTION-FOREIGN'
            );

        $foreign['parcel']->update([
            'status' =>
                ParcelStatus::Lost->value,
        ]);

        $this
            ->actingAs($this->logisticsA)
            ->get(route('logistics.dashboard'))
            ->assertOk()
            ->assertViewHas(
                'sortingExceptionCount',
                2
            )
            ->assertSee(
                '2'
            )
            ->assertSee(
                'sorting'
            )
            ->assertSee(
                'exceptions'
            )
            ->assertSee(
                'Parcels requiring sorting review'
            );
    }

    public function test_dashboard_uses_real_owned_zone_readiness(): void
    {
        $readyZone = $this->makeZone(
            $this->centerA,
            'DASH-ZONE-READY'
        );

        $emptyZone = $this->makeZone(
            $this->centerA,
            'DASH-ZONE-EMPTY'
        );

        $inactiveZone = $this->makeZone(
            $this->centerA,
            'DASH-ZONE-INACTIVE'
        );

        $inactiveZone->update([
            'status' => 'inactive',
        ]);

        $foreignZone = $this->makeZone(
            $this->centerB,
            'DASH-ZONE-FOREIGN'
        );

        /*
        * One fully ready parcel.
        */
        $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $readyZone,
            'DASH-ZONE-READY-1'
        );

        /*
        * One parcel is already sorted into the zone,
        * but its shipment is not fully sorted yet.
        */
        $staged = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $readyZone,
            'DASH-ZONE-STAGED'
        );

        $staged['shipment']->update([
            'status' =>
                ShipmentStatus::AtSortingCenter->value,
        ]);

        /*
        * Only one of these two riders is currently
        * available.
        */
        $this->makeRider(
            $this->profileA,
            $this->centerA,
            $readyZone,
            'DASH-ZONE-AVAILABLE',
            10
        );

        $offlineRider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $readyZone,
            'DASH-ZONE-OFFLINE',
            10
        );

        $offlineRider->update([
            'availability_status' =>
                'offline',
        ]);

        /*
        * Empty zone still has one available Rider.
        * This also verifies zero-safe readiness.
        */
        $this->makeRider(
            $this->profileA,
            $this->centerA,
            $emptyZone,
            'DASH-ZONE-EMPTY',
            10
        );

        /*
        * Foreign provider data must never appear.
        */
        $this->makeReadyShipmentFor(
            $this->profileB,
            $this->centerB,
            $foreignZone,
            'DASH-ZONE-FOREIGN'
        );

        $this->makeRider(
            $this->profileB,
            $this->centerB,
            $foreignZone,
            'DASH-ZONE-FOREIGN',
            10
        );

        $this
            ->actingAs($this->logisticsA)
            ->get(route('logistics.dashboard'))
            ->assertOk()
            ->assertViewHas(
                'zones',
                function ($zones) use (
                    $readyZone,
                    $emptyZone,
                    $inactiveZone,
                    $foreignZone
                ): bool {
                    $rows = collect($zones)
                        ->keyBy('id');

                    if (
                        ! $rows->has($readyZone->id)
                        || ! $rows->has($emptyZone->id)
                        || $rows->has($inactiveZone->id)
                        || $rows->has($foreignZone->id)
                    ) {
                        return false;
                    }

                    $ready =
                        $rows[$readyZone->id];

                    $empty =
                        $rows[$emptyZone->id];

                    return $ready['parcels'] === 2
                        && $ready['ready'] === 1
                        && $ready['riders'] === 1
                        && $empty['parcels'] === 0
                        && $empty['ready'] === 0
                        && $empty['riders'] === 1;
                }
            )
            ->assertSee(
                'Dispatch Zone DASH-ZONE-READY'
            )
            ->assertSee(
                'Dispatch Zone DASH-ZONE-EMPTY'
            )
            ->assertDontSee(
                'Dispatch Zone DASH-ZONE-INACTIVE'
            )
            ->assertDontSee(
                'Dispatch Zone DASH-ZONE-FOREIGN'
            );
    }

    public function test_dashboard_uses_real_owned_fulfillment_metrics(): void
    {
        $receivedZone = $this->makeZone(
            $this->centerA,
            'DASH-RECEIVED'
        );

        $readyZone = $this->makeZone(
            $this->centerA,
            'DASH-READY'
        );

        $dispatchZone = $this->makeZone(
            $this->centerA,
            'DASH-DISPATCH'
        );

        $foreignReceivedZone = $this->makeZone(
            $this->centerB,
            'DASH-FOREIGN-RECEIVED'
        );

        $foreignDispatchZone = $this->makeZone(
            $this->centerB,
            'DASH-FOREIGN-DISPATCH'
        );

        /*
        * One owned parcel received today.
        */
        $received = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $receivedZone,
            'DASH-RECEIVED'
        );

        $received['shipment']->update([
            'status' =>
                ShipmentStatus::AtSortingCenter->value,
        ]);

        $received['parcel']->update([
            'status' =>
                ParcelStatus::Received->value,

            'last_event_at' =>
                now(),
        ]);

        ShipmentEvent::query()->create([
            'shipment_id' =>
                $received['shipment']->id,

            'parcel_id' =>
                $received['parcel']->id,

            'event_type' =>
                'received_at_center',

            'from_status' =>
                ParcelStatus::PickedUp->value,

            'to_status' =>
                ParcelStatus::Received->value,

            'sorting_center_id' =>
                $this->centerA->id,

            'sorting_zone_id' =>
                $receivedZone->id,

            'actor_user_id' =>
                $this->logisticsA->id,

            'source' =>
                'logistics',

            'occurred_at' =>
                now(),
        ]);

        /*
        * One owned parcel sorted and ready.
        */
        $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $readyZone,
            'DASH-READY'
        );

        /*
        * One owned dispatched shipment / active route.
        */
        $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $dispatchZone,
            'DASH-DISPATCH'
        );

        $rider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $dispatchZone,
            'DASH-DISPATCH',
            10
        );

        app(DispatchService::class)
            ->dispatchZone(
                $dispatchZone,
                $rider,
                $this->logisticsA
            );

        /*
        * Foreign received parcel must not affect
        * this provider's dashboard.
        */
        $foreignReceived =
            $this->makeReadyShipmentFor(
                $this->profileB,
                $this->centerB,
                $foreignReceivedZone,
                'DASH-FOREIGN-RECEIVED'
            );

        $foreignReceived['shipment']->update([
            'status' =>
                ShipmentStatus::AtSortingCenter->value,
        ]);

        $foreignReceived['parcel']->update([
            'status' =>
                ParcelStatus::Received->value,

            'last_event_at' =>
                now(),
        ]);

        ShipmentEvent::query()->create([
            'shipment_id' =>
                $foreignReceived['shipment']->id,

            'parcel_id' =>
                $foreignReceived['parcel']->id,

            'event_type' =>
                'received_at_center',

            'from_status' =>
                ParcelStatus::PickedUp->value,

            'to_status' =>
                ParcelStatus::Received->value,

            'sorting_center_id' =>
                $this->centerB->id,

            'sorting_zone_id' =>
                $foreignReceivedZone->id,

            'actor_user_id' =>
                $this->logisticsB->id,

            'source' =>
                'logistics',

            'occurred_at' =>
                now(),
        ]);

        /*
        * Foreign dispatched shipment must also
        * remain excluded.
        */
        $this->makeReadyShipmentFor(
            $this->profileB,
            $this->centerB,
            $foreignDispatchZone,
            'DASH-FOREIGN-DISPATCH'
        );

        $foreignRider = $this->makeRider(
            $this->profileB,
            $this->centerB,
            $foreignDispatchZone,
            'DASH-FOREIGN-DISPATCH',
            10
        );

        app(DispatchService::class)
            ->dispatchZone(
                $foreignDispatchZone,
                $foreignRider,
                $this->logisticsB
            );

        $this
            ->actingAs($this->logisticsA)
            ->get(route('logistics.dashboard'))
            ->assertOk()
            ->assertViewHas(
                'metrics',
                function (array $metrics): bool {
                    $rows = collect($metrics)
                        ->keyBy('label');

                    return $rows[
                        'Incoming Parcels'
                    ]['value'] === 1

                        && $rows[
                            'Incoming Parcels'
                        ]['trend']
                            === '1 awaiting sorting'

                        && $rows[
                            'Active Sorting Queue'
                        ]['value'] === 1

                        && $rows[
                            'Active Sorting Queue'
                        ]['trend']
                            === '1 sorted & ready'

                        && $rows[
                            'Dispatched Shipments'
                        ]['value'] === 1

                        && $rows[
                            'Dispatched Shipments'
                        ]['trend']
                            === '1 active route';
                }
            );
    }

    public function test_dashboard_defaults_to_today_in_manila(): void
    {
        $zone = $this->makeZone(
            $this->centerA,
            'DASH-TODAY'
        );

        $today = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $zone,
            'DASH-TODAY'
        );

        $yesterday = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $zone,
            'DASH-YESTERDAY'
        );

        $this->recordDashboardReceivedEvent(
            $today,
            $this->centerA,
            $zone,
            $this->logisticsA,
            '2026-10-04 00:00:00',
            'Dashboard Manila start boundary.'
        );

        $this->recordDashboardReceivedEvent(
            $yesterday,
            $this->centerA,
            $zone,
            $this->logisticsA,
            '2026-10-03 23:59:59',
            'Dashboard outside yesterday.'
        );

        Carbon::setTestNow(
            Carbon::create(
                2026,
                10,
                4,
                12,
                0,
                0,
                'Asia/Manila'
            )
        );

        try {
            $this
                ->actingAs($this->logisticsA)
                ->get(route('logistics.dashboard'))
                ->assertOk()
                ->assertViewHas(
                    'dashboardRange',
                    [
                        'from' => '2026-10-04',
                        'to' => '2026-10-04',
                    ]
                )
                ->assertViewHas(
                    'metrics',
                    function (array $metrics): bool {
                        return collect($metrics)
                            ->keyBy('label')
                            ->get('Incoming Parcels')['value'] === 1;
                    }
                )
                ->assertSee(
                    'value="2026-10-04"',
                    false
                );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dashboard_uses_inclusive_manila_date_bounds_for_incoming_and_activity(): void
    {
        $zone = $this->makeZone(
            $this->centerA,
            'DASH-RANGE'
        );

        $start = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $zone,
            'DASH-RANGE-START'
        );

        $end = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $zone,
            'DASH-RANGE-END'
        );

        $before = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $zone,
            'DASH-RANGE-BEFORE'
        );

        $after = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $zone,
            'DASH-RANGE-AFTER'
        );

        $this->recordDashboardReceivedEvent(
            $start,
            $this->centerA,
            $zone,
            $this->logisticsA,
            '2026-10-01 00:00:00',
            'Dashboard selected start boundary.'
        );

        $this->recordDashboardReceivedEvent(
            $end,
            $this->centerA,
            $zone,
            $this->logisticsA,
            '2026-10-03 23:59:59',
            'Dashboard selected end boundary.'
        );

        $this->recordDashboardReceivedEvent(
            $before,
            $this->centerA,
            $zone,
            $this->logisticsA,
            '2026-09-30 23:59:59',
            'Dashboard before selected range.'
        );

        $this->recordDashboardReceivedEvent(
            $after,
            $this->centerA,
            $zone,
            $this->logisticsA,
            '2026-10-04 00:00:00',
            'Dashboard after selected range.'
        );

        $this
            ->actingAs($this->logisticsA)
            ->get(
                route(
                    'logistics.dashboard',
                    [
                        'from' => '2026-10-01',
                        'to' => '2026-10-03',
                    ]
                )
            )
            ->assertOk()
            ->assertViewHas(
                'dashboardRange',
                [
                    'from' => '2026-10-01',
                    'to' => '2026-10-03',
                ]
            )
            ->assertViewHas(
                'metrics',
                function (array $metrics): bool {
                    return collect($metrics)
                        ->keyBy('label')
                        ->get('Incoming Parcels')['value'] === 2;
                }
            )
            ->assertSee(
                'Dashboard selected start boundary.'
            )
            ->assertSee(
                'Dashboard selected end boundary.'
            )
            ->assertDontSee(
                'Dashboard before selected range.'
            )
            ->assertDontSee(
                'Dashboard after selected range.'
            )
            ->assertSee(
                'value="2026-10-01"',
                false
            )
            ->assertSee(
                'value="2026-10-03"',
                false
            );
    }

    public function test_dashboard_rejects_invalid_and_reversed_date_ranges(): void
    {
        $invalid = $this
            ->actingAs($this->logisticsA)
            ->get(
                route(
                    'logistics.dashboard',
                    [
                        'from' => '2026-02-30',
                        'to' => '2026-03-01',
                    ]
                )
            );

        $invalid
            ->assertRedirect()
            ->assertSessionHasErrors('from');

        $reversed = $this
            ->actingAs($this->logisticsA)
            ->get(
                route(
                    'logistics.dashboard',
                    [
                        'from' => '2026-10-04',
                        'to' => '2026-10-03',
                    ]
                )
            );

        $reversed
            ->assertRedirect(route('logistics.dashboard'))
            ->assertSessionHasErrors('to');
    }

    public function test_dashboard_date_metrics_ignore_query_ownership_ids_and_other_facilities(): void
    {
        $ownZone = $this->makeZone(
            $this->centerA,
            'DASH-SCOPE-OWN'
        );

        $foreignZone = $this->makeZone(
            $this->centerB,
            'DASH-SCOPE-FOREIGN'
        );

        $own = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $ownZone,
            'DASH-SCOPE-OWN'
        );

        $foreign = $this->makeReadyShipmentFor(
            $this->profileB,
            $this->centerB,
            $foreignZone,
            'DASH-SCOPE-FOREIGN'
        );

        $this->recordDashboardReceivedEvent(
            $own,
            $this->centerA,
            $ownZone,
            $this->logisticsA,
            '2026-10-02 10:00:00',
            'Dashboard authenticated facility activity.'
        );

        $this->recordDashboardReceivedEvent(
            $foreign,
            $this->centerB,
            $foreignZone,
            $this->logisticsB,
            '2026-10-02 12:00:00',
            'Dashboard foreign provider activity.'
        );

        $this
            ->actingAs($this->logisticsA)
            ->get(
                route(
                    'logistics.dashboard',
                    [
                        'from' => '2026-10-02',
                        'to' => '2026-10-02',
                        'logistics_profile_id' => $this->profileB->id,
                        'sorting_center_id' => $this->centerB->id,
                    ]
                )
            )
            ->assertOk()
            ->assertViewHas(
                'metrics',
                function (array $metrics): bool {
                    return collect($metrics)
                        ->keyBy('label')
                        ->get('Incoming Parcels')['value'] === 1;
                }
            )
            ->assertSee(
                'Dashboard authenticated facility activity.'
            )
            ->assertDontSee(
                'Dashboard foreign provider activity.'
            );
    }

    public function test_monitoring_page_shows_only_owned_dispatch_batches(): void
    {
        $ownZone = $this->makeZone(
            $this->centerA,
            'MONITOR-OWN'
        );

        $foreignZone = $this->makeZone(
            $this->centerB,
            'MONITOR-FOREIGN'
        );

        $ownRider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $ownZone,
            'MONITOR-OWN',
            10
        );

        $foreignRider = $this->makeRider(
            $this->profileB,
            $this->centerB,
            $foreignZone,
            'MONITOR-FOREIGN',
            10
        );

        $own = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $ownZone,
            'MONITOR-OWN'
        );
        $own['shipment']->sellerOrder->items()->create([
            'product_name' => 'Monitoring Order Product',
            'variant_name' => 'Black / Medium',
            'sku' => 'MONITOR-PRODUCT-001',
            'unit_price_minor' => 10000,
            'quantity' => 1,
            'discount_minor' => 0,
            'subtotal_minor' => 10000,
            'product_image_disk' => 'public',
            'product_image_path' => 'seller-products/monitor-order.png',
        ]);

        $this->makeReadyShipmentFor(
            $this->profileB,
            $this->centerB,
            $foreignZone,
            'MONITOR-FOREIGN'
        );

        $ownBatch = app(DispatchService::class)
            ->dispatchZone(
                $ownZone,
                $ownRider,
                $this->logisticsA
            );

        $foreignBatch = app(DispatchService::class)
            ->dispatchZone(
                $foreignZone,
                $foreignRider,
                $this->logisticsB
            );

        $this->actingAs($this->logisticsA)
            ->get(
                route('logistics.dispatch.monitoring')
            )
            ->assertOk()
            ->assertSee($ownBatch->batch_no)
            ->assertSee('dispatch-products-cell', false)
            ->assertSee('Monitoring Order Product')
            ->assertSee('seller-products/monitor-order.png', false)
            ->assertSee($ownRider->user->name)
            ->assertSee($ownZone->code)
            ->assertDontSee($foreignBatch->batch_no)
            ->assertDontSee($foreignRider->user->name)
            ->assertDontSee($foreignZone->code)
            ->assertDontSee('DL-8412')
            ->assertDontSee('Nico Flores')
            ->assertDontSee(
                'data-set-status',
                false
            )
            ->assertDontSee('Reassign delivery');
    }

    public function test_delivery_proofs_are_private_to_admin_and_the_owning_logistics_provider(): void
    {
        Storage::fake('local');

        $ownZone = $this->makeZone($this->centerA, 'PROOF-OWN');
        $foreignZone = $this->makeZone($this->centerB, 'PROOF-FOREIGN');
        $ownRider = $this->makeRider($this->profileA, $this->centerA, $ownZone, 'PROOF-OWN', 10);
        $foreignRider = $this->makeRider($this->profileB, $this->centerB, $foreignZone, 'PROOF-FOREIGN', 10);
        $own = $this->makeReadyShipmentFor($this->profileA, $this->centerA, $ownZone, 'PROOF-OWN', 2);
        $foreign = $this->makeReadyShipmentFor($this->profileB, $this->centerB, $foreignZone, 'PROOF-FOREIGN');
        $ownBatch = app(DispatchService::class)->dispatchZone($ownZone, $ownRider, $this->logisticsA);
        $foreignBatch = app(DispatchService::class)->dispatchZone($foreignZone, $foreignRider, $this->logisticsB);

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/Kj0AAAAASUVORK5CYII='
        );
        $ownFilePath = 'delivery-proofs/own-delivery-proof.png';
        $foreignFilePath = 'delivery-proofs/foreign-delivery-proof.png';
        Storage::disk('local')->put($ownFilePath, $png);
        Storage::disk('local')->put($foreignFilePath, $png);

        $ownProofs = collect();
        foreach ($own['parcels'] as $index => $parcel) {
            $attempt = DeliveryAttempt::query()->create([
                'parcel_id' => $parcel->id,
                'dispatch_batch_id' => $ownBatch->id,
                'rider_profile_id' => $ownRider->id,
                'attempt_no' => 1,
                'outcome' => 'delivered',
                'attempted_at' => now(),
            ]);

            $ownProofs->push(DeliveryProof::query()->create([
                'delivery_attempt_id' => $attempt->id,
                'type' => 'photo',
                'file_path' => $ownFilePath,
                'recipient_name' => 'Own Proof Recipient',
                'captured_at' => $index === 0 ? now()->subMinute() : now(),
                'uploaded_by' => $ownRider->user_id,
            ]));
        }

        $foreignAttempt = DeliveryAttempt::query()->create([
            'parcel_id' => $foreign['parcel']->id,
            'dispatch_batch_id' => $foreignBatch->id,
            'rider_profile_id' => $foreignRider->id,
            'attempt_no' => 1,
            'outcome' => 'delivered',
            'attempted_at' => now(),
        ]);
        $foreignProof = DeliveryProof::query()->create([
            'delivery_attempt_id' => $foreignAttempt->id,
            'type' => 'photo',
            'file_path' => $foreignFilePath,
            'recipient_name' => 'Foreign Proof Recipient',
            'captured_at' => now(),
            'uploaded_by' => $foreignRider->user_id,
        ]);

        $logisticsProofUrl = route(
            'logistics.dispatch.monitoring.delivery-proofs.show',
            $ownProofs->last(),
        );

        $this->actingAs($this->logisticsA)
            ->get(route('logistics.dispatch.monitoring'))
            ->assertOk()
            ->assertSee($logisticsProofUrl, false)
            ->assertDontSee(route(
                'logistics.dispatch.monitoring.delivery-proofs.show',
                $foreignProof,
            ), false);

        $this->actingAs($this->logisticsA)
            ->get($logisticsProofUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delivery.proof.previewed',
            'actor_user_id' => $this->logisticsA->id,
            'auditable_id' => $ownProofs->last()->id,
        ]);

        $this->actingAs($this->logisticsB)
            ->get($logisticsProofUrl)
            ->assertNotFound();

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $this->actingAs($buyer)
            ->get($logisticsProofUrl)
            ->assertForbidden();

        $this->actingAs($buyer)
            ->get(route(
                'admin.fulfillment.delivery-proofs.show',
                $ownProofs->last(),
            ))
            ->assertForbidden();

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        $adminProofUrl = route(
            'admin.fulfillment.delivery-proofs.show',
            $ownProofs->last(),
        );

        $adminFulfillment = $this->actingAs($admin)
            ->get(route('admin.fulfillment'))
            ->assertOk()
            ->assertSee($adminProofUrl, false)
            ->assertSee(route(
                'admin.fulfillment.delivery-proofs.show',
                $foreignProof,
            ), false);

        $this->assertSame(
            1,
            substr_count($adminFulfillment->getContent(), $adminProofUrl),
            'The Admin ledger should link one shared upload only once, even when it is attached to multiple parcel attempts.',
        );

        $this->actingAs($admin)
            ->get($adminProofUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delivery.proof.previewed',
            'actor_user_id' => $admin->id,
            'auditable_id' => $ownProofs->last()->id,
        ]);

        Storage::disk('local')->delete($ownFilePath);

        $this->actingAs($admin)
            ->get($adminProofUrl)
            ->assertNotFound();

        $nonImagePath = 'delivery-proofs/not-an-image.txt';
        Storage::disk('local')->put($nonImagePath, 'not an image');
        $invalidMimeProof = DeliveryProof::query()->create([
            'delivery_attempt_id' => $ownProofs->last()->delivery_attempt_id,
            'type' => 'photo',
            'file_path' => $nonImagePath,
            'captured_at' => now(),
            'uploaded_by' => $ownRider->user_id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.fulfillment.delivery-proofs.show', $invalidMimeProof))
            ->assertNotFound();

        $traversalProof = DeliveryProof::query()->create([
            'delivery_attempt_id' => $ownProofs->last()->delivery_attempt_id,
            'type' => 'photo',
            'file_path' => 'delivery-proofs/../private-proof.png',
            'captured_at' => now(),
            'uploaded_by' => $ownRider->user_id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.fulfillment.delivery-proofs.show', $traversalProof))
            ->assertNotFound();
    }

    public function test_monitoring_derives_real_stages_progress_and_metrics(): void
    {
        $assignedZone = $this->makeZone(
            $this->centerA,
            'STAGE-ASSIGNED'
        );

        $outZone = $this->makeZone(
            $this->centerA,
            'STAGE-OUT'
        );

        $deliveredZone = $this->makeZone(
            $this->centerA,
            'STAGE-DELIVERED'
        );

        $failedZone = $this->makeZone(
            $this->centerA,
            'STAGE-FAILED'
        );

        $assignedRider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $assignedZone,
            'STAGE-ASSIGNED',
            10
        );

        $outRider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $outZone,
            'STAGE-OUT',
            10
        );

        $deliveredRider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $deliveredZone,
            'STAGE-DELIVERED',
            10
        );

        $failedRider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $failedZone,
            'STAGE-FAILED',
            10
        );

        $assignedRecord =
            $this->makeReadyShipmentFor(
                $this->profileA,
                $this->centerA,
                $assignedZone,
                'STAGE-ASSIGNED'
            );

        $outRecord =
            $this->makeReadyShipmentFor(
                $this->profileA,
                $this->centerA,
                $outZone,
                'STAGE-OUT'
            );

        $deliveredRecord =
            $this->makeReadyShipmentFor(
                $this->profileA,
                $this->centerA,
                $deliveredZone,
                'STAGE-DELIVERED'
            );

        $failedRecord =
            $this->makeReadyShipmentFor(
                $this->profileA,
                $this->centerA,
                $failedZone,
                'STAGE-FAILED'
            );

        $assignedBatch =
            app(DispatchService::class)
                ->dispatchZone(
                    $assignedZone,
                    $assignedRider,
                    $this->logisticsA
                );

        $outBatch =
            app(DispatchService::class)
                ->dispatchZone(
                    $outZone,
                    $outRider,
                    $this->logisticsA
                );

        $deliveredBatch =
            app(DispatchService::class)
                ->dispatchZone(
                    $deliveredZone,
                    $deliveredRider,
                    $this->logisticsA
                );

        $failedBatch =
            app(DispatchService::class)
                ->dispatchZone(
                    $failedZone,
                    $failedRider,
                    $this->logisticsA
                );

        $outRecord['parcel']->update([
            'status' =>
                ParcelStatus::OutForDelivery->value,

            'last_event_at' =>
                now(),
        ]);

        $deliveredRecord['parcel']->update([
            'status' =>
                ParcelStatus::Delivered->value,

            'last_event_at' =>
                now(),
        ]);

        $failedRecord['parcel']->update([
            'status' =>
                ParcelStatus::Failed->value,

            'last_event_at' =>
                now(),
        ]);

        $this->actingAs($this->logisticsA)
            ->get(
                route('logistics.dispatch.monitoring')
            )
            ->assertOk()
            ->assertViewHas(
                'deliveries',
                function ($deliveries) use (
                    $assignedBatch,
                    $outBatch,
                    $deliveredBatch,
                    $failedBatch
                ): bool {
                    $rows = $deliveries->keyBy('id');

                    if (
                        ! $rows->has(
                            $assignedBatch->batch_no
                        )
                        || ! $rows->has(
                            $outBatch->batch_no
                        )
                        || ! $rows->has(
                            $deliveredBatch->batch_no
                        )
                        || ! $rows->has(
                            $failedBatch->batch_no
                        )
                    ) {
                        return false;
                    }

                    $assigned =
                        $rows[
                            $assignedBatch->batch_no
                        ];

                    $out =
                        $rows[
                            $outBatch->batch_no
                        ];

                    $delivered =
                        $rows[
                            $deliveredBatch->batch_no
                        ];

                    $failed =
                        $rows[
                            $failedBatch->batch_no
                        ];

                    $assignedTimeline =
                        collect(
                            $assigned['timeline']
                        )->pluck('label');

                    return
                        $assigned['status']
                            === 'ASSIGNED_TO_RIDER'
                        && $assigned['progress']
                            === 0
                        && $out['status']
                            === 'OUT_FOR_DELIVERY'
                        && $out['progress']
                            === 0
                        && $delivered['status']
                            === 'DELIVERED'
                        && $delivered['progress']
                            === 100
                        && $failed['status']
                            === 'DELIVERY_FAILED'
                        && $failed['progress']
                            === 100
                        && $assignedTimeline
                            ->contains(
                                'Assigned to rider'
                            )
                        && $assignedTimeline
                            ->contains(
                                'Released for delivery'
                            );
                }
            )
            ->assertViewHas(
                'metrics',
                fn (array $metrics) =>
                    $metrics['assigned'] === 1
                    && $metrics[
                        'out_for_delivery_parcels'
                    ] === 1
                    && $metrics[
                        'out_for_delivery_routes'
                    ] === 1
                    && $metrics[
                        'delivered_today'
                    ] === 1
                    && $metrics[
                        'exceptions'
                    ] === 1
            );

        /*
        * The untouched dispatch remains the assigned case.
        */
        $this->assertSame(
            ParcelStatus::Dispatched->value,
            $assignedRecord['parcel']
                ->fresh()
                ->status
        );
    }

    public function test_monitoring_keeps_mixed_failed_and_active_route_out_for_delivery(): void
    {
        $zone = $this->makeZone(
            $this->centerA,
            'MONITOR-MIXED'
        );

        $rider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $zone,
            'MONITOR-MIXED',
            10
        );

        $record = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $zone,
            'MONITOR-MIXED',
            2
        );

        $batch = app(DispatchService::class)
            ->dispatchZone(
                $zone,
                $rider,
                $this->logisticsA
            );

        $parcels = Parcel::query()
            ->where(
                'shipment_id',
                $record['parcel']->shipment_id
            )
            ->orderBy('id')
            ->get();

        $this->assertCount(
            2,
            $parcels
        );

        $parcels[0]->update([
            'status' =>
                ParcelStatus::Failed->value,

            'last_event_at' =>
                now(),
        ]);

        $parcels[1]->update([
            'status' =>
                ParcelStatus::OutForDelivery->value,

            'last_event_at' =>
                now(),
        ]);

        $this
            ->actingAs($this->logisticsA)
            ->get(
                route(
                    'logistics.dispatch.monitoring'
                )
            )
            ->assertOk()
            ->assertViewHas(
                'deliveries',
                function ($deliveries) use (
                    $batch
                ): bool {
                    $delivery =
                        $deliveries->firstWhere(
                            'id',
                            $batch->batch_no
                        );

                    return $delivery !== null
                        && $delivery['status']
                            === 'OUT_FOR_DELIVERY'
                        && $delivery['progress']
                            === 50;
                }
            )
            ->assertViewHas(
                'metrics',
                fn (array $metrics) =>
                    $metrics[
                        'out_for_delivery_parcels'
                    ] === 1
                    && $metrics[
                        'out_for_delivery_routes'
                    ] === 1
                    && $metrics[
                        'exceptions'
                    ] === 1
            );
    }

    public function test_monitoring_page_is_zero_safe_without_dispatch_batches(): void
    {
        $this->actingAs($this->logisticsA)
            ->get(
                route('logistics.dispatch.monitoring')
            )
            ->assertOk()
            ->assertViewHas(
                'deliveries',
                fn ($deliveries) =>
                    $deliveries->isEmpty()
            )
            ->assertViewHas(
                'metrics',
                fn (array $metrics) =>
                    $metrics['assigned'] === 0
                    && $metrics[
                        'out_for_delivery_parcels'
                    ] === 0
                    && $metrics[
                        'out_for_delivery_routes'
                    ] === 0
                    && $metrics[
                        'delivered_today'
                    ] === 0
                    && $metrics[
                        'exceptions'
                    ] === 0
            )
            ->assertSee(
                'No dispatch batches are'
            )
            ->assertSee(
                'available for monitoring.'
            );
    }

    public function test_logistics_can_dispatch_sorted_parcels_to_an_eligible_rider(): void
    {
        $zone = $this->makeZone(
            $this->centerA,
            'NORTH'
        );

        $rider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $zone,
            'SUCCESS',
            10
        );

        $record = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $zone,
            'SUCCESS',
            2
        );

        $batch = app(DispatchService::class)
            ->dispatchZone(
                $zone,
                $rider,
                $this->logisticsA
            );

        $this->assertSame(
            'dispatched',
            $batch->status
        );

        $this->assertSame(
            $this->centerA->id,
            $batch->sorting_center_id
        );

        $this->assertSame(
            $zone->id,
            $batch->sorting_zone_id
        );

        $this->assertSame(
            $rider->id,
            $batch->rider_profile_id
        );

        $this->assertSame(
            $this->logisticsA->id,
            $batch->prepared_by
        );

        $this->assertNotNull($batch->prepared_at);
        $this->assertNotNull($batch->assigned_at);
        $this->assertNotNull($batch->dispatched_at);

        $this->assertCount(
            2,
            $batch->parcels
        );

        foreach ($record['parcels'] as $parcel) {
            $this->assertSame(
                ParcelStatus::Dispatched->value,
                $parcel->fresh()->status
            );

            $this->assertDatabaseHas(
                'dispatch_batch_parcels',
                [
                    'dispatch_batch_id' => $batch->id,
                    'parcel_id' => $parcel->id,
                ]
            );

            $this->assertDatabaseHas(
                'shipment_events',
                [
                    'shipment_id' =>
                        $record['shipment']->id,

                    'parcel_id' =>
                        $parcel->id,

                    'event_type' =>
                        'parcel_dispatched',

                    'from_status' =>
                        ParcelStatus::Sorted->value,

                    'to_status' =>
                        ParcelStatus::Dispatched->value,

                    'sorting_center_id' =>
                        $this->centerA->id,

                    'sorting_zone_id' =>
                        $zone->id,

                    'actor_user_id' =>
                        $this->logisticsA->id,

                    'source' =>
                        'logistics',
                ]
            );
        }

        $this->assertSame(
            ShipmentStatus::Dispatched->value,
            $record['shipment']->fresh()->status
        );

        $this->assertDatabaseHas(
            'shipment_events',
            [
                'shipment_id' =>
                    $record['shipment']->id,

                'parcel_id' =>
                    null,

                'event_type' =>
                    'shipment_dispatched',

                'from_status' =>
                    ShipmentStatus::Sorted->value,

                'to_status' =>
                    ShipmentStatus::Dispatched->value,

                'actor_user_id' =>
                    $this->logisticsA->id,

                'source' =>
                    'logistics',
            ]
        );
    }

    public function test_courier_work_type_cannot_receive_a_local_dispatch(): void
    {
        $zone = $this->makeZone($this->centerA, 'COURIER-LOCAL-BLOCK');
        $rider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $zone,
            'COURIER-LOCAL-BLOCK',
            10,
        );
        $rider->update([
            'work_type' => 'courier',
            'vehicle_type' => 'Van',
            'linehaul_parcel_capacity' => 20,
            'courier_vehicle_verified_by' => $this->logisticsA->id,
            'courier_vehicle_verified_at' => now(),
        ]);
        $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $zone,
            'COURIER-LOCAL-BLOCK',
            1,
        );

        $this->expectException(ConflictHttpException::class);
        app(DispatchService::class)->dispatchZone($zone, $rider, $this->logisticsA);
    }

    public function test_logistics_cannot_dispatch_from_another_providers_zone(): void
    {
        $foreignZone = $this->makeZone(
            $this->centerB,
            'FOREIGN'
        );

        $ownZone = $this->makeZone(
            $this->centerA,
            'OWN'
        );

        $rider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $ownZone,
            'OWN',
            10
        );

        $this->expectException(
            AuthorizationException::class
        );

        app(DispatchService::class)
            ->dispatchZone(
                $foreignZone,
                $rider,
                $this->logisticsA
            );
    }

    public function test_logistics_cannot_dispatch_to_another_providers_rider(): void
    {
        $zoneA = $this->makeZone(
            $this->centerA,
            'OWN'
        );

        $zoneB = $this->makeZone(
            $this->centerB,
            'FOREIGN'
        );

        $foreignRider = $this->makeRider(
            $this->profileB,
            $this->centerB,
            $zoneB,
            'FOREIGN',
            10
        );

        $this->expectException(
            AuthorizationException::class
        );

        app(DispatchService::class)
            ->dispatchZone(
                $zoneA,
                $foreignRider,
                $this->logisticsA
            );
    }

    public function test_rider_must_belong_to_the_dispatch_sorting_center(): void
    {
        $zone = $this->makeZone(
            $this->centerA,
            'CENTER-A'
        );

        $otherZone = $this->makeZone(
            $this->centerB,
            'CENTER-B'
        );

        $rider = $this->makeRider(
            $this->profileA,
            $this->centerB,
            $otherZone,
            'WRONG-CENTER',
            10
        );

        $this->expectException(
            ConflictHttpException::class
        );

        app(DispatchService::class)
            ->dispatchZone(
                $zone,
                $rider,
                $this->logisticsA
            );
    }

    public function test_rider_must_belong_to_the_dispatch_zone(): void
    {
        $zoneA = $this->makeZone(
            $this->centerA,
            'ZONE-A'
        );

        $zoneB = $this->makeZone(
            $this->centerA,
            'ZONE-B'
        );

        $rider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $zoneB,
            'WRONG-ZONE',
            10
        );

        $this->expectException(
            ConflictHttpException::class
        );

        app(DispatchService::class)
            ->dispatchZone(
                $zoneA,
                $rider,
                $this->logisticsA
            );
    }

    public function test_unapproved_rider_cannot_receive_dispatch(): void
    {
        $zone = $this->makeZone(
            $this->centerA,
            'UNAPPROVED'
        );

        $rider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $zone,
            'UNAPPROVED',
            10
        );

        $rider->update([
            'verification_status' => 'pending',
        ]);

        $this->expectException(
            ConflictHttpException::class
        );

        app(DispatchService::class)
            ->dispatchZone(
                $zone,
                $rider,
                $this->logisticsA
            );
    }

    public function test_unavailable_rider_cannot_receive_dispatch(): void
    {
        $zone = $this->makeZone(
            $this->centerA,
            'UNAVAILABLE'
        );

        $rider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $zone,
            'UNAVAILABLE',
            10
        );

        $rider->update([
            'availability_status' => 'offline',
        ]);

        $this->expectException(
            ConflictHttpException::class
        );

        app(DispatchService::class)
            ->dispatchZone(
                $zone,
                $rider,
                $this->logisticsA
            );
    }

    public function test_inactive_rider_user_cannot_receive_dispatch(): void
    {
        $zone = $this->makeZone(
            $this->centerA,
            'INACTIVE'
        );

        $rider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $zone,
            'INACTIVE',
            10
        );

        $rider->user->update([
            'status' => 'suspended',
        ]);

        $this->expectException(
            ConflictHttpException::class
        );

        app(DispatchService::class)
            ->dispatchZone(
                $zone,
                $rider,
                $this->logisticsA
            );
    }

    public function test_unsorted_parcels_are_not_dispatchable(): void
    {
        $zone = $this->makeZone(
            $this->centerA,
            'UNSORTED'
        );

        $rider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $zone,
            'UNSORTED',
            10
        );

        $record = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $zone,
            'UNSORTED'
        );

        $record['parcel']->update([
            'status' =>
                ParcelStatus::Received->value,
        ]);

        $record['shipment']->update([
            'status' =>
                ShipmentStatus::AtSortingCenter->value,
        ]);

        $this->expectException(
            ConflictHttpException::class
        );

        app(DispatchService::class)
            ->dispatchZone(
                $zone,
                $rider,
                $this->logisticsA
            );
    }

    public function test_parcel_in_an_active_dispatch_batch_is_not_dispatched_again(): void
    {
        $zone = $this->makeZone(
            $this->centerA,
            'DUPLICATE'
        );

        $rider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $zone,
            'DUPLICATE',
            10
        );

        $record = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $zone,
            'DUPLICATE'
        );

        $existingBatch =
            DispatchBatch::query()->create([
                'batch_no' =>
                    'DSP-EXISTING-DUPLICATE',

                'sorting_center_id' =>
                    $this->centerA->id,

                'sorting_zone_id' =>
                    $zone->id,

                'rider_profile_id' =>
                    $rider->id,

                'status' =>
                    'dispatched',

                'prepared_by' =>
                    $this->logisticsA->id,

                'prepared_at' =>
                    now(),

                'assigned_at' =>
                    now(),

                'dispatched_at' =>
                    now(),
            ]);

        $existingBatch->parcels()->attach(
            $record['parcel']->id,
            [
                'sequence' => 1,
                'loaded_at' => now(),
            ]
        );

        $this->expectException(
            ConflictHttpException::class
        );

        app(DispatchService::class)
            ->dispatchZone(
                $zone,
                $rider,
                $this->logisticsA
            );
    }

    public function test_dispatch_rejects_ready_parcels_above_rider_capacity(): void
    {
        $zone = $this->makeZone(
            $this->centerA,
            'CAPACITY'
        );

        $rider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $zone,
            'CAPACITY',
            1
        );

        $record = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $zone,
            'CAPACITY',
            2
        );

        try {
            app(DispatchService::class)
                ->dispatchZone(
                    $zone,
                    $rider,
                    $this->logisticsA
                );

            $this->fail(
                'Expected dispatch capacity conflict.'
            );
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }

        foreach ($record['parcels'] as $parcel) {
            $this->assertSame(
                ParcelStatus::Sorted->value,
                $parcel->fresh()->status
            );
        }

        $this->assertDatabaseCount(
            'dispatch_batches',
            0
        );
    }

    public function test_existing_active_load_counts_against_rider_capacity(): void
    {
        $zone = $this->makeZone(
            $this->centerA,
            'ACTIVE-LOAD'
        );

        $rider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $zone,
            'ACTIVE-LOAD',
            2
        );

        $existing = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $zone,
            'OLD-LOAD'
        );

        $existingBatch =
            DispatchBatch::query()->create([
                'batch_no' =>
                    'DSP-OLD-ACTIVE-LOAD',

                'sorting_center_id' =>
                    $this->centerA->id,

                'sorting_zone_id' =>
                    $zone->id,

                'rider_profile_id' =>
                    $rider->id,

                'status' =>
                    'dispatched',

                'prepared_by' =>
                    $this->logisticsA->id,

                'prepared_at' =>
                    now(),

                'assigned_at' =>
                    now(),

                'dispatched_at' =>
                    now(),
            ]);

        $existingBatch->parcels()->attach(
            $existing['parcel']->id,
            [
                'sequence' => 1,
                'loaded_at' => now(),
            ]
        );

        $existing['parcel']->update([
            'status' =>
                ParcelStatus::Dispatched->value,
        ]);

        $new = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $zone,
            'NEW-LOAD',
            2
        );

        try {
            app(DispatchService::class)
                ->dispatchZone(
                    $zone,
                    $rider,
                    $this->logisticsA
                );

            $this->fail(
                'Expected remaining capacity conflict.'
            );
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }

        foreach ($new['parcels'] as $parcel) {
            $this->assertSame(
                ParcelStatus::Sorted->value,
                $parcel->fresh()->status
            );
        }

        $this->assertDatabaseCount(
            'dispatch_batches',
            1
        );
    }

    public function test_shipment_is_dispatched_only_after_all_sorted_parcels_leave_sorting(): void
    {
        $zoneA = $this->makeZone(
            $this->centerA,
            'MULTI-A'
        );

        $zoneB = $this->makeZone(
            $this->centerA,
            'MULTI-B'
        );

        $riderA = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $zoneA,
            'MULTI-A',
            10
        );

        $riderB = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $zoneB,
            'MULTI-B',
            10
        );

        $record = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $zoneA,
            'MULTI',
            2
        );

        $record['parcels'][1]->update([
            'current_zone_id' =>
                $zoneB->id,
        ]);

        app(DispatchService::class)
            ->dispatchZone(
                $zoneA,
                $riderA,
                $this->logisticsA
            );

        $this->assertSame(
            ShipmentStatus::Sorted->value,
            $record['shipment']->fresh()->status
        );

        app(DispatchService::class)
            ->dispatchZone(
                $zoneB,
                $riderB,
                $this->logisticsA
            );

        $this->assertSame(
            ShipmentStatus::Dispatched->value,
            $record['shipment']->fresh()->status
        );

        $this->assertSame(
            1,
            $record['shipment']
                ->events()
                ->where(
                    'event_type',
                    'shipment_dispatched'
                )
                ->count()
        );
    }

    /**
     * @return array{
     *     User,
     *     LogisticsProfile,
     *     SortingCenter
     * }
     */
    private function makeLogisticsProvider(
        string $suffix
    ): array {
        $user = User::factory()->create([
            'name' =>
                "Logistics {$suffix}",

            'first_name' =>
                'Logistics',

            'last_name' =>
                $suffix,

            'email' =>
                "dispatch-logistics-{$suffix}@example.test",

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
                    "Logistics {$suffix} Incorporated",

                'display_name' =>
                    "Logistics {$suffix}",

                'contact_phone' =>
                    '0917000000'
                    . ($suffix === 'A' ? '1' : '2'),

                'status' =>
                    'active',
            ]);

        $center = $this->makeCenter(
            $profile,
            $user,
            "MAIN-{$suffix}"
        );

        return [
            $user,
            $profile,
            $center,
        ];
    }

    private function makeCenter(
        LogisticsProfile $profile,
        User $user,
        string $suffix
    ): SortingCenter {
        $address = Address::query()->create([
            'user_id' =>
                $user->id,

            'label' =>
                "Sorting Center {$suffix}",

            'recipient_name' =>
                $user->name,

            'phone' =>
                $profile->contact_phone,

            'street' =>
                "{$suffix} Logistics Road",

            'barangay' =>
                'San Rafael',

            'city_municipality' =>
                'San Pablo City',

            'province' =>
                'Laguna',

            'postal_code' =>
                '4000',
        ]);

        return SortingCenter::query()->create([
            'logistics_profile_id' =>
                $profile->id,

            'address_id' =>
                $address->id,

            'name' =>
                "Sorting Center {$suffix}",

            'code' =>
                "DISPATCH-CENTER-{$suffix}",

            'contact_phone' =>
                $profile->contact_phone,

            'status' =>
                'active',
        ]);
    }

    private function makeZone(
        SortingCenter $center,
        string $suffix
    ): SortingZone {
        return SortingZone::query()->create([
            'sorting_center_id' =>
                $center->id,

            'code' =>
                "DISPATCH-ZONE-{$suffix}",

            'name' =>
                "Dispatch Zone {$suffix}",

            'destination_rules' => [
                'province' => 'Laguna',
                'city_municipality' => 'San Pablo City',
                'barangays' => ['San Rafael'],
            ],

            'status' =>
            'active',
        ]);
    }

    private function recordDashboardReceivedEvent(
        array $record,
        SortingCenter $center,
        SortingZone $zone,
        User $actor,
        string $occurredAt,
        string $notes
    ): ShipmentEvent {
        return ShipmentEvent::query()->create([
            'shipment_id' => $record['shipment']->id,
            'parcel_id' => $record['parcel']->id,
            'event_type' => 'received_at_center',
            'from_status' => ParcelStatus::PickedUp->value,
            'to_status' => ParcelStatus::Received->value,
            'sorting_center_id' => $center->id,
            'sorting_zone_id' => $zone->id,
            'actor_user_id' => $actor->id,
            'source' => 'logistics',
            'notes' => $notes,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function makeRider(
        LogisticsProfile $profile,
        SortingCenter $center,
        SortingZone $zone,
        string $suffix,
        int $capacity
    ): RiderProfile {
        $user = User::factory()->create([
            'name' =>
                "Dispatch Rider {$suffix}",

            'email' =>
                'dispatch-rider-'
                . strtolower($suffix)
                . '@example.test',

            'role' =>
                'rider',

            'status' =>
                'active',

            'vehicle_type' =>
                'Motorcycle',

            'plate_number' =>
                "DSP-{$suffix}",
        ]);

        return RiderProfile::query()->create([
            'user_id' =>
                $user->id,

            'logistics_profile_id' =>
                $profile->id,

            'home_sorting_center_id' =>
                $center->id,

            'current_zone_id' =>
                $zone->id,

            'vehicle_type' =>
                'Motorcycle',

            'plate_number' =>
                "DSP-{$suffix}",

            'parcel_capacity' =>
                $capacity,

            'availability_status' =>
                'available',

            'verification_status' =>
                'approved',
        ]);
    }

    /**
     * @return array{
     *     shipment: Shipment,
     *     waybill: Waybill,
     *     parcel: Parcel,
     *     parcels: \Illuminate\Support\Collection<int, Parcel>
     * }
     */
    private function makeReadyShipmentFor(
        LogisticsProfile $profile,
        SortingCenter $center,
        SortingZone $zone,
        string $suffix,
        int $parcelCount = 1
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
                    "Dispatch Seller {$suffix}",

                'standing_status' =>
                    'good_standing',
            ]);

        $store = Store::query()->create([
            'seller_profile_id' =>
                $sellerProfile->id,

            'name' =>
                "Dispatch Store {$suffix}",

            'slug' =>
                'dispatch-store-'
                . strtolower($suffix),

            'publication_status' =>
                'published',
        ]);

        $order = Order::query()->create([
            'order_no' =>
                "DISPATCH-ORDER-{$suffix}",

            'buyer_id' =>
                $buyer->id,

            'recipient_name' =>
                'Dispatch Buyer',

            'recipient_phone' =>
                '09171234567',

            'address_line' =>
                '123 Dispatch Street',

            'barangay' =>
                'San Rafael',

            'city_municipality' =>
                'San Pablo City',

            'province' =>
                'Laguna',

            'postal_code' =>
                '4000',

            'subtotal_minor' =>
                10000,

            'total_minor' =>
                10000,

            'status' =>
                'processing',

            'payment_status' =>
                'paid',
        ]);

        $sellerOrder =
            SellerOrder::query()->create([
                'seller_order_no' =>
                    "DISPATCH-SO-{$suffix}",

                'order_id' =>
                    $order->id,

                'store_id' =>
                    $store->id,

                'status' =>
                    'ready_for_pickup',

                'subtotal_minor' =>
                    10000,

                'total_minor' =>
                    10000,
            ]);

        $shipment =
            Shipment::query()->create([
                'shipment_no' =>
                    "DISPATCH-SHIP-{$suffix}",

                'seller_order_id' =>
                    $sellerOrder->id,

                'logistics_profile_id' =>
                    $profile->id,

                'status' =>
                    ShipmentStatus::Sorted->value,

                'shipping_fee_minor' =>
                    0,

                'cod_amount_minor' =>
                    0,
            ]);

        $waybill = Waybill::query()->create([
            'shipment_id' =>
                $shipment->id,

            'waybill_no' =>
                "DISPATCH-WB-{$suffix}",

            'scan_token' =>
                hash(
                    'sha256',
                    "dispatch-scan-{$suffix}"
                ),

            'barcode_value' =>
                "DISPATCH-BARCODE-{$suffix}",

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
                        "DISPATCH-PARCEL-{$suffix}-{$sequence}",

                    'piece_sequence' =>
                        $sequence,

                    'weight_kg' =>
                        1.250,

                    'status' =>
                        ParcelStatus::Sorted->value,

                    'current_sorting_center_id' =>
                        $center->id,

                    'current_zone_id' =>
                        $zone->id,

                    'last_event_at' =>
                        now(),
                ])
            );
        }

        return [
            'shipment' =>
                $shipment,

            'waybill' =>
                $waybill,

            'parcel' =>
                $parcels->first(),

            'parcels' =>
                $parcels,
        ];
    }

    public function test_dispatch_page_uses_real_owned_zones_parcels_and_riders(): void
    {
        $ownZone = $this->makeZone(
            $this->centerA,
            'UI-OWN'
        );

        $foreignZone = $this->makeZone(
            $this->centerB,
            'UI-FOREIGN'
        );

        $ownRider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $ownZone,
            'UI-OWN',
            10
        );

        $foreignRider = $this->makeRider(
            $this->profileB,
            $this->centerB,
            $foreignZone,
            'UI-FOREIGN',
            10
        );

        $own = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $ownZone,
            'UI-OWN'
        );

        $unmatched = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $ownZone,
            'UI-UNMATCHED'
        );
        $unmatched['shipment']->sellerOrder->order->update([
            'barangay' => 'Pinagbayanan',
            'city_municipality' => 'Pila',
        ]);

        $foreign = $this->makeReadyShipmentFor(
            $this->profileB,
            $this->centerB,
            $foreignZone,
            'UI-FOREIGN'
        );

        $this->actingAs($this->logisticsA)
            ->get(
                route('logistics.dispatch.index')
            )
            ->assertOk()
            ->assertSee($ownZone->code)
            ->assertSee($ownRider->user->name)
            ->assertSee('1 parcel does not match this zone')
            ->assertViewHas(
                'metrics',
                fn (array $metrics) =>
                    $metrics['ready'] === 1
                    && $metrics['ready_zones'] === 1
            )
            ->assertViewHas(
                'zones',
                fn ($zones) => $zones->first()['routing_issues'] === 1
            )
            ->assertDontSee($foreignZone->code)
            ->assertDontSee($foreignRider->user->name)
            ->assertDontSee($foreign['parcel']->parcel_no)
            ->assertDontSee('Nico Flores')
            ->assertDontSee('SP-N1')
            ->assertDontSee(
                'data-dispatch-action',
                false
            );

        $this->assertSame(
            ParcelStatus::Sorted->value,
            $own['parcel']->fresh()->status
        );
    }

    public function test_dispatch_post_route_creates_real_batch(): void
    {
        $zone = $this->makeZone(
            $this->centerA,
            'HTTP'
        );

        $rider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $zone,
            'HTTP',
            10
        );

        $record = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $zone,
            'HTTP'
        );

        $this->actingAs($this->logisticsA)
            ->post(
                route(
                    'logistics.dispatch.store',
                    $zone
                ),
                [
                    'rider_profile_id' =>
                        $rider->id,
                ]
            )
            ->assertRedirect(
                route('logistics.dispatch.index')
            )
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $batch = DispatchBatch::query()
            ->where(
                'rider_profile_id',
                $rider->id
            )
            ->firstOrFail();

        $this->assertSame(
            'dispatched',
            $batch->status
        );

        $this->assertDatabaseHas(
            'dispatch_batch_parcels',
            [
                'dispatch_batch_id' =>
                    $batch->id,

                'parcel_id' =>
                    $record['parcel']->id,
            ]
        );

        $this->assertSame(
            ParcelStatus::Dispatched->value,
            $record['parcel']->fresh()->status
        );

        $this->assertSame(
            ShipmentStatus::Dispatched->value,
            $record['shipment']->fresh()->status
        );
    }

    public function test_dispatch_leaves_parcels_outside_the_zone_barangay_coverage_undispatched(): void
    {
        $zone = $this->makeZone(
            $this->centerA,
            'PARTIAL-COVERAGE'
        );
        $rider = $this->makeRider(
            $this->profileA,
            $this->centerA,
            $zone,
            'PARTIAL-COVERAGE',
            10
        );
        $covered = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $zone,
            'COVERED-PARCEL'
        );
        $uncovered = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $zone,
            'UNCOVERED-PARCEL'
        );
        $uncovered['shipment']->sellerOrder->order->update([
            'barangay' => 'Pinagbayanan',
            'city_municipality' => 'Pila',
        ]);

        $batch = app(DispatchService::class)->dispatchZone(
            $zone,
            $rider,
            $this->logisticsA
        );

        $this->assertDatabaseHas('dispatch_batch_parcels', [
            'dispatch_batch_id' => $batch->id,
            'parcel_id' => $covered['parcel']->id,
        ]);
        $this->assertDatabaseMissing('dispatch_batch_parcels', [
            'dispatch_batch_id' => $batch->id,
            'parcel_id' => $uncovered['parcel']->id,
        ]);
        $this->assertSame(
            ParcelStatus::Dispatched->value,
            $covered['parcel']->fresh()->status
        );
        $this->assertSame(
            ParcelStatus::Sorted->value,
            $uncovered['parcel']->fresh()->status
        );
    }

    public function test_zone_cannot_be_deactivated_while_a_failed_parcel_can_retry_delivery(): void
    {
        $zone = $this->makeZone($this->centerA, 'FAILED-IN-USE');
        $record = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $zone,
            'FAILED-IN-USE'
        );
        $record['parcel']->update([
            'status' => ParcelStatus::Failed->value,
        ]);

        $this->actingAs($this->logisticsA)
            ->patch(
                route('logistics.sorting.zones.status', $zone),
                ['status' => 'inactive']
            )
            ->assertRedirect()
            ->assertSessionHasErrors('status');

        $this->assertSame('active', $zone->fresh()->status);
    }

    public function test_dispatch_post_route_rejects_foreign_rider(): void
    {
        $ownZone = $this->makeZone(
            $this->centerA,
            'HTTP-OWN'
        );

        $foreignZone = $this->makeZone(
            $this->centerB,
            'HTTP-FOREIGN'
        );

        $foreignRider = $this->makeRider(
            $this->profileB,
            $this->centerB,
            $foreignZone,
            'HTTP-FOREIGN',
            10
        );

        $record = $this->makeReadyShipmentFor(
            $this->profileA,
            $this->centerA,
            $ownZone,
            'HTTP-FOREIGN-RIDER'
        );

        $this->actingAs($this->logisticsA)
            ->post(
                route(
                    'logistics.dispatch.store',
                    $ownZone
                ),
                [
                    'rider_profile_id' =>
                        $foreignRider->id,
                ]
            )
            ->assertForbidden();

        $this->assertDatabaseCount(
            'dispatch_batches',
            0
        );

        $this->assertSame(
            ParcelStatus::Sorted->value,
            $record['parcel']->fresh()->status
        );
    }
}
