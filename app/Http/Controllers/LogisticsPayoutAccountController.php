<?php

namespace App\Http\Controllers;

use App\Services\LogisticsPayoutAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LogisticsPayoutAccountController extends Controller
{
    public function store(Request $request, LogisticsPayoutAccountService $accounts): RedirectResponse
    {
        $rules = [
            'channel' => ['required', Rule::in(['bank_transfer', 'gcash'])],
            'provider_name' => ['nullable', 'required_if:channel,bank_transfer', 'string', 'min:2', 'max:120'],
            'account_name' => ['required', 'string', 'min:2', 'max:160'],
            'destination_value' => ['required', 'string', 'min:6', 'max:42'],
        ];
        if ($request->input('channel') === 'bank_transfer') {
            $rules['destination_value'][] = 'regex:/^[0-9 -]{6,42}$/';
        }
        try {
            $data = $request->validate($rules);
            $account = $accounts->create($request->user(), $data, $request);
        } catch (ValidationException $exception) {
            return redirect()
                ->to(route('logistics.profile.index').'#payouts')
                ->withErrors($exception->errors())
                ->withInput($request->except('destination_value'));
        }

        return redirect()
            ->to(route('logistics.profile.index').'#payouts')
            ->with('success', "{$account->provider_name} payout destination ending in {$account->destination_last4} was submitted for Finance review.");
    }

    public function deactivate(Request $request, int $account, LogisticsPayoutAccountService $accounts): RedirectResponse
    {
        $accounts->deactivate($request->user(), $account, $request);

        return redirect()
            ->to(route('logistics.profile.index').'#payouts')
            ->with('success', 'The payout destination was deactivated. Existing settlement history is unchanged.');
    }
}
