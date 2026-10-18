<?php

namespace App\Notifications;

class SellerWithdrawalStatusNotification extends BearlyNotification
{
    public function __construct(
        private readonly int $withdrawalId,
        private readonly string $withdrawalNo,
        private readonly int $amountMinor,
        private readonly string $status,
        private readonly ?string $reason = null,
    ) {}

    public function eventKey(): string
    {
        return 'seller_withdrawal_status_changed';
    }

    public function title(): string
    {
        return match ($this->status) {
            'pending_review' => 'Withdrawal submitted',
            'approved' => 'Withdrawal approved',
            'processing' => 'Withdrawal processing',
            'completed' => 'Withdrawal completed',
            'rejected' => 'Withdrawal rejected',
            'failed' => 'Withdrawal transfer failed',
            default => 'Withdrawal status updated',
        };
    }

    public function body(): string
    {
        return $this->message();
    }

    public function type(): string
    {
        return match ($this->status) {
            'approved', 'completed' => 'success',
            'rejected', 'failed' => 'danger',
            default => 'warning',
        };
    }

    public function actionUrl(): ?string
    {
        return route('seller.finance.wallet');
    }

    /**
     * Keep the historical stored payload unchanged; existing consumers and
     * regression tests depend on these exact keys.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'event' => 'seller_withdrawal_status_changed',
            'withdrawal_id' => $this->withdrawalId,
            'withdrawal_no' => $this->withdrawalNo,
            'amount_minor' => $this->amountMinor,
            'status' => $this->status,
            'reason' => $this->reason,
            'message' => $this->message(),
        ];
    }

    private function message(): string
    {
        return match ($this->status) {
            'pending_review' => "Withdrawal {$this->withdrawalNo} was submitted and is waiting for Finance review.",
            'approved' => "Withdrawal {$this->withdrawalNo} was approved.",
            'processing' => "Finance is processing withdrawal {$this->withdrawalNo}.",
            'completed' => "Withdrawal {$this->withdrawalNo} is complete. Finance verified the bank transfer.",
            'rejected' => "Withdrawal {$this->withdrawalNo} was rejected.",
            'failed' => "The transfer for withdrawal {$this->withdrawalNo} failed. The reserved funds were returned to your available balance.",
            default => "Withdrawal {$this->withdrawalNo} status changed.",
        };
    }
}
