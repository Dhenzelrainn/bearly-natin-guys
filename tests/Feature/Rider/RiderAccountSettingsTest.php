<?php

namespace Tests\Feature\Rider;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\Address;
use App\Models\LinehaulRun;
use App\Models\LogisticsProfile;
use App\Models\RiderProfile;
use App\Models\RiderVehicleChangeRequest;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use App\Services\RiderVehicleChangeService;
use Tests\TestCase;

class RiderAccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rider_vehicle_change_stays_pending_until_sponsoring_logistics_approves(): void
    {
        Storage::fake('local');

        [
            'logisticsUser' => $logisticsUser,
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
                    'work_type' => 'local',
                    'vehicle_type' =>
                        'Motorcycle',

                    'plate_number' =>
                        'abc-1234',

                    'vehicle_model' =>
                        'Honda Click 160',
                    'or_cr' => UploadedFile::fake()->create('new-or-cr.pdf', 100, 'application/pdf'),
                    'driver_license' => UploadedFile::fake()->create('new-license.pdf', 100, 'application/pdf'),
                ]
            )
            ->assertRedirect(
                route('rider.profile.index')
                . '#vehicle'
            )
            ->assertSessionHas(
                'success',
                'Vehicle and work-type changes were sent to your sponsoring Logistics provider for review. Your current permissions remain unchanged until approval.'
            );

        $change = RiderVehicleChangeRequest::query()->firstOrFail();
        $this->assertSame('pending', $change->status);
        Storage::disk('local')->assertExists($change->or_cr_path);
        Storage::disk('local')->assertExists($change->driver_license_path);

        $riderProfile->refresh();
        $rider->refresh();

        $this->assertSame(
            'E-bike',
            $riderProfile->vehicle_type
        );

        $this->assertSame(
            'RDR-2026',
            $riderProfile->plate_number
        );

        $this->assertSame(
            'Honda EM1 e:',
            $riderProfile->vehicle_model
        );

        $this->assertSame(
            null,
            $riderProfile->work_type
        );

        $this->assertSame(
            'Legacy Motorcycle',
            $rider->vehicle_type
        );

        $this->assertSame(
            'LEG-001',
            $rider->plate_number
        );

        app(RiderVehicleChangeService::class)->approve($change, $logisticsUser);

        $this->assertSame('approved', $change->fresh()->status);
        $this->assertSame('Motorcycle', $riderProfile->fresh()->vehicle_type);
        $this->assertSame('ABC-1234', $riderProfile->fresh()->plate_number);
        $this->assertSame('Honda Click 160', $riderProfile->fresh()->vehicle_model);
        $this->assertSame('local', $riderProfile->fresh()->work_type);
        $this->assertNull($riderProfile->fresh()->linehaul_parcel_capacity);
        $this->assertSame('Motorcycle', $rider->fresh()->vehicle_type);
        $this->assertSame('ABC-1234', $rider->fresh()->plate_number);
    }

    public function test_vehicle_update_cannot_change_rider_operational_assignment(): void
    {
        Storage::fake('local');

        [
            'logisticsUser' => $logisticsUser,
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
                    'work_type' => 'courier',
                    'vehicle_type' =>
                        'Van',

                    'plate_number' =>
                        'SAFE-2026',

                    'vehicle_model' =>
                        'Toyota LiteAce',

                    'or_cr' => UploadedFile::fake()->create('courier-or-cr.pdf', 100, 'application/pdf'),
                    'driver_license' => UploadedFile::fake()->create('courier-license.pdf', 100, 'application/pdf'),

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

                    'linehaul_parcel_capacity' => 999,
                    'courier_vehicle_verified_by' => $logisticsUser->id,
                    'courier_vehicle_verified_at' => now()->toDateTimeString(),
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
            'E-bike',
            $riderProfile->vehicle_type
        );

        $this->assertSame(
            'RDR-2026',
            $riderProfile->plate_number
        );

        $this->assertNull($riderProfile->work_type);
        $this->assertNull($riderProfile->linehaul_parcel_capacity);
        $this->assertNull($riderProfile->courier_vehicle_verified_at);
        $this->assertDatabaseHas('rider_vehicle_change_requests', [
            'rider_profile_id' => $riderProfile->id,
            'requested_work_type' => 'courier',
            'vehicle_type' => 'Van',
            'plate_number' => 'SAFE-2026',
            'status' => 'pending',
        ]);

        $foreignLogistics = User::factory()->create([
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
        ]);
        LogisticsProfile::query()->create([
            'user_id' => $foreignLogistics->id,
            'legal_name' => 'Foreign Logistics',
            'display_name' => 'Foreign Logistics',
            'contact_phone' => '09170000003',
            'psgc_city_code' => '0403422000',
            'status' => 'active',
        ]);

        $change = RiderVehicleChangeRequest::query()->firstOrFail();
        $this->actingAs($foreignLogistics)
            ->post(route('logistics.riders.vehicle-changes.approve', $change), [
                'linehaul_capacity' => 40,
            ])
            ->assertForbidden();

        $this->assertSame('pending', $change->fresh()->status);
        $this->assertSame('E-bike', $riderProfile->fresh()->vehicle_type);
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
            '+639179999999',
            $rider->contact_number
        );

        $this->assertSame(
            '+639179999999',
            $rider->phone
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
            '+639178888888',
            $riderProfile->emergency_contact_phone
        );
    }

    public function test_rider_profile_rejects_duplicate_phone_and_underage_birthday(): void
    {
        ['rider' => $rider] = $this->makeRiderAccount();

        User::factory()->create([
            'email' => 'other-phone-owner@example.test',
            'contact_number' => '+639177777777',
        ]);

        Carbon::setTestNow('2026-10-08 12:00:00');

        try {
            $this->actingAs($rider)
                ->from(route('rider.profile.index'))
                ->patch(route('rider.profile.update'), [
                    'name' => 'Updated Rider',
                    'sex' => 'Male',
                    'contact' => '0917 777 7777',
                    'birthday' => '2008-10-09',
                ])
                ->assertSessionHasErrors(['contact', 'birthday']);

            $this->assertSame('09181234567', $rider->fresh()->contact_number);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_vehicle_update_rejects_case_insensitive_existing_rider_plate(): void
    {
        ['rider' => $rider, 'riderProfile' => $riderProfile] = $this->makeRiderAccount();

        $otherRider = User::factory()->create([
            'email' => 'other-rider@example.test',
            'role' => UserRole::Rider->value,
            'status' => AccountStatus::Active->value,
            'plate_number' => 'dup-2026',
        ]);

        RiderProfile::query()->create([
            'user_id' => $otherRider->id,
            'logistics_profile_id' => $riderProfile->logistics_profile_id,
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'dup-2026',
        ]);

        $this->actingAs($rider)
            ->from(route('rider.profile.index'))
            ->patch(route('rider.profile.vehicle.update'), [
                'vehicle_type' => 'Motorcycle',
                'plate_number' => 'DUP-2026',
            ])
            ->assertSessionHasErrors('plate_number');
    }

    public function test_rider_cannot_request_a_vehicle_outside_the_selected_work_type(): void
    {
        Storage::fake('local');
        ['rider' => $rider] = $this->makeRiderAccount();

        $this->from(route('rider.profile.index'))
            ->actingAs($rider)
            ->patch(route('rider.profile.vehicle.update'), [
                'work_type' => 'courier',
                'vehicle_type' => 'Motorcycle',
                'plate_number' => 'INVALID-01',
                'or_cr' => UploadedFile::fake()->create('or-cr.pdf', 100, 'application/pdf'),
                'driver_license' => UploadedFile::fake()->create('license.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect(route('rider.profile.index'))
            ->assertSessionHasErrors('vehicle_type');

        $this->assertDatabaseCount('rider_vehicle_change_requests', 0);
    }

    public function test_logistics_cannot_approve_vehicle_change_during_active_linehaul_run(): void
    {
        Storage::fake('local');
        [
            'logisticsUser' => $logisticsUser,
            'logisticsProfile' => $logisticsProfile,
            'center' => $center,
            'rider' => $rider,
            'riderProfile' => $riderProfile,
        ] = $this->makeRiderAccount();
        $riderProfile->update([
            'work_type' => 'courier',
            'vehicle_type' => 'Van',
            'linehaul_parcel_capacity' => 10,
            'courier_vehicle_verified_by' => $logisticsUser->id,
            'courier_vehicle_verified_at' => now(),
        ]);

        $this->actingAs($rider)
            ->patch(route('rider.profile.vehicle.update'), [
                'work_type' => 'local',
                'vehicle_type' => 'Motorcycle',
                'vehicle_model' => 'Honda Click',
                'plate_number' => 'LOCAL-NEW-01',
                'or_cr' => UploadedFile::fake()->create('local-or-cr.pdf', 100, 'application/pdf'),
                'driver_license' => UploadedFile::fake()->create('local-license.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect(route('rider.profile.index').'#vehicle')
            ->assertSessionHasNoErrors();

        $change = RiderVehicleChangeRequest::query()->firstOrFail();
        LinehaulRun::query()->create([
            'run_no' => 'LHR-ACTIVE-CHANGE-01',
            'origin_logistics_profile_id' => $logisticsProfile->id,
            'destination_logistics_profile_id' => $logisticsProfile->id,
            'origin_sorting_center_id' => $center->id,
            'courier_rider_profile_id' => $riderProfile->id,
            'status' => 'departed',
            'assigned_by' => $logisticsUser->id,
            'assigned_at' => now()->subHour(),
            'departed_at' => now(),
        ]);

        try {
            app(RiderVehicleChangeService::class)->approve($change, $logisticsUser);
            $this->fail('The Courier vehicle must not change while its line-haul run is active.');
        } catch (ConflictHttpException) {
            $this->assertSame('pending', $change->fresh()->status);
            $this->assertSame('courier', $riderProfile->fresh()->work_type);
            $this->assertSame('Van', $riderProfile->fresh()->vehicle_type);
            $this->assertSame('LOCAL-NEW-01', $change->fresh()->plate_number);
        }
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
                    'city' => 'San Pablo City',
                    'province' => 'Laguna',
                    'postal_code' => '4000',
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
            'San Pablo City',
            $riderAddress->city_municipality
        );

        $this->assertSame(
            'Laguna',
            $riderAddress->province
        );

        $this->assertSame(
            '4000',
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

    public function test_rider_cannot_move_home_address_outside_sponsoring_city(): void
    {
        ['rider' => $rider, 'riderAddress' => $riderAddress] = $this->makeRiderAccount();

        $this->from(route('rider.profile.index').'#addresses')
            ->actingAs($rider)
            ->patch(route('rider.profile.address.home.update'), [
                'street' => 'New Street',
                'barangay' => 'Poblacion',
                'city' => 'Calamba City',
                'province' => 'Laguna',
                'postal_code' => '4027',
            ])
            ->assertRedirect(route('rider.profile.index').'#addresses')
            ->assertSessionHasErrors('city');

        $this->assertSame('San Pablo City', $riderAddress->fresh()->city_municipality);
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

                'psgc_city_code' => '0403424000',

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

                'psgc_city_code' => '0403424000',
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

                'destination_rules' => [
                    'province' => 'Laguna',
                    'city_municipality' => 'San Pablo City',
                    'psgc_city_code' => '0403424000',
                    'barangays' => ['Del Remedio', 'San Roque', 'Santo Angel'],
                ],

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

                'psgc_city_code' => '0403424000',
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
