<?php

namespace Tests\Feature\Seeders;

use App\Models\AccountApplication;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PilaVictoriaLocalRiderDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PilaVictoriaLocalRiderDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_BARANGAYS = [
        'Pila' => [
            'city_code' => '0403422000',
            'postal_code' => '4010',
            'barangays' => [
                '0403422001' => 'Aplaya',
                '0403422002' => 'Bagong Pook',
                '0403422003' => 'Bukal',
                '0403422004' => 'Bulilan Norte',
                '0403422005' => 'Bulilan Sur',
                '0403422006' => 'Concepcion',
                '0403422007' => 'Labuin',
                '0403422008' => 'Linga',
                '0403422009' => 'Masico',
                '0403422010' => 'Mojon',
                '0403422011' => 'Pansol',
                '0403422012' => 'Pinagbayanan',
                '0403422014' => 'San Antonio',
                '0403422015' => 'San Miguel',
                '0403422016' => 'Santa Clara Norte',
                '0403422017' => 'Santa Clara Sur',
                '0403422018' => 'Tubuan',
            ],
        ],
        'Victoria' => [
            'city_code' => '0403430000',
            'postal_code' => '4011',
            'barangays' => [
                '0403430001' => 'Banca-banca',
                '0403430002' => 'Daniw',
                '0403430003' => 'Masapang',
                '0403430004' => 'Nanhaya',
                '0403430005' => 'Pagalangan',
                '0403430007' => 'San Benito',
                '0403430008' => 'San Felix',
                '0403430009' => 'San Francisco',
                '0403430010' => 'San Roque',
            ],
        ],
    ];

    public function test_it_seeds_one_local_rider_for_every_official_barangay_with_the_matching_city_sponsor(): void
    {
        // An unrelated provider is created first to prove the seeder selects by
        // canonical city code rather than falling back to the first profile.
        $this->makeLogisticsSponsor('San Pablo', '0403424000', '4000');
        [$pilaSponsor, $pilaProfile] = $this->makeLogisticsSponsor('Pila', '0403422000', '4010');
        [$victoriaSponsor, $victoriaProfile] = $this->makeLogisticsSponsor('Victoria', '0403430000', '4011');

        $this->app->make(PilaVictoriaLocalRiderDemoSeeder::class)->run();

        $this->assertSame(26, User::query()->where('role', 'rider')->count());

        $riderRoleId = (int) Role::query()->where('name', 'rider')->value('id');

        $passwordChecked = false;

        foreach (self::EXPECTED_BARANGAYS as $city => $municipality) {
            $isPila = $city === 'Pila';
            $sponsor = $isPila ? $pilaSponsor : $victoriaSponsor;
            $sponsorProfile = $isPila ? $pilaProfile : $victoriaProfile;

            foreach ($municipality['barangays'] as $barangayCode => $barangay) {
                $address = Address::query()
                    ->where('label', 'Home')
                    ->where('psgc_barangay_code', $barangayCode)
                    ->sole();

                $user = $address->user;
                $profile = $user->riderProfile;
                $application = $user->applications()->sole();

                $this->assertSame('rider', $user->role);
                $this->assertSame('active', $user->status);
                $this->assertTrue($user->hasRole('rider'));
                $this->assertSame((int) $sponsor->id, (int) $user->logistics_id);
                $this->assertSame((int) $sponsor->id, (int) $user->approved_by);
                $this->assertSame('Region IV-A (CALABARZON)', $user->region);
                $this->assertSame('0400000000', $user->region_code);
                $this->assertSame('Laguna', $user->province);
                $this->assertSame($city, $user->city);
                $this->assertSame($barangay, $user->barangay);
                $this->assertSame('Motorcycle', $user->vehicle_type);

                $this->assertSame((int) $user->id, (int) $address->user_id);
                $this->assertSame('Region IV-A (CALABARZON)', $address->region);
                $this->assertSame('0400000000', $address->psgc_region_code);
                $this->assertSame('Laguna', $address->province);
                $this->assertSame('0403400000', $address->psgc_province_code);
                $this->assertSame($city, $address->city_municipality);
                $this->assertSame($municipality['city_code'], $address->psgc_city_code);
                $this->assertSame($barangay, $address->barangay);
                $this->assertSame($barangayCode, $address->psgc_barangay_code);
                $this->assertSame($municipality['postal_code'], $address->postal_code);

                $this->assertSame((int) $sponsorProfile->id, (int) $profile->logistics_profile_id);
                $this->assertSame('local', $profile->work_type);
                $this->assertSame('Motorcycle', $profile->vehicle_type);
                $this->assertSame('available', $profile->availability_status);
                $this->assertSame('approved', $profile->verification_status);
                $this->assertSame('both', $profile->specialization);
                $this->assertSame(10, (int) $profile->parcel_capacity);
                $this->assertNull($profile->linehaul_parcel_capacity);
                $this->assertNull($profile->courier_vehicle_verified_by);
                $this->assertNull($profile->courier_vehicle_verified_at);

                $this->assertSame((int) $user->id, (int) $application->user_id);
                $this->assertSame($riderRoleId, (int) $application->requested_role_id);
                $this->assertSame((int) $sponsorProfile->id, (int) $application->sponsor_logistics_profile_id);
                $this->assertSame('local', $application->requested_work_type);
                $this->assertSame('approved', $application->status);
                $this->assertSame(0, $application->documents()->count());
                $this->assertStringContainsString(
                    'no identity documents were reviewed',
                    (string) $application->revision_notes,
                );

                $this->assertSame(1, $user->addresses()->where('label', 'Home')->count());
                $this->assertSame(1, $user->roles()->where('roles.name', 'rider')->count());

                if (! $passwordChecked) {
                    $this->assertTrue(Hash::check('Password123', $user->password));
                    $passwordChecked = true;
                }
            }
        }

        $riders = User::query()->where('role', 'rider')->get();

        $this->assertSame(26, $riders->pluck('email')->unique()->count());
        $this->assertSame(26, $riders->pluck('phone')->unique()->count());
        $this->assertSame(26, RiderProfile::query()->pluck('plate_number')->unique()->count());
        $this->assertSame(26, AccountApplication::query()->pluck('application_no')->unique()->count());
        $this->assertSame(26, Address::query()->where('label', 'Home')->count());
        $this->assertSame(0, AccountApplication::query()->whereHas('documents')->count());
    }

    public function test_repeated_runs_are_idempotent(): void
    {
        $this->makeLogisticsSponsor('Pila', '0403422000', '4010');
        $this->makeLogisticsSponsor('Victoria', '0403430000', '4011');

        $seeder = $this->app->make(PilaVictoriaLocalRiderDemoSeeder::class);

        $seeder->run();

        $firstRunUserIds = User::query()->where('role', 'rider')->orderBy('id')->pluck('id')->all();

        $seeder->run();
        $seeder->run();

        $this->assertSame(26, User::query()->where('role', 'rider')->count());
        $this->assertSame($firstRunUserIds, User::query()->where('role', 'rider')->orderBy('id')->pluck('id')->all());
        $this->assertSame(26, RiderProfile::query()->count());
        $this->assertSame(26, AccountApplication::query()->count());
        $this->assertSame(26, Address::query()->where('label', 'Home')->count());
        $this->assertSame(26, User::query()->where('role', 'rider')->whereHas('roles', fn ($query) => $query->where('name', 'rider'))->count());
    }

    public function test_existing_inactive_and_courier_riders_block_only_their_own_barangay_and_are_not_modified(): void
    {
        [$pilaSponsor, $pilaProfile] = $this->makeLogisticsSponsor('Pila', '0403422000', '4010');
        [$victoriaSponsor, $victoriaProfile] = $this->makeLogisticsSponsor('Victoria', '0403430000', '4011');

        [$inactiveRider, $inactiveProfile] = $this->makeExistingRider(
            $pilaSponsor,
            $pilaProfile,
            'Pila',
            '0403422000',
            'Aplaya',
            '0403422001',
            'suspended',
            'local',
        );

        [$courierRider, $courierProfile] = $this->makeExistingRider(
            $victoriaSponsor,
            $victoriaProfile,
            'Victoria',
            '0403430000',
            'Banca-banca',
            '0403430001',
            'active',
            'courier',
        );

        $inactiveSnapshot = $inactiveRider->fresh()->getAttributes();
        $inactiveProfileSnapshot = $inactiveProfile->fresh()->getAttributes();
        $courierSnapshot = $courierRider->fresh()->getAttributes();
        $courierProfileSnapshot = $courierProfile->fresh()->getAttributes();

        $this->app->make(PilaVictoriaLocalRiderDemoSeeder::class)->run();

        $this->assertSame(26, User::withTrashed()->where('role', 'rider')->count());
        $this->assertSame(1, Address::query()->where('label', 'Home')->where('psgc_barangay_code', '0403422001')->count());
        $this->assertSame(1, Address::query()->where('label', 'Home')->where('psgc_barangay_code', '0403430001')->count());

        $this->assertSame($inactiveRider->id, Address::query()
            ->where('label', 'Home')
            ->where('psgc_barangay_code', '0403422001')
            ->value('user_id'));
        $this->assertSame($courierRider->id, Address::query()
            ->where('label', 'Home')
            ->where('psgc_barangay_code', '0403430001')
            ->value('user_id'));

        $this->assertSame($inactiveSnapshot, $inactiveRider->fresh()->getAttributes());
        $this->assertSame($inactiveProfileSnapshot, $inactiveProfile->fresh()->getAttributes());
        $this->assertSame($courierSnapshot, $courierRider->fresh()->getAttributes());
        $this->assertSame($courierProfileSnapshot, $courierProfile->fresh()->getAttributes());
        $this->assertSame('suspended', $inactiveRider->fresh()->status);
        $this->assertSame('local', $inactiveProfile->fresh()->work_type);
        $this->assertSame('courier', $courierProfile->fresh()->work_type);
        $this->assertSame(0, $inactiveRider->applications()->count());
        $this->assertSame(0, $courierRider->applications()->count());
    }

    public function test_soft_deleted_and_legacy_address_riders_still_block_their_barangay(): void
    {
        [$pilaSponsor, $pilaProfile] = $this->makeLogisticsSponsor('Pila', '0403422000', '4010');
        $this->makeLogisticsSponsor('Victoria', '0403430000', '4011');

        [$softDeletedRider, $softDeletedProfile] = $this->makeExistingRider(
            $pilaSponsor,
            $pilaProfile,
            'Pila',
            '0403422000',
            'Bukal',
            '0403422003',
            'active',
            'local',
        );

        $softDeletedProfile->delete();
        $softDeletedRider->delete();

        [$legacyRider, $legacyProfile] = $this->makeExistingRider(
            $pilaSponsor,
            $pilaProfile,
            'Pila',
            '0403422000',
            'Bagong Pook',
            null,
            'active',
            'local',
        );

        $this->app->make(PilaVictoriaLocalRiderDemoSeeder::class)->run();

        $this->assertSame(26, User::withTrashed()->where('role', 'rider')->count());
        $this->assertSame(1, Address::query()
            ->where('label', 'Home')
            ->where('city_municipality', 'Pila')
            ->where('barangay', 'Bagong Pook')
            ->count());
        $this->assertSame(1, Address::query()
            ->where('label', 'Home')
            ->where('psgc_barangay_code', '0403422003')
            ->count());

        $this->assertSame($legacyRider->id, Address::query()
            ->where('label', 'Home')
            ->where('city_municipality', 'Pila')
            ->where('barangay', 'Bagong Pook')
            ->value('user_id'));
        $this->assertSame($softDeletedRider->id, Address::query()
            ->where('label', 'Home')
            ->where('psgc_barangay_code', '0403422003')
            ->value('user_id'));

        $this->assertTrue($softDeletedRider->fresh()->trashed());
        $this->assertTrue($softDeletedProfile->fresh()->trashed());
        $this->assertSame('local', $legacyProfile->fresh()->work_type);
        $this->assertSame(0, $legacyRider->applications()->count());
    }

    public function test_it_refuses_to_seed_outside_local_or_testing_without_writing(): void
    {
        $originalEnvironment = $this->app['env'];
        $this->app->instance('env', 'production');

        try {
            try {
                $this->app->make(PilaVictoriaLocalRiderDemoSeeder::class)->run();
                $this->fail('The Local Rider demo seeder should reject production environments.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'PilaVictoriaLocalRiderDemoSeeder may only run in local or testing environments.',
                    $exception->getMessage(),
                );
            }
        } finally {
            $this->app->instance('env', $originalEnvironment);
        }

        $this->assertSame(0, User::withTrashed()->count());
        $this->assertSame(0, Address::withTrashed()->count());
        $this->assertSame(0, RiderProfile::withTrashed()->count());
        $this->assertSame(0, AccountApplication::count());
        $this->assertDatabaseMissing('roles', ['name' => 'rider']);
    }

    public function test_it_fails_before_any_write_when_a_city_has_no_valid_active_city_matched_sponsor(): void
    {
        $this->makeLogisticsSponsor('Pila', '0403422000', '4010');
        $this->makeLogisticsSponsor('Victoria', '0403430000', '4011', 'suspended');
        $this->makeLogisticsSponsor('San Pablo', '0403424000', '4000');

        $userCount = User::withTrashed()->count();
        $addressCount = Address::withTrashed()->count();

        try {
            $this->app->make(PilaVictoriaLocalRiderDemoSeeder::class)->run();
            $this->fail('The seeder should require a valid active Victoria sponsor.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Victoria, Laguna needs exactly one active Logistics profile', $exception->getMessage());
            $this->assertStringContainsString('0403430000', $exception->getMessage());
        }

        $this->assertSame($userCount, User::withTrashed()->count());
        $this->assertSame($addressCount, Address::withTrashed()->count());
        $this->assertSame(0, RiderProfile::withTrashed()->count());
        $this->assertSame(0, AccountApplication::count());
        $this->assertDatabaseMissing('roles', ['name' => 'rider']);
        $this->assertDatabaseMissing('users', ['email' => 'rider.demo.pila.0403422001@bearly.test']);
    }

    public function test_it_aborts_before_writing_when_a_reserved_demo_identity_is_already_in_use(): void
    {
        $this->makeLogisticsSponsor('Pila', '0403422000', '4010');
        $this->makeLogisticsSponsor('Victoria', '0403430000', '4011');

        $conflictingUser = User::factory()->create([
            'email' => 'rider.demo.pila.0403422001@bearly.test',
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $userCount = User::withTrashed()->count();
        $addressCount = Address::withTrashed()->count();

        try {
            $this->app->make(PilaVictoriaLocalRiderDemoSeeder::class)->run();
            $this->fail('The seeder should reject a reserved demo email collision.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Reserved Local Rider demo identities are already in use', $exception->getMessage());
            $this->assertStringContainsString('No accounts were written', $exception->getMessage());
        }

        $this->assertSame($userCount, User::withTrashed()->count());
        $this->assertSame($addressCount, Address::withTrashed()->count());
        $this->assertSame(0, RiderProfile::withTrashed()->count());
        $this->assertSame(0, AccountApplication::count());
        $this->assertDatabaseMissing('roles', ['name' => 'rider']);
        $this->assertSame('buyer', $conflictingUser->fresh()->role);
    }

    /** @return array{0: User, 1: LogisticsProfile} */
    private function makeLogisticsSponsor(
        string $city,
        string $cityCode,
        string $postalCode,
        string $userStatus = 'active',
        string $profileStatus = 'active',
    ): array {
        $logisticsRole = Role::query()->firstOrCreate(
            ['name' => 'logistics'],
            ['display_name' => 'Logistics'],
        );

        $phone = '+63918'.substr($cityCode, -7);

        $sponsor = User::factory()->create([
            'name' => "{$city} Logistics",
            'first_name' => 'Demo',
            'last_name' => 'Logistics',
            'email' => "logistics.demo.{$cityCode}@example.test",
            'phone' => $phone,
            'contact_number' => $phone,
            'role' => 'logistics',
            'status' => $userStatus,
            'province' => 'Laguna',
            'city' => $city,
            'barangay' => 'Poblacion',
        ]);

        $sponsor->roles()->syncWithoutDetaching([
            $logisticsRole->id => ['assigned_at' => now()],
        ]);

        $profile = LogisticsProfile::query()->create([
            'user_id' => $sponsor->id,
            'legal_name' => "{$city} Logistics Inc.",
            'display_name' => "{$city} Logistics",
            'contact_phone' => $phone,
            'psgc_city_code' => $cityCode,
            'status' => $profileStatus,
        ]);

        Address::query()->create([
            'user_id' => $sponsor->id,
            'label' => 'Sorting center',
            'recipient_name' => $sponsor->name,
            'phone' => $phone,
            'street' => 'Demo Logistics Road',
            'barangay' => 'Poblacion',
            'city_municipality' => $city,
            'province' => 'Laguna',
            'postal_code' => $postalCode,
            'psgc_city_code' => $cityCode,
            'psgc_province_code' => '0403400000',
        ]);

        return [$sponsor, $profile];
    }

    /** @return array{0: User, 1: RiderProfile} */
    private function makeExistingRider(
        User $sponsor,
        LogisticsProfile $sponsorProfile,
        string $city,
        string $cityCode,
        string $barangay,
        ?string $barangayCode,
        string $status,
        string $workType,
    ): array {
        $riderRole = Role::query()->firstOrCreate(
            ['name' => 'rider'],
            ['display_name' => 'Rider'],
        );

        $slug = Str::slug($barangay);
        $phone = '+63919'.(
            $barangayCode !== null
                ? substr($barangayCode, -7)
                : str_pad((string) (crc32($city.$barangay) % 10000000), 7, '0', STR_PAD_LEFT)
        );
        $plate = 'EXIST-'.strtoupper($slug);

        $user = User::query()->create([
            'name' => "Existing {$barangay} Rider",
            'first_name' => 'Existing',
            'last_name' => 'Rider',
            'email' => "existing.rider.{$cityCode}.{$slug}@example.test",
            'email_verified_at' => now(),
            'phone' => $phone,
            'contact_number' => $phone,
            'role' => 'rider',
            'status' => $status,
            'province' => 'Laguna',
            'city' => $city,
            'barangay' => $barangay,
            'street_address' => '1 Existing Rider Street',
            'vehicle_type' => $workType === 'courier' ? 'Van' : 'Motorcycle',
            'plate_number' => $plate,
            'logistics_id' => $sponsor->id,
            'approved_by' => $sponsor->id,
            'approved_at' => now(),
            'password' => Hash::make('Password123'),
        ]);

        $user->roles()->syncWithoutDetaching([
            $riderRole->id => [
                'assigned_by' => $sponsor->id,
                'assigned_at' => now(),
            ],
        ]);

        Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Home',
            'recipient_name' => $user->name,
            'phone' => $phone,
            'house_number' => '1',
            'street' => 'Existing Rider Street',
            'barangay' => $barangay,
            'city_municipality' => $city,
            'province' => 'Laguna',
            'postal_code' => $city === 'Pila' ? '4010' : '4011',
            'psgc_province_code' => '0403400000',
            'psgc_city_code' => $barangayCode === null ? null : $cityCode,
            'psgc_barangay_code' => $barangayCode,
        ]);

        $profile = RiderProfile::query()->create([
            'user_id' => $user->id,
            'logistics_profile_id' => $sponsorProfile->id,
            'vehicle_type' => $workType === 'courier' ? 'Van' : 'Motorcycle',
            'vehicle_model' => $workType === 'courier' ? 'Existing courier van' : 'Existing local motorcycle',
            'plate_number' => $plate,
            'parcel_capacity' => $workType === 'courier' ? null : 10,
            'availability_status' => 'offline',
            'verification_status' => 'approved',
            'work_type' => $workType,
            'linehaul_parcel_capacity' => $workType === 'courier' ? 10 : null,
            'courier_vehicle_verified_by' => $workType === 'courier' ? $sponsor->id : null,
            'courier_vehicle_verified_at' => $workType === 'courier' ? now() : null,
            'specialization' => 'both',
        ]);

        return [$user, $profile];
    }
}
