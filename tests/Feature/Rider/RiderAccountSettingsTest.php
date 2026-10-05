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

    public function test_rider_can_update_normalized_vehicle_details(): void
    {
        [
            'rider' => $rider,
            'riderProfile' => $riderProfile,
        ] = $this->makeRiderAccount();

        $this
            ->actingAs($rider)
            ->patch(
                route(
                    'rider.profile.vehicle.update'
                ),
                [
                    'vehicle_type' =>
                        'Motorcycle',

                    'plate_number' =>
                        'abc-1234',

                    'vehicle_model' =>
                        'Honda Click 160',

                    'parcel_capacity' =>
                        18,
                ]
            )
            ->assertRedirect(
                route('rider.profile.index')
                . '#vehicle'
            )
            ->assertSessionHas(
                'success',
                'Vehicle details updated successfully.'
            );

        $riderProfile->refresh();
        $rider->refresh();

        $this->assertSame(
            'Motorcycle',
            $riderProfile->vehicle_type
        );

        $this->assertSame(
            'ABC-1234',
            $riderProfile->plate_number
        );

        $this->assertSame(
            'Honda Click 160',
            $riderProfile->vehicle_model
        );

        $this->assertSame(
            18,
            $riderProfile->parcel_capacity
        );

        /*
        * Legacy compatibility fields remain
        * synchronized with RiderProfile.
        */
        $this->assertSame(
            'Motorcycle',
            $rider->vehicle_type
        );

        $this->assertSame(
            'ABC-1234',
            $rider->plate_number
        );
    }

    public function test_vehicle_update_cannot_change_rider_operational_assignment(): void
    {
        [
            'rider' => $rider,
            'riderProfile' => $riderProfile,
        ] = $this->makeRiderAccount();

        $originalLogisticsProfileId =
            $riderProfile->logistics_profile_id;

        $originalHomeSortingCenterId =
            $riderProfile->home_sorting_center_id;

        $originalCurrentZoneId =
            $riderProfile->current_zone_id;

        $originalVerificationStatus =
            $riderProfile->verification_status;

        $originalAvailabilityStatus =
            $riderProfile->availability_status;

        $this
            ->actingAs($rider)
            ->patch(
                route(
                    'rider.profile.vehicle.update'
                ),
                [
                    'vehicle_type' =>
                        'Van',

                    'plate_number' =>
                        'SAFE-2026',

                    'vehicle_model' =>
                        'Toyota LiteAce',

                    'parcel_capacity' =>
                        40,

                    /*
                    * Malicious / out-of-scope input.
                    */
                    'logistics_profile_id' =>
                        999999,

                    'home_sorting_center_id' =>
                        999999,

                    'current_zone_id' =>
                        999999,

                    'verification_status' =>
                        'pending',

                    'availability_status' =>
                        'offline',
                ]
            )
            ->assertRedirect(
                route('rider.profile.index')
                . '#vehicle'
            );

        $riderProfile->refresh();

        $this->assertSame(
            $originalLogisticsProfileId,
            $riderProfile->logistics_profile_id
        );

        $this->assertSame(
            $originalHomeSortingCenterId,
            $riderProfile->home_sorting_center_id
        );

        $this->assertSame(
            $originalCurrentZoneId,
            $riderProfile->current_zone_id
        );

        $this->assertSame(
            $originalVerificationStatus,
            $riderProfile->verification_status
        );

        $this->assertSame(
            $originalAvailabilityStatus,
            $riderProfile->availability_status
        );

        $this->assertSame(
            'Van',
            $riderProfile->vehicle_type
        );

        $this->assertSame(
            'SAFE-2026',
            $riderProfile->plate_number
        );
    }

    public function test_rider_can_update_personal_profile(): void
    {
        [
            'rider' => $rider,
            'riderProfile' => $riderProfile,
        ] = $this->makeRiderAccount();

        $response = $this
            ->actingAs($rider)
            ->patch(
                route('rider.profile.update'),
                [
                    'name' =>
                        'Updated Rider Name',

                    'sex' =>
                        'Female',

                    'contact' =>
                        '09179999999',

                    'birthday' =>
                        '2001-08-15',

                    'emergency_contact_name' =>
                        'Emergency Person',

                    'emergency_contact_phone' =>
                        '09178888888',
                ]
            );

        $response->assertRedirect(
            route('rider.profile.index')
            . '#profile'
        );

        $response->assertSessionHas(
            'success',
            'Rider profile updated successfully.'
        );

        $rider->refresh();
        $riderProfile->refresh();

        $this->assertSame(
            'Updated Rider Name',
            $rider->name
        );

        $this->assertSame(
            'female',
            $rider->sex
        );

        $this->assertSame(
            '09179999999',
            $rider->contact_number
        );

        $this->assertSame(
            '2001-08-15',
            $rider->birthday?->format('Y-m-d')
        );

        $this->assertSame(
            'Emergency Person',
            $riderProfile->emergency_contact_name
        );

        $this->assertSame(
            '09178888888',
            $riderProfile->emergency_contact_phone
        );
    }

    public function test_profile_update_cannot_change_email_role_or_status(): void
    {
        [
            'rider' => $rider,
        ] = $this->makeRiderAccount();

        $originalEmail = $rider->email;

        $this
            ->actingAs($rider)
            ->patch(
                route('rider.profile.update'),
                [
                    'name' =>
                        'Safe Rider',

                    'sex' =>
                        'Male',

                    'contact' =>
                        '09170000000',

                    'birthday' =>
                        '2000-01-01',

                    'emergency_contact_name' =>
                        'Safe Contact',

                    'emergency_contact_phone' =>
                        '09171111111',

                    /*
                     * Unexpected / malicious fields.
                     * These must never modify protected
                     * account attributes.
                     */
                    'email' =>
                        'hijacked@example.test',

                    'role' =>
                        UserRole::Admin->value,

                    'status' =>
                        AccountStatus::Suspended->value,
                ]
            )
            ->assertRedirect(
                route('rider.profile.index')
                . '#profile'
            );

        $rider->refresh();

        $this->assertSame(
            $originalEmail,
            $rider->email
        );

        $this->assertSame(
            UserRole::Rider->value,
            $rider->role
        );

        $this->assertSame(
            AccountStatus::Active->value,
            $rider->status
        );

        $this->assertSame(
            'Safe Rider',
            $rider->name
        );
    }

    public function test_correct_current_password_allows_password_change(): void
    {
        [
            'rider' => $rider,
        ] = $this->makeRiderAccount();

        $rider->update([
            'password' =>
                Hash::make('OldPassword123'),
        ]);

        $this
            ->actingAs($rider)
            ->patch(
                route(
                    'rider.profile.password.update'
                ),
                [
                    'current_password' =>
                        'OldPassword123',

                    'new_password' =>
                        'NewPassword456',

                    'new_password_confirmation' =>
                        'NewPassword456',
                ]
            )
            ->assertRedirect(
                route('rider.profile.index')
                . '#security'
            )
            ->assertSessionHas(
                'success',
                'Password updated successfully.'
            );

        $rider->refresh();

        $this->assertTrue(
            Hash::check(
                'NewPassword456',
                $rider->password
            )
        );

        $this->assertFalse(
            Hash::check(
                'OldPassword123',
                $rider->password
            )
        );
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        [
            'rider' => $rider,
        ] = $this->makeRiderAccount();

        $rider->update([
            'password' =>
                Hash::make('CorrectPassword123'),
        ]);

        $oldHash = $rider->password;

        $this
            ->actingAs($rider)
            ->from(
                route('rider.profile.index')
                . '#security'
            )
            ->patch(
                route(
                    'rider.profile.password.update'
                ),
                [
                    'current_password' =>
                        'WrongPassword123',

                    'new_password' =>
                        'NewPassword456',

                    'new_password_confirmation' =>
                        'NewPassword456',
                ]
            )
            ->assertRedirect(
                route('rider.profile.index')
                . '#security'
            )
            ->assertSessionHasErrors(
                'current_password'
            );

        $rider->refresh();

        $this->assertSame(
            $oldHash,
            $rider->password
        );

        $this->assertTrue(
            Hash::check(
                'CorrectPassword123',
                $rider->password
            )
        );
    }

    public function test_rider_can_update_normalized_home_address(): void
    {
        [
            'rider' => $rider,
            'riderAddress' => $riderAddress,
        ] = $this->makeRiderAccount();

        $legacyStreet = $rider->street_address;

        $this
            ->actingAs($rider)
            ->patch(
                route('rider.profile.address.home.update'),
                [
                    'house_number' => '88',
                    'street' => 'Updated Rider Street',
                    'barangay' => 'San Roque',
                    'city' => 'Calamba City',
                    'province' => 'Laguna',
                    'postal_code' => '4027',
                ]
            )
            ->assertRedirect(
                route('rider.profile.index')
                . '#addresses'
            )
            ->assertSessionHas(
                'success',
                'Home address updated successfully.'
            );

        $riderAddress->refresh();
        $rider->refresh();

        $this->assertSame(
            'Home',
            $riderAddress->label
        );

        $this->assertSame(
            '88',
            $riderAddress->house_number
        );

        $this->assertSame(
            'Updated Rider Street',
            $riderAddress->street
        );

        $this->assertSame(
            'San Roque',
            $riderAddress->barangay
        );

        $this->assertSame(
            'Calamba City',
            $riderAddress->city_municipality
        );

        $this->assertSame(
            'Laguna',
            $riderAddress->province
        );

        $this->assertSame(
            '4027',
            $riderAddress->postal_code
        );

        $this->assertSame(
            $legacyStreet,
            $rider->street_address
        );
    }

    public function test_home_update_creates_home_address_for_migrated_rider(): void
    {
        [
            'rider' => $rider,
            'riderAddress' => $riderAddress,
        ] = $this->makeRiderAccount();

        $riderAddress->update([
            'label' => 'Temporary',
        ]);

        $this->assertDatabaseMissing(
            'addresses',
            [
                'user_id' => $rider->id,
                'label' => 'Home',
            ]
        );

        $this
            ->actingAs($rider)
            ->patch(
                route('rider.profile.address.home.update'),
                [
                    'house_number' => '10',
                    'street' => 'New Home Street',
                    'barangay' => 'Santo Angel',
                    'city' => 'San Pablo City',
                    'province' => 'Laguna',
                    'postal_code' => '4000',
                ]
            )
            ->assertRedirect(
                route('rider.profile.index')
                . '#addresses'
            );

        $this->assertDatabaseHas(
            'addresses',
            [
                'user_id' => $rider->id,
                'label' => 'Home',
                'street' => 'New Home Street',
                'barangay' => 'Santo Angel',
                'city_municipality' => 'San Pablo City',
            ]
        );

        $this->assertDatabaseHas(
            'addresses',
            [
                'id' => $riderAddress->id,
                'label' => 'Temporary',
                'street' => 'Rizal Street',
            ]
        );
    }

    public function test_rider_can_add_additional_owned_address(): void
    {
        [
            'rider' => $rider,
        ] = $this->makeRiderAccount();

        $this
            ->actingAs($rider)
            ->post(
                route('rider.profile.addresses.store'),
                [
                    'label' => 'Secondary home',
                    'house_number' => '7',
                    'street' => 'Maharlika Street',
                    'barangay' => 'San Francisco',
                    'city' => 'Calamba City',
                    'province' => 'Laguna',
                    'postal_code' => '4027',
                ]
            )
            ->assertRedirect(
                route('rider.profile.index')
                . '#addresses'
            )
            ->assertSessionHas(
                'success',
                'Rider address added successfully.'
            );

        $this->assertDatabaseHas(
            'addresses',
            [
                'user_id' => $rider->id,
                'label' => 'Secondary home',
                'house_number' => '7',
                'street' => 'Maharlika Street',
                'barangay' => 'San Francisco',
                'city_municipality' => 'Calamba City',
                'province' => 'Laguna',
                'postal_code' => '4027',
            ]
        );
    }

    public function test_additional_address_cannot_replace_home_label(): void
    {
        [
            'rider' => $rider,
        ] = $this->makeRiderAccount();

        $homeCountBefore = Address::query()
            ->where('user_id', $rider->id)
            ->where('label', 'Home')
            ->count();

        $this
            ->actingAs($rider)
            ->post(
                route('rider.profile.addresses.store'),
                [
                    'label' => 'home',
                    'street' => 'Fake Replacement Street',
                    'barangay' => 'Fake Barangay',
                    'city' => 'Fake City',
                    'province' => 'Fake Province',
                ]
            )
            ->assertSessionHasErrors(
                'label'
            );

        $homeCountAfter = Address::query()
            ->where('user_id', $rider->id)
            ->where('label', 'Home')
            ->count();

        $this->assertSame(
            $homeCountBefore,
            $homeCountAfter
        );

        $this->assertDatabaseMissing(
            'addresses',
            [
                'user_id' => $rider->id,
                'street' => 'Fake Replacement Street',
            ]
        );
    }

    public function test_account_page_uses_normalized_rider_profile_and_address(): void
    {
        [
            'rider' => $rider,
        ] = $this->makeRiderAccount();

        $response = $this
            ->actingAs($rider)
            ->get(
                route('rider.profile.index')
            );

        $response->assertOk();

        /*
         * Normalized RiderProfile values.
         */
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

        /*
         * Normalized Rider home address.
         */
        $response->assertSee(
            '25, Rizal Street, Brgy. Del Remedio, San Pablo City, Laguna, 4000'
        );

        /*
         * Normalized operational assignment.
         */
        $response->assertSee(
            'San Pablo North · SP-N1'
        );

        $response->assertSee(
            'Approved rider'
        );

        /*
         * Legacy conflicting user fields must not
         * override normalized Rider data.
         */
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

    /**
     * Build one fully normalized active Rider account.
     *
     * Legacy Rider fields intentionally contain
     * conflicting values so tests can verify that
     * RiderProfile and Address are authoritative.
     *
     * @return array{
     *     logisticsUser: User,
     *     logisticsProfile: LogisticsProfile,
     *     centerAddress: Address,
     *     center: SortingCenter,
     *     zone: SortingZone,
     *     rider: User,
     *     riderAddress: Address,
     *     riderProfile: RiderProfile
     * }
     */
    private function makeRiderAccount(): array
    {
        $logisticsUser = User::factory()->create([
            'name' =>
                'Laguna Logistics Operator',

            'business_name' =>
                'Laguna Central Logistics',

            'email' =>
                'logistics@example.test',

            'role' =>
                UserRole::Logistics->value,

            'status' =>
                AccountStatus::Active->value,
        ]);

        $logisticsProfile =
            LogisticsProfile::query()->create([
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

        $centerAddress =
            Address::query()->create([
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

        $center =
            SortingCenter::query()->create([
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

        $zone =
            SortingZone::query()->create([
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

        $riderAddress =
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

        $riderProfile =
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

        return compact(
            'logisticsUser',
            'logisticsProfile',
            'centerAddress',
            'center',
            'zone',
            'rider',
            'riderAddress',
            'riderProfile'
        );
    }
}