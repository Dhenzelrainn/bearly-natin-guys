<?php

namespace App\Http\Controllers;

use App\Services\LogisticsPayoutAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminLogisticsPayoutAccountController extends Controller
{
    public function show(Request $request, int $account, LogisticsPayoutAccountService $accounts): Response
    {
        return response()
            ->view('admin.finance.logistics-payout-account', [
                'payoutAccount' => $accounts->financeDetails($request->user(), $account, $request),
            ])
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function verify(Request $request, int $account, LogisticsPayoutAccountService $accounts): RedirectResponse
    {
        $data = $request->validate([
            'verification_note' => ['required', 'string', 'min:5', 'max:1000'],
            'destination_verified' => ['required', 'accepted'],
        ]);

        $accounts->verify($request->user(), $account, trim($data['verification_note']), $request);

        return redirect()
            ->route('admin.shipping-settlements.index')
            ->with('success', 'The Logistics payout destination was verified and is available for remittance.');
    }

    public function reject(Request $request, int $account, LogisticsPayoutAccountService $accounts): RedirectResponse
    {
        $data = $request->validate([
            'review_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $accounts->reject($request->user(), $account, trim($data['review_reason']), $request);

        return redirect()
            ->route('admin.shipping-settlements.index')
            ->with('success', 'The Logistics payout destination was rejected.');
    }
}
