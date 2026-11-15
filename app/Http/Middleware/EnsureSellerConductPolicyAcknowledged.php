<?php

namespace App\Http\Middleware;

use App\Services\SellerPolicyAcknowledgmentService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSellerConductPolicyAcknowledged
{
    public function __construct(
        private readonly SellerPolicyAcknowledgmentService $policies,
    ) {}

    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $version = $this->policies->currentRequiredVersion();

        if (! $version || $this->policies->hasAccepted($request->user(), $version)) {
            return $next($request);
        }

        if ($request->routeIs('seller.policies.current', 'seller.policies.accept')) {
            return $next($request);
        }

        return redirect()
            ->route('seller.policies.current')
            ->with('policy_required', true);
    }
}
