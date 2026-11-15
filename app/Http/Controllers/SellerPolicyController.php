<?php

namespace App\Http\Controllers;

use App\Services\SellerPolicyAcknowledgmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SellerPolicyController extends Controller
{
    public function show(Request $request, SellerPolicyAcknowledgmentService $policies): Response|RedirectResponse
    {
        $version = $policies->currentRequiredVersion();

        if (! $version) {
            return redirect()->route('seller.dashboard');
        }

        $acknowledgmentRequired = ! $policies->hasAccepted($request->user(), $version);

        return response()
            ->view('seller.policies.required', [
                'version' => $version,
                'acknowledgmentRequired' => $acknowledgmentRequired,
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function accept(Request $request, SellerPolicyAcknowledgmentService $policies): RedirectResponse
    {
        $request->validate(['accepted' => ['required', 'accepted']]);

        if (! $policies->acknowledgeCurrent($request->user(), $request)) {
            return redirect()->route('seller.dashboard');
        }

        return redirect()
            ->route('seller.dashboard')
            ->with('success', 'Policy acknowledgment recorded.');
    }
}
