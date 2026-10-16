<?php

namespace Tests\Feature\Logistics;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_logistics_can_assign_own_rider_to_own_active_center_and_zone(): void
    {
        $provider = $this->makeProvider(
            'A',
            'provider-a@example.test'
        );

        $center = $this->makeCenter(
            $provider,
            'MAIN'
        );

        $zone = $this->makeZone(
            $center,
            'NORTH'
        );

        $rider = $this->makeRider(
            $provider,
            'OWN'
        );

        $this
            ->actingAs($provider['user'])
            ->patch(
                route(
                    'logistics.riders.assignment.update',
                    $rider['user']
                ),
                [
                    'home_sorting_center_id' =>
                        $center->id,

                    'current_zone_id' =>
                        $zone->id,
                ]
            )
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'success',
                'Rider assignment was refreshed from the registered home barangay.'
            );

        $rider['profile']->refresh();

        $this->assertSame(
            $center->id,
            $rider['profile']
                ->home_sorting_center_id
        );

        $this->assertSame(
            $zone->id,
            $rider['profile']
                ->current_zone_id
        );
    }

    public function test_rider_assignment_refresh_uses_home_area_not_submitted_center(): void
    {
        $provider = $this->makeProvider(
            'A',
            'provider-a@example.test'
        );

        $center = $this->makeCenter($provider, 'MAIN');
        $zone = $this->makeZone($center, 'MAIN');
        $otherProvider = $this->makeProvider(
            'B',
            'provider-b@example.test'
        );
        $foreignCenter = $this->makeCenter($otherProvider, 'FOREIGN');

        $rider = $this->makeRider(
            $provider,
            'NOZONE',
            $center,
            $zone
        );

        $this
            ->actingAs($provider['user'])
            ->patch(
                route(
                    'logistics.riders.assignment.update',
                    $rider['user']
                ),
                [
                    'home_sorting_center_id' =>
                        $foreignCenter->id,

                    'current_zone_id' =>
                        null,
                ]
            )
            ->assertSessionHasNoErrors();

        $rider['profile']->refresh();

        $this->assertSame($center->id, $rider['profile']->home_sorting_center_id);
        $this->assertSame($zone->id, $rider['profile']->current_zone_id);
        $this->assertNotSame($foreignCenter->id, $rider['profile']->home_sorting_center_id);
    }

    public function test_logistics_cannot_assign_another_providers_rider(): void
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
        $riderB = $this->makeRider(
            $providerB,
            'FOREIGN'
        );

        $this
            ->actingAs($providerA['user'])
            ->patch(
                route(
                    'logistics.riders.assignment.update',
                    $riderB['user']
                ),
                [
                    'home_sorting_center_id' =>
                        $centerA->id,

                    'current_zone_id' =>
                        null,
                ]
            )
            ->assertNotFound();

        $riderB['profile']->refresh();

        $this->assertSame(
            $providerB['profile']->id,
            $riderB['profile']
                ->logistics_profile_id
        );
    }

    public function test_logistics_cannot_assign_rider_to_foreign_sorting_center(): void
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
        $zoneA = $this->makeZone($centerA, 'A');

        $centerB = $this->makeCenter(
            $providerB,
            'B'
        );

        $rider = $this->makeRider(
            $providerA,
            'CENTER',
            $centerA,
            $zoneA
        );

        $this
            ->actingAs($providerA['user'])
            ->patch(
                route(
                    'logistics.riders.assignment.update',
                    $rider['user']
                ),
                [
                    'home_sorting_center_id' =>
                        $centerB->id,

                    'current_zone_id' =>
                        null,
                ]
            )
            ->assertSessionHasNoErrors();

        $rider['profile']->refresh();

        $this->assertSame(
            $centerA->id,
            $rider['profile']
                ->home_sorting_center_id
        );
        $this->assertSame($zoneA->id, $rider['profile']->current_zone_id);
    }

    public function test_submitted_zone_cannot_override_home_area_match(): void
    {
        $provider = $this->makeProvider(
            'A',
            'provider@example.test'
        );

        $centerA = $this->makeCenter(
            $provider,
            'A'
        );

        $zoneB = $this->makeZone($centerA, 'B');
        $zoneB->update([
            'destination_rules' => [
                'province' => 'Laguna',
                'city_municipality' => 'San Pablo City',
                'psgc_city_code' => $provider['profile']->psgc_city_code,
                'barangays' => ['Different Barangay'],
            ],
        ]);
        $zoneA = $this->makeZone($centerA, 'A');

        $rider = $this->makeRider(
            $provider,
            'ZONE',
            $centerA
        );

        $this
            ->actingAs($provider['user'])
            ->patch(
                route(
                    'logistics.riders.assignment.update',
                    $rider['user']
                ),
                [
                    'home_sorting_center_id' =>
                        $centerA->id,

                    /*
                     * Valid provider, but wrong center.
                     */
                    'current_zone_id' =>
                        $zoneB->id,
                ]
            )
            ->assertSessionHasNoErrors();

        $rider['profile']->refresh();

        $this->assertSame(
            $centerA->id,
            $rider['profile']
                ->home_sorting_center_id
        );

        $this->assertSame($zoneA->id, $rider['profile']->current_zone_id);
        $this->assertNotSame($zoneB->id, $rider['profile']->current_zone_id);
    }

    public function test_inactive_sorting_center_cannot_be_assigned(): void
    {
        $provider = $this->makeProvider(
            'A',
            'provider@example.test'
        );

        $inactiveCenter = $this->makeCenter(
            $provider,
            'INACTIVE',
            'inactive'
        );
        $inactiveZone = $this->makeZone($inactiveCenter, 'INACTIVE-ZONE');

        $rider = $this->makeRider(
            $provider,
            'INACTIVE-CENTER',
            $inactiveCenter,
            $inactiveZone
        );

        $this
            ->actingAs($provider['user'])
            ->patch(
                route(
                    'logistics.riders.assignment.update',
                    $rider['user']
                ),
                [
                    'home_sorting_center_id' =>
                        $inactiveCenter->id,

                    'current_zone_id' =>
                        null,
                ]
            )
            ->assertSessionHasNoErrors();

        $rider['profile']->refresh();

        $this->assertNull($rider['profile']->home_sorting_center_id);
        $this->assertNull($rider['profile']->current_zone_id);
    }

    public function test_inactive_zone_cannot_be_assigned(): void
    {
        $provider = $this->makeProvider(
            'A',
            'provider@example.test'
        );

        $center = $this->makeCenter(
            $provider,
            'MAIN'
        );

        $inactiveZone = $this->makeZone(
            $center,
            'INACTIVE',
            'inactive'
        );

        $rider = $this->makeRider(
            $provider,
            'INACTIVE-ZONE',
            $center
        );

        $this
            ->actingAs($provider['user'])
            ->patch(
                route(
                    'logistics.riders.assignment.update',
                    $rider['user']
                ),
                [
                    'home_sorting_center_id' =>
                        $center->id,

                    'current_zone_id' =>
                        $inactiveZone->id,
                ]
            )
            ->assertSessionHasNoErrors();

        $rider['profile']->refresh();

        $this->assertNull($rider['profile']->home_sorting_center_id);
        $this->assertNull($rider['profile']->current_zone_id);
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

        $user
            ->roles()
            ->syncWithoutDetaching([
                $roleId,
            ]);

        $location = $this->locationFor($suffix);

        $profile =
            LogisticsProfile::query()
                ->create([
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
        $address = Address::query()
            ->create([
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

                'barangay' => $location['barangay'],

                'city_municipality' =>
                    $location['city'],

                'province' =>
                    $location['province'],

                'postal_code' =>
                    $location['postal_code'],

                'psgc_city_code' => $location['code'],
            ]);

        return SortingCenter::query()
            ->create([
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
        string $suffix,
        string $status = 'active'
    ): SortingZone {
        $location = $this->locationFor((string) $center->logisticsProfile->psgc_city_code);

        return SortingZone::query()
            ->create([
                'sorting_center_id' =>
                    $center->id,

                'code' =>
                    "ZONE-{$suffix}",

                'name' =>
                    "Zone {$suffix}",

                'destination_rules' =>
                    [
                        'province' => $location['province'],
                        'city_municipality' => $location['city'],
                        'psgc_city_code' => $location['code'],
                        'barangays' => [$location['barangay']],
                    ],

                'status' =>
                    $status,
            ]);
    }

    /**
     * @return array{
     *     user: User,
     *     profile: RiderProfile
     * }
     */
    private function makeRider(
        array $provider,
        string $suffix,
        ?SortingCenter $center = null,
        ?SortingZone $zone = null
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
                AccountStatus::Active->value,

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

        $profile = RiderProfile::query()
            ->create([
                'user_id' =>
                    $user->id,

                'logistics_profile_id' =>
                    $provider['profile']->id,

                'home_sorting_center_id' =>
                    $center?->id,

                'current_zone_id' =>
                    $zone?->id,

                'vehicle_type' =>
                    'Motorcycle',

                'plate_number' =>
                    "PLATE-{$suffix}",

                'availability_status' =>
                    'offline',

                'verification_status' =>
                    'approved',
            ]);

        return compact(
            'user',
            'profile'
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
