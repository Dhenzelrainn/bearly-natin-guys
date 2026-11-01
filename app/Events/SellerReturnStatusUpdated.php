<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SellerReturnStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $sellerUserId,
        public readonly int $returnRequestId,
        public readonly string $returnNo,
        public readonly string $status,
        public readonly string $updatedAt,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->sellerUserId)];
    }

    public function broadcastAs(): string
    {
        return 'seller.return-status-updated';
    }

    /** @return array<string, int|string> */
    public function broadcastWith(): array
    {
        return [
            'return_request_id' => $this->returnRequestId,
            'return_no' => $this->returnNo,
            'status' => $this->status,
            'updated_at' => $this->updatedAt,
        ];
    }
}
