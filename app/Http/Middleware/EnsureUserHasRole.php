<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless(
            $request->user()->status === 'active' && collect($roles)->contains(fn (string $role) => $request->user()->hasRole($role)),
            403
        );

        return $next($request);
    }
}
