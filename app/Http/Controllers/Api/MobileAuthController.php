<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RegistrationLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class MobileAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = strtolower(trim($credentials['email']));

        $rateKey = 'mobile-login:' . hash_hmac(
            'sha256',
            $email . '|' . $request->ip(),
            (string) config('app.key')
        );

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $seconds = RateLimiter::availableIn($rateKey);

            return response()->json([
                'message' => "Too many sign-in attempts. Try again in {$seconds} seconds.",
            ], 429);
        }

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($rateKey, 60);

            return response()->json([
                'message' => 'Invalid email or password.',
            ], 401);
        }

        RateLimiter::clear($rateKey);

        if (! in_array($user->role, [
            UserRole::Buyer->value,
            UserRole::Rider->value,
        ], true)) {
            return response()->json([
                'message' => 'This account type is not available in the Bearly mobile app.',
            ], 403);
        }

        if ($user->status !== AccountStatus::Active->value) {
            return response()->json([
                'message' => $this->statusMessage($user->status),
                'account_status' => $user->status,
                'role' => $user->role,
            ], 403);
        }

        $user->forceFill([
            'last_login_at' => now(),
        ])->save();

        app(RegistrationLifecycleService::class)
            ->syncActiveRole($user);

        $token = $user
            ->createToken('bearly-mobile')
            ->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'token_type' => 'Bearer',
            'token' => $token,

            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()
            ?->currentAccessToken()
            ?->delete();

        return response()->json([
            'message' => 'Logout successful.',
        ]);
    }

    private function statusMessage(?string $status): string
    {
        return match ($status) {
            AccountStatus::Pending->value =>
                'Your application is still pending approval.',

            AccountStatus::NeedsRevision->value =>
                'Your application needs revision before it can be approved.',

            AccountStatus::Rejected->value =>
                'Your application was not approved.',

            AccountStatus::Suspended->value =>
                'Your account is currently suspended.',

            AccountStatus::Deactivated->value =>
                'Your account is currently deactivated.',

            AccountStatus::Banned->value =>
                'Your account is not available.',

            default =>
                'Your account is not currently available.',
        };
    }
}