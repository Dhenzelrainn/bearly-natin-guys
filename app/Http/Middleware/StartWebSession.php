<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;

class StartWebSession extends StartSession
{
    public function __construct(SessionManager $manager, CacheFactory $cache)
    {
        // Preserve Laravel's cache resolver for routes using session blocking.
        parent::__construct($manager, fn () => $cache);
    }

    protected function handleStatefulRequest(Request $request, $session, Closure $next)
    {
        if (! $request->routeIs('auth.session.status')) {
            return parent::handleStatefulRequest($request, $session, $next);
        }

        // Monitoring must not renew idle sessions, age flash messages, or
        // overwrite session changes from concurrent requests. Revocation still
        // destroys a stale session through EnforceAuthSessionVersion.
        $request->setLaravelSession($this->startSession($request, $session));

        return $next($request);
    }
}
