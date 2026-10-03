<?php

namespace Tests\Feature\Logistics;

use App\Enums\AccountStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\PickupRequest;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PickupRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_logistics_lists_only_own_pickups_with_real_fulfillment_data(): void
    {
        $provider = $this->makeProvider('A');
        $foreignProvider = $this->makeProvider('B');
        $seller = $this->makeSeller('OWN');
        $foreignSeller = $this->makeSeller('FOREIGN');

        $own = $this->makePickup(
            $provider,
            $seller,
            'OWN'
        );
        $foreign = $this->makePickup(
            $foreignProvider,
            $foreignSeller,
            'FOREIGN'
        );

        $response = $this
            ->actingAs($provider['user'])
            ->get(route('logistics.pickups.index'));

        $response
            ->assertOk()
            ->assertSee($own['pickup']->pickup_no)
            ->assertSee($seller['store']->name)
            ->assertSee($own['parcel']->parcel_no)
            ->assertSee($own['waybill']->waybill_no)
            ->assertSee($own['shipment']->shipment_no)
            ->assertSee($own['sellerOrder']->seller_order_no)
            ->assertDontSee($foreign['pickup']->pickup_no)
            ->assertDontSee($foreignSeller['store']->name)
            ->assertDontSee('PU-24091');
    }

    public function test_owned_pickup_does_not_expose_or_mutate_foreign_provider_parcel(): void
    {
        $provider = $this->makeProvider('A');
        $foreignProvider = $this->makeProvider('B');
        $seller = $this->makeSeller('MIXED');
        $foreignSeller = $this->makeSeller('HIDDEN');
        $own = $this->makePickup($provider, $seller, 'MIXED', 'verified');
        $foreign = $this->makePickup(
            $foreignProvider,
            $foreignSeller,
            'HIDDEN'
        );

        $own['pickup']->parcels()->attach(
            $foreign['parcel']->id
        );

        $response = $this
            ->actingAs($provider['user'])
            ->get(route('logistics.pickups.index'));

        $response
            ->assertOk()
            ->assertSee($own['parcel']->parcel_no)
            ->assertDontSee($foreign['parcel']->parcel_no)
            ->assertDontSee($foreign['shipment']->shipment_no);

        $rider = $this->makeRider($provider, 'MIXED');

        $this
            ->actingAs($provider['user'])
            ->post(
                route(
                    'logistics.pickups.assign',
                    $own['pickup']
                ),
                ['rider_profile_id' => $rider->id]
            )
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ShipmentStatus::PickupAssigned->value,
            $own['shipment']->fresh()->status
        );
        $this->assertSame(
            ShipmentStatus::ReadyForPickup->value,
            $foreign['shipment']->fresh()->status
        );
    }

    public function test_logistics_can_verify_own_requested_pickup(): void
    {
        $provider = $this->makeProvider('VERIFY');
        $seller = $this->makeSeller('VERIFY');
        $pickup = $this->makePickup(
            $provider,
            $seller,
            'VERIFY'
        )['pickup'];

        $this
            ->actingAs($provider['user'])
            ->post(route('logistics.pickups.verify', $pickup))
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'success',
                "{$pickup->pickup_no} was verified."
            );

        $pickup->refresh();

        $this->assertSame('verified', $pickup->status);
        $this->assertSame(
            $provider['user']->id,
            $pickup->verified_by
        );
        $this->assertNotNull($pickup->verified_at);
    }

    public function test_logistics_cannot_view_or_change_foreign_pickup(): void
    {
        $provider = $this->makeProvider('OWNER');
        $foreignProvider = $this->makeProvider('FOREIGN');
        $seller = $this->makeSeller('FOREIGN-ACTION');
        $pickup = $this->makePickup(
            $foreignProvider,
            $seller,
            'FOREIGN-ACTION'
        )['pickup'];

        $this
            ->actingAs($provider['user'])
            ->post(route('logistics.pickups.verify', $pickup))
            ->assertNotFound();

        $this
            ->actingAs($provider['user'])
            ->patch(
                route('logistics.pickups.cancel', $pickup),
                ['cancellation_reason' => 'Not ours.']
            )
            ->assertNotFound();

        $this->assertSame(
            'requested',
            $pickup->fresh()->status
        );
    }

    public function test_pickup_status_transitions_are_enforced(): void
    {
        $provider = $this->makeProvider('TRANSITION');
        $seller = $this->makeSeller('TRANSITION');
        $pickup = $this->makePickup(
            $provider,
            $seller,
            'TRANSITION',
            'completed'
        )['pickup'];

        $this
            ->actingAs($provider['user'])
            ->post(route('logistics.pickups.verify', $pickup))
            ->assertStatus(409);

        $this
            ->actingAs($provider['user'])
            ->patch(
                route('logistics.pickups.cancel', $pickup),
                ['cancellation_reason' => 'Too late.']
            )
            ->assertStatus(409);

        $this->assertSame(
            'completed',
            $pickup->fresh()->status
        );
    }

    public function test_rejecting_pickup_requires_and_persists_reason(): void
    {
        $provider = $this->makeProvider('REJECT');
        $seller = $this->makeSeller('REJECT');
        $pickup = $this->makePickup(
            $provider,
            $seller,
            'REJECT'
        )['pickup'];

        $this
            ->actingAs($provider['user'])
            ->patch(route('logistics.pickups.cancel', $pickup))
            ->assertSessionHasErrors('cancellation_reason');

        $this
            ->actingAs($provider['user'])
            ->patch(
                route('logistics.pickups.cancel', $pickup),
                ['cancellation_reason' => 'Parcel count mismatch.']
            )
            ->assertSessionHasNoErrors();

        $pickup->refresh();

        $this->assertSame('cancelled', $pickup->status);
        $this->assertSame(
            'Parcel count mismatch.',
            $pickup->cancellation_reason
        );
        $this->assertNotNull($pickup->cancelled_at);
    }

    public function test_verified_pickup_can_be_assigned_to_owned_available_rider(): void
    {
        $provider = $this->makeProvider('ASSIGN');
        $seller = $this->makeSeller('ASSIGN');
        $fulfillment = $this->makePickup(
            $provider,
            $seller,
            'ASSIGN',
            'verified'
        );
        $rider = $this->makeRider($provider, 'ASSIGN');

        $this
            ->actingAs($provider['user'])
            ->post(
                route(
                    'logistics.pickups.assign',
                    $fulfillment['pickup']
                ),
                [
                    'rider_profile_id' => $rider->id,
                    'notes' => 'Use the seller loading bay.',
                ]
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('pickup_assignments', [
            'pickup_request_id' =>
                $fulfillment['pickup']->id,
            'rider_profile_id' => $rider->id,
            'status' => 'assigned',
            'assigned_by' => $provider['user']->id,
            'notes' => 'Use the seller loading bay.',
        ]);

        $this->assertSame(
            'scheduled',
            $fulfillment['pickup']->fresh()->status
        );
        $this->assertSame(
            ShipmentStatus::PickupAssigned->value,
            $fulfillment['shipment']->fresh()->status
        );
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $fulfillment['shipment']->id,
            'event_type' => 'pickup_assigned',
            'from_status' =>
                ShipmentStatus::ReadyForPickup->value,
            'to_status' =>
                ShipmentStatus::PickupAssigned->value,
            'actor_user_id' => $provider['user']->id,
            'source' => 'logistics',
        ]);
    }

    public function test_pickup_cannot_be_assigned_to_foreign_or_unavailable_rider(): void
    {
        $provider = $this->makeProvider('A');
        $foreignProvider = $this->makeProvider('B');
        $seller = $this->makeSeller('RIDER-SCOPE');
        $pickup = $this->makePickup(
            $provider,
            $seller,
            'RIDER-SCOPE',
            'verified'
        )['pickup'];
        $foreignRider = $this->makeRider(
            $foreignProvider,
            'FOREIGN'
        );
        $unavailableRider = $this->makeRider(
            $provider,
            'OFFLINE',
            'offline'
        );

        $this
            ->actingAs($provider['user'])
            ->post(
                route('logistics.pickups.assign', $pickup),
                ['rider_profile_id' => $foreignRider->id]
            )
            ->assertNotFound();

        $this
            ->actingAs($provider['user'])
            ->post(
                route('logistics.pickups.assign', $pickup),
                ['rider_profile_id' => $unavailableRider->id]
            )
            ->assertNotFound();

        $this->assertSame(
            'verified',
            $pickup->fresh()->status
        );
        $this->assertDatabaseCount('pickup_assignments', 0);
    }

    public function test_pickup_ui_wires_status_actions_metrics_and_owned_available_riders(): void
    {
        $provider = $this->makeProvider('UI');
        $foreignProvider = $this->makeProvider('UI-FOREIGN');
        $seller = $this->makeSeller('UI');
        $pending = $this->makePickup(
            $provider,
            $seller,
            'UI-PENDING'
        )['pickup'];
        $verified = $this->makePickup(
            $provider,
            $seller,
            'UI-VERIFIED',
            'verified'
        )['pickup'];
        $availableRider = $this->makeRider(
            $provider,
            'UI-AVAILABLE'
        );
        $foreignRider = $this->makeRider(
            $foreignProvider,
            'UI-HIDDEN'
        );

        $response = $this
            ->actingAs($provider['user'])
            ->get(route('logistics.pickups.index'));

        $response
            ->assertOk()
            ->assertViewHas(
                'pickupMetrics',
                fn (array $metrics) =>
                    $metrics['awaiting_review'] === 1
            )
            ->assertSee(
                route('logistics.pickups.verify', $pending),
                false
            )
            ->assertSee(
                route('logistics.pickups.cancel', $pending),
                false
            )
            ->assertSee(
                route('logistics.pickups.assign', $verified),
                false
            )
            ->assertSee($availableRider->user->name)
            ->assertDontSee($foreignRider->user->name)
            ->assertSee('Pickup requests are submitted by Sellers.')
            ->assertDontSee('data-preview-form="manualPickup"', false)
            ->assertDontSee('data-set-status=', false);
    }

    public function test_dashboard_uses_owned_pending_pickup_count(): void
    {
        $provider = $this->makeProvider('DASHBOARD');
        $foreignProvider = $this->makeProvider('DASHBOARD-FOREIGN');
        $seller = $this->makeSeller('DASHBOARD');
        $foreignSeller = $this->makeSeller('DASHBOARD-FOREIGN');

        $this->makePickup(
            $provider,
            $seller,
            'DASHBOARD-PENDING'
        );
        $this->makePickup(
            $provider,
            $seller,
            'DASHBOARD-VERIFIED',
            'verified'
        );
        $this->makePickup(
            $foreignProvider,
            $foreignSeller,
            'DASHBOARD-HIDDEN'
        );

        $this
            ->actingAs($provider['user'])
            ->get(route('logistics.dashboard'))
            ->assertOk()
            ->assertSee('1 pickup request')
            ->assertDontSee('2 pickup requests');
    }

    private function makeProvider(string $suffix): array
    {
        $user = User::factory()->create([
            'name' => "Logistics {$suffix}",
            'email' =>
                'logistics-'.strtolower($suffix).'@example.test',
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
            'business_name' => "Logistics {$suffix}",
        ]);

        $user->roles()->syncWithoutDetaching([
            Role::where('name', UserRole::Logistics->value)
                ->value('id'),
        ]);

        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => "Logistics {$suffix} Inc.",
            'display_name' => "Logistics {$suffix}",
            'contact_phone' => '09170000001',
            'status' => 'active',
        ]);

        return compact('user', 'profile');
    }

    private function makeSeller(string $suffix): array
    {
        $user = User::factory()->create([
            'name' => "Seller {$suffix}",
            'email' =>
                'seller-'.strtolower($suffix).'@example.test',
            'role' => UserRole::Seller->value,
            'status' => AccountStatus::Active->value,
            'contact_number' => '09171111111',
        ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Pickup',
            'recipient_name' => $user->name,
            'phone' => '09171111111',
            'house_number' => '12',
            'street' => "Seller {$suffix} Street",
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
            'is_default_pickup' => true,
        ]);

        $profile = SellerProfile::query()->create([
            'user_id' => $user->id,
            'legal_business_name' => "Seller {$suffix} Shop",
            'pickup_address_id' => $address->id,
            'approved_at' => now(),
        ]);

        $store = Store::query()->create([
            'seller_profile_id' => $profile->id,
            'name' => "Store {$suffix}",
            'slug' => 'store-'.strtolower($suffix),
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

    private function makeRider(
        array $provider,
        string $suffix,
        string $availability = 'available'
    ): RiderProfile {
        $user = User::factory()->create([
            'name' => "Rider {$suffix}",
            'email' =>
                'pickup-rider-'.strtolower($suffix).'@example.test',
            'role' => UserRole::Rider->value,
            'status' => AccountStatus::Active->value,
            'logistics_id' => $provider['user']->id,
        ]);

        return RiderProfile::query()->create([
            'user_id' => $user->id,
            'logistics_profile_id' => $provider['profile']->id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => "RIDER-{$suffix}",
            'availability_status' => $availability,
            'verification_status' => 'approved',
        ]);
    }

    private function makePickup(
        array $provider,
        array $seller,
        string $suffix,
        string $status = 'requested'
    ): array {
        $buyer = User::factory()->create([
            'email' =>
                'buyer-'.strtolower($suffix).'@example.test',
            'role' => UserRole::Buyer->value,
            'status' => AccountStatus::Active->value,
        ]);

        $order = Order::query()->create([
            'order_no' => "ORDER-{$suffix}",
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
            'status' => 'confirmed',
            'payment_status' => 'paid',
        ]);

        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => "SELLER-ORDER-{$suffix}",
            'order_id' => $order->id,
            'store_id' => $seller['store']->id,
            'status' => 'ready_for_pickup',
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
            'ready_at' => now(),
        ]);

        $shipment = Shipment::query()->create([
            'shipment_no' => "SHIPMENT-{$suffix}",
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider['profile']->id,
            'origin_address_id' => $seller['address']->id,
            'purpose' => 'outbound',
            'status' => ShipmentStatus::ReadyForPickup->value,
        ]);

        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => "WAYBILL-{$suffix}",
            'scan_token' => hash('sha256', "scan-{$suffix}"),
            'barcode_value' => "BARCODE-{$suffix}",
            'piece_count' => 1,
            'total_weight_kg' => 1.250,
            'status' => 'printed',
            'generated_at' => now(),
            'printed_at' => now(),
            'print_count' => 1,
        ]);

        $parcel = Parcel::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_id' => $waybill->id,
            'parcel_no' => "PARCEL-{$suffix}",
            'piece_sequence' => 1,
            'weight_kg' => 1.250,
            'status' => 'ready_for_pickup',
        ]);

        $pickup = PickupRequest::query()->create([
            'pickup_no' => "PICKUP-{$suffix}",
            'store_id' => $seller['store']->id,
            'logistics_profile_id' => $provider['profile']->id,
            'pickup_address_id' => $seller['address']->id,
            'status' => $status,
            'requested_date' => today(),
            'window_start' => now()->addHour(),
            'window_end' => now()->addHours(2),
            'seller_instructions' => 'Use the loading bay.',
            'completed_at' => $status === 'completed'
                ? now()
                : null,
        ]);

        $pickup->parcels()->attach($parcel->id);

        return compact(
            'order',
            'sellerOrder',
            'shipment',
            'waybill',
            'parcel',
            'pickup'
        );
    }
}
