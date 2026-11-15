<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RiderLocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $logisticsUserId,
        public readonly int $riderUserId,
        public readonly bool $locationCleared,
        public readonly string $updatedAt,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return $this->logisticsUserId > 0
            ? [new PrivateChannel('users.'.$this->logisticsUserId)]
            : [];
    }

    public function broadcastAs(): string
    {
        return 'rider.location-updated';
    }

    /** @return array<string, bool|int|string> */
    public function broadcastWith(): array
    {
        return [
            'rider_user_id' => $this->riderUserId,
            'location_cleared' => $this->locationCleared,
            'updated_at' => $this->updatedAt,
        ];
    }
}
