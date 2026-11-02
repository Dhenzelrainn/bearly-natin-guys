<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\Address;
use App\Models\Category;
use App\Models\LogisticsProfile;
use App\Models\User;
use App\Services\RegistrationDraftService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
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
                'valid_id' => UploadedFile::fake()->create('seller-id.pdf', 100, 'application/pdf'),
                'business_permit' => UploadedFile::fake()->create('permit.pdf', 100, 'application/pdf'),
            ]);
            $this->grantEmailProof($email);

            $this->post(route('register.submit'), $payload)
                ->assertRedirect(route('application.pending'));

            $user = User::query()->where('email', $email)->firstOrFail();
            $this->assertSame(AccountStatus::Pending->value, $user->status);
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

    public function test_buyer_registration_hides_the_seller_id_upload_and_does_not_mark_it_required(): void
    {
        $response = $this->get(route('register'))
            ->assertOk()
            ->assertSee('Verified email activates your Buyer account', false)
            ->assertSee('placeholder="e.g. Juan"', false)
            ->assertSee('placeholder="e.g. Dela Cruz"', false)
            ->assertSee('placeholder="name@example.com"', false)
            ->assertSee('placeholder="917 123 4567"', false)
            ->assertSee('placeholder="Create password"', false)
            ->assertSee('placeholder="Confirm password"', false)
            ->assertSee('placeholder="Street or purok"', false)
            ->assertSee('placeholder="House or unit no."', false)
            ->assertSee('placeholder="e.g. 1000"', false)
            ->assertSee('data-search-placeholder="Search region"', false)
            ->assertSee('data-search-placeholder="Search city"', false)
            ->assertSee('placeholder="Registered business name"', false);

        $html = $response->getContent();
        preg_match('/<input name="valid_id"[^>]*>/s', $html, $matches);

        $this->assertNotEmpty($matches[0] ?? null);
        $this->assertDoesNotMatchRegularExpression('/\srequired(?:\s|>)/', $matches[0]);
        $this->assertMatchesRegularExpression('/data-seller-document\s+hidden/', $html);
    }

    public function test_rider_and_logistics_registration_show_short_placeholders_across_all_steps(): void
    {
        foreach ([
            route('rider.register') => 'Rider application',
            route('logistics.register') => 'Logistics partner application',
        ] as $registrationRoute => $registrationLabel) {
            $this->get($registrationRoute)
                ->assertOk()
                ->assertSee('<header class="registration-header" data-registration-header>', false)
                ->assertSee('class="registration-brand-divider" aria-hidden="true"', false)
                ->assertSee($registrationLabel)
                ->assertSee('placeholder="e.g. Juan"', false)
                ->assertSee('placeholder="e.g. Dela Cruz"', false)
                ->assertSee('placeholder="name@example.com"', false)
                ->assertSee('placeholder="0917 123 4567"', false)
                ->assertSee('placeholder="6-digit code"', false)
                ->assertSee('placeholder="Auto-calculated"', false)
                ->assertSee('placeholder="Auto-generated"', false)
                ->assertSee('placeholder="Street or purok"', false)
                ->assertSee('placeholder="House or unit no."', false)
                ->assertSee('data-search-placeholder="Search region"', false)
                ->assertSee('data-search-placeholder="Search municipality"', false)
                ->assertSee('placeholder="Create password"', false)
                ->assertSee('placeholder="Confirm password"', false);
        }

        $this->get(route('rider.register'))
            ->assertOk()
            ->assertSee('placeholder="e.g. Toyota HiAce"', false)
            ->assertSee('placeholder="e.g. ABC 1234"', false);

        $this->get(route('logistics.register'))
            ->assertOk()
            ->assertSee('placeholder="Registered facility or business name"', false);
    }

    public function test_rider_logistics_partner_selector_is_after_municipality_in_address_step(): void
    {
        $html = (string) $this->get(route('rider.register'))
            ->assertOk()
            ->getContent();

        $stepOneStart = strpos($html, '<section class="form-step" data-form-step="1">');
        $stepTwoStart = strpos($html, '<section class="form-step" data-form-step="2" hidden>');
        $stepThreeStart = strpos($html, '<section class="form-step" data-form-step="3" hidden>');

        $this->assertNotFalse($stepOneStart);
        $this->assertNotFalse($stepTwoStart);
        $this->assertNotFalse($stepThreeStart);

        $stepOne = substr($html, $stepOneStart, $stepTwoStart - $stepOneStart);
        $stepTwo = substr($html, $stepTwoStart, $stepThreeStart - $stepTwoStart);

        $this->assertStringNotContainsString('name="logistics_partner"', $stepOne);
        $this->assertMatchesRegularExpression(
            '/<select\b(?=[^>]*\bid="rider-logistics-partner")(?=[^>]*\bname="logistics_partner")(?=[^>]*\brequired\b)[^>]*>/s',
            $stepTwo
        );
        $this->assertStringContainsString('aria-describedby="rider-logistics-partner-help"', $stepTwo);
        $this->assertStringContainsString('id="rider-logistics-partner-help"', $stepTwo);
        $this->assertStringContainsString('data-logistics-partner-help', $stepTwo);

        $cityPosition = strpos($stepTwo, 'id="rider-city"');
        $partnerPosition = strpos($stepTwo, 'id="rider-logistics-partner"');
        $barangayPosition = strpos($stepTwo, 'id="rider-barangay"');

        $this->assertNotFalse($cityPosition);
        $this->assertNotFalse($partnerPosition);
        $this->assertNotFalse($barangayPosition);
        $this->assertGreaterThan($cityPosition, $partnerPosition);
        $this->assertLessThan($barangayPosition, $partnerPosition);
    }

    public function test_buyer_registration_rejects_missing_email_proof_without_persisting_user_or_address(): void
    {
        $payload = $this->buyerData(
            'missing-proof@example.test',
            '09171234567',
        );

        $this->from(route('register'))
            ->post(route('register.submit'), $payload)
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
        $this->assertFalse(Address::query()->where('recipient_name', 'Bianca Santos')->exists());
        $this->assertFalse(AccountApplication::query()->exists());
        $this->assertSame([], Storage::disk('local')->allFiles('registration-documents/valid-ids'));
    }

    public function test_buyer_registration_rejects_a_region_that_does_not_match_the_selected_city(): void
    {
        $payload = $this->buyerData('mismatched-region@example.test', '09171234567', [
            'region' => 'Region I (Ilocos Region)',
            'region_code' => '0100000000',
        ]);

        $this->from(route('register'))
            ->post(route('register.submit'), $payload)
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors('region');

        $this->assertDatabaseMissing('users', [
            'email' => $payload['email'],
        ]);
    }

    public function test_registration_draft_cookie_is_http_only_lax_and_renews_for_seven_days(): void
    {
        $now = Carbon::create(2026, 11, 2, 9, 0, 0);
        Carbon::setTestNow($now);

        try {
            $response = $this->get(route('register'))->assertOk();
            $cookie = $response->getCookie(RegistrationDraftService::COOKIE_NAME);

            $this->assertNotNull($cookie);
            $this->assertTrue($cookie->isHttpOnly());
            $this->assertSame('lax', $cookie->getSameSite());
            $this->assertSame($response->baseRequest->isSecure(), $cookie->isSecure());
            $this->assertSame($now->copy()->addDays(7)->timestamp, $cookie->getExpiresTime());

            $secureResponse = $this->withServerVariables(['HTTPS' => 'on'])
                ->get(route('rider.register'))
                ->assertOk();
            $secureCookie = $secureResponse->getCookie(RegistrationDraftService::COOKIE_NAME);
            $this->assertNotNull($secureCookie);
            $this->assertTrue($secureCookie->isSecure());
            $this->assertTrue($secureCookie->isHttpOnly());
            $this->assertSame('lax', $secureCookie->getSameSite());

            Carbon::setTestNow($now->copy()->addDays(2));
            $renewed = $this->withCredentials()->withCookie(
                RegistrationDraftService::COOKIE_NAME,
                $cookie->getValue(),
            )->postJson(route('register.draft.save', 'rider'), [
                'fields' => ['first_name' => 'Saved'],
                'step' => 2,
            ]);
            $renewedCookie = $renewed->getCookie(RegistrationDraftService::COOKIE_NAME);

            $renewed->assertOk();
            $this->assertNotNull($renewedCookie);
            $this->assertTrue($renewedCookie->isHttpOnly());
            $this->assertSame('lax', $renewedCookie->getSameSite());
            $this->assertSame($renewed->baseRequest->isSecure(), $renewedCookie->isSecure());
            $this->assertSame($now->copy()->addDays(9)->timestamp, $renewedCookie->getExpiresTime());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_successful_buyer_registration_clears_its_registration_draft(): void
    {
        $email = 'draft-cleanup-buyer@example.test';
        $token = str_repeat('e', 64);

        $this->withCredentials()->withCookie(RegistrationDraftService::COOKIE_NAME, $token)
            ->postJson(route('register.draft.save', 'buyer-seller'), [
                'fields' => [
                    'role' => UserRole::Buyer->value,
                    'first_name' => 'Bianca',
                    'email' => $email,
                ],
                'step' => 2,
            ])
            ->assertOk();

        $this->assertDatabaseHas('registration_drafts', [
            'browser_token_hash' => hash('sha256', $token),
            'flow' => 'buyer-seller',
        ]);

        $this->grantEmailProof($email);
        $this->withCookie(RegistrationDraftService::COOKIE_NAME, $token)
            ->post(route('register.submit'), $this->buyerData($email, '09181234567'))
            ->assertRedirect(route('login'));

        $this->assertDatabaseMissing('registration_drafts', [
            'browser_token_hash' => hash('sha256', $token),
            'flow' => 'buyer-seller',
        ]);
    }

    public function test_seller_registration_still_requires_id_and_business_permit(): void
    {
        $category = Category::query()
            ->where('is_registration_option', true)
            ->where('is_active', true)
            ->firstOrFail();
        $payload = $this->buyerData('seller-documents@example.test', '09181234567', [
            'role' => UserRole::Seller->value,
            'business_name' => 'Seller Documents Store',
            'business_category_id' => (string) $category->id,
        ]);
        unset($payload['valid_id']);

        $this->from(route('register'))
            ->post(route('register.submit'), $payload)
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors(['valid_id', 'business_permit']);

        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
    }

    public function test_verified_google_email_can_register_a_buyer_without_id_or_manual_review(): void
    {
        $email = 'google-buyer@example.test';
        $googleUser = (new SocialiteUser)
            ->setRaw([
                'email_verified' => true,
                'given_name' => 'Google',
                'family_name' => 'Buyer',
            ])
            ->map([
                'id' => 'google-new-buyer-1',
                'name' => 'Google Buyer',
                'email' => $email,
            ]);
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->once()->andReturn($googleUser);
        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($driver);

        $this->get(route('google.callback'))
            ->assertRedirect(route('register'))
            ->assertSessionHas('google_registration');

        $this->post(route('register.submit'), $this->buyerData($email, '09181234567'))
            ->assertRedirect(route('login'));

        $buyer = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame(AccountStatus::Active->value, $buyer->status);
        $this->assertNotNull($buyer->email_verified_at);
        $this->assertSame('google-new-buyer-1', $buyer->google_id);
        $this->assertNull($buyer->valid_id_path);
        $this->assertNull($buyer->phone_verified_at);
        $this->assertFalse($buyer->applications()->exists());
    }

    public function test_unverified_google_email_cannot_start_buyer_registration(): void
    {
        $email = 'unverified-google-buyer@example.test';
        $googleUser = (new SocialiteUser)
            ->setRaw(['email_verified' => false])
            ->map([
                'id' => 'google-unverified-buyer-1',
                'name' => 'Unverified Buyer',
                'email' => $email,
            ]);
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->once()->andReturn($googleUser);
        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($driver);

        $this->get(route('google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', ['email' => $email]);
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
            ->assertRedirect(route('login'));

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
            ->assertRedirect(route('login'));

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
                ->assertRedirect(route('login'));

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
            'region' => 'Region IV-A (CALABARZON)',
            'region_code' => '0400000000',
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
            'region' => 'Region IV-A (CALABARZON)',
            'region_code' => '0400000000',
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
            'region' => 'Region IV-A (CALABARZON)',
            'region_code' => '0400000000',
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

    public function test_rider_registration_rejects_a_region_that_does_not_match_the_selected_city(): void
    {
        $logistics = User::factory()->create([
            'email' => 'region-check-logistics@example.test',
            'contact_number' => '+639281234567',
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
        ]);

        $data = $this->riderData($logistics, [
            'email' => 'mismatched-region-rider@example.test',
            'region' => 'Region I (Ilocos Region)',
            'region_code' => '0100000000',
        ]);

        $this->from(route('rider.register'))
            ->post(route('rider.register.submit'), $data)
            ->assertRedirect(route('rider.register'))
            ->assertSessionHasErrors('region');

        $this->assertDatabaseMissing('users', [
            'email' => 'mismatched-region-rider@example.test',
        ]);
    }

    public function test_rider_registration_rejects_partner_that_does_not_serve_submitted_municipality(): void
    {
        $logistics = User::factory()->create([
            'email' => 'other-city-logistics@example.test',
            'contact_number' => '+639281234567',
            'role' => UserRole::Logistics->value,
            'status' => AccountStatus::Active->value,
        ]);

        LogisticsProfile::query()->create([
            'user_id' => $logistics->id,
            'legal_name' => 'Other City Logistics',
            'display_name' => 'Other City Logistics',
            'contact_phone' => '09170000001',
            'psgc_city_code' => '0403422000',
            'status' => AccountStatus::Active->value,
        ]);

        $data = $this->riderData($logistics, [
            'email' => 'mismatched-partner-city@example.test',
        ]);

        $this->from(route('rider.register'))
            ->post(route('rider.register.submit'), $data)
            ->assertSessionHasErrors('logistics_partner');

        $this->assertDatabaseMissing('users', [
            'email' => 'mismatched-partner-city@example.test',
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
            'region' => 'Region IV-A (CALABARZON)',
            'region_code' => '0400000000',
            'province' => 'Laguna',
            'city' => 'San Pablo City',
            'barangay' => 'San Rafael',
            'street_name' => 'Bearly Street',
            'house_number' => '12',
            'postal_code' => '4000',
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
            'region' => 'Region IV-A (CALABARZON)',
            'region_code' => '0400000000',
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
