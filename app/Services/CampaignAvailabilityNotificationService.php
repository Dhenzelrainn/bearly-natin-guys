<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\PlatformPromotion;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CampaignAvailabilityNotificationService
{
    public const EVENT_KEY = 'seller_campaign_available';

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Notify eligible Sellers when an open campaign is first made available.
     * Delivery is deferred until the caller's transaction commits.
     */
    public function notifyWhenFirstOpened(PlatformPromotion $campaign): int
    {
        return $this->deliverMissing($campaign, allowCurrentCampaign: false);
    }

    /**
     * Idempotent backfill for an already-open or live campaign.
     */
    public function deliverMissingForCurrentCampaign(PlatformPromotion $campaign): int
    {
        return $this->deliverMissing($campaign, allowCurrentCampaign: true);
    }

    private function deliverMissing(PlatformPromotion $campaign, bool $allowCurrentCampaign): int
    {
        return DB::transaction(function () use ($campaign, $allowCurrentCampaign): int {
            $campaign = PlatformPromotion::query()
                ->campaigns()
                ->whereKey($campaign->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->isNotifiable($campaign, $allowCurrentCampaign)) {
                return 0;
            }

            $campaign->loadMissing(['stores:id', 'categories:id', 'products:id']);
            $storeIds = $campaign->stores->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $categoryIds = $campaign->categories->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $productIds = $campaign->products->pluck('id')->map(fn ($id): int => (int) $id)->all();

            $alreadyNotified = DB::table('notifications')
                ->select('notifiable_id')
                ->where('notifiable_type', (new User)->getMorphClass())
                ->where('type', WorkflowNotification::class)
                ->where('data->event', self::EVENT_KEY)
                ->where('data->meta->campaign_id', (int) $campaign->id);

            $recipients = User::query()
                ->where('status', AccountStatus::Active->value)
                ->where(function (Builder $roles): void {
                    $roles->where('role', UserRole::Seller->value)
                        ->orWhereHas(
                            'roles',
                            fn (Builder $assignedRoles) => $assignedRoles->where('roles.name', UserRole::Seller->value),
                        );
                })
                ->whereHas('sellerProfile.store', function (Builder $stores) use ($storeIds, $categoryIds, $productIds): void {
                    $stores->visibleToBuyers()
                        ->when($storeIds !== [], fn (Builder $query) => $query->whereIn('stores.id', $storeIds))
                        ->whereHas('products', function (Builder $products) use ($categoryIds, $productIds): void {
                            $products->visibleToBuyers()
                                ->when($categoryIds !== [], fn (Builder $query) => $query->whereIn('category_id', $categoryIds))
                                ->when($productIds !== [], fn (Builder $query) => $query->whereIn('products.id', $productIds))
                                ->whereHas('variants', fn (Builder $variants) => $variants
                                    ->where('is_active', true)
                                    ->whereColumn('stock_on_hand', '>', 'stock_reserved'));
                        });
                })
                ->whereNotIn('users.id', $alreadyNotified)
                ->orderBy('users.id');

            $notification = new WorkflowNotification(
                eventKey: self::EVENT_KEY,
                title: 'New Bearly campaign: '.$campaign->name,
                body: '"'.$campaign->name.'" is available in Seller Center. Review its schedule and eligibility.',
                actionUrl: route('seller.promotions.campaigns.show', $campaign),
                type: 'info',
                meta: [
                    'campaign_id' => (int) $campaign->id,
                    'campaign_type' => (string) $campaign->campaign_type,
                ],
            );

            $notified = 0;
            $recipients->chunkById(250, function (Collection $users) use ($notification, &$notified): void {
                $this->notifications->sendManyAfterCommit($users, $notification);
                $notified += $users->count();
            });

            return $notified;
        }, attempts: 3);
    }

    private function isNotifiable(PlatformPromotion $campaign, bool $allowCurrentCampaign): bool
    {
        if ($campaign->endsAt()?->lessThan(now('Asia/Manila')) ?? true) {
            return false;
        }

        if ($campaign->status === PlatformPromotion::STATUS_OPEN) {
            return $allowCurrentCampaign || $campaign->isJoinable();
        }

        return $allowCurrentCampaign && $campaign->status === PlatformPromotion::STATUS_ACTIVE;
    }
}
