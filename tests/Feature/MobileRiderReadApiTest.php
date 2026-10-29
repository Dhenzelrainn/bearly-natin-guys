<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\AccountApplication;
use App\Models\ApplicationDocument;
use App\Models\DeliveryAttempt;
use App\Models\DispatchBatch;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\PickupAssignment;
use App\Models\PickupRequest;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileRiderReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_and_assigned_pickup_jobs_use_the_authenticated_rider(): void
    {
        $provider = $this->makeProvider('pickup-scope');
        $rider = $this->makeRider($provider, 'pickup-owner');
        $otherRider = $this->makeRider($provider, 'pickup-other');
        $pickup = $this->makePickup($provider, $rider, 'OWNED-PICKUP');
        $otherPickup = $this->makePickup($provider, $otherRider, 'OTHER-PICKUP');

        Sanctum::actingAs($rider['user']);

        $this->getJson('/api/mobile/rider/dashboard')
            ->assertOk()
            ->assertJsonPath('rider.name', $rider['user']->name)
            ->assertJsonPath('metrics.assigned_pickups', 1);

        $this->getJson('/api/mobile/rider/pickups')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pickup->pickup_no);

        $this->getJson("/api/mobile/rider/pickups/{$pickup->pickup_no}")
            ->assertOk()
            ->assertJsonPath('data.id', $pickup->pickup_no)
            ->assertJsonPath('data.title', $pickup->store->name);

        $this->getJson("/api/mobile/rider/pickups/{$otherPickup->pickup_no}")
            ->assertNotFound();

        $this->assertDatabaseHas('pickup_assignments', [
            'pickup_request_id' => $pickup->id,
            'rider_profile_id' => $rider['profile']->id,
            'status' => 'assigned',
        ]);
        $this->assertDatabaseHas('pickup_assignments', [
            'pickup_request_id' => $otherPickup->id,
            'rider_profile_id' => $otherRider['profile']->id,
            'status' => 'assigned',
        ]);
    }

    public function test_assigned_delivery_jobs_and_details_are_scoped_to_the_rider(): void
    {
        $provider = $this->makeProvider('delivery-scope');
        $rider = $this->makeRider($provider, 'delivery-owner');
        $otherRider = $this->makeRider($provider, 'delivery-other');
        $delivery = $this->makeDelivery($provider, $rider, 'OWNED-DELIVERY');
        $otherDelivery = $this->makeDelivery($provider, $otherRider, 'OTHER-DELIVERY');

        Sanctum::actingAs($rider['user']);

        $this->getJson('/api/mobile/rider/deliveries')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $delivery['shipment']->shipment_no)
            ->assertJsonPath('data.0.status', 'Assigned');

        $this->getJson("/api/mobile/rider/deliveries/{$delivery['shipment']->shipment_no}")
            ->assertOk()
            ->assertJsonPath('data.id', $delivery['shipment']->shipment_no)
            ->assertJsonPath('data.parcels.0.parcel_no', $delivery['parcel']->parcel_no);

        $this->getJson("/api/mobile/rider/deliveries/{$otherDelivery['shipment']->shipment_no}")
            ->assertNotFound();

        $this->assertDatabaseHas('dispatch_batches', [
            'id' => $delivery['batch']->id,
            'rider_profile_id' => $rider['profile']->id,
            'status' => 'dispatched',
        ]);
        $this->assertDatabaseHas('dispatch_batches', [
            'id' => $otherDelivery['batch']->id,
            'rider_profile_id' => $otherRider['profile']->id,
            'status' => 'dispatched',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $delivery['order']->id,
            'status' => 'pending_payment',
            'total_minor' => 10000,
        ]);
        $this->assertDatabaseHas('shipments', [
            'id' => $delivery['shipment']->id,
            'status' => 'pending_waybill',
        ]);
    }

    public function test_unapproved_rider_profiles_cannot_use_rider_mobile_routes(): void
    {
        $provider = $this->makeProvider('approval');
        $rider = $this->makeRider($provider, 'unapproved', 'pending');
        Sanctum::actingAs($rider['user']);

        $this->getJson('/api/mobile/rider/dashboard')->assertForbidden();
        $this->getJson('/api/mobile/rider/pickups')->assertForbidden();
        $this->getJson('/api/mobile/rider/account')->assertForbidden();
        $this->getJson('/api/mobile/rider/history')->assertForbidden();
    }

    public function test_buyer_tokens_cannot_access_rider_mobile_routes(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);
        Sanctum::actingAs($buyer);

        $this->getJson('/api/mobile/rider/dashboard')->assertForbidden();
        $this->getJson('/api/mobile/rider/pickups')->assertForbidden();
        $this->getJson('/api/mobile/rider/account')->assertForbidden();
        $this->getJson('/api/mobile/rider/history')->assertForbidden();
    }

    public function test_mobile_account_returns_only_the_authenticated_riders_profile_and_safe_document_statuses(): void
    {
        $provider = $this->makeProvider('account-display');
        $provider['profile']->update(['display_name' => 'North Logistics Hub']);
        $rider = $this->makeRider($provider, 'account-owner');
        $otherRider = $this->makeRider($provider, 'account-other');
        $rider['profile']->update([
            'vehicle_model' => 'Click 160',
            'parcel_capacity' => 18,
        ]);
        $address = $this->makeAddress($rider['user'], 'Home');
        $address->update([
            'house_number' => '10',
            'street' => 'Test Road',
            'postal_code' => '4012',
        ]);
        $homeCenter = SortingCenter::query()->create([
            'logistics_profile_id' => $provider['profile']->id,
            'address_id' => $address->id,
            'name' => 'Rider Home Center',
            'code' => 'RHC-1',
        ]);
        $zone = SortingZone::query()->create([
            'sorting_center_id' => $homeCenter->id,
            'name' => 'Lumban Zone',
            'code' => 'LUM-1',
        ]);
        $rider['profile']->update([
            'home_sorting_center_id' => $homeCenter->id,
            'current_zone_id' => $zone->id,
        ]);

        $role = Role::query()->create([
            'name' => 'rider',
            'display_name' => 'Rider',
        ]);
        $application = AccountApplication::query()->create([
            'application_no' => 'APP-RIDER-ACCOUNT',
            'user_id' => $rider['user']->id,
            'requested_role_id' => $role->id,
            'status' => 'approved',
        ]);
        ApplicationDocument::query()->create([
            'application_id' => $application->id,
            'document_type' => 'or_cr',
            'file_path' => 'private/should-not-be-returned/or-cr.pdf',
            'original_name' => 'or-cr.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'verification_status' => 'approved',
        ]);
        ApplicationDocument::query()->create([
            'application_id' => $application->id,
            'document_type' => 'driver_license',
            'file_path' => 'private/should-not-be-returned/license.pdf',
            'original_name' => 'license.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'verification_status' => 'approved',
        ]);

        Sanctum::actingAs($rider['user']);

        $response = $this->getJson('/api/mobile/rider/account')
            ->assertOk()
            ->assertJsonPath('data.name', $rider['user']->name)
            ->assertJsonPath('data.email', $rider['user']->email)
            ->assertJsonPath('data.vehicle.model', 'Click 160')
            ->assertJsonPath('data.vehicle.parcel_capacity', 18)
            ->assertJsonPath('data.logistics_provider.name', 'North Logistics Hub')
            ->assertJsonPath('data.sorting.home_center', 'Rider Home Center')
            ->assertJsonPath('data.sorting.current_zone', 'Lumban Zone')
            ->assertJsonPath('data.sorting.current_zone_center', 'Rider Home Center')
            ->assertJsonPath('data.addresses.0.label', 'Home')
            ->assertJsonPath('data.documents.valid_id', 'not_provided')
            ->assertJsonPath('data.documents.or_cr', 'approved')
            ->assertJsonPath('data.documents.driver_license', 'approved')
            ->assertJsonMissingPath('data.documents.or_cr.file_path')
            ->assertJsonMissingPath('data.addresses.0.phone');

        $this->getJson('/api/mobile/rider/dashboard')
            ->assertOk()
            ->assertJsonPath('rider.logistics_provider', 'North Logistics Hub')
            ->assertJsonPath('rider.home_sorting_center', 'Rider Home Center')
            ->assertJsonPath('rider.current_zone', 'Lumban Zone')
            ->assertJsonPath('rider.current_zone_center', 'Rider Home Center');

        $this->assertStringNotContainsString(
            'private/should-not-be-returned',
            $response->getContent(),
        );

        Sanctum::actingAs($otherRider['user']);
        $this->getJson('/api/mobile/rider/account')
            ->assertOk()
            ->assertJsonPath('data.name', $otherRider['user']->name)
            ->assertJsonPath('data.email', $otherRider['user']->email)
            ->assertJsonPath('data.addresses', [])
            ->assertJsonPath('data.documents.or_cr', 'not_provided');
    }

    public function test_mobile_account_handles_missing_optional_rider_data(): void
    {
        $provider = $this->makeProvider('account-empty');
        $rider = $this->makeRider($provider, 'account-empty');
        Sanctum::actingAs($rider['user']);

        $this->getJson('/api/mobile/rider/account')
            ->assertOk()
            ->assertJsonPath('data.sorting.home_center', null)
            ->assertJsonPath('data.sorting.current_zone', null)
            ->assertJsonPath('data.addresses', [])
            ->assertJsonPath('data.documents.valid_id', 'not_provided')
            ->assertJsonPath('data.documents.or_cr', 'not_provided');
    }

    public function test_mobile_history_uses_existing_rider_delivery_records_and_scopes_them_to_the_owner(): void
    {
        $provider = $this->makeProvider('history-scope');
        $rider = $this->makeRider($provider, 'history-owner');
        $otherRider = $this->makeRider($provider, 'history-other');
        $ownDelivery = $this->makeDelivery($provider, $rider, 'HISTORY-OWN');
        $otherDelivery = $this->makeDelivery($provider, $otherRider, 'HISTORY-OTHER');

        DeliveryAttempt::query()->create([
            'parcel_id' => $ownDelivery['parcel']->id,
            'dispatch_batch_id' => $ownDelivery['batch']->id,
            'rider_profile_id' => $rider['profile']->id,
            'attempt_no' => 1,
            'outcome' => 'delivered',
            'attempted_at' => now(),
        ]);
        DeliveryAttempt::query()->create([
            'parcel_id' => $otherDelivery['parcel']->id,
            'dispatch_batch_id' => $otherDelivery['batch']->id,
            'rider_profile_id' => $otherRider['profile']->id,
            'attempt_no' => 1,
            'outcome' => 'delivered',
            'attempted_at' => now(),
        ]);

        Sanctum::actingAs($rider['user']);

        $this->getJson('/api/mobile/rider/history')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.shipment_no', $ownDelivery['shipment']->shipment_no)
            ->assertJsonPath('data.0.parcel_no', $ownDelivery['parcel']->parcel_no)
            ->assertJsonPath('data.0.status', 'delivered')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissing(['shipment_no' => $otherDelivery['shipment']->shipment_no]);

        $this->assertDatabaseHas('delivery_attempts', [
            'parcel_id' => $ownDelivery['parcel']->id,
            'outcome' => 'delivered',
        ]);
        $this->assertDatabaseHas('shipments', [
            'id' => $ownDelivery['shipment']->id,
            'status' => 'pending_waybill',
        ]);
    }

    public function test_mobile_history_returns_empty_for_riders_without_history(): void
    {
        $provider = $this->makeProvider('history-empty');
        $rider = $this->makeRider($provider, 'history-empty');
        Sanctum::actingAs($rider['user']);

        $this->getJson('/api/mobile/rider/history')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    private function makeProvider(string $suffix): array
    {
        $user = User::factory()->create([
            'name' => "Logistics {$suffix}",
            'email' => "logistics-{$suffix}@example.test",
            'role' => 'logistics',
            'status' => 'active',
        ]);

        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => "Logistics {$suffix}",
            'display_name' => "Logistics {$suffix}",
            'contact_phone' => '09170000000',
            'status' => 'active',
        ]);

        return compact('user', 'profile');
    }

    private function makeRider(array $provider, string $suffix, string $verification = 'approved'): array
    {
        $user = User::factory()->create([
            'name' => "Rider {$suffix}",
            'email' => "rider-{$suffix}@example.test",
            'role' => 'rider',
            'status' => 'active',
        ]);

        $profile = RiderProfile::query()->create([
            'user_id' => $user->id,
            'logistics_profile_id' => $provider['profile']->id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => "TEST-{$user->id}",
            'availability_status' => 'available',
            'verification_status' => $verification,
        ]);

        return compact('user', 'profile');
    }

    private function makePickup(array $provider, array $rider, string $suffix): PickupRequest
    {
        $seller = User::factory()->create([
            'name' => "Seller {$suffix}",
            'role' => 'seller',
            'status' => 'active',
        ]);
        $sellerProfile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => "Seller {$suffix}",
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $sellerProfile->id,
            'name' => "Store {$suffix}",
            'slug' => strtolower($suffix),
        ]);
        $address = $this->makeAddress($seller, "Pickup {$suffix}");
        $pickup = PickupRequest::query()->create([
            'pickup_no' => $suffix,
            'store_id' => $store->id,
            'logistics_profile_id' => $provider['profile']->id,
            'pickup_address_id' => $address->id,
            'status' => 'verified',
            'requested_date' => now()->toDateString(),
            'window_start' => now()->addHour(),
            'window_end' => now()->addHours(2),
            'seller_instructions' => 'Use the side entrance.',
        ]);
        PickupAssignment::query()->create([
            'pickup_request_id' => $pickup->id,
            'rider_profile_id' => $rider['profile']->id,
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);

        return $pickup;
    }

    private function makeDelivery(array $provider, array $rider, string $suffix): array
    {
        $seller = User::factory()->create([
            'name' => "Seller {$suffix}",
            'role' => 'seller',
            'status' => 'active',
        ]);
        $sellerProfile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => "Seller {$suffix}",
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $sellerProfile->id,
            'name' => "Store {$suffix}",
            'slug' => strtolower($suffix),
        ]);
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);
        $order = Order::query()->create([
            'order_no' => "ORDER-{$suffix}",
            'buyer_id' => $buyer->id,
            'recipient_name' => "Buyer {$suffix}",
            'recipient_phone' => '09171112222',
            'address_line' => '12 Test Street',
            'barangay' => 'Test Barangay',
            'city_municipality' => 'Quezon City',
            'province' => 'Metro Manila',
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => "SELLER-{$suffix}",
            'order_id' => $order->id,
            'store_id' => $store->id,
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
        ]);
        $shipment = Shipment::query()->create([
            'shipment_no' => "SHIP-{$suffix}",
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider['profile']->id,
        ]);
        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => "WB-{$suffix}",
            'scan_token' => hash('sha256', "scan-{$suffix}"),
            'barcode_value' => "BARCODE-{$suffix}",
            'total_weight_kg' => 1.250,
            'generated_at' => now(),
        ]);
        $parcel = Parcel::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_id' => $waybill->id,
            'parcel_no' => "PARCEL-{$suffix}",
            'piece_sequence' => 1,
            'weight_kg' => 1.250,
            'size_class' => 'small',
            'status' => 'dispatched',
        ]);
        $center = SortingCenter::query()->where(
            'logistics_profile_id',
            $provider['profile']->id,
        )->first();
        if (! $center) {
            $address = $this->makeAddress($seller, "Center {$suffix}");
            $center = SortingCenter::query()->create([
                'logistics_profile_id' => $provider['profile']->id,
                'address_id' => $address->id,
                'name' => "Center {$suffix}",
                'code' => "C-{$suffix}",
            ]);
        }
        $batch = DispatchBatch::query()->create([
            'batch_no' => "BATCH-{$suffix}",
            'sorting_center_id' => $center->id,
            'rider_profile_id' => $rider['profile']->id,
            'status' => 'dispatched',
            'dispatched_at' => now(),
        ]);
        $batch->parcels()->attach($parcel->id);

        return compact('order', 'shipment', 'parcel', 'batch');
    }

    private function makeAddress(User $user, string $label): Address
    {
        return Address::query()->create([
            'user_id' => $user->id,
            'label' => $label,
            'recipient_name' => $user->name,
            'phone' => '09170000000',
            'street' => 'Test Street',
            'barangay' => 'Test Barangay',
            'city_municipality' => 'Quezon City',
            'province' => 'Metro Manila',
        ]);
    }
}
