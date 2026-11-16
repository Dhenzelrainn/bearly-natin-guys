<?php

namespace App\Services;

use App\Events\UserSessionsRevoked;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class UserSessionRevocationService
{
    public const SESSION_VERSION_KEY = 'bearly_auth_session_version';

    public function __construct(private readonly AuditService $audit) {}

    public function markCurrentSession(Request $request, User $user): void
    {
        $request->session()->put(
            self::SESSION_VERSION_KEY,
            (int) $user->auth_session_version,
        );
    }

    public function sessionIsCurrent(Request $request, User $user): bool
    {
        $currentVersion = (int) $user->auth_session_version;
        $sessionVersion = $request->session()->get(self::SESSION_VERSION_KEY);

        // Existing sessions do not have a marker until their first request
        // after deployment. Accept those only before this account has ever
        // been force-signed-out; after a revocation, missing markers are stale.
        if ($sessionVersion === null && $currentVersion === 0) {
            $this->markCurrentSession($request, $user);

            return true;
        }

        if (! is_int($sessionVersion) && ! (is_string($sessionVersion) && ctype_digit($sessionVersion))) {
            return false;
        }

        return (int) $sessionVersion === $currentVersion;
    }

    /**
     * Revoke every sign-in credential for a user and preserve the Admin action
     * in the same transaction.
     *
     * @return array{auth_session_version: int, mobile_api_tokens_revoked: int, remembered_sign_ins_revoked: int}
     */
    public function forceLogoutAll(User $target, User $actor, Request $request): array
    {
        return DB::transaction(function () use ($target, $actor, $request): array {
            $user = User::withTrashed()
                ->lockForUpdate()
                ->findOrFail($target->getKey());

            $oldVersion = (int) $user->auth_session_version;
            $newVersion = $oldVersion + 1;

            $user->forceFill([
                'auth_session_version' => $newVersion,
                'remember_token' => Str::random(60),
            ])->save();

            $revokedTokens = (int) $user->tokens()->delete();
            $revokedRememberedSignIns = (int) $user->rememberedAccounts()->delete();

            $this->audit->record(
                action: 'auth.force_signed_out',
                module: 'Authentication',
                subject: $user,
                description: 'An administrator forced this account to sign out across all web sessions and mobile devices.',
                oldValues: ['auth_session_version' => $oldVersion],
                newValues: [
                    'event' => 'force_signed_out',
                    'scope' => 'all_devices',
                    'role' => (string) $user->role,
                    'web_sessions_invalidated' => true,
                    'mobile_api_tokens_revoked' => $revokedTokens,
                    'remembered_sign_ins_revoked' => $revokedRememberedSignIns,
                    'remember_token_rotated' => true,
                    'auth_session_version' => $newVersion,
                ],
                severity: 'warning',
                request: $request,
                actor: $actor,
            );

            $event = new UserSessionsRevoked((int) $user->getKey());

            DB::afterCommit(function () use ($event): void {
                try {
                    event($event);
                } catch (Throwable $exception) {
                    // Revocation is already committed. Browser session checks
                    // remain the fallback if realtime delivery is unavailable.
                    Log::notice('Bearly realtime session revocation broadcast failed.', [
                        'user_id' => $event->userId,
                        'exception' => $exception::class,
                    ]);
                }
            });

            return [
                'auth_session_version' => $newVersion,
                'mobile_api_tokens_revoked' => $revokedTokens,
                'remembered_sign_ins_revoked' => $revokedRememberedSignIns,
            ];
        }, attempts: 3);
    }
}
