<?php

namespace App\Http\Middleware;

use App\Enums\RiderWorkType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLocalRiderWorkType
{
    public function handle(Request $request, Closure $next): Response
    {
        $profile = $request->user()?->riderProfile()->first();

        if ($profile && ! RiderWorkType::isLocal($profile->work_type)) {
            return redirect()->route('rider.courier.dashboard');
        }

        return $next($request);
    }
}
