<?php

namespace App\Http\Controllers;

use App\Models\SellerBankAccount;
use App\Models\SellerProfile;
use App\Models\SellerWalletTransaction;
use App\Models\SellerWithdrawal;
use App\Models\SellerWithdrawalTransfer;
use App\Services\AuditService;
use App\Services\BuyerCatalogService;
use App\Services\SellerWalletService;
use App\Services\SellerWithdrawalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SellerWalletController extends Controller
{
    public function index(
        Request $request,
        SellerWalletService $wallets,
        BuyerCatalogService $catalog,
    ): View {
        $sellerProfile = $this->sellerProfile($request);
        $wallet = $wallets->refreshForSeller($sellerProfile);

        $transactions = SellerWalletTransaction::query()
            ->where('seller_wallet_id', $wallet->id)
            ->with([
                'sellerTransaction.sellerOrder.order',
                'sellerTransaction.sellerOrder.items.product.images.variant',
                'sellerTransaction.sellerOrder.items.variant',
                'withdrawal.transfer',
                'legacyPayout.items.transaction.sellerOrder.order',
                'legacyPayout.items.transaction.sellerOrder.items.product.images.variant',
                'legacyPayout.items.transaction.sellerOrder.items.variant',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'ledger_page');

        $transactions->getCollection()->each(function (SellerWalletTransaction $transaction) use ($catalog): void {
            $sellerOrders = collect();

            if ($transaction->sellerTransaction?->sellerOrder) {
                $sellerOrders->push($transaction->sellerTransaction->sellerOrder);
            } elseif ($transaction->legacyPayout) {
                foreach ($transaction->legacyPayout->items as $item) {
                    if ($item->transaction?->sellerOrder) {
                        $sellerOrders->push($item->transaction->sellerOrder);
                    }
                }
            }

            $sellerOrders = $sellerOrders->unique('id')->values();
            $firstOrder = $sellerOrders->first();

            $transaction->setAttribute(
                'covered_order_label',
                $firstOrder
                    ? ($firstOrder->order?->order_no ?? $firstOrder->seller_order_no)
                        .($sellerOrders->count() > 1 ? ' +'.($sellerOrders->count() - 1).' more' : '')
                    : null,
            );

            $transaction->setAttribute('order_item_previews', $sellerOrders
                ->flatMap(fn ($sellerOrder) => $sellerOrder->items)
                ->map(fn ($item): array => [
                    'name' => $item->product_name ?: 'Unnamed product',
                    'variant' => trim((string) $item->variant_name),
                    'quantity' => (int) $item->quantity,
                    'image_url' => $catalog->orderItemImageUrl($item),
                ])
                ->values()
                ->all());
        });

        $withdrawals = SellerWithdrawal::query()
            ->where('seller_profile_id', $sellerProfile->id)
            ->with(['bankAccount', 'transfer'])
            ->latest('requested_at')
            ->paginate(12, ['*'], 'withdrawal_page')
            ->withQueryString();

        $bankAccounts = SellerBankAccount::query()
            ->where('seller_profile_id', $sellerProfile->id)
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        return view('seller.Finance.wallet', [
            'seller' => $request->user(),
            'wallet' => $wallet,
            'transactions' => $transactions,
            'withdrawals' => $withdrawals,
            'bankAccounts' => $bankAccounts,
            'idempotencyKey' => old('idempotency_key', (string) Str::uuid()),
        ]);
    }

    public function bankAccounts(Request $request): View
    {
        $sellerProfile = $this->sellerProfile($request);
        $accounts = SellerBankAccount::query()
            ->where('seller_profile_id', $sellerProfile->id)
            ->orderByDesc('is_default')
            ->latest('id')
            ->get();

        return view('seller.Finance.bank-accounts', [
            'seller' => $request->user(),
            'accounts' => $accounts,
        ]);
    }

    public function storeBankAccount(Request $request, SellerWithdrawalService $withdrawals): RedirectResponse
    {
        $data = $request->validate([
            'bank_name' => ['required', 'string', 'min:2', 'max:120'],
            'account_name' => ['required', 'string', 'min:2', 'max:160'],
            'account_number' => ['required', 'string', 'regex:/^[0-9 -]{6,42}$/'],
        ], [
            'account_number.regex' => 'Enter a bank account number using 6 to 34 digits. Spaces and hyphens are allowed.',
        ]);

        $account = $withdrawals->addBankAccount($request->user(), $data, $request);

        return back()->with('success', "{$account->bank_name} account ending in {$account->account_number_last4} was linked. The full account number is encrypted and will not appear in lists.");
    }

    public function deactivateBankAccount(
        Request $request,
        SellerBankAccount $account,
        SellerWithdrawalService $withdrawals,
    ): RedirectResponse {
        $withdrawals->deactivateBankAccount($request->user(), (int) $account->id, $request);

        return back()->with('success', 'The bank account was deactivated. Existing withdrawal records keep their original account link.');
    }

    public function requestWithdrawal(Request $request, SellerWithdrawalService $withdrawals): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/'],
            'seller_bank_account_id' => ['required', 'integer', 'exists:seller_bank_accounts,id'],
            'idempotency_key' => ['required', 'uuid'],
        ], [
            'amount.regex' => 'Enter an amount with up to two decimal places. The amount must be greater than zero and within your available balance.',
        ]);

        $amountMinor = $this->amountToMinor($data['amount']);
        $withdrawal = $withdrawals->requestWithdrawal(
            $request->user(),
            $amountMinor,
            (int) $data['seller_bank_account_id'],
            $data['idempotency_key'],
            $request,
        );

        return redirect()
            ->route('seller.finance.wallet')
            ->with('success', "Withdrawal {$withdrawal->withdrawal_no} was submitted. ₱".number_format((int) $withdrawal->amount_minor / 100, 2).' is reserved while Finance reviews it.');
    }

    public function downloadTransferProof(
        Request $request,
        SellerWithdrawal $withdrawal,
        AuditService $audit,
    ): StreamedResponse {
        $profileId = (int) ($request->user()->sellerProfile?->id ?? 0);
        abort_unless($profileId > 0 && (int) $withdrawal->seller_profile_id === $profileId, 404);
        abort_unless($withdrawal->status === 'completed', 404);

        $transfer = SellerWithdrawalTransfer::query()
            ->where('seller_withdrawal_id', $withdrawal->id)
            ->where('status', 'verified')
            ->firstOrFail();
        abort_unless($transfer->proof_disk === 'local'
            && filled($transfer->proof_path)
            && Storage::disk('local')->exists($transfer->proof_path), 404);

        $audit->record(
            action: 'seller_withdrawal.proof_downloaded_by_seller',
            module: 'Seller Wallet',
            subject: $withdrawal,
            description: "Seller downloaded verified bank-transfer proof for {$withdrawal->withdrawal_no}.",
            newValues: ['withdrawal_no' => $withdrawal->withdrawal_no, 'transfer_no' => $transfer->transfer_no],
            request: $request,
        );

        return $this->privateDownload($transfer->proof_path, $transfer->proof_original_name ?: 'withdrawal-proof');
    }

    private function sellerProfile(Request $request): SellerProfile
    {
        $seller = $request->user();
        abort_unless($seller?->role === 'seller' && $seller->status === 'active', 403);

        $profile = $seller->sellerProfile;
        abort_unless($profile && $profile->approved_at, 404);

        return $profile;
    }

    private function amountToMinor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function privateDownload(string $path, string $originalName): StreamedResponse
    {
        $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename(str_replace('\\', '/', $originalName))) ?: 'withdrawal-proof';
        $response = Storage::disk('local')->download($path, Str::limit($safeName, 200, ''));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
