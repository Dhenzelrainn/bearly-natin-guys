<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LogisticsRefundCashPayoutUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $logisticsUserId,
        public readonly int $payoutId,
        public readonly string $payoutNo,
        public readonly string $status,
        public readonly string $updatedAt,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->logisticsUserId)];
    }

    public function broadcastAs(): string
    {
        return 'logistics.refund-cash-updated';
    }

    /** @return array<string, int|string> */
    public function broadcastWith(): array
    {
        return [
            'payout_id' => $this->payoutId,
            'payout_no' => $this->payoutNo,
            'status' => $this->status,
            'updated_at' => $this->updatedAt,
        ];
    }
}
