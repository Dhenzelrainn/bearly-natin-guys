<?php

namespace Tests\Feature;

use App\Models\RememberedAccount;
use App\Models\User;
use App\Services\RememberedAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class RememberedAccountLoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'remembered-login-test-password';

    private function account(string $name, string $email): User
    {
        return User::factory()->create([
            'name' => $name,
            'first_name' => explode(' ', $name)[0],
            'last_name' => explode(' ', $name)[1] ?? 'Buyer',
            'email' => $email,
            'password' => Hash::make(self::PASSWORD),
            'role' => 'buyer',
            'status' => 'active',
        ]);
    }

    private function passwordLogin(User $user, bool $remember = true)
    {
        $payload = [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ];

        if ($remember) {
            $payload['remember'] = '1';
        }

        return $this->post(route('login.submit'), $payload);
    }

    private function rememberedCookie($response): Cookie
    {
        $cookie = $response->getCookie(RememberedAccountService::COOKIE_NAME);
        $this->assertNotNull($cookie);

        return $cookie;
    }

    public function test_password_login_remember_opt_in_saves_an_http_only_browser_account_and_shows_picker(): void
    {
        $user = $this->account('Morgan Lee', 'morgan@example.test');

        $response = $this->passwordLogin($user)->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('remembered_accounts', [
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseCount('remembered_accounts', 1);

        $cookie = $this->rememberedCookie($response);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame($response->baseRequest->isSecure(), $cookie->isSecure());
        $this->assertStringNotContainsString($user->email, $cookie->getValue());

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->withCookie(RememberedAccountService::COOKIE_NAME, $cookie->getValue())
            ->get(route('login'))
            ->assertOk()
            ->assertSee('Recent Accounts')
            ->assertSee('Morgan Lee')
            ->assertSee('morgan@example.test')
            ->assertSee('Use another account')
            ->assertSee('Clear saved accounts on this browser')
            ->assertSee(route('login.recent', $user->id), false);
    }

    public function test_recent_account_one_tap_login_rotates_credential_and_logout_keeps_it(): void
    {
        $user = $this->account('Morgan Lee', 'morgan@example.test');
        $initialLogin = $this->passwordLogin($user)->assertRedirect(route('home'));
        $initialCookie = $this->rememberedCookie($initialLogin);

        $this->post(route('logout'))->assertRedirect(route('login'));

        $oneTapLogin = $this->withCookie(RememberedAccountService::COOKIE_NAME, $initialCookie->getValue())
            ->post(route('login.recent', $user->id))
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'auth.signed_in',
        ]);

        $rotatedCookie = $this->rememberedCookie($oneTapLogin);
        $this->assertNotSame($initialCookie->getValue(), $rotatedCookie->getValue());

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->withCookie(RememberedAccountService::COOKIE_NAME, $initialCookie->getValue())
            ->post(route('login.recent', $user->id))
            ->assertRedirect(route('login'));
        $this->assertGuest();

        $this->withCookie(RememberedAccountService::COOKIE_NAME, $rotatedCookie->getValue())
            ->post(route('login.recent', $user->id))
            ->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_unchecked_remember_me_does_not_create_a_saved_account(): void
    {
        $user = $this->account('Morgan Lee', 'morgan@example.test');

        $response = $this->passwordLogin($user, remember: false)
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('remembered_accounts', 0);
        $this->assertNull($response->getCookie(RememberedAccountService::COOKIE_NAME));
    }

    public function test_google_sign_in_remember_consent_adds_account_to_the_picker(): void
    {
        $user = $this->account('Bea Google', 'bea.google@example.test');
        $user->update(['google_id' => 'google-user-remembered']);

        $googleUser = (new SocialiteUser)
            ->setRaw(['email_verified' => true])
            ->map([
                'id' => 'google-user-remembered',
                'nickname' => 'bea.google',
                'name' => 'Bea Google',
                'email' => 'bea.google@example.test',
            ]);

        $driver = Mockery::mock();
        $driver->shouldReceive('redirect')->once()->andReturn(redirect('/fake-google-oauth'));
        $driver->shouldReceive('user')->once()->andReturn($googleUser);
        Socialite::shouldReceive('driver')->with('google')->twice()->andReturn($driver);

        $this->post(route('google.remembered.redirect'), ['remember' => '1'])
            ->assertRedirect('/fake-google-oauth');

        $callback = $this->get(route('google.callback'))
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('remembered_accounts', ['user_id' => $user->id]);
        $this->assertNotNull($callback->getCookie(RememberedAccountService::COOKIE_NAME));
    }

    public function test_saved_account_id_without_its_cookie_credential_cannot_sign_in_or_remove_an_account(): void
    {
        $first = $this->account('Morgan Lee', 'morgan@example.test');
        $second = $this->account('Alex Smith', 'alex@example.test');
        $login = $this->passwordLogin($first)->assertRedirect(route('home'));
        $cookie = $this->rememberedCookie($login);
        $this->post(route('logout'));

        $this->withCookie(RememberedAccountService::COOKIE_NAME, $cookie->getValue())
            ->post(route('login.recent', $second->id))
            ->assertRedirect(route('login'));
        $this->assertGuest();

        $this->withCookie(RememberedAccountService::COOKIE_NAME, $cookie->getValue())
            ->post(route('login.recent.forget', $second->id))
            ->assertRedirect(route('login'));
        $this->assertDatabaseHas('remembered_accounts', ['user_id' => $first->id]);
        $this->assertDatabaseMissing('remembered_accounts', ['user_id' => $second->id]);
    }

    public function test_removing_and_clearing_saved_accounts_revokes_their_server_credentials(): void
    {
        $first = $this->account('Morgan Lee', 'morgan@example.test');
        $second = $this->account('Alex Smith', 'alex@example.test');

        $firstLogin = $this->passwordLogin($first)->assertRedirect(route('home'));
        $firstCookie = $this->rememberedCookie($firstLogin);
        $this->post(route('logout'));

        $secondLogin = $this->withCookie(RememberedAccountService::COOKIE_NAME, $firstCookie->getValue())
            ->passwordLogin($second)
            ->assertRedirect(route('home'));
        $bothCookie = $this->rememberedCookie($secondLogin);
        $this->post(route('logout'));

        $this->withCookie(RememberedAccountService::COOKIE_NAME, $bothCookie->getValue())
            ->get(route('login'))
            ->assertSee('Morgan Lee')
            ->assertSee('Alex Smith');

        $removeResponse = $this->withCookie(RememberedAccountService::COOKIE_NAME, $bothCookie->getValue())
            ->post(route('login.recent.forget', $first->id))
            ->assertRedirect(route('login'));
        $remainingCookie = $this->rememberedCookie($removeResponse);

        $this->assertDatabaseMissing('remembered_accounts', ['user_id' => $first->id]);
        $this->assertDatabaseHas('remembered_accounts', ['user_id' => $second->id]);

        $clearResponse = $this->withCookie(RememberedAccountService::COOKIE_NAME, $remainingCookie->getValue())
            ->post(route('login.recent.clear'))
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('remembered_accounts', 0);
        $this->assertNotNull($clearResponse->getCookie(RememberedAccountService::COOKIE_NAME));
    }

    public function test_password_change_revokes_all_remembered_account_credentials(): void
    {
        $user = $this->account('Morgan Lee', 'morgan@example.test');
        $this->passwordLogin($user)->assertRedirect(route('home'));

        $this->assertDatabaseHas('remembered_accounts', ['user_id' => $user->id]);

        $user->update(['password' => Hash::make('a-new-strong-password')]);

        $this->assertDatabaseMissing('remembered_accounts', ['user_id' => $user->id]);
    }
}
