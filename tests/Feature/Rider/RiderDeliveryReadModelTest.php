<?php

namespace Tests\Feature\Rider;

use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Models\Address;
use App\Models\DeliveryAttempt;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderDeliveryReadModelTest extends TestCase
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

    /**
     * @return array{
     *     0: User,
     *     1: LogisticsProfile,
     *     2: SortingCenter
     * }
     */
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