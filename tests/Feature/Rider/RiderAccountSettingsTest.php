<?php

namespace Tests\Feature\Rider;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\RiderProfile;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RiderAccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_page_uses_normalized_rider_profile_and_address(): void
    {
        $logisticsUser = User::factory()->create([
            'name' => 'Laguna Logistics Operator',
            'business_name' => 'Laguna Central Logistics',
            'email' => 'logistics@example.test',
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
        ]);

        $logisticsProfile = LogisticsProfile::query()->create([
            'user_id' =>
                $logisticsUser->id,

            'legal_name' =>
                'Laguna Central Logistics Inc.',

            'display_name' =>
                'Laguna Central Logistics',

            'contact_phone' =>
                '09170000000',

            'status' =>
                'active',
        ]);

        $centerAddress = Address::query()->create([
            'user_id' =>
                $logisticsUser->id,

            'label' =>
                'Sorting center',

            'recipient_name' =>
                'Laguna Central Logistics',

            'phone' =>
                '09170000000',

            'house_number' =>
                '10',

            'street' =>
                'Warehouse Road',

            'barangay' =>
                'San Rafael',

            'city_municipality' =>
                'San Pablo City',

            'province' =>
                'Laguna',

            'postal_code' =>
                '4000',
        ]);

        $center = SortingCenter::query()->create([
            'logistics_profile_id' =>
                $logisticsProfile->id,

            'address_id' =>
                $centerAddress->id,

            'name' =>
                'Laguna Central Sorting Hub',

            'code' =>
                'SC-RIDER-ACCOUNT',

            'contact_phone' =>
                '09170000000',

            'status' =>
                'active',
        ]);

        $zone = SortingZone::query()->create([
            'sorting_center_id' =>
                $center->id,

            'code' =>
                'SP-N1',

            'name' =>
                'San Pablo North',

            'status' =>
                'active',
        ]);

        $rider = User::factory()->create([
            'name' =>
                'Juan Rider',

            'first_name' =>
                'Juan',

            'last_name' =>
                'Rider',

            'email' =>
                'rider@example.test',

            'contact_number' =>
                '09181234567',

            'sex' =>
                'male',

            'birthday' =>
                '2000-05-12',

            'role' =>
                UserRole::Rider->value,

            'status' =>
                AccountStatus::Active->value,

            'logistics_id' =>
                $logisticsUser->id,

            /*
             * Deliberately conflicting legacy data.
             * The account page must prefer normalized records.
             */
            'vehicle_type' =>
                'Legacy Motorcycle',

            'plate_number' =>
                'LEG-001',

            'street_address' =>
                'Legacy Street',

            'barangay' =>
                'Legacy Barangay',

            'city' =>
                'Legacy City',

            'province' =>
                'Legacy Province',

            'password' =>
                Hash::make('Password123'),
        ]);

        Address::query()->create([
            'user_id' =>
                $rider->id,

            'label' =>
                'Home',

            'recipient_name' =>
                'Juan Rider',

            'phone' =>
                '09181234567',

            'house_number' =>
                '25',

            'street' =>
                'Rizal Street',

            'barangay' =>
                'Del Remedio',

            'city_municipality' =>
                'San Pablo City',

            'province' =>
                'Laguna',

            'postal_code' =>
                '4000',
        ]);

        RiderProfile::query()->create([
            'user_id' =>
                $rider->id,

            'logistics_profile_id' =>
                $logisticsProfile->id,

            'home_sorting_center_id' =>
                $center->id,

            'current_zone_id' =>
                $zone->id,

            'vehicle_type' =>
                'E-bike',

            'vehicle_model' =>
                'Honda EM1 e:',

            'plate_number' =>
                'RDR-2026',

            'parcel_capacity' =>
                24,

            'emergency_contact_name' =>
                'Maria Rider',

            'emergency_contact_phone' =>
                '09175555555',

            'availability_status' =>
                'available',

            'verification_status' =>
                'approved',
        ]);

        $response = $this
            ->actingAs($rider)
            ->get(
                route('rider.profile.index')
            );

        $response->assertOk();

        $response->assertSee(
            'E-bike'
        );

        $response->assertSee(
            'RDR-2026'
        );

        $response->assertSee(
            'Honda EM1 e:'
        );

        $response->assertSee(
            '24'
        );

        $response->assertSee(
            'Maria Rider'
        );

        $response->assertSee(
            '09175555555'
        );

        $response->assertSee(
            '25, Rizal Street, Brgy. Del Remedio, San Pablo City, Laguna, 4000'
        );

        $response->assertSee(
            'San Pablo North · SP-N1'
        );

        $response->assertSee(
            'Approved rider'
        );

        $response->assertDontSee(
            'Legacy Motorcycle'
        );

        $response->assertDontSee(
            'LEG-001'
        );

        $response->assertDontSee(
            'Legacy Street'
        );
    }
}