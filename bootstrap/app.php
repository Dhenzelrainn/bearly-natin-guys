<?php

use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
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
        //
    })
    ->create();