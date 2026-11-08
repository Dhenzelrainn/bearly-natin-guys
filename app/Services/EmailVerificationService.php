<?php

namespace App\Services;

use App\Mail\RegistrationCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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
        if (! $this->deliveryIsConfigured()) {
            $this->fail('Email verification is not configured. Please contact Bearly support.');
        }

        return 'smtp';
    }

    private function deliveryIsConfigured(): bool
    {
        return (bool) config('email-verification.enabled')
            && (bool) config('mail.mailers.smtp.host')
            && (bool) config('mail.from.address');
    }

    public function send(Request $request, string $email): array
    {
        $driver = $this->driver();
        $emailKey = 'buyer-email-send-address:'.$this->digest($email);
        $sessionKey = 'buyer-email-send-session:'.$this->digest($request->session()->getId());
        $ipKey = 'buyer-email-send-ip:'.$this->digest((string) $request->ip());
        $emailLimit = max(1, (int) config('email-verification.send_limit_per_email', 5));
        $sessionLimit = max(1, (int) config('email-verification.send_limit_per_session', 10));
        $ipLimit = max(1, (int) config('email-verification.send_limit_per_ip', 60));
        $limitWindow = max(1, (int) config('email-verification.send_limit_window', 3600));

        return Cache::lock($emailKey.':lock', 25)->block(2, function () use (
            $request,
            $email,
            $emailKey,
            $sessionKey,
            $ipKey,
            $emailLimit,
            $sessionLimit,
            $ipLimit,
            $limitWindow,
            $driver,
        ) {
            foreach ([
                [$emailKey, $emailLimit, 'Too many verification codes were requested for this email.'],
                [$sessionKey, $sessionLimit, 'Too many verification codes were requested in this browser session.'],
                [$ipKey, $ipLimit, 'Too many verification codes were requested from this network.'],
            ] as [$key, $limit, $message]) {
                if (RateLimiter::tooManyAttempts($key, $limit)) {
                    $this->abortRateLimited($message, $key);
                }
            }

            $cooldownKey = $emailKey.':cooldown';

            if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
                $this->abortRateLimited(
                    'Please wait before requesting another verification code.',
                    $cooldownKey,
                );
            }

            RateLimiter::hit($emailKey, $limitWindow);
            RateLimiter::hit($sessionKey, $limitWindow);
            RateLimiter::hit($ipKey, $limitWindow);
            RateLimiter::hit($cooldownKey, max(1, (int) config('email-verification.cooldown', 60)));
            Cache::forget($this->key($request));
            $proofKey = $this->browserProofKey($request, $email);
            if ($proofKey) {
                Cache::forget($proofKey);
            }

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

    private function abortRateLimited(string $message, string $key): never
    {
        $retryAfter = max(1, RateLimiter::availableIn($key));
        $wait = $retryAfter < 60
            ? $retryAfter.' '.($retryAfter === 1 ? 'second' : 'seconds')
            : (int) ceil($retryAfter / 60).' minutes';

        abort(429, $message.' Try again in '.$wait.'.', [
            'Retry-After' => (string) $retryAfter,
        ]);
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

            $proofKey = $this->browserProofKey($request, $email);
            if ($proofKey) {
                Cache::put($proofKey, [
                    'email' => $state['email'],
                    'driver' => $state['driver'],
                    'verified_until' => $state['verified_until'],
                    'verified_at' => $state['verified_at'],
                ], max(1, $state['verified_until'] - now()->timestamp));
            }

            return ['message' => 'Email address verified.', 'expires_in' => config('email-verification.proof_ttl')];
        });
    }

    public function verifiedAt(Request $request, string $email): string
    {
        $state = $this->verifiedProof($request, $email);

        if (! $state || $state['driver'] !== $this->driver()) {
            $this->fail('Verify this email address before submitting your application.');
        }

        return $state['verified_at'];
    }

    /** @return array{verified: bool, expires_in: int} */
    public function status(Request $request, string $email): array
    {
        if (! $this->deliveryIsConfigured()) {
            return ['verified' => false, 'expires_in' => 0];
        }

        $state = $this->verifiedProof($request, $email);

        if (! $state || $state['driver'] !== 'smtp') {
            return ['verified' => false, 'expires_in' => 0];
        }

        return [
            'verified' => true,
            'expires_in' => max(0, $state['verified_until'] - now()->timestamp),
        ];
    }

    public function forget(Request $request, ?string $email = null): void
    {
        $state = Cache::get($this->key($request));
        $email ??= is_array($state) && is_string($state['email'] ?? null)
            ? $state['email']
            : null;

        if ($email !== null) {
            $proofKey = $this->browserProofKey($request, $email);
            if ($proofKey) {
                Cache::forget($proofKey);
            }
        }

        Cache::forget($this->key($request));
    }

    /** @return array{email: string, driver: string, verified_until: int, verified_at: string}|null */
    private function verifiedProof(Request $request, string $email): ?array
    {
        $email = strtolower(trim($email));
        $proofKey = $this->browserProofKey($request, $email);
        $proof = $proofKey ? Cache::get($proofKey) : null;

        if ($this->isVerifiedState($proof, $email)) {
            return $proof;
        }

        $state = Cache::get($this->key($request));

        return $this->isVerifiedState($state, $email) ? $state : null;
    }

    private function isVerifiedState(mixed $state, string $email): bool
    {
        return is_array($state)
            && is_string($state['email'] ?? null)
            && hash_equals($email, $state['email'])
            && is_int($state['verified_until'] ?? null)
            && $state['verified_until'] > now()->timestamp
            && is_string($state['verified_at'] ?? null)
            && is_string($state['driver'] ?? null);
    }

    private function browserProofKey(Request $request, string $email): ?string
    {
        $token = $request->cookie(RegistrationDraftService::COOKIE_NAME);

        if (! is_string($token) || ! preg_match('/^[A-Za-z0-9]{64}$/D', $token)) {
            return null;
        }

        return 'buyer-email-proof:'.$this->digest($token.'|'.strtolower(trim($email)));
    }
}
