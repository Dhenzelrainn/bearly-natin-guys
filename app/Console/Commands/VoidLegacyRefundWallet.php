<?php

namespace App\Console\Commands;

use App\Models\Refund;
use App\Models\SellerRefundRecovery;
use App\Models\SellerTransaction;
use App\Models\SellerWalletTransaction;
use App\Models\User;
use App\Services\AuditService;
use App\Services\SellerWalletService;
use Illuminate\Console\Command;

class VoidLegacyRefundWallet extends Command
{
    protected $signature = 'seller-wallet:void-legacy-refund
                            {refund : Refund ID or refund number}
                            {--apply : Apply the legacy void instead of only previewing it}
                            {--reason= : Reason recorded in the recovery event}
                            {--actor= : Admin user ID to record as the actor}';

    protected $description = 'Preview or close a pre-cutoff Seller refund as legacy history.';

    public function handle(SellerWalletService $wallets, AuditService $audit): int
    {
        $identifier = trim((string) $this->argument('refund'));
        $refund = Refund::query()
            ->where('refund_no', $identifier)
            ->when(ctype_digit($identifier), fn ($query) => $query->orWhere('id', (int) $identifier))
            ->with('returnRequest')
            ->first();

        if (! $refund) {
            $this->error('Refund not found.');

            return self::FAILURE;
        }

        $recovery = SellerRefundRecovery::query()
            ->where('refund_id', $refund->id)
            ->first();
        if (! $recovery) {
            $this->error('This refund has no Seller recovery balance.');

            return self::FAILURE;
        }

        $actor = $this->actor();
        if (! $actor) {
            $this->error('No active Admin actor was found. Pass --actor=<user id>.');

            return self::FAILURE;
        }

        $sale = SellerTransaction::query()
            ->where('seller_order_id', (int) ($refund->returnRequest?->seller_order_id ?? 0))
            ->where('type', 'sale')
            ->first();
        $pendingMinor = $sale
            ? (int) SellerWalletTransaction::query()->where('seller_transaction_id', $sale->id)->sum('pending_delta_minor')
            : 0;
        $availableMinor = $sale
            ? (int) SellerWalletTransaction::query()->where('seller_transaction_id', $sale->id)->sum('available_delta_minor')
            : 0;

        $this->table(
            ['Refund', 'Status', 'Completed at', 'Cutoff', 'Recovery', 'Outstanding', 'Sale pending', 'Sale available'],
            [[
                $refund->refund_no,
                $refund->status,
                $refund->completed_at?->toDateTimeString() ?? '—',
                (string) config('seller-wallet.refund_cutoff_at'),
                $recovery->status,
                '₱'.number_format($recovery->outstandingMinor() / 100, 2),
                '₱'.number_format($pendingMinor / 100, 2),
                '₱'.number_format($availableMinor / 100, 2),
            ]],
        );

        if ($recovery->status === 'voided') {
            $this->info('This refund is already marked as a legacy void. No changes were made.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->warn('Preview only. Re-run with --apply to close this legacy refund.');

            return self::SUCCESS;
        }

        $reason = trim((string) ($this->option('reason') ?: 'Legacy pre-cutoff refund closed as void.'));
        $result = $wallets->voidLegacyRefund($refund, $actor, $reason);
        $movement = $result['wallet_transaction'];

        $audit->record(
            action: 'seller_wallet.legacy_refund_voided',
            module: 'Seller Wallet',
            subject: $refund,
            description: "Legacy refund {$refund->refund_no} was closed as void by command.",
            oldValues: [
                'recovery_status' => $recovery->status,
                'outstanding_minor' => $recovery->outstandingMinor(),
            ],
            newValues: [
                'recovery_status' => $result['recovery']->status,
                'wallet_transaction_no' => $movement?->transaction_no,
                'wallet_amount_minor' => $movement?->amount_minor,
                'reason' => $reason,
            ],
            severity: 'warning',
            actor: $actor,
        );

        $this->info($movement
            ? "Legacy refund closed. Wallet movement {$movement->transaction_no} removed ₱".number_format($movement->amount_minor / 100, 2).'.'
            : 'Legacy refund recovery closed as void. No wallet balance needed clearing.');

        return self::SUCCESS;
    }

    private function actor(): ?User
    {
        $actorId = $this->option('actor');

        $query = User::query()
            ->where('role', 'admin')
            ->where('status', 'active');

        return $actorId
            ? $query->whereKey((int) $actorId)->first()
            : $query->orderBy('id')->first();
    }
}
