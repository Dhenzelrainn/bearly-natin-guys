<?php

namespace Tests\Feature\Logistics;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Enums\DeliveryAttemptOutcome;
use App\Models\Address;
use App\Models\DeliveryAttempt;
use App\Models\DispatchBatch;
use App\Models\RiderProfile;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\LogisticsProfile;
use App\Models\User;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\Store;
use App\Models\Waybill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LogisticsReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_default_to_latest_seven_day_period(): void
    {
        $user = $this->makeLogisticsOperator();

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
            $response = $this
                ->actingAs($user)
                ->get(
                    route('logistics.reports.index')
                );

            $response
                ->assertOk()
                ->assertViewHas(
                    'reportRange',
                    function (array $range): bool {
                        return $range['from']
                                === '2026-09-28'
                            && $range['to']
                                === '2026-10-04';
                    }
                )
                ->assertSee(
                    'value="2026-09-28"',
                    false
                )
                ->assertSee(
                    'value="2026-10-04"',
                    false
                );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_reports_accept_custom_date_range(): void
    {
        $user = $this->makeLogisticsOperator();

        $response = $this
            ->actingAs($user)
            ->get(
                route(
                    'logistics.reports.index',
                    [
                        'from' => '2026-09-14',
                        'to' => '2026-09-20',
                    ]
                )
            );

        $response
            ->assertOk()
            ->assertViewHas(
                'reportRange',
                function (array $range): bool {
                    return $range['from']
                            === '2026-09-14'
                        && $range['to']
                            === '2026-09-20';
                }
            )
            ->assertSee(
                'value="2026-09-14"',
                false
            )
            ->assertSee(
                'value="2026-09-20"',
                false
            );
    }

    public function test_reports_reject_reversed_date_range(): void
    {
        $user = $this->makeLogisticsOperator();

        $response = $this
            ->actingAs($user)
            ->get(
                route(
                    'logistics.reports.index',
                    [
                        'from' => '2026-09-20',
                        'to' => '2026-09-14',
                    ]
                )
            );

        $response
            ->assertRedirect(
                route('logistics.reports.index')
            )
            ->assertSessionHasErrors('to');
    }

    public function test_reports_use_real_provider_scoped_summary_metrics(): void
    {
        $ownUser = $this->makeLogisticsOperator(
            'A',
            'reports-a@example.test'
        );

        $foreignUser = $this->makeLogisticsOperator(
            'B',
            'reports-b@example.test'
        );

        $ownProfile = LogisticsProfile::query()
            ->where('user_id', $ownUser->id)
            ->firstOrFail();

        $foreignProfile = LogisticsProfile::query()
            ->where('user_id', $foreignUser->id)
            ->firstOrFail();

        $first = $this->makeReportParcel(
            $ownProfile,
            'O1'
        );

        $second = $this->makeReportParcel(
            $ownProfile,
            'O2'
        );

        $third = $this->makeReportParcel(
            $ownProfile,
            'O3'
        );

        $outsideRange = $this->makeReportParcel(
            $ownProfile,
            'O4'
        );

        $foreign = $this->makeReportParcel(
            $foreignProfile,
            'F1'
        );

        /*
        * Own parcel #1:
        * received -> sorted in 30 minutes -> delivered.
        */
        $this->recordParcelEvent(
            $first['shipment'],
            $first['parcel'],
            'received_at_center',
            ParcelStatus::Received->value,
            '2026-09-15 09:00:00'
        );

        $this->recordParcelEvent(
            $first['shipment'],
            $first['parcel'],
            'parcel_sorted',
            ParcelStatus::Sorted->value,
            '2026-09-15 09:30:00'
        );

        $this->recordParcelEvent(
            $first['shipment'],
            $first['parcel'],
            'parcel_delivered',
            ParcelStatus::Delivered->value,
            '2026-09-15 12:00:00'
        );

        /*
        * Own parcel #2:
        * received -> sorted in 60 minutes -> failed delivery.
        */
        $this->recordParcelEvent(
            $second['shipment'],
            $second['parcel'],
            'received_at_center',
            ParcelStatus::Received->value,
            '2026-09-16 10:00:00'
        );

        $this->recordParcelEvent(
            $second['shipment'],
            $second['parcel'],
            'parcel_sorted',
            ParcelStatus::Sorted->value,
            '2026-09-16 11:00:00'
        );

        $this->recordParcelEvent(
            $second['shipment'],
            $second['parcel'],
            'parcel_delivery_failed',
            ParcelStatus::Failed->value,
            '2026-09-16 13:00:00'
        );

        $this->recordParcelEvent(
            $second['shipment'],
            $second['parcel'],
            'parcel_delivery_retried',
            ParcelStatus::OutForDelivery->value,
            '2026-09-16 14:00:00'
        );

        /*
        * Own parcel #3 contributes to throughput only.
        */
        $this->recordParcelEvent(
            $third['shipment'],
            $third['parcel'],
            'received_at_center',
            ParcelStatus::Received->value,
            '2026-09-17 08:00:00'
        );

        /*
        * Own-provider activity outside the selected range
        * must not affect the report.
        */
        $this->recordParcelEvent(
            $outsideRange['shipment'],
            $outsideRange['parcel'],
            'received_at_center',
            ParcelStatus::Received->value,
            '2026-09-13 08:00:00'
        );

        $this->recordParcelEvent(
            $outsideRange['shipment'],
            $outsideRange['parcel'],
            'parcel_delivered',
            ParcelStatus::Delivered->value,
            '2026-09-13 10:00:00'
        );

        /*
        * Foreign-provider activity inside the selected range
        * must also be excluded.
        */
        $this->recordParcelEvent(
            $foreign['shipment'],
            $foreign['parcel'],
            'received_at_center',
            ParcelStatus::Received->value,
            '2026-09-15 07:00:00'
        );

        $this->recordParcelEvent(
            $foreign['shipment'],
            $foreign['parcel'],
            'parcel_sorted',
            ParcelStatus::Sorted->value,
            '2026-09-15 07:10:00'
        );

        $this->recordParcelEvent(
            $foreign['shipment'],
            $foreign['parcel'],
            'parcel_delivered',
            ParcelStatus::Delivered->value,
            '2026-09-15 08:00:00'
        );

        $response = $this
            ->actingAs($ownUser)
            ->get(
                route(
                    'logistics.reports.index',
                    [
                        'from' => '2026-09-14',
                        'to' => '2026-09-20',
                    ]
                )
            );

        $response
            ->assertOk()
            ->assertViewHas(
                'summary',
                function (array $summary): bool {
                    return $summary['throughput'] === 3
                        && $summary['delivered'] === 1
                        && (float) $summary['success_rate']
                            === 50.0
                        && $summary[
                            'resolved_delivery_outcomes'
                        ] === 2
                        && $summary['avg_sort_time']
                            === '45m';
                }
            )

            ->assertViewHas(
                'dailyVolumes',
                [
                    0,
                    1,
                    1,
                    1,
                    0,
                    0,
                    0,
                ]
            )
            ->assertViewHas(
                'dailyLabels',
                [
                    'Sep 14',
                    'Sep 15',
                    'Sep 16',
                    'Sep 17',
                    'Sep 18',
                    'Sep 19',
                    'Sep 20',
                ]
            )
            ->assertViewHas(
                'statusBreakdown',
                function (array $items): bool {
                    $breakdown = collect($items)
                        ->keyBy('label');

                    return
                        $breakdown['Delivered']['value'] === 1
                        && (float) $breakdown[
                            'Delivered'
                        ]['share'] === 50.0

                        && $breakdown[
                            'Out for Delivery'
                        ]['value'] === 1
                        && (float) $breakdown[
                            'Out for Delivery'
                        ]['share'] === 50.0

                        && $breakdown[
                            'Delivery Failed'
                        ]['value'] === 0

                        && $breakdown[
                            'Returned'
                        ]['value'] === 0;
                }
            );
    }

    public function test_reports_use_real_provider_scoped_rider_performance(): void
    {
        $ownUser = $this->makeLogisticsOperator(
            'RIDER',
            'reports-rider@example.test'
        );

        $foreignUser = $this->makeLogisticsOperator(
            'FOREIGN-RIDER',
            'reports-foreign-rider@example.test'
        );

        $ownProfile = LogisticsProfile::query()
            ->where('user_id', $ownUser->id)
            ->firstOrFail();

        $foreignProfile = LogisticsProfile::query()
            ->where('user_id', $foreignUser->id)
            ->firstOrFail();

        $ownFacility = $this->makeReportFacility(
            $ownUser,
            $ownProfile,
            'OWN'
        );

        $foreignFacility = $this->makeReportFacility(
            $foreignUser,
            $foreignProfile,
            'FOREIGN'
        );

        $primaryRider = $this->makeReportRider(
            $ownProfile,
            $ownFacility['center'],
            $ownFacility['zone'],
            'Alpha'
        );

        $zeroOutcomeRider = $this->makeReportRider(
            $ownProfile,
            $ownFacility['center'],
            $ownFacility['zone'],
            'Zero'
        );

        $foreignRider = $this->makeReportRider(
            $foreignProfile,
            $foreignFacility['center'],
            $foreignFacility['zone'],
            'Foreign'
        );

        /*
        * Primary owned rider receives three parcels
        * inside the selected period.
        */
        $first = $this->makeReportParcel(
            $ownProfile,
            'RIDER-O1'
        );

        $second = $this->makeReportParcel(
            $ownProfile,
            'RIDER-O2'
        );

        $third = $this->makeReportParcel(
            $ownProfile,
            'RIDER-O3'
        );

        $primaryBatch = $this->makeReportDispatchBatch(
            $ownFacility['center'],
            $ownFacility['zone'],
            $primaryRider,
            $ownUser,
            'OWN-A',
            '2026-09-15 08:00:00'
        );

        $primaryBatch->parcels()->attach([
            $first['parcel']->id => [
                'sequence' => 1,
                'loaded_at' => '2026-09-15 08:10:00',
            ],

            $second['parcel']->id => [
                'sequence' => 2,
                'loaded_at' => '2026-09-15 08:11:00',
            ],

            $third['parcel']->id => [
                'sequence' => 3,
                'loaded_at' => '2026-09-15 08:12:00',
            ],
        ]);

        /*
        * First parcel succeeds immediately.
        */
        $this->recordDeliveryAttempt(
            $first['parcel'],
            $primaryBatch,
            $primaryRider,
            1,
            DeliveryAttemptOutcome::Delivered,
            '2026-09-15 12:00:00'
        );

        /*
        * Second parcel fails once, then succeeds on retry.
        *
        * This should produce:
        * delivered = 2
        * failed = 1
        * rate = 66.7%
        */
        $this->recordDeliveryAttempt(
            $second['parcel'],
            $primaryBatch,
            $primaryRider,
            1,
            DeliveryAttemptOutcome::Failed,
            '2026-09-16 13:00:00'
        );

        $this->recordDeliveryAttempt(
            $second['parcel'],
            $primaryBatch,
            $primaryRider,
            2,
            DeliveryAttemptOutcome::Delivered,
            '2026-09-17 10:00:00'
        );

        /*
        * Second owned rider has an assigned parcel but no
        * resolved delivery attempt during the period.
        */
        $zeroParcel = $this->makeReportParcel(
            $ownProfile,
            'RIDER-ZERO'
        );

        $zeroBatch = $this->makeReportDispatchBatch(
            $ownFacility['center'],
            $ownFacility['zone'],
            $zeroOutcomeRider,
            $ownUser,
            'OWN-Z',
            '2026-09-18 09:00:00'
        );

        $zeroBatch->parcels()->attach(
            $zeroParcel['parcel']->id,
            [
                'sequence' => 1,
                'loaded_at' => '2026-09-18 09:10:00',
            ]
        );

        /*
        * Owned activity before the selected period must
        * not affect assigned or outcome counts.
        */
        $outsideRange = $this->makeReportParcel(
            $ownProfile,
            'RIDER-OLD'
        );

        $outsideBatch = $this->makeReportDispatchBatch(
            $ownFacility['center'],
            $ownFacility['zone'],
            $primaryRider,
            $ownUser,
            'OWN-OLD',
            '2026-09-13 08:00:00'
        );

        $outsideBatch->parcels()->attach(
            $outsideRange['parcel']->id,
            [
                'sequence' => 1,
                'loaded_at' => '2026-09-13 08:10:00',
            ]
        );

        $this->recordDeliveryAttempt(
            $outsideRange['parcel'],
            $outsideBatch,
            $primaryRider,
            1,
            DeliveryAttemptOutcome::Delivered,
            '2026-09-13 12:00:00'
        );

        /*
        * Foreign-provider rider activity inside the period
        * must not appear in the authenticated provider report.
        */
        $foreignParcel = $this->makeReportParcel(
            $foreignProfile,
            'RIDER-F1'
        );

        $foreignBatch = $this->makeReportDispatchBatch(
            $foreignFacility['center'],
            $foreignFacility['zone'],
            $foreignRider,
            $foreignUser,
            'FOREIGN',
            '2026-09-16 07:00:00'
        );

        $foreignBatch->parcels()->attach(
            $foreignParcel['parcel']->id,
            [
                'sequence' => 1,
                'loaded_at' => '2026-09-16 07:10:00',
            ]
        );

        $this->recordDeliveryAttempt(
            $foreignParcel['parcel'],
            $foreignBatch,
            $foreignRider,
            1,
            DeliveryAttemptOutcome::Delivered,
            '2026-09-16 11:00:00'
        );

        $response = $this
            ->actingAs($ownUser)
            ->get(
                route(
                    'logistics.reports.index',
                    [
                        'from' => '2026-09-14',
                        'to' => '2026-09-20',
                    ]
                )
            );

        $response
            ->assertOk()
            ->assertViewHas(
                'riderStats',
                function (array $items): bool {
                    $riders = collect($items)
                        ->keyBy('name');

                    if ($riders->count() !== 2) {
                        return false;
                    }

                    $primary = $riders->get(
                        'Report Rider Alpha'
                    );

                    $zero = $riders->get(
                        'Report Rider Zero'
                    );

                    if (! $primary || ! $zero) {
                        return false;
                    }

                    return
                        $primary['assigned'] === 3
                        && $primary['delivered'] === 2
                        && $primary['failed'] === 1
                        && $primary['rate'] === '66.7%'

                        && $zero['assigned'] === 1
                        && $zero['delivered'] === 0
                        && $zero['failed'] === 0
                        && $zero['rate'] === '0.0%'

                        && ! $riders->has(
                            'Report Rider Foreign'
                        );
                }
            );
    }

    public function test_reports_summary_is_zero_safe_without_activity(): void
    {
        $user = $this->makeLogisticsOperator(
            'ZERO',
            'reports-zero@example.test'
        );

        $response = $this
            ->actingAs($user)
            ->get(
                route(
                    'logistics.reports.index',
                    [
                        'from' => '2026-09-14',
                        'to' => '2026-09-20',
                    ]
                )
            );

        $response
            ->assertOk()
            ->assertViewHas(
                'summary',
                function (array $summary): bool {
                    return $summary['throughput'] === 0
                        && $summary['delivered'] === 0
                        && (float) $summary['success_rate']
                            === 0.0
                        && $summary[
                            'resolved_delivery_outcomes'
                        ] === 0
                        && $summary['avg_sort_time']
                            === '—';
                }
            );
    }

    private function makeLogisticsOperator(
        string $suffix = 'A',
        string $email = 'reports@example.test'
    ): User {
        $user = User::factory()->create([
            'name' =>
                "Reports Logistics Operator {$suffix}",

            'email' =>
                $email,

            'role' =>
                UserRole::Logistics->value,

            'status' =>
                AccountStatus::Active->value,

            'business_name' =>
                "Reports Logistics {$suffix}",
        ]);

        LogisticsProfile::query()->create([
            'user_id' =>
                $user->id,

            'legal_name' =>
                "Reports Logistics {$suffix} Incorporated",

            'display_name' =>
                "Reports Logistics {$suffix}",

            'contact_phone' =>
                '09170000000',

            'status' =>
                'active',
        ]);

        return $user;
    }

    /**
     * @return array{
     *     shipment: Shipment,
     *     parcel: Parcel
     * }
     */
    private function makeReportParcel(
        LogisticsProfile $profile,
        string $suffix
    ): array {
        $buyer = User::factory()->create([
            'role' =>
                UserRole::Buyer->value,

            'status' =>
                AccountStatus::Active->value,
        ]);

        $seller = User::factory()->create([
            'role' =>
                UserRole::Seller->value,

            'status' =>
                AccountStatus::Active->value,
        ]);

        $sellerProfile =
            SellerProfile::query()->create([
                'user_id' =>
                    $seller->id,

                'legal_business_name' =>
                    "Report Seller {$suffix}",

                'standing_status' =>
                    'good_standing',
            ]);

        $store = Store::query()->create([
            'seller_profile_id' =>
                $sellerProfile->id,

            'name' =>
                "Report Store {$suffix}",

            'slug' =>
                'report-store-'.strtolower($suffix),

            'publication_status' =>
                'published',
        ]);

        $order = Order::query()->create([
            'order_no' =>
                "REPORT-ORDER-{$suffix}",

            'buyer_id' =>
                $buyer->id,

            'recipient_name' =>
                'Report Buyer',

            'recipient_phone' =>
                '09171234567',

            'address_line' =>
                '123 Report Street',

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
                    "REPORT-SO-{$suffix}",

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

        $shipment = Shipment::query()->create([
            'shipment_no' =>
                "REPORT-SHIP-{$suffix}",

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
                "REPORT-WB-{$suffix}",

            'scan_token' =>
                hash(
                    'sha256',
                    "report-scan-{$suffix}"
                ),

            'barcode_value' =>
                "REPORT-BARCODE-{$suffix}",

            'piece_count' =>
                1,

            'total_weight_kg' =>
                1.250,

            'status' =>
                'generated',

            'generated_at' =>
                now(),
        ]);

        $parcel = Parcel::query()->create([
            'shipment_id' =>
                $shipment->id,

            'waybill_id' =>
                $waybill->id,

            'parcel_no' =>
                "REPORT-PARCEL-{$suffix}",

            'piece_sequence' =>
                1,

            'weight_kg' =>
                1.250,

            'status' =>
                ParcelStatus::Received->value,
        ]);

        return [
            'shipment' => $shipment,
            'parcel' => $parcel,
        ];
    }

    private function recordParcelEvent(
        Shipment $shipment,
        Parcel $parcel,
        string $eventType,
        string $toStatus,
        string $occurredAt
    ): ShipmentEvent {
        return ShipmentEvent::query()->create([
            'shipment_id' =>
                $shipment->id,

            'parcel_id' =>
                $parcel->id,

            'event_type' =>
                $eventType,

            'to_status' =>
                $toStatus,

            'source' =>
                'system',

            'occurred_at' =>
                $occurredAt,
        ]);
    }

    /**
     * @return array{
     *     center: SortingCenter,
     *     zone: SortingZone
     * }
     */
    private function makeReportFacility(
        User $user,
        LogisticsProfile $profile,
        string $suffix
    ): array {
        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => "Report Sorting Center {$suffix}",
            'recipient_name' => $user->name,
            'phone' => $profile->contact_phone,
            'house_number' => '10',
            'street' => "Report Hub Road {$suffix}",
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
        ]);

        $center = SortingCenter::query()->create([
            'logistics_profile_id' => $profile->id,
            'address_id' => $address->id,
            'name' => "Report Sorting Center {$suffix}",
            'code' => "REPORT-CENTER-{$suffix}",
            'contact_phone' => $profile->contact_phone,
            'status' => 'active',
        ]);

        $zone = SortingZone::query()->create([
            'sorting_center_id' => $center->id,
            'code' => "REPORT-ZONE-{$suffix}",
            'name' => "Report Zone {$suffix}",
            'destination_rules' => [],
            'status' => 'active',
        ]);

        return [
            'center' => $center,
            'zone' => $zone,
        ];
    }

    private function makeReportRider(
        LogisticsProfile $profile,
        SortingCenter $center,
        SortingZone $zone,
        string $suffix
    ): RiderProfile {
        $user = User::factory()->create([
            'name' => "Report Rider {$suffix}",

            'email' =>
                'report-rider-'
                .strtolower($suffix)
                .'-'
                .$profile->id
                .'@example.test',

            'role' =>
                UserRole::Rider->value,

            'status' =>
                AccountStatus::Active->value,

            'vehicle_type' =>
                'Motorcycle',

            'plate_number' =>
                "REPORT-{$suffix}-{$profile->id}",
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
                "REPORT-{$suffix}-{$profile->id}",

            'parcel_capacity' =>
                20,

            'availability_status' =>
                'available',

            'verification_status' =>
                'approved',
        ]);
    }

    private function makeReportDispatchBatch(
        SortingCenter $center,
        SortingZone $zone,
        RiderProfile $rider,
        User $operator,
        string $suffix,
        string $assignedAt
    ): DispatchBatch {
        return DispatchBatch::query()->create([
            'batch_no' =>
                "REPORT-BATCH-{$suffix}",

            'sorting_center_id' =>
                $center->id,

            'sorting_zone_id' =>
                $zone->id,

            'rider_profile_id' =>
                $rider->id,

            'status' =>
                'dispatched',

            'prepared_by' =>
                $operator->id,

            'prepared_at' =>
                $assignedAt,

            'assigned_at' =>
                $assignedAt,

            'dispatched_at' =>
                $assignedAt,
        ]);
    }

    private function recordDeliveryAttempt(
        Parcel $parcel,
        DispatchBatch $batch,
        RiderProfile $rider,
        int $attemptNo,
        DeliveryAttemptOutcome $outcome,
        string $attemptedAt
    ): DeliveryAttempt {
        return DeliveryAttempt::query()->create([
            'parcel_id' =>
                $parcel->id,

            'dispatch_batch_id' =>
                $batch->id,

            'rider_profile_id' =>
                $rider->id,

            'attempt_no' =>
                $attemptNo,

            'outcome' =>
                $outcome->value,

            'failure_reason' =>
                $outcome === DeliveryAttemptOutcome::Failed
                    ? 'recipient_unavailable'
                    : null,

            'attempted_at' =>
                $attemptedAt,
        ]);
    }
}