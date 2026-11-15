<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class BuyerPasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_in_buyer_can_reset_their_own_password_and_is_signed_out(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'email' => 'buyer@example.test',
        ]);
        $token = Password::createToken($buyer);
        $newPassword = 'NewBuyerPassword123';

        $this->actingAs($buyer)
            ->get(route('password.reset', [
                'token' => $token,
                'email' => $buyer->email,
            ]))
            ->assertOk()
            ->assertViewIs('auth.reset-password');

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $buyer->email,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Your password has been updated. You can sign in with your new password.');

        $this->assertGuest();
        $this->assertTrue(Hash::check($newPassword, $buyer->fresh()->password));
    }

    public function test_signed_in_buyer_cannot_open_or_submit_a_reset_link_for_another_account(): void
    {
        $signedInBuyer = User::factory()->create([
            'role' => 'buyer',
            'email' => 'signed-in@example.test',
        ]);
        $resetTarget = User::factory()->create([
            'role' => 'buyer',
            'email' => 'other-buyer@example.test',
        ]);
        $originalPasswordHash = $resetTarget->password;
        $token = Password::createToken($resetTarget);
        $newPassword = 'NotForThisAccount123';

        $this->actingAs($signedInBuyer)
            ->get(route('password.reset', [
                'token' => $token,
                'email' => $resetTarget->email,
            ]))
            ->assertForbidden();

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $resetTarget->email,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertForbidden();

        $this->assertSame($originalPasswordHash, $resetTarget->fresh()->password);
    }

    public function test_guest_can_complete_a_valid_password_reset(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'email' => 'guest-reset@example.test',
        ]);
        $token = Password::createToken($buyer);
        $newPassword = 'GuestResetPassword123';

        $this->get(route('password.reset', [
            'token' => $token,
            'email' => $buyer->email,
        ]))->assertOk();

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $buyer->email,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertTrue(Hash::check($newPassword, $buyer->fresh()->password));
    }

    public function test_invalid_and_expired_reset_tokens_are_rejected(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'email' => 'expired-reset@example.test',
        ]);
        $newPassword = 'RejectedResetPassword123';
        $resetUrl = route('password.reset', [
            'token' => 'invalid-token',
            'email' => $buyer->email,
        ]);

        $this->from($resetUrl)
            ->post(route('password.update'), [
                'token' => 'invalid-token',
                'email' => $buyer->email,
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ])
            ->assertRedirect($resetUrl)
            ->assertSessionHasErrors('email');

        $expiredToken = Password::createToken($buyer);
        $this->travel((int) config('auth.passwords.users.expire') + 1)->minutes();

        $this->from(route('password.reset', [
            'token' => $expiredToken,
            'email' => $buyer->email,
        ]))
            ->post(route('password.update'), [
                'token' => $expiredToken,
                'email' => $buyer->email,
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ])
            ->assertSessionHasErrors('email');

        $this->assertFalse(Hash::check($newPassword, $buyer->fresh()->password));
    }

    public function test_forgot_password_request_remains_guest_only(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer']);

        $this->actingAs($buyer)
            ->get(route('password.request'))
            ->assertRedirect(route('home'));
    }

    public function test_signed_in_admin_keeps_the_existing_dashboard_redirect_for_reset_links(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email' => 'admin@example.test',
        ]);

        $this->actingAs($admin)
            ->get(route('password.reset', [
                'token' => 'reset-token',
                'email' => $admin->email,
            ]))
            ->assertRedirect(route('admin.dashboard'));

        $this->post(route('password.update'), [])
            ->assertRedirect(route('admin.dashboard'));
    }
}
