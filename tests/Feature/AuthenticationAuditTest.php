<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\RememberedAccount;
use App\Models\User;
use App\Services\UserSessionRevocationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    private function authenticationAuditLog(User $user, string $action = 'auth.signed_in'): AuditLog
    {
        return AuditLog::query()->create([
            'actor_user_id' => $user->id,
            'action' => $action,
            'module' => 'Authentication',
            'auditable_type' => $user->getMorphClass(),
            'auditable_id' => $user->id,
            'description' => 'Authentication activity for test coverage.',
            'new_values' => [
                'event' => $action === 'auth.signed_out' ? 'signed_out' : 'signed_in',
                'channel' => 'web_password',
                'role' => $user->role,
            ],
            'ip_address' => '127.0.0.1',
            'user_agent' => self::CHROME_USER_AGENT,
            'severity' => 'info',
            'created_at' => now(),
        ]);
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

        $this->withToken($token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonMissingPath('auth_session_version');
    }

    public function test_mobile_login_cannot_issue_a_token_when_force_logout_wins_the_race(): void
    {
        $buyer = $this->buyerFixture(['email' => 'mobile.revoked@example.test']);
        $advanceVersionAfterPasswordLookup = true;

        DB::listen(function (QueryExecuted $query) use ($buyer, &$advanceVersionAfterPasswordLookup): void {
            if (
                ! $advanceVersionAfterPasswordLookup
                || ! str_contains(strtolower($query->sql), 'lower(email)')
            ) {
                return;
            }

            $advanceVersionAfterPasswordLookup = false;
            DB::table('users')
                ->where('id', $buyer->id)
                ->update(['auth_session_version' => DB::raw('auth_session_version + 1')]);
        });

        $this->postJson('/api/mobile/login', [
            'email' => 'mobile.revoked@example.test',
            'password' => 'password',
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This sign-in was interrupted by an account security change. Please submit your credentials again.');

        $this->assertSame(1, $buyer->fresh()->auth_session_version);
        $this->assertSame(0, $buyer->tokens()->count());
        $this->assertSame(0, $this->signInEvents());
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

    public function test_admin_can_force_logout_a_user_from_an_authentication_audit_entry(): void
    {
        $buyer = $this->buyerFixture([
            'name' => 'Bea Buyer',
            'email' => 'force.logout@example.test',
            'remember_token' => 'existing-remember-token',
        ]);
        $admin = $this->buyerFixture([
            'name' => 'Maria Admin',
            'email' => 'force.logout.admin@example.test',
            'role' => 'admin',
        ]);
        $authLog = $this->authenticationAuditLog($buyer);

        $firstMobileToken = $buyer->createToken('first-mobile-device')->plainTextToken;
        $buyer->createToken('second-mobile-device');
        RememberedAccount::query()->create([
            'user_id' => $buyer->id,
            'token_hash' => hash('sha256', 'remembered-sign-in-one'),
            'expires_at' => now()->addDays(7),
            'last_used_at' => now(),
        ]);
        RememberedAccount::query()->create([
            'user_id' => $buyer->id,
            'token_hash' => hash('sha256', 'remembered-sign-in-two'),
            'expires_at' => now()->addDays(7),
            'last_used_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.audit-logs'))
            ->assertOk()
            ->assertSee('Sign out all devices')
            ->assertSee('Bea Buyer');

        $this->post(route('admin.audit-logs.force-logout', $authLog))
            ->assertRedirect(route('admin.audit-logs'))
            ->assertSessionHas('success');

        $buyer->refresh();
        $this->assertSame(1, $buyer->auth_session_version);
        $this->assertNotSame('existing-remember-token', $buyer->remember_token);
        $this->assertSame(0, $buyer->tokens()->count());
        $this->assertSame(0, $buyer->rememberedAccounts()->count());

        $this->actingAs($buyer->fresh())
            ->withSession([
                UserSessionRevocationService::SESSION_VERSION_KEY => 0,
            ])
            ->get(route('home'))
            ->assertRedirect(route('login'));

        $this->assertGuest();

        $this->withToken($firstMobileToken)
            ->getJson('/api/user')
            ->assertUnauthorized();

        $forceLogout = AuditLog::query()->where('action', 'auth.force_signed_out')->sole();
        $this->assertSame($admin->id, $forceLogout->actor_user_id);
        $this->assertSame('Authentication', $forceLogout->module);
        $this->assertSame($buyer->getMorphClass(), $forceLogout->auditable_type);
        $this->assertSame($buyer->id, $forceLogout->auditable_id);
        $this->assertSame('warning', $forceLogout->severity);
        $this->assertSame('force_signed_out', $forceLogout->new_values['event']);
        $this->assertSame('all_devices', $forceLogout->new_values['scope']);
        $this->assertTrue($forceLogout->new_values['web_sessions_invalidated']);
        $this->assertSame(2, $forceLogout->new_values['mobile_api_tokens_revoked']);
        $this->assertSame(2, $forceLogout->new_values['remembered_sign_ins_revoked']);
        $this->assertTrue($forceLogout->new_values['remember_token_rotated']);
    }

    public function test_stale_or_unmarked_web_sessions_are_rejected_after_force_logout_but_new_login_works(): void
    {
        $buyer = $this->buyerFixture(['email' => 'revoked.session@example.test']);
        $buyer->fill(['auth_session_version' => 1]);
        $buyer->refresh();
        $this->assertSame(0, $buyer->auth_session_version);
        $buyer->forceFill(['auth_session_version' => 1])->save();

        $this->actingAs($buyer)
            ->withSession([
                UserSessionRevocationService::SESSION_VERSION_KEY => 0,
            ])
            ->get(route('home'))
            ->assertRedirect(route('login'));

        $this->assertGuest();

        $this->actingAs($buyer->fresh())
            ->withSession([])
            ->get(route('home'))
            ->assertRedirect(route('login'));

        $this->assertGuest();

        $this->post('/login', [
            'email' => 'revoked.session@example.test',
            'password' => 'password',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($buyer);

        $this->get(route('home'))
            ->assertOk()
            ->assertSessionHas(UserSessionRevocationService::SESSION_VERSION_KEY, 1);
    }

    public function test_force_logout_route_rejects_non_auth_audit_records(): void
    {
        $buyer = $this->buyerFixture();
        $admin = $this->buyerFixture([
            'name' => 'Maria Admin',
            'email' => 'non-auth.log.admin@example.test',
            'role' => 'admin',
        ]);
        $log = AuditLog::query()->create([
            'actor_user_id' => $admin->id,
            'action' => 'product.updated',
            'module' => 'Products',
            'auditable_type' => $buyer->getMorphClass(),
            'auditable_id' => $buyer->id,
            'description' => 'A non-authentication audit record.',
            'new_values' => [],
            'ip_address' => '127.0.0.1',
            'user_agent' => self::CHROME_USER_AGENT,
            'severity' => 'info',
            'created_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.audit-logs.force-logout', $log))
            ->assertNotFound();

        $this->assertSame(0, $buyer->fresh()->auth_session_version);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.force_signed_out']);
    }

    public function test_audit_log_page_is_admin_only(): void
    {
        $buyer = $this->buyerFixture();

        $this->actingAs($buyer)
            ->get(route('admin.audit-logs'))
            ->assertForbidden();

        $log = $this->authenticationAuditLog($buyer);

        $this->actingAs($buyer)
            ->post(route('admin.audit-logs.force-logout', $log))
            ->assertForbidden();
    }
}
