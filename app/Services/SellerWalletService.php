<?php

namespace App\Services;

use App\Enums\SellerWalletTransactionType;
use App\Models\Order;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\SellerOrder;
use App\Models\SellerPayout;
use App\Models\SellerProfile;
use App\Models\SellerTransaction;
use App\Models\SellerWallet;
use App\Models\SellerWalletTransaction;
use App\Models\SellerWithdrawal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class SellerWalletService
{
    private const RETURN_WINDOW_DAYS = 7;

    public function refreshForSeller(SellerProfile $sellerProfile): SellerWallet
    {
        return DB::transaction(function () use ($sellerProfile): SellerWallet {
            $lockedProfile = SellerProfile::query()->whereKey($sellerProfile->id)->lockForUpdate()->firstOrFail();
            $wallet = $this->lockOrCreateWallet($lockedProfile);

            $this->creditPostedSalesAsPending($wallet);
            $this->releaseEligiblePendingEarnings($wallet);

            return $wallet->fresh();
        }, attempts: 3);
    }

    public function refreshAllSellers(): int
    {
        $refreshed = 0;

        SellerProfile::query()
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('seller_transactions')
                    ->whereColumn('seller_transactions.seller_profile_id', 'seller_profiles.id')
                    ->where('seller_transactions.type', 'sale')
                    ->whereIn('seller_transactions.status', ['posted', 'completed', 'paid'])
                    ->whereNotNull('seller_transactions.posted_at')
                    ->where('seller_transactions.amount_minor', '>', 0);
            })
            ->orderBy('id')
            ->chunkById(100, function ($profiles) use (&$refreshed): void {
                foreach ($profiles as $profile) {
                    $this->refreshForSeller($profile);
                    $refreshed++;
                }
            });

        return $refreshed;
    }

    public function recordPendingEarning(SellerTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $profile = SellerProfile::query()
                ->whereKey($transaction->seller_profile_id)
                ->lockForUpdate()
                ->first();

            if (! $profile) {
                return;
            }

            $wallet = $this->lockOrCreateWallet($profile);
            $source = SellerTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if (! $this->isCreditCandidate($source)
                || $source->payoutItem()->exists()
                || $source->walletTransactions()->exists()) {
                return;
            }

            $amountMinor = (int) $source->amount_minor;
            $this->appendMovement(
                wallet: $wallet,
                type: SellerWalletTransactionType::SaleEarningPending->value,
                amountMinor: $amountMinor,
                pendingDeltaMinor: $amountMinor,
                sellerTransaction: $source,
                reference: $source->transaction_no,
                description: 'Seller sale earnings are pending settlement eligibility.',
                idempotencyKey: 'seller-sale-pending:'.$source->id,
            );
        }, attempts: 3);
    }

    public function reserveForWithdrawal(SellerWithdrawal $withdrawal, User $actor): SellerWalletTransaction
    {
        $wallet = SellerWallet::query()->whereKey($withdrawal->seller_wallet_id)->lockForUpdate()->firstOrFail();
        $existing = $wallet->transactions()
            ->where('seller_withdrawal_id', $withdrawal->id)
            ->where('type', SellerWalletTransactionType::WithdrawalReservation->value)
            ->first();

        if ($existing) {
            return $existing;
        }

        $amount = (int) $withdrawal->amount_minor;
        if ($amount < 1 || (int) $wallet->available_balance_minor < $amount) {
            throw ValidationException::withMessages(['amount' => 'The available wallet balance changed. Refresh the page and try again.']);
        }

        return $this->appendMovement(
            wallet: $wallet,
            type: SellerWalletTransactionType::WithdrawalReservation->value,
            amountMinor: $amount,
            availableDeltaMinor: -$amount,
            reservedDeltaMinor: $amount,
            withdrawal: $withdrawal,
            reference: $withdrawal->withdrawal_no,
            description: 'Funds reserved for a Seller withdrawal request.',
            actor: $actor,
            idempotencyKey: 'seller-withdrawal-reserved:'.$withdrawal->id,
        );
    }

    public function releaseWithdrawalReservation(
        SellerWithdrawal $withdrawal,
        User $actor,
        string $description,
    ): SellerWalletTransaction {
        $wallet = SellerWallet::query()->whereKey($withdrawal->seller_wallet_id)->lockForUpdate()->firstOrFail();
        $existing = $wallet->transactions()
            ->where('seller_withdrawal_id', $withdrawal->id)
            ->where('type', SellerWalletTransactionType::WithdrawalRelease->value)
            ->first();

        if ($existing) {
            return $existing;
        }

        $amount = (int) $withdrawal->amount_minor;
        if ($amount < 1 || (int) $wallet->reserved_balance_minor < $amount) {
            throw new LogicException('The reserved wallet balance does not reconcile with this withdrawal.');
        }

        return $this->appendMovement(
            wallet: $wallet,
            type: SellerWalletTransactionType::WithdrawalRelease->value,
            amountMinor: $amount,
            availableDeltaMinor: $amount,
            reservedDeltaMinor: -$amount,
            withdrawal: $withdrawal,
            reference: $withdrawal->withdrawal_no,
            description: $description,
            actor: $actor,
            idempotencyKey: 'seller-withdrawal-release:'.$withdrawal->id,
        );
    }

    public function completeWithdrawal(SellerWithdrawal $withdrawal, User $actor): SellerWalletTransaction
    {
        $wallet = SellerWallet::query()->whereKey($withdrawal->seller_wallet_id)->lockForUpdate()->firstOrFail();
        $existing = $wallet->transactions()
            ->where('seller_withdrawal_id', $withdrawal->id)
            ->where('type', SellerWalletTransactionType::WithdrawalCompletion->value)
            ->first();

        if ($existing) {
            return $existing;
        }

        $amount = (int) $withdrawal->amount_minor;
        if ($amount < 1 || (int) $wallet->reserved_balance_minor < $amount) {
            throw new LogicException('The reserved wallet balance does not reconcile with this withdrawal.');
        }

        return $this->appendMovement(
            wallet: $wallet,
            type: SellerWalletTransactionType::WithdrawalCompletion->value,
            amountMinor: $amount,
            reservedDeltaMinor: -$amount,
            withdrawnDeltaMinor: $amount,
            withdrawal: $withdrawal,
            reference: $withdrawal->transfer?->bank_reference ?: $withdrawal->withdrawal_no,
            description: 'Verified bank transfer completed for Seller withdrawal.',
            actor: $actor,
            idempotencyKey: 'seller-withdrawal-completed:'.$withdrawal->id,
        );
    }

    public function recordLegacyPayoutCompletion(SellerPayout $payout, ?User $actor = null): ?SellerWalletTransaction
    {
        if (! in_array(strtolower((string) $payout->status), ['paid', 'completed'], true)
            || ! $payout->paid_at
            || (int) $payout->net_minor < 1) {
            return null;
        }

        return DB::transaction(function () use ($payout, $actor): SellerWalletTransaction {
            $profile = SellerProfile::query()->whereKey($payout->seller_profile_id)->lockForUpdate()->firstOrFail();
            $wallet = $this->lockOrCreateWallet($profile);
            $existing = SellerWalletTransaction::query()->where('seller_payout_id', $payout->id)->first();

            if ($existing) {
                return $existing;
            }

            return $this->appendMovement(
                wallet: $wallet,
                type: SellerWalletTransactionType::LegacyWithdrawalCompletion->value,
                amountMinor: (int) $payout->net_minor,
                withdrawnDeltaMinor: (int) $payout->net_minor,
                legacyPayout: $payout,
                reference: $payout->reference,
                description: 'Historical paid Seller payout '.$payout->payout_no,
                actor: $actor,
                idempotencyKey: 'legacy-seller-payout:'.$payout->id,
            );
        }, attempts: 3);
    }

    /** @return array{available:int,pending:int,reserved:int,withdrawn:int} */
    public function ledgerTotals(SellerWallet $wallet): array
    {
        $totals = SellerWalletTransaction::query()
            ->where('seller_wallet_id', $wallet->id)
            ->selectRaw('COALESCE(SUM(available_delta_minor), 0) as available_total')
            ->selectRaw('COALESCE(SUM(pending_delta_minor), 0) as pending_total')
            ->selectRaw('COALESCE(SUM(reserved_delta_minor), 0) as reserved_total')
            ->selectRaw('COALESCE(SUM(withdrawn_delta_minor), 0) as withdrawn_total')
            ->first();

        return [
            'available' => (int) $totals->available_total,
            'pending' => (int) $totals->pending_total,
            'reserved' => (int) $totals->reserved_total,
            'withdrawn' => (int) $totals->withdrawn_total,
        ];
    }

    private function lockOrCreateWallet(SellerProfile $profile): SellerWallet
    {
        SellerWallet::query()->firstOrCreate(['seller_profile_id' => $profile->id]);

        return SellerWallet::query()
            ->where('seller_profile_id', $profile->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function creditPostedSalesAsPending(SellerWallet $wallet): void
    {
        $sources = SellerTransaction::query()
            ->where('seller_profile_id', $wallet->seller_profile_id)
            ->where('type', 'sale')
            ->whereIn('status', ['posted', 'completed', 'paid'])
            ->whereNotNull('posted_at')
            ->where('amount_minor', '>', 0)
            ->whereDoesntHave('payoutItem')
            ->whereDoesntHave('walletTransactions')
            ->orderBy('posted_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($sources as $source) {
            $this->appendMovement(
                wallet: $wallet,
                type: SellerWalletTransactionType::SaleEarningPending->value,
                amountMinor: (int) $source->amount_minor,
                pendingDeltaMinor: (int) $source->amount_minor,
                sellerTransaction: $source,
                reference: $source->transaction_no,
                description: 'Seller sale earnings are pending settlement eligibility.',
                idempotencyKey: 'seller-sale-pending:'.$source->id,
            );
        }
    }

    private function releaseEligiblePendingEarnings(SellerWallet $wallet): void
    {
        $entries = $wallet->transactions()
            ->where('type', SellerWalletTransactionType::SaleEarningPending->value)
            ->where('pending_delta_minor', '>', 0)
            ->with([
                'sellerTransaction.payment',
                'sellerTransaction.sellerOrder.shipments.codCashCollection',
                'sellerTransaction.walletTransactions' => fn (HasMany $query) => $query
                    ->where('type', SellerWalletTransactionType::SaleEarningAvailable->value),
            ])
            ->lockForUpdate()
            ->get();

        foreach ($entries as $entry) {
            $source = $entry->sellerTransaction;
            if (! $source
                || $source->walletTransactions->isNotEmpty()
                || ! $this->isSettlementEligible($source)) {
                continue;
            }

            $amount = (int) $entry->pending_delta_minor;
            if ($amount < 1 || (int) $wallet->pending_balance_minor < $amount) {
                throw new LogicException('Pending wallet balance does not reconcile with its ledger entry.');
            }

            $this->appendMovement(
                wallet: $wallet,
                type: SellerWalletTransactionType::SaleEarningAvailable->value,
                amountMinor: $amount,
                availableDeltaMinor: $amount,
                pendingDeltaMinor: -$amount,
                sellerTransaction: $source,
                reference: $source->transaction_no,
                description: 'Seller earnings became available after COD, order, and return eligibility checks.',
                idempotencyKey: 'seller-sale-available:'.$source->id,
            );
        }
    }

    private function isCreditCandidate(SellerTransaction $transaction): bool
    {
        return $transaction->type === 'sale'
            && in_array($transaction->status, ['posted', 'completed', 'paid'], true)
            && $transaction->posted_at !== null
            && (int) $transaction->amount_minor > 0;
    }

    private function isSettlementEligible(SellerTransaction $transaction): bool
    {
        if (! $this->isCreditCandidate($transaction)) {
            return false;
        }

        $sellerOrderId = (int) ($transaction->seller_order_id ?? 0);
        $orderId = (int) ($transaction->sellerOrder?->order_id ?? 0);
        if ($sellerOrderId < 1 || $orderId < 1) {
            return false;
        }

        // Return/refund workflows lock Order then SellerOrder before opening or
        // resolving cases. Take those locks before inspecting case state so a
        // boundary-time return request cannot race wallet eligibility release.
        $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
        if (! $order) {
            return false;
        }

        $sellerOrder = SellerOrder::query()
            ->whereKey($sellerOrderId)
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->first();
        if (! $sellerOrder) {
            return false;
        }
        $sellerOrder->load('shipments.codCashCollection');

        $payment = $transaction->payment;
        if (! $payment
            || ! in_array($payment->status, ['paid', 'partially_refunded', 'refunded'], true)
            || ! $payment->paid_at
            || ! $sellerOrder || $sellerOrder->status !== 'completed' || ! $sellerOrder->completed_at) {
            return false;
        }

        $returnDeadline = CarbonImmutable::parse($sellerOrder->completed_at)
            ->addDays(self::RETURN_WINDOW_DAYS)
            ->endOfDay();
        if (! now()->greaterThan($returnDeadline)) {
            return false;
        }

        $returnCases = ReturnRequest::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->lockForUpdate()
            ->get(['id', 'status']);
        if ($returnCases->contains(fn ($case): bool => ! in_array($case->status, ['resolved', 'rejected'], true))) {
            return false;
        }

        if ($returnCases->isNotEmpty() && Refund::query()
            ->whereIn('return_request_id', $returnCases->pluck('id'))
            ->whereIn('status', ['pending', 'processing'])
            ->lockForUpdate()
            ->exists()) {
            return false;
        }

        if ($payment->method !== 'cod') {
            return true;
        }

        $codShipments = $sellerOrder->shipments
            ->where('purpose', 'outbound')
            ->filter(fn ($shipment): bool => (int) $shipment->cod_amount_minor > 0);
        if ($codShipments->isEmpty()) {
            return false;
        }

        foreach ($codShipments as $shipment) {
            $collection = $shipment->codCashCollection;
            if (! $collection
                || $collection->status !== 'reconciled'
                || (int) $collection->payment_id !== (int) $payment->id
                || (int) $collection->amount_reconciled_minor !== (int) $shipment->cod_amount_minor) {
                return false;
            }
        }

        return true;
    }

    private function appendMovement(
        SellerWallet $wallet,
        string $type,
        int $amountMinor,
        int $availableDeltaMinor = 0,
        int $pendingDeltaMinor = 0,
        int $reservedDeltaMinor = 0,
        int $withdrawnDeltaMinor = 0,
        ?SellerTransaction $sellerTransaction = null,
        ?SellerWithdrawal $withdrawal = null,
        ?SellerPayout $legacyPayout = null,
        ?string $reference = null,
        ?string $description = null,
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): SellerWalletTransaction {
        $newAvailable = (int) $wallet->available_balance_minor + $availableDeltaMinor;
        $newPending = (int) $wallet->pending_balance_minor + $pendingDeltaMinor;
        $newReserved = (int) $wallet->reserved_balance_minor + $reservedDeltaMinor;
        $newWithdrawn = (int) $wallet->total_withdrawn_minor + $withdrawnDeltaMinor;

        if ($amountMinor < 1 || min($newAvailable, $newPending, $newReserved, $newWithdrawn) < 0) {
            throw new LogicException('A wallet movement would create an invalid or negative balance.');
        }

        $wallet->forceFill([
            'available_balance_minor' => $newAvailable,
            'pending_balance_minor' => $newPending,
            'reserved_balance_minor' => $newReserved,
            'total_withdrawn_minor' => $newWithdrawn,
        ])->save();

        return SellerWalletTransaction::query()->create([
            'transaction_no' => 'SWX-'.Str::upper((string) Str::ulid()),
            'seller_wallet_id' => $wallet->id,
            'seller_transaction_id' => $sellerTransaction?->id,
            'seller_withdrawal_id' => $withdrawal?->id,
            'seller_payout_id' => $legacyPayout?->id,
            'type' => $type,
            'amount_minor' => $amountMinor,
            'available_delta_minor' => $availableDeltaMinor,
            'pending_delta_minor' => $pendingDeltaMinor,
            'reserved_delta_minor' => $reservedDeltaMinor,
            'withdrawn_delta_minor' => $withdrawnDeltaMinor,
            'available_balance_after_minor' => $newAvailable,
            'pending_balance_after_minor' => $newPending,
            'reserved_balance_after_minor' => $newReserved,
            'total_withdrawn_after_minor' => $newWithdrawn,
            'reference' => $reference,
            'description' => $description,
            'idempotency_key' => $idempotencyKey,
            'actor_user_id' => $actor?->id,
            'created_at' => now(),
        ]);
    }
}
