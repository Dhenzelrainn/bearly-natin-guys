<?php

namespace App\Services;

use App\Mail\RegistrationCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MobileEmailVerificationService
{
    private function digest(string $value): string
    {
        return hash_hmac(
            'sha256',
            $value,
            (string) config('app.key')
        );
    }

    private function verificationKey(string $verificationId): string
    {
        return 'mobile-email-verification:' . $this->digest($verificationId);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages([
            'email' => $message,
        ]);
    }

    private function driver(): string
    {
        if (
            ! config('email-verification.enabled')
            || ! config('mail.mailers.smtp.host')
            || ! config('mail.from.address')
        ) {
            $this->fail(
                'Email verification is not configured. Please contact Bearly support.'
            );
        }

        return 'smtp';
    }

    public function send(Request $request, string $email): array
    {
        $driver = $this->driver();

        $email = strtolower(trim($email));

        $emailKey = 'mobile-email-send-address:' . $this->digest($email);

        $ipKey = 'mobile-email-send-ip:' . $this->digest(
            (string) $request->ip()
        );

        return Cache::lock($emailKey . ':lock', 25)
            ->block(2, function () use (
                $email,
                $emailKey,
                $ipKey,
                $driver
            ) {
                if (RateLimiter::tooManyAttempts($emailKey, 5)) {
                    abort(
                        429,
                        'Too many email requests. Please try again later.'
                    );
                }

                if (RateLimiter::tooManyAttempts($ipKey, 10)) {
                    abort(
                        429,
                        'Too many email requests. Please try again later.'
                    );
                }

                if (
                    RateLimiter::tooManyAttempts(
                        $emailKey . ':cooldown',
                        1
                    )
                ) {
                    abort(
                        429,
                        'Please wait before requesting another code.'
                    );
                }

                RateLimiter::hit($emailKey, 3600);
                RateLimiter::hit($ipKey, 3600);

                RateLimiter::hit(
                    $emailKey . ':cooldown',
                    (int) config('email-verification.cooldown')
                );

                $verificationId = Str::random(64);

                $code = (string) random_int(100000, 999999);

                $state = [
                    'email' => $email,
                    'driver' => $driver,
                    'code_hash' => Hash::make($code),
                    'attempts' => 0,
                    'expires_at' => now()
                        ->addSeconds(
                            (int) config('email-verification.ttl')
                        )
                        ->timestamp,
                    'verified_until' => null,
                    'verified_at' => null,
                ];

                try {
                    Mail::mailer('smtp')
                        ->to($email)
                        ->send(new RegistrationCode($code));
                } catch (Throwable) {
                    $this->fail(
                        'We could not send the email. Please try again later or contact Bearly support.'
                    );
                }

                Cache::put(
                    $this->verificationKey($verificationId),
                    $state,
                    (int) config('email-verification.ttl')
                );

                return [
                    'message' =>
                        'Verification code sent. Check your inbox and spam folder.',

                    'verification_id' => $verificationId,

                    'retry_after' =>
                        (int) config('email-verification.cooldown'),

                    'expires_in' =>
                        (int) config('email-verification.ttl'),
                ];
            });
    }

    public function check(
        string $email,
        string $code,
        string $verificationId
    ): array {
        $email = strtolower(trim($email));

        $key = $this->verificationKey($verificationId);

        return Cache::lock($key . ':lock', 25)
            ->block(2, function () use (
                $email,
                $code,
                $key
            ) {
                $state = Cache::get($key);

                if (
                    ! $state
                    || ! hash_equals(
                        (string) $state['email'],
                        $email
                    )
                    || (int) $state['expires_at'] <= now()->timestamp
                ) {
                    $this->fail(
                        'Your code has expired or your email address changed. Request a new code.'
                    );
                }

                if (
                    ($state['verified_until'] ?? 0)
                    > now()->timestamp
                ) {
                    return [
                        'message' => 'Email address verified.',
                        'expires_in' =>
                            (int) $state['verified_until']
                            - now()->timestamp,
                    ];
                }

                if (
                    (int) $state['attempts']
                    >= (int) config(
                        'email-verification.max_attempts'
                    )
                ) {
                    $this->fail(
                        'Too many incorrect codes. Request a new code after the cooldown.'
                    );
                }

                $state['attempts']++;

                Cache::put(
                    $key,
                    $state,
                    max(
                        1,
                        (int) $state['expires_at']
                        - now()->timestamp
                    )
                );

                if (
                    $state['driver'] !== $this->driver()
                ) {
                    $this->fail(
                        'Verification settings changed. Request a new code.'
                    );
                }

                if (
                    ! Hash::check(
                        $code,
                        (string) $state['code_hash']
                    )
                ) {
                    $this->fail(
                        'That code is incorrect. Please check the email and try again.'
                    );
                }

                $state['verified_until'] = now()
                    ->addSeconds(
                        (int) config(
                            'email-verification.proof_ttl'
                        )
                    )
                    ->timestamp;

                $state['verified_at'] = now()
                    ->toDateTimeString();

                unset($state['code_hash']);

                Cache::put(
                    $key,
                    $state,
                    (int) config(
                        'email-verification.proof_ttl'
                    )
                );

                return [
                    'message' => 'Email address verified.',

                    'expires_in' =>
                        (int) config(
                            'email-verification.proof_ttl'
                        ),
                ];
            });
    }

    public function verifiedAt(
        string $email,
        string $verificationId
    ): string {
        $email = strtolower(trim($email));

        $state = Cache::get(
            $this->verificationKey($verificationId)
        );

        if (
            ! $state
            || ! hash_equals(
                (string) $state['email'],
                $email
            )
            || ($state['verified_until'] ?? 0)
                <= now()->timestamp
            || $state['driver'] !== $this->driver()
        ) {
            $this->fail(
                'Verify this email address before submitting your application.'
            );
        }

        return (string) $state['verified_at'];
    }

    public function forget(string $verificationId): void
    {
        Cache::forget(
            $this->verificationKey($verificationId)
        );
    }
}