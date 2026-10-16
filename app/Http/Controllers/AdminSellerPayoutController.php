<?php

namespace App\Http\Controllers;

use App\Enums\SellerWithdrawalStatus;
use App\Models\SellerWithdrawal;
use App\Models\SellerWithdrawalTransfer;
use App\Services\AuditService;
use App\Services\SellerWithdrawalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminSellerPayoutController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');
        $search = trim((string) $request->query('q', ''));
        $statuses = array_column(SellerWithdrawalStatus::cases(), 'value');
        if ($status && ! in_array($status, $statuses, true)) {
            $status = null;
        }

        $query = SellerWithdrawal::query()
            ->with(['sellerProfile.store', 'sellerProfile.user', 'bankAccount', 'transfer'])
            ->when($status, fn ($withdrawals) => $withdrawals->where('status', $status))
            ->when($search !== '', function ($withdrawals) use ($search): void {
                $withdrawals->where(function ($filtered) use ($search): void {
                    $filtered->where('withdrawal_no', 'like', '%'.$search.'%')
                        ->orWhere('bank_name_snapshot', 'like', '%'.$search.'%')
                        ->orWhereHas('sellerProfile.user', fn ($users) => $users->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('sellerProfile.store', fn ($stores) => $stores->where('name', 'like', '%'.$search.'%'));
                });
            });

        $withdrawals = $query->latest('requested_at')->paginate(25)->withQueryString();
        $summary = SellerWithdrawal::query()
            ->selectRaw('status, COUNT(*) as requests_count, COALESCE(SUM(amount_minor), 0) as amount_minor')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return view('admin.finance.seller-payouts.index', [
            'withdrawals' => $withdrawals,
            'summary' => $summary,
            'statuses' => SellerWithdrawalStatus::cases(),
            'selectedStatus' => $status,
            'search' => $search,
        ]);
    }

    public function show(Request $request, SellerWithdrawal $withdrawal, AuditService $audit): View
    {
        $withdrawal->load([
            'sellerProfile.user',
            'bankAccount',
            'histories.actor',
            'transfer.recorder',
            'transfer.verifier',
        ]);

        $audit->record(
            action: 'seller_withdrawal.review_viewed',
            module: 'Finance',
            subject: $withdrawal,
            description: "Finance reviewed withdrawal {$withdrawal->withdrawal_no}; bank account access was recorded.",
            newValues: [
                'withdrawal_no' => $withdrawal->withdrawal_no,
                'status' => $withdrawal->status,
                'bank_name' => $withdrawal->bank_name_snapshot,
                'account_number_last4' => $withdrawal->account_number_last4,
                'full_bank_account_details_viewed' => true,
            ],
            request: $request,
        );

        return view('admin.finance.seller-payouts.show', compact('withdrawal'));
    }

    public function approve(Request $request, SellerWithdrawal $withdrawal, SellerWithdrawalService $service): RedirectResponse
    {
        $data = $request->validate([
            'review_note' => ['nullable', 'string', 'min:5', 'max:1000'],
        ]);
        $service->approve($request->user(), (int) $withdrawal->id, $data['review_note'] ?? null, $request);

        return back()->with('success', "Withdrawal {$withdrawal->withdrawal_no} was approved.");
    }

    public function reject(Request $request, SellerWithdrawal $withdrawal, SellerWithdrawalService $service): RedirectResponse
    {
        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $service->reject($request->user(), (int) $withdrawal->id, $data['rejection_reason'], $request);

        return back()->with('success', "Withdrawal {$withdrawal->withdrawal_no} was rejected and its reserved balance was released.");
    }

    public function startProcessing(Request $request, SellerWithdrawal $withdrawal, SellerWithdrawalService $service): RedirectResponse
    {
        $data = $request->validate([
            'processing_note' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $service->startProcessing($request->user(), (int) $withdrawal->id, $data['processing_note'], $request);

        return back()->with('success', "Finance started processing withdrawal {$withdrawal->withdrawal_no}.");
    }

    public function recordTransfer(Request $request, SellerWithdrawal $withdrawal, SellerWithdrawalService $service): RedirectResponse
    {
        $data = $request->validate([
            'transfer_amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'bank_reference' => ['nullable', 'string', 'max:150', 'required_if:transfer_outcome,succeeded'],
            'transfer_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'transfer_outcome' => ['required', Rule::in(['succeeded', 'failed'])],
            'no_successful_transfer_confirmed' => ['exclude_unless:transfer_outcome,failed'],
            'failure_reason' => ['required_if:transfer_outcome,failed', 'string', 'min:5', 'max:1000'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], [
            'transfer_amount.regex' => 'Enter the actual transfer amount with up to two decimal places.',
            'bank_reference.required_if' => 'Enter the bank reference for a successful transfer.',
        ]);

        $proof = $request->file('proof');
        $recorded = $service->recordTransfer(
            $request->user(),
            (int) $withdrawal->id,
            $this->amountToMinor($data['transfer_amount']),
            $data['bank_reference'] ?? null,
            $data['transfer_date'],
            $data['transfer_outcome'],
            $request->boolean('no_successful_transfer_confirmed'),
            $data['failure_reason'] ?? null,
            $proof,
            $request,
        );

        $message = $data['transfer_outcome'] === 'failed'
            ? "Transfer failed. Reserved funds for {$recorded->withdrawal_no} were returned to the Seller's available balance."
            : "Transfer details recorded for {$recorded->withdrawal_no}. Verify the successful transfer before marking it completed.";

        return back()->with('success', $message);
    }

    public function failTransfer(Request $request, SellerWithdrawal $withdrawal, SellerWithdrawalService $service): RedirectResponse
    {
        $data = $request->validate([
            'no_successful_transfer_confirmed' => ['required', 'accepted'],
            'failure_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'no_successful_transfer_confirmed.accepted' => 'Confirm that no successful transfer occurred before releasing funds.',
        ]);

        $service->confirmTransferFailure(
            $request->user(),
            (int) $withdrawal->id,
            $request->boolean('no_successful_transfer_confirmed'),
            $data['failure_reason'],
            $request,
        );

        return back()->with('success', "Finance confirmed the transfer for {$withdrawal->withdrawal_no} failed and released the reserved funds.");
    }

    public function complete(Request $request, SellerWithdrawal $withdrawal, SellerWithdrawalService $service): RedirectResponse
    {
        $data = $request->validate([
            'transfer_verified' => ['required', 'accepted'],
            'verification_note' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'transfer_verified.accepted' => 'Confirm that the successful transfer was independently verified.',
        ]);
        $service->complete(
            $request->user(),
            (int) $withdrawal->id,
            $request->boolean('transfer_verified'),
            $data['verification_note'],
            $request,
        );

        return back()->with('success', "Withdrawal {$withdrawal->withdrawal_no} is completed and the reserved funds were recorded as withdrawn.");
    }

    public function downloadProof(
        Request $request,
        SellerWithdrawal $withdrawal,
        AuditService $audit,
    ): StreamedResponse {
        $transfer = SellerWithdrawalTransfer::query()
            ->where('seller_withdrawal_id', $withdrawal->id)
            ->firstOrFail();
        abort_unless($transfer->proof_disk === 'local'
            && filled($transfer->proof_path)
            && Storage::disk('local')->exists($transfer->proof_path), 404);

        $audit->record(
            action: 'seller_withdrawal.proof_viewed_by_finance',
            module: 'Finance',
            subject: $withdrawal,
            description: "Finance reviewed bank-transfer proof for {$withdrawal->withdrawal_no}.",
            newValues: ['withdrawal_no' => $withdrawal->withdrawal_no, 'transfer_no' => $transfer->transfer_no],
            request: $request,
        );

        $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename(str_replace('\\', '/', (string) $transfer->proof_original_name))) ?: 'bank-transfer-proof';
        $response = Storage::disk('local')->download($transfer->proof_path, Str::limit($safeName, 200, ''));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function amountToMinor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
