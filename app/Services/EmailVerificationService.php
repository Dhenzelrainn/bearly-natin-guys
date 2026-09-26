<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\RegistrationCode;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmailVerificationService
{
    public function key(Request $request): string
    {
        return 'buyer-email:'.$this->digest($request->session()->getId());
    }

    private function digest(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['email' => $message]);
    }

    private function driver(): string
    {
        // Use a dedicated SMTP transport. Never silently fall back to a log mailer.
        if (! config('email-verification.enabled') || ! config('mail.mailers.smtp.host') ||
            ! config('mail.from.address')) {
            $this->fail('Email verification is not configured. Please contact Bearly support.');
        }
        return 'smtp';
    }

    public function send(Request $request, string $email): array
    {
        $driver = $this->driver();
        $emailKey = 'buyer-email-send-address:'.$this->digest($email);
        return Cache::lock($emailKey.':lock', 25)->block(2, function () use ($request, $email, $emailKey, $driver) {
            foreach ([$emailKey => 5, 'buyer-email-send-ip:'.$this->digest($request->ip()) => 10] as $key => $limit) {
                if (RateLimiter::tooManyAttempts($key, $limit)) {
                    abort(429, 'Too many email requests. Please try again later.');
                }
            }
            if (RateLimiter::tooManyAttempts($emailKey.':cooldown', 1)) {
                abort(429, 'Please wait 60 seconds before requesting another code.');
            }
            RateLimiter::hit($emailKey, 3600);
            RateLimiter::hit('buyer-email-send-ip:'.$this->digest($request->ip()), 3600);
            RateLimiter::hit($emailKey.':cooldown', config('email-verification.cooldown'));
            Cache::forget($this->key($request));
            $state = ['email' => $email, 'driver' => $driver, 'attempts' => 0,
                'expires_at' => now()->addSeconds(config('email-verification.ttl'))->timestamp,
                'verified_until' => null, 'verified_at' => null];
            $code = (string) random_int(100000, 999999);
            $state['code_hash'] = Hash::make($code);
            try {
                Mail::mailer('smtp')->to($email)->send(new RegistrationCode($code));
            } catch (Throwable) {
                // Never expose SMTP credentials or OTPs in responses or logs.
                $this->fail('We could not send the email. Please try again later or contact Bearly support.');
            }
            Cache::put($this->key($request), $state, config('email-verification.ttl'));
            return ['message' => 'Verification code sent. Check your inbox and spam folder.',
                'retry_after' => config('email-verification.cooldown'), 'expires_in' => config('email-verification.ttl')];
        });
    }

    public function check(Request $request, string $email, string $code): array
    {
        return Cache::lock($this->key($request).':lock', 25)->block(2, function () use ($request, $email, $code) {
            $state = Cache::get($this->key($request));
            if (! $state || ! hash_equals($state['email'], $email) || $state['expires_at'] <= now()->timestamp) {
                $this->fail('Your code has expired or your email address changed. Request a new code.');
            }
            if (($state['verified_until'] ?? 0) > now()->timestamp) {
                $this->verifiedAt($request, $email);
                return ['message' => 'Email address verified.', 'expires_in' => $state['verified_until'] - now()->timestamp];
            }
            if ($state['attempts'] >= config('email-verification.max_attempts')) {
                $this->fail('Too many incorrect codes. Request a new code after the cooldown.');
            }
            // Count every incorrect verification attempt.
            $state['attempts']++;
            Cache::put($this->key($request), $state, max(1, $state['expires_at'] - now()->timestamp));
            if ($state['driver'] !== $this->driver()) {
                $this->fail('Verification settings changed. Request a new code.');
            }
            $approved = Hash::check($code, $state['code_hash']);
            if (! $approved) {
                $this->fail('That code is incorrect. Please check the email and try again.');
            }
            $state['verified_until'] = now()->addSeconds(config('email-verification.proof_ttl'))->timestamp;
            $state['verified_at'] = now()->toDateTimeString();
            unset($state['code_hash']);
            Cache::put($this->key($request), $state, config('email-verification.proof_ttl'));
            return ['message' => 'Email address verified.', 'expires_in' => config('email-verification.proof_ttl')];
        });
    }

    public function verifiedAt(Request $request, string $email): string
    {
        $state = Cache::get($this->key($request));
        if (! $state || ! hash_equals($state['email'], $email) ||
            ($state['verified_until'] ?? 0) <= now()->timestamp || $state['driver'] !== $this->driver()) {
            $this->fail('Verify this email address before submitting your buyer application.');
        }
        return $state['verified_at'];
    }

    public function forget(Request $request): void
    {
        Cache::forget($this->key($request));
    }
}
