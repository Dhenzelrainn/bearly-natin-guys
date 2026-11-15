<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RiderShippingEarningUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $riderUserId,
        public readonly int $logisticsUserId,
        public readonly int $earningId,
        public readonly string $status,
        public readonly string $updatedAt,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('users.'.$this->riderUserId),
            new PrivateChannel('users.'.$this->logisticsUserId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'shipping-settlement.rider-earning-updated';
    }

    /** @return array<string, int|string> */
    public function broadcastWith(): array
    {
        return [
            'earning_id' => $this->earningId,
            'status' => $this->status,
            'updated_at' => $this->updatedAt,
        ];
    }
}
