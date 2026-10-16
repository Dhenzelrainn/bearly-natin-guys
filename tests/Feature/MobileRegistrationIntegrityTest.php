<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Mail\RegistrationCode;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MobileRegistrationIntegrityTest extends TestCase
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

        Cache::flush();
        Mail::fake();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
    }

    public function test_mobile_buyer_registration_enforces_name_length_and_minimum_age(): void
    {
        Carbon::setTestNow('2026-10-08 12:00:00');

        try {
            $this->post('/api/mobile/register/buyer', $this->buyerData([
                'first_name' => 'A',
                'last_name' => str_repeat('B', 51),
                'birthday' => '2008-10-09',
            ]), ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([
                    'first_name',
                    'last_name',
                    'birthday',
                ]);

            $this->assertDatabaseMissing('users', [
                'email' => 'mobile-buyer@example.test',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_mobile_buyer_registration_rejects_a_duplicate_normalized_phone(): void
    {
        User::factory()->create([
            'email' => 'existing-seller@example.test',
            'role' => UserRole::Seller->value,
            'status' => AccountStatus::Pending->value,
            'contact_number' => '+639171234567',
        ]);

        $this->post('/api/mobile/register/buyer', $this->buyerData([
            'contact_number' => '0917 123 4567',
        ]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contact_number');

        $this->assertDatabaseMissing('users', [
            'email' => 'mobile-buyer@example.test',
        ]);
    }

    public function test_mobile_buyer_registration_accepts_the_eighteenth_birthday_and_saves_e164_phone(): void
    {
        Carbon::setTestNow('2026-10-08 12:00:00');

        try {
            $email = 'mobile-buyer@example.test';
            $sendResponse = $this->postJson('/api/mobile/register/email/send', [
                'email' => $email,
            ])->assertOk();

            $verificationId = $sendResponse->json('verification_id');
            $code = null;

            Mail::assertSent(RegistrationCode::class, function (RegistrationCode $mail) use (&$code, $email): bool {
                $code = $mail->code;

                return $mail->hasTo($email);
            });

            $this->assertNotEmpty($verificationId);
            $this->assertNotEmpty($code);

            $this->postJson('/api/mobile/register/email/verify', [
                'email' => $email,
                'code' => $code,
                'verification_id' => $verificationId,
            ])->assertOk();

            $response = $this->post('/api/mobile/register/buyer', $this->buyerData([
                'birthday' => '2008-10-08',
                'verification_id' => $verificationId,
            ]), ['Accept' => 'application/json']);

            $response->assertCreated()
                ->assertJsonPath('application.email', $email)
                ->assertJsonPath('application.status', AccountStatus::Pending->value);

            $user = User::query()->where('email', $email)->firstOrFail();

            $this->assertSame('+639171234567', $user->contact_number);
            $this->assertSame('+639171234567', $user->phone);
            Storage::disk('local')->assertExists($user->valid_id_path);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function buyerData(array $overrides = []): array
    {
        return array_replace([
            'first_name' => 'Bianca',
            'last_name' => 'Santos',
            'sex' => 'female',
            'birthday' => '1990-05-10',
            'email' => 'mobile-buyer@example.test',
            'verification_id' => str_repeat('a', 64),
            'contact_number' => '09171234567',
            'phone_country' => 'PH',
            'province' => 'Laguna',
            'city' => 'City of San Pablo',
            'city_code' => '0403424000',
            'barangay' => 'San Rafael',
            'street_name' => 'Bearly Street',
            'house_number' => '12',
            'postal_code' => '4000',
            'postal_manual' => '0',
            'valid_id' => UploadedFile::fake()->create('buyer-id.pdf', 100, 'application/pdf'),
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'terms' => '1',
        ], $overrides);
    }
}
