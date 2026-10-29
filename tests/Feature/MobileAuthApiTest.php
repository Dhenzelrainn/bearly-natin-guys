<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\LogisticsProfile;
use App\Models\RiderProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileAuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_can_sign_in_read_their_mobile_profile_and_revoke_the_active_token(): void
    {
        $buyer = User::factory()->create([
            'email' => 'mobile.buyer@example.test',
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $login = $this->postJson('/api/mobile/login', [
            'email' => $buyer->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.id', $buyer->id)
            ->assertJsonPath('user.role', 'buyer')
            ->assertJsonPath('user.status', 'active');

        $token = $login->json('token');
        $tokenHash = hash('sha256', $token);

        $this->withToken($token)
            ->getJson('/api/mobile/me')
            ->assertOk()
            ->assertJsonPath('user.id', $buyer->id)
            ->assertJsonPath('user.email', $buyer->email)
            ->assertJsonPath('user.role', 'buyer');

        $this->withToken($token)
            ->postJson('/api/mobile/logout')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'token' => $tokenHash,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.signed_out',
            'auditable_id' => $buyer->id,
        ]);
    }

    public function test_rider_can_sign_in_using_the_server_role(): void
    {
        $logisticsUser = User::factory()->create([
            'role' => 'logistics',
            'status' => 'active',
        ]);
        $logisticsProfile = LogisticsProfile::query()->create([
            'user_id' => $logisticsUser->id,
            'legal_name' => 'Mobile Test Logistics',
            'display_name' => 'Mobile Test Logistics',
            'contact_phone' => '09170000000',
            'status' => 'active',
        ]);
        $rider = User::factory()->create([
            'email' => 'mobile.rider@example.test',
            'role' => 'rider',
            'status' => 'active',
        ]);
        RiderProfile::query()->create([
            'user_id' => $rider->id,
            'logistics_profile_id' => $logisticsProfile->id,
            'vehicle_type' => 'Motorcycle',
            'verification_status' => 'approved',
        ]);

        $response = $this->postJson('/api/mobile/login', [
            'email' => $rider->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('user.role', 'rider')
            ->assertJsonPath('user.status', 'active');

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_active_but_unapproved_rider_cannot_sign_in(): void
    {
        $logisticsUser = User::factory()->create([
            'role' => 'logistics',
            'status' => 'active',
        ]);
        $logisticsProfile = LogisticsProfile::query()->create([
            'user_id' => $logisticsUser->id,
            'legal_name' => 'Pending Rider Logistics',
            'display_name' => 'Pending Rider Logistics',
            'contact_phone' => '09170000000',
            'status' => 'active',
        ]);
        $rider = User::factory()->create([
            'email' => 'unapproved.mobile.rider@example.test',
            'role' => 'rider',
            'status' => 'active',
        ]);
        RiderProfile::query()->create([
            'user_id' => $rider->id,
            'logistics_profile_id' => $logisticsProfile->id,
            'vehicle_type' => 'Motorcycle',
            'verification_status' => 'pending',
        ]);

        $this->postJson('/api/mobile/login', [
            'email' => $rider->email,
            'password' => 'password',
        ])->assertForbidden();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $rider->id,
        ]);
    }

    public function test_login_rejects_inactive_accounts_and_non_mobile_roles(): void
    {
        foreach ([
            ['role' => 'buyer', 'status' => 'pending'],
            ['role' => 'buyer', 'status' => 'needs_revision'],
            ['role' => 'buyer', 'status' => 'rejected'],
            ['role' => 'buyer', 'status' => 'suspended'],
            ['role' => 'buyer', 'status' => 'deactivated'],
            ['role' => 'buyer', 'status' => 'banned'],
            ['role' => 'rider', 'status' => 'suspended'],
            ['role' => 'seller', 'status' => 'active'],
            ['role' => 'logistics', 'status' => 'active'],
        ] as $index => $account) {
            $user = User::factory()->create([
                'email' => "blocked-mobile-{$index}@example.test",
                ...$account,
            ]);

            $this->postJson('/api/mobile/login', [
                'email' => $user->email,
                'password' => 'password',
            ])->assertForbidden();

            $this->assertDatabaseMissing('personal_access_tokens', [
                'tokenable_id' => $user->id,
            ]);
        }
    }

    public function test_existing_mobile_tokens_are_revoked_when_the_account_becomes_ineligible(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);
        $plainTextToken = $buyer->createToken('bearly-mobile')->plainTextToken;
        $tokenHash = hash('sha256', $plainTextToken);

        $buyer->update(['status' => 'suspended']);

        $this->withToken($plainTextToken)
            ->getJson('/api/mobile/me')
            ->assertForbidden()
            ->assertJsonPath('account_status', 'suspended');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'token' => $tokenHash,
        ]);

        $this->withToken($plainTextToken)
            ->getJson('/api/user')
            ->assertForbidden();
    }

    public function test_invalid_credentials_do_not_issue_a_mobile_token(): void
    {
        $buyer = User::factory()->create([
            'email' => 'mobile.invalid@example.test',
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $this->postJson('/api/mobile/login', [
            'email' => $buyer->email,
            'password' => 'incorrect-password',
        ])->assertUnauthorized();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $buyer->id,
        ]);
    }
}
