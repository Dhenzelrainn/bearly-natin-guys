<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RiderOperationalStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $riderUserId,
        public readonly int $logisticsUserId,
        public readonly string $accountStatus,
        public readonly string $availabilityStatus,
        public readonly string $updatedAt,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [];

        foreach (array_unique([
            $this->riderUserId,
            $this->logisticsUserId,
        ]) as $userId) {
            if ($userId > 0) {
                $channels[] = new PrivateChannel('users.'.$userId);
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'rider.operational-status-updated';
    }

    /** @return array<string, int|string> */
    public function broadcastWith(): array
    {
        return [
            'rider_user_id' => $this->riderUserId,
            'account_status' => $this->accountStatus,
            'availability_status' => $this->availabilityStatus,
            'updated_at' => $this->updatedAt,
        ];
    }
}
