<?php

namespace Tests\Feature\Logistics;

use App\Enums\AccountStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Events\RiderOperationalStatusUpdated;
use App\Models\AccountApplication;
use App\Models\Address;
use App\Models\AuditLog;
use App\Models\DispatchBatch;
use App\Models\LinehaulRun;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\PickupAssignment;
use App\Models\PickupRequest;
use App\Models\Refund;
use App\Models\RefundCashPayout;
use App\Models\ReturnRequest;
use App\Models\RiderLocation;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\SortingCenter;
use App\Models\Store;
use App\Models\User;
use App\Models\Waybill;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RiderAccountStatusManagementTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_logistics_blocking_a_rider_cancels_only_unpicked_pickups_and_records_custody(): void
    {
        Notification::fake();
        Event::fake([RiderOperationalStatusUpdated::class]);

        $provider = $this->makeProvider('STATUS-A');
        $rider = $this->makeRider($provider, 'STATUS', AccountStatus::Active->value, 'available');
        RiderLocation::query()->create([
            'rider_profile_id' => $rider['profile']->id,
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'accuracy_meters' => 12,
            'last_seen_at' => now(),
        ]);
        $seller = $this->makeSeller('STATUS');
        $unpicked = $this->makePickup($provider, $seller, 'UNPICKED');
        $pickedUp = $this->makePickup($provider, $seller, 'PICKED');

        $unpicked['pickup']->update(['status' => 'scheduled']);
        $unpicked['shipment']->update(['status' => ShipmentStatus::PickupAssigned->value]);
        $unpickedAssignment = PickupAssignment::query()->create([
            'pickup_request_id' => $unpicked['pickup']->id,
            'rider_profile_id' => $rider['profile']->id,
            'status' => 'arrived',
            'assigned_by' => $provider['user']->id,
            'assigned_at' => now()->subHour(),
            'accepted_at' => now()->subMinutes(45),
            'arrived_at' => now()->subMinutes(15),
            'notes' => 'Call the seller on arrival.',
        ]);

        $pickedUp['pickup']->update(['status' => 'scheduled']);
        $pickedUp['parcel']->update(['status' => 'picked_up']);
        $pickedUp['shipment']->update(['status' => ShipmentStatus::PickedUp->value]);
        $pickedUpAssignment = PickupAssignment::query()->create([
            'pickup_request_id' => $pickedUp['pickup']->id,
            'rider_profile_id' => $rider['profile']->id,
            'status' => 'picked_up',
            'assigned_by' => $provider['user']->id,
            'assigned_at' => now()->subHours(2),
            'accepted_at' => now()->subHours(1),
            'arrived_at' => now()->subMinutes(50),
            'picked_up_at' => now()->subMinutes(30),
        ]);

        $response = $this->actingAs($provider['user'])
            ->patch(route('logistics.riders.status.update', $rider['user']), [
                'status' => AccountStatus::Suspended->value,
                'reason' => 'Documented safety review.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $successMessage = (string) $response->getSession()->get('success');
        $this->assertStringContainsString('Off duty', $successMessage);
        $this->assertStringContainsString('Verified queue for manual reassignment', $successMessage);
        $this->assertStringContainsString('no automatic reassignment', $successMessage);
        $this->assertStringContainsString('audit-logged', $successMessage);

        Event::assertDispatched(
            RiderOperationalStatusUpdated::class,
            function (RiderOperationalStatusUpdated $event) use ($provider, $rider): bool {
                $payload = $event->broadcastWith();

                return $event->logisticsUserId === $provider['user']->id
                    && $payload['rider_user_id'] === $rider['user']->id
                    && $payload['account_status']
                        === AccountStatus::Suspended->value
                    && $payload['availability_status'] === 'offline'
                    && $payload['updated_at'] !== '';
            },
        );

        $this->assertSame(AccountStatus::Suspended->value, $rider['user']->fresh()->status);
        $this->assertSame('offline', $rider['profile']->fresh()->availability_status);
        $this->assertDatabaseMissing('rider_locations', [
            'rider_profile_id' => $rider['profile']->id,
        ]);
        $this->assertSame('Documented safety review.', $rider['user']->fresh()->status_reason);

        $this->assertDatabaseHas('pickup_assignments', [
            'id' => $unpickedAssignment->id,
            'rider_profile_id' => $rider['profile']->id,
            'status' => 'cancelled',
        ]);
        $this->assertNotNull($unpickedAssignment->fresh()->cancelled_at);
        $this->assertSame('verified', $unpicked['pickup']->fresh()->status);
        $this->assertSame(
            ShipmentStatus::ReadyForPickup->value,
            $unpicked['shipment']->fresh()->status
        );
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $unpicked['shipment']->id,
            'event_type' => 'pickup_assignment_cancelled',
            'from_status' => ShipmentStatus::PickupAssigned->value,
            'to_status' => ShipmentStatus::ReadyForPickup->value,
            'actor_user_id' => $provider['user']->id,
        ]);

        $this->assertDatabaseHas('pickup_assignments', [
            'id' => $pickedUpAssignment->id,
            'rider_profile_id' => $rider['profile']->id,
            'status' => 'picked_up',
        ]);
        $this->assertSame(ShipmentStatus::PickedUp->value, $pickedUp['shipment']->fresh()->status);

        $audit = AuditLog::query()
            ->where('action', 'rider.status_changed')
            ->where('auditable_id', $rider['user']->id)
            ->sole();

        $this->assertSame($provider['user']->id, $audit->actor_user_id);
        $this->assertSame([
            'status' => AccountStatus::Active->value,
            'availability_status' => 'available',
        ], $audit->old_values);
        $this->assertSame(
            AccountStatus::Suspended->value,
            $audit->new_values['status']
        );
        $this->assertSame('Documented safety review.', $audit->new_values['reason']);
        $this->assertSame(
            [$unpickedAssignment->id],
            $audit->new_values['affected']['cancelled_pickup_assignment_ids']
        );
        $this->assertSame(
            [$pickedUpAssignment->id],
            $audit->new_values['affected']['retained_picked_up_assignment_ids']
        );
    }

    public function test_logistics_cannot_change_a_foreign_rider_status(): void
    {
        $provider = $this->makeProvider('OWNER');
        $foreignProvider = $this->makeProvider('FOREIGN');
        $rider = $this->makeRider($foreignProvider, 'FOREIGN', AccountStatus::Active->value, 'available');

        $this->actingAs($provider['user'])
            ->patch(route('logistics.riders.status.update', $rider['user']), [
                'status' => AccountStatus::Suspended->value,
                'reason' => 'This Rider is not ours.',
            ])
            ->assertForbidden();

        $this->assertSame(AccountStatus::Active->value, $rider['user']->fresh()->status);
        $this->assertSame('available', $rider['profile']->fresh()->availability_status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'rider.status_changed',
            'auditable_id' => $rider['user']->id,
        ]);
    }

    public function test_pending_rider_profile_cannot_be_activated(): void
    {
        $provider = $this->makeProvider('PENDING');
        $rider = $this->makeRider(
            $provider,
            'PENDING',
            AccountStatus::Suspended->value,
            'offline',
            'pending'
        );

        $this->actingAs($provider['user'])
            ->patch(route('logistics.riders.status.update', $rider['user']), [
                'status' => AccountStatus::Active->value,
                'reason' => 'Attempted before approval.',
            ])
            ->assertStatus(409);

        $this->assertSame(AccountStatus::Suspended->value, $rider['user']->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'rider.status_changed',
            'auditable_id' => $rider['user']->id,
        ]);
    }

    public function test_reactivation_keeps_rider_offline_until_the_rider_opts_in(): void
    {
        $provider = $this->makeProvider('REACTIVATE');
        $rider = $this->makeRider(
            $provider,
            'REACTIVATE',
            AccountStatus::Suspended->value,
            'available'
        );

        $this->actingAs($provider['user'])
            ->patch(route('logistics.riders.status.update', $rider['user']), [
                'status' => AccountStatus::Active->value,
                'reason' => 'Review completed and access restored.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(AccountStatus::Active->value, $rider['user']->fresh()->status);
        $this->assertSame('offline', $rider['profile']->fresh()->availability_status);
    }

    public function test_status_change_audits_retained_active_dispatch_cash_and_linehaul_work(): void
    {
        $provider = $this->makeProvider('RETAINED');
        $rider = $this->makeRider($provider, 'RETAINED', AccountStatus::Active->value, 'available');
        $seller = $this->makeSeller('RETAINED');
        $center = SortingCenter::query()->create([
            'logistics_profile_id' => $provider['profile']->id,
            'address_id' => $seller['address']->id,
            'name' => 'Retained Sorting Center',
            'code' => 'RETAINED-CENTER',
            'status' => 'active',
        ]);

        $dispatchBatch = DispatchBatch::query()->create([
            'batch_no' => 'DISPATCH-RETAINED',
            'sorting_center_id' => $center->id,
            'rider_profile_id' => $rider['profile']->id,
            'status' => 'dispatched',
            'assigned_at' => now()->subHour(),
            'dispatched_at' => now()->subMinutes(30),
        ]);

        $linehaulRun = LinehaulRun::query()->create([
            'run_no' => 'LINEHAUL-RETAINED',
            'origin_logistics_profile_id' => $provider['profile']->id,
            'destination_logistics_profile_id' => $provider['profile']->id,
            'origin_sorting_center_id' => $center->id,
            'courier_rider_profile_id' => $rider['profile']->id,
            'status' => 'departed',
            'assigned_by' => $provider['user']->id,
            'assigned_at' => now()->subHours(2),
            'departed_at' => now()->subHour(),
        ]);

        $pickup = $this->makePickup($provider, $seller, 'RETAINED-CASH');
        $returnRequest = ReturnRequest::query()->create([
            'return_no' => 'RETURN-RETAINED',
            'order_id' => $pickup['order']->id,
            'seller_order_id' => $pickup['sellerOrder']->id,
            'buyer_id' => $pickup['order']->buyer_id,
            'store_id' => $seller['store']->id,
            'request_type' => 'refund_only',
            'reason_code' => 'other',
            'status' => 'refund_pending',
            'requested_amount_minor' => 5000,
            'submitted_at' => now()->subDays(2),
        ]);
        $refund = Refund::query()->create([
            'refund_no' => 'REFUND-RETAINED',
            'return_request_id' => $returnRequest->id,
            'order_id' => $pickup['order']->id,
            'amount_minor' => 5000,
            'status' => 'pending',
            'method' => 'cod_cash',
        ]);
        $refundCashPayout = RefundCashPayout::query()->create([
            'payout_no' => 'PAYOUT-RETAINED',
            'refund_id' => $refund->id,
            'return_request_id' => $returnRequest->id,
            'logistics_profile_id' => $provider['profile']->id,
            'rider_profile_id' => $rider['profile']->id,
            'amount_minor' => 5000,
            'status' => 'cash_with_rider',
            'assigned_at' => now()->subHour(),
            'received_by_rider_at' => now()->subMinutes(50),
        ]);

        $this->actingAs($provider['user'])
            ->patch(route('logistics.riders.status.update', $rider['user']), [
                'status' => AccountStatus::Suspended->value,
                'reason' => 'Retain active custody for review.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('dispatch_batches', [
            'id' => $dispatchBatch->id,
            'rider_profile_id' => $rider['profile']->id,
            'status' => 'dispatched',
        ]);
        $this->assertDatabaseHas('refund_cash_payouts', [
            'id' => $refundCashPayout->id,
            'rider_profile_id' => $rider['profile']->id,
            'status' => 'cash_with_rider',
        ]);
        $this->assertDatabaseHas('linehaul_runs', [
            'id' => $linehaulRun->id,
            'courier_rider_profile_id' => $rider['profile']->id,
            'status' => 'departed',
        ]);

        $audit = AuditLog::query()
            ->where('action', 'rider.status_changed')
            ->where('auditable_id', $rider['user']->id)
            ->sole();

        $this->assertSame(
            [$dispatchBatch->id],
            $audit->new_values['affected']['retained_dispatch_batch_ids']
        );
        $this->assertSame(
            [$refundCashPayout->id],
            $audit->new_values['affected']['retained_refund_cash_payout_ids']
        );
        $this->assertSame(
            [$linehaulRun->id],
            $audit->new_values['affected']['retained_linehaul_run_ids']
        );
    }

    public function test_logistics_rider_page_exposes_status_reason_controls_and_picked_up_intake(): void
    {
        $provider = $this->makeProvider('UI');
        $rider = $this->makeRider($provider, 'UI', AccountStatus::Active->value, 'offline');
        $this->makeApprovedApplication($provider, $rider['user']);
        $seller = $this->makeSeller('UI');
        $pickedUp = $this->makePickup($provider, $seller, 'UI-PICKED');
        $pickedUp['pickup']->update(['status' => 'scheduled']);
        $pickedUp['parcel']->update(['status' => 'picked_up']);
        $pickedUp['shipment']->update(['status' => ShipmentStatus::PickedUp->value]);
        PickupAssignment::query()->create([
            'pickup_request_id' => $pickedUp['pickup']->id,
            'rider_profile_id' => $rider['profile']->id,
            'status' => 'picked_up',
            'assigned_at' => now()->subHour(),
            'picked_up_at' => now()->subMinutes(30),
        ]);

        $this->actingAs($provider['user'])
            ->get(route('logistics.riders.show', $rider['user']->id))
            ->assertOk()
            ->assertSee('Manage Rider account status')
            ->assertSee(route('logistics.riders.status.update', $rider['user']), false)
            ->assertSee('Reason', false)
            ->assertSee('Picked-up assignments retained');

        $this->actingAs($provider['user'])
            ->get(route('logistics.sorting.incoming'))
            ->assertOk()
            ->assertSee($pickedUp['waybill']->waybill_no)
            ->assertSee('Awaiting Intake')
            ->assertSee('Log intake');
    }

    /** @return array{user: User, profile: LogisticsProfile} */
    private function makeProvider(string $suffix): array
    {
        $user = User::factory()->create([
            'name' => "Logistics {$suffix}",
            'email' => 'logistics-'.strtolower($suffix).'@example.test',
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
        ]);

        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => "Logistics {$suffix} Inc.",
            'display_name' => "Logistics {$suffix}",
            'contact_phone' => $this->phone(),
            'status' => AccountStatus::Active->value,
        ]);

        return compact('user', 'profile');
    }

    /** @return array{user: User, profile: RiderProfile} */
    private function makeRider(
        array $provider,
        string $suffix,
        string $status,
        string $availability,
        string $verificationStatus = 'approved',
    ): array {
        $user = User::factory()->create([
            'name' => "Rider {$suffix}",
            'email' => 'rider-'.strtolower($suffix).'@example.test',
            'role' => UserRole::Rider->value,
            'status' => $status,
            'logistics_id' => $provider['user']->id,
        ]);

        $profile = RiderProfile::query()->create([
            'user_id' => $user->id,
            'logistics_profile_id' => $provider['profile']->id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'RIDER-'.$suffix,
            'availability_status' => $availability,
            'verification_status' => $verificationStatus,
        ]);

        return compact('user', 'profile');
    }

    /** @return array{user: User, address: Address, profile: SellerProfile, store: Store} */
    private function makeSeller(string $suffix): array
    {
        $phone = $this->phone();
        $user = User::factory()->create([
            'name' => "Seller {$suffix}",
            'email' => 'seller-'.strtolower($suffix).'@example.test',
            'role' => UserRole::Seller->value,
            'status' => AccountStatus::Active->value,
            'phone' => $phone,
            'contact_number' => $phone,
        ]);
        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Pickup',
            'recipient_name' => $user->name,
            'phone' => $phone,
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
            'contact_phone' => $phone,
            'operating_hours' => 'Monday-Friday, 9:00 AM-5:00 PM',
            'publication_status' => 'published',
            'published_at' => now(),
        ]);

        return compact('user', 'address', 'profile', 'store');
    }

    /** @return array{order: Order, sellerOrder: SellerOrder, shipment: Shipment, waybill: Waybill, parcel: Parcel, pickup: PickupRequest} */
    private function makePickup(array $provider, array $seller, string $suffix): array
    {
        $buyer = User::factory()->create([
            'email' => 'buyer-'.strtolower($suffix).'@example.test',
            'role' => UserRole::Buyer->value,
            'status' => AccountStatus::Active->value,
        ]);
        $order = Order::query()->create([
            'order_no' => 'ORDER-'.$suffix,
            'buyer_id' => $buyer->id,
            'recipient_name' => $buyer->name,
            'recipient_phone' => $this->phone(),
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
            'seller_order_no' => 'SELLER-ORDER-'.$suffix,
            'order_id' => $order->id,
            'store_id' => $seller['store']->id,
            'status' => 'ready_for_pickup',
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
            'ready_at' => now(),
        ]);
        $shipment = Shipment::query()->create([
            'shipment_no' => 'SHIPMENT-'.$suffix,
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider['profile']->id,
            'origin_address_id' => $seller['address']->id,
            'purpose' => 'outbound',
            'status' => ShipmentStatus::ReadyForPickup->value,
        ]);
        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => 'WAYBILL-'.$suffix,
            'scan_token' => hash('sha256', 'scan-'.$suffix),
            'barcode_value' => 'BARCODE-'.$suffix,
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
            'parcel_no' => 'PARCEL-'.$suffix,
            'piece_sequence' => 1,
            'weight_kg' => 1.250,
            'status' => 'ready_for_pickup',
        ]);
        $pickup = PickupRequest::query()->create([
            'pickup_no' => 'PICKUP-'.$suffix,
            'store_id' => $seller['store']->id,
            'logistics_profile_id' => $provider['profile']->id,
            'pickup_address_id' => $seller['address']->id,
            'status' => 'scheduled',
            'requested_date' => today(),
            'window_start' => now()->addHour(),
            'window_end' => now()->addHours(2),
        ]);
        $pickup->parcels()->attach($parcel->id);

        return compact('order', 'sellerOrder', 'shipment', 'waybill', 'parcel', 'pickup');
    }

    private function makeApprovedApplication(array $provider, User $rider): AccountApplication
    {
        return AccountApplication::query()->create([
            'application_no' => 'APP-'.strtoupper(substr(md5((string) $rider->id), 0, 10)),
            'user_id' => $rider->id,
            'requested_role_id' => Role::query()->where('name', UserRole::Rider->value)->value('id'),
            'sponsor_logistics_profile_id' => $provider['profile']->id,
            'status' => 'approved',
            'submitted_at' => now(),
            'decided_at' => now(),
        ]);
    }

    private function phone(): string
    {
        return sprintf('+63917%07d', ++$this->sequence);
    }
}
