<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\Address;
use App\Models\LogisticsProfile;
use App\Models\Role;
use App\Models\SortingCenter;
use App\Models\User;
use App\Services\PostalCodeLookup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Seeds one deterministic active Logistics demo provider for every Laguna
 * city/municipality except Pila and Victoria.
 *
 * These are local/testing fixtures only. No government ID or business permit
 * is reviewed or recorded, and the accounts are approved directly so they can
 * act as local test providers. The seeder is intentionally not called by
 * DatabaseSeeder.
 *
 * Manual local/testing use:
 * php artisan db:seed --class=LagunaLogisticsDemoSeeder
 */
class LagunaLogisticsDemoSeeder extends Seeder
{
    protected const PASSWORD = 'Password123';

    protected const REGION = 'Region IV-A (CALABARZON)';

    protected const REGION_CODE = '0400000000';

    protected const PROVINCE = 'Laguna';

    protected const PROVINCE_CODE = '0403400000';

    /**
     * Pila and Victoria already have local providers in the existing demo
     * data and must never be created, relocated, or updated by this seeder.
     */
    protected const EXCLUDED_CITY_CODES = [
        '0403422000',
        '0403430000',
    ];

    /**
     * Official Laguna city/municipality PSGC codes from the local
     * philippine-postal-codes snapshot, excluding Pila and Victoria.
     *
     * @var list<string>
     */
    protected const TARGET_CITY_CODES = [
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

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                class_basename(static::class).' may only run in local or testing environments.'
            );
        }

        $targetCities = $this->resolveTargetCities();
        $occupiedCities = $this->occupiedCityCodes(array_keys($targetCities));

        $fixtures = [];
        $skippedCities = [];

        foreach ($targetCities as $cityCode => $city) {
            if (isset($occupiedCities[$cityCode])) {
                $skippedCities[] = $city['city'];

                continue;
            }

            $fixtures[] = $this->logisticsFixture($cityCode, $city);
        }

        if ($fixtures === []) {
            $this->reportResult([], $skippedCities);

            return;
        }

        $admin = $this->activeAdminReviewer();

        // Check every reserved identity before opening the write transaction so
        // a collision cannot leave only part of Laguna seeded.
        $this->assertIdentitiesAvailable($fixtures);

        DB::transaction(function () use ($fixtures, $admin): void {
            $logisticsRole = Role::query()->firstOrCreate(
                ['name' => UserRole::Logistics->value],
                ['display_name' => 'Logistics'],
            );

            $now = now();

            foreach ($fixtures as $fixture) {
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
                    'role' => UserRole::Logistics->value,
                    'status' => AccountStatus::Active->value,
                    'region' => self::REGION,
                    'region_code' => self::REGION_CODE,
                    'province' => self::PROVINCE,
                    'city' => $fixture['city'],
                    'barangay' => 'Poblacion',
                    'street_address' => $fixture['street'],
                    'business_name' => $fixture['name'],
                    'approved_by' => $admin->id,
                    'approved_at' => $now,
                    'terms_accepted_at' => $now,
                    'terms_version' => config('bearly-policies.version'),
                    'privacy_version' => config('bearly-policies.version'),
                    'password' => Hash::make(self::PASSWORD),
                ]);

                $user->roles()->syncWithoutDetaching([
                    $logisticsRole->id => [
                        'assigned_by' => $admin->id,
                        'assigned_at' => $now,
                    ],
                ]);

                $address = Address::query()->create([
                    'user_id' => $user->id,
                    'label' => 'Sorting center',
                    'recipient_name' => $fixture['name'],
                    'phone' => $fixture['phone'],
                    'house_number' => '1',
                    'street' => $fixture['street'],
                    'barangay' => 'Poblacion',
                    'region' => self::REGION,
                    'city_municipality' => $fixture['city'],
                    'province' => self::PROVINCE,
                    'postal_code' => $fixture['postal_code'],
                    'psgc_region_code' => self::REGION_CODE,
                    'psgc_province_code' => self::PROVINCE_CODE,
                    'psgc_city_code' => $fixture['city_code'],
                    'psgc_barangay_code' => null,
                    'is_default_shipping' => false,
                    'is_default_pickup' => true,
                ]);

                $application = AccountApplication::query()->create([
                    'application_no' => $fixture['application_no'],
                    'user_id' => $user->id,
                    'requested_role_id' => $logisticsRole->id,
                    'business_name' => $fixture['name'],
                    'status' => 'approved',
                    'submitted_at' => $now,
                    'review_started_at' => $now,
                    'decided_at' => $now,
                    'reviewed_by' => $admin->id,
                    'decision_reason' => null,
                    'revision_notes' => 'Local/testing demo fixture only; no government ID or business permit was reviewed and no documents were uploaded.',
                ]);

                $profile = LogisticsProfile::query()->create([
                    'user_id' => $user->id,
                    'application_id' => $application->id,
                    'legal_name' => $fixture['name'],
                    'display_name' => $fixture['name'],
                    'contact_phone' => $fixture['phone'],
                    'psgc_city_code' => $fixture['city_code'],
                    'status' => 'active',
                ]);

                SortingCenter::query()->create([
                    'logistics_profile_id' => $profile->id,
                    'address_id' => $address->id,
                    'name' => $fixture['name'],
                    'code' => 'SC-'.$profile->id,
                    'contact_phone' => $fixture['phone'],
                    'status' => 'active',
                ]);
            }
        });

        $this->reportResult($fixtures, $skippedCities);
    }

    /**
     * @return list<string>
     */
    protected function targetCityCodes(): array
    {
        return self::TARGET_CITY_CODES;
    }

    /**
     * Resolve and validate every target city from the local snapshot before any
     * database write. The seeder never calls a live network service.
     *
     * @return array<string, array{city: string, postal_code: string|null}>
     */
    private function resolveTargetCities(): array
    {
        $lookup = app(PostalCodeLookup::class);
        $cityCodes = $this->targetCityCodes();

        if (count($cityCodes) !== count(array_unique($cityCodes))) {
            throw new RuntimeException(
                'Laguna Logistics demo target city codes must be unique. No accounts were written.'
            );
        }

        $cities = [];

        foreach ($cityCodes as $cityCode) {
            $cityCode = is_string($cityCode) ? trim($cityCode) : '';

            if (! preg_match('/^\d{10}$/D', $cityCode)) {
                throw new RuntimeException(
                    'Laguna Logistics demo targets must be valid 10-digit PSGC city/municipality codes. No accounts were written.'
                );
            }

            if (in_array($cityCode, self::EXCLUDED_CITY_CODES, true)) {
                throw new RuntimeException(
                    "Laguna Logistics demo target {$cityCode} is excluded because Pila and Victoria must remain untouched. No accounts were written."
                );
            }

            $place = $lookup->findByCanonicalCityCode($cityCode);

            if ($place === null || trim((string) ($place['province'] ?? '')) !== self::PROVINCE) {
                throw new RuntimeException(
                    "Laguna Logistics demo target {$cityCode} does not resolve to ".self::PROVINCE.' in the local postal-code snapshot. No accounts were written.'
                );
            }

            $city = trim((string) ($place['city'] ?? ''));

            if ($city === '') {
                throw new RuntimeException(
                    "Laguna Logistics demo target {$cityCode} has no city/municipality label in the local postal-code snapshot. No accounts were written."
                );
            }

            $cities[$cityCode] = [
                'city' => $city,
                'postal_code' => $this->postalCodeFor($place),
            ];
        }

        return $cities;
    }

    /**
     * Use the first configured snapshot postal code for the city, or leave the
     * address postal code empty when the snapshot has no configured option.
     *
     * @param  array{province?: string, city?: string, options?: array<int, array{area?: string, code?: string}>}  $place
     */
    private function postalCodeFor(array $place): ?string
    {
        foreach ($place['options'] ?? [] as $option) {
            $postalCode = trim((string) ($option['code'] ?? ''));

            if ($postalCode !== '') {
                return $postalCode;
            }
        }

        return null;
    }

    /**
     * A target city is occupied by any persisted Logistics city ownership,
     * including trashed profiles, legacy service addresses, pending applicants,
     * and applications that have no profile yet.
     *
     * @param  list<string>  $targetCityCodes
     * @return array<string, bool>
     */
    private function occupiedCityCodes(array $targetCityCodes): array
    {
        $targets = array_fill_keys($targetCityCodes, true);
        $occupied = [];
        $lookup = app(PostalCodeLookup::class);

        $profiles = LogisticsProfile::withTrashed()
            ->get(['id', 'user_id', 'psgc_city_code']);

        foreach ($profiles as $profile) {
            $cityCode = trim((string) $profile->psgc_city_code);

            if (isset($targets[$cityCode])) {
                $occupied[$cityCode] = true;
            }
        }

        $profileUserIds = $profiles
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $candidates = User::withTrashed()
            ->where(function ($query) use ($profileUserIds): void {
                $query->where('role', UserRole::Logistics->value)
                    ->orWhereHas('roles', fn ($roles) => $roles->where('name', UserRole::Logistics->value))
                    ->orWhereHas('applications', fn ($applications) => $applications
                        ->whereHas('requestedRole', fn ($role) => $role->where('name', UserRole::Logistics->value)));

                if ($profileUserIds !== []) {
                    $query->orWhereIn('id', $profileUserIds);
                }
            })
            ->with(['addresses' => fn ($addresses) => $addresses->withTrashed()])
            ->get();

        foreach ($candidates as $candidate) {
            foreach ($candidate->addresses as $address) {
                if (! $this->isRegistrationAddress($address)) {
                    continue;
                }

                $cityCode = $this->cityCodeForAddress($address, $lookup);

                if ($cityCode !== null && isset($targets[$cityCode])) {
                    $occupied[$cityCode] = true;
                }
            }

            // Legacy accounts may have only the users table location fields.
            // Both the verified province and city labels are required.
            $legacyCityCode = $lookup->canonicalCityCode(
                null,
                (string) $candidate->province,
                (string) $candidate->city,
            );

            if ($legacyCityCode !== null && isset($targets[$legacyCityCode])) {
                $occupied[$legacyCityCode] = true;
            }
        }

        return $occupied;
    }

    private function isRegistrationAddress(Address $address): bool
    {
        return strtolower(trim((string) $address->label)) === 'sorting center'
            || (bool) $address->is_default_pickup;
    }

    private function cityCodeForAddress(Address $address, PostalCodeLookup $lookup): ?string
    {
        $cityCode = trim((string) $address->psgc_city_code);

        if ($cityCode !== '') {
            return $cityCode;
        }

        // Legacy rows without a PSGC code fall back to their verified
        // province + city/municipality labels; a city name alone is never enough.
        return $lookup->canonicalCityCode(
            null,
            (string) $address->province,
            (string) $address->city_municipality,
        );
    }

    /**
     * @param  array{city: string, postal_code: string|null}  $city
     * @return array{
     *     city: string,
     *     city_code: string,
     *     postal_code: string|null,
     *     name: string,
     *     first_name: string,
     *     last_name: string,
     *     email: string,
     *     phone: string,
     *     application_no: string,
     *     street: string
     * }
     */
    private function logisticsFixture(string $cityCode, array $city): array
    {
        $name = "{$city['city']} Demo Logistics";

        return [
            'city' => $city['city'],
            'city_code' => $cityCode,
            'postal_code' => $city['postal_code'],
            'name' => $name,
            'first_name' => "{$city['city']} Demo",
            'last_name' => 'Logistics',
            'email' => "logistics.demo.{$cityCode}@bearly.test",
            'phone' => '+63920'.substr($cityCode, -7),
            'application_no' => "DEMO-LOGISTICS-{$cityCode}",
            'street' => "1 {$city['city']} Demo Logistics Service Road",
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fixtures
     */
    private function assertIdentitiesAvailable(array $fixtures): void
    {
        $emails = array_column($fixtures, 'email');
        $phones = array_column($fixtures, 'phone');
        $applicationNumbers = array_column($fixtures, 'application_no');

        if (
            count($emails) !== count(array_unique($emails))
            || count($phones) !== count(array_unique($phones))
            || count($applicationNumbers) !== count(array_unique($applicationNumbers))
        ) {
            throw new RuntimeException(
                'Laguna Logistics demo identities are not deterministic and unique. No accounts were written.'
            );
        }

        $conflicts = [];

        User::withTrashed()
            ->where(function ($query) use ($emails, $phones): void {
                $query->whereIn('email', $emails)
                    ->orWhereIn('phone', $phones)
                    ->orWhereIn('contact_number', $phones);
            })
            ->get()
            ->each(function (User $user) use ($emails, $phones, &$conflicts): void {
                if (in_array($user->email, $emails, true)) {
                    $conflicts[] = "email {$user->email}";
                }

                if (
                    in_array($user->phone, $phones, true)
                    || in_array($user->contact_number, $phones, true)
                ) {
                    $conflicts[] = 'phone '.($user->phone ?: $user->contact_number);
                }
            });

        AccountApplication::query()
            ->whereIn('application_no', $applicationNumbers)
            ->get()
            ->each(function (AccountApplication $application) use (&$conflicts): void {
                $conflicts[] = "application {$application->application_no}";
            });

        if ($conflicts !== []) {
            throw new RuntimeException(
                'Reserved Laguna Logistics demo identities are already in use: '
                .implode('; ', array_unique($conflicts))
                .'. No accounts were written.'
            );
        }
    }

    private function activeAdminReviewer(): User
    {
        $admin = User::query()
            ->where('status', AccountStatus::Active->value)
            ->where(function ($query): void {
                $query->where('role', UserRole::Admin->value)
                    ->orWhereHas('roles', fn ($roles) => $roles->where('name', UserRole::Admin->value));
            })
            ->orderBy('id')
            ->first();

        if (! $admin) {
            throw new RuntimeException(
                'Laguna Logistics demo seeding requires an existing active Admin account to record demo approval metadata. No accounts were written.'
            );
        }

        return $admin;
    }

    /**
     * @param  list<array<string, mixed>>  $created
     * @param  list<string>  $skipped
     */
    private function reportResult(array $created, array $skipped): void
    {
        if ($created !== []) {
            $this->command?->info(
                'Seeded '.count($created).' active Logistics demo provider(s) for Laguna city/municipalities except Pila and Victoria.'
            );
            $this->command?->line('Password for the new demo accounts: '.self::PASSWORD);
        } else {
            $this->command?->info('No new Laguna Logistics demo providers were needed.');
        }

        if ($skipped !== []) {
            $this->command?->line(
                'Skipped '.count($skipped).' city/municipality(s) that already have a Logistics account or application: '.implode(', ', $skipped).'.'
            );
        }

        $this->command?->warn(
            'Local/testing demo fixtures only; no government ID or business permit was reviewed, and no documents were recorded.'
        );
    }
}
