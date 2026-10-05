<?php

namespace Tests\Feature\Rider;

use App\Enums\AccountStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\PickupAssignment;
use App\Models\PickupRequest;
use App\Models\RiderProfile;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderPickupWorkflowTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_pickup_acceptance_cannot_be_repeated(): void
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
            ->assertStatus(409);

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
                'DETAIL'
            );

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
                'Use the loading bay.'
            );
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
                'name' =>
                    'Rider Without Profile',

                'email' =>
                    'rider-no-profile@example.test',

                'role' =>
                    UserRole::Rider->value,

                'status' =>
                    AccountStatus::Active->value,
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

    private function makeProvider(
        string $suffix
    ): array {
        $user =
            User::factory()->create([
                'name' =>
                    "Logistics {$suffix}",

                'email' =>
                    'rider-pickup-logistics-'
                    .strtolower($suffix)
                    .'@example.test',

                'role' =>
                    UserRole::Logistics->value,

                'status' =>
                    AccountStatus::Active->value,

                'business_name' =>
                    "Logistics {$suffix}",
            ]);

        $profile =
            LogisticsProfile::query()
                ->create([
                    'user_id' =>
                        $user->id,

                    'legal_name' =>
                        "Logistics {$suffix} Inc.",

                    'display_name' =>
                        "Logistics {$suffix}",

                    'contact_phone' =>
                        '09170000001',

                    'status' =>
                        'active',
                ]);

        return compact(
            'user',
            'profile'
        );
    }

    private function makeRider(
        array $provider,
        string $suffix
    ): RiderProfile {
        $user =
            User::factory()->create([
                'name' =>
                    "Pickup Rider {$suffix}",

                'email' =>
                    'rider-pickup-'
                    .strtolower($suffix)
                    .'@example.test',

                'role' =>
                    UserRole::Rider->value,

                'status' =>
                    AccountStatus::Active->value,

                'logistics_id' =>
                    $provider['user']->id,

                'vehicle_type' =>
                    'Motorcycle',

                'plate_number' =>
                    "RP-{$suffix}",
            ]);

        return RiderProfile::query()
            ->create([
                'user_id' =>
                    $user->id,

                'logistics_profile_id' =>
                    $provider['profile']->id,

                'vehicle_type' =>
                    'Motorcycle',

                'plate_number' =>
                    "RP-{$suffix}",

                'availability_status' =>
                    'available',

                'verification_status' =>
                    'approved',
            ]);
    }

    private function makeSeller(
        string $suffix
    ): array {
        $user =
            User::factory()->create([
                'name' =>
                    "Pickup Seller {$suffix}",

                'email' =>
                    'rider-pickup-seller-'
                    .strtolower($suffix)
                    .'@example.test',

                'role' =>
                    UserRole::Seller->value,

                'status' =>
                    AccountStatus::Active->value,

                'contact_number' =>
                    '09171111111',
            ]);

        $address =
            Address::query()->create([
                'user_id' =>
                    $user->id,

                'label' =>
                    'Pickup',

                'recipient_name' =>
                    $user->name,

                'phone' =>
                    '09171111111',

                'house_number' =>
                    '12',

                'street' =>
                    "Pickup {$suffix} Street",

                'barangay' =>
                    'San Rafael',

                'city_municipality' =>
                    'San Pablo City',

                'province' =>
                    'Laguna',

                'postal_code' =>
                    '4000',

                'is_default_pickup' =>
                    true,
            ]);

        $profile =
            SellerProfile::query()->create([
                'user_id' =>
                    $user->id,

                'legal_business_name' =>
                    "Pickup Seller {$suffix}",

                'pickup_address_id' =>
                    $address->id,

                'approved_at' =>
                    now(),
            ]);

        $store =
            Store::query()->create([
                'seller_profile_id' =>
                    $profile->id,

                'name' =>
                    "Pickup Store {$suffix}",

                'slug' =>
                    'pickup-store-'
                    .strtolower($suffix),

                'contact_phone' =>
                    '09171111111',

                'publication_status' =>
                    'published',

                'published_at' =>
                    now(),
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
                'email' =>
                    'rider-pickup-buyer-'
                    .strtolower($suffix)
                    .'@example.test',

                'role' =>
                    UserRole::Buyer->value,

                'status' =>
                    AccountStatus::Active->value,
            ]);

        $order =
            Order::query()->create([
                'order_no' =>
                    "RIDER-PICKUP-ORDER-{$suffix}",

                'buyer_id' =>
                    $buyer->id,

                'recipient_name' =>
                    $buyer->name,

                'recipient_phone' =>
                    '09172222222',

                'address_line' =>
                    'Buyer Street',

                'barangay' =>
                    'San Roque',

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
                    "RIDER-PICKUP-SO-{$suffix}",

                'order_id' =>
                    $order->id,

                'store_id' =>
                    $seller['store']->id,

                'status' =>
                    'ready_for_pickup',

                'subtotal_minor' =>
                    10000,

                'total_minor' =>
                    10000,

                'ready_at' =>
                    now(),
            ]);

        $shipment =
            Shipment::query()->create([
                'shipment_no' =>
                    "RIDER-PICKUP-SHIP-{$suffix}",

                'seller_order_id' =>
                    $sellerOrder->id,

                'logistics_profile_id' =>
                    $provider['profile']->id,

                'origin_address_id' =>
                    $seller['address']->id,

                'purpose' =>
                    'outbound',

                'status' =>
                    ShipmentStatus::PickupAssigned->value,

                'shipping_fee_minor' =>
                    0,

                'cod_amount_minor' =>
                    0,
            ]);

        $waybill =
            Waybill::query()->create([
                'shipment_id' =>
                    $shipment->id,

                'waybill_no' =>
                    "RIDER-PICKUP-WB-{$suffix}",

                'scan_token' =>
                    hash(
                        'sha256',
                        "rider-pickup-scan-{$suffix}"
                    ),

                'barcode_value' =>
                    "RIDER-PICKUP-BARCODE-{$suffix}",

                'piece_count' =>
                    1,

                'total_weight_kg' =>
                    1.250,

                'status' =>
                    'printed',

                'generated_at' =>
                    now(),

                'printed_at' =>
                    now(),

                'print_count' =>
                    1,
            ]);

        $parcel =
            Parcel::query()->create([
                'shipment_id' =>
                    $shipment->id,

                'waybill_id' =>
                    $waybill->id,

                'parcel_no' =>
                    "RIDER-PICKUP-PARCEL-{$suffix}",

                'piece_sequence' =>
                    1,

                'weight_kg' =>
                    1.250,

                'size_class' =>
                    'small',

                'status' =>
                    'ready_for_pickup',
            ]);

        $pickup =
            PickupRequest::query()->create([
                'pickup_no' =>
                    "RIDER-PICKUP-{$suffix}",

                'store_id' =>
                    $seller['store']->id,

                'logistics_profile_id' =>
                    $provider['profile']->id,

                'pickup_address_id' =>
                    $seller['address']->id,

                'status' =>
                    $assignmentStatus === 'completed'
                        ? 'completed'
                        : 'scheduled',

                'requested_date' =>
                    today(),

                'window_start' =>
                    now()->addHour(),

                'window_end' =>
                    now()->addHours(2),

                'seller_instructions' =>
                    'Use the loading bay.',

                'completed_at' =>
                    $assignmentStatus === 'completed'
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
                    'pickup_request_id' =>
                        $pickup->id,

                    'rider_profile_id' =>
                        $rider->id,

                    'status' =>
                        $assignmentStatus,

                    'assigned_by' =>
                        $provider['user']->id,

                    'assigned_at' =>
                        now(),

                    'notes' =>
                        'Return parcels to the assigned sorting center.',

                    'completed_at' =>
                        $assignmentStatus === 'completed'
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