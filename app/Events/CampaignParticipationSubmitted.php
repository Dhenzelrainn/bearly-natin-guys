<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CampaignParticipationSubmitted implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly int $campaignId) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('admin.campaigns')];
    }

    public function broadcastAs(): string
    {
        return 'campaign.participation-submitted';
    }

    /** @return array{campaign_id: int} */
    public function broadcastWith(): array
    {
        return ['campaign_id' => $this->campaignId];
    }
}
