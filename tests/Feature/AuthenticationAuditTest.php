<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class AuthenticationAuditTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        .'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

    private function buyerFixture(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Bea Buyer',
            'email' => 'bea.buyer@example.test',
            'role' => 'buyer',
            'status' => 'active',
        ], $attributes));
    }

    private function signInEvents(): int
    {
        return AuditLog::query()
            ->whereIn('action', ['auth.signed_in', 'auth.signed_out'])
            ->count();
    }

    public function test_web_password_login_records_a_successful_sign_in(): void
    {
        $password = 'correct-horse-battery-staple';

        $buyer = $this->buyerFixture(['password' => Hash::make($password)]);

        $this->withHeader('User-Agent', self::CHROME_USER_AGENT)
            ->post('/login', [
                'email' => 'Bea.Buyer@Example.test',
                'password' => $password,
            ])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($buyer);

        $log = AuditLog::query()->where('action', 'auth.signed_in')->sole();

        $this->assertSame($buyer->id, $log->actor_user_id);
        $this->assertSame('Authentication', $log->module);
        $this->assertSame('info', $log->severity);
        $this->assertSame('127.0.0.1', $log->ip_address);
        $this->assertSame(self::CHROME_USER_AGENT, $log->user_agent);
        $this->assertSame($buyer->getMorphClass(), $log->auditable_type);
        $this->assertSame($buyer->id, $log->auditable_id);
        $this->assertNull($log->old_values);
        $this->assertSame([
            'event' => 'signed_in',
            'channel' => 'web_password',
            'role' => 'buyer',
            'device' => 'Desktop',
            'browser' => 'Chrome 141',
            'os' => 'Windows 10/11',
            'location' => null,
        ], $log->new_values);

        $this->assertNotNull($log->created_at);
        $this->assertStringNotContainsString($password, (string) $log->description);
        $this->assertStringNotContainsString($password, (string) json_encode($log->new_values));
    }

    public function test_rejected_and_inactive_web_login_attempts_do_not_record_a_successful_sign_in(): void
    {
        $this->buyerFixture();

        $this->buyerFixture([
            'name' => 'Pending Buyer',
            'email' => 'pending.buyer@example.test',
            'status' => 'pending',
        ]);

        $this->post('/login', [
            'email' => 'bea.buyer@example.test',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->post('/login', [
            'email' => 'pending.buyer@example.test',
            'password' => 'password',
        ])->assertRedirect(route('application.pending'));

        $this->assertGuest();
        $this->assertSame(0, $this->signInEvents());
    }

    public function test_web_logout_records_a_signed_out_event_and_keeps_the_actor(): void
    {
        $buyer = $this->buyerFixture();

        $this->post('/login', [
            'email' => 'bea.buyer@example.test',
            'password' => 'password',
        ])->assertRedirect(route('home'));

        $this->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();

        $log = AuditLog::query()->where('action', 'auth.signed_out')->sole();

        $this->assertSame($buyer->id, $log->actor_user_id);
        $this->assertSame('Authentication', $log->module);
        $this->assertSame('web_password', $log->new_values['channel']);
        $this->assertSame('signed_out', $log->new_values['event']);
        $this->assertSame('buyer', $log->new_values['role']);
    }

    public function test_google_callback_login_records_the_google_channel(): void
    {
        $buyer = $this->buyerFixture([
            'name' => 'Bea Google',
            'email' => 'bea.google@example.test',
        ]);

        $googleUser = (new SocialiteUser)
            ->setRaw([
                'email_verified' => true,
                'given_name' => 'Bea',
                'family_name' => 'Google',
            ])
            ->map([
                'id' => 'google-user-456',
                'nickname' => 'bea.google',
                'name' => 'Bea Google',
                'email' => 'bea.google@example.test',
            ]);

        $driver = Mockery::mock();
        $driver->shouldReceive('user')->once()->andReturn($googleUser);

        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($driver);

        $this->get(route('google.callback'))->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($buyer);

        $login = AuditLog::query()->where('action', 'auth.signed_in')->sole();

        $this->assertSame($buyer->id, $login->actor_user_id);
        $this->assertSame('google', $login->new_values['channel']);
        $this->assertSame('buyer', $login->new_values['role']);
        $this->assertSame('Successful sign-in with Google.', $login->description);

        $this->post('/logout')->assertRedirect(route('login'));

        $logout = AuditLog::query()->where('action', 'auth.signed_out')->sole();

        $this->assertSame($buyer->id, $logout->actor_user_id);
        $this->assertSame('google', $logout->new_values['channel']);
    }

    public function test_mobile_login_records_a_successful_sign_in_without_storing_the_token(): void
    {
        $buyer = $this->buyerFixture(['email' => 'mobile.buyer@example.test']);

        $userAgent = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 '
            .'(KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36';

        $response = $this->withHeader('User-Agent', $userAgent)
            ->postJson('/api/mobile/login', [
                'email' => 'Mobile.Buyer@Example.test',
                'password' => 'password',
            ])
            ->assertOk()
            ->assertJsonPath('user.role', 'buyer');

        $token = (string) $response->json('token');

        $this->assertNotSame('', $token);

        $log = AuditLog::query()->where('action', 'auth.signed_in')->sole();

        $this->assertSame($buyer->id, $log->actor_user_id);
        $this->assertSame('Authentication', $log->module);
        $this->assertSame($userAgent, $log->user_agent);
        $this->assertSame([
            'event' => 'signed_in',
            'channel' => 'mobile_api',
            'role' => 'buyer',
            'device' => 'Mobile',
            'browser' => 'Chrome 130',
            'os' => 'Android 14',
            'location' => null,
        ], $log->new_values);

        $this->assertStringNotContainsString($token, (string) json_encode($log->toArray()));
        $this->assertSame(1, $buyer->tokens()->count());
    }

    public function test_mobile_logout_records_a_signed_out_event_and_revokes_the_token(): void
    {
        $buyer = $this->buyerFixture(['email' => 'mobile.logout@example.test']);

        $token = $buyer->createToken('bearly-mobile')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/mobile/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logout successful.');

        $this->assertSame(0, $buyer->tokens()->count());

        $log = AuditLog::query()->where('action', 'auth.signed_out')->sole();

        $this->assertSame($buyer->id, $log->actor_user_id);
        $this->assertSame('Authentication', $log->module);
        $this->assertSame('mobile_api', $log->new_values['channel']);
        $this->assertSame('signed_out', $log->new_values['event']);
        $this->assertSame('buyer', $log->new_values['role']);
        $this->assertStringNotContainsString($token, (string) json_encode($log->toArray()));
    }

    public function test_rejected_and_inactive_mobile_login_attempts_do_not_record_a_successful_sign_in(): void
    {
        $this->buyerFixture([
            'email' => 'seller.mobile@example.test',
            'role' => 'seller',
            'status' => 'active',
        ]);

        $this->buyerFixture([
            'email' => 'pending.mobile@example.test',
            'status' => 'pending',
        ]);

        $this->postJson('/api/mobile/login', [
            'email' => 'seller.mobile@example.test',
            'password' => 'password',
        ])->assertForbidden();

        $this->postJson('/api/mobile/login', [
            'email' => 'pending.mobile@example.test',
            'password' => 'password',
        ])->assertForbidden();

        $this->postJson('/api/mobile/login', [
            'email' => 'pending.mobile@example.test',
            'password' => 'wrong-password',
        ])->assertUnauthorized();

        $this->assertSame(0, $this->signInEvents());
    }

    public function test_admin_audit_log_page_shows_authentication_activity_with_the_real_role(): void
    {
        $this->buyerFixture(['name' => 'Bea Buyer']);

        $admin = $this->buyerFixture([
            'name' => 'Maria Admin',
            'email' => 'maria.admin@example.test',
            'role' => 'admin',
        ]);

        $this->withHeader('User-Agent', self::CHROME_USER_AGENT)
            ->post('/login', [
                'email' => 'bea.buyer@example.test',
                'password' => 'password',
            ])->assertRedirect(route('home'));

        $this->actingAs($admin)
            ->get(route('admin.audit-logs'))
            ->assertOk()
            ->assertSee('Signed in')
            ->assertSee('Authentication')
            ->assertSee('Bea Buyer')
            ->assertSee('Buyer')
            ->assertSee('Web (email and password)')
            ->assertSee('Chrome 141')
            ->assertSee('Windows 10/11')
            ->assertSee('Approximate location unavailable')
            ->assertSee('Account role')
            ->assertDontSee('Administrator role');
    }

    public function test_audit_log_page_is_admin_only(): void
    {
        $this->actingAs($this->buyerFixture())
            ->get(route('admin.audit-logs'))
            ->assertForbidden();
    }
}
