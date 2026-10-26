<?php

namespace Database\Seeders;

use Illuminate\Database\Connection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Creates stable, disposable accounts for local authenticated browser smoke tests.
 *
 * This seeder deliberately uses only the validated SQLite connection's query
 * builder. It does not fire model events, dispatch jobs, send mail, or write files.
 */
class DisposableWebSmokeFixtureSeeder extends Seeder
{
    private const FIXTURE_TIMESTAMP = '2026-10-01 00:00:00';

    private const CITY = 'City of Santa Rosa';

    private const PROFILE_CITY = 'Santa Rosa';

    private const LEGACY_CITY = 'Santa Rosa';

    private const PROVINCE = 'Laguna';

    private const CITY_CODE = '0403428000';

    private const PROVINCE_CODE = '0403400000';

    private const LEGACY_CITY_CODE = '0403424000';

    private const LEGACY_PROVINCE_CODE = '0434';

    public function run(): void
    {
        [$connectionName, $databasePath, $password] = $this->validatedTarget();
        $connection = DB::connection($connectionName);
        $this->assertConnectedTarget($connection, $databasePath);

        $connection->transaction(function () use ($connection, $password): void {
            $this->seedFixtures($connection, $password);
        });
    }

    /** @return array{string, string, string} */
    private function validatedTarget(): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Disposable Web smoke fixtures are allowed only in local or testing environments.');
        }

        if (getenv('BEARLY_DISPOSABLE_SMOKE') !== '1') {
            throw new RuntimeException('Set BEARLY_DISPOSABLE_SMOKE=1 in the current process to opt in.');
        }

        $password = getenv('BEARLY_DISPOSABLE_SMOKE_PASSWORD');
        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('A process-level BEARLY_DISPOSABLE_SMOKE_PASSWORD of at least 12 characters is required.');
        }

        $connectionName = config('database.default');
        if ($connectionName !== 'sqlite' || DB::getDefaultConnection() !== 'sqlite') {
            throw new RuntimeException('The resolved Laravel default connection must be sqlite.');
        }

        if ($this->hasDatabaseUrlOverride()) {
            throw new RuntimeException('Database URL overrides are not allowed for disposable Web smoke fixtures.');
        }

        $sqlite = config('database.connections.sqlite');
        if (! is_array($sqlite)
            || ($sqlite['driver'] ?? null) !== 'sqlite'
            || filled($sqlite['url'] ?? null)
            || filled(config('database.url'))) {
            throw new RuntimeException('The resolved SQLite connection has an ambiguous driver or URL override.');
        }

        if (array_key_exists('read', $sqlite) || array_key_exists('write', $sqlite)) {
            throw new RuntimeException('Split SQLite read/write connections are not allowed for disposable fixtures.');
        }

        $database = $sqlite['database'] ?? null;
        if (! is_string($database) || trim($database) === '' || $database === ':memory:' || str_starts_with(strtolower($database), 'file:')) {
            throw new RuntimeException('The SQLite database must be an existing disposable file, not memory or a URI.');
        }

        $processConnection = getenv('DB_CONNECTION');
        if (is_string($processConnection) && trim($processConnection) !== '' && $processConnection !== 'sqlite') {
            throw new RuntimeException('The process DB_CONNECTION conflicts with the resolved SQLite connection.');
        }

        $databasePath = realpath($database);
        if ($databasePath === false || ! is_file($databasePath)) {
            throw new RuntimeException('The configured SQLite database file must already exist.');
        }

        $processDatabase = getenv('DB_DATABASE');
        if (is_string($processDatabase) && trim($processDatabase) !== '') {
            $processDatabasePath = realpath($processDatabase);
            if ($processDatabasePath === false || ! $this->samePath($processDatabasePath, $databasePath)) {
                throw new RuntimeException('The process DB_DATABASE conflicts with the resolved SQLite file.');
            }
        }

        $temp = getenv('TEMP');
        $tempPath = is_string($temp) && trim($temp) !== '' ? realpath($temp) : false;
        if ($tempPath === false || ! is_dir($tempPath)) {
            throw new RuntimeException('Windows TEMP must resolve to an existing directory.');
        }

        $databaseDirectory = realpath(dirname($databasePath));
        if ($databaseDirectory === false
            || ! $this->samePath(dirname($databaseDirectory), $tempPath)
            || strtolower(basename($databasePath)) !== 'database.sqlite'
            || ! preg_match('/^bearly-web-ready-[A-Za-z0-9][A-Za-z0-9_-]{15,63}$/', basename($databaseDirectory))) {
            throw new RuntimeException('SQLite must be database.sqlite in a uniquely named bearly-web-ready-* directory directly under Windows TEMP.');
        }

        return [$connectionName, $databasePath, $password];
    }

    private function hasDatabaseUrlOverride(): bool
    {
        foreach (['DB_URL', 'DATABASE_URL'] as $key) {
            foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null] as $value) {
                if (is_string($value) && trim($value) !== '') {
                    return true;
                }
            }
        }

        $connections = config('database.connections');
        if (! is_array($connections) || $connections === []) {
            return true;
        }

        foreach ($connections as $connection) {
            if (is_array($connection) && filled($connection['url'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function assertConnectedTarget(Connection $connection, string $expectedPath): void
    {
        if ($connection->getDriverName() !== 'sqlite'
            || $connection->getConfig('driver') !== 'sqlite'
            || filled($connection->getConfig('url'))
            || ! ($connection->getPdo() instanceof \PDO)) {
            throw new RuntimeException('Laravel did not open the expected SQLite connection.');
        }

        $pdo = $connection->getPdo();
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new RuntimeException('The connected PDO driver is not SQLite.');
        }

        $databases = $connection->select('PRAGMA database_list');
        $mainDatabases = array_values(array_filter($databases, fn (object $database): bool => ($database->name ?? null) === 'main'));
        $otherDatabases = array_values(array_filter($databases, fn (object $database): bool => ($database->name ?? null) !== 'main'));
        if (count($mainDatabases) !== 1
            || count($otherDatabases) > 1
            || (count($otherDatabases) === 1 && (($otherDatabases[0]->name ?? null) !== 'temp' || filled($otherDatabases[0]->file ?? null)))
            || ! is_string($mainDatabases[0]->file ?? null)
            || ! $this->samePath((string) realpath($mainDatabases[0]->file), $expectedPath)) {
            throw new RuntimeException('The SQLite connection opened an unexpected or attached database.');
        }
    }

    private function seedFixtures(Connection $db, string $password): void
    {
        $roles = [];
        foreach (['buyer', 'seller', 'logistics', 'rider'] as $roleName) {
            $role = $db->table('roles')->where('name', $roleName)->first();
            if (! $role) {
                throw new RuntimeException("Required base role [{$roleName}] is missing; run DatabaseSeeder on the disposable database first.");
            }
            $roles[$roleName] = $role;
        }

        $category = $db->table('categories')->where('slug', 'electronics-and-gadgets')->first();
        if (! $category) {
            throw new RuntimeException('The base Electronics and Gadgets business category is missing; run DatabaseSeeder on the disposable database first.');
        }

        $buyer = $this->ensureUser($db, 'buyer.web-smoke@bearly.test', 'Web Smoke Buyer', 'buyer', $password);
        $seller = $this->ensureUser($db, 'seller.web-smoke@bearly.test', 'Web Smoke Seller', 'seller', $password);
        $logistics = $this->ensureUser($db, 'logistics.web-smoke@bearly.test', 'Web Smoke Logistics', 'logistics', $password);
        $pickupRider = $this->ensureUser($db, 'rider-pickup.web-smoke@bearly.test', 'Web Smoke Pickup Rider', 'rider', $password, $logistics->id);
        $deliveryRider = $this->ensureUser($db, 'rider-delivery.web-smoke@bearly.test', 'Web Smoke Delivery Rider', 'rider', $password, $logistics->id);

        foreach ([$buyer, $seller, $logistics, $pickupRider, $deliveryRider] as $user) {
            $roleName = $user->role;
            $this->ensureRoleAssignment($db, (int) $user->id, (int) $roles[$roleName]->id);
        }

        $buyerAddress = $this->ensureAddress($db, $buyer, 'Buyer Home', true, false);
        $sellerAddress = $this->ensureAddress($db, $seller, 'Seller Pickup', false, true);
        $centerAddress = $this->ensureAddress($db, $logistics, 'Sorting Center', false, false);

        $sellerProfile = $this->ensureRow($db, 'seller_profiles', ['user_id' => $seller->id], [
            'legal_business_name' => 'Bearly Web Smoke Seller',
            'approved_category_id' => $category->id,
            'pickup_address_id' => $sellerAddress->id,
            'standing_status' => 'good_standing',
            'approved_at' => self::FIXTURE_TIMESTAMP,
        ]);
        $store = $this->ensureRow($db, 'stores', ['seller_profile_id' => $sellerProfile->id], [
            'name' => 'Bearly Web Smoke Store',
            'slug' => 'bearly-web-smoke-store',
            'contact_email' => 'seller.web-smoke@bearly.test',
            'contact_phone' => '+639171234567',
            'publication_status' => 'published',
            'published_at' => self::FIXTURE_TIMESTAMP,
        ]);
        $product = $this->ensureRow($db, 'products', [
            'store_id' => $store->id,
            'slug' => 'bearly-web-smoke-cod-product',
        ], [
            'category_id' => $category->id,
            'name' => 'Bearly Web Smoke USB Cable',
            'description' => 'Disposable local browser smoke-test product.',
            'product_status' => 'active',
            'compliance_status' => 'clear',
            'voucher_eligible' => 1,
            'payment_methods' => json_encode(['cod'], JSON_THROW_ON_ERROR),
            'published_at' => self::FIXTURE_TIMESTAMP,
        ]);
        $this->ensureRow($db, 'product_variants', ['sku' => 'BEARLY-WEB-SMOKE-001'], [
            'product_id' => $product->id,
            'name' => 'Regular / Blue',
            'options' => json_encode(['Size' => 'Regular', 'Color' => 'Blue'], JSON_THROW_ON_ERROR),
            'price_minor' => 19900,
            'stock_on_hand' => 5,
            'stock_reserved' => 0,
            'package_weight_kg' => 0.5,
            'package_length_cm' => 20,
            'package_width_cm' => 15,
            'package_height_cm' => 10,
            'is_active' => 1,
        ]);

        $logisticsProfile = $this->ensureRow($db, 'logistics_profiles', ['user_id' => $logistics->id], [
            'legal_name' => 'Bearly Web Smoke Logistics',
            'display_name' => 'Bearly Web Smoke Logistics',
            'contact_phone' => '+639171234568',
            'psgc_city_code' => self::CITY_CODE,
            'status' => 'active',
        ], true, [], ['psgc_city_code' => self::LEGACY_CITY_CODE]);
        $center = $this->ensureRow($db, 'sorting_centers', ['code' => 'BWS-CENTER-001'], [
            'logistics_profile_id' => $logisticsProfile->id,
            'address_id' => $centerAddress->id,
            'name' => 'Bearly Web Smoke Sorting Center',
            'contact_phone' => '+639171234568',
            'status' => 'active',
        ]);
        $zone = $this->ensureRow($db, 'sorting_zones', [
            'sorting_center_id' => $center->id,
            'code' => 'BWS-ZONE-001',
        ], [
            'name' => 'Santa Rosa Balibago Smoke Zone',
            'destination_rules' => json_encode([
                'province' => self::PROVINCE,
                'city_municipality' => self::CITY,
                'psgc_city_code' => self::CITY_CODE,
                'barangays' => ['Balibago'],
            ], JSON_THROW_ON_ERROR),
            'status' => 'active',
        ], true, [], ['destination_rules' => $this->legacyZoneRules()]);

        foreach ([[$pickupRider, 'BWS-PICKUP-001'], [$deliveryRider, 'BWS-DELIVERY-001']] as [$rider, $plate]) {
            $this->ensureRow($db, 'rider_profiles', ['user_id' => $rider->id], [
                'logistics_profile_id' => $logisticsProfile->id,
                'home_sorting_center_id' => $center->id,
                'current_zone_id' => $zone->id,
                'vehicle_type' => 'motorcycle',
                'plate_number' => $plate,
                'parcel_capacity' => 10,
                'availability_status' => 'available',
                'verification_status' => 'approved',
            ]);
        }
    }

    private function ensureUser(Connection $db, string $email, string $name, string $role, string $password, ?int $logisticsId = null): object
    {
        [$firstName, $lastName] = array_pad(explode(' ', $name, 2), 2, 'Smoke');
        $phone = match ($email) {
            'buyer.web-smoke@bearly.test' => '+639171234570',
            'seller.web-smoke@bearly.test' => '+639171234571',
            'logistics.web-smoke@bearly.test' => '+639171234572',
            'rider-pickup.web-smoke@bearly.test' => '+639171234573',
            'rider-delivery.web-smoke@bearly.test' => '+639171234574',
            default => throw new RuntimeException('Unknown disposable Web smoke user identifier.'),
        };
        $existing = $db->table('users')->where('email', $email)->first();
        if ($existing && ($existing->role ?? null) !== $role) {
            throw new RuntimeException("Disposable fixture email [{$email}] is already associated with a different role.");
        }

        return $this->ensureRow($db, 'users', ['email' => $email], [
            'name' => $name,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'role' => $role,
            'status' => 'active',
            'phone' => $phone,
            'contact_number' => $phone,
            'province' => self::PROVINCE,
            'city' => self::PROFILE_CITY,
            'barangay' => 'Balibago',
            'street_address' => '10 Bearly Web Smoke Road',
            'email_verified_at' => self::FIXTURE_TIMESTAMP,
            'logistics_id' => $logisticsId,
        ], true, ['password' => Hash::make($password)]);
    }

    private function ensureAddress(Connection $db, object $user, string $label, bool $shipping, bool $pickup): object
    {
        return $this->ensureRow($db, 'addresses', [
            'user_id' => $user->id,
            'label' => $label,
        ], [
            'recipient_name' => $user->name,
            'phone' => $user->phone,
            'street' => '10 Bearly Web Smoke Road',
            'barangay' => 'Balibago',
            'city_municipality' => self::CITY,
            'province' => self::PROVINCE,
            'postal_code' => '4026',
            'psgc_province_code' => self::PROVINCE_CODE,
            'psgc_city_code' => self::CITY_CODE,
            'is_default_shipping' => $shipping ? 1 : 0,
            'is_default_pickup' => $pickup ? 1 : 0,
        ], true, [], [
            'city_municipality' => self::LEGACY_CITY,
            'psgc_province_code' => self::LEGACY_PROVINCE_CODE,
            'psgc_city_code' => self::LEGACY_CITY_CODE,
        ]);
    }

    /**
     * Create a fixture role pivot once, then preserve its legitimate assignment
     * timestamp. Successful login updates assigned_at as historical activity.
     */
    private function ensureRoleAssignment(Connection $db, int $userId, int $roleId): object
    {
        $assignments = $db->table('role_user')->where('user_id', $userId)->get();
        foreach ($assignments as $assignment) {
            if (! $this->sameValue($assignment->role_id ?? null, $roleId)) {
                throw new RuntimeException('A disposable fixture user has an unexpected role assignment; no data was overwritten.');
            }
        }

        $identity = ['user_id' => $userId, 'role_id' => $roleId];
        $row = $db->table('role_user')->where($identity)->first();
        if ($row) {
            if (! $this->sameValue($row->user_id ?? null, $userId)
                || ! $this->sameValue($row->role_id ?? null, $roleId)) {
                throw new RuntimeException('An existing disposable role assignment has an unexpected user or role ID.');
            }

            if (($row->assigned_by ?? null) !== null) {
                throw new RuntimeException('An existing disposable role assignment has an unexpected [assigned_by] value; no data was overwritten.');
            }

            if (! $this->isValidAssignmentTimestamp($row->assigned_at ?? null)) {
                throw new RuntimeException('An existing disposable role assignment has an invalid [assigned_at] value; no data was overwritten.');
            }

            return $row;
        }

        $db->table('role_user')->insert($identity + [
            'assigned_by' => null,
            'assigned_at' => self::FIXTURE_TIMESTAMP,
        ]);
        $row = $db->table('role_user')->where($identity)->first();
        if (! $row
            || ! $this->sameValue($row->user_id ?? null, $userId)
            || ! $this->sameValue($row->role_id ?? null, $roleId)
            || ($row->assigned_by ?? null) !== null
            || ($row->assigned_at ?? null) !== self::FIXTURE_TIMESTAMP) {
            throw new RuntimeException('Unable to create disposable fixture role assignment.');
        }

        return $row;
    }

    private function isValidAssignmentTimestamp(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return false;
        }

        $timestamp = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $timestamp !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $timestamp->format('Y-m-d H:i:s') === $value;
    }

    private function legacyZoneRules(): string
    {
        return json_encode([
            'province' => self::PROVINCE,
            'city_municipality' => self::LEGACY_CITY,
            'psgc_city_code' => self::LEGACY_CITY_CODE,
            'barangays' => ['Balibago'],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Insert fixture rows once; reruns verify them and only repair exact known
     * legacy location values before related operational records exist.
     * Password is a create-only value and is deliberately not reset on reruns.
     *
     * @param array<string, int|string|null> $identity
     * @param array<string, int|string|null> $expected
     * @param array<string, int|string|null> $createOnly
     * @param array<string, int|string|null> $repairable Known values from the original disposable fixture only.
     */
    private function ensureRow(
        Connection $db,
        string $table,
        array $identity,
        array $expected,
        bool $timestamps = true,
        array $createOnly = [],
        array $repairable = [],
    ): object {
        $query = $db->table($table)->where($identity);
        $row = $query->first();
        if ($row) {
            if (property_exists($row, 'deleted_at') && $row->deleted_at !== null) {
                throw new RuntimeException("A soft-deleted disposable fixture row exists in [{$table}]; it will not be revived.");
            }

            $repairs = [];
            foreach ($expected as $column => $value) {
                if (! $this->sameValue($row->{$column} ?? null, $value)) {
                    if (! array_key_exists($column, $repairable)
                        || ! $this->sameValue($row->{$column} ?? null, $repairable[$column])) {
                        throw new RuntimeException("An existing disposable fixture row in [{$table}] has an unexpected [{$column}] value; no data was overwritten.");
                    }

                    $repairs[$column] = $value;
                }
            }

            if ($repairs !== []) {
                $this->assertLocationRepairIsUnused($db, $table, $row);
                $query->where('id', $row->id)->update(array_merge($repairs, [
                    'updated_at' => self::FIXTURE_TIMESTAMP,
                ]));

                return $db->table($table)->where($identity)->first();
            }

            return $row;
        }

        $values = array_merge($identity, $expected, $createOnly);
        if ($timestamps) {
            $values['created_at'] = self::FIXTURE_TIMESTAMP;
            $values['updated_at'] = self::FIXTURE_TIMESTAMP;
        }
        if (in_array($table, ['addresses', 'users', 'seller_profiles', 'stores', 'products', 'product_variants', 'logistics_profiles', 'sorting_centers', 'sorting_zones', 'rider_profiles'], true)) {
            $values['deleted_at'] = null;
        }

        $db->table($table)->insert($values);
        $row = $db->table($table)->where($identity)->first();
        if (! $row) {
            throw new RuntimeException("Unable to create disposable fixture row in [{$table}].");
        }

        return $row;
    }

    private function assertLocationRepairIsUnused(Connection $db, string $table, object $row): void
    {
        $inUse = match ($table) {
            'addresses' => $this->addressHasOperationalUse($db, $row),
            'logistics_profiles' => $db->table('shipments')->where('logistics_profile_id', $row->id)->exists()
                || $db->table('shipping_settlements')->where('logistics_profile_id', $row->id)->exists()
                || $db->table('rider_earning_payouts')->where('logistics_profile_id', $row->id)->exists()
                || $db->table('pickup_requests')->where('logistics_profile_id', $row->id)->exists()
                || $db->table('dispatch_batches')
                    ->join('sorting_centers', 'dispatch_batches.sorting_center_id', '=', 'sorting_centers.id')
                    ->where('sorting_centers.logistics_profile_id', $row->id)
                    ->exists(),
            'sorting_zones' => $db->table('parcels')->where('current_zone_id', $row->id)->exists()
                || $db->table('dispatch_batches')->where('sorting_zone_id', $row->id)->exists(),
            default => true,
        };

        if ($inUse) {
            throw new RuntimeException("The known legacy location in [{$table}] cannot be repaired after related operational records exist.");
        }
    }

    private function addressHasOperationalUse(Connection $db, object $address): bool
    {
        $addressId = (int) $address->id;
        $userId = (int) $address->user_id;

        return $db->table('orders')
                ->where('buyer_id', $userId)
                ->orWhere('shipping_address_id', $addressId)
                ->exists()
            || $db->table('seller_orders')
                ->join('stores', 'seller_orders.store_id', '=', 'stores.id')
                ->join('seller_profiles', 'stores.seller_profile_id', '=', 'seller_profiles.id')
                ->where('seller_profiles.user_id', $userId)
                ->exists()
            || $db->table('shipments')
                ->where('origin_address_id', $addressId)
                ->orWhere('destination_address_id', $addressId)
                ->exists()
            || $db->table('sorting_centers')
                ->join('dispatch_batches', 'dispatch_batches.sorting_center_id', '=', 'sorting_centers.id')
                ->where('sorting_centers.address_id', $addressId)
                ->exists()
            || $db->table('sorting_centers')
                ->join('shipments', 'shipments.logistics_profile_id', '=', 'sorting_centers.logistics_profile_id')
                ->where('sorting_centers.address_id', $addressId)
                ->exists()
            || $db->table('pickup_requests')->where('pickup_address_id', $addressId)->exists();
    }

    private function sameValue(mixed $actual, mixed $expected): bool
    {
        if ($actual === $expected) {
            return true;
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return (string) (0 + $actual) === (string) (0 + $expected);
        }

        return $actual !== null && $expected !== null && (string) $actual === (string) $expected;
    }

    private function samePath(string $left, string $right): bool
    {
        $left = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $left), DIRECTORY_SEPARATOR);
        $right = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $right), DIRECTORY_SEPARATOR);

        return PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($left, $right) === 0
            : $left === $right;
    }
}
