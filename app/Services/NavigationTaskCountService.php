<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ParcelStatus;
use App\Enums\ReturnRequestStatus;
use App\Enums\RiderWorkType;
use App\Enums\SellerOrderStatus;
use App\Enums\SellerWithdrawalStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\CodCashCollection;
use App\Models\Dispute;
use App\Models\LinehaulRun;
use App\Models\PickupRequest;
use App\Models\PlatformCampaignParticipation;
use App\Models\PolicyVersion;
use App\Models\ProductViolation;
use App\Models\RefundCashPayout;
use App\Models\ReturnRequest;
use App\Models\RiderVehicleChangeRequest;
use App\Models\SellerOrder;
use App\Models\SellerWithdrawal;
use App\Models\ShipmentTransfer;
use App\Models\User;
use App\Models\Waybill;
use Illuminate\Support\Facades\DB;

class NavigationTaskCountService
{
    /**
     * Return counts keyed by the destination route used in the role navigation.
     * Counts are derived from persisted workflow state; visiting a destination
     * has no side effect on them.
     *
     * @return array<string, int>
     */
    public function forUser(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return match ($user->role) {
            UserRole::Admin->value => $this->adminCounts(),
            UserRole::Seller->value => $this->sellerCounts($user),
            UserRole::Logistics->value => $this->logisticsCounts($user),
            UserRole::Rider->value => $this->riderCounts($user),
            default => [],
        };
    }

    /** @return array<string, int> */
    private function adminCounts(): array
    {
        $applicationCounts = AccountApplication::query()
            ->join('roles', 'roles.id', '=', 'account_applications.requested_role_id')
            ->join('users', 'users.id', '=', 'account_applications.user_id')
            ->whereIn('roles.name', UserRole::adminReviewQueueRoles())
            ->whereIn('account_applications.status', ['pending', 'submitted', 'under_review', 'needs_revision'])
            ->whereIn('users.status', [AccountStatus::Pending->value, AccountStatus::NeedsRevision->value])
            ->selectRaw('roles.name as requested_role, COUNT(account_applications.id) as task_count')
            ->groupBy('roles.name')
            ->get()
            ->keyBy('requested_role');

        $allApplicationsCount = AccountApplication::query()
            ->join('roles', 'roles.id', '=', 'account_applications.requested_role_id')
            ->join('users', 'users.id', '=', 'account_applications.user_id')
            ->whereIn('roles.name', UserRole::adminReviewQueueRoles())
            ->whereIn('account_applications.status', ['submitted', 'under_review', 'needs_revision'])
            ->whereIn('users.status', [AccountStatus::Pending->value, AccountStatus::NeedsRevision->value])
            ->count('account_applications.id');

        $sellerApplications = (int) ($applicationCounts->get(UserRole::Seller->value)?->task_count ?? 0);
        $logisticsApplications = (int) ($applicationCounts->get(UserRole::Logistics->value)?->task_count ?? 0);
        $providerKey = (string) config('shipping.provider_key');

        return [
            'admin.registrations' => $allApplicationsCount,
            'admin.registrations.sellers' => $sellerApplications,
            'admin.registrations.logistics' => $logisticsApplications,
            'admin.compliance.violations' => ProductViolation::query()
                ->whereIn('status', ['flagged', 'under_review', 'correction_requested'])
                ->count(),
            'admin.disputes' => Dispute::query()
                ->whereNotIn('status', ['resolved', 'closed'])
                ->count(),
            'admin.compliance.returns-refunds' => ReturnRequest::query()
                ->whereIn('status', [
                    ReturnRequestStatus::UnderReview->value,
                    ReturnRequestStatus::RefundPending->value,
                ])
                ->count(),
            'admin.seller-payouts.index' => SellerWithdrawal::query()
                ->whereIn('status', [
                    SellerWithdrawalStatus::PendingReview->value,
                    SellerWithdrawalStatus::Approved->value,
                    SellerWithdrawalStatus::Processing->value,
                ])
                ->count(),
            'admin.campaigns' => PlatformCampaignParticipation::query()
                ->where('status', PlatformCampaignParticipation::STATUS_PENDING_REVIEW)
                ->count(),
            'admin.shipping-policy-approvals.index' => $providerKey === ''
                ? 0
                : PolicyVersion::query()
                    ->where('status', 'pending_approval')
                    ->whereHas('policy', fn ($query) => $query
                        ->forProvider($providerKey)
                        ->where('status', '!=', 'archived'))
                    ->count(),
        ];
    }

