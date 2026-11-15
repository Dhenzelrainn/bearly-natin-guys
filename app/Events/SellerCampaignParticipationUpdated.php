<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SellerCampaignParticipationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $sellerUserId,
        public readonly int $campaignId,
        public readonly int $participationId,
        public readonly string $updatedAt,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->sellerUserId)];
    }

    public function broadcastAs(): string
    {
        return 'seller.campaign-participation-updated';
    }

    /** @return array<string, int|string> */
    public function broadcastWith(): array
    {
        return [
            'campaign_id' => $this->campaignId,
            'participation_id' => $this->participationId,
            'updated_at' => $this->updatedAt,
        ];
    }
}
