<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\RegistrationLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class MobileAuthController extends Controller
{
    public function login(Request $request, AuditService $audit): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = strtolower(trim($credentials['email']));

        $rateKey = 'mobile-login:'.hash_hmac(
            'sha256',
            $email.'|'.$request->ip(),
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

        // A force-logout may commit after the initial password lookup but
        // before this request creates a Sanctum token. Serialize issuance with
        // revocation and reject credentials checked against an older version.
        $expectedSessionVersion = (int) $user->auth_session_version;
        $login = DB::transaction(function () use (
            $audit,
            $credentials,
            $expectedSessionVersion,
            $request,
            $user,
        ): array {
            $lockedUser = User::query()
                ->lockForUpdate()
                ->find($user->getKey());

            if (! $lockedUser || ! Hash::check($credentials['password'], $lockedUser->password)) {
                return ['error' => 'credentials'];
            }

            if ((int) $lockedUser->auth_session_version !== $expectedSessionVersion) {
                return ['error' => 'session_revoked'];
            }

            if (! in_array($lockedUser->role, [
                UserRole::Buyer->value,
                UserRole::Rider->value,
            ], true)) {
                return ['error' => 'role'];
            }

            if ($lockedUser->status !== AccountStatus::Active->value) {
                return [
                    'error' => 'status',
                    'account_status' => $lockedUser->status,
                    'role' => $lockedUser->role,
                ];
            }

            $lockedUser->forceFill([
                'last_login_at' => now(),
            ])->save();

            app(RegistrationLifecycleService::class)
                ->syncActiveRole($lockedUser);

            $token = $lockedUser
                ->createToken('bearly-mobile')
                ->plainTextToken;

            $audit->recordAuthentication($lockedUser, 'signed_in', 'mobile_api', $request);

            return [
                'user' => $lockedUser,
                'token' => $token,
            ];
        }, attempts: 3);

        if (($login['error'] ?? null) === 'session_revoked') {
            return response()->json([
                'message' => 'This sign-in was interrupted by an account security change. Please submit your credentials again.',
            ], 409);
        }

        if (($login['error'] ?? null) === 'role') {
            return response()->json([
                'message' => 'This account type is not available in the Bearly mobile app.',
            ], 403);
        }

        if (($login['error'] ?? null) === 'status') {
            return response()->json([
                'message' => $this->statusMessage($login['account_status'], $login['role']),
                'account_status' => $login['account_status'],
                'role' => $login['role'],
            ], 403);
        }

        if (($login['error'] ?? null) !== null) {
            return response()->json([
                'message' => 'Invalid email or password.',
            ], 401);
        }

        /** @var User $user */
        $user = $login['user'];
        $token = $login['token'];

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

    public function logout(Request $request, AuditService $audit): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($user) {
            $audit->recordAuthentication($user, 'signed_out', 'mobile_api', $request);
        }

        $token?->delete();

        return response()->json([
            'message' => 'Logout successful.',
        ]);
    }

    private function statusMessage(?string $status, ?string $role = null): string
    {
        return match ($status) {
            AccountStatus::Pending->value => $role === UserRole::Buyer->value
                ? 'This Buyer account is from the previous review process and remains pending outside the active Admin queues. New Buyer registrations activate after email verification; contact Support about this existing account.'
                : 'Your application is still pending approval.',

            AccountStatus::NeedsRevision->value => $role === UserRole::Buyer->value
                ? 'Your existing Buyer application needs revision and is outside the active Admin queues. Contact Support about this existing account.'
                : 'Your application needs revision before it can be approved.',

            AccountStatus::Rejected->value => 'Your application was not approved.',

            AccountStatus::Suspended->value => 'Your account is currently suspended.',

            AccountStatus::Deactivated->value => 'Your account is currently deactivated.',

            AccountStatus::Banned->value => 'Your account is not available.',

            default => 'Your account is not currently available.',
        };
    }
}
