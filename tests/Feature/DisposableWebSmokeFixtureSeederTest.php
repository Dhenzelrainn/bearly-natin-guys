<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PostalCodeLookup;
use App\Services\RegistrationLifecycleService;
use App\Services\SortingZoneMatcher;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DisposableWebSmokeFixtureSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class DisposableWebSmokeFixtureSeederTest extends TestCase
{
    private const PASSWORD = 'DisposableSmokePassword123!';

    private const CITY = 'City of Santa Rosa';

    private const CITY_CODE = '0403428000';

    private const PROVINCE_CODE = '0403400000';

    private const LEGACY_CITY = 'Santa Rosa';

    private const LEGACY_CITY_CODE = '0403424000';

    private const LEGACY_PROVINCE_CODE = '0434';

    private ?string $fixtureDirectory = null;

    private ?string $outsideDirectory = null;

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['BEARLY_DISPOSABLE_SMOKE', 'BEARLY_DISPOSABLE_SMOKE_PASSWORD', 'DB_URL', 'DATABASE_URL', 'DB_CONNECTION', 'DB_DATABASE'] as $key) {
            $this->originalEnvironment[$key] = getenv($key);
        }

        $temp = getenv('TEMP');
        if (! is_string($temp) || realpath($temp) === false) {
            $this->fail('The focused disposable seeder tests require Windows TEMP to resolve.');
        }

        $this->fixtureDirectory = $this->createDirectory($temp, 'bearly-web-ready-test-'.bin2hex(random_bytes(16)));
        touch($this->fixtureDirectory.DIRECTORY_SEPARATOR.'database.sqlite');
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        DB::purge('sqlite');

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironment($key, $value === false ? null : $value);
        }

        foreach ([$this->fixtureDirectory, $this->outsideDirectory] as $directory) {
            if ($directory !== null) {
                $this->removeCreatedDirectory($directory);
            }
        }

        parent::tearDown();
    }

    public function test_it_rejects_unsafe_configurations_before_opening_a_database_connection(): void
    {
        $this->useFixtureConfiguration();
        $this->setEnvironment('BEARLY_DISPOSABLE_SMOKE', null);
        $this->setEnvironment('BEARLY_DISPOSABLE_SMOKE_PASSWORD', self::PASSWORD);
        $this->assertRejectedWithoutConnecting('opt in');

        $this->setEnvironment('BEARLY_DISPOSABLE_SMOKE', '1');
        $this->setEnvironment('BEARLY_DISPOSABLE_SMOKE_PASSWORD', null);
        $this->assertRejectedWithoutConnecting('process-level BEARLY_DISPOSABLE_SMOKE_PASSWORD');
        $this->setEnvironment('BEARLY_DISPOSABLE_SMOKE_PASSWORD', self::PASSWORD);
        app()->detectEnvironment(fn (): string => 'staging');
        $this->assertRejectedWithoutConnecting('local or testing');
        app()->detectEnvironment(fn (): string => 'testing');

        config(['database.default' => 'mysql']);
        DB::setDefaultConnection('mysql');
        $this->assertRejectedWithoutConnecting('default connection');
        config(['database.default' => 'sqlite']);
        DB::setDefaultConnection('sqlite');

        config(['database.connections.sqlite.database' => ':memory:']);
        $this->assertRejectedWithoutConnecting('existing disposable file');
        config(['database.connections.sqlite.database' => $this->fixtureDirectory.DIRECTORY_SEPARATOR.'database.sqlite']);

        $this->setEnvironment('DB_URL', 'mysql://protected.example/shared');
        config(['database.connections.sqlite.url' => 'mysql://protected.example/shared']);
        $this->assertRejectedWithoutConnecting('URL overrides');
        $this->setEnvironment('DB_URL', '');
        config(['database.connections.sqlite.url' => '']);

        $temp = (string) getenv('TEMP');
        $this->outsideDirectory = $this->createDirectory($temp, 'bearly-not-disposable-'.bin2hex(random_bytes(16)));
        $outsideDatabase = $this->outsideDirectory.DIRECTORY_SEPARATOR.'database.sqlite';
        touch($outsideDatabase);
        $this->setEnvironment('DB_DATABASE', $outsideDatabase);
        config(['database.connections.sqlite.database' => $outsideDatabase]);
        $this->assertRejectedWithoutConnecting('uniquely named bearly-web-ready');
        $this->setEnvironment('DB_DATABASE', $this->fixtureDirectory.DIRECTORY_SEPARATOR.'database.sqlite');
        config(['database.connections.sqlite.database' => $this->fixtureDirectory.DIRECTORY_SEPARATOR.'database.sqlite']);
    }

    public function test_it_creates_the_minimal_cross_role_fixtures_idempotently_without_financial_records(): void
    {
        $this->prepareDisposableDatabase();

        $adminBefore = DB::connection('sqlite')->table('users')->where('role', 'admin')->orderBy('id')->get()->map(fn (object $user): array => (array) $user)->all();
        app(DisposableWebSmokeFixtureSeeder::class)->run();

        $db = DB::connection('sqlite');
        $fixtureEmails = [
            'buyer.web-smoke@bearly.test' => 'buyer',
            'seller.web-smoke@bearly.test' => 'seller',
            'logistics.web-smoke@bearly.test' => 'logistics',
            'rider-pickup.web-smoke@bearly.test' => 'rider',
            'rider-delivery.web-smoke@bearly.test' => 'rider',
        ];
        $this->assertCount(5, $fixtureEmails);
        foreach ($fixtureEmails as $email => $role) {
            $user = $db->table('users')->where('email', $email)->first();
            $this->assertNotNull($user);
            $this->assertSame($role, $user->role);
            $this->assertSame('active', $user->status);
            $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
            $roleId = $db->table('roles')->where('name', $role)->value('id');
            $assignment = $db->table('role_user')->where('user_id', $user->id)->where('role_id', $roleId)->first();
            $this->assertNotNull($assignment);
            $this->assertSame('2026-10-01 00:00:00', $assignment->assigned_at);
            $this->assertNull($assignment->assigned_by);
            $this->assertSame(1, $db->table('role_user')->where('user_id', $user->id)->where('role_id', $roleId)->count());
        }

        $buyer = $db->table('users')->where('email', 'buyer.web-smoke@bearly.test')->first();
        $buyerAddress = $db->table('addresses')->where('user_id', $buyer->id)->where('is_default_shipping', 1)->first();
        $this->assertNotNull($buyerAddress);
        $this->assertSame(self::CITY, $buyerAddress->city_municipality);
        $this->assertSame('Laguna', $buyerAddress->province);
        $this->assertSame(self::CITY_CODE, $buyerAddress->psgc_city_code);
        $this->assertSame(self::PROVINCE_CODE, $buyerAddress->psgc_province_code);
        $postalCodes = app(PostalCodeLookup::class);
        $this->assertSame(self::CITY_CODE, $postalCodes->canonicalCityCode(
            $buyerAddress->psgc_city_code,
            $buyerAddress->province,
            $buyerAddress->city_municipality,
        ));
        $canonicalCity = $postalCodes->findByCanonicalCityCode(self::CITY_CODE);
        $this->assertSame('Laguna', $canonicalCity['province']);
        $this->assertSame(self::CITY, $canonicalCity['city']);
        $this->assertSame('Balibago', $buyerAddress->barangay);
        $this->assertNull($buyerAddress->psgc_barangay_code, 'The current local reference data and address form do not provide a verifiable barangay PSGC code.');

        $seller = $db->table('users')->where('email', 'seller.web-smoke@bearly.test')->first();
        $sellerProfile = $db->table('seller_profiles')->where('user_id', $seller->id)->first();
        $this->assertSame('good_standing', $sellerProfile->standing_status);
        $this->assertNotNull($sellerProfile->approved_at);
        $this->assertSame(1, $db->table('addresses')->where('id', $sellerProfile->pickup_address_id)->where('user_id', $seller->id)->where('is_default_pickup', 1)->count());
        $sellerAddress = $db->table('addresses')->where('id', $sellerProfile->pickup_address_id)->first();
        $this->assertSame(self::CITY, $sellerAddress->city_municipality);
        $this->assertSame(self::CITY_CODE, $sellerAddress->psgc_city_code);
        $this->assertSame(self::PROVINCE_CODE, $sellerAddress->psgc_province_code);
        $store = $db->table('stores')->where('seller_profile_id', $sellerProfile->id)->first();
        $this->assertSame('published', $store->publication_status);
        $product = $db->table('products')->where('store_id', $store->id)->first();
        $this->assertSame('active', $product->product_status);
        $this->assertSame('clear', $product->compliance_status);
        $this->assertSame('["cod"]', $product->payment_methods);
        $this->assertTrue(Product::query()->visibleToBuyers()->acceptsCashOnDelivery()->whereKey($product->id)->exists());
        $variant = $db->table('product_variants')->where('product_id', $product->id)->first();
        $this->assertSame(19900, (int) $variant->price_minor);
        $this->assertSame(5, (int) $variant->stock_on_hand);
        $this->assertSame(1, (int) $variant->is_active);

        $logistics = $db->table('users')->where('email', 'logistics.web-smoke@bearly.test')->first();
        $logisticsProfile = $db->table('logistics_profiles')->where('user_id', $logistics->id)->first();
        $this->assertSame('active', $logisticsProfile->status);
        $center = $db->table('sorting_centers')->where('logistics_profile_id', $logisticsProfile->id)->first();
        $zone = $db->table('sorting_zones')->where('sorting_center_id', $center->id)->first();
        $this->assertSame('active', $center->status);
        $this->assertSame('active', $zone->status);
        $this->assertSame(1, $db->table('addresses')->where('id', $center->address_id)->where('user_id', $logistics->id)->count());
        $centerAddress = $db->table('addresses')->where('id', $center->address_id)->first();
        $this->assertSame(self::CITY, $centerAddress->city_municipality);
        $this->assertSame(self::CITY_CODE, $centerAddress->psgc_city_code);
        $this->assertSame(self::PROVINCE_CODE, $centerAddress->psgc_province_code);
        $this->assertSame(self::CITY_CODE, $logisticsProfile->psgc_city_code ?? '');
        $zoneRules = json_decode($zone->destination_rules, true);
        $this->assertSame('Laguna', $zoneRules['province']);
        $this->assertSame(self::CITY, $zoneRules['city_municipality']);
        $this->assertSame(self::CITY_CODE, $zoneRules['psgc_city_code']);
        $this->assertContains('Balibago', $zoneRules['barangays']);
        $this->assertTrue(app(SortingZoneMatcher::class)->coversDestination($zoneRules, [
            'province' => $buyerAddress->province,
            'city_municipality' => $buyerAddress->city_municipality,
            'barangay' => $buyerAddress->barangay,
            'psgc_city_code' => $buyerAddress->psgc_city_code,
        ]));

        foreach (['rider-pickup.web-smoke@bearly.test', 'rider-delivery.web-smoke@bearly.test'] as $email) {
            $rider = $db->table('users')->where('email', $email)->first();
            $profile = $db->table('rider_profiles')->where('user_id', $rider->id)->first();
            $this->assertSame($logistics->id, $rider->logistics_id);
            $this->assertSame($logisticsProfile->id, $profile->logistics_profile_id);
            $this->assertSame($center->id, $profile->home_sorting_center_id);
            $this->assertSame($zone->id, $profile->current_zone_id);
            $this->assertSame('approved', $profile->verification_status);
            $this->assertSame('available', $profile->availability_status);
            $this->assertGreaterThanOrEqual(1, (int) $profile->parcel_capacity);
        }

        $cartItemId = $this->createBuyerCart((int) $buyer->id, (int) $variant->id);
        $quote = app(CheckoutService::class)->quote(
            User::query()->findOrFail($buyer->id),
            [$cartItemId],
            (int) $buyerAddress->id,
        );
        $this->assertSame(19900, $quote['subtotal_minor']);
        $this->assertGreaterThan(0, $quote['shipping_fee_minor']);
        $this->assertSame(24900, $quote['total_minor']);
        $this->assertSame('same_municipality', $quote['seller_orders'][0]['quote']['zone_classification']);
        $this->assertSame(self::CITY_CODE, $quote['seller_orders'][0]['quote']['destination']['psgc_city_code']);

        $adminAfter = $db->table('users')->where('role', 'admin')->orderBy('id')->get()->map(fn (object $user): array => (array) $user)->all();
        $this->assertSame($adminBefore, $adminAfter, 'The disposable fixture seeder must not create or alter Admin accounts.');

        $tables = ['users', 'addresses', 'seller_profiles', 'stores', 'products', 'product_variants', 'logistics_profiles', 'sorting_centers', 'sorting_zones', 'rider_profiles', 'role_user'];
        $countsBefore = [];
        foreach ($tables as $table) {
            $countsBefore[$table] = $db->table($table)->count();
        }
        $passwordHashBefore = $db->table('users')->where('email', 'buyer.web-smoke@bearly.test')->value('password');

        app(DisposableWebSmokeFixtureSeeder::class)->run();

        foreach ($tables as $table) {
            $this->assertSame($countsBefore[$table], $db->table($table)->count(), "Repeated seeding must not duplicate {$table} rows.");
        }
        $this->assertSame($passwordHashBefore, $db->table('users')->where('email', 'buyer.web-smoke@bearly.test')->value('password'), 'Reruns must not reset fixture passwords.');

        foreach (['orders', 'payments', 'cod_cash_collections', 'seller_transactions', 'seller_wallet_transactions', 'platform_commissions', 'rider_earnings', 'rider_earning_payouts', 'shipping_settlements'] as $table) {
            $this->assertSame(0, $db->table($table)->count(), "Fixture setup must not create {$table} records.");
        }
    }

    public function test_it_preserves_login_updated_role_timestamps_while_repairing_legacy_locations(): void
    {
        $this->seedDisposableFixtures();

        $db = DB::connection('sqlite');
        $fixtureRoleNames = [
            'buyer.web-smoke@bearly.test' => 'buyer',
            'seller.web-smoke@bearly.test' => 'seller',
            'logistics.web-smoke@bearly.test' => 'logistics',
            'rider-pickup.web-smoke@bearly.test' => 'rider',
            'rider-delivery.web-smoke@bearly.test' => 'rider',
        ];
        $previousTestNow = Carbon::getTestNow();
        Carbon::setTestNow('2026-10-26 07:15:00');
        try {
            foreach ($fixtureRoleNames as $email => $_roleName) {
                app(RegistrationLifecycleService::class)->syncActiveRole(
                    User::query()->where('email', $email)->firstOrFail(),
                );
            }
        } finally {
            Carbon::setTestNow($previousTestNow);
        }

        $timestampsAfterLogin = [];
        foreach ($fixtureRoleNames as $email => $roleName) {
            $userId = $db->table('users')->where('email', $email)->value('id');
            $roleId = $db->table('roles')->where('name', $roleName)->value('id');
            $timestampsAfterLogin[$email] = $db->table('role_user')
                ->where('user_id', $userId)
                ->where('role_id', $roleId)
                ->value('assigned_at');
            $this->assertSame('2026-10-26 07:15:00', $timestampsAfterLogin[$email]);
        }

        $financialTables = [
            'orders', 'payments', 'shipments', 'cod_cash_collections',
            'seller_transactions', 'seller_wallet_transactions', 'platform_commissions',
            'rider_earnings', 'rider_earning_payouts', 'shipping_settlements',
        ];
        $financialCountsBefore = [];
        foreach ($financialTables as $table) {
            $financialCountsBefore[$table] = $db->table($table)->count();
        }

        $fixtureUserIds = $db->table('users')
            ->whereIn('email', [
                'buyer.web-smoke@bearly.test',
                'seller.web-smoke@bearly.test',
                'logistics.web-smoke@bearly.test',
            ])
            ->pluck('id');
        $allFixtureUserIds = $db->table('users')->whereIn('email', array_keys($fixtureRoleNames))->pluck('id');
        $db->table('addresses')->whereIn('user_id', $fixtureUserIds)->update([
            'city_municipality' => self::LEGACY_CITY,
            'psgc_province_code' => self::LEGACY_PROVINCE_CODE,
            'psgc_city_code' => self::LEGACY_CITY_CODE,
        ]);

        $logistics = $db->table('users')->where('email', 'logistics.web-smoke@bearly.test')->first();
        $logisticsProfileId = $db->table('logistics_profiles')->where('user_id', $logistics->id)->value('id');
        $db->table('logistics_profiles')->where('id', $logisticsProfileId)->update([
            'psgc_city_code' => self::LEGACY_CITY_CODE,
        ]);
        $center = $db->table('sorting_centers')->where('logistics_profile_id', $logisticsProfileId)->first();
        $db->table('sorting_zones')->where('sorting_center_id', $center->id)->update([
            'destination_rules' => json_encode([
                'province' => 'Laguna',
                'city_municipality' => self::LEGACY_CITY,
                'psgc_city_code' => self::LEGACY_CITY_CODE,
                'barangays' => ['Balibago'],
            ], JSON_THROW_ON_ERROR),
        ]);

        app(DisposableWebSmokeFixtureSeeder::class)->run();

        $this->assertSame(3, $db->table('addresses')->whereIn('user_id', $fixtureUserIds)
            ->where('city_municipality', self::CITY)
            ->where('psgc_province_code', self::PROVINCE_CODE)
            ->where('psgc_city_code', self::CITY_CODE)
            ->count());
        $this->assertSame(self::CITY_CODE, $db->table('logistics_profiles')->where('user_id', $logistics->id)->value('psgc_city_code'));
        $zone = $db->table('sorting_zones')->where('sorting_center_id', $center->id)->first();
        $rules = json_decode($zone->destination_rules, true);
        $this->assertSame(self::CITY, $rules['city_municipality']);
        $this->assertSame(self::CITY_CODE, $rules['psgc_city_code']);

        foreach ($fixtureRoleNames as $email => $roleName) {
            $userId = $db->table('users')->where('email', $email)->value('id');
            $roleId = $db->table('roles')->where('name', $roleName)->value('id');
            $this->assertSame(
                $timestampsAfterLogin[$email],
                $db->table('role_user')->where('user_id', $userId)->where('role_id', $roleId)->value('assigned_at'),
                'Rerunning the fixture seeder must preserve the role assignment timestamp written during login.',
            );
            $this->assertSame(1, $db->table('role_user')->where('user_id', $userId)->count());
        }
        $this->assertSame(
            5,
            $db->table('role_user')->whereIn('user_id', $allFixtureUserIds)->count(),
            'Rerunning after login must not duplicate role pivots for fixture accounts.',
        );

        $buyer = $db->table('users')->where('email', 'buyer.web-smoke@bearly.test')->first();
        $buyerAddress = $db->table('addresses')->where('user_id', $buyer->id)->where('is_default_shipping', 1)->first();
        $variant = $db->table('product_variants')->where('sku', 'BEARLY-WEB-SMOKE-001')->first();
        $cartItemId = $this->createBuyerCart((int) $buyer->id, (int) $variant->id);
        $quote = app(CheckoutService::class)->quote(
            User::query()->findOrFail($buyer->id),
            [$cartItemId],
            (int) $buyerAddress->id,
        );
        $this->assertGreaterThan(0, $quote['shipping_fee_minor']);
        $this->assertSame(24900, $quote['total_minor']);

        foreach ($financialTables as $table) {
            $this->assertSame($financialCountsBefore[$table], $db->table($table)->count(), "Location repair must not alter {$table} records.");
        }
    }

    public function test_it_fails_closed_for_unexpected_role_assignments_and_provenance(): void
    {
        $this->seedDisposableFixtures();

        $db = DB::connection('sqlite');
        $buyerId = (int) $db->table('users')->where('email', 'buyer.web-smoke@bearly.test')->value('id');
        $buyerRoleId = (int) $db->table('roles')->where('name', 'buyer')->value('id');
        $sellerRoleId = (int) $db->table('roles')->where('name', 'seller')->value('id');
        $logisticsId = (int) $db->table('users')->where('email', 'logistics.web-smoke@bearly.test')->value('id');

        $db->table('role_user')->where('user_id', $buyerId)->where('role_id', $buyerRoleId)->update(['assigned_by' => $logisticsId]);
        try {
            app(DisposableWebSmokeFixtureSeeder::class)->run();
            $this->fail('The seeder accepted an unexpected role assignment provenance.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('[assigned_by]', $exception->getMessage());
        }
        $this->assertSame($logisticsId, (int) $db->table('role_user')->where('user_id', $buyerId)->where('role_id', $buyerRoleId)->value('assigned_by'));

        $db->table('role_user')->where('user_id', $buyerId)->where('role_id', $buyerRoleId)->update(['assigned_by' => null]);
        $db->table('role_user')->insert([
            'user_id' => $buyerId,
            'role_id' => $sellerRoleId,
            'assigned_by' => null,
            'assigned_at' => '2026-10-01 00:00:00',
        ]);
        try {
            app(DisposableWebSmokeFixtureSeeder::class)->run();
            $this->fail('The seeder accepted an unexpected additional role assignment.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unexpected role assignment', $exception->getMessage());
        }
        $this->assertSame(2, $db->table('role_user')->where('user_id', $buyerId)->count());
    }

    public function test_it_does_not_repair_an_unexpected_fixture_ownership_value(): void
    {
        $this->seedDisposableFixtures();

        $db = DB::connection('sqlite');
        $pickupRider = $db->table('users')->where('email', 'rider-pickup.web-smoke@bearly.test')->first();
        $db->table('users')->where('id', $pickupRider->id)->update(['logistics_id' => null]);

        try {
            app(DisposableWebSmokeFixtureSeeder::class)->run();
            $this->fail('The seeder accepted an unexpected Rider/Logistics ownership value.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('[logistics_id]', $exception->getMessage());
        }

        $this->assertNull($db->table('users')->where('id', $pickupRider->id)->value('logistics_id'));
    }

    public function test_checkout_rejects_a_city_code_that_does_not_match_the_selected_address(): void
    {
        $this->seedDisposableFixtures();

        $db = DB::connection('sqlite');
        $buyer = $db->table('users')->where('email', 'buyer.web-smoke@bearly.test')->first();
        $address = $db->table('addresses')->where('user_id', $buyer->id)->where('label', 'Buyer Home')->first();
        $variant = $db->table('product_variants')->where('sku', 'BEARLY-WEB-SMOKE-001')->first();
        $cartItemId = $this->createBuyerCart((int) $buyer->id, (int) $variant->id);
        $db->table('addresses')->where('id', $address->id)->update(['psgc_city_code' => self::LEGACY_CITY_CODE]);

        try {
            app(CheckoutService::class)->quote(
                User::query()->findOrFail($buyer->id),
                [$cartItemId],
                (int) $address->id,
            );
            $this->fail('Checkout accepted a city code that does not match the selected city label.');
        } catch (ValidationException $exception) {
            $this->assertContains(
                'Please select your city again; the PSGC city code does not match the address.',
                $exception->errors()['city'] ?? [],
            );
        }
    }

    private function seedDisposableFixtures(): void
    {
        $this->prepareDisposableDatabase();
        app(DisposableWebSmokeFixtureSeeder::class)->run();
    }

    private function prepareDisposableDatabase(): void
    {
        $this->useFixtureConfiguration();
        $this->setEnvironment('BEARLY_DISPOSABLE_SMOKE', '1');
        $this->setEnvironment('BEARLY_DISPOSABLE_SMOKE_PASSWORD', self::PASSWORD);

        $migrationExit = Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $this->assertSame(0, $migrationExit, Artisan::output());
        app(DatabaseSeeder::class)->run();
    }

    private function createBuyerCart(int $buyerId, int $variantId): int
    {
        $now = '2026-10-01 00:00:00';
        $cartId = DB::connection('sqlite')->table('carts')->insertGetId([
            'user_id' => $buyerId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) DB::connection('sqlite')->table('cart_items')->insertGetId([
            'cart_id' => $cartId,
            'product_variant_id' => $variantId,
            'quantity' => 1,
            'selected' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function assertRejectedWithoutConnecting(string $message): void
    {
        DB::purge('sqlite');
        $this->assertSame([], DB::getConnections());

        try {
            app(DisposableWebSmokeFixtureSeeder::class)->run();
            $this->fail('Unsafe disposable database configuration was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        $this->assertSame([], DB::getConnections(), 'The seeder must reject unsafe configuration before opening any database connection.');
    }

    private function useFixtureConfiguration(): void
    {
        config([
            'app.env' => 'testing',
            'database.default' => 'sqlite',
            'database.connections.sqlite.driver' => 'sqlite',
            'database.connections.sqlite.database' => $this->fixtureDirectory.DIRECTORY_SEPARATOR.'database.sqlite',
            'database.connections.sqlite.url' => '',
            'database.url' => null,
        ]);
        DB::setDefaultConnection('sqlite');
        DB::purge('sqlite');
        $this->setEnvironment('DB_CONNECTION', 'sqlite');
        $this->setEnvironment('DB_DATABASE', $this->fixtureDirectory.DIRECTORY_SEPARATOR.'database.sqlite');
        $this->setEnvironment('DB_URL', '');
        $this->setEnvironment('DATABASE_URL', '');
    }

    private function createDirectory(string $parent, string $name): string
    {
        $path = rtrim($parent, DIRECTORY_SEPARATOR.'\\/').DIRECTORY_SEPARATOR.$name;
        if (! mkdir($path, 0700) || realpath($path) === false) {
            $this->fail('Unable to create an isolated disposable fixture directory.');
        }

        return (string) realpath($path);
    }

    private function setEnvironment(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function removeCreatedDirectory(string $directory): void
    {
        $real = realpath($directory);
        $temp = realpath((string) getenv('TEMP'));
        if ($real === false || $temp === false || realpath(dirname($real)) !== $temp) {
            throw new RuntimeException('Refusing to clean a test directory outside its direct TEMP child.');
        }

        foreach (new \DirectoryIterator($real) as $item) {
            if ($item->isDot()) {
                continue;
            }
            if ($item->isDir() && ! $item->isLink()) {
                $this->removeCreatedDirectory($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($real);
    }
}
