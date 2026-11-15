<?php

namespace App\Console\Commands;

use App\Models\PlatformPromotion;
use App\Services\CampaignAvailabilityNotificationService;
use Illuminate\Console\Command;

class NotifyCampaignSellers extends Command
{
    protected $signature = 'campaigns:notify-eligible-sellers {campaign : The Bearly campaign ID}';

    protected $description = 'Deliver any missing in-app notifications for an open or live Bearly campaign.';

    public function handle(CampaignAvailabilityNotificationService $notifications): int
    {
        $campaignId = filter_var($this->argument('campaign'), FILTER_VALIDATE_INT);

        if ($campaignId === false || $campaignId < 1) {
            $this->error('Provide a valid Bearly campaign ID.');

            return self::FAILURE;
        }

        $campaign = PlatformPromotion::query()
            ->campaigns()
            ->find($campaignId);

        if (! $campaign) {
            $this->error('Bearly campaign not found.');

            return self::FAILURE;
        }

        $count = $notifications->deliverMissingForCurrentCampaign($campaign);
        $this->info("Delivered missing campaign notifications to {$count} eligible Seller(s).");

        return self::SUCCESS;
    }
}
