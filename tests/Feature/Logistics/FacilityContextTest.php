<?php

namespace Tests\Feature\Logistics;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\SortingCenter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacilityContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_uses_real_sorting_center_as_active_facility(): void
    {
        [
            'user' => $user,
            'profile' => $profile,
            'center' => $center,
        ] = $this->makeLogisticsFacility();

        $response = $this
            ->actingAs($user)
            ->get(route('logistics.dashboard'));

        $response->assertOk();

        $response->assertViewHas(
            'activeFacility',
            function (array $facility) use ($center): bool {
                return $facility['id'] === $center->id
                    && $facility['name'] === 'Real South Laguna Hub'
                    && $facility['code'] === 'SC-REAL-001'
                    && $facility['status'] === 'active';
            }
        );

        $response->assertViewHas(
            'operator',
            function (array $operator): bool {
                return $operator['business_name']
                        === 'Real South Laguna Hub'
                    && $operator['contact']
                        === '09171111111';
            }
        );

        $response->assertSee(
            'Real South Laguna Hub'
        );
    }

    public function test_account_page_reads_real_facility_and_keeps_operator_contact_separate(): void
    {
        [
            'user' => $user,
            'center' => $center,
        ] = $this->makeLogisticsFacility();

        $response = $this
            ->actingAs($user)
            ->get(route('logistics.profile.index'));

        $response->assertOk();

        $response->assertViewHas(
            'operator',
            function (array $operator): bool {
                return $operator['name']
                        === 'Logistics Operator'
                    && $operator['email']
                        === 'operator@example.test'
                    && $operator['contact']
                        === '09171111111';
            }
        );

        $response->assertViewHas(
            'facility',
            function (array $facility) use ($center): bool {
                return $facility['id'] === $center->id
                    && $facility['business_name']
                        === 'Real South Laguna Hub'
                    && $facility['code']
                        === 'SC-REAL-001'
                    && $facility['contact']
                        === '09172222222'
                    && $facility['address']
                        === '12, Hub Street, San Rafael, San Pablo City, Laguna, 4000'
                    && $facility['operating_hours']
                        === '08:00-18:00'
                    && $facility['daily_capacity']
                        === 500
                    && $facility['status']
                        === 'active';
            }
        );
    }

    public function test_facility_context_is_scoped_to_authenticated_logistics_profile(): void
    {
        [
            'user' => $user,
            'center' => $ownCenter,
        ] = $this->makeLogisticsFacility();

        $foreignUser = User::factory()->create([
            'name' => 'Foreign Logistics',
            'email' => 'foreign@example.test',
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
            'business_name' => 'Foreign Legacy Business',
        ]);

        $foreignProfile = LogisticsProfile::query()->create([
            'user_id' => $foreignUser->id,
            'legal_name' => 'Foreign Logistics Incorporated',
            'display_name' => 'Foreign Logistics',
            'contact_phone' => '09173333333',
            'status' => 'active',
        ]);

        $foreignAddress = Address::query()->create([
            'user_id' => $foreignUser->id,
            'label' => 'Sorting center',
            'recipient_name' => 'Foreign Logistics',
            'phone' => '09173333333',
            'street' => 'Foreign Street',
            'barangay' => 'Foreign Barangay',
            'city_municipality' => 'Calamba City',
            'province' => 'Laguna',
            'postal_code' => '4027',
        ]);

        SortingCenter::query()->create([
            'logistics_profile_id' => $foreignProfile->id,
            'address_id' => $foreignAddress->id,
            'name' => 'Foreign Sorting Hub',
            'code' => 'SC-FOREIGN',
            'contact_phone' => '09173333333',
            'status' => 'active',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('logistics.dashboard'));

        $response->assertOk();

        $response->assertViewHas(
            'activeFacility',
            function (array $facility) use ($ownCenter): bool {
                return $facility['id'] === $ownCenter->id
                    && $facility['name']
                        === 'Real South Laguna Hub';
            }
        );

        $response->assertDontSee(
            'Foreign Sorting Hub'
        );
    }

    /**
     * @return array{
     *     user: User,
     *     profile: LogisticsProfile,
     *     center: SortingCenter,
     *     address: Address
     * }
     */
    private function makeLogisticsFacility(): array
    {
        $user = User::factory()->create([
            'name' => 'Logistics Operator',
            'first_name' => 'Logistics',
            'last_name' => 'Operator',
            'email' => 'operator@example.test',
            'contact_number' => '09171111111',
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,

            // Intentionally different so the test proves
            // SortingCenter is the preferred source.
            'business_name' => 'Legacy Logistics Name',
        ]);

        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => 'South Laguna Logistics Incorporated',
            'display_name' => 'South Laguna Logistics',
            'contact_phone' => '09170000000',
            'status' => 'active',
        ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Sorting center',
            'recipient_name' => 'South Laguna Logistics',
            'phone' => '09172222222',
            'house_number' => '12',
            'street' => 'Hub Street',
            'barangay' => 'San Rafael',
            'city_municipality' => 'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
        ]);

        $center = SortingCenter::query()->create([
            'logistics_profile_id' => $profile->id,
            'address_id' => $address->id,
            'name' => 'Real South Laguna Hub',
            'code' => 'SC-REAL-001',
            'contact_phone' => '09172222222',
            'operating_hours' => '08:00-18:00',
            'daily_capacity' => 500,
            'status' => 'active',
        ]);

        return compact(
            'user',
            'profile',
            'center',
            'address'
        );
    }
}