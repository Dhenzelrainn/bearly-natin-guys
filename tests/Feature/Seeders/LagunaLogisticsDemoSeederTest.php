<?php

namespace Tests\Feature\Seeders;

use App\Models\AccountApplication;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\Role;
use App\Models\SortingCenter;
use App\Models\User;
use App\Services\PostalCodeLookup;
use Database\Seeders\LagunaLogisticsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class LagunaLogisticsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const TARGET_CITY_CODES = [
        '0403401000',
        '0403402000',
        '0403403000',
        '0403404000',
        '0403405000',
        '0403406000',
        '0403407000',
        '0403408000',
        '0403409000',
        '0403410000',
        '0403411000',
        '0403412000',
        '0403413000',
        '0403414000',
        '0403415000',
        '0403416000',
        '0403417000',
        '0403418000',
        '0403419000',
        '0403420000',
        '0403421000',
        '0403423000',
        '0403424000',
        '0403425000',
        '0403426000',
        '0403427000',
        '0403428000',
        '0403429000',
    ];

    public function test_it_seeds_one_demo_provider_for_each_other_laguna_city_and_leaves_pila_and_victoria_untouched(): void
    {
        $admin = $this->makeAdmin();

        $pila = $this->makeProvider('0403422000', 'Pila', 'Laguna', '4010');
        $victoria = $this->makeProvider('0403430000', 'Victoria', 'Laguna', '4011');

        $pilaSnapshot = $this->providerSnapshot($pila);
        $victoriaSnapshot = $this->providerSnapshot($victoria);

        $this->app->make(LagunaLogisticsDemoSeeder::class)->run();

        $this->assertSame(28, User::query()
            ->where('email', 'like', 'logistics.demo.%@bearly.test')
            ->count());

        foreach (self::TARGET_CITY_CODES as $cityCode) {
            $place = $this->place($cityCode);
            $expectedPostalCode = $this->expectedPostalCode($place);
            $email = "logistics.demo.{$cityCode}@bearly.test";

            $user = User::query()->where('email', $email)->sole();

            $this->assertSame('logistics', $user->role);
            $this->assertSame('active', $user->status);
            $this->assertTrue($user->hasRole('logistics'));
            $this->assertSame((int) $admin->id, (int) $user->approved_by);
            $this->assertNotNull($user->approved_at);
            $this->assertSame('Region IV-A (CALABARZON)', $user->region);
            $this->assertSame('0400000000', $user->region_code);
            $this->assertSame('Laguna', $user->province);
            $this->assertSame($place['city'], $user->city);
            $this->assertSame('Poblacion', $user->barangay);
            $this->assertStringContainsString('Demo Logistics', (string) $user->name);
            $this->assertStringContainsString('Demo Logistics', (string) $user->business_name);
            $this->assertStringContainsString('Demo', (string) $user->street_address);
            $this->assertSame('+63920'.substr($cityCode, -7), $user->phone);
            $this->assertSame($user->phone, $user->contact_number);
            $this->assertTrue(Hash::check('Password123', $user->password));
            $this->assertSame(1, $user->roles()->where('roles.name', 'logistics')->count());

            $profile = LogisticsProfile::query()->where('user_id', $user->id)->sole();

            $this->assertSame($cityCode, $profile->psgc_city_code);
            $this->assertSame('active', $profile->status);
            $this->assertSame($user->name, $profile->display_name);
            $this->assertSame($user->name, $profile->legal_name);
            $this->assertSame($user->phone, $profile->contact_phone);
            $this->assertNotNull($profile->application_id);

            $application = AccountApplication::query()
                ->where('application_no', "DEMO-LOGISTICS-{$cityCode}")
                ->sole();

            $this->assertSame((int) $user->id, (int) $application->user_id);
            $this->assertSame((int) $profile->application_id, (int) $application->id);
            $this->assertSame('logistics', $application->requestedRole->name);
            $this->assertSame($user->name, $application->business_name);
            $this->assertNull($application->sponsor_logistics_profile_id);
            $this->assertSame('approved', $application->status);
            $this->assertSame((int) $admin->id, (int) $application->reviewed_by);
            $this->assertNotNull($application->submitted_at);
            $this->assertNotNull($application->review_started_at);
            $this->assertNotNull($application->decided_at);
            $this->assertNull($application->decision_reason);
            $this->assertStringContainsString(
                'no government ID or business permit was reviewed',
                (string) $application->revision_notes,
            );
            $this->assertSame(0, $application->documents()->count());

            $address = Address::query()
                ->where('user_id', $user->id)
                ->where('label', 'Sorting center')
                ->sole();

            $this->assertSame($user->name, $address->recipient_name);
            $this->assertSame($user->phone, $address->phone);
            $this->assertSame('Poblacion', $address->barangay);
            $this->assertNull($address->psgc_barangay_code);
            $this->assertSame('Region IV-A (CALABARZON)', $address->region);
            $this->assertSame('0400000000', $address->psgc_region_code);
            $this->assertSame('Laguna', $address->province);
            $this->assertSame('0403400000', $address->psgc_province_code);
            $this->assertSame($place['city'], $address->city_municipality);
            $this->assertSame($cityCode, $address->psgc_city_code);
            $this->assertSame($expectedPostalCode, $address->postal_code);
            $this->assertFalse((bool) $address->is_default_shipping);
            $this->assertTrue((bool) $address->is_default_pickup);
            $this->assertStringContainsString('Demo Logistics', (string) $address->street);

            $center = SortingCenter::query()
                ->where('logistics_profile_id', $profile->id)
                ->sole();

            $this->assertSame((int) $address->id, (int) $center->address_id);
            $this->assertSame($user->name, $center->name);
            $this->assertSame('SC-'.$profile->id, $center->code);
            $this->assertSame($user->phone, $center->contact_phone);
            $this->assertSame('active', $center->status);
            $this->assertNull($center->operating_hours);
            $this->assertNull($center->daily_capacity);
            $this->assertSame(0, $center->zones()->count());
        }

        $this->assertSame(28, LogisticsProfile::query()
            ->whereIn('psgc_city_code', self::TARGET_CITY_CODES)
            ->count());

        foreach ([
            '0403422000' => 'Pila',
            '0403430000' => 'Victoria',
        ] as $cityCode => $city) {
            $this->assertSame(1, LogisticsProfile::query()->where('psgc_city_code', $cityCode)->count());
            $this->assertDatabaseMissing('users', ['email' => "logistics.demo.{$cityCode}@bearly.test"]);
        }

        $this->assertSame($pilaSnapshot, $this->providerSnapshot($pila));
        $this->assertSame($victoriaSnapshot, $this->providerSnapshot($victoria));

        $this->assertSame(30, User::query()->where('role', 'logistics')->count());
        $this->assertSame(30, LogisticsProfile::query()->count());
        $this->assertSame(30, SortingCenter::query()->count());
        $this->assertSame(28, AccountApplication::query()->count());
        $this->assertSame(30, Address::query()->where('label', 'Sorting center')->count());
        $this->assertDatabaseCount('application_documents', 0);
        $this->assertDatabaseCount('sorting_zones', 0);
    }

    public function test_repeated_runs_do_not_create_duplicates(): void
    {
        $this->makeAdmin();

        $seeder = $this->app->make(LagunaLogisticsDemoSeeder::class);
        $seeder->run();

        $firstUserIds = User::query()->where('role', 'logistics')->orderBy('id')->pluck('id')->all();
        $firstProfileIds = LogisticsProfile::query()->orderBy('id')->pluck('id')->all();
        $firstAddressIds = Address::query()->where('label', 'Sorting center')->orderBy('id')->pluck('id')->all();
        $firstApplicationIds = AccountApplication::query()->orderBy('id')->pluck('id')->all();
        $firstCenterIds = SortingCenter::query()->orderBy('id')->pluck('id')->all();

        $seeder->run();
        $seeder->run();

        $this->assertSame($firstUserIds, User::query()->where('role', 'logistics')->orderBy('id')->pluck('id')->all());
        $this->assertSame($firstProfileIds, LogisticsProfile::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame($firstAddressIds, Address::query()->where('label', 'Sorting center')->orderBy('id')->pluck('id')->all());
        $this->assertSame($firstApplicationIds, AccountApplication::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame($firstCenterIds, SortingCenter::query()->orderBy('id')->pluck('id')->all());

        $this->assertSame(28, User::query()->where('role', 'logistics')->count());
        $this->assertSame(28, User::query()->where('role', 'logistics')->distinct()->count('email'));
        $this->assertSame(28, User::query()->where('role', 'logistics')->distinct()->count('phone'));
        $this->assertSame(28, LogisticsProfile::query()->count());
        $this->assertSame(28, SortingCenter::query()->count());
        $this->assertSame(28, AccountApplication::query()->count());
        $this->assertSame(28, Address::query()->where('label', 'Sorting center')->count());
        $this->assertSame(28, User::query()
            ->where('role', 'logistics')
            ->whereHas('roles', fn ($query) => $query->where('name', 'logistics'))
            ->count());
    }

    public function test_soft_deleted_profiles_and_pending_unprofiled_applicants_skip_only_their_own_city(): void
    {
        $this->makeAdmin();

        $bay = $this->place('0403402000');
        $calauan = $this->place('0403406000');

        $bayProvider = $this->makeProvider('0403402000', $bay['city'], 'Laguna', $this->expectedPostalCode($bay));
        $bayProvider['profile']->delete();

        $calauanApplicant = $this->makePendingApplicant('0403406000', $calauan['city'], 'Laguna', null);

        $baySnapshot = $this->providerSnapshot($bayProvider, withTrashedProfile: true);
        $calauanSnapshot = $this->pendingApplicantSnapshot($calauanApplicant);

        $this->app->make(LagunaLogisticsDemoSeeder::class)->run();

        $this->assertSame($baySnapshot, $this->providerSnapshot($bayProvider, withTrashedProfile: true));
        $this->assertSame($calauanSnapshot, $this->pendingApplicantSnapshot($calauanApplicant));

        $this->assertTrue(LogisticsProfile::withTrashed()->findOrFail($bayProvider['profile']->id)->trashed());
        $this->assertSame(1, LogisticsProfile::withTrashed()->where('psgc_city_code', '0403402000')->count());
        $this->assertSame(0, LogisticsProfile::withTrashed()->where('psgc_city_code', '0403406000')->count());
        $this->assertSame(0, LogisticsProfile::withTrashed()->where('user_id', $calauanApplicant['user']->id)->count());

        $this->assertDatabaseMissing('users', ['email' => 'logistics.demo.0403402000@bearly.test']);
        $this->assertDatabaseMissing('users', ['email' => 'logistics.demo.0403406000@bearly.test']);

        $this->assertDatabaseHas('users', ['email' => 'logistics.demo.0403401000@bearly.test']);
        $this->assertSame(26, User::query()
            ->where('email', 'like', 'logistics.demo.%@bearly.test')
            ->count());
        $this->assertSame(27, LogisticsProfile::withTrashed()->count());
        $this->assertSame(27, SortingCenter::query()->count());
        $this->assertSame(27, AccountApplication::query()->count());
    }

    public function test_a_logistics_account_in_another_city_does_not_suppress_the_laguna_targets(): void
    {
        $this->makeAdmin();

        $otherCity = $this->makeProvider('0306917000', 'Victoria', 'Tarlac', '2313');
        $snapshot = $this->providerSnapshot($otherCity);

        $this->app->make(LagunaLogisticsDemoSeeder::class)->run();

        $this->assertSame($snapshot, $this->providerSnapshot($otherCity));
        $this->assertSame(1, LogisticsProfile::query()->where('psgc_city_code', '0306917000')->count());
        $this->assertSame(28, User::query()
            ->where('email', 'like', 'logistics.demo.%@bearly.test')
            ->count());
        $this->assertSame(29, LogisticsProfile::query()->count());
        $this->assertSame(29, SortingCenter::query()->count());
    }

    public function test_a_city_label_alone_does_not_mark_a_target_occupied_without_a_matching_province(): void
    {
        $this->makeAdmin();

        $calauan = $this->place('0403406000');
        $applicant = $this->makePendingApplicant('0403406000', $calauan['city'], 'Tarlac', null);
        $snapshot = $this->pendingApplicantSnapshot($applicant);

        $this->app->make(LagunaLogisticsDemoSeeder::class)->run();

        $this->assertSame($snapshot, $this->pendingApplicantSnapshot($applicant));
        $this->assertDatabaseHas('users', ['email' => 'logistics.demo.0403406000@bearly.test']);
        $this->assertSame(1, LogisticsProfile::query()->where('psgc_city_code', '0403406000')->count());
        $this->assertSame(28, User::query()
            ->where('email', 'like', 'logistics.demo.%@bearly.test')
            ->count());
    }

    public function test_it_requires_an_active_admin_reviewer_before_any_write(): void
    {
        $this->makeAdmin('suspended');

        $userCount = User::withTrashed()->count();

        try {
            $this->app->make(LagunaLogisticsDemoSeeder::class)->run();
            $this->fail('The Laguna Logistics seeder should require an active Admin reviewer.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('requires an existing active Admin account', $exception->getMessage());
            $this->assertStringContainsString('No accounts were written', $exception->getMessage());
        }

        $this->assertSame($userCount, User::withTrashed()->count());
        $this->assertSame(0, Address::withTrashed()->count());
        $this->assertSame(0, LogisticsProfile::withTrashed()->count());
        $this->assertSame(0, SortingCenter::withTrashed()->count());
        $this->assertSame(0, AccountApplication::query()->count());
        $this->assertDatabaseMissing('roles', ['name' => 'logistics']);
    }

    public function test_it_aborts_before_writing_when_a_reserved_demo_identity_is_already_in_use(): void
    {
        $this->makeAdmin();

        $conflictingUser = User::factory()->create([
            'email' => 'logistics.demo.0403429000@bearly.test',
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $userCount = User::withTrashed()->count();

        try {
            $this->app->make(LagunaLogisticsDemoSeeder::class)->run();
            $this->fail('The Laguna Logistics seeder should reject a reserved demo email collision.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Reserved Laguna Logistics demo identities are already in use', $exception->getMessage());
            $this->assertStringContainsString('No accounts were written', $exception->getMessage());
        }

        $this->assertSame($userCount, User::withTrashed()->count());
        $this->assertSame(0, Address::withTrashed()->count());
        $this->assertSame(0, LogisticsProfile::withTrashed()->count());
        $this->assertSame(0, SortingCenter::withTrashed()->count());
        $this->assertSame(0, AccountApplication::query()->count());
        $this->assertDatabaseMissing('roles', ['name' => 'logistics']);
        $this->assertSame('buyer', $conflictingUser->fresh()->role);
    }

    public function test_invalid_target_city_codes_abort_before_any_write(): void
    {
        $cases = [
            [['0403401000', 'NOT-A-CODE'], 'valid 10-digit PSGC city/municipality codes'],
            [['0403401000', '0306917000'], 'does not resolve to Laguna'],
            [['0403401000', '0403422000'], 'Pila and Victoria must remain untouched'],
        ];

        foreach ($cases as [$cityCodes, $message]) {
            $seeder = new class($cityCodes) extends LagunaLogisticsDemoSeeder
            {
                /** @param list<string> $cityCodes */
                public function __construct(private array $cityCodes) {}

                protected function targetCityCodes(): array
                {
                    return $this->cityCodes;
                }
            };

            try {
                $seeder->run();
                $this->fail('The Laguna Logistics seeder should reject invalid target city codes.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        $this->assertSame(0, User::withTrashed()->count());
        $this->assertSame(0, Address::withTrashed()->count());
        $this->assertSame(0, LogisticsProfile::withTrashed()->count());
        $this->assertSame(0, SortingCenter::withTrashed()->count());
        $this->assertSame(0, AccountApplication::query()->count());
        $this->assertDatabaseMissing('roles', ['name' => 'logistics']);
    }

    public function test_it_refuses_to_seed_outside_local_or_testing_without_writing(): void
    {
        $originalEnvironment = $this->app['env'];
        $this->app->instance('env', 'production');

        try {
            try {
                $this->app->make(LagunaLogisticsDemoSeeder::class)->run();
                $this->fail('The Laguna Logistics demo seeder should reject production environments.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'LagunaLogisticsDemoSeeder may only run in local or testing environments.',
                    $exception->getMessage(),
                );
            }
        } finally {
            $this->app->instance('env', $originalEnvironment);
        }

        $this->assertSame(0, User::withTrashed()->count());
        $this->assertSame(0, Address::withTrashed()->count());
        $this->assertSame(0, LogisticsProfile::withTrashed()->count());
        $this->assertSame(0, SortingCenter::withTrashed()->count());
        $this->assertSame(0, AccountApplication::query()->count());
        $this->assertDatabaseMissing('roles', ['name' => 'logistics']);
    }

    /**
     * @return array{province: string, city: string, options: array<int, array{area?: string, code?: string}>}
     */
    private function place(string $cityCode): array
    {
        $place = app(PostalCodeLookup::class)->findByCanonicalCityCode($cityCode);

        $this->assertNotNull($place);
        $this->assertSame('Laguna', $place['province']);

        return [
            'province' => $place['province'],
            'city' => trim((string) $place['city']),
            'options' => $place['options'] ?? [],
        ];
    }

    /**
     * @param  array{options: array<int, array{area?: string, code?: string}>}  $place
     */
    private function expectedPostalCode(array $place): ?string
    {
        foreach ($place['options'] as $option) {
            $postalCode = trim((string) ($option['code'] ?? ''));

            if ($postalCode !== '') {
                return $postalCode;
            }
        }

        return null;
    }

    private function makeAdmin(string $status = 'active'): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'admin'],
            ['display_name' => 'Administrator'],
        );

        $admin = User::factory()->create([
            'name' => 'Laguna Demo Admin',
            'first_name' => 'Laguna Demo',
            'last_name' => 'Admin',
            'email' => 'laguna.demo.admin@bearly.test',
            'role' => 'admin',
            'status' => $status,
        ]);

        $admin->roles()->syncWithoutDetaching([
            $role->id => ['assigned_at' => now()],
        ]);

        return $admin;
    }

    /**
     * @return array{user: User, profile: LogisticsProfile, address: Address, center: SortingCenter}
     */
    private function makeProvider(
        string $cityCode,
        string $city,
        string $province,
        ?string $postalCode,
    ): array {
        $logisticsRole = Role::query()->firstOrCreate(
            ['name' => 'logistics'],
            ['display_name' => 'Logistics'],
        );

        $phone = '+63918'.substr($cityCode, -7);

        $user = User::factory()->create([
            'name' => "Existing {$city} Logistics",
            'first_name' => 'Existing',
            'last_name' => 'Logistics',
            'email' => "existing.logistics.{$cityCode}@example.test",
            'phone' => $phone,
            'contact_number' => $phone,
            'role' => 'logistics',
            'status' => 'active',
            'region' => 'Region IV-A (CALABARZON)',
            'region_code' => '0400000000',
            'province' => $province,
            'city' => $city,
            'barangay' => 'Poblacion',
            'street_address' => '1 Existing Logistics Road',
            'business_name' => "Existing {$city} Logistics",
        ]);

        $user->roles()->syncWithoutDetaching([
            $logisticsRole->id => ['assigned_at' => now()],
        ]);

        $profile = LogisticsProfile::query()->create([
            'user_id' => $user->id,
            'legal_name' => "Existing {$city} Logistics Inc.",
            'display_name' => "Existing {$city} Logistics",
            'contact_phone' => $phone,
            'psgc_city_code' => $cityCode,
            'status' => 'active',
        ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Sorting center',
            'recipient_name' => $user->name,
            'phone' => $phone,
            'house_number' => '1',
            'street' => 'Existing Logistics Road',
            'barangay' => 'Poblacion',
            'region' => 'Region IV-A (CALABARZON)',
            'city_municipality' => $city,
            'province' => $province,
            'postal_code' => $postalCode,
            'psgc_region_code' => '0400000000',
            'psgc_province_code' => $province === 'Laguna' ? '0403400000' : null,
            'psgc_city_code' => $cityCode,
            'is_default_shipping' => false,
            'is_default_pickup' => true,
        ]);

        $center = SortingCenter::query()->create([
            'logistics_profile_id' => $profile->id,
            'address_id' => $address->id,
            'name' => "Existing {$city} Sorting Center",
            'code' => 'SC-'.$profile->id,
            'contact_phone' => $phone,
            'status' => 'active',
        ]);

        return compact('user', 'profile', 'address', 'center');
    }

    /**
     * @return array{user: User, address: Address, application: AccountApplication}
     */
    private function makePendingApplicant(
        string $cityCode,
        string $city,
        string $province,
        ?string $addressCityCode,
    ): array {
        $logisticsRole = Role::query()->firstOrCreate(
            ['name' => 'logistics'],
            ['display_name' => 'Logistics'],
        );

        $phone = '+63919'.substr($cityCode, -7);

        $user = User::factory()->create([
            'name' => "Pending {$city} Logistics",
            'first_name' => 'Pending',
            'last_name' => 'Logistics',
            'email' => "pending.logistics.{$cityCode}.{$province}@example.test",
            'phone' => $phone,
            'contact_number' => $phone,
            'role' => 'logistics',
            'status' => 'pending',
            'province' => $province,
            'city' => $city,
            'barangay' => 'Poblacion',
            'street_address' => '1 Pending Logistics Road',
            'business_name' => "Pending {$city} Logistics",
        ]);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'label' => 'Sorting center',
            'recipient_name' => $user->name,
            'phone' => $phone,
            'house_number' => '1',
            'street' => 'Pending Logistics Road',
            'barangay' => 'Poblacion',
            'region' => 'Region IV-A (CALABARZON)',
            'city_municipality' => $city,
            'province' => $province,
            'postal_code' => null,
            'psgc_region_code' => $province === 'Laguna' ? '0400000000' : null,
            'psgc_province_code' => $province === 'Laguna' ? '0403400000' : null,
            'psgc_city_code' => $addressCityCode,
            'is_default_shipping' => false,
            'is_default_pickup' => true,
        ]);

        $application = AccountApplication::query()->create([
            'application_no' => "PENDING-LOGISTICS-{$cityCode}",
            'user_id' => $user->id,
            'requested_role_id' => $logisticsRole->id,
            'business_name' => $user->business_name,
            'status' => 'submitted',
            'submitted_at' => now(),
            'review_started_at' => null,
            'decided_at' => null,
            'reviewed_by' => null,
            'decision_reason' => null,
            'revision_notes' => null,
        ]);

        return compact('user', 'address', 'application');
    }

    /**
     * @param  array{user: User, profile: LogisticsProfile, address: Address, center: SortingCenter}  $provider
     * @return array<string, array<string, mixed>>
     */
    private function providerSnapshot(array $provider, bool $withTrashedProfile = false): array
    {
        $profile = $withTrashedProfile
            ? LogisticsProfile::withTrashed()->findOrFail($provider['profile']->id)
            : $provider['profile']->fresh();

        return [
            'user' => $provider['user']->fresh()->getAttributes(),
            'profile' => $profile->getAttributes(),
            'address' => $provider['address']->fresh()->getAttributes(),
            'center' => $provider['center']->fresh()->getAttributes(),
        ];
    }

    /**
     * @param  array{user: User, address: Address, application: AccountApplication}  $applicant
     * @return array<string, array<string, mixed>>
     */
    private function pendingApplicantSnapshot(array $applicant): array
    {
        return [
            'user' => $applicant['user']->fresh()->getAttributes(),
            'address' => $applicant['address']->fresh()->getAttributes(),
            'application' => $applicant['application']->fresh()->getAttributes(),
        ];
    }
}
