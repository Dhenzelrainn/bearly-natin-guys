<?php

namespace Tests\Feature\Logistics;

use App\Enums\AccountStatus;
use App\Enums\DeliveryAttemptOutcome;
use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\AuditLog;
use App\Models\DispatchBatch;
use App\Models\LogisticsProfile;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\SellerOrder;
use App\Models\SellerProfile;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\RiderProfile;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\User;
use App\Models\Waybill;
use App\Services\RegistrationLifecycleService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderManagementDataTest extends TestCase
{
    use RefreshDatabase;

    private int $phoneSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_dashboard_pending_rider_metric_uses_normalized_sponsored_applications(): void
    {
        $providerA = $this->makeLogisticsProvider(
            'DASH-A',
            'dashboard-a@example.test'
        );

        $providerB = $this->makeLogisticsProvider(
            'DASH-B',
            'dashboard-b@example.test'
        );

        $riderA = $this->makePendingRider(
            $providerA['user'],
            'DASH-A',
            'San Pablo City'
        );

        $riderB = $this->makePendingRider(
            $providerB['user'],
            'DASH-B',
            'Calamba City'
        );

        /*
        * Deliberately make the legacy logistics_id values
        * misleading. The dashboard must use the normalized
        * sponsored AccountApplication instead.
        */
        $riderA['user']->forceFill([
            'logistics_id' => $providerB['user']->id,
        ])->save();

        $riderB['user']->forceFill([
            'logistics_id' => $providerA['user']->id,
        ])->save();

        $this
            ->actingAs($providerA['user'])
            ->get(route('logistics.dashboard'))
            ->assertOk()
            ->assertViewHas(
                'pendingRiderCount',
                1
            )
            ->assertViewHas(
                'metrics',
                function (array $metrics): bool {
                    $rows = collect($metrics)
                        ->keyBy('label');

                    return $rows[
                        'Pending Rider Applications'
                    ]['value'] === 1
                        && $rows[
                            'Pending Rider Applications'
                        ]['trend'] === 'Needs review';
                }
            )
            ->assertViewHas(
                'topNotifications',
                function (array $notifications): bool {
                    return count($notifications) === 1
                        && $notifications[0]['title']
                            === '1 rider application awaiting review';
                }
            );
    }

    public function test_rider_management_uses_sponsored_normalized_application_and_hides_foreign_applications(): void
    {
        $providerA = $this->makeLogisticsProvider(
            'A',
            'provider-a@example.test'
        );

        $providerB = $this->makeLogisticsProvider(
            'B',
            'provider-b@example.test'
        );

        $riderA = $this->makePendingRider(
            $providerA['user'],
            'A',
            'San Pablo City'
        );

        $riderB = $this->makePendingRider(
            $providerB['user'],
            'B',
            'Calamba City'
        );

        $response = $this
            ->actingAs($providerA['user'])
            ->get(route('logistics.riders.index'));

        $response->assertOk();

        $response->assertViewHas(
            'applications',
            function (array $applications) use (
                $riderA,
                $riderB
            ): bool {
                $own = collect($applications)
                    ->firstWhere(
                        'user_id',
                        $riderA['user']->id
                    );

                $foreign = collect($applications)
                    ->firstWhere(
                        'user_id',
                        $riderB['user']->id
                    );

                return $own !== null
                    && $own['id']
                        === $riderA['application']
                            ->application_no
                    && $own['application_id']
                        === $riderA['application']->id
                    && $own['name']
                        === $riderA['user']->name
                    && $own['area']
                        === 'San Pablo City'
                    && $own['status']
                        === 'Pending'
                    && $foreign === null;
            }
        );
    }

    public function test_rider_detail_uses_normalized_address_and_application_documents(): void
    {
        $provider = $this->makeLogisticsProvider(
            'A',
            'detail-provider@example.test'
        );

        $rider = $this->makePendingRider(
            $provider['user'],
            'DETAIL',
            'San Pablo City'
        );

        $documents = $rider['application']
            ->documents()
            ->get()
            ->keyBy('document_type');

        $documents['driver_license']->update([
            'verification_status' => 'verified',
            'verified_by' => $provider['user']->id,
            'verified_at' => now(),
        ]);

        $documents['or_cr']->update([
            'verification_status' => 'rejected',
            'verified_by' => $provider['user']->id,
            'verified_at' => now(),
            'rejection_reason' => 'Image is unreadable.',
        ]);

        $response = $this
            ->actingAs($provider['user'])
            ->get(
                route(
                    'logistics.riders.show',
                    $rider['user']->id
                )
            );

        $response->assertOk();

        $response->assertViewHas(
            'application',
            function (array $application) use (
                $rider
            ): bool {
                $documents = collect(
                    $application['documents']
                );

                $license = $documents
                    ->firstWhere(
                        'type',
                        'driver_license'
                    );

                $orCr = $documents
                    ->firstWhere(
                        'type',
                        'or_cr'
                    );

                return $application['id']
                        === $rider['application']
                            ->application_no
                    && $application['application_id']
                        === $rider['application']->id
                    && $application['user_id']
                        === $rider['user']->id
                    && $application['address']
                        === '24, Rider Street, San Rafael, San Pablo City, Laguna, 4000'
                    && $license !== null
                    && $license['filename']
                        === 'driver-license.pdf'
                    && $license['status']
                        === 'Verified'
                    && $orCr !== null
                    && $orCr['filename']
                        === 'or-cr.pdf'
                    && $orCr['status']
                        === 'Rejected'
                    && $orCr['rejection_reason']
                        === 'Image is unreadable.';
            }
        );
    }

    public function test_approved_riders_are_loaded_from_rider_profiles_and_current_zone(): void
    {
        $provider = $this->makeLogisticsProvider(
            'A',
            'approved-provider@example.test'
        );

        $rider = $this->makePendingRider(
            $provider['user'],
            'APPROVED',
            'San Pablo City'
        );

        /*
         * Verify both documents so this test remains valid
         * even after Phase 2B makes Rider document review
         * mandatory before approval.
         */
        $rider['application']
            ->documents()
            ->update([
                'verification_status' => 'verified',
                'verified_by' => $provider['user']->id,
                'verified_at' => now(),
                'rejection_reason' => null,
            ]);

        app(RegistrationLifecycleService::class)
            ->approve(
                $rider['user'],
                $provider['user']
            );

        $rider['user']->refresh();

        $profile = $rider['user']
            ->riderProfile()
            ->firstOrFail();

        $profile->update([
            'vehicle_type' => 'Normalized Motorcycle',
            'current_zone_id' => $provider['zone']->id,
        ]);

        /*
         * Deliberately make the legacy User value different.
         * The page must prefer RiderProfile.
         */
        $rider['user']->update([
            'vehicle_type' => 'Legacy Vehicle Value',
        ]);

        $response = $this
            ->actingAs($provider['user'])
            ->get(route('logistics.riders.index'));

        $response
            ->assertOk()
            ->assertSee('value="active">Enabled', false)
            ->assertSee('data-status="active"', false)
            ->assertSee('data-availability="offline"', false)
            ->assertSee('Off duty', false);

        $response->assertViewHas(
            'riders',
            function (array $riders) use (
                $rider,
                $profile,
                $provider
            ): bool {
                $result = collect($riders)
                    ->firstWhere(
                        'id',
                        $rider['user']->id
                    );

                return $result !== null
                    && $result['profile_id']
                        === $profile->id
                    && $result['name']
                        === $rider['user']->name
                    && $result['vehicle']
                        === 'Normalized Motorcycle'
                    && $result['zone']
                        === $provider['zone']->name
                    && $result['jobs'] === 0
                    && $result['rating'] === null
                    && $result['status']
                        === 'Active'
                    && $result['status_key']
                        === AccountStatus::Active->value
                    && $result['availability_status']
                        === 'offline';
            }
        );
    }

    public function test_rider_management_shows_owned_shipment_workload_metrics(): void
    {
        $provider = $this->makeLogisticsProvider(
            'WORKLOAD',
            'workload-provider@example.test',
        );

        $riderA = $this->makePendingRider(
            $provider['user'],
            'WORKLOAD-A',
            'San Pablo City',
        );
        $riderB = $this->makePendingRider(
            $provider['user'],
            'WORKLOAD-B',
            'San Pablo City',
        );

        foreach ([$riderA, $riderB] as $rider) {
            $rider['application']->documents()->update([
                'verification_status' => 'verified',
                'verified_by' => $provider['user']->id,
                'verified_at' => now(),
                'rejection_reason' => null,
            ]);

            app(RegistrationLifecycleService::class)->approve(
                $rider['user'],
                $provider['user'],
            );
        }

        $profileA = $riderA['user']->riderProfile()->firstOrFail();
        $profileB = $riderB['user']->riderProfile()->firstOrFail();

        $active = $this->makeWorkloadAssignment(
            $provider,
            $profileA,
            'ACTIVE',
            2,
            ParcelStatus::OutForDelivery->value,
        );

        $completed = $this->makeWorkloadAssignment(
            $provider,
            $profileA,
            'COMPLETED',
            3,
            ParcelStatus::Delivered->value,
        );
        $completedAt = now('Asia/Manila')
            ->startOfDay()
            ->addHours(9)
            ->utc();

        foreach ($completed['parcels'] as $parcel) {
            $parcel->deliveryAttempts()->create([
                'dispatch_batch_id' => $completed['batch']->id,
                'rider_profile_id' => $profileA->id,
                'attempt_no' => 1,
                'outcome' => DeliveryAttemptOutcome::Delivered->value,
                'attempted_at' => $completedAt,
            ]);
        }

        $foreignRiderCompleted = $this->makeWorkloadAssignment(
            $provider,
            $profileB,
            'FOREIGN',
            1,
            ParcelStatus::Delivered->value,
        );
        $foreignRiderCompleted['parcels']->firstOrFail()
            ->deliveryAttempts()
            ->create([
                'dispatch_batch_id' => $foreignRiderCompleted['batch']->id,
                'rider_profile_id' => $profileB->id,
                'attempt_no' => 1,
                'outcome' => DeliveryAttemptOutcome::Delivered->value,
                'attempted_at' => $completedAt,
            ]);

        $response = $this
            ->actingAs($provider['user'])
            ->get(route('logistics.riders.index'));

        $response
            ->assertOk()
            ->assertSeeText('Active jobs')
            ->assertSeeText('Completed today');

        $response->assertViewHas(
            'riders',
            function (array $riders) use ($profileA, $profileB): bool {
                $rows = collect($riders)->keyBy('profile_id');
                $rowA = $rows->get($profileA->id);
                $rowB = $rows->get($profileB->id);

                return $rowA !== null
                    && $rowA['active_jobs'] === 1
                    && $rowA['completed_today'] === 1
                    && $rowB !== null
                    && $rowB['active_jobs'] === 0
                    && $rowB['completed_today'] === 1;
            },
        );
    }

    public function test_rider_detail_cannot_be_opened_by_another_logistics_provider(): void
    {
        $providerA = $this->makeLogisticsProvider(
            'A',
            'owner@example.test'
        );

        $providerB = $this->makeLogisticsProvider(
            'B',
            'foreign@example.test'
        );

        $rider = $this->makePendingRider(
            $providerA['user'],
            'PRIVATE',
            'San Pablo City'
        );

        $this
            ->actingAs($providerB['user'])
            ->get(
                route(
                    'logistics.riders.show',
                    $rider['user']->id
                )
            )
            ->assertNotFound();
    }

    public function test_owning_logistics_can_update_local_rider_capacity_and_audit_it(): void
    {
        $provider = $this->makeLogisticsProvider(
            'CAPACITY',
            'capacity-provider@example.test',
        );
        $rider = $this->makePendingRider(
            $provider['user'],
            'CAPACITY',
            'San Pablo City',
        );

        $rider['application']->documents()->update([
            'verification_status' => 'verified',
            'verified_by' => $provider['user']->id,
            'verified_at' => now(),
            'rejection_reason' => null,
        ]);

        app(RegistrationLifecycleService::class)->approve(
            $rider['user'],
            $provider['user'],
        );

        $profile = $rider['user']->riderProfile()->firstOrFail();
        $profile->update([
            'work_type' => 'local',
            'parcel_capacity' => 12,
        ]);

        $this
            ->actingAs($provider['user'])
            ->patch(
                route('logistics.riders.capacity.update', $rider['user']),
                ['parcel_capacity' => 24],
            )
            ->assertRedirect(route('logistics.riders.show', $rider['user']))
            ->assertSessionHas(
                'success',
                'Local Rider parcel capacity was updated.',
            );

        $this->assertSame(24, $profile->fresh()->parcel_capacity);

        $audit = AuditLog::query()
            ->where('action', 'rider.parcel_capacity_updated')
            ->where('auditable_id', $profile->id)
            ->latest('created_at')
            ->firstOrFail();

        $this->assertSame($provider['user']->id, $audit->actor_user_id);
        $this->assertSame(12, $audit->old_values['parcel_capacity']);
        $this->assertSame(24, $audit->new_values['parcel_capacity']);
    }

    public function test_logistics_cannot_lower_capacity_below_active_dispatch_load(): void
    {
        $provider = $this->makeLogisticsProvider(
            'LOAD',
            'capacity-load-provider@example.test',
        );
        $rider = $this->makePendingRider(
            $provider['user'],
            'LOAD',
            'San Pablo City',
        );

        $rider['application']->documents()->update([
            'verification_status' => 'verified',
            'verified_by' => $provider['user']->id,
            'verified_at' => now(),
            'rejection_reason' => null,
        ]);

        app(RegistrationLifecycleService::class)->approve(
            $rider['user'],
            $provider['user'],
        );

        $profile = $rider['user']->riderProfile()->firstOrFail();
        $profile->update([
            'work_type' => 'local',
            'parcel_capacity' => 5,
        ]);

        $this->makeWorkloadAssignment(
            $provider,
            $profile,
            'CAPACITY-LOAD',
            2,
            ParcelStatus::Dispatched->value,
        );

        $this
            ->actingAs($provider['user'])
            ->patch(
                route('logistics.riders.capacity.update', $rider['user']),
                ['parcel_capacity' => 1],
            )
            ->assertSessionHasErrors('parcel_capacity');

        $this->assertSame(5, $profile->fresh()->parcel_capacity);
    }

    public function test_logistics_cannot_update_a_foreign_rider_capacity(): void
    {
        $providerA = $this->makeLogisticsProvider(
            'OWNED',
            'capacity-owner@example.test',
        );
        $providerB = $this->makeLogisticsProvider(
            'FOREIGN-B',
            'capacity-foreign@example.test',
        );
        $rider = $this->makePendingRider(
            $providerA['user'],
            'OWNED-CAPACITY',
            'San Pablo City',
        );

        $rider['application']->documents()->update([
            'verification_status' => 'verified',
            'verified_by' => $providerA['user']->id,
            'verified_at' => now(),
            'rejection_reason' => null,
        ]);

        app(RegistrationLifecycleService::class)->approve(
            $rider['user'],
            $providerA['user'],
        );

        $profile = $rider['user']->riderProfile()->firstOrFail();
        $profile->update([
            'work_type' => 'local',
            'parcel_capacity' => 12,
        ]);

        $this
            ->actingAs($providerB['user'])
            ->patch(
                route('logistics.riders.capacity.update', $rider['user']),
                ['parcel_capacity' => 24],
            )
            ->assertNotFound();

        $this->assertSame(12, $profile->fresh()->parcel_capacity);
    }

    /**
     * @return array{
     *     user: User,
     *     profile: LogisticsProfile,
     *     address: Address,
     *     center: SortingCenter,
     *     zone: SortingZone
     * }
     */
    private function makeLogisticsProvider(
        string $suffix,
        string $email
    ): array {
        $phone = $this->nextPhone();
        $location = $this->locationFor($suffix);

        $user = User::factory()->create([
            'name' => "Logistics {$suffix}",
            'first_name' => 'Logistics',
            'last_name' => $suffix,
            'email' => $email,
            'phone' => $phone,
            'contact_number' => $phone,
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
            'business_name' => "Logistics {$suffix}",
        ]);

        $profile = LogisticsProfile::query()
            ->create([
                'user_id' => $user->id,
                'legal_name' =>
                    "Logistics {$suffix} Incorporated",
                'display_name' =>
                    "Logistics {$suffix}",
                'contact_phone' =>
                    $phone,
                'psgc_city_code' => $location['code'],
                'status' => 'active',
            ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Sorting center',
            'recipient_name' =>
                "Logistics {$suffix}",
            'phone' => $phone,
            'house_number' => '10',
            'street' => "Hub Road {$suffix}",
            'barangay' => $location['barangay'],
            'city_municipality' => $location['city'],
            'province' => $location['province'],
            'postal_code' => $location['postal_code'],
            'psgc_city_code' => $location['code'],
        ]);

        $center = SortingCenter::query()
            ->create([
                'logistics_profile_id' =>
                    $profile->id,
                'address_id' => $address->id,
                'name' =>
                    "Sorting Center {$suffix}",
                'code' =>
                    "CENTER-{$suffix}",
                'contact_phone' =>
                    $phone,
                'status' => 'active',
            ]);

        $zone = SortingZone::query()->create([
            'sorting_center_id' => $center->id,
            'name' => "Zone {$suffix}",
            'code' => "ZONE-{$suffix}",
            'destination_rules' => [
                'province' => $location['province'],
                'city_municipality' => $location['city'],
                'psgc_city_code' => $location['code'],
                'barangays' => [$location['barangay']],
            ],
        ]);

        return compact(
            'user',
            'profile',
            'address',
            'center',
            'zone'
        );
    }

    /**
     * @return array{
     *     user: User,
     *     application: \App\Models\AccountApplication
     * }
     */
    private function makePendingRider(
        User $sponsor,
        string $suffix,
        string $normalizedCity
    ): array {
        $phone = $this->nextPhone();
        $location = $this->locationFor($normalizedCity);

        $user = User::factory()->create([
            'name' => "Rider {$suffix}",
            'first_name' => 'Rider',
            'last_name' => $suffix,
            'email' =>
                'rider-'
                . strtolower($suffix)
                . '@example.test',
            'phone' => $phone,
            'contact_number' => $phone,
            'role' => UserRole::Rider->value,
            'status' => AccountStatus::Pending->value,
            'logistics_id' => $sponsor->id,

            /*
             * Deliberately different from normalized
             * application address.
             */
            'city' => 'Legacy City',

            'vehicle_type' => 'Motorcycle',
            'plate_number' => "PLATE-{$suffix}",
            'driver_license_path' =>
                "registration-documents/riders/{$suffix}/license.pdf",
            'or_cr_path' =>
                "registration-documents/riders/{$suffix}/or-cr.pdf",
        ]);

        $application =
            app(RegistrationLifecycleService::class)
                ->recordPendingApplication(
                    $user,
                    UserRole::Rider->value,
                    [
                        'house_number' => '24',
                        'street' => 'Rider Street',
                        'barangay' => 'San Rafael',
                        'municipality' => $normalizedCity,
                        'province' => 'Laguna',
                        'postal_code' => $location['postal_code'],
                        'city_code' => $location['code'],
                    ],
                    [],
                    [],
                    $sponsor->id
                );

        $application->documents()->create([
            'document_type' => 'driver_license',
            'file_path' =>
                "test/{$suffix}/driver-license.pdf",
            'original_name' =>
                'driver-license.pdf',
            'mime_type' =>
                'application/pdf',
            'size_bytes' => 100,
            'verification_status' => 'pending',
        ]);

        $application->documents()->create([
            'document_type' => 'or_cr',
            'file_path' =>
                "test/{$suffix}/or-cr.pdf",
            'original_name' =>
                'or-cr.pdf',
            'mime_type' =>
                'application/pdf',
            'size_bytes' => 100,
            'verification_status' => 'pending',
        ]);

        return compact(
            'user',
            'application'
        );
    }

    /**
     * @return array{batch: DispatchBatch, shipment: Shipment, parcels: \Illuminate\Support\Collection<int, Parcel>}
     */
    private function makeWorkloadAssignment(
        array $provider,
        RiderProfile $rider,
        string $suffix,
        int $parcelCount,
        string $parcelStatus,
    ): array {
        $buyer = User::factory()->create([
            'role' => UserRole::Buyer->value,
            'status' => AccountStatus::Active->value,
        ]);
        $seller = User::factory()->create([
            'role' => UserRole::Seller->value,
            'status' => AccountStatus::Active->value,
        ]);
        $sellerProfile = SellerProfile::query()->create([
            'user_id' => $seller->id,
            'legal_business_name' => "Workload Seller {$suffix}",
            'standing_status' => 'good_standing',
        ]);
        $store = Store::query()->create([
            'seller_profile_id' => $sellerProfile->id,
            'name' => "Workload Store {$suffix}",
            'slug' => 'workload-store-'.strtolower($suffix),
            'publication_status' => 'published',
        ]);
        $order = Order::query()->create([
            'order_no' => "WORKLOAD-ORDER-{$suffix}",
            'buyer_id' => $buyer->id,
            'recipient_name' => "Workload Recipient {$suffix}",
            'recipient_phone' => '09171234567',
            'address_line' => 'Workload Street',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
            'status' => 'processing',
            'payment_status' => 'paid',
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_no' => "WORKLOAD-SO-{$suffix}",
            'order_id' => $order->id,
            'store_id' => $store->id,
            'status' => 'ready_for_pickup',
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
        ]);
        $shipment = Shipment::query()->create([
            'shipment_no' => "WORKLOAD-SHIP-{$suffix}",
            'seller_order_id' => $sellerOrder->id,
            'logistics_profile_id' => $provider['profile']->id,
            'status' => ShipmentStatus::OutForDelivery->value,
            'shipping_fee_minor' => 0,
            'cod_amount_minor' => 0,
        ]);
        $waybill = Waybill::query()->create([
            'shipment_id' => $shipment->id,
            'waybill_no' => "WORKLOAD-WB-{$suffix}",
            'scan_token' => hash('sha256', "workload-scan-{$suffix}"),
            'barcode_value' => "WORKLOAD-BARCODE-{$suffix}",
            'piece_count' => $parcelCount,
            'total_weight_kg' => 1.0,
            'status' => 'generated',
            'generated_at' => now(),
        ]);
        $parcels = collect();

        for ($sequence = 1; $sequence <= $parcelCount; $sequence++) {
            $parcels->push(Parcel::query()->create([
                'shipment_id' => $shipment->id,
                'waybill_id' => $waybill->id,
                'parcel_no' => "WORKLOAD-PARCEL-{$suffix}-{$sequence}",
                'piece_sequence' => $sequence,
                'weight_kg' => 1.0,
                'size_class' => 'small',
                'status' => $parcelStatus,
                'current_sorting_center_id' => $provider['center']->id,
                'current_zone_id' => $rider->current_zone_id,
                'last_event_at' => now(),
            ]));
        }
        $batch = DispatchBatch::query()->create([
            'batch_no' => "WORKLOAD-BATCH-{$suffix}",
            'sorting_center_id' => $provider['center']->id,
            'sorting_zone_id' => $rider->current_zone_id,
            'rider_profile_id' => $rider->id,
            'status' => 'dispatched',
            'prepared_by' => $provider['user']->id,
            'prepared_at' => now(),
            'assigned_at' => now(),
            'dispatched_at' => now(),
        ]);

        foreach ($parcels as $sequence => $parcel) {
            $batch->parcels()->attach($parcel->id, [
                'sequence' => $sequence + 1,
                'loaded_at' => now(),
            ]);
        }

        return compact('batch', 'shipment', 'parcels');
    }

    /** @return array{code: string, city: string, province: string, barangay: string, postal_code: string} */
    private function locationFor(string $value): array
    {
        $calamba = str_contains(strtolower($value), 'calamba')
            || preg_match('/(?:^|-)B$/i', $value) === 1;

        return $calamba
            ? ['code' => '0403405000', 'city' => 'Calamba City', 'province' => 'Laguna', 'barangay' => 'San Rafael', 'postal_code' => '4027']
            : ['code' => '0403424000', 'city' => 'San Pablo City', 'province' => 'Laguna', 'barangay' => 'San Rafael', 'postal_code' => '4000'];
    }

    private function nextPhone(): string
    {
        return sprintf('+63917%07d', ++$this->phoneSequence);
    }
}
