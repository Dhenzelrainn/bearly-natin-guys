<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RegistrationValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'email-verification.enabled' => true,
            'mail.mailers.smtp.host' => 'smtp.test',
            'mail.from.address' => 'noreply@bearly.test',
            'cache.default' => 'array',
        ]);

        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    public function test_database_backed_seller_categories_persist_the_matching_business_category_id(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $categories = Category::query()
            ->where('is_registration_option', true)
            ->where('is_active', true)
            ->with('marketplaceCategories')
            ->get()
            ->sortBy(fn (Category $category): int => (int) $category->marketplaceCategories->first()->position)
            ->values();

        $this->assertCount(12, $categories);

        $registerPage = $this->get(route('register'))
            ->assertOk()
            ->assertSee('name="business_category_id"', false)
            ->assertDontSee('Toys and Hobbies')
            ->assertDontSee('Automotive and Parts')
            ->assertDontSee('Books and Stationery');

        foreach ($categories as $index => $category) {
            $registerPage
                ->assertSee('<option value="'.$category->id.'"', false)
                ->assertSee($category->name);

            $email = 'registration-option-'.$category->id.'@example.test';
            $phone = '0917'.str_pad((string) ($index + 1), 7, '0', STR_PAD_LEFT);
            $payload = $this->buyerData($email, $phone, [
                'role' => UserRole::Seller->value,
                'business_name' => 'Registration Option Seller '.($index + 1),
                'business_category_id' => (string) $category->id,
                'business_permit' => UploadedFile::fake()->create('permit.pdf', 100, 'application/pdf'),
            ]);
            $this->grantEmailProof($email);

            $this->post(route('register.submit'), $payload)
                ->assertRedirect(route('application.pending'));

            $user = User::query()->where('email', $email)->firstOrFail();
            $application = AccountApplication::query()->where('user_id', $user->id)->firstOrFail();
            $buyerCategory = $category->marketplaceCategories->sole();

            $this->assertSame((int) $category->id, (int) $application->business_category_id);
            $this->assertSame($category->name, $user->business_category);
            $this->assertSame(1, $category->marketplaceCategories->count());
            $this->assertSame($category->slug, $buyerCategory->slug);
        }

        $legacyCategory = Category::query()->where('slug', 'toys-and-hobbies')->firstOrFail();
        $legacyPayload = $this->buyerData('legacy-category-choice@example.test', '09179999999', [
            'role' => UserRole::Seller->value,
            'business_name' => 'Legacy Category Attempt',
            'business_category_id' => $legacyCategory->id,
            'business_permit' => UploadedFile::fake()->create('legacy-permit.pdf', 100, 'application/pdf'),
        ]);

        $this->from(route('register'))
            ->post(route('register.submit'), $legacyPayload)
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors('business_category_id');
        $this->assertDatabaseMissing('users', ['email' => 'legacy-category-choice@example.test']);
    }

    public function test_buyer_names_support_unicode_and_enforce_two_to_fifty_characters(): void
    {
        $twoCharacterName = $this->buyerData(
            'jo@example.test',
            '09171234567',
            ['first_name' => 'Jo', 'last_name' => "Peñaflor-O'Hara"]
        );
        $this->grantEmailProof($twoCharacterName['email']);

        $this->post(route('register.submit'), $twoCharacterName)
            ->assertRedirect(route('application.pending'));

        $this->assertDatabaseHas('users', [
            'email' => 'jo@example.test',
            'first_name' => 'Jo',
            'last_name' => "Peñaflor-O'Hara",
        ]);

        $fiftyCharacterName = $this->buyerData(
            'fifty@example.test',
            '09181234567',
            ['first_name' => str_repeat('A', 50)]
        );
        $this->grantEmailProof($fiftyCharacterName['email']);

        $this->post(route('register.submit'), $fiftyCharacterName)
            ->assertRedirect(route('application.pending'));

        $this->assertDatabaseHas('users', [
            'email' => 'fifty@example.test',
            'first_name' => str_repeat('A', 50),
        ]);

        $tooShort = $this->buyerData(
            'short@example.test',
            '09191234567',
            ['first_name' => 'J']
        );

        $this->from(route('register'))
            ->post(route('register.submit'), $tooShort)
            ->assertSessionHasErrors('first_name');

        $tooLong = $this->buyerData(
            'long@example.test',
            '09201234567',
            ['first_name' => str_repeat('A', 51)]
        );

        $this->from(route('register'))
            ->post(route('register.submit'), $tooLong)
            ->assertSessionHasErrors('first_name');

        $this->assertDatabaseMissing('users', ['email' => 'short@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'long@example.test']);
    }

    public function test_buyer_must_be_eighteen_and_may_register_on_the_eighteenth_birthday(): void
    {
        Carbon::setTestNow('2026-10-08 12:00:00');

        try {
            $underage = $this->buyerData(
                'underage@example.test',
                '09171234567',
                ['birthday' => '2008-10-09']
            );

            $this->from(route('register'))
                ->post(route('register.submit'), $underage)
                ->assertSessionHasErrors('birthday');

            $this->assertDatabaseMissing('users', [
                'email' => 'underage@example.test',
            ]);

            $eighteenToday = $this->buyerData(
                'eighteen@example.test',
                '09181234567',
                ['birthday' => '2008-10-08']
            );
            $this->grantEmailProof($eighteenToday['email']);

            $this->post(route('register.submit'), $eighteenToday)
                ->assertRedirect(route('application.pending'));

            $eighteen = User::query()
                ->where('email', 'eighteen@example.test')
                ->firstOrFail();

            $this->assertSame('2008-10-08', $eighteen->birthday->toDateString());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_phone_numbers_are_normalized_and_unique_across_roles(): void
    {
        User::factory()->create([
            'email' => 'existing-seller@example.test',
            'role' => UserRole::Seller->value,
            'status' => AccountStatus::Pending->value,
            'contact_number' => '+639171234567',
        ]);

        $duplicate = $this->buyerData(
            'another-account@example.test',
            '09171234567'
        );

        $this->from(route('register'))
            ->post(route('register.submit'), $duplicate)
            ->assertSessionHasErrors('contact_number');

        $this->assertDatabaseMissing('users', [
            'email' => 'another-account@example.test',
        ]);
    }

    public function test_registration_email_is_case_insensitive_and_validated_before_otp_proof(): void
    {
        User::factory()->create([
            'email' => 'already-registered@example.test',
            'contact_number' => '+639171234567',
        ]);

        $duplicate = $this->buyerData(
            'ALREADY-REGISTERED@EXAMPLE.TEST',
            '09181234567'
        );

        $this->from(route('register'))
            ->post(route('register.submit'), $duplicate)
            ->assertSessionHasErrors('email');

        $invalid = $this->buyerData(
            'not-an-email-address',
            '09191234567'
        );

        $this->from(route('register'))
            ->post(route('register.submit'), $invalid)
            ->assertSessionHasErrors('email');
    }

    public function test_logistics_invalid_names_are_reported_before_email_proof_errors(): void
    {
        $data = [
            'business_name' => 'Test Logistics',
            'first_name' => 'Carlos7',
            'last_name' => 'Dela Cruz@#',
            'sex' => 'Male',
            'email' => 'invalid-names-logistics@example.test',
            'contact_number' => '09285551234',
            'birthday' => '1990-01-01',
            'province' => 'Laguna',
            'municipality' => 'San Pablo City',
            'barangay' => 'San Rafael',
            'street' => 'Bearly Street',
            'house_number' => '12',
            'postal_code' => '4000',
            'valid_id' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
            'business_permit' => UploadedFile::fake()->create('permit.pdf', 100, 'application/pdf'),
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'terms' => '1',
        ];

        $this->from(route('logistics.register'))
            ->post(route('logistics.register.submit'), $data)
            ->assertSessionHasErrors(['first_name', 'last_name'])
            ->assertSessionDoesntHaveErrors('email');

        $this->assertDatabaseMissing('users', [
            'email' => 'invalid-names-logistics@example.test',
        ]);
    }

    public function test_logistics_requires_a_four_digit_postal_code(): void
    {
        $data = [
            'business_name' => 'Test Logistics',
            'first_name' => 'Carlos',
            'last_name' => 'Mendoza',
            'sex' => 'Male',
            'email' => 'postal-logistics@example.test',
            'contact_number' => '09285551234',
            'birthday' => '1990-01-01',
            'province' => 'Laguna',
            'municipality' => 'San Pablo City',
            'barangay' => 'San Rafael',
            'street' => 'Bearly Street',
            'house_number' => '12',
            'postal_code' => '40000',
            'valid_id' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
            'business_permit' => UploadedFile::fake()->create('permit.pdf', 100, 'application/pdf'),
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'terms' => '1',
        ];

        $this->from(route('logistics.register'))
            ->post(route('logistics.register.submit'), $data)
            ->assertSessionHasErrors('postal_code');
    }

    public function test_logistics_rejects_postal_code_that_does_not_match_the_selected_municipality(): void
    {
        $data = [
            'business_name' => 'Test Logistics',
            'first_name' => 'Carlos',
            'last_name' => 'Mendoza',
            'sex' => 'Male',
            'email' => 'mismatched-postal-logistics@example.test',
            'contact_number' => '09285551234',
            'birthday' => '1990-01-01',
            'province' => 'Laguna',
            'municipality' => 'Santa Cruz',
            'barangay' => 'Poblacion',
            'street' => 'Bearly Street',
            'house_number' => '12',
            'city_code' => '0403426000',
            'postal_code' => '4000',
            'postal_manual' => '0',
            'valid_id' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
            'business_permit' => UploadedFile::fake()->create('permit.pdf', 100, 'application/pdf'),
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'terms' => '1',
        ];

        $this->from(route('logistics.register'))
            ->post(route('logistics.register.submit'), $data)
            ->assertSessionHasErrors('postal_code');

        $this->assertDatabaseMissing('users', [
            'email' => 'mismatched-postal-logistics@example.test',
        ]);
    }

    public function test_rider_name_and_age_errors_are_not_masked_by_missing_email_proof(): void
    {
        Carbon::setTestNow('2026-10-08 12:00:00');

        try {
            $logistics = User::factory()->create([
                'email' => 'approved-logistics@example.test',
                'contact_number' => '+639281234567',
                'role' => UserRole::Logistics->value,
                'status' => AccountStatus::Active->value,
            ]);

            $data = $this->riderData($logistics, [
                'first_name' => 'Pedro99',
                'last_name' => 'Dela Cruz@#',
                'birthday' => '2008-10-09',
            ]);

            $this->from(route('rider.register'))
                ->post(route('rider.register.submit'), $data)
                ->assertSessionHasErrors(['first_name', 'last_name', 'birthday'])
                ->assertSessionDoesntHaveErrors('email');

            $this->assertDatabaseMissing('users', [
                'email' => 'invalid-rider@example.test',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_rider_mobile_and_plate_duplicates_are_rejected(): void
    {
        $logistics = User::factory()->create([
            'email' => 'approved-logistics@example.test',
            'contact_number' => '+639281234567',
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
        ]);

        User::factory()->create([
            'email' => 'existing-rider@example.test',
            'role' => UserRole::Rider->value,
            'status' => AccountStatus::Pending->value,
            'contact_number' => '+639171234567',
            'plate_number' => '123ABC',
        ]);

        $duplicatePhone = $this->riderData($logistics, [
            'email' => 'duplicate-phone-rider@example.test',
            'contact_number' => '09171234567',
            'plate_number' => '456DEF',
        ]);

        $this->from(route('rider.register'))
            ->post(route('rider.register.submit'), $duplicatePhone)
            ->assertSessionHasErrors('contact_number');

        $duplicatePlate = $this->riderData($logistics, [
            'email' => 'duplicate-plate-rider@example.test',
            'contact_number' => '09181234567',
            'plate_number' => '123abc',
        ]);

        $this->from(route('rider.register'))
            ->post(route('rider.register.submit'), $duplicatePlate)
            ->assertSessionHasErrors('plate_number');

        $this->assertDatabaseMissing('users', [
            'email' => 'duplicate-phone-rider@example.test',
        ]);
        $this->assertDatabaseMissing('users', [
            'email' => 'duplicate-plate-rider@example.test',
        ]);
    }

    public function test_rider_rejects_postal_code_that_does_not_match_the_selected_municipality(): void
    {
        $logistics = User::factory()->create([
            'email' => 'approved-logistics@example.test',
            'contact_number' => '+639281234567',
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
        ]);

        $data = $this->riderData($logistics, [
            'email' => 'mismatched-postal-rider@example.test',
            'postal_code' => '4009',
        ]);

        $this->from(route('rider.register'))
            ->post(route('rider.register.submit'), $data)
            ->assertSessionHasErrors('postal_code');

        $this->assertDatabaseMissing('users', [
            'email' => 'mismatched-postal-rider@example.test',
        ]);
    }

    public function test_rider_rejects_invalid_philippine_mobile_formats_before_email_proof(): void
    {
        $logistics = User::factory()->create([
            'email' => 'approved-logistics@example.test',
            'contact_number' => '+639281234567',
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
        ]);

        foreach ([
            '0917ABC4567',
            '0917-123-456',
            '12345678901',
            '02-8123-4567',
            '091712345678',
        ] as $invalidNumber) {
            $data = $this->riderData($logistics, [
                'email' => 'invalid-phone-rider@example.test',
                'contact_number' => $invalidNumber,
            ]);

            $this->from(route('rider.register'))
                ->post(route('rider.register.submit'), $data)
                ->assertSessionHasErrors('contact_number');
        }
    }

    public function test_buyer_cannot_open_other_role_dashboards(): void
    {
        $buyer = User::factory()->create([
            'email' => 'active-buyer@example.test',
            'role' => UserRole::Buyer->value,
            'status' => AccountStatus::Active->value,
        ]);

        $this->actingAs($buyer)
            ->get(route('seller.dashboard'))
            ->assertForbidden();

        $this->get(route('logistics.dashboard'))->assertForbidden();
        $this->get(route('rider.dashboard.pickups'))->assertForbidden();
        $this->get(route('admin.dashboard'))->assertForbidden();
    }

    private function buyerData(string $email, string $contactNumber, array $overrides = []): array
    {
        return array_replace([
            'role' => UserRole::Buyer->value,
            'first_name' => 'Bianca',
            'last_name' => 'Santos',
            'sex' => 'female',
            'birthday' => '1990-05-10',
            'email' => $email,
            'contact_number' => $contactNumber,
            'phone_country' => 'PH',
            'province' => 'Laguna',
            'city' => 'San Pablo City',
            'barangay' => 'San Rafael',
            'street_name' => 'Bearly Street',
            'house_number' => '12',
            'postal_code' => '4000',
            'valid_id' => UploadedFile::fake()->create('buyer-id.pdf', 100, 'application/pdf'),
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'terms' => '1',
        ], $overrides);
    }

    private function riderData(User $logistics, array $overrides = []): array
    {
        return array_replace([
            'logistics_partner' => $logistics->id,
            'first_name' => 'Pedro Jose',
            'last_name' => 'Garcia',
            'sex' => 'Male',
            'email' => 'valid-rider@example.test',
            'contact_number' => '09451239876',
            'birthday' => '1995-03-22',
            'province' => 'Laguna',
            'municipality' => 'City of San Pablo',
            'barangay' => 'San Rafael',
            'street' => 'Rider Road',
            'house_number' => '3',
            'city_code' => '0403424000',
            'postal_code' => '4000',
            'postal_manual' => '0',
            'vehicle_type' => 'Motorcycle',
            'plate_number' => '123ABC',
            'or_cr' => UploadedFile::fake()->create('or-cr.pdf', 100, 'application/pdf'),
            'driver_license' => UploadedFile::fake()->create('license.pdf', 100, 'application/pdf'),
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'terms' => '1',
        ], $overrides);
    }

    private function grantEmailProof(string $email): void
    {
        $this->startSession();
        $sessionId = $this->app['session']->getId();

        $this->withCookie((string) config('session.cookie'), $sessionId);

        Cache::put(
            'buyer-email:'.hash_hmac('sha256', $sessionId, (string) config('app.key')),
            [
                'email' => $email,
                'driver' => 'smtp',
                'attempts' => 0,
                'expires_at' => now()->addMinutes(5)->timestamp,
                'verified_until' => now()->addMinutes(30)->timestamp,
                'verified_at' => now()->toDateTimeString(),
            ],
            now()->addMinutes(30)
        );
    }
}
