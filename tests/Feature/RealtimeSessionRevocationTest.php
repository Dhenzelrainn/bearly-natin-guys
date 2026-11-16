<?php

namespace Tests\Feature;

use App\Events\UserSessionsRevoked;
use App\Models\AuditLog;
use App\Models\RememberedAccount;
use App\Models\User;
use App\Services\UserSessionRevocationService;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RealtimeSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'buyer', int $version = 0): User
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active']);
        $user->forceFill(['auth_session_version' => $version])->save();

        return $user;
    }

    public static function roles(): array
    {
        return array_map(fn (string $role): array => [$role], [
            'buyer', 'seller', 'admin', 'logistics', 'rider',
        ]);
    }

    #[DataProvider('roles')]
    public function test_current_sessions_can_be_checked_for_every_role_without_exposing_credentials(string $role): void
    {
        $user = $this->user($role, 2);

        $this->actingAs($user)
            ->withSession([UserSessionRevocationService::SESSION_VERSION_KEY => 2])
            ->getJson(route('auth.session.status', ['user_id' => 999999]))
            ->assertOk()
            ->assertExactJson([
                'data' => ['authenticated' => true, 'user_id' => $user->id],
                'message' => 'Your session is active.',
            ])
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertCookieMissing(config('session.cookie'));

        $identity = view('partials.realtime-identity')->render();
        $this->assertStringContainsString('session_status_url', $identity);
        $this->assertStringContainsString('sign_in_url', $identity);
        $this->assertStringNotContainsString('auth_session_version', $identity);
    }

    public function test_guest_status_is_no_store_json_even_without_an_accept_header(): void
    {
        $this->get(route('auth.session.status'))
            ->assertUnauthorized()
            ->assertJsonPath('data', null)
            ->assertJsonPath('code', 'UNAUTHENTICATED')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertCookieMissing(config('session.cookie'));
    }

    public function test_revoked_status_is_a_json_401_and_new_login_remains_valid(): void
    {
        $user = $this->user(version: 1);

        $this->actingAs($user)
            ->withSession([UserSessionRevocationService::SESSION_VERSION_KEY => 0])
            ->get(route('auth.session.status'))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'AUTH_SESSION_REVOKED')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertGuest();

        $this->post(route('login.submit'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('home'));

        $this->getJson(route('auth.session.status'))
            ->assertOk()
            ->assertJsonPath('data.user_id', $user->id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_other_json_requests_also_receive_401_instead_of_a_login_html_redirect(): void
    {
        $user = $this->user(version: 1);

        $this->actingAs($user)
            ->withSession([UserSessionRevocationService::SESSION_VERSION_KEY => 0])
            ->getJson(route('notifications.index'))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'AUTH_SESSION_REVOKED');

        $this->assertGuest();
    }

    public function test_legacy_unmarked_sessions_are_accepted_only_before_the_first_revocation(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->getJson(route('auth.session.status'))
            ->assertOk();

        $this->flushSession();
        $user->forceFill(['auth_session_version' => 1])->save();

        $this->actingAs($user->fresh())
            ->getJson(route('auth.session.status'))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'AUTH_SESSION_REVOKED');
    }

    public function test_event_is_private_minimal_and_dispatched_only_after_the_outer_transaction_commits(): void
    {
        $target = $this->user();
        $actor = $this->user('admin');
        Event::fake([UserSessionsRevoked::class]);

        DB::beginTransaction();
        app(UserSessionRevocationService::class)->forceLogoutAll($target, $actor, Request::create('/'));
        Event::assertNotDispatched(UserSessionsRevoked::class);
        DB::commit();

        Event::assertDispatchedTimes(UserSessionsRevoked::class, 1);
        Event::assertDispatched(UserSessionsRevoked::class, function (UserSessionsRevoked $event) use ($target): bool {
            $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
            $this->assertSame(['private-users.'.$target->id], array_map(
                fn ($channel): string => $channel->name,
                $event->broadcastOn(),
            ));
            $this->assertSame('auth.sessions-revoked', $event->broadcastAs());
            $this->assertSame(['scope' => 'all_devices'], $event->broadcastWith());

            return $event->userId === $target->id;
        });
    }

    public function test_rolled_back_revocation_never_broadcasts(): void
    {
        $target = $this->user();
        $actor = $this->user('admin');
        $target->createToken('still-valid');
        Event::fake([UserSessionsRevoked::class]);

        DB::beginTransaction();
        app(UserSessionRevocationService::class)->forceLogoutAll($target, $actor, Request::create('/'));
        DB::rollBack();

        Event::assertNotDispatched(UserSessionsRevoked::class);
        $this->assertSame(0, $target->fresh()->auth_session_version);
        $this->assertSame(1, $target->tokens()->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.force_signed_out']);
    }

    public function test_broadcast_outage_does_not_fail_admin_revocation_or_preserve_sign_in_credentials(): void
    {
        $target = $this->user();
        $actor = $this->user('admin');
        $target->createToken('revoked-mobile-token');
        RememberedAccount::query()->create([
            'user_id' => $target->id,
            'token_hash' => hash('sha256', 'revoked-remembered-account'),
            'expires_at' => now()->addDay(),
        ]);
        $auditLog = AuditLog::query()->create([
            'actor_user_id' => $target->id,
            'action' => 'auth.signed_in',
            'module' => 'Authentication',
            'auditable_type' => $target->getMorphClass(),
            'auditable_id' => $target->id,
            'severity' => 'info',
            'created_at' => now(),
        ]);

        Log::spy();
        Event::listen(UserSessionsRevoked::class, function (): void {
            throw new RuntimeException('Realtime unavailable.');
        });

        $this->actingAs($actor)
            ->post(route('admin.audit-logs.force-logout', $auditLog))
            ->assertRedirect(route('admin.audit-logs'))
            ->assertSessionHas('success');

        $this->assertSame(1, $target->fresh()->auth_session_version);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertSame(0, $target->rememberedAccounts()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.force_signed_out',
            'actor_user_id' => $actor->id,
            'auditable_id' => $target->id,
        ]);
        Log::shouldHaveReceived('notice')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Bearly realtime session revocation broadcast failed.'
                && $context === ['user_id' => $target->id, 'exception' => RuntimeException::class],
        );
    }

    public function test_revoked_sessions_cannot_reauthorize_private_channels(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        require base_path('routes/channels.php');

        $owner = $this->user(version: 1);
        $other = $this->user();

        $this->actingAs($owner)
            ->withSession([UserSessionRevocationService::SESSION_VERSION_KEY => 1])
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-users.'.$owner->id,
            ])->assertOk();

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-users.'.$other->id,
        ])->assertForbidden();

        $this->withSession([UserSessionRevocationService::SESSION_VERSION_KEY => 0])
            ->postJson('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => 'private-users.'.$owner->id,
            ])->assertUnauthorized()->assertJsonPath('code', 'AUTH_SESSION_REVOKED');
    }

    private function databaseSession(User $user, int $minutesAgo = 2, bool $encrypted = false): array
    {
        config([
            'session.driver' => 'database',
            'session.connection' => 'sqlite',
            'session.table' => 'sessions',
            'session.encrypt' => $encrypted,
            'session.lifetime' => 10,
        ]);
        // Bearly does not migrate Laravel's optional session table. Exercise
        // this supported driver in the isolated SQLite :memory: database only.
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity');
        });
        $sessionId = Str::random(40);
        $attributes = [
            Auth::guard('web')->getName() => $user->id,
            UserSessionRevocationService::SESSION_VERSION_KEY => 0,
            '_token' => Str::random(40),
            '_previous' => ['url' => url('/cart')],
            '_flash' => ['old' => ['status'], 'new' => []],
            'status' => 'Keep this feedback for the next actual page.',
        ];
        $data = serialize($attributes);
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'payload' => base64_encode($encrypted ? app('encrypter')->encrypt($data) : $data),
            'last_activity' => now()->subMinutes($minutesAgo)->timestamp,
        ]);
        $this->withCookie(config('session.cookie'), $sessionId)->withCredentials();

        return [$sessionId, (array) DB::table('sessions')->find($sessionId)];
    }

    public static function encryptionModes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('encryptionModes')]
    public function test_status_does_not_renew_database_session_or_consume_flash_and_normal_pages_still_save(bool $encrypted): void
    {
        [$sessionId, $before] = $this->databaseSession($this->user(), encrypted: $encrypted);

        $this->getJson(route('auth.session.status'))
            ->assertOk()
            ->assertCookieMissing(config('session.cookie'));

        $this->assertSame($before, (array) DB::table('sessions')->find($sessionId));

        $this->get('/about')->assertOk()->assertCookie(config('session.cookie'));
        $after = DB::table('sessions')->find($sessionId);
        $this->assertGreaterThan($before['last_activity'], $after->last_activity);
        $data = base64_decode($after->payload);
        $this->assertArrayNotHasKey('status', unserialize($encrypted ? app('encrypter')->decrypt($data) : $data));
    }

    public function test_status_does_not_renew_the_cookie_session_driver(): void
    {
        $user = $this->user();
        config(['session.driver' => 'cookie', 'session.encrypt' => false]);
        $sessionId = Str::random(40);
        $data = serialize([
            Auth::guard('web')->getName() => $user->id,
            UserSessionRevocationService::SESSION_VERSION_KEY => 0,
            '_token' => Str::random(40),
        ]);

        $this->withCookies([
            config('session.cookie') => $sessionId,
            $sessionId => json_encode(['data' => $data, 'expires' => now()->addMinutes(10)->timestamp]),
        ])->withCredentials()
            ->getJson(route('auth.session.status'))
            ->assertOk()
            ->assertJsonPath('data.user_id', $user->id)
            ->assertCookieMissing($sessionId)
            ->assertCookieMissing(config('session.cookie'));
    }

    public function test_session_blocking_on_existing_registration_routes_keeps_the_cache_resolver(): void
    {
        $this->postJson(route('register.email.send'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_expired_database_session_cannot_be_kept_alive_by_monitoring(): void
    {
        [$sessionId, $before] = $this->databaseSession($this->user(), 11);

        $this->getJson(route('auth.session.status'))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED')
            ->assertCookieMissing(config('session.cookie'));

        $this->assertSame($before, (array) DB::table('sessions')->find($sessionId));
    }

    public function test_status_destroys_a_revoked_persistent_session(): void
    {
        $user = $this->user(version: 1);
        [$sessionId] = $this->databaseSession($user);

        $this->getJson(route('auth.session.status'))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'AUTH_SESSION_REVOKED');

        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
    }

    public function test_login_page_explains_session_end_without_depending_on_a_shared_one_time_flash(): void
    {
        $this->get(route('login', ['session' => 'ended']))
            ->assertOk()
            ->assertSee('Your session has ended. Please sign in again.');

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Your session has ended. Please sign in again.');
    }
}
