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
                'Rider assignment was updated.'
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

    public function test_logistics_can_assign_center_without_current_zone(): void
    {
        $provider = $this->makeProvider(
            'A',
            'provider-a@example.test'
        );

        $oldCenter = $this->makeCenter(
            $provider,
            'OLD'
        );

        $oldZone = $this->makeZone(
            $oldCenter,
            'OLD'
        );

        $newCenter = $this->makeCenter(
            $provider,
            'NEW'
        );

        $rider = $this->makeRider(
            $provider,
            'NOZONE',
            $oldCenter,
            $oldZone
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
                        $newCenter->id,

                    'current_zone_id' =>
                        null,
                ]
            )
            ->assertSessionHasNoErrors();

        $rider['profile']->refresh();

        $this->assertSame(
            $newCenter->id,
            $rider['profile']
                ->home_sorting_center_id
        );

        $this->assertNull(
            $rider['profile']
                ->current_zone_id
        );
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

        $centerB = $this->makeCenter(
            $providerB,
            'B'
        );

        $rider = $this->makeRider(
            $providerA,
            'CENTER',
            $centerA
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
            ->assertNotFound();

        $rider['profile']->refresh();

        $this->assertSame(
            $centerA->id,
            $rider['profile']
                ->home_sorting_center_id
        );
    }

    public function test_zone_must_belong_to_selected_sorting_center(): void
    {
        $provider = $this->makeProvider(
            'A',
            'provider@example.test'
        );

        $centerA = $this->makeCenter(
            $provider,
            'A'
        );

        $centerB = $this->makeCenter(
            $provider,
            'B'
        );

        $zoneB = $this->makeZone(
            $centerB,
            'B'
        );

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
            ->assertNotFound();

        $rider['profile']->refresh();

        $this->assertSame(
            $centerA->id,
            $rider['profile']
                ->home_sorting_center_id
        );

        $this->assertNull(
            $rider['profile']
                ->current_zone_id
        );
    }

    public function test_inactive_sorting_center_cannot_be_assigned(): void
    {
        $provider = $this->makeProvider(
            'A',
            'provider@example.test'
        );

        $activeCenter = $this->makeCenter(
            $provider,
            'ACTIVE'
        );

        $inactiveCenter = $this->makeCenter(
            $provider,
            'INACTIVE',
            'inactive'
        );

        $rider = $this->makeRider(
            $provider,
            'INACTIVE-CENTER',
            $activeCenter
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
            ->assertNotFound();

        $rider['profile']->refresh();

        $this->assertSame(
            $activeCenter->id,
            $rider['profile']
                ->home_sorting_center_id
        );
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
            ->assertNotFound();

        $rider['profile']->refresh();

        $this->assertSame(
            $center->id,
            $rider['profile']
                ->home_sorting_center_id
        );

        $this->assertNull(
            $rider['profile']
                ->current_zone_id
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

                    'status' =>
                        'active',
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

                'barangay' =>
                    'San Rafael',

                'city_municipality' =>
                    'San Pablo City',

                'province' =>
                    'Laguna',

                'postal_code' =>
                    '4000',
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
        return SortingZone::query()
            ->create([
                'sorting_center_id' =>
                    $center->id,

                'code' =>
                    "ZONE-{$suffix}",

                'name' =>
                    "Zone {$suffix}",

                'destination_rules' =>
                    [],

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
}
