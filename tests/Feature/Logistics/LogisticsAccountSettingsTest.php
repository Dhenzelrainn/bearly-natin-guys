<?php

namespace Tests\Feature\Logistics;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\LogisticsLocation;
use App\Models\LogisticsPayoutAccount;
use App\Models\LogisticsProfile;
use App\Models\SortingCenter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LogisticsAccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_logistics_can_share_a_latest_provider_scoped_live_location_and_stop_sharing(): void
    {
        ['user' => $user, 'profile' => $profile] = $this->makeLogisticsFacility('A', 'live-a@example.test');
        ['user' => $otherUser, 'profile' => $otherProfile] = $this->makeLogisticsFacility('B', 'live-b@example.test');

        $this->actingAs($user)
            ->postJson(route('logistics.location.update'), [
                'logistics_profile_id' => $otherProfile->id,
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'accuracy_meters' => 10000,
            ])
            ->assertOk()
            ->assertJsonPath('data.shared', true)
            ->assertJsonPath('data.location.latitude', 14.5995)
            ->assertJsonPath('data.location.accuracy_meters', 10000)
            ->assertJsonPath('data.stale_after_seconds', 180);

        $this->actingAs($user)
            ->postJson(route('logistics.location.update'), [
                'latitude' => 14.6100,
                'longitude' => 120.9900,
                'accuracy_meters' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.location.latitude', 14.61)
            ->assertJsonPath('data.location.accuracy_meters', null);

        $this->assertDatabaseCount('logistics_locations', 1);
        $this->assertDatabaseHas('logistics_locations', [
            'logistics_profile_id' => $profile->id,
            'latitude' => 14.61,
            'longitude' => 120.99,
            'accuracy_meters' => null,
        ]);
        $this->assertDatabaseMissing('logistics_locations', [
            'logistics_profile_id' => $otherProfile->id,
        ]);
        $this->assertDatabaseHas('addresses', [
            'id' => $profile->sortingCenters()->value('address_id'),
            'latitude' => null,
            'longitude' => null,
        ]);

        $this->actingAs($otherUser)
            ->getJson(route('logistics.location.show'))
            ->assertOk()
            ->assertJsonPath('data.shared', false)
            ->assertJsonPath('data.location', null);

        $response = $this->actingAs($user)
            ->getJson(route('logistics.location.show'))
            ->assertOk()
            ->assertJsonPath('data.shared', true)
            ->assertJsonPath('data.location.longitude', 120.99);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->actingAs($user)
            ->deleteJson(route('logistics.location.destroy'))
            ->assertOk()
            ->assertJsonPath('data.shared', false)
            ->assertJsonPath('data.location', null);

        $this->assertDatabaseMissing('logistics_locations', [
            'logistics_profile_id' => $profile->id,
        ]);
    }

    public function test_logistics_live_location_rejects_invalid_coordinates_and_accuracy(): void
    {
        ['user' => $user] = $this->makeLogisticsFacility('LIVE-VALIDATION', 'live-validation@example.test');

        $this->actingAs($user)
            ->postJson(route('logistics.location.update'), [
                'latitude' => 91,
                'longitude' => 120,
                'accuracy_meters' => 10,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('latitude');

        $this->actingAs($user)
            ->postJson(route('logistics.location.update'), [
                'latitude' => 14,
                'longitude' => 120,
                'accuracy_meters' => 100001,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accuracy_meters');

        $this->assertDatabaseCount('logistics_locations', 0);
    }

    public function test_logistics_can_register_encrypted_bank_and_gcash_destinations_for_finance_review(): void
    {
        ['user' => $user] = $this->makeLogisticsFacility();

        $this->actingAs($user)
            ->post(route('logistics.profile.payout-accounts.store'), [
                'channel' => 'bank_transfer',
                'provider_name' => 'Union Bank',
                'account_name' => 'Logistics Hub Inc.',
                'destination_value' => '1234 5678 9012',
            ])
            ->assertRedirect(route('logistics.profile.index') . '#payouts')
            ->assertSessionHas('success');

        $bank = LogisticsPayoutAccount::query()->sole();
        $this->assertSame('pending_review', $bank->status);
        $this->assertSame('123456789012', $bank->destination_value);
        $this->assertSame('9012', $bank->destination_last4);
        $this->assertNotSame('123456789012', DB::table('logistics_payout_accounts')->where('id', $bank->id)->value('destination_value'));

        $this->actingAs($user)
            ->from(route('logistics.profile.index'))
            ->post(route('logistics.profile.payout-accounts.store'), [
                'channel' => 'bank_transfer',
                'account_name' => 'Logistics Hub Inc.',
                'destination_value' => '9876543210',
            ])
            ->assertSessionHasErrors('provider_name')
            ->assertSessionMissing('_old_input.destination_value');

        $this->actingAs($user)
            ->post(route('logistics.profile.payout-accounts.store'), [
                'channel' => 'gcash',
                'account_name' => 'Logistics Hub Inc.',
                'destination_value' => '0917 123 4567',
            ])
            ->assertRedirect(route('logistics.profile.index') . '#payouts')
            ->assertSessionHas('success');

        $gcash = LogisticsPayoutAccount::query()->where('channel', 'gcash')->sole();
        $this->assertSame('GCash', $gcash->provider_name);
        $this->assertSame('+639171234567', $gcash->destination_value);
        $this->assertSame('4567', $gcash->destination_last4);

        $this->actingAs($user)
            ->get(route('logistics.profile.index') . '#payouts')
            ->assertOk()
            ->assertSee('•••• 9012')
            ->assertSee('•••• 4567')
            ->assertSee('Pending Review')
            ->assertDontSee('123456789012')
            ->assertDontSee('+639171234567');
    }

    public function test_finance_views_sensitive_destination_with_audit_and_verifies_it_before_use(): void
    {
        ['user' => $user] = $this->makeLogisticsFacility();
        $this->actingAs($user)->post(route('logistics.profile.payout-accounts.store'), [
            'channel' => 'bank_transfer',
            'provider_name' => 'Union Bank',
            'account_name' => 'Logistics Hub Inc.',
            'destination_value' => '123456789012',
        ])->assertRedirect();
        $account = LogisticsPayoutAccount::query()->sole();
        $admin = User::factory()->create(['role' => UserRole::Admin->value, 'status' => AccountStatus::Active->value]);

        $this->actingAs($admin)
            ->get(route('admin.shipping-settlements.index'))
            ->assertOk()
            ->assertSee('Logistics payout destinations')
            ->assertSee('•••• 9012')
            ->assertDontSee('123456789012');

        $detailsResponse = $this->actingAs($admin)
            ->get(route('admin.logistics-payout-accounts.show', $account))
            ->assertOk()
            ->assertSee('123456789012')
            ->assertSee('I independently confirmed this destination');
        $this->assertStringContainsString('no-store', (string) $detailsResponse->headers->get('Cache-Control'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'logistics_payout_account.details_viewed')->count());
        $auditValues = (string) DB::table('audit_logs')->where('action', 'logistics_payout_account.details_viewed')->value('new_values');
        $this->assertStringNotContainsString('123456789012', $auditValues);

        $this->actingAs($admin)
            ->post(route('admin.logistics-payout-accounts.verify', $account), [
                'verification_note' => 'Confirmed with registered provider contact.',
                'destination_verified' => '1',
            ])
            ->assertRedirect(route('admin.shipping-settlements.index'));

        $this->assertSame('verified', $account->fresh()->status);
        $this->assertSame($admin->id, (int) $account->fresh()->reviewed_by);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'logistics_payout_account.verified')->count());

        $this->actingAs($user)
            ->get(route('logistics.profile.index') . '#payouts')
            ->assertOk()
            ->assertSee('Verified')
            ->assertSee('•••• 9012')
            ->assertDontSee('123456789012');
    }

    public function test_logistics_cannot_deactivate_another_providers_destination_and_finance_can_reject_pending_details(): void
    {
        ['user' => $owner] = $this->makeLogisticsFacility('A', 'payout-owner@example.test');
        ['user' => $other] = $this->makeLogisticsFacility('B', 'payout-other@example.test');
        $this->actingAs($owner)->post(route('logistics.profile.payout-accounts.store'), [
            'channel' => 'gcash',
            'account_name' => 'Owner Logistics',
            'destination_value' => '09171234567',
        ])->assertRedirect();
        $account = LogisticsPayoutAccount::query()->sole();

        $this->actingAs($other)
            ->post(route('logistics.profile.payout-accounts.deactivate', $account))
            ->assertNotFound();
        $this->assertSame('pending_review', $account->fresh()->status);

        $admin = User::factory()->create(['role' => UserRole::Admin->value, 'status' => AccountStatus::Active->value]);
        $this->actingAs($admin)
            ->post(route('admin.logistics-payout-accounts.reject', $account), [
                'review_reason' => 'Unable to confirm the registered destination.',
            ])
            ->assertRedirect(route('admin.shipping-settlements.index'));

        $this->assertSame('rejected', $account->fresh()->status);
        $this->assertSame('Unable to confirm the registered destination.', $account->fresh()->review_note);
        $this->actingAs($other)->get(route('admin.logistics-payout-accounts.show', $account))->assertForbidden();
    }

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
            'phone' => '+639179999999',
            'contact_number' => '+639179999999',
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
            '+639178888888',
            $user->contact_number
        );
    }

    public function test_operator_contact_update_rejects_a_duplicate_normalized_phone(): void
    {
        ['user' => $user] = $this->makeLogisticsFacility();

        User::factory()->create([
            'email' => 'phone-owner@example.test',
            'contact_number' => '+639177777777',
        ]);

        $this->actingAs($user)
            ->from(route('logistics.profile.index'))
            ->patch(route('logistics.profile.update'), [
                'name' => 'Operator',
                'contact' => '0917 777 7777',
            ])
            ->assertSessionHasErrors('contact');

        $this->assertNotSame('+639177777777', $user->fresh()->contact_number);
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
            'address' => $address,
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
                    'latitude' => '14.5995',
                    'longitude' => '120.9842',
                ]
            )
            ->assertRedirect(
                route('logistics.profile.index') . '#facility'
            )
            ->assertSessionHas(
                'success',
                'Facility settings and map pin updated successfully.'
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
        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'latitude' => 14.5995,
            'longitude' => 120.9842,
        ]);
    }

    public function test_facility_account_shows_the_saved_pin_for_its_active_sorting_center(): void
    {
        [
            'user' => $user,
            'address' => $address,
        ] = $this->makeLogisticsFacility();
        $address->update([
            'latitude' => 14.61,
            'longitude' => 120.99,
        ]);

        $this->actingAs($user)
            ->get(route('logistics.profile.index') . '#facility')
            ->assertOk()
            ->assertSee('data-logistics-facility-map', false)
            ->assertSee('data-latitude="14.61"', false)
            ->assertSee('data-longitude="120.99"', false)
            ->assertSee('data-facility-location-status', false)
            ->assertSee('data-stop-facility-location', false)
            ->assertSee('Find facility location');
    }

    public function test_facility_map_pin_can_be_cleared_without_changing_the_registered_address(): void
    {
        [
            'user' => $user,
            'address' => $address,
        ] = $this->makeLogisticsFacility();
        $address->update([
            'latitude' => 14.61,
            'longitude' => 120.99,
        ]);

        $this->actingAs($user)
            ->patch(route('logistics.profile.facility.update'), [
                'business_name' => 'Updated Hub',
                'contact' => '09176661234',
                'operating_hours' => '08:00-20:00',
                'daily_capacity' => 600,
                'latitude' => '',
                'longitude' => '',
            ])
            ->assertRedirect(route('logistics.profile.index') . '#facility')
            ->assertSessionHas('success', 'Facility settings and map pin updated successfully.');

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'street' => 'Hub Street A',
            'latitude' => null,
            'longitude' => null,
        ]);
    }

    public function test_facility_map_pin_coordinates_must_be_a_valid_pair(): void
    {
        [
            'user' => $user,
            'address' => $address,
        ] = $this->makeLogisticsFacility();
        $address->update([
            'latitude' => 14.61,
            'longitude' => 120.99,
        ]);
        $validFacilityFields = [
            'business_name' => 'Updated Hub',
            'contact' => '09176661234',
            'operating_hours' => '08:00-20:00',
            'daily_capacity' => 600,
        ];

        $this->actingAs($user)
            ->from(route('logistics.profile.index') . '#facility')
            ->patch(route('logistics.profile.facility.update'), $validFacilityFields + [
                'latitude' => '90.0001',
                'longitude' => '120.99',
            ])
            ->assertRedirect(route('logistics.profile.index') . '#facility')
            ->assertSessionHasErrors('latitude');

        $this->actingAs($user)
            ->from(route('logistics.profile.index') . '#facility')
            ->patch(route('logistics.profile.facility.update'), $validFacilityFields + [
                'latitude' => '14.61',
            ])
            ->assertRedirect(route('logistics.profile.index') . '#facility')
            ->assertSessionHasErrors('longitude');

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'latitude' => 14.61,
            'longitude' => 120.99,
        ]);
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
        $centerA->address()->update([
            'latitude' => 14.0,
            'longitude' => 120.0,
        ]);
        $centerB->address()->update([
            'latitude' => 15.0,
            'longitude' => 121.0,
        ]);

        $this
            ->actingAs($userA)
            ->patch(
                route('logistics.profile.facility.update'),
                [
                    'business_name' => 'Provider A Updated Hub',
                    'contact' => '09170000099',
                    'operating_hours' => '06:00-18:00',
                    'daily_capacity' => 900,
                    'latitude' => '16.0',
                    'longitude' => '122.0',

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
        $this->assertDatabaseHas('addresses', [
            'id' => $centerA->address_id,
            'latitude' => 16.0,
            'longitude' => 122.0,
        ]);
        $this->assertDatabaseHas('addresses', [
            'id' => $centerB->address_id,
            'latitude' => 15.0,
            'longitude' => 121.0,
        ]);
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
        $contactNumber = match ($suffix) {
            'A' => '09171111111',
            'B' => '09172222222',
            'INACTIVE' => '09173333333',
            default => '09174444444',
        };

        $user = User::factory()->create([
            'name' => "Logistics Operator {$suffix}",
            'first_name' => 'Logistics',
            'last_name' => "Operator {$suffix}",
            'email' => $email,
            'phone' => $contactNumber,
            'contact_number' => $contactNumber,
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
