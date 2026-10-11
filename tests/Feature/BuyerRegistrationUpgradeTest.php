<?php
namespace Tests\Feature;

use App\Mail\RegistrationCode;
use App\Services\EmailVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
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

    public function test_email_verification_endpoint_rejects_non_six_digit_codes(): void
    {
        $this->postJson(route('register.email.check'), [
            'email' => 'format@example.test',
            'code' => '48291',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }
}
