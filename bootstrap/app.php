<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\PreventBackCache;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            PreventBackCache::class,
        ]);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Redirect already-authenticated users
        |--------------------------------------------------------------------------
        |
        | Prevent Laravel's guest middleware from always redirecting an
        | authenticated account to /home. Each Bearly role returns to its
        | own dashboard instead.
        |
        */

        $middleware->redirectUsersTo(function (Request $request) {
            $user = $request->user();

            return match ($user?->role) {
                'admin' => route('admin.dashboard'),
                'buyer' => route('home'),
                'seller' => route('seller.dashboard'),
                'logistics' => route('logistics.dashboard'),
                'rider' => route('rider.dashboard.deliveries'),
                default => route('shop.home'),
            };
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (HttpException $exception, Request $request) {
            if ($exception->getStatusCode() !== 419) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your session expired. Please refresh the page and try again.',
                    'code' => 'CSRF_TOKEN_MISMATCH',
                ], 419);
            }

            return redirect()
                ->back(302, [], route('login'))
                ->withErrors([
                    'csrf' => 'Your session expired. The page was refreshed. Please submit the form again.',
                ]);
        });
    })
    ->create();
