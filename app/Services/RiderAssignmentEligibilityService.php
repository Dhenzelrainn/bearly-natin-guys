<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ParcelStatus;
use App\Enums\RiderSpecialization;
use App\Enums\RiderWorkType;
use App\Models\DispatchBatch;
use App\Models\PickupAssignment;
use App\Models\PickupRequest;
use App\Models\RiderProfile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RiderAssignmentEligibilityService
{
    public const STATUS_AVAILABLE = 'Available';

    public const STATUS_ASSIGNED = 'Assigned';

    public const STATUS_IN_PROGRESS = 'In Progress';

    public const STATUS_OFFLINE = 'Offline';

    /** @var list<string> */
    public const ACTIVE_PICKUP_STATUSES = [
        'assigned',
        'accepted',
        'arrived',
        'picked_up',
    ];

    /** @var list<string> */
    public const IN_PROGRESS_PICKUP_STATUSES = [
        'accepted',
        'arrived',
        'picked_up',
    ];

    public function assertLocalRider(RiderProfile $rider): void
    {
        if (! RiderWorkType::isLocal($rider->work_type)) {
            throw new AuthorizationException(
                'Courier profiles cannot receive local pickup or delivery work.'
            );
        }
    }

    public function assertRiderAccountUsable(RiderProfile $rider): void
    {
        if (
            $rider->verification_status !== 'approved'
            || $rider->availability_status !== 'available'
            || ! $rider->user
            || $rider->user->status !== AccountStatus::Active->value
        ) {
            throw new ConflictHttpException(
                'Only approved, available, active Riders can receive work.'
            );
        }
    }

    public function assertPickupEligible(RiderProfile $rider, PickupRequest $pickup): void
    {
        $this->assertLocalRider($rider);
        $this->assertRiderAccountUsable($rider);

        if ((int) $rider->logistics_profile_id !== (int) $pickup->logistics_profile_id) {
            throw new AuthorizationException(
                'The pickup request and Rider must belong to the same Logistics provider.'
            );
        }

        if (! RiderSpecialization::allowsPickup($rider->specialization)) {
            throw new ConflictHttpException(
                'This Rider is assigned to delivery-only work and cannot receive pickup assignments.'
            );
        }

        $start = Carbon::parse($pickup->window_start);
        $end = Carbon::parse($pickup->window_end);

        if ($end->lessThanOrEqualTo($start)) {
            throw new ConflictHttpException(
                'The pickup request has an invalid service window.'
            );
        }

        if ($this->hasPickupConflict($rider, $start, $end)) {
            throw new ConflictHttpException(
                'The Rider already has a pickup assignment that overlaps this schedule.'
            );
        }

        if ($this->hasUnscheduledActiveDeliveryConflict($rider)) {
            throw new ConflictHttpException(
                'The Rider has an active legacy delivery batch without a schedule window; complete it before assigning overlapping work.'
            );
        }
    }

    public function assertDeliveryEligible(
        RiderProfile $rider,
        ?Carbon $plannedStart = null,
        ?Carbon $plannedEnd = null,
        ?int $ignoreBatchId = null,
    ): void {
        $this->assertLocalRider($rider);
        $this->assertRiderAccountUsable($rider);

        if (! RiderSpecialization::allowsDelivery($rider->specialization)) {
            throw new ConflictHttpException(
                'This Rider is assigned to pickup-only work and cannot receive delivery dispatches.'
            );
        }

        if (($plannedStart === null) !== ($plannedEnd === null)) {
            throw new ConflictHttpException(
                'The delivery schedule window is invalid.'
            );
        }

        $start = $plannedStart ?? now();
        // Internal/legacy service callers without an interval are checked
        // against the present moment. Their created batch remains unscheduled
        // and conservatively blocks future work until its parcels are final.
        $end = $plannedEnd ?? $start->copy()->addSecond();

        if ($end->lessThanOrEqualTo($start)) {
            throw new ConflictHttpException(
                'The delivery schedule window is invalid.'
            );
        }

        if ($this->hasPickupConflict($rider, $start, $end)) {
            throw new ConflictHttpException(
                'The Rider already has a pickup assignment that overlaps this delivery schedule.'
            );
        }

        if ($this->hasUnscheduledActiveDeliveryConflict($rider, $ignoreBatchId)) {
            throw new ConflictHttpException(
                'The Rider has an active delivery batch without a schedule window; complete it before assigning another delivery.'
            );
        }

        if ($this->hasDispatchConflict($rider, $start, $end, $ignoreBatchId)) {
            throw new ConflictHttpException(
                'The Rider already has a delivery batch that overlaps this schedule.'
            );
        }
    }

    public function hasPickupConflict(
        RiderProfile $rider,
        Carbon $start,
        Carbon $end,
        ?int $ignoreAssignmentId = null,
    ): bool {
        return PickupAssignment::query()
            ->where('rider_profile_id', $rider->id)
            ->whereIn('status', self::ACTIVE_PICKUP_STATUSES)
            ->whereNull('cancelled_at')
            ->when(
                $ignoreAssignmentId !== null,
                fn ($query) => $query->whereKeyNot($ignoreAssignmentId),
            )
            ->whereHas(
                'pickupRequest',
                fn ($query) => $query
                    ->where('window_start', '<', $end)
                    ->where('window_end', '>', $start)
                    ->whereNotIn('status', ['cancelled', 'rejected']),
            )
            ->exists();
    }

    public function hasDispatchConflict(
        RiderProfile $rider,
        Carbon $start,
        Carbon $end,
        ?int $ignoreBatchId = null,
    ): bool {
        return DispatchBatch::query()
            ->where('rider_profile_id', $rider->id)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->when(
                $ignoreBatchId !== null,
                fn ($query) => $query->whereKeyNot($ignoreBatchId),
            )
            ->whereNotNull('planned_window_start')
            ->whereNotNull('planned_window_end')
            ->where('planned_window_start', '<', $end)
            ->where('planned_window_end', '>', $start)
            ->exists();
    }

    /**
     * A legacy active dispatch has no authoritative work interval. Do not
     * assume it is free just because a proposed assignment is in the future.
     */
    private function hasUnscheduledActiveDeliveryConflict(
        RiderProfile $rider,
        ?int $ignoreBatchId = null,
    ): bool {
        return DB::table('dispatch_batches as batches')
            ->join(
                'dispatch_batch_parcels as batch_parcels',
                'batch_parcels.dispatch_batch_id',
                '=',
                'batches.id',
            )
            ->join('parcels', 'parcels.id', '=', 'batch_parcels.parcel_id')
            ->where('batches.rider_profile_id', $rider->id)
            ->whereNotIn('batches.status', ['completed', 'cancelled'])
            ->when($ignoreBatchId !== null, fn ($query) => $query->where('batches.id', '!=', $ignoreBatchId))
            ->where(function ($query): void {
                $query->whereNull('batches.planned_window_start')
                    ->orWhereNull('batches.planned_window_end');
            })
            ->whereNotIn('parcels.status', [
                ParcelStatus::Delivered->value,
                ParcelStatus::Returned->value,
            ])
            ->exists();
    }

    /**
     * Return provider-candidate Rider IDs that have no pickup or unknown-window
     * dispatch conflict for this persisted pickup window.
     *
     * @param  Collection<int, RiderProfile>  $riders
     * @return Collection<int, int>
     */
    public function pickupConflictRiderIds(Collection $riders, PickupRequest $pickup): Collection
    {
        $riderIds = $riders->pluck('id')->map(fn ($id): int => (int) $id)->filter()->unique()->values();

        if ($riderIds->isEmpty()) {
            return collect();
        }

        $start = Carbon::parse($pickup->window_start);
        $end = Carbon::parse($pickup->window_end);

        $pickupConflicts = PickupAssignment::query()
            ->whereIn('rider_profile_id', $riderIds)
            ->whereIn('status', self::ACTIVE_PICKUP_STATUSES)
            ->whereNull('cancelled_at')
            ->whereHas('pickupRequest', fn ($query) => $query
                ->where('window_start', '<', $end)
                ->where('window_end', '>', $start)
                ->whereNotIn('status', ['cancelled', 'rejected']))
            ->distinct()
            ->pluck('rider_profile_id');

        $dispatchConflicts = DB::table('dispatch_batches as batches')
            ->join('dispatch_batch_parcels as batch_parcels', 'batch_parcels.dispatch_batch_id', '=', 'batches.id')
            ->join('parcels', 'parcels.id', '=', 'batch_parcels.parcel_id')
            ->whereIn('batches.rider_profile_id', $riderIds)
            ->whereNotIn('batches.status', ['completed', 'cancelled'])
            ->whereNotIn('parcels.status', [ParcelStatus::Delivered->value, ParcelStatus::Returned->value])
            ->where(function ($query) use ($start, $end): void {
                $query->where(function ($window) use ($start, $end): void {
                    $window->whereNotNull('batches.planned_window_start')
                        ->whereNotNull('batches.planned_window_end')
                        ->where('batches.planned_window_start', '<', $end)
                        ->where('batches.planned_window_end', '>', $start);
                })->orWhereNull('batches.planned_window_start')
                    ->orWhereNull('batches.planned_window_end');
            })
            ->distinct()
            ->pluck('batches.rider_profile_id');

        return $pickupConflicts
            ->merge($dispatchConflicts)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    public function operationalStatus(RiderProfile $rider): string
    {
        return $this->operationalStatuses(collect([$rider]))
            ->get((int) $rider->id, self::STATUS_OFFLINE);
    }

    /**
     * @param  Collection<int, RiderProfile>  $riders
     * @return Collection<int, string>
     */
    public function operationalStatuses(Collection $riders): Collection
    {
        $riderIds = $riders
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($riderIds->isEmpty()) {
            return collect();
        }

        $pickupInProgress = PickupAssignment::query()
            ->whereIn('rider_profile_id', $riderIds->all())
            ->whereIn('status', self::IN_PROGRESS_PICKUP_STATUSES)
            ->whereNull('cancelled_at')
            ->distinct()
            ->pluck('rider_profile_id')
            ->map(fn ($id): int => (int) $id)
            ->flip();

        $pickupAssigned = PickupAssignment::query()
            ->whereIn('rider_profile_id', $riderIds->all())
            ->where('status', 'assigned')
            ->whereNull('cancelled_at')
            ->distinct()
            ->pluck('rider_profile_id')
            ->map(fn ($id): int => (int) $id)
            ->flip();

        $deliveryInProgress = DB::table('dispatch_batches as batches')
            ->join(
                'dispatch_batch_parcels as batch_parcels',
                'batch_parcels.dispatch_batch_id',
                '=',
                'batches.id',
            )
            ->join('parcels', 'parcels.id', '=', 'batch_parcels.parcel_id')
            ->whereIn('batches.rider_profile_id', $riderIds->all())
            ->whereNotIn('batches.status', ['completed', 'cancelled'])
            ->where('parcels.status', ParcelStatus::OutForDelivery->value)
            ->distinct()
            ->pluck('batches.rider_profile_id')
            ->map(fn ($id): int => (int) $id)
            ->flip();

        $deliveryAssigned = DB::table('dispatch_batches as batches')
            ->join(
                'dispatch_batch_parcels as batch_parcels',
                'batch_parcels.dispatch_batch_id',
                '=',
                'batches.id',
            )
            ->join('parcels', 'parcels.id', '=', 'batch_parcels.parcel_id')
            ->whereIn('batches.rider_profile_id', $riderIds->all())
            ->whereNotIn('batches.status', ['completed', 'cancelled'])
            ->whereNotIn('parcels.status', [
                ParcelStatus::Delivered->value,
                ParcelStatus::Returned->value,
            ])
            ->distinct()
            ->pluck('batches.rider_profile_id')
            ->map(fn ($id): int => (int) $id)
            ->flip();

        return $riders->mapWithKeys(function (RiderProfile $rider) use (
            $pickupInProgress,
            $pickupAssigned,
            $deliveryInProgress,
            $deliveryAssigned,
        ): array {
            $id = (int) $rider->id;

            if ($rider->availability_status !== 'available') {
                return [$id => self::STATUS_OFFLINE];
            }

            if ($pickupInProgress->has($id) || $deliveryInProgress->has($id)) {
                return [$id => self::STATUS_IN_PROGRESS];
            }

            if ($pickupAssigned->has($id) || $deliveryAssigned->has($id)) {
                return [$id => self::STATUS_ASSIGNED];
            }

            return [$id => self::STATUS_AVAILABLE];
        });
    }
}