    /** @return array<string, int> */
    private function sellerCounts(User $user): array
    {
        $storeId = $user->sellerProfile?->store?->id;

        if (! $storeId) {
            return [];
        }

        return [
            'seller.orders' => SellerOrder::query()
                ->where('store_id', $storeId)
                ->whereIn('status', [
                    'pending',
                    'placed',
                    SellerOrderStatus::Accepted->value,
                    SellerOrderStatus::Packing->value,
                ])
                ->count(),
            'seller.orders.returns' => ReturnRequest::query()
                ->where('store_id', $storeId)
                ->where(function ($query): void {
                    $query->whereIn('status', [
                        ReturnRequestStatus::AwaitingSeller->value,
                        ReturnRequestStatus::AwaitingInspection->value,
                    ])->orWhereHas('items', fn ($items) => $items->where('quarantine_review_status', 'pending'));
                })
                ->count(),
            'seller.products.pricing' => app(CampaignService::class)->joinableCountFor($user),
        ];
    }

    /** @return array<string, int> */
    private function logisticsCounts(User $user): array
    {
        $profile = $user->logisticsProfile;

        if (! $profile) {
            return [];
        }

        $cashCustodyCount = CodCashCollection::query()
            ->where('logistics_profile_id', $profile->id)
            ->whereIn('status', ['collected', 'disputed', 'received_by_logistics'])
            ->count();

        $cashCustodyCount += RefundCashPayout::query()
            ->where('logistics_profile_id', $profile->id)
            ->whereIn('status', [
                'awaiting_logistics_receipt',
                'awaiting_rider_assignment',
                'assigned_to_rider',
                'logistics_return_verification',
                'delivered_pending_verification',
            ])
            ->count();

        return [
            'logistics.riders.vehicle-changes' => RiderVehicleChangeRequest::query()
                ->where('status', 'pending')
                ->whereHas('riderProfile', fn ($query) => $query
                    ->where('logistics_profile_id', $profile->id))
                ->count(),
            'logistics.pickups.index' => PickupRequest::query()
                ->forLogisticsProfile((int) $profile->id)
                ->whereIn('status', ['requested', 'verified'])
                ->count(),
            'logistics.cash.index' => $cashCustodyCount,
            'logistics.sorting.incoming' => Waybill::query()
                ->forLogisticsProfile((int) $profile->id)
                ->whereHas('shipment', fn ($query) => $query
                    ->where('status', '!=', ShipmentStatus::HandoffPending->value))
                ->whereHas('parcels', fn ($query) => $query
                    ->where(function ($query) use ($profile): void {
                        $query->where(function ($atProviderCenter) use ($profile): void {
                            $atProviderCenter
                                ->whereHas('currentSortingCenter', fn ($center) => $center
                                    ->where('logistics_profile_id', $profile->id)
                                    ->where('status', 'active'))
                                ->where('status', '!=', ParcelStatus::InTransit->value);
                        })->orWhere('status', ParcelStatus::PickedUp->value);
                    }))
                ->count(),
            'logistics.transfers.index' => ShipmentTransfer::query()
                ->where('to_logistics_profile_id', $profile->id)
                ->where('status', 'sent')
                ->count() + app(ShipmentHandoffService::class)->manifestReadyCountFor($profile),
        ];
    }

    /** @return array<string, int> */
    private function riderCounts(User $user): array
    {
        $profile = $user->riderProfile;

        if (! $profile) {
            return [];
        }

        if ($profile->work_type === RiderWorkType::Courier->value) {
            return [
                'rider.courier.dashboard' => LinehaulRun::query()
                    ->where('courier_rider_profile_id', $profile->id)
                    ->whereIn('status', ['assigned', 'loading', 'loaded', 'departed', 'arrived', 'exception'])
                    ->count(),
            ];
        }

        $deliveryStops = DB::table('dispatch_batch_parcels')
            ->join('dispatch_batches', 'dispatch_batches.id', '=', 'dispatch_batch_parcels.dispatch_batch_id')
            ->join('parcels', 'parcels.id', '=', 'dispatch_batch_parcels.parcel_id')
            ->where('dispatch_batches.rider_profile_id', $profile->id)
            ->where('dispatch_batches.status', '!=', 'cancelled')
            ->where('parcels.status', '!=', ParcelStatus::Delivered->value)
            ->distinct()
            ->count('parcels.shipment_id');

        return [
            'rider.dashboard.pickups' => $profile->pickupAssignments()
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->count(),
            'rider.dashboard.deliveries' => $deliveryStops,
            'rider.refund-cash.index' => RefundCashPayout::query()
                ->where('rider_profile_id', $profile->id)
                ->whereIn('status', ['assigned_to_rider', 'cash_with_rider', 'out_for_delivery', 'cash_return_pending'])
                ->count(),
        ];
    }
}
