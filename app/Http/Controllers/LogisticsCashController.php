<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\CodCashCollection;
use App\Models\RefundCashPayout;
use App\Services\AuditService;
use App\Services\CodCashCollectionService;
use App\Services\RefundCashPayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LogisticsCashController extends Controller
{
    public function index(Request $request, RefundCashPayoutService $cash): View
    {
        $user = $request->user();

        return view('logistics.cash.index', $this->layoutData($request) + $cash->logisticsWorkspace($user));
    }

    public function receiveCod(Request $request, CodCashCollection $collection, CodCashCollectionService $cash): RedirectResponse
    {
        $data = $request->validate([
            'actual_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'reference' => ['required', 'string', 'max:150'],
        ]);
        $cash->receiveAtLogistics($request->user(), (int) $collection->id, $this->amountToMinor($data['actual_amount']), trim($data['reference']));

        return back()->with('success', "COD cash {$collection->collection_no} was acknowledged by Logistics.");
    }

    public function remitCod(Request $request, CodCashCollection $collection, CodCashCollectionService $cash): RedirectResponse
    {
        $data = $request->validate([
            'actual_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'reference' => ['required', 'string', 'max:150'],
        ]);
        $cash->remitToFinance($request->user(), (int) $collection->id, $this->amountToMinor($data['actual_amount']), trim($data['reference']));

        return back()->with('success', "COD cash {$collection->collection_no} was recorded as remitted to Finance.");
    }

    public function receiveRefundCash(Request $request, RefundCashPayout $payout, RefundCashPayoutService $cash): RedirectResponse
    {
        $data = $request->validate([
            'actual_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'reference' => ['required', 'string', 'max:150'],
        ]);
        $cash->receiveAtLogistics($request->user(), (int) $payout->id, $this->amountToMinor($data['actual_amount']), trim($data['reference']));

        return back()->with('success', "Refund cash {$payout->payout_no} was received by Logistics.");
    }

    public function assignRefundCash(Request $request, RefundCashPayout $payout, RefundCashPayoutService $cash): RedirectResponse
    {
        $data = $request->validate([
            'rider_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $payout = $cash->assignRider($request->user(), (int) $payout->id, (int) $data['rider_user_id']);

        return back()->with('success', "Rider assigned to {$payout->payout_no}. Print acknowledgment {$payout->acknowledgment_reference} and give it to the Rider with the cash.");
    }

    public function prepareRefundAcknowledgmentSlip(Request $request, RefundCashPayout $payout, RefundCashPayoutService $cash): RedirectResponse
    {
        $payout = $cash->prepareAcknowledgmentForLogistics($request->user(), (int) $payout->id);

        return redirect()
            ->route('logistics.cash.refunds.acknowledgment-slip', $payout)
            ->with('success', "Acknowledgment {$payout->acknowledgment_reference} is ready to print or save as PDF.");
    }

    public function refundAcknowledgmentSlip(Request $request, RefundCashPayout $payout, RefundCashPayoutService $cash): View
    {
        $payout = $cash->acknowledgmentSlipForLogistics($request->user(), (int) $payout->id);

        return view('logistics.cash.acknowledgment-slip', $this->layoutData($request) + ['payout' => $payout]);
    }

    public function confirmReturnedRefundCash(Request $request, RefundCashPayout $payout, RefundCashPayoutService $cash): RedirectResponse
    {
        $data = $request->validate([
            'actual_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'reference' => ['required', 'string', 'max:150'],
        ]);
        $cash->confirmReturnedCashAtLogistics($request->user(), (int) $payout->id, $this->amountToMinor($data['actual_amount']), trim($data['reference']));

        return back()->with('success', "Logistics confirmed receipt of the returned refund cash {$payout->payout_no}.");
    }

    public function verifyRefundReceipt(Request $request, RefundCashPayout $payout, RefundCashPayoutService $cash): RedirectResponse
    {
        $data = $request->validate([
            'verification_note' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $cash->verifyBuyerReceipt($request->user(), (int) $payout->id, trim($data['verification_note']));

        return back()->with('success', "The Buyer acknowledgment for {$payout->payout_no} is verified and awaiting Finance completion.");
    }

    public function downloadRefundAcknowledgment(
        Request $request,
        RefundCashPayout $payout,
        RefundCashPayoutService $cash,
        AuditService $audit,
    ): BinaryFileResponse {
        $verifiedPayout = $cash->acknowledgmentForLogistics($request->user(), (int) $payout->id);
        $audit->record(
            action: 'refund_cash.acknowledgment_downloaded',
            module: 'Logistics',
            subject: $verifiedPayout,
            description: "Signed Buyer acknowledgment downloaded for {$verifiedPayout->payout_no}.",
            newValues: ['original_name' => $verifiedPayout->acknowledgment_original_name],
            severity: 'info',
            request: $request,
        );

        return response()->download(
            Storage::disk('local')->path($verifiedPayout->acknowledgment_path),
            $this->safeDownloadName($verifiedPayout->acknowledgment_original_name ?: 'buyer-acknowledgment'),
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    private function amountToMinor(string $amount): int
    {
        if (! preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount with up to two decimal places.']);
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0', STR_PAD_RIGHT);
    }

    private function safeDownloadName(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename(str_replace('\\', '/', $name))) ?: 'buyer-acknowledgment';

        return Str::limit($safe, 200, '');
    }

    /** @return array<string, mixed> */
    private function layoutData(Request $request): array
    {
        $user = $request->user();
        $profile = $user->logisticsProfile()->with('sortingCenters')->first();
        $facility = $profile?->sortingCenters->firstWhere('status', 'active');
        $name = $user->name ?: 'Bearly Logistics';
        $initials = collect(preg_split('/\s+/', trim($name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
        $pendingRiderCount = $profile
            ? AccountApplication::query()
                ->where('sponsor_logistics_profile_id', $profile->id)
                ->whereHas('requestedRole', fn ($query) => $query->where('name', UserRole::Rider->value))
                ->whereIn('status', ['submitted', 'under_review', 'needs_revision'])
                ->count()
            : 0;

        return [
            'operator' => [
                'name' => $name,
                'initials' => $initials ?: 'BL',
                'email' => $user->email ?: '',
                'role' => 'Logistics Operator',
                'business_name' => $facility?->name ?: ($profile?->display_name ?: $name),
            ],
            'pendingRiderCount' => $pendingRiderCount,
            'topNotifications' => [],
        ];
    }
}
