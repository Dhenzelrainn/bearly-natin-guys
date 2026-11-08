<?php

namespace Tests\Feature;

use App\Mail\RegistrationCode;
use App\Services\EmailVerificationService;
use App\Services\RegistrationDraftService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BuyerRegistrationUpgradeTest extends TestCase
{
    public function test_email_code_is_delivered_and_proof_is_bound_to_address(): void
    {
        config(['email-verification.enabled' => true, 'cache.default' => 'array', 'session.driver' => 'array']);
        Cache::flush();
        Mail::fake();
        $request = Request::create('/register/email/send', 'POST');
        $session = app('session')->driver();
        $session->start();
        $request->setLaravelSession($session);
        $service = app(EmailVerificationService::class);
        $result = $service->send($request, 'test@example.com');
        $this->assertArrayNotHasKey('code', $result);
        $code = '';
        Mail::assertSent(RegistrationCode::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return $mail->hasTo('test@example.com');
        });
        $service->check($request, 'test@example.com', $code);
        $this->assertNotEmpty($service->verifiedAt($request, 'test@example.com'));
        $this->expectException(ValidationException::class);
        $service->verifiedAt($request, 'different@example.com');
    }

    public function test_disabled_email_delivery_does_not_create_proof(): void
    {
        config(['email-verification.enabled' => false]);
        Mail::fake();
        $request = Request::create('/register/email/send', 'POST');
        $request->setLaravelSession(app('session')->driver());
        try {
            app(EmailVerificationService::class)->send($request, 'test@example.com');
            $this->fail('Disabled delivery must reject sending.');
        } catch (ValidationException) {
            Mail::assertNothingSent();
        }
    }

    public function test_email_code_expires_after_five_minutes(): void
    {
        config([
            'email-verification.enabled' => true,
            'cache.default' => 'array',
            'session.driver' => 'array',
        ]);
        Cache::flush();
        Mail::fake();

        $request = Request::create('/register/email/send', 'POST');
        $session = app('session')->driver();
        $session->start();
        $request->setLaravelSession($session);
        $service = app(EmailVerificationService::class);
        $service->send($request, 'expiry@example.test');

        $code = '';
        Mail::assertSent(RegistrationCode::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return $mail->hasTo('expiry@example.test');
        });

        $sentAt = Carbon::now();
        Carbon::setTestNow($sentAt->copy()->addSeconds(301));

        try {
            $service->check($request, 'expiry@example.test', $code);
            $this->fail('A code older than five minutes must expire.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'expired',
                strtolower($exception->errors()['email'][0])
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_resending_after_the_cooldown_invalidates_the_previous_code(): void
    {
        config([
            'email-verification.enabled' => true,
            'cache.default' => 'array',
            'session.driver' => 'array',
        ]);
        Cache::flush();
        Mail::fake();

        $request = Request::create('/register/email/send', 'POST');
        $session = app('session')->driver();
        $session->start();
        $request->setLaravelSession($session);
        $service = app(EmailVerificationService::class);
        $service->send($request, 'resend@example.test');

        $firstCode = '';
        Mail::assertSent(RegistrationCode::class, function ($mail) use (&$firstCode) {
            $firstCode = $mail->code;

            return $mail->hasTo('resend@example.test');
        });

        $sentAt = Carbon::now();
        Carbon::setTestNow($sentAt->copy()->addSeconds(61));

        try {
            $service->send($request, 'resend@example.test');

            $codes = [];
            Mail::assertSent(RegistrationCode::class, function ($mail) use (&$codes) {
                if ($mail->hasTo('resend@example.test')) {
                    $codes[] = $mail->code;
                }

                return true;
            });

            $this->assertCount(2, $codes);
            $this->assertNotSame($firstCode, $codes[1]);

            try {
                $service->check($request, 'resend@example.test', $firstCode);
                $this->fail('The previous code must be invalid after resending.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString(
                    'incorrect',
                    strtolower($exception->errors()['email'][0])
                );
            }

            $service->check($request, 'resend@example.test', $codes[1]);
            $this->assertNotEmpty(
                $service->verifiedAt($request, 'resend@example.test')
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_verified_email_proof_restores_for_the_same_browser_until_the_existing_expiry(): void
    {
        config([
            'email-verification.enabled' => true,
            'email-verification.proof_ttl' => 1800,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'mail.mailers.smtp.host' => 'smtp.test',
            'mail.from.address' => 'noreply@bearly.test',
        ]);
        Cache::flush();
        Mail::fake();

        $email = 'resume-proof@example.test';
        $browserToken = str_repeat('r', 64);
        $firstRequest = $this->otpRequest(
            '203.0.113.59',
            str_repeat('a', 40),
            $browserToken,
        );
        $service = app(EmailVerificationService::class);
        $service->send($firstRequest, $email);

        $code = '';
        Mail::assertSent(RegistrationCode::class, function ($mail) use (&$code, $email) {
            $code = $mail->code;

            return $mail->hasTo($email);
        });

        $service->check($firstRequest, $email, $code);
        $verifiedAt = Carbon::now();

        $resumedBrowserRequest = $this->otpRequest(
            '203.0.113.59',
            str_repeat('b', 40),
            $browserToken,
        );
        $status = $service->status($resumedBrowserRequest, $email);

        $this->assertTrue($status['verified']);
        $this->assertSame(1800, $status['expires_in']);
        $this->assertNotEmpty($service->verifiedAt($resumedBrowserRequest, $email));

        $otherBrowserRequest = $this->otpRequest(
            '203.0.113.59',
            str_repeat('c', 40),
            str_repeat('s', 64),
        );
        $this->assertFalse($service->status($otherBrowserRequest, $email)['verified']);

        Carbon::setTestNow($verifiedAt->copy()->addSeconds(1801));

        try {
            $this->assertFalse($service->status($resumedBrowserRequest, $email)['verified']);

            try {
                $service->verifiedAt($resumedBrowserRequest, $email);
                $this->fail('Expired proof must not permit registration submission.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_web_otp_send_allows_distinct_visitors_sharing_one_ip_above_the_old_limit(): void
    {
        config([
            'email-verification.enabled' => true,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'mail.mailers.smtp.host' => 'smtp.test',
            'mail.from.address' => 'noreply@bearly.test',
        ]);
        Cache::flush();
        Mail::fake();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.60']);

        for ($attempt = 1; $attempt <= 11; $attempt++) {
            $this->postJson(route('register.email.send'), [
                'email' => "shared-network-{$attempt}@example.test",
            ])
                ->assertOk()
                ->assertJsonPath('retry_after', 60);
        }
    }

    public function test_otp_cooldown_returns_a_specific_message_and_retry_after_header(): void
    {
        config([
            'email-verification.enabled' => true,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'mail.mailers.smtp.host' => 'smtp.test',
            'mail.from.address' => 'noreply@bearly.test',
        ]);
        Cache::flush();
        Mail::fake();

        $payload = ['email' => 'cooldown@example.test'];

        $this->postJson(route('register.email.send'), $payload)->assertOk();

        $response = $this->postJson(route('register.email.send'), $payload)
            ->assertStatus(429);

        $this->assertStringContainsString(
            'Please wait before requesting another verification code.',
            (string) $response->json('message'),
        );
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertStringContainsString('Try again in', (string) $response->json('message'));
    }

    public function test_otp_hourly_email_limit_returns_its_own_retry_after(): void
    {
        config([
            'email-verification.enabled' => true,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'mail.mailers.smtp.host' => 'smtp.test',
            'mail.from.address' => 'noreply@bearly.test',
            'email-verification.send_limit_per_email' => 5,
            'email-verification.send_limit_per_session' => 10,
            'email-verification.send_limit_per_ip' => 60,
            'email-verification.send_limit_window' => 3600,
        ]);
        Cache::flush();
        Mail::fake();

        $request = $this->otpRequest('203.0.113.61', str_repeat('1', 40));
        $service = app(EmailVerificationService::class);
        $sentAt = Carbon::parse('2026-11-08 10:00:00');
        Carbon::setTestNow($sentAt);

        try {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $service->send($request, 'hourly-cap@example.test');

                if ($attempt < 5) {
                    Carbon::setTestNow(Carbon::now()->addSeconds(61));
                }
            }

            try {
                $service->send($request, 'hourly-cap@example.test');
                $this->fail('The per-email hourly send limit must remain enforced.');
            } catch (HttpException $exception) {
                $this->assertSame(429, $exception->getStatusCode());
                $this->assertGreaterThan(0, (int) $exception->getHeaders()['Retry-After']);
                $this->assertStringContainsString(
                    'Too many verification codes were requested for this email.',
                    $exception->getMessage(),
                );
                $this->assertStringContainsString('Try again in', $exception->getMessage());
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_otp_session_and_ip_limits_remain_independent_backstops(): void
    {
        config([
            'email-verification.enabled' => true,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'mail.mailers.smtp.host' => 'smtp.test',
            'mail.from.address' => 'noreply@bearly.test',
            'email-verification.send_limit_per_email' => 5,
            'email-verification.send_limit_per_session' => 2,
            'email-verification.send_limit_per_ip' => 2,
            'email-verification.send_limit_window' => 3600,
        ]);
        Cache::flush();
        Mail::fake();

        $service = app(EmailVerificationService::class);
        $request = $this->otpRequest('203.0.113.62', str_repeat('2', 40));
        $service->send($request, 'session-one@example.test');
        $service->send($request, 'session-two@example.test');

        try {
            $service->send($request, 'session-three@example.test');
            $this->fail('The per-session send limit must remain enforced.');
        } catch (HttpException $exception) {
            $this->assertSame(429, $exception->getStatusCode());
            $this->assertStringContainsString('browser session', $exception->getMessage());
        }

        Cache::flush();
        $service = app(EmailVerificationService::class);
        $networkRequest = $this->otpRequest('203.0.113.63', str_repeat('3', 40));
        $service->send($networkRequest, 'network-one@example.test');
        $service->send($networkRequest, 'network-two@example.test');

        try {
            $service->send(
                $this->otpRequest('203.0.113.63', str_repeat('4', 40)),
                'network-three@example.test',
            );
            $this->fail('The per-IP hourly send limit must remain enforced.');
        } catch (HttpException $exception) {
            $this->assertSame(429, $exception->getStatusCode());
            $this->assertStringContainsString('from this network', $exception->getMessage());
        }

        $service->send(
            $this->otpRequest('203.0.113.64', str_repeat('5', 40)),
            'different-network@example.test',
        );
    }

    public function test_email_verification_endpoint_rejects_non_six_digit_codes(): void
    {
        $this->postJson(route('register.email.check'), [
            'email' => 'format@example.test',
            'code' => '48291',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_email_verification_status_endpoint_is_no_store_and_validates_the_address(): void
    {
        config(['email-verification.enabled' => false]);

        $this->postJson(route('register.email.status'), [
            'email' => 'not-an-email',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $response = $this->postJson(route('register.email.status'), [
            'email' => 'status@example.test',
        ])->assertOk()
            ->assertJsonPath('verified', false)
            ->assertJsonPath('expires_in', 0);

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
    }

    private function otpRequest(string $ip, string $sessionId, ?string $registrationToken = null): Request
    {
        $cookies = $registrationToken === null
            ? []
            : [RegistrationDraftService::COOKIE_NAME => $registrationToken];
        $request = Request::create('/register/email/send', 'POST', [], $cookies, [], [
            'REMOTE_ADDR' => $ip,
        ]);
        $session = app('session')->driver();
        $session->setId($sessionId);
        $request->setLaravelSession($session);

        return $request;
    }
}
