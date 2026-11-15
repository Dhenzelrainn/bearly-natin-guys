<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\UserSessionRevocationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceAuthSessionVersion
{
    public function __construct(
        private readonly UserSessionRevocationService $sessionRevocations,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if (
            $user instanceof User
            && ! $this->sessionRevocations->sessionIsCurrent($request, $user)
        ) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->with('status', 'Your session was signed out. Please sign in again.');
        }

        return $next($request);
    }
}
