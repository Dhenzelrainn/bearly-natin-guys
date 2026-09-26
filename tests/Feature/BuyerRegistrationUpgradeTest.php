<?php
namespace Tests\Feature;

use App\Mail\RegistrationCode;
use App\Services\EmailVerificationService;
use Illuminate\Http\Request;
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
}
