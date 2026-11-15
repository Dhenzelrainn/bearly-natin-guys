<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\RiderSpecialization;
use App\Enums\RiderWorkType;
use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\RiderProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\PostalCodeLookup;
use App\Services\RiderZoneAssignmentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Seeds one deterministic Local Rider demo account per official barangay in
 * Pila and Victoria, Laguna, skipping any barangay that already has a Rider.
 *
 * Manual local/testing use:
 * php artisan db:seed --class=PilaVictoriaLocalRiderDemoSeeder
 */
class PilaVictoriaLocalRiderDemoSeeder extends Seeder
{
    protected const PASSWORD = 'Password123';

    protected const REGION = 'Region IV-A (CALABARZON)';

    protected const REGION_CODE = '0400000000';

    protected const PROVINCE = 'Laguna';

    protected const PROVINCE_CODE = '0403400000';

    protected const PARCEL_CAPACITY = 10;

    /**
     * Official PSGC barangays verified from the psgc.cloud API v2 source used
     * by the application. Keep this snapshot deterministic so seeding never
     * depends on a live network request.
     *
     * @var array<string, array{
     *     city_code: string,
     *     postal_code: string,
     *     barangays: array<string, string>
     * }>
     */
    protected const MUNICIPALITIES = [
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

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                class_basename(static::class).' may only run in local or testing environments.'
            );
        }

        $sponsors = $this->resolveSponsors();
        $occupied = $this->occupiedBarangays();

        $ridersToCreate = [];
        $skippedBarangays = [];

        foreach (self::MUNICIPALITIES as $city => $municipality) {
            foreach ($municipality['barangays'] as $barangayCode => $barangay) {
                if (isset($occupied[$barangayCode])) {
                    $skippedBarangays[] = "{$barangay}, {$city}";

                    continue;
                }

                $ridersToCreate[] = $this->riderFixture(
                    $city,
                    $municipality,
                    $barangayCode,
                    $barangay,
                    $sponsors[$city],
                );
            }
        }

        if ($ridersToCreate === []) {
            $this->reportResult([], $skippedBarangays);

            return;
        }

        // Check every reserved identity before opening the write transaction so
        // a collision cannot leave one municipality partially seeded.
        $this->assertIdentitiesAvailable($ridersToCreate);

        DB::transaction(function () use ($ridersToCreate): void {
            $riderRole = Role::query()->firstOrCreate(
                ['name' => UserRole::Rider->value],
                ['display_name' => 'Rider'],
            );

            $now = now();

            foreach ($ridersToCreate as $fixture) {
                $sponsor = $fixture['sponsor'];

                $user = User::query()->create([
                    'name' => $fixture['name'],
                    'first_name' => $fixture['first_name'],
                    'last_name' => $fixture['last_name'],
                    'middle_initial' => null,
                    'sex' => 'prefer_not_to_say',
                    'birthday' => '1995-01-01',
                    'email' => $fixture['email'],
                    'email_verified_at' => $now,
                    'phone' => $fixture['phone'],
                    'contact_number' => $fixture['phone'],
                    'role' => UserRole::Rider->value,
                    'status' => AccountStatus::Active->value,
                    'region' => self::REGION,
                    'region_code' => self::REGION_CODE,
                    'province' => self::PROVINCE,
                    'city' => $fixture['city'],
                    'barangay' => $fixture['barangay'],
                    'street_address' => $fixture['street'],
                    'vehicle_type' => RiderWorkType::vehicleTypes(RiderWorkType::Local)[0],
                    'plate_number' => $fixture['plate_number'],
                    'logistics_id' => $sponsor['user']->id,
                    'approved_by' => $sponsor['user']->id,
                    'approved_at' => $now,
                    'terms_accepted_at' => $now,
                    'terms_version' => config('bearly-policies.version'),
                    'privacy_version' => config('bearly-policies.version'),
                    'password' => Hash::make(self::PASSWORD),
                ]);

                $user->roles()->syncWithoutDetaching([
                    $riderRole->id => [
                        'assigned_by' => $sponsor['user']->id,
                        'assigned_at' => $now,
                    ],
                ]);

                Address::query()->create([
                    'user_id' => $user->id,
                    'label' => 'Home',
                    'recipient_name' => $fixture['name'],
                    'phone' => $fixture['phone'],
                    'house_number' => '1',
                    'street' => $fixture['street'],
                    'region' => self::REGION,
                    'barangay' => $fixture['barangay'],
                    'city_municipality' => $fixture['city'],
                    'province' => self::PROVINCE,
                    'postal_code' => $fixture['postal_code'],
                    'psgc_region_code' => self::REGION_CODE,
                    'psgc_province_code' => self::PROVINCE_CODE,
                    'psgc_city_code' => $fixture['city_code'],
                    'psgc_barangay_code' => $fixture['barangay_code'],
                    'is_default_shipping' => false,
                    'is_default_pickup' => false,
                ]);

                AccountApplication::query()->create([
                    'application_no' => $fixture['application_no'],
                    'user_id' => $user->id,
                    'requested_role_id' => $riderRole->id,
                    'sponsor_logistics_profile_id' => $sponsor['profile']->id,
                    'requested_work_type' => RiderWorkType::Local->value,
                    'rider_vehicle_model' => 'Demo local motorcycle',
                    'status' => 'approved',
                    'submitted_at' => $now,
                    'review_started_at' => $now,
                    'decided_at' => $now,
                    'reviewed_by' => $sponsor['user']->id,
                    'decision_reason' => null,
                    'revision_notes' => 'Local/testing demo fixture only; no identity documents were reviewed.',
                ]);

                $profile = RiderProfile::query()->create([
                    'user_id' => $user->id,
                    'logistics_profile_id' => $sponsor['profile']->id,
                    'home_sorting_center_id' => null,
                    'current_zone_id' => null,
                    'vehicle_type' => RiderWorkType::vehicleTypes(RiderWorkType::Local)[0],
                    'vehicle_model' => 'Demo local motorcycle',
                    'plate_number' => $fixture['plate_number'],
                    'parcel_capacity' => self::PARCEL_CAPACITY,
                    'availability_status' => 'available',
                    'verification_status' => 'approved',
                    'work_type' => RiderWorkType::Local->value,
                    'linehaul_parcel_capacity' => null,
                    'courier_vehicle_verified_by' => null,
                    'courier_vehicle_verified_at' => null,
                    'specialization' => RiderSpecialization::Both->value,
                ]);

                app(RiderZoneAssignmentService::class)->sync($profile);
            }
        });

        $this->reportResult($ridersToCreate, $skippedBarangays);
    }

    /**
     * @return array<string, array{profile: LogisticsProfile, user: User}>
     */
    private function resolveSponsors(): array
    {
        $sponsors = [];
        $problems = [];

        foreach (self::MUNICIPALITIES as $city => $municipality) {
            $cityCode = $municipality['city_code'];

            $profiles = LogisticsProfile::query()
                ->where('status', AccountStatus::Active->value)
                ->where('psgc_city_code', $cityCode)
                ->whereHas('user', fn ($query) => $query
                    ->where('role', UserRole::Logistics->value)
                    ->where('status', AccountStatus::Active->value))
                ->with('user.addresses')
                ->orderBy('id')
                ->get()
                ->filter(fn (LogisticsProfile $profile): bool => $profile->user?->addresses
                    ->contains(fn (Address $address): bool => trim((string) $address->psgc_city_code) === $cityCode) ?? false)
                ->values();

            if ($profiles->count() !== 1) {
                $problems[] = "{$city}, ".self::PROVINCE
                    ." needs exactly one active Logistics profile whose user is active and has a service address for PSGC city code {$cityCode}; found {$profiles->count()}.";

                continue;
            }

            /** @var LogisticsProfile $profile */
            $profile = $profiles->first();

            $sponsors[$city] = [
                'profile' => $profile,
                'user' => $profile->user,
            ];
        }

        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }

        return $sponsors;
    }

    /**
     * Detect occupied barangays from any persisted Rider-role account or Rider
     * profile, including soft-deleted records and non-local work types.
     *
     * @return array<string, string>
     */
    private function occupiedBarangays(): array
    {
        $targets = [];

        foreach (self::MUNICIPALITIES as $city => $municipality) {
            $targets[$municipality['city_code']] = [
                'city' => $city,
                'barangays' => $municipality['barangays'],
            ];
        }

        $riders = User::withTrashed()
            ->where(function ($query): void {
                $query->where('role', UserRole::Rider->value)
                    ->orWhereHas('roles', fn ($roles) => $roles->where('name', UserRole::Rider->value))
                    ->orWhereHas('riderProfile', fn ($profiles) => $profiles->withTrashed());
            })
            ->with('addresses')
            ->get();

        $occupied = [];

        foreach ($riders as $rider) {
            $matchedByAddress = false;

            foreach ($rider->addresses as $address) {
                $match = $this->matchingBarangay($address, $targets);

                if ($match === null) {
                    continue;
                }

                $matchedByAddress = true;
                $occupied[$match['code']] = $match['city'];
            }

            if ($matchedByAddress) {
                continue;
            }

            // Legacy profiles may only have the users table location fields.
            $legacyCityCode = app(PostalCodeLookup::class)->canonicalCityCode(
                null,
                (string) $rider->province,
                (string) $rider->city,
            );

            if ($legacyCityCode === null || ! isset($targets[$legacyCityCode])) {
                continue;
            }

            $legacyBarangay = $this->normalizeLabel((string) $rider->barangay);

            foreach ($targets[$legacyCityCode]['barangays'] as $barangayCode => $barangay) {
                if ($this->normalizeLabel($barangay) === $legacyBarangay) {
                    $occupied[$barangayCode] = $targets[$legacyCityCode]['city'];

                    break;
                }
            }
        }

        return $occupied;
    }

    /**
     * @param  array<string, array{city: string, barangays: array<string, string>}>  $targets
     * @return array{code: string, city: string}|null
     */
    private function matchingBarangay(Address $address, array $targets): ?array
    {
        $cityCode = $this->cityCodeForAddress($address);

        if ($cityCode === null || ! isset($targets[$cityCode])) {
            return null;
        }

        $barangayCode = trim((string) $address->psgc_barangay_code);

        if ($barangayCode !== '') {
            return isset($targets[$cityCode]['barangays'][$barangayCode])
                ? ['code' => $barangayCode, 'city' => $targets[$cityCode]['city']]
                : null;
        }

        $barangay = $this->normalizeLabel((string) $address->barangay);

        foreach ($targets[$cityCode]['barangays'] as $code => $name) {
            if ($this->normalizeLabel($name) === $barangay) {
                return ['code' => $code, 'city' => $targets[$cityCode]['city']];
            }
        }

        return null;
    }

    private function cityCodeForAddress(Address $address): ?string
    {
        $cityCode = trim((string) $address->psgc_city_code);

        if ($cityCode !== '') {
            return $cityCode;
        }

        // Legacy rows without a PSGC code fall back to their verified
        // province + municipality labels; a city name alone is never enough.
        return app(PostalCodeLookup::class)->canonicalCityCode(
            null,
            (string) $address->province,
            (string) $address->city_municipality,
        );
    }

    private function normalizeLabel(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return preg_replace('/[^a-z0-9]/', '', strtolower($ascii ?: $value)) ?: '';
    }

    /**
     * @param  array<string, array{city_code: string, postal_code: string, barangays: array<string, string>}>  $municipality
     * @param  array{profile: LogisticsProfile, user: User}  $sponsor
     * @return array{
     *     city: string,
     *     city_code: string,
     *     postal_code: string,
     *     barangay: string,
     *     barangay_code: string,
     *     name: string,
     *     first_name: string,
     *     last_name: string,
     *     email: string,
     *     phone: string,
     *     plate_number: string,
     *     application_no: string,
     *     street: string,
     *     sponsor: array{profile: LogisticsProfile, user: User}
     * }
     */
    private function riderFixture(
        string $city,
        array $municipality,
        string $barangayCode,
        string $barangay,
        array $sponsor,
    ): array {
        $citySlug = strtolower($city);

        return [
            'city' => $city,
            'city_code' => $municipality['city_code'],
            'postal_code' => $municipality['postal_code'],
            'barangay' => $barangay,
            'barangay_code' => $barangayCode,
            'name' => "{$city} {$barangay} Demo Rider",
            'first_name' => "{$city} {$barangay}",
            'last_name' => 'Demo Rider',
            'email' => "rider.demo.{$citySlug}.{$barangayCode}@bearly.test",
            'phone' => '+63917'.substr($barangayCode, -7),
            'plate_number' => "DEMO-LOCAL-{$barangayCode}",
            'application_no' => "DEMO-LOCAL-RIDER-{$barangayCode}",
            'street' => "{$barangay} Demo Rider Street",
            'sponsor' => $sponsor,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $riders
     */
    private function assertIdentitiesAvailable(array $riders): void
    {
        $emails = array_column($riders, 'email');
        $phones = array_column($riders, 'phone');
        $plates = array_column($riders, 'plate_number');
        $applicationNumbers = array_column($riders, 'application_no');

        if (
            count($emails) !== count(array_unique($emails))
            || count($phones) !== count(array_unique($phones))
            || count($plates) !== count(array_unique($plates))
            || count($applicationNumbers) !== count(array_unique($applicationNumbers))
        ) {
            throw new RuntimeException(
                'Local Rider demo identities are not deterministic and unique. No accounts were written.'
            );
        }

        $conflicts = [];

        User::withTrashed()
            ->where(function ($query) use ($emails, $phones, $plates): void {
                $query->whereIn('email', $emails)
                    ->orWhereIn('phone', $phones)
                    ->orWhereIn('contact_number', $phones)
                    ->orWhere(function ($plateQuery) use ($plates): void {
                        $plateQuery->where('role', UserRole::Rider->value)
                            ->whereIn('plate_number', $plates);
                    });
            })
            ->get()
            ->each(function (User $user) use ($emails, $phones, $plates, &$conflicts): void {
                if (in_array($user->email, $emails, true)) {
                    $conflicts[] = "email {$user->email}";
                }

                if (
                    in_array($user->phone, $phones, true)
                    || in_array($user->contact_number, $phones, true)
                ) {
                    $conflicts[] = 'phone '.($user->phone ?: $user->contact_number);
                }

                if (
                    $user->role === UserRole::Rider->value
                    && in_array($user->plate_number, $plates, true)
                ) {
                    $conflicts[] = "User plate {$user->plate_number}";
                }
            });

        RiderProfile::withTrashed()
            ->whereIn('plate_number', $plates)
            ->get()
            ->each(function (RiderProfile $profile) use (&$conflicts): void {
                $conflicts[] = "Rider profile plate {$profile->plate_number}";
            });

        AccountApplication::query()
            ->whereIn('application_no', $applicationNumbers)
            ->get()
            ->each(function (AccountApplication $application) use (&$conflicts): void {
                $conflicts[] = "application {$application->application_no}";
            });

        if ($conflicts !== []) {
            throw new RuntimeException(
                'Reserved Local Rider demo identities are already in use: '
                .implode('; ', array_unique($conflicts))
                .'. No accounts were written.'
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $created
     * @param  list<string>  $skipped
     */
    private function reportResult(array $created, array $skipped): void
    {
        if ($created !== []) {
            $this->command?->info(
                'Seeded '.count($created).' Local Rider demo account(s) for Pila and Victoria, Laguna.'
            );
            $this->command?->line('Password for the new demo accounts: '.self::PASSWORD);
        } else {
            $this->command?->info('No new Local Rider demo accounts were needed.');
        }

        if ($skipped !== []) {
            $this->command?->line(
                'Skipped '.count($skipped).' barangay(s) that already have a Rider: '.implode(', ', $skipped).'.'
            );
        }

        $this->command?->warn(
            'Local/testing demo fixtures only; no identity documents were reviewed.'
        );
    }
}
