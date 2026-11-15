<?php

namespace App\Http\Controllers;

use App\Models\RefundCashPayout;
use App\Services\RefundCashPayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RiderCashController extends Controller
{
    public function index(Request $request, RefundCashPayoutService $cash): View
    {
        $user = $request->user();
        $profile = $user->riderProfile()->with('logisticsProfile.user')->first();
        $name = $user->name ?: 'Bearly Rider';
        $initials = collect(preg_split('/\s+/', trim($name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        return view('rider.refund-cash.index', [
            'rider' => ['name' => $name, 'initials' => $initials ?: 'BR', 'role' => 'Rider'],
            'logistics' => ['name' => $profile?->logisticsProfile?->user?->name ?: 'Logistics'],
            'topNotifications' => [],
            'payouts' => $cash->riderTasks($user),
        ]);
    }

    public function receive(Request $request, RefundCashPayout $payout, RefundCashPayoutService $cash): RedirectResponse
    {
        $data = $request->validate([
            'actual_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'acknowledgment_reference' => ['required', 'string', 'max:150'],
        ]);
        $cash->riderReceivesCash(
            $request->user(),
            (int) $payout->id,
            $this->amountToMinor($data['actual_amount']),
            trim($data['acknowledgment_reference']),
        );

        return back()->with('success', "Refund cash and acknowledgment form {$payout->acknowledgment_reference} receipt is recorded. Keep the full amount secured for delivery.");
    }

    public function start(Request $request, RefundCashPayout $payout, RefundCashPayoutService $cash): RedirectResponse
    {
        $payout = $cash->startDelivery($request->user(), (int) $payout->id);

        return back()->with('success', "Delivery of refund cash {$payout->payout_no} has started. Use the numbered acknowledgment form provided by Logistics.");
    }

    public function deliver(Request $request, RefundCashPayout $payout, RefundCashPayoutService $cash): RedirectResponse
    {
        $data = $request->validate([
            'actual_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'recipient_name' => ['required', 'string', 'max:160'],
            'acknowledgment' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);
        /** @var UploadedFile $acknowledgment */
        $acknowledgment = $data['acknowledgment'];
        $cash->deliverToBuyer(
            $request->user(),
            (int) $payout->id,
            $this->amountToMinor($data['actual_amount']),
            trim($data['recipient_name']),
            $acknowledgment,
        );

        return back()->with('success', "Refund cash {$payout->payout_no} delivery and signed Buyer acknowledgment are recorded. Finance must verify receipt before completion.");
    }

    public function fail(Request $request, RefundCashPayout $payout, RefundCashPayoutService $cash): RedirectResponse
    {
        $data = $request->validate(['failure_reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $cash->failDelivery($request->user(), (int) $payout->id, trim($data['failure_reason']));

        return back()->with('success', 'Delivery attempt recorded. Return the full refund cash to Logistics before it can be reassigned.');
    }

    public function returnCash(Request $request, RefundCashPayout $payout, RefundCashPayoutService $cash): RedirectResponse
    {
        $data = $request->validate([
            'actual_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'reference' => ['required', 'string', 'max:150'],
        ]);
        $cash->returnCashToLogistics($request->user(), (int) $payout->id, $this->amountToMinor($data['actual_amount']), trim($data['reference']));

        return back()->with('success', 'Cash return was recorded. Logistics must confirm physical receipt before another Rider can be assigned.');
    }

    private function amountToMinor(string $amount): int
    {
        if (! preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount with up to two decimal places.']);
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0', STR_PAD_RIGHT);
    }
}
