<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureMobileAccountIsEligible
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user
            && $user->status === AccountStatus::Active->value
            && in_array($user->role, [
                UserRole::Buyer->value,
                UserRole::Rider->value,
            ], true)
        ) {
            return $next($request);
        }

        $token = $user?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json([
            'message' => 'This account is not eligible to use the Bearly mobile app.',
            'account_status' => $user?->status,
            'role' => $user?->role,
        ], 403);
    }
}
