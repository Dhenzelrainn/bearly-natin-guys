<?php

namespace Tests\Feature\Logistics;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\RiderProfile;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\User;
use App\Services\RegistrationLifecycleService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderManagementDataTest extends TestCase
{
    use RefreshDatabase;

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

        $response->assertOk();

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
                        === 'Active';
            }
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
        $user = User::factory()->create([
            'name' => "Logistics {$suffix}",
            'first_name' => 'Logistics',
            'last_name' => $suffix,
            'email' => $email,
            'contact_number' => '09170000001',
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
                    '09170000001',
                'status' => 'active',
            ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Sorting center',
            'recipient_name' =>
                "Logistics {$suffix}",
            'phone' => '09170000001',
            'house_number' => '10',
            'street' => "Hub Road {$suffix}",
            'barangay' => 'San Rafael',
            'city_municipality' =>
                'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
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
                    '09170000001',
                'status' => 'active',
            ]);

        $zone = SortingZone::query()->create([
            'sorting_center_id' => $center->id,
            'name' => "Zone {$suffix}",
            'code' => "ZONE-{$suffix}",
            'destination_rules' => [],
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
        $user = User::factory()->create([
            'name' => "Rider {$suffix}",
            'first_name' => 'Rider',
            'last_name' => $suffix,
            'email' =>
                'rider-'
                . strtolower($suffix)
                . '@example.test',
            'contact_number' => '09181234567',
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
                        'postal_code' => '4000',
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
}