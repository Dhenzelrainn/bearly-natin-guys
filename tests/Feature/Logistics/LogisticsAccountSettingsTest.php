<?php

namespace Tests\Feature\Logistics;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\SortingCenter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LogisticsAccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_update_name_and_contact(): void
    {
        ['user' => $user] = $this->makeLogisticsFacility();

        $response = $this
            ->actingAs($user)
            ->patch(
                route('logistics.profile.update'),
                [
                    'name' => 'Updated Logistics Operator',
                    'contact' => '09179999999',
                ]
            );

        $response->assertRedirect(
            route('logistics.profile.index') . '#profile'
        );

        $response->assertSessionHas(
            'success',
            'Operator profile updated successfully.'
        );

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Logistics Operator',
            'contact_number' => '09179999999',
        ]);
    }

    public function test_profile_update_cannot_change_role_or_email(): void
    {
        ['user' => $user] = $this->makeLogisticsFacility();

        $originalEmail = $user->email;

        $this
            ->actingAs($user)
            ->patch(
                route('logistics.profile.update'),
                [
                    'name' => 'Safe Operator',
                    'contact' => '09178888888',

                    // Malicious/unexpected fields.
                    'role' => UserRole::Admin->value,
                    'email' => 'changed@example.test',
                ]
            )
            ->assertRedirect(
                route('logistics.profile.index') . '#profile'
            );

        $user->refresh();

        $this->assertSame(
            UserRole::Logistics->value,
            $user->role
        );

        $this->assertSame(
            $originalEmail,
            $user->email
        );

        $this->assertSame(
            'Safe Operator',
            $user->name
        );

        $this->assertSame(
            '09178888888',
            $user->contact_number
        );
    }

    public function test_correct_current_password_allows_password_change(): void
    {
        ['user' => $user] = $this->makeLogisticsFacility();

        $user->update([
            'password' => Hash::make('OldPassword123'),
        ]);

        $this
            ->actingAs($user)
            ->patch(
                route('logistics.profile.password.update'),
                [
                    'current_password' => 'OldPassword123',
                    'new_password' => 'NewPassword456',
                    'new_password_confirmation' => 'NewPassword456',
                ]
            )
            ->assertRedirect(
                route('logistics.profile.index') . '#security'
            )
            ->assertSessionHas(
                'success',
                'Password updated successfully.'
            );

        $user->refresh();

        $this->assertTrue(
            Hash::check(
                'NewPassword456',
                $user->password
            )
        );

        $this->assertFalse(
            Hash::check(
                'OldPassword123',
                $user->password
            )
        );
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        ['user' => $user] = $this->makeLogisticsFacility();

        $user->update([
            'password' => Hash::make('CorrectPassword123'),
        ]);

        $oldHash = $user->password;

        $this
            ->actingAs($user)
            ->from(
                route('logistics.profile.index') . '#security'
            )
            ->patch(
                route('logistics.profile.password.update'),
                [
                    'current_password' => 'WrongPassword123',
                    'new_password' => 'NewPassword456',
                    'new_password_confirmation' => 'NewPassword456',
                ]
            )
            ->assertRedirect(
                route('logistics.profile.index') . '#security'
            )
            ->assertSessionHasErrors(
                'current_password'
            );

        $user->refresh();

        $this->assertSame(
            $oldHash,
            $user->password
        );

        $this->assertTrue(
            Hash::check(
                'CorrectPassword123',
                $user->password
            )
        );
    }

    public function test_facility_settings_update_real_sorting_center(): void
    {
        [
            'user' => $user,
            'center' => $center,
        ] = $this->makeLogisticsFacility();

        $this
            ->actingAs($user)
            ->patch(
                route('logistics.profile.facility.update'),
                [
                    'business_name' => 'Updated Laguna Sorting Hub',
                    'contact' => '09175551234',
                    'operating_hours' => '07:00-19:00',
                    'daily_capacity' => 750,
                ]
            )
            ->assertRedirect(
                route('logistics.profile.index') . '#facility'
            )
            ->assertSessionHas(
                'success',
                'Facility settings updated successfully.'
            );

        $this->assertDatabaseHas(
            'sorting_centers',
            [
                'id' => $center->id,
                'name' => 'Updated Laguna Sorting Hub',
                'contact_phone' => '09175551234',
                'operating_hours' => '07:00-19:00',
                'daily_capacity' => 750,
            ]
        );
    }

    public function test_facility_update_does_not_modify_normalized_address(): void
    {
        [
            'user' => $user,
            'address' => $address,
        ] = $this->makeLogisticsFacility();

        $original = [
            'house_number' => $address->house_number,
            'street' => $address->street,
            'barangay' => $address->barangay,
            'city_municipality' => $address->city_municipality,
            'province' => $address->province,
            'postal_code' => $address->postal_code,
        ];

        $this
            ->actingAs($user)
            ->patch(
                route('logistics.profile.facility.update'),
                [
                    'business_name' => 'Updated Hub',
                    'contact' => '09176661234',
                    'operating_hours' => '08:00-20:00',
                    'daily_capacity' => 600,

                    // Must be ignored by the controller.
                    'address' => 'Malicious replacement address',
                ]
            )
            ->assertRedirect(
                route('logistics.profile.index') . '#facility'
            );

        $address->refresh();

        $this->assertSame(
            $original['house_number'],
            $address->house_number
        );

        $this->assertSame(
            $original['street'],
            $address->street
        );

        $this->assertSame(
            $original['barangay'],
            $address->barangay
        );

        $this->assertSame(
            $original['city_municipality'],
            $address->city_municipality
        );

        $this->assertSame(
            $original['province'],
            $address->province
        );

        $this->assertSame(
            $original['postal_code'],
            $address->postal_code
        );
    }

    public function test_inactive_sorting_center_cannot_be_modified(): void
    {
        [
            'user' => $user,
            'center' => $center,
        ] = $this->makeLogisticsFacility(
            'INACTIVE',
            'inactive-operator@example.test'
        );

        $center->update([
            'status' => 'inactive',
        ]);

        $original = [
            'name' =>
                $center->name,

            'contact_phone' =>
                $center->contact_phone,

            'operating_hours' =>
                $center->operating_hours,

            'daily_capacity' =>
                $center->daily_capacity,
        ];

        $this
            ->actingAs($user)
            ->patch(
                route(
                    'logistics.profile.facility.update'
                ),
                [
                    'business_name' =>
                        'Should Not Update',

                    'contact' =>
                        '09179990000',

                    'operating_hours' =>
                        '00:00-23:59',

                    'daily_capacity' =>
                        9999,
                ]
            )
            ->assertNotFound();

        $center->refresh();

        $this->assertSame(
            $original['name'],
            $center->name
        );

        $this->assertSame(
            $original['contact_phone'],
            $center->contact_phone
        );

        $this->assertSame(
            $original['operating_hours'],
            $center->operating_hours
        );

        $this->assertSame(
            $original['daily_capacity'],
            $center->daily_capacity
        );
    }

    public function test_logistics_cannot_modify_another_providers_facility(): void
    {
        [
            'user' => $userA,
            'center' => $centerA,
        ] = $this->makeLogisticsFacility(
            'A',
            'operator-a@example.test'
        );

        [
            'center' => $centerB,
        ] = $this->makeLogisticsFacility(
            'B',
            'operator-b@example.test'
        );

        $originalForeignName = $centerB->name;
        $originalForeignContact = $centerB->contact_phone;

        $this
            ->actingAs($userA)
            ->patch(
                route('logistics.profile.facility.update'),
                [
                    'business_name' => 'Provider A Updated Hub',
                    'contact' => '09170000099',
                    'operating_hours' => '06:00-18:00',
                    'daily_capacity' => 900,

                    // Even if submitted manually, this is ignored.
                    'sorting_center_id' => $centerB->id,
                ]
            )
            ->assertRedirect(
                route('logistics.profile.index') . '#facility'
            );

        $centerA->refresh();
        $centerB->refresh();

        $this->assertSame(
            'Provider A Updated Hub',
            $centerA->name
        );

        $this->assertSame(
            $originalForeignName,
            $centerB->name
        );

        $this->assertSame(
            $originalForeignContact,
            $centerB->contact_phone
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
    private function makeLogisticsFacility(
        string $suffix = 'A',
        string $email = 'operator@example.test'
    ): array {
        $user = User::factory()->create([
            'name' => "Logistics Operator {$suffix}",
            'first_name' => 'Logistics',
            'last_name' => "Operator {$suffix}",
            'email' => $email,
            'contact_number' => '09171111111',
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
            'business_name' => "Legacy Logistics {$suffix}",
            'password' => Hash::make('Password123'),
        ]);

        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' =>
                "Logistics {$suffix} Incorporated",
            'display_name' =>
                "Logistics {$suffix}",
            'contact_phone' =>
                '09170000000',
            'status' => 'active',
        ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Sorting center',
            'recipient_name' =>
                "Logistics {$suffix}",
            'phone' => '09172222222',
            'house_number' => '12',
            'street' => "Hub Street {$suffix}",
            'barangay' => 'San Rafael',
            'city_municipality' =>
                'San Pablo City',
            'province' => 'Laguna',
            'postal_code' => '4000',
        ]);

        $center = SortingCenter::query()->create([
            'logistics_profile_id' =>
                $profile->id,
            'address_id' => $address->id,
            'name' =>
                "Sorting Hub {$suffix}",
            'code' =>
                "SC-SETTINGS-{$suffix}",
            'contact_phone' =>
                '09172222222',
            'operating_hours' =>
                '08:00-18:00',
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