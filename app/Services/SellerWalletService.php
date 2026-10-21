<?php

namespace App\Services;

use App\Enums\SellerWalletTransactionType;
use App\Models\Order;
use App\Models\Refund;
use App\Models\ReturnRequest;
use App\Models\SellerOrder;
use App\Models\SellerPayout;
use App\Models\SellerProfile;
use App\Models\SellerRefundRecovery;
use App\Models\SellerRefundRecoveryEvent;
use App\Models\SellerTransaction;
use App\Models\SellerWallet;
use App\Models\SellerWalletTransaction;
use App\Models\SellerWithdrawal;
use App\Models\User;
use Carbon\CarbonImmutable;
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
            $this->applyCompletedRefundAdjustments($wallet);
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

    public function recordRefundAdjustment(Refund $refund, ?User $actor = null): ?SellerWalletTransaction
    {
        if (! $this->isRefundAdjustmentEligible($refund)) {
            return null;
        }

        $recovery = SellerRefundRecovery::query()
            ->where('refund_id', $refund->id)
            ->first();

        if (! $recovery) {
            return null;
        }

        return DB::transaction(function () use ($recovery, $actor): ?SellerWalletTransaction {
            $profile = SellerProfile::query()
                ->whereKey($recovery->seller_profile_id)
                ->lockForUpdate()
                ->first();

            if (! $profile) {
                return null;
            }

            $wallet = $this->lockOrCreateWallet($profile);

            return $this->applyRefundAdjustmentLocked($wallet, $recovery, $actor);
        }, attempts: 3);
    }

    public function outstandingRecoveryMinor(SellerProfile $profile): int
    {
        return $this->outstandingRecoveryMinorForSellerId((int) $profile->id);
    }

    public function outstandingRecoveryMinorForSellerId(int $sellerProfileId): int
    {
        return (int) SellerRefundRecovery::query()
            ->where('seller_profile_id', $sellerProfileId)
            ->whereIn('status', ['pending', 'partially_recovered'])
            ->selectRaw('COALESCE(SUM(amount_minor - recovered_amount_minor), 0) as outstanding')
            ->value('outstanding');
    }

    /**
     * Close a pre-cutoff Seller refund as legacy history. This deliberately
     * writes off the remaining Seller balance instead of pretending that COD
     * cash was collected or that a wallet deduction already happened.
     *
     * @return array{wallet_transaction: ?SellerWalletTransaction, recovery: SellerRefundRecovery}
     */
    public function voidLegacyRefund(Refund $refund, User $actor, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Enter a short reason for closing this legacy refund.']);
        }
        if ($refund->status !== 'completed' || ! $refund->completed_at) {
            throw ValidationException::withMessages(['refund' => 'Only completed refunds can be closed as legacy.']);
        }
        if (CarbonImmutable::parse($refund->completed_at)->greaterThanOrEqualTo($this->refundCutoff())) {
            throw ValidationException::withMessages(['refund' => 'Refunds completed on or after the cutoff must use the automatic wallet adjustment workflow.']);
        }

        $recoveryStub = SellerRefundRecovery::query()
            ->where('refund_id', $refund->id)
            ->first();

        if (! $recoveryStub) {
            throw ValidationException::withMessages(['refund' => 'This refund has no Seller recovery balance to close.']);
        }

        return DB::transaction(function () use ($refund, $actor, $reason, $recoveryStub): array {
            $profile = SellerProfile::query()
                ->whereKey($recoveryStub->seller_profile_id)
                ->lockForUpdate()
                ->firstOrFail();
            $wallet = $this->lockOrCreateWallet($profile);
            $recovery = SellerRefundRecovery::query()
                ->whereKey($recoveryStub->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($recovery->status === 'voided') {
                return ['wallet_transaction' => null, 'recovery' => $recovery];
            }

            if (SellerWalletTransaction::query()->where('refund_id', $refund->id)->exists()) {
                throw ValidationException::withMessages(['refund' => 'This refund already has a wallet movement and cannot be closed as a legacy void.']);
            }

            $lockedRefund = Refund::query()
                ->whereKey($refund->id)
                ->with('returnRequest')
                ->lockForUpdate()
                ->firstOrFail();
            $sale = $this->saleTransactionForRefund($lockedRefund);
            $salePending = 0;
            $saleAvailable = 0;

            if ($sale) {
                $salePending = (int) SellerWalletTransaction::query()
                    ->where('seller_transaction_id', $sale->id)
                    ->sum('pending_delta_minor');
                $saleAvailable = (int) SellerWalletTransaction::query()
                    ->where('seller_transaction_id', $sale->id)
                    ->sum('available_delta_minor');
            }

            $fromPending = min($salePending, (int) $wallet->pending_balance_minor);
            $fromAvailable = min($saleAvailable, (int) $wallet->available_balance_minor);
            $amountMinor = $fromPending + $fromAvailable;
            $movement = null;

            if ($amountMinor > 0) {
                $movement = $this->appendMovement(
                    wallet: $wallet,
                    type: SellerWalletTransactionType::ManualAdjustment->value,
                    amountMinor: $amountMinor,
                    availableDeltaMinor: -$fromAvailable,
                    pendingDeltaMinor: -$fromPending,
                    sellerTransaction: $sale,
                    refund: $lockedRefund,
                    reference: 'LEGACY-VOID-'.$lockedRefund->refund_no,
                    description: 'Legacy pre-cutoff refund closed as void; no payout or recovery was collected.',
                    actor: $actor,
                    idempotencyKey: 'legacy-refund-void:'.$lockedRefund->id,
                    metadata: [
                        'legacy_void' => true,
                        'refund_id' => (int) $lockedRefund->id,
                        'seller_refund_recovery_id' => (int) $recovery->id,
                        'reason' => $reason,
                    ],
                );
            }

            $recovery->update(['status' => 'voided']);
            SellerRefundRecoveryEvent::query()->create([
                'seller_refund_recovery_id' => $recovery->id,
                'recorded_by' => $actor->id,
                'seller_payout_id' => null,
                'method' => 'legacy_void',
                'amount_minor' => 0,
                'external_reference' => 'LEGACY-VOID-'.$lockedRefund->refund_no,
                'recovered_on' => now()->toDateString(),
                'note' => $reason,
                'created_at' => now(),
            ]);

            return ['wallet_transaction' => $movement, 'recovery' => $recovery->fresh()];
        }, attempts: 3);
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
                || $source->walletTransactions()->whereIn('type', [
                    SellerWalletTransactionType::SaleEarningPending->value,
                    SellerWalletTransactionType::SaleEarningAvailable->value,
                ])->exists()) {
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
            ->whereDoesntHave('walletTransactions', fn ($query) => $query->whereIn('type', [
                SellerWalletTransactionType::SaleEarningPending->value,
                SellerWalletTransactionType::SaleEarningAvailable->value,
            ]))
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
                'sellerTransaction.walletTransactions',
            ])
            ->lockForUpdate()
            ->get();

        foreach ($entries as $entry) {
            $source = $entry->sellerTransaction;
            if (! $source || ! $this->isSettlementEligible($source)) {
                continue;
            }

            $hasAvailable = $source->walletTransactions->contains(
                fn (SellerWalletTransaction $transaction): bool => $transaction->type === SellerWalletTransactionType::SaleEarningAvailable->value,
            );
            $amount = (int) $source->walletTransactions->sum('pending_delta_minor');

            if ($hasAvailable || $amount < 1) {
                continue;
            }
            if ((int) $wallet->pending_balance_minor < $amount) {
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

    private function applyCompletedRefundAdjustments(SellerWallet $wallet): void
    {
        $recoveries = SellerRefundRecovery::query()
            ->where('seller_profile_id', $wallet->seller_profile_id)
            ->whereIn('status', ['pending', 'partially_recovered'])
            ->whereHas('refund', fn ($query) => $query
                ->where('status', 'completed')
                ->where('financially_responsible_party', 'seller'))
            ->whereDoesntHave('refund.walletTransactions')
            ->with('refund.returnRequest')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($recoveries as $recovery) {
            if (! $recovery->refund || ! $this->isRefundAdjustmentEligible($recovery->refund)) {
                continue;
            }

            $this->applyRefundAdjustmentLocked($wallet, $recovery, null);
        }
    }

    private function applyRefundAdjustmentLocked(
        SellerWallet $wallet,
        SellerRefundRecovery $recovery,
        ?User $actor,
    ): ?SellerWalletTransaction {
        $recovery = SellerRefundRecovery::query()
            ->whereKey($recovery->id)
            ->lockForUpdate()
            ->first();

        if (! $recovery || ! in_array($recovery->status, ['pending', 'partially_recovered'], true)) {
            return null;
        }

        $outstanding = $recovery->outstandingMinor();
        if ($outstanding < 1) {
            return null;
        }

        $existing = SellerWalletTransaction::query()
            ->where('refund_id', $recovery->refund_id)
            ->first();
        if ($existing) {
            return $existing->type === SellerWalletTransactionType::RefundAdjustment->value ? $existing : null;
        }

        $refund = Refund::query()
            ->whereKey($recovery->refund_id)
            ->with('returnRequest')
            ->first();
        if (! $refund || ! $this->isRefundAdjustmentEligible($refund)) {
            return null;
        }

        $sale = $this->saleTransactionForRefund($refund);
        if (! $sale) {
            return null;
        }

        $salePending = (int) SellerWalletTransaction::query()
            ->where('seller_transaction_id', $sale->id)
            ->sum('pending_delta_minor');
        $saleAvailable = (int) SellerWalletTransaction::query()
            ->where('seller_transaction_id', $sale->id)
            ->sum('available_delta_minor');

        $fromPending = min($salePending, (int) $wallet->pending_balance_minor, $outstanding);
        $remaining = $outstanding - $fromPending;
        $fromAvailable = min($saleAvailable, (int) $wallet->available_balance_minor, $remaining);

        if ($fromPending + $fromAvailable < $outstanding) {
            return null;
        }

        $movement = $this->appendMovement(
            wallet: $wallet,
            type: SellerWalletTransactionType::RefundAdjustment->value,
            amountMinor: $outstanding,
            availableDeltaMinor: -$fromAvailable,
            pendingDeltaMinor: -$fromPending,
            sellerTransaction: $sale,
            refund: $refund,
            reference: $refund->refund_no,
            description: 'Completed Seller-responsible refund deducted from the Seller Wallet.',
            actor: $actor,
            idempotencyKey: 'seller-refund-adjustment:'.$refund->id,
            metadata: [
                'refund_id' => (int) $refund->id,
                'seller_refund_recovery_id' => (int) $recovery->id,
                'wallet_offset_pending_minor' => $fromPending,
                'wallet_offset_available_minor' => $fromAvailable,
            ],
        );

        $recovery->update([
            'recovered_amount_minor' => (int) $recovery->amount_minor,
            'status' => 'recovered',
        ]);
        SellerRefundRecoveryEvent::query()->create([
            'seller_refund_recovery_id' => $recovery->id,
            'recorded_by' => $actor?->id,
            'seller_payout_id' => null,
            'method' => 'wallet_offset',
            'amount_minor' => $outstanding,
            'external_reference' => $movement->transaction_no,
            'recovered_on' => now()->toDateString(),
            'note' => 'Deducted automatically from the Seller Wallet when the refund was completed.',
            'created_at' => now(),
        ]);

        return $movement;
    }

    private function saleTransactionForRefund(Refund $refund): ?SellerTransaction
    {
        $sellerOrderId = (int) ($refund->returnRequest?->seller_order_id ?? 0);
        if ($sellerOrderId < 1) {
            return null;
        }

        return SellerTransaction::query()
            ->where('seller_order_id', $sellerOrderId)
            ->where('type', 'sale')
            ->lockForUpdate()
            ->first();
    }

    private function isRefundAdjustmentEligible(Refund $refund): bool
    {
        return $refund->status === 'completed'
            && $refund->financially_responsible_party === 'seller'
            && $refund->completed_at !== null
            && CarbonImmutable::parse($refund->completed_at)->greaterThanOrEqualTo($this->refundCutoff());
    }

    private function refundCutoff(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            (string) config('seller-wallet.refund_cutoff_at', '2026-10-21 00:00:00'),
            'UTC',
        );
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
        ?Refund $refund = null,
        ?SellerWithdrawal $withdrawal = null,
        ?SellerPayout $legacyPayout = null,
        ?string $reference = null,
        ?string $description = null,
        ?User $actor = null,
        ?string $idempotencyKey = null,
        ?array $metadata = null,
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
            'refund_id' => $refund?->id,
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
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
