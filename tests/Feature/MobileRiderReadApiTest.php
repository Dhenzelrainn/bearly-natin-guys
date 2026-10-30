<?php

namespace Tests\Feature;

use App\Models\AccountApplication;
use App\Models\Address;
use App\Models\ApplicationDocument;
use App\Models\CodCashCollection;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryProof;
use App\Models\DispatchBatch;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Payment;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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

    public function test_mobile_rider_starts_an_owned_delivery_and_receives_server_state(): void
    {
        $provider = $this->makeProvider('start-success');
        $rider = $this->makeRider($provider, 'start-success');
        $delivery = $this->makeDelivery($provider, $rider, 'START-SUCCESS');
        $delivery['shipment']->update(['status' => 'dispatched']);

        Sanctum::actingAs($rider['user']);

        $this->getJson("/api/mobile/rider/deliveries/{$delivery['shipment']->shipment_no}")
            ->assertOk()
            ->assertJsonPath('data.can_start_delivery', true);

        $this->postJson("/api/mobile/rider/deliveries/{$delivery['shipment']->shipment_no}/start")
            ->assertOk()
            ->assertJsonPath('message', 'Delivery started. Assigned parcels are now out for delivery.')
            ->assertJsonPath('data.id', $delivery['shipment']->shipment_no)
            ->assertJsonPath('data.status', 'Out for Delivery')
            ->assertJsonPath('data.can_start_delivery', false)
            ->assertJsonPath('data.parcels.0.status', 'out_for_delivery');

        $this->getJson('/api/mobile/rider/deliveries')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'Out for Delivery');

        $this->assertSame('out_for_delivery', $delivery['shipment']->fresh()->status);
        $this->assertSame('out_for_delivery', $delivery['parcel']->fresh()->status);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $delivery['shipment']->id,
            'event_type' => 'shipment_out_for_delivery',
            'to_status' => 'out_for_delivery',
        ]);
    }

    public function test_mobile_delivery_start_rejects_replayed_requests_without_duplicate_events(): void
    {
        $provider = $this->makeProvider('start-replay');
        $rider = $this->makeRider($provider, 'start-replay');
        $delivery = $this->makeDelivery($provider, $rider, 'START-REPLAY');
        $delivery['shipment']->update(['status' => 'dispatched']);
        Sanctum::actingAs($rider['user']);

        $path = "/api/mobile/rider/deliveries/{$delivery['shipment']->shipment_no}/start";
        $this->postJson($path)->assertOk();
        $this->postJson($path)->assertStatus(409);

        $this->assertSame(1, $delivery['shipment']->events()
            ->where('event_type', 'shipment_out_for_delivery')->count());
        $this->assertSame('out_for_delivery', $delivery['parcel']->fresh()->status);
    }

    public function test_mobile_delivery_start_is_hidden_from_other_riders_and_providers(): void
    {
        $provider = $this->makeProvider('start-scope');
        $rider = $this->makeRider($provider, 'start-owner');
        $otherRider = $this->makeRider($provider, 'start-other');
        $own = $this->makeDelivery($provider, $rider, 'START-OWN');
        $foreign = $this->makeDelivery($provider, $otherRider, 'START-FOREIGN');
        $own['shipment']->update(['status' => 'dispatched']);
        $foreign['shipment']->update(['status' => 'dispatched']);

        Sanctum::actingAs($rider['user']);
        $path = "/api/mobile/rider/deliveries/{$foreign['shipment']->shipment_no}";
        $this->getJson($path)->assertNotFound();
        $this->postJson("{$path}/start")->assertNotFound();

        $otherProvider = $this->makeProvider('start-wrong-provider');
        $mismatched = $this->makeDelivery($otherProvider, $rider, 'START-WRONG-PROVIDER');
        $mismatched['shipment']->update(['status' => 'dispatched']);
        $mismatchPath = "/api/mobile/rider/deliveries/{$mismatched['shipment']->shipment_no}/start";
        $this->postJson($mismatchPath)->assertNotFound();

        $this->assertSame('dispatched', $own['shipment']->fresh()->status);
        $this->assertSame('dispatched', $foreign['shipment']->fresh()->status);
        $this->assertSame('dispatched', $mismatched['shipment']->fresh()->status);
    }

    public function test_mobile_delivery_start_requires_active_approved_rider_and_logistics_provider(): void
    {
        $provider = $this->makeProvider('start-eligibility');
        $unapproved = $this->makeRider($provider, 'start-unapproved', 'pending');
        $unapprovedDelivery = $this->makeDelivery($provider, $unapproved, 'START-UNAPPROVED');
        $unapprovedDelivery['shipment']->update(['status' => 'dispatched']);
        Sanctum::actingAs($unapproved['user']);
        $this->postJson("/api/mobile/rider/deliveries/{$unapprovedDelivery['shipment']->shipment_no}/start")
            ->assertForbidden();
        $this->assertSame('dispatched', $unapprovedDelivery['shipment']->fresh()->status);

        $inactiveUserRider = $this->makeRider($provider, 'start-inactive-user');
        $inactiveUserDelivery = $this->makeDelivery($provider, $inactiveUserRider, 'START-INACTIVE-USER');
        $inactiveUserDelivery['shipment']->update(['status' => 'dispatched']);
        $inactiveUserRider['user']->update(['status' => 'suspended']);
        Sanctum::actingAs($inactiveUserRider['user']);
        $this->postJson("/api/mobile/rider/deliveries/{$inactiveUserDelivery['shipment']->shipment_no}/start")
            ->assertForbidden();

        $provider['profile']->update(['status' => 'inactive']);
        Sanctum::actingAs($unapproved['user']->fresh());
        // Restore a valid Rider profile so this assertion isolates provider state.
        $unapproved['profile']->update(['verification_status' => 'approved']);
        $this->postJson("/api/mobile/rider/deliveries/{$unapprovedDelivery['shipment']->shipment_no}/start")
            ->assertForbidden();

        $provider['profile']->update(['status' => 'active']);
        $provider['user']->update(['status' => 'suspended']);
        $this->postJson("/api/mobile/rider/deliveries/{$unapprovedDelivery['shipment']->shipment_no}/start")
            ->assertForbidden();
        $this->assertSame('dispatched', $unapprovedDelivery['shipment']->fresh()->status);
    }

    public function test_mobile_delivery_start_rejects_invalid_shipment_and_parcel_states(): void
    {
        $provider = $this->makeProvider('start-invalid-state');
        $rider = $this->makeRider($provider, 'start-invalid-state');
        $shipmentInvalid = $this->makeDelivery($provider, $rider, 'START-SHIPMENT-INVALID');
        $shipmentInvalid['shipment']->update(['status' => 'picked_up']);
        $parcelInvalid = $this->makeDelivery($provider, $rider, 'START-PARCEL-INVALID');
        $parcelInvalid['shipment']->update(['status' => 'dispatched']);
        $parcelInvalid['parcel']->update(['status' => 'picked_up']);
        Sanctum::actingAs($rider['user']);

        $this->postJson("/api/mobile/rider/deliveries/{$shipmentInvalid['shipment']->shipment_no}/start")
            ->assertStatus(409);
        $this->postJson("/api/mobile/rider/deliveries/{$parcelInvalid['shipment']->shipment_no}/start")
            ->assertStatus(409);

        $this->assertSame('picked_up', $shipmentInvalid['shipment']->fresh()->status);
        $this->assertSame('picked_up', $parcelInvalid['parcel']->fresh()->status);
    }

    public function test_mobile_start_preserves_multi_rider_shipment_aggregation(): void
    {
        $provider = $this->makeProvider('start-multi-rider');
        $firstRider = $this->makeRider($provider, 'start-multi-first');
        $secondRider = $this->makeRider($provider, 'start-multi-second');
        $delivery = $this->makeDelivery($provider, $firstRider, 'START-MULTI');
        $delivery['shipment']->update(['status' => 'dispatched']);

        $secondParcel = Parcel::query()->create([
            'shipment_id' => $delivery['shipment']->id,
            'waybill_id' => $delivery['parcel']->waybill_id,
            'parcel_no' => 'PARCEL-START-MULTI-2',
            'piece_sequence' => 2,
            'weight_kg' => 1.250,
            'size_class' => 'small',
            'status' => 'dispatched',
        ]);
        $secondBatch = DispatchBatch::query()->create([
            'batch_no' => 'BATCH-START-MULTI-2',
            'sorting_center_id' => $delivery['batch']->sorting_center_id,
            'rider_profile_id' => $secondRider['profile']->id,
            'status' => 'dispatched',
            'dispatched_at' => now(),
        ]);
        $secondBatch->parcels()->attach($secondParcel->id);

        Sanctum::actingAs($firstRider['user']);
        $this->postJson("/api/mobile/rider/deliveries/{$delivery['shipment']->shipment_no}/start")
            ->assertOk()
            ->assertJsonPath('data.parcel_count', 1)
            ->assertJsonPath('data.status', 'Out for Delivery');
        $this->assertSame('dispatched', $delivery['shipment']->fresh()->status);
        $this->assertSame('out_for_delivery', $delivery['parcel']->fresh()->status);
        $this->assertSame('dispatched', $secondParcel->fresh()->status);

        Sanctum::actingAs($secondRider['user']);
        $this->getJson('/api/mobile/rider/deliveries')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.parcels.0.parcel_no', $secondParcel->parcel_no);
        $this->postJson("/api/mobile/rider/deliveries/{$delivery['shipment']->shipment_no}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'Out for Delivery');

        $this->assertSame('out_for_delivery', $delivery['shipment']->fresh()->status);
        $this->assertSame('out_for_delivery', $secondParcel->fresh()->status);
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

    public function test_mobile_delivery_lists_and_details_exclude_delivered_history(): void
    {
        $provider = $this->makeProvider('delivered-history');
        $rider = $this->makeRider($provider, 'delivered-history');
        $delivery = $this->makeDelivery($provider, $rider, 'DELIVERED-HISTORY');
        $delivery['shipment']->update(['status' => 'delivered']);
        $delivery['parcel']->update(['status' => 'delivered']);
        DeliveryAttempt::query()->create([
            'parcel_id' => $delivery['parcel']->id,
            'dispatch_batch_id' => $delivery['batch']->id,
            'rider_profile_id' => $rider['profile']->id,
            'attempt_no' => 1,
            'outcome' => 'delivered',
            'attempted_at' => now(),
        ]);

        Sanctum::actingAs($rider['user']);

        $this->getJson('/api/mobile/rider/deliveries')
            ->assertOk()
            ->assertJsonPath('data', []);
        $this->getJson("/api/mobile/rider/deliveries/{$delivery['shipment']->shipment_no}")
            ->assertNotFound();
        $this->getJson('/api/mobile/rider/history')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.shipment_no', $delivery['shipment']->shipment_no)
            ->assertJsonPath('data.0.status', 'delivered');
    }

    public function test_mobile_pickup_list_excludes_collected_and_completed_assignments(): void
    {
        $provider = $this->makeProvider('pickup-history');
        $rider = $this->makeRider($provider, 'pickup-history');
        $activePickup = $this->makePickup($provider, $rider, 'PICKUP-ACTIVE');
        $collectedPickup = $this->makePickup($provider, $rider, 'PICKUP-COLLECTED');
        $collectedAssignment = PickupAssignment::query()
            ->where('pickup_request_id', $collectedPickup->id)
            ->where('rider_profile_id', $rider['profile']->id)
            ->firstOrFail();
        $collectedAssignment->update(['status' => 'picked_up']);
        $collectedPickup->update(['status' => 'completed']);

        Sanctum::actingAs($rider['user']);

        $this->getJson('/api/mobile/rider/pickups')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $activePickup->pickup_no)
            ->assertJsonPath('data.0.status_raw', 'assigned');
        $this->getJson("/api/mobile/rider/pickups/{$collectedPickup->pickup_no}")
            ->assertOk()
            ->assertJsonPath('data.status_raw', 'picked_up');
    }

    public function test_mobile_pickup_acceptance_is_owner_scoped_and_replay_safe(): void
    {
        $provider = $this->makeProvider('mobile-accept');
        $rider = $this->makeRider($provider, 'mobile-accept-owner');
        $otherRider = $this->makeRider($provider, 'mobile-accept-other');
        $pickup = $this->makePickup($provider, $rider, 'MOBILE-ACCEPT-OWN');
        $otherPickup = $this->makePickup($provider, $otherRider, 'MOBILE-ACCEPT-OTHER');
        $path = "/api/mobile/rider/pickups/{$pickup->pickup_no}/accept";

        Sanctum::actingAs($rider['user']);

        $this->postJson($path)
            ->assertOk()
            ->assertJsonPath('message', 'Pickup assignment accepted.')
            ->assertJsonPath('data.id', $pickup->pickup_no)
            ->assertJsonPath('data.status_raw', 'accepted');

        $this->postJson($path)
            ->assertOk()
            ->assertJsonPath('data.status_raw', 'accepted');
        $this->postJson("/api/mobile/rider/pickups/{$otherPickup->pickup_no}/accept")
            ->assertNotFound();

        $this->assertDatabaseHas('pickup_assignments', [
            'pickup_request_id' => $pickup->id,
            'rider_profile_id' => $rider['profile']->id,
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('pickup_assignments', [
            'pickup_request_id' => $otherPickup->id,
            'rider_profile_id' => $otherRider['profile']->id,
            'status' => 'assigned',
        ]);
    }

    public function test_mobile_pickup_actions_require_an_eligible_rider_and_active_matching_provider(): void
    {
        $provider = $this->makeProvider('mobile-eligibility');
        $pendingRider = $this->makeRider($provider, 'mobile-pending', 'pending');
        $pendingPickup = $this->makePickup($provider, $pendingRider, 'MOBILE-PENDING');
        Sanctum::actingAs($pendingRider['user']);
        $this->postJson("/api/mobile/rider/pickups/{$pendingPickup->pickup_no}/accept")
            ->assertForbidden();

        $suspendedRider = $this->makeRider($provider, 'mobile-suspended');
        $suspendedRider['user']->update(['status' => 'suspended']);
        $suspendedPickup = $this->makePickup($provider, $suspendedRider, 'MOBILE-SUSPENDED');
        Sanctum::actingAs($suspendedRider['user']);
        $this->postJson("/api/mobile/rider/pickups/{$suspendedPickup->pickup_no}/accept")
            ->assertForbidden();

        $inactiveProvider = $this->makeProvider('mobile-inactive-provider');
        $inactiveRider = $this->makeRider($inactiveProvider, 'mobile-inactive-provider');
        $inactivePickup = $this->makePickup($inactiveProvider, $inactiveRider, 'MOBILE-INACTIVE-PROVIDER');
        $inactiveProvider['profile']->update(['status' => 'suspended']);
        Sanctum::actingAs($inactiveRider['user']);
        $this->postJson("/api/mobile/rider/pickups/{$inactivePickup->pickup_no}/accept")
            ->assertForbidden();

        $foreignProvider = $this->makeProvider('mobile-foreign-provider');
        $matchingRider = $this->makeRider($provider, 'mobile-foreign-assignment');
        $foreignPickup = $this->makePickup($foreignProvider, $matchingRider, 'MOBILE-FOREIGN-PROVIDER');
        Sanctum::actingAs($matchingRider['user']);
        $this->postJson("/api/mobile/rider/pickups/{$foreignPickup->pickup_no}/accept")
            ->assertForbidden();

        $this->assertDatabaseHas('pickup_assignments', [
            'pickup_request_id' => $foreignPickup->id,
            'rider_profile_id' => $matchingRider['profile']->id,
            'status' => 'assigned',
        ]);
    }

    public function test_mobile_pickup_actions_require_sanctum_authentication(): void
    {
        $this->postJson('/api/mobile/rider/pickups/UNKNOWN/accept')
            ->assertUnauthorized();
        $this->postJson('/api/mobile/rider/pickups/UNKNOWN/confirm', [
            'waybill_identifier' => 'WB-UNKNOWN',
        ])->assertUnauthorized();
    }

    public function test_mobile_pickup_confirmation_requires_an_exact_waybill_and_is_replay_protected(): void
    {
        $provider = $this->makeProvider('mobile-confirm');
        $rider = $this->makeRider($provider, 'mobile-confirm');
        $record = $this->makeConfirmablePickup($provider, $rider, 'MOBILE-CONFIRM');
        Sanctum::actingAs($rider['user']);

        $path = "/api/mobile/rider/pickups/{$record['pickup']->pickup_no}/confirm";
        $this->postJson($path, ['waybill_identifier' => 'WB-NOT-ASSIGNED'])
            ->assertStatus(409);
        $this->assertSame('accepted', $record['assignment']->fresh()->status);
        $this->assertSame('ready_for_pickup', $record['parcel']->fresh()->status);
        $this->assertSame('pickup_assigned', $record['shipment']->fresh()->status);

        $this->postJson($path, [
            'waybill_identifier' => $record['waybill']->barcode_value,
            'scan_method' => 'manual',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Pickup confirmed. Parcels are now marked as picked up.')
            ->assertJsonPath('data.status_raw', 'picked_up')
            ->assertJsonPath('data.manifest.0.parcels.0.status', 'Picked Up');

        $eventCount = \DB::table('shipment_events')->count();
        $this->postJson($path, ['waybill_identifier' => $record['waybill']->waybill_no])
            ->assertStatus(409);

        $this->assertSame('picked_up', $record['assignment']->fresh()->status);
        $this->assertSame('picked_up', $record['parcel']->fresh()->status);
        $this->assertSame('picked_up', $record['shipment']->fresh()->status);
        $this->assertSame('handed_to_logistics', $record['sellerOrder']->fresh()->status);
        $this->assertSame($eventCount, \DB::table('shipment_events')->count());
    }

    public function test_mobile_pickup_confirmation_rejects_invalid_parcel_status_without_partial_changes(): void
    {
        $provider = $this->makeProvider('mobile-invalid-parcel');
        $rider = $this->makeRider($provider, 'mobile-invalid-parcel');
        $record = $this->makeConfirmablePickup(
            $provider,
            $rider,
            'MOBILE-INVALID-PARCEL',
            parcelStatus: 'dispatched',
        );
        Sanctum::actingAs($rider['user']);

        $this->postJson(
            "/api/mobile/rider/pickups/{$record['pickup']->pickup_no}/confirm",
            ['waybill_identifier' => $record['waybill']->waybill_no],
        )->assertStatus(409);

        $this->assertSame('accepted', $record['assignment']->fresh()->status);
        $this->assertSame('dispatched', $record['parcel']->fresh()->status);
        $this->assertSame('pickup_assigned', $record['shipment']->fresh()->status);
        $this->assertDatabaseCount('shipment_events', 0);
    }

    public function test_mobile_pickup_confirmation_validates_manual_waybill_payload(): void
    {
        $provider = $this->makeProvider('mobile-validation');
        $rider = $this->makeRider($provider, 'mobile-validation');
        $record = $this->makeConfirmablePickup($provider, $rider, 'MOBILE-VALIDATION');
        Sanctum::actingAs($rider['user']);

        $path = "/api/mobile/rider/pickups/{$record['pickup']->pickup_no}/confirm";
        $this->postJson($path, ['waybill_identifier' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('waybill_identifier');
        $this->postJson($path, [
            'waybill_identifier' => $record['waybill']->waybill_no,
            'scan_method' => 'unknown',
        ])->assertUnprocessable()->assertJsonValidationErrors('scan_method');

        $this->assertSame('accepted', $record['assignment']->fresh()->status);
        $this->assertSame('ready_for_pickup', $record['parcel']->fresh()->status);
    }

    public function test_mobile_delivery_completion_stores_private_proof_and_is_idempotent(): void
    {
        Storage::fake('local');
        Notification::fake();

        $provider = $this->makeProvider('complete-success');
        $rider = $this->makeRider($provider, 'complete-success');
        $delivery = $this->makeDelivery($provider, $rider, 'COMPLETE-SUCCESS');
        $delivery['shipment']->update(['status' => 'out_for_delivery']);
        $delivery['parcel']->update(['status' => 'out_for_delivery']);
        Sanctum::actingAs($rider['user']);

        $path = "/api/mobile/rider/deliveries/{$delivery['shipment']->shipment_no}";
        $this->getJson("{$path}/completion-status")
            ->assertOk()
            ->assertJsonPath('data.status', 'Out for Delivery')
            ->assertJsonPath('data.cod_due_minor', 0)
            ->assertJsonPath('data.requires_cod_collection', false);

        $completed = $this->post($this->completionPath($delivery['shipment']), [
            'recipient_name' => '  Delivery Recipient  ',
            'proof_photo' => $this->fakeProofPhoto('proof.png'),
        ], ['Accept' => 'application/json']);
        $completed
            ->assertOk()
            ->assertJsonPath('data.status', 'Delivered')
            ->assertJsonPath('data.status_raw', 'delivered')
            ->assertJsonPath('data.shipment_status', 'delivered')
            ->assertJsonPath('data.parcels.0.status', 'delivered')
            ->assertJsonPath('data.cod_collection', null)
            ->assertJsonPath('data.already_completed', false);

        $proof = DeliveryProof::query()->sole();
        $this->assertSame('Delivery Recipient', $proof->recipient_name);
        Storage::disk('local')->assertExists($proof->file_path);
        $completed->assertDontSee('delivery-proofs/');
        $this->assertDatabaseHas('shipments', [
            'id' => $delivery['shipment']->id,
            'status' => 'delivered',
        ]);

        $this->getJson("{$path}/completion-status")
            ->assertOk()
            ->assertJsonPath('data.status', 'Delivered')
            ->assertJsonPath('data.shipment_status', 'delivered');
        $this->getJson('/api/mobile/rider/deliveries')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/mobile/rider/history')
            ->assertOk()
            ->assertJsonPath('data.0.shipment_no', $delivery['shipment']->shipment_no)
            ->assertJsonPath('data.0.status', 'delivered');

        $this->post($this->completionPath($delivery['shipment']), [
            'recipient_name' => 'Delivery Recipient',
            'proof_photo' => $this->fakeProofPhoto('replay.png'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.already_completed', true)
            ->assertJsonPath('data.status', 'Delivered');

        $this->assertDatabaseCount('delivery_attempts', 1);
        $this->assertDatabaseCount('delivery_proofs', 1);
        $this->assertSame(1, $delivery['shipment']->events()
            ->where('event_type', 'shipment_delivered')->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('delivery-proofs'));
    }

    public function test_mobile_delivery_completion_requires_valid_recipient_and_photo(): void
    {
        Storage::fake('local');
        $provider = $this->makeProvider('complete-validation');
        $rider = $this->makeRider($provider, 'complete-validation');
        $delivery = $this->makeDelivery($provider, $rider, 'COMPLETE-VALIDATION');
        $delivery['shipment']->update(['status' => 'out_for_delivery']);
        $delivery['parcel']->update(['status' => 'out_for_delivery']);
        Sanctum::actingAs($rider['user']);

        $path = $this->completionPath($delivery['shipment']);
        $this->post($path, [], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient_name', 'proof_photo']);
        $this->post($path, [
            'recipient_name' => '   ',
            'proof_photo' => $this->fakeProofPhoto('proof.png'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recipient_name');
        $this->post($path, [
            'recipient_name' => 'Recipient',
            'proof_photo' => UploadedFile::fake()->create('proof.gif', 100, 'image/gif'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('proof_photo');
        $this->post($path, [
            'recipient_name' => 'Recipient',
            'proof_photo' => $this->fakeProofPhoto('large.png', 10 * 1024 * 1024 + 1),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('proof_photo');

        $this->assertSame('out_for_delivery', $delivery['shipment']->fresh()->status);
        $this->assertSame('out_for_delivery', $delivery['parcel']->fresh()->status);
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertDatabaseCount('delivery_proofs', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('delivery-proofs'));
    }

    public function test_mobile_delivery_completion_is_scoped_to_eligible_rider_and_provider(): void
    {
        Storage::fake('local');
        $provider = $this->makeProvider('complete-scope');
        $rider = $this->makeRider($provider, 'complete-owner');
        $otherRider = $this->makeRider($provider, 'complete-other');
        $own = $this->makeDelivery($provider, $rider, 'COMPLETE-OWN');
        $foreign = $this->makeDelivery($provider, $otherRider, 'COMPLETE-FOREIGN');
        $otherProvider = $this->makeProvider('complete-other-provider');
        $otherProviderRider = $this->makeRider($otherProvider, 'complete-provider-rider');
        $wrongProvider = $this->makeDelivery(
            $otherProvider,
            $otherProviderRider,
            'COMPLETE-WRONG-PROVIDER',
        );
        foreach ([$own, $foreign, $wrongProvider] as $delivery) {
            $delivery['shipment']->update(['status' => 'out_for_delivery']);
            $delivery['parcel']->update(['status' => 'out_for_delivery']);
        }

        Sanctum::actingAs($rider['user']);
        $this->getJson("/api/mobile/rider/deliveries/{$foreign['shipment']->shipment_no}/completion-status")
            ->assertNotFound();
        $this->post($this->completionPath($foreign['shipment']), [
            'recipient_name' => 'Recipient',
            'proof_photo' => $this->fakeProofPhoto('foreign.png'),
        ], ['Accept' => 'application/json'])->assertNotFound();
        $this->getJson("/api/mobile/rider/deliveries/{$wrongProvider['shipment']->shipment_no}/completion-status")
            ->assertNotFound();
        $this->post($this->completionPath($wrongProvider['shipment']), [
            'recipient_name' => 'Recipient',
            'proof_photo' => $this->fakeProofPhoto('wrong-provider.png'),
        ], ['Accept' => 'application/json'])->assertNotFound();
        $this->assertSame('out_for_delivery', $foreign['shipment']->fresh()->status);
        $this->assertSame('out_for_delivery', $wrongProvider['shipment']->fresh()->status);
        $this->assertDatabaseCount('delivery_proofs', 0);

        $inactiveRider = $this->makeRider($provider, 'complete-inactive-user');
        $inactive = $this->makeDelivery($provider, $inactiveRider, 'COMPLETE-INACTIVE');
        $inactive['shipment']->update(['status' => 'out_for_delivery']);
        $inactive['parcel']->update(['status' => 'out_for_delivery']);
        $inactiveRider['user']->update(['status' => 'suspended']);
        Sanctum::actingAs($inactiveRider['user']);
        $this->post($this->completionPath($inactive['shipment']), [
            'recipient_name' => 'Recipient',
            'proof_photo' => $this->fakeProofPhoto('inactive.png'),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $inactiveRider['user']->update(['status' => 'active']);
        $provider['profile']->update(['status' => 'inactive']);
        $this->post($this->completionPath($inactive['shipment']), [
            'recipient_name' => 'Recipient',
            'proof_photo' => $this->fakeProofPhoto('inactive-provider.png'),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $provider['profile']->update(['status' => 'active']);
        $provider['user']->update(['status' => 'suspended']);
        $this->post($this->completionPath($inactive['shipment']), [
            'recipient_name' => 'Recipient',
            'proof_photo' => $this->fakeProofPhoto('inactive-owner.png'),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $provider['user']->update(['status' => 'active']);
        $inactiveRider['profile']->update(['verification_status' => 'pending']);
        $this->post($this->completionPath($inactive['shipment']), [
            'recipient_name' => 'Recipient',
            'proof_photo' => $this->fakeProofPhoto('pending-rider.png'),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $inactiveRider['profile']->update(['verification_status' => 'approved']);
        $inactive['parcel']->update(['status' => 'dispatched']);
        $this->post($this->completionPath($inactive['shipment']), [
            'recipient_name' => 'Recipient',
            'proof_photo' => $this->fakeProofPhoto('invalid-state.png'),
        ], ['Accept' => 'application/json'])->assertStatus(409);

        $this->assertSame('out_for_delivery', $own['shipment']->fresh()->status);
        $this->assertSame('out_for_delivery', $inactive['shipment']->fresh()->status);
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertDatabaseCount('delivery_proofs', 0);
    }

    public function test_mobile_cod_completion_records_exact_and_disputed_cash_using_server_due(): void
    {
        Storage::fake('local');
        Notification::fake();

        foreach ([
            ['EXACT', '100.00', 'collected', 0],
            ['SHORT', '95.00', 'disputed', -500],
        ] as [$suffix, $actual, $status, $discrepancy]) {
            $provider = $this->makeProvider("cod-{$suffix}");
            $rider = $this->makeRider($provider, "cod-{$suffix}");
            $delivery = $this->makeDelivery($provider, $rider, "COD-{$suffix}");
            $delivery['shipment']->update([
                'status' => 'out_for_delivery',
                'purpose' => 'outbound',
                'cod_amount_minor' => 10000,
            ]);
            $delivery['parcel']->update(['status' => 'out_for_delivery']);
            Payment::query()->create([
                'payment_no' => "PAY-MOBILE-COD-{$suffix}",
                'order_id' => $delivery['order']->id,
                'method' => 'cod',
                'amount_minor' => 10000,
                'status' => 'pending',
                'initiated_at' => now(),
            ]);
            Sanctum::actingAs($rider['user']);

            $path = "/api/mobile/rider/deliveries/{$delivery['shipment']->shipment_no}";
            $this->getJson("{$path}/completion-status")
                ->assertOk()
                ->assertJsonPath('data.cod_due_minor', 10000)
                ->assertJsonPath('data.requires_cod_collection', true);
            $this->post($this->completionPath($delivery['shipment']), [
                'recipient_name' => 'COD Recipient',
                'proof_photo' => $this->fakeProofPhoto("cod-{$suffix}.png"),
                'cod_collected_amount' => $actual,
            ], ['Accept' => 'application/json'])
                ->assertOk()
                ->assertJsonPath('data.shipment_status', 'delivered')
                ->assertJsonPath('data.cod_collection.status', $status)
                ->assertJsonPath('data.cod_collection.amount_due_minor', 10000)
                ->assertJsonPath('data.cod_collection.discrepancy_minor', $discrepancy);

            $collection = CodCashCollection::query()
                ->where('shipment_id', $delivery['shipment']->id)
                ->firstOrFail();
            $this->assertSame($status, $collection->status);
            $this->assertSame($discrepancy, (int) $collection->discrepancy_minor);
        }
    }

    public function test_mobile_cod_completion_requires_actual_amount_and_rolls_back_all_completion_records(): void
    {
        Storage::fake('local');
        $provider = $this->makeProvider('cod-required');
        $rider = $this->makeRider($provider, 'cod-required');
        $delivery = $this->makeDelivery($provider, $rider, 'COD-REQUIRED');
        $delivery['shipment']->update([
            'status' => 'out_for_delivery',
            'purpose' => 'outbound',
            'cod_amount_minor' => 10000,
        ]);
        $delivery['parcel']->update(['status' => 'out_for_delivery']);
        Payment::query()->create([
            'payment_no' => 'PAY-MOBILE-COD-REQUIRED',
            'order_id' => $delivery['order']->id,
            'method' => 'cod',
            'amount_minor' => 10000,
            'status' => 'pending',
            'initiated_at' => now(),
        ]);
        Sanctum::actingAs($rider['user']);

        $this->post($this->completionPath($delivery['shipment']), [
            'recipient_name' => 'COD Recipient',
            'proof_photo' => $this->fakeProofPhoto('cod-required.png'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cod_collected_amount');

        $this->assertSame('out_for_delivery', $delivery['shipment']->fresh()->status);
        $this->assertSame('out_for_delivery', $delivery['parcel']->fresh()->status);
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertDatabaseCount('delivery_proofs', 0);
        $this->assertDatabaseCount('cod_cash_collections', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('delivery-proofs'));
    }

    public function test_mobile_delivery_completion_handles_all_owned_parcels_in_a_multi_parcel_shipment(): void
    {
        Storage::fake('local');
        Notification::fake();
        $provider = $this->makeProvider('complete-multi');
        $rider = $this->makeRider($provider, 'complete-multi');
        $delivery = $this->makeDelivery($provider, $rider, 'COMPLETE-MULTI');
        $delivery['shipment']->update(['status' => 'out_for_delivery']);
        $delivery['parcel']->update(['status' => 'out_for_delivery']);
        $secondParcel = Parcel::query()->create([
            'shipment_id' => $delivery['shipment']->id,
            'waybill_id' => $delivery['parcel']->waybill_id,
            'parcel_no' => 'PARCEL-COMPLETE-MULTI-2',
            'piece_sequence' => 2,
            'weight_kg' => 1.250,
            'size_class' => 'small',
            'status' => 'out_for_delivery',
        ]);
        $delivery['batch']->parcels()->attach($secondParcel->id);
        Sanctum::actingAs($rider['user']);

        $this->post($this->completionPath($delivery['shipment']), [
            'recipient_name' => 'Multi Parcel Recipient',
            'proof_photo' => $this->fakeProofPhoto('multi.png'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.status', 'Delivered')
            ->assertJsonCount(2, 'data.parcels');

        $this->assertSame('delivered', $delivery['shipment']->fresh()->status);
        $this->assertSame('delivered', $delivery['parcel']->fresh()->status);
        $this->assertSame('delivered', $secondParcel->fresh()->status);
        $this->assertDatabaseCount('delivery_attempts', 2);
        $this->assertDatabaseCount('delivery_proofs', 2);
    }

    private function completionPath(Shipment $shipment): string
    {
        return "/api/mobile/rider/deliveries/{$shipment->shipment_no}/complete";
    }

    private function fakeProofPhoto(string $filename, int $paddingBytes = 0): UploadedFile
    {
        $image = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC',
            true,
        );
        $this->assertNotFalse($image);

        return UploadedFile::fake()->createWithContent(
            $filename,
            $image.str_repeat('x', $paddingBytes),
        );
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

    private function makeConfirmablePickup(
        array $provider,
        array $rider,
        string $suffix,
        string $parcelStatus = 'ready_for_pickup',
    ): array {
        $pickup = $this->makePickup($provider, $rider, $suffix);
        $pickup->update(['status' => 'scheduled']);
        $assignment = PickupAssignment::query()
            ->where('pickup_request_id', $pickup->id)
            ->where('rider_profile_id', $rider['profile']->id)
            ->firstOrFail();
        $assignment->update(['status' => 'accepted', 'accepted_at' => now()]);

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);
        $order = Order::query()->create([
            'order_no' => "MOBILE-PICKUP-ORDER-{$suffix}",
            'buyer_id' => $buyer->id,
            'recipient_name' => 'Test Buyer',
            'recipient_phone' => '09170000001',
            'address_line' => 'Test Buyer Street',
            'barangay' => 'Test Barangay',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
            'status' => 'processing',
            'payment_status' => 'paid',
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => "MOBILE-PICKUP-SELLER-ORDER-{$suffix}",
            'order_id' => $order->id,
            'store_id' => $pickup->store_id,
            'status' => 'ready_for_pickup',
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
            'ready_at' => now(),
        ]);
        $shipment = Shipment::query()->create([
            'shipment_no' => "MOBILE-PICKUP-SHIPMENT-{$suffix}",
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider['profile']->id,
            'purpose' => 'outbound',
            'status' => 'pickup_assigned',
        ]);
        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => "MOBILE-PICKUP-WAYBILL-{$suffix}",
            'scan_token' => hash('sha256', "mobile-pickup-scan-{$suffix}"),
            'barcode_value' => "MOBILE-PICKUP-BARCODE-{$suffix}",
            'total_weight_kg' => 1.250,
            'status' => 'printed',
            'generated_at' => now(),
            'printed_at' => now(),
            'print_count' => 1,
        ]);
        $parcel = Parcel::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_id' => $waybill->id,
            'parcel_no' => "MOBILE-PICKUP-PARCEL-{$suffix}",
            'piece_sequence' => 1,
            'weight_kg' => 1.250,
            'size_class' => 'small',
            'status' => $parcelStatus,
        ]);
        $pickup->parcels()->attach($parcel->id);

        return compact('pickup', 'assignment', 'sellerOrder', 'shipment', 'waybill', 'parcel');
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
