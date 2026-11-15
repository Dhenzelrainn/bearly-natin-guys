<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShippingPolicySubmissionAvailable implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<int>  $adminUserIds
     */
    public function __construct(public readonly array $adminUserIds) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return collect($this->adminUserIds)
            ->map(fn ($userId): int => (int) $userId)
            ->filter(fn (int $userId): bool => $userId > 0)
            ->unique()
            ->map(fn (int $userId): PrivateChannel => new PrivateChannel('users.'.$userId))
            ->values()
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'shipping-policy.submitted';
    }

    /** @return array<string, bool> */
    public function broadcastWith(): array
    {
        return ['queue_updated' => true];
    }
}
