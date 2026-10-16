<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SellerWithdrawalStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly int $withdrawalId,
        private readonly string $withdrawalNo,
        private readonly int $amountMinor,
        private readonly string $status,
        private readonly ?string $reason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $message = match ($this->status) {
            'pending_review' => "Withdrawal {$this->withdrawalNo} was submitted and is waiting for Finance review.",
            'approved' => "Withdrawal {$this->withdrawalNo} was approved.",
            'processing' => "Finance is processing withdrawal {$this->withdrawalNo}.",
            'completed' => "Withdrawal {$this->withdrawalNo} is complete. Finance verified the bank transfer.",
            'rejected' => "Withdrawal {$this->withdrawalNo} was rejected.",
            'failed' => "The transfer for withdrawal {$this->withdrawalNo} failed. The reserved funds were returned to your available balance.",
            default => "Withdrawal {$this->withdrawalNo} status changed.",
        };

        return [
            'event' => 'seller_withdrawal_status_changed',
            'withdrawal_id' => $this->withdrawalId,
            'withdrawal_no' => $this->withdrawalNo,
            'amount_minor' => $this->amountMinor,
            'status' => $this->status,
            'reason' => $this->reason,
            'message' => $message,
        ];
    }
}
