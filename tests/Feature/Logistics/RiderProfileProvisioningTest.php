<?php

namespace Tests\Feature\Logistics;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\User;
use App\Services\RegistrationLifecycleService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderProfileProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_newly_approved_rider_receives_sponsors_active_sorting_center(): void
    {
        $provider = $this->makeProvider(
            'A',
            'provider-a@example.test'
        );

        $activeCenter = $this->makeCenter(
            $provider,
            'ACTIVE',
            'active'
        );
        $activeZone = $this->makeZone($activeCenter, 'ACTIVE-MATCH');

        $rider = $this->makeRiderApplication(
            $provider,
            'NEW'
        );

        app(RegistrationLifecycleService::class)
            ->approve(
                $rider['user'],
                $provider['user']
            );

        $profile = $rider['user']
            ->fresh()
            ->riderProfile()
            ->firstOrFail();

        $this->assertSame(
            $provider['profile']->id,
            $profile->logistics_profile_id
        );

        $this->assertSame(
            $activeCenter->id,
            $profile->home_sorting_center_id
        );

        $this->assertSame($activeZone->id, $profile->current_zone_id);

        $this->assertSame(
            'Motorcycle',
            $profile->vehicle_type
        );

        $this->assertSame(
            'PLATE-NEW',
            $profile->plate_number
        );

        $this->assertSame(
            'offline',
            $profile->availability_status
        );

        $this->assertSame(
            'approved',
            $profile->verification_status
        );
    }

    public function test_existing_valid_center_zone_and_availability_are_preserved(): void
    {
        $provider = $this->makeProvider(
            'A',
            'preserve@example.test'
        );

        $assignedCenter = $this->makeCenter(
            $provider,
            'ASSIGNED'
        );

        $assignedZone = $this->makeZone(
            $assignedCenter,
            'ASSIGNED'
        );

        $rider = $this->makeRiderApplication(
            $provider,
            'PRESERVE'
        );

        RiderProfile::query()->create([
            'user_id' =>
                $rider['user']->id,

            'logistics_profile_id' =>
                $provider['profile']->id,

            'home_sorting_center_id' =>
                $assignedCenter->id,

            'current_zone_id' =>
                $assignedZone->id,

            /*
             * Deliberately stale values. Approval should
             * synchronize these from User.
             */
            'vehicle_type' =>
                'Old Vehicle',

            'plate_number' =>
                'OLD-PLATE',

            /*
             * Must not be reset to offline.
             */
            'availability_status' =>
                'available',

            'verification_status' =>
                'pending',
        ]);

        app(RegistrationLifecycleService::class)
            ->approve(
                $rider['user'],
                $provider['user']
            );

        $profile = RiderProfile::query()
            ->where(
                'user_id',
                $rider['user']->id
            )
            ->firstOrFail();

        $this->assertSame(
            $assignedCenter->id,
            $profile->home_sorting_center_id
        );

        $this->assertSame(
            $assignedZone->id,
            $profile->current_zone_id
        );

        $this->assertSame(
            'available',
            $profile->availability_status
        );

        $this->assertSame(
            'Motorcycle',
            $profile->vehicle_type
        );

        $this->assertSame(
            'PLATE-PRESERVE',
            $profile->plate_number
        );

        $this->assertSame(
            'approved',
            $profile->verification_status
        );
    }

    public function test_foreign_center_and_zone_are_replaced_with_sponsors_center(): void
    {
        $providerA = $this->makeProvider(
            'A',
            'provider-a@example.test'
        );

        $providerB = $this->makeProvider(
            'B',
            'provider-b@example.test'
        );

        $centerA = $this->makeCenter(
            $providerA,
            'A'
        );
        $zoneA = $this->makeZone($centerA, 'A-MATCH');

        $centerB = $this->makeCenter(
            $providerB,
            'B'
        );

        $zoneB = $this->makeZone(
            $centerB,
            'B'
        );

        $rider = $this->makeRiderApplication(
            $providerA,
            'FOREIGN'
        );

        RiderProfile::query()->create([
            'user_id' =>
                $rider['user']->id,

            /*
             * Simulate stale/incorrect operational data.
             */
            'logistics_profile_id' =>
                $providerB['profile']->id,

            'home_sorting_center_id' =>
                $centerB->id,

            'current_zone_id' =>
                $zoneB->id,

            'vehicle_type' =>
                'Motorcycle',

            'plate_number' =>
                'FOREIGN-OLD',

            'availability_status' =>
                'available',

            'verification_status' =>
                'pending',
        ]);

        app(RegistrationLifecycleService::class)
            ->approve(
                $rider['user'],
                $providerA['user']
            );

        $profile = RiderProfile::query()
            ->where(
                'user_id',
                $rider['user']->id
            )
            ->firstOrFail();

        $this->assertSame(
            $providerA['profile']->id,
            $profile->logistics_profile_id
        );

        $this->assertSame(
            $centerA->id,
            $profile->home_sorting_center_id
        );

        $this->assertSame($zoneA->id, $profile->current_zone_id);

        /*
         * Existing availability still remains an
         * operational concern and is not reset merely
         * because assignment data was repaired.
         */
        $this->assertSame(
            'available',
            $profile->availability_status
        );
    }

    /**
     * @return array{
     *     user: User,
     *     profile: LogisticsProfile
     * }
     */
    private function makeProvider(
        string $suffix,
        string $email
    ): array {
        $location = $this->locationFor($suffix);
        $user = User::factory()->create([
            'name' =>
                "Logistics {$suffix}",
            'email' =>
                $email,
            'role' =>
                UserRole::Logistics->value,
            'status' =>
                AccountStatus::Active->value,
            'business_name' =>
                "Logistics {$suffix}",
        ]);

        $roleId = Role::query()
            ->where(
                'name',
                UserRole::Logistics->value
            )
            ->value('id');

        $user->roles()
            ->syncWithoutDetaching([
                $roleId,
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
                    '09170000001',
                'psgc_city_code' => $location['code'],
                'status' =>
                    'active',
            ]);

        Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Sorting center',
            'recipient_name' => $user->name,
            'phone' => '09170000001',
            'street' => 'Service Road',
            'barangay' => $location['barangay'],
            'city_municipality' => $location['city'],
            'province' => $location['province'],
            'postal_code' => $location['postal_code'],
            'psgc_city_code' => $location['code'],
        ]);

        return compact(
            'user',
            'profile'
        );
    }

    private function makeCenter(
        array $provider,
        string $suffix,
        string $status = 'active'
    ): SortingCenter {
        $location = $this->locationFor((string) $provider['profile']->psgc_city_code);
        $address = Address::query()->create([
            'user_id' =>
                $provider['user']->id,
            'label' =>
                "Sorting Center {$suffix}",
            'recipient_name' =>
                $provider['user']->name,
            'phone' =>
                '09170000001',
            'house_number' =>
                '10',
            'street' =>
                "Hub Road {$suffix}",
            'barangay' =>
                $location['barangay'],
            'city_municipality' =>
                $location['city'],
            'province' =>
                $location['province'],
            'postal_code' =>
                $location['postal_code'],
            'psgc_city_code' => $location['code'],
        ]);

        return SortingCenter::query()->create([
            'logistics_profile_id' =>
                $provider['profile']->id,
            'address_id' =>
                $address->id,
            'name' =>
                "Sorting Center {$suffix}",
            'code' =>
                "SC-{$suffix}-"
                .$provider['profile']->id,
            'contact_phone' =>
                '09170000001',
            'status' =>
                $status,
        ]);
    }

    private function makeZone(
        SortingCenter $center,
        string $suffix
    ): SortingZone {
        $location = $this->locationFor((string) $center->logisticsProfile->psgc_city_code);

        return SortingZone::query()->create([
            'sorting_center_id' =>
                $center->id,
            'code' =>
                "ZONE-{$suffix}",
            'name' =>
                "Zone {$suffix}",
            'destination_rules' => [
                'province' => $location['province'],
                'city_municipality' => $location['city'],
                'psgc_city_code' => $location['code'],
                'barangays' => [$location['barangay']],
            ],
            'status' =>
                'active',
        ]);
    }

    /**
     * @return array{
     *     user: User,
     *     application: AccountApplication
     * }
     */
    private function makeRiderApplication(
        array $provider,
        string $suffix
    ): array {
        $location = $this->locationFor((string) $provider['profile']->psgc_city_code);
        $user = User::factory()->create([
            'name' =>
                "Rider {$suffix}",
            'email' =>
                'rider-'
                .strtolower($suffix)
                .'@example.test',
            'role' =>
                UserRole::Rider->value,
            'status' =>
                AccountStatus::Pending->value,
            'logistics_id' =>
                $provider['user']->id,
            'vehicle_type' =>
                'Motorcycle',
            'plate_number' =>
                "PLATE-{$suffix}",
        ]);

        Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Home',
            'recipient_name' => $user->name,
            'phone' => '09170000001',
            'street' => 'Rider Street',
            'barangay' => $location['barangay'],
            'city_municipality' => $location['city'],
            'province' => $location['province'],
            'postal_code' => $location['postal_code'],
            'psgc_city_code' => $location['code'],
        ]);

        $application =
            AccountApplication::query()->create([
                'application_no' =>
                    "APP-RIDER-{$suffix}",
                'user_id' =>
                    $user->id,
                'requested_role_id' =>
                    Role::query()
                        ->where(
                            'name',
                            UserRole::Rider->value
                        )
                        ->value('id'),
                'sponsor_logistics_profile_id' =>
                    $provider['profile']->id,
                'status' =>
                    'under_review',
                'submitted_at' =>
                    now(),
                'review_started_at' =>
                    now(),
            ]);

        foreach (
            [
                'driver_license',
                'or_cr',
            ] as $documentType
        ) {
            $application
                ->documents()
                ->create([
                    'document_type' =>
                        $documentType,
                    'file_path' =>
                        "test/{$suffix}/{$documentType}.pdf",
                    'original_name' =>
                        "{$documentType}.pdf",
                    'mime_type' =>
                        'application/pdf',
                    'size_bytes' =>
                        100,
                    'verification_status' =>
                        'verified',
                    'verified_by' =>
                        $provider['user']->id,
                    'verified_at' =>
                        now(),
                ]);
        }

        return compact(
            'user',
            'application'
        );
    }

    /** @return array{code: string, city: string, province: string, barangay: string, postal_code: string} */
    private function locationFor(string $value): array
    {
        $calamba = $value === 'B' || $value === '0403405000';

        return $calamba
            ? ['code' => '0403405000', 'city' => 'Calamba City', 'province' => 'Laguna', 'barangay' => 'San Rafael', 'postal_code' => '4027']
            : ['code' => '0403424000', 'city' => 'San Pablo City', 'province' => 'Laguna', 'barangay' => 'San Rafael', 'postal_code' => '4000'];
    }
}
