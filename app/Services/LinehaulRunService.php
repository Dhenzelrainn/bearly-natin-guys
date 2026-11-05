<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ParcelStatus;
use App\Enums\RiderWorkType;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\LinehaulRun;
use App\Models\LinehaulRunEvent;
use App\Models\LinehaulRunShipment;
use App\Models\LogisticsProfile;
use App\Models\Parcel;
use App\Models\RiderProfile;
use App\Models\Shipment;
use App\Models\ShipmentTransfer;
use App\Models\SortingCenter;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class LinehaulRunService
{
    public function __construct(
        private readonly ShipmentHandoffService $handoffs,
        private readonly CourierDailyEarningService $courierDaily,
    ) {}

    /**
     * Create one provider-scoped run for shipments sharing the same destination.
     * No parcel movement or destination custody change occurs at assignment.
     *
     * @param  array<int, int>  $shipmentIds
     */
    public function assign(
        array $shipmentIds,
        int $courierProfileId,
        User $actor
    ): LinehaulRun {
        return DB::transaction(function () use ($shipmentIds, $courierProfileId, $actor): LinehaulRun {
            $origin = $this->activeLogisticsProfile($actor);
            $center = $this->singleActiveCenter($origin);
            $ids = collect($shipmentIds)->map(fn ($id): int => (int) $id)->unique()->sort()->values();

            if ($ids->isEmpty() || $ids->count() !== count($shipmentIds)) {
                throw new ConflictHttpException('Select one or more distinct eligible shipments for the manifest.');
            }

            $courier = RiderProfile::query()
                ->with(['user', 'courierVehicleVerifier.logisticsProfile'])
                ->whereKey($courierProfileId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEligibleCourier($courier, $origin);

            $hasActiveRun = LinehaulRun::query()
                ->where('courier_rider_profile_id', $courier->id)
                ->whereIn('status', ['assigned', 'loading', 'loaded', 'departed', 'arrived', 'exception'])
                ->lockForUpdate()
                ->exists();

            if ($hasActiveRun) {
                throw new ConflictHttpException('This Courier already has an active line-haul run.');
            }

            $shipments = Shipment::query()
                ->with(['sellerOrder.order', 'destinationAddress'])
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($shipments->count() !== $ids->count()) {
                throw new ConflictHttpException('One or more selected shipments are no longer available.');
            }

            $targetProfile = null;
            $pieces = 0;
            $shipmentParcels = [];

            foreach ($shipments as $shipment) {
                if ((int) $shipment->logistics_profile_id !== (int) $origin->id
                    || $shipment->status !== ShipmentStatus::AtSortingCenter->value
                    || ! in_array($shipment->purpose, ['outbound', 'return'], true)
                    || $shipment->transfer()->exists()
                    || $shipment->linehaulRunAssignment()->exists()
                    || ! $this->handoffs->isCrossCityFor($shipment, $origin)) {
                    throw new ConflictHttpException("Shipment {$shipment->shipment_no} is not eligible for a new city handoff.");
                }

                $destination = $this->handoffs->destinationProviderFor($shipment);

                if (! $destination || (int) $destination->id === (int) $origin->id
                    || ! $this->handoffs->hasSingleActiveSortingCenter($destination)) {
                    throw new ConflictHttpException("Shipment {$shipment->shipment_no} has no active destination Logistics facility.");
                }

                if ($targetProfile && (int) $targetProfile->id !== (int) $destination->id) {
                    throw new ConflictHttpException('A line-haul manifest can contain shipments for only one destination Logistics provider.');
                }

                $targetProfile = $destination;
                $parcels = Parcel::query()
                    ->where('shipment_id', $shipment->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($parcels->isEmpty() || $parcels->contains(
                    fn (Parcel $parcel): bool => $parcel->status !== ParcelStatus::Received->value
                        || (int) $parcel->current_sorting_center_id !== (int) $center->id
                )) {
                    throw new ConflictHttpException("All parcels in shipment {$shipment->shipment_no} must be received at this provider's Sorting Center.");
                }

                $pieces += $parcels->count();
                $shipmentParcels[$shipment->id] = $parcels;
            }

            if (! $targetProfile || $pieces > (int) $courier->linehaul_parcel_capacity) {
                throw new ConflictHttpException('The selected manifest exceeds the Courier’s Logistics-verified parcel capacity.');
            }

            $targetProfile = LogisticsProfile::query()
                ->whereKey($targetProfile->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($targetProfile->status !== AccountStatus::Active->value
                || ! $this->handoffs->hasSingleActiveSortingCenter($targetProfile)) {
                throw new ConflictHttpException('The destination Logistics facility is no longer eligible to receive a handoff.');
            }

            $now = now();
            $run = LinehaulRun::query()->create([
                'run_no' => 'LHR-'.Str::upper((string) Str::ulid()),
                'origin_logistics_profile_id' => $origin->id,
                'destination_logistics_profile_id' => $targetProfile->id,
                'origin_sorting_center_id' => $center->id,
                'courier_rider_profile_id' => $courier->id,
                'status' => 'assigned',
                'assigned_by' => $actor->id,
                'assigned_at' => $now,
            ]);

            foreach ($shipments as $shipment) {
                LinehaulRunShipment::query()->create([
                    'linehaul_run_id' => $run->id,
                    'shipment_id' => $shipment->id,
                ]);
            }

            $this->event($run, 'assigned', null, 'assigned', $actor, null, [
                'courier_rider_profile_id' => (int) $courier->id,
                'destination_logistics_profile_id' => (int) $targetProfile->id,
                'shipment_ids' => $shipments->modelKeys(),
                'parcel_count' => $pieces,
            ], $now);

            return $run->fresh([
                'originLogisticsProfile',
                'destinationLogisticsProfile',
                'courierRiderProfile.user',
                'shipments.waybill',
                'events',
            ]);
        }, attempts: 3);
    }

    public function startLoading(LinehaulRun $run, User $actor): LinehaulRun
    {
        return $this->transitionForCourier($run, $actor, 'assigned', 'loading', 'loading_started');
    }

    public function markLoaded(LinehaulRun $run, User $actor): LinehaulRun
    {
        return DB::transaction(function () use ($run, $actor): LinehaulRun {
            $lockedRun = $this->lockRun($run);
            $this->assertCourierOwnsRun($lockedRun, $actor);

            if ($lockedRun->status !== 'loading') {
                throw new ConflictHttpException('Start loading this run before confirming that its cargo is loaded.');
            }

            $this->assertManifestStillAtOrigin($lockedRun);
            $now = now();
            LinehaulRunShipment::query()
                ->where('linehaul_run_id', $lockedRun->id)
                ->update(['loaded_at' => $now, 'updated_at' => $now]);
            $lockedRun->update(['status' => 'loaded', 'loaded_at' => $now]);
            $this->event($lockedRun, 'cargo_loaded', 'loading', 'loaded', $actor, null, [
                'shipment_count' => $lockedRun->shipments()->count(),
            ], $now);

            return $lockedRun->fresh(['shipments.waybill', 'events']);
        }, attempts: 3);
    }

    public function depart(LinehaulRun $run, User $actor): LinehaulRun
    {
        return DB::transaction(function () use ($run, $actor): LinehaulRun {
            $lockedRun = $this->lockRun($run);
            $this->assertCourierOwnsRun($lockedRun, $actor);

            if ($lockedRun->status !== 'loaded') {
                throw new ConflictHttpException('Every shipment must be loaded before this run can depart.');
            }

            $this->assertManifestStillAtOrigin($lockedRun);
            $shipments = Shipment::query()
                ->whereIn('id', $lockedRun->shipments()->select('shipments.id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $now = now();

            foreach ($shipments as $shipment) {
                $transfer = ShipmentTransfer::query()->create([
                    'shipment_id' => $shipment->id,
                    'from_logistics_profile_id' => $lockedRun->origin_logistics_profile_id,
                    'to_logistics_profile_id' => $lockedRun->destination_logistics_profile_id,
                    'from_sorting_center_id' => $lockedRun->origin_sorting_center_id,
                    'status' => 'sent',
                    'sent_by' => $actor->id,
                    'sent_at' => $now,
                ]);

                $shipment->update(['status' => ShipmentStatus::HandoffPending->value]);

                foreach (Parcel::query()->where('shipment_id', $shipment->id)->orderBy('id')->lockForUpdate()->get() as $parcel) {
                    $parcel->update([
                        'status' => ParcelStatus::InTransit->value,
                        'last_event_at' => $now,
                    ]);
                    $shipment->events()->create([
                        'parcel_id' => $parcel->id,
                        'event_type' => 'logistics_handoff_sent',
                        'from_status' => ParcelStatus::Received->value,
                        'to_status' => ParcelStatus::InTransit->value,
                        'sorting_center_id' => $lockedRun->origin_sorting_center_id,
                        'actor_user_id' => $actor->id,
                        'source' => 'courier',
                        'metadata' => [
                            'shipment_transfer_id' => (int) $transfer->id,
                            'linehaul_run_id' => (int) $lockedRun->id,
                            'from_logistics_profile_id' => (int) $lockedRun->origin_logistics_profile_id,
                            'to_logistics_profile_id' => (int) $lockedRun->destination_logistics_profile_id,
                        ],
                        'occurred_at' => $now,
                    ]);
                }

                $this->handoffs->notifyCourierDeparture($transfer);
            }

            $lockedRun->update(['status' => 'departed', 'departed_at' => $now]);
            $this->event($lockedRun, 'departed', 'loaded', 'departed', $actor, null, [
                'shipment_count' => $shipments->count(),
            ], $now);

            return $lockedRun->fresh(['shipments.waybill', 'events']);
        }, attempts: 3);
    }

    public function arrive(LinehaulRun $run, User $actor): LinehaulRun
    {
        return $this->transitionForCourier($run, $actor, 'departed', 'arrived', 'arrived');
    }

    public function reportException(LinehaulRun $run, User $actor, string $reason): LinehaulRun
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw new ConflictHttpException('Describe the line-haul exception before submitting it.');
        }

        return DB::transaction(function () use ($run, $actor, $reason): LinehaulRun {
            $lockedRun = $this->lockRun($run);
            $this->assertCourierOwnsRun($lockedRun, $actor);

            if (in_array($lockedRun->status, ['completed', 'exception'], true)) {
                throw new ConflictHttpException('This run cannot accept another exception in its current state.');
            }

            $from = $lockedRun->status;
            $lockedRun->update([
                'status' => 'exception',
                'status_before_exception' => $from,
            ]);
            $this->event($lockedRun, 'exception_reported', $from, 'exception', $actor, $reason, null, now());

            return $lockedRun->fresh(['shipments.waybill', 'events']);
        }, attempts: 3);
    }

    public function resolveException(LinehaulRun $run, User $actor, string $resolution): LinehaulRun
    {
        $resolution = trim($resolution);

        if (mb_strlen($resolution) < 5) {
            throw new ConflictHttpException('Describe how the line-haul exception was resolved.');
        }

        return DB::transaction(function () use ($run, $actor, $resolution): LinehaulRun {
            $lockedRun = $this->lockRun($run);
            $this->assertRunOperator($lockedRun, $actor);

            if ($lockedRun->status !== 'exception'
                || ! in_array($lockedRun->status_before_exception, ['assigned', 'loading', 'loaded', 'departed', 'arrived'], true)) {
                throw new ConflictHttpException('This run has no resolvable line-haul exception.');
            }

            $from = $lockedRun->status;
            $resume = $lockedRun->status_before_exception;
            $lockedRun->update([
                'status' => $resume,
                'status_before_exception' => null,
            ]);
            $this->event($lockedRun, 'exception_resolved', $from, $resume, $actor, $resolution, null, now());

            return $lockedRun->fresh(['shipments.waybill', 'events']);
        }, attempts: 3);
    }

    public function courierRunsFor(RiderProfile $profile)
    {
        return $this->courierRunsQueryFor($profile)
            ->limit(50)
            ->get();
    }

    public function courierRunHistoryFor(RiderProfile $profile): LengthAwarePaginator
    {
        return $this->courierRunsQueryFor($profile)
            ->paginate(20)
            ->withQueryString();
    }

    private function courierRunsQueryFor(RiderProfile $profile): Builder
    {
        return LinehaulRun::query()
            ->where('courier_rider_profile_id', $profile->id)
            ->with([
                'originLogisticsProfile.user',
                'destinationLogisticsProfile.user',
                'originSortingCenter',
                'shipments.waybill',
                'shipments.parcels',
                'shipments.sellerOrder.store',
                'events.actor',
            ])
            ->latest('assigned_at')
            ->latest('id');
    }

    public function recordDestinationAcceptance(LinehaulRun $run, User $actor): void
    {
        $lockedRun = LinehaulRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

        if ($lockedRun->status !== 'arrived') {
            return;
        }

        $actorProfile = $actor->logisticsProfile;

        if ($actor->role !== UserRole::Logistics->value
            || $actor->status !== AccountStatus::Active->value
            || (int) ($actorProfile?->id ?? 0) !== (int) $lockedRun->destination_logistics_profile_id
            || $actorProfile?->status !== AccountStatus::Active->value) {
            throw new AuthorizationException('Only the active destination Logistics provider may complete a line-haul run.');
        }

        $shipmentIds = $lockedRun->shipments()->select('shipments.id');
        $manifestCount = LinehaulRunShipment::query()
            ->where('linehaul_run_id', $lockedRun->id)
            ->count();
        $acceptedCount = ShipmentTransfer::query()
            ->whereIn('shipment_id', $shipmentIds)
            ->where('status', 'accepted')
            ->count();
        $allAccepted = $manifestCount > 0 && $acceptedCount === $manifestCount;

        if (! $allAccepted) {
            return;
        }

        $now = now();
        $lockedRun->update(['status' => 'completed', 'completed_at' => $now]);
        $this->event($lockedRun, 'completed', 'arrived', 'completed', $actor, null, [
            'destination_acceptance_required' => true,
        ], $now);

        $this->courierDaily->reconcileRun($lockedRun);
    }

    private function transitionForCourier(
        LinehaulRun $run,
        User $actor,
        string $from,
        string $to,
        string $eventType
    ): LinehaulRun {
        return DB::transaction(function () use ($run, $actor, $from, $to, $eventType): LinehaulRun {
            $lockedRun = $this->lockRun($run);
            $this->assertCourierOwnsRun($lockedRun, $actor);

            if ($lockedRun->status !== $from) {
                throw new ConflictHttpException("This run cannot move from {$lockedRun->status} to {$to}.");
            }

            if ($to === 'arrived' && ! $lockedRun->departed_at) {
                throw new ConflictHttpException('A run must depart before the Courier can record arrival.');
            }

            $now = now();
            $timestamps = match ($to) {
                'arrived' => ['arrived_at' => $now],
                default => [],
            };
            $lockedRun->update(['status' => $to] + $timestamps);
            $this->event($lockedRun, $eventType, $from, $to, $actor, null, null, $now);

            return $lockedRun->fresh(['shipments.waybill', 'events']);
        }, attempts: 3);
    }

    private function assertManifestStillAtOrigin(LinehaulRun $run): void
    {
        $shipmentIds = $run->shipments()->pluck('shipments.id');
        $shipments = Shipment::query()
            ->whereIn('id', $shipmentIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($shipments->isEmpty() || $shipments->count() !== $shipmentIds->count()) {
            throw new ConflictHttpException('The run no longer has its complete shipment manifest.');
        }

        foreach ($shipments as $shipment) {
            if ((int) $shipment->logistics_profile_id !== (int) $run->origin_logistics_profile_id
                || $shipment->status !== ShipmentStatus::AtSortingCenter->value
                || $shipment->transfer()->exists()) {
                throw new ConflictHttpException("Shipment {$shipment->shipment_no} is no longer ready at the origin facility.");
            }

            $parcels = Parcel::query()->where('shipment_id', $shipment->id)->orderBy('id')->lockForUpdate()->get();

            if ($parcels->isEmpty() || $parcels->contains(
                fn (Parcel $parcel): bool => $parcel->status !== ParcelStatus::Received->value
                    || (int) $parcel->current_sorting_center_id !== (int) $run->origin_sorting_center_id
            )) {
                throw new ConflictHttpException("Shipment {$shipment->shipment_no} no longer matches the loaded manifest.");
            }
        }
    }

    private function assertEligibleCourier(RiderProfile $courier, LogisticsProfile $origin): void
    {
        if ((int) $courier->logistics_profile_id !== (int) $origin->id) {
            throw new AuthorizationException('The Courier belongs to another Logistics provider.');
        }

        $verifier = $courier->courierVehicleVerifier;

        if (! $courier->user
            || $courier->user->status !== AccountStatus::Active->value
            || ! $courier->user->hasRole(UserRole::Rider->value)
            || $courier->verification_status !== 'approved'
            || $courier->availability_status !== 'available'
            || $courier->work_type !== RiderWorkType::Courier->value
            || ! $courier->courier_vehicle_verified_at
            || ! $verifier
            || $verifier->role !== UserRole::Logistics->value
            || $verifier->status !== AccountStatus::Active->value
            || (int) ($verifier->logisticsProfile?->id ?? 0) !== (int) $origin->id
            || (int) $courier->linehaul_parcel_capacity < 1
            || ! in_array($courier->vehicle_type, RiderWorkType::vehicleTypes(RiderWorkType::Courier), true)) {
            throw new ConflictHttpException('Choose an active, available Courier with Logistics-verified vehicle suitability and capacity.');
        }
    }

    private function assertCourierOwnsRun(LinehaulRun $run, User $actor): void
    {
        $profile = $actor->riderProfile()
            ->with('courierVehicleVerifier.logisticsProfile')
            ->first();
        $verifier = $profile?->courierVehicleVerifier;

        if ($actor->role !== UserRole::Rider->value
            || $actor->status !== AccountStatus::Active->value
            || ! $profile
            || (int) $profile->id !== (int) $run->courier_rider_profile_id
            || $profile->work_type !== RiderWorkType::Courier->value
            || ! $profile->courier_vehicle_verified_at
            || $verifier?->role !== UserRole::Logistics->value
            || $verifier?->status !== AccountStatus::Active->value
            || (int) ($verifier?->logisticsProfile?->id ?? 0) !== (int) $run->origin_logistics_profile_id) {
            throw new AuthorizationException('Only the assigned, Logistics-verified Courier may update this run.');
        }
    }

    private function assertRunOperator(LinehaulRun $run, User $actor): void
    {
        $courier = $actor->riderProfile;
        $verifier = $courier?->courierVehicleVerifier;
        $logistics = $actor->logisticsProfile;
        $isAssignedCourier = $actor->role === UserRole::Rider->value
            && $actor->status === AccountStatus::Active->value
            && $courier?->verification_status === 'approved'
            && $courier?->work_type === RiderWorkType::Courier->value
            && $courier?->courier_vehicle_verified_at !== null
            && $verifier?->role === UserRole::Logistics->value
            && $verifier?->status === AccountStatus::Active->value
            && (int) ($verifier?->logisticsProfile?->id ?? 0) === (int) $run->origin_logistics_profile_id
            && (int) $courier?->id === (int) $run->courier_rider_profile_id;
        $isOriginLogistics = $actor->role === UserRole::Logistics->value
            && $actor->status === AccountStatus::Active->value
            && $logistics?->status === AccountStatus::Active->value
            && (int) $logistics?->id === (int) $run->origin_logistics_profile_id;

        if (! $isAssignedCourier && ! $isOriginLogistics) {
            throw new AuthorizationException('Only the assigned Courier or origin Logistics provider may resolve this exception.');
        }
    }

    private function activeLogisticsProfile(User $actor): LogisticsProfile
    {
        $profile = $actor->logisticsProfile()->first();

        if ($actor->role !== UserRole::Logistics->value
            || $actor->status !== AccountStatus::Active->value
            || ! $profile
            || $profile->status !== AccountStatus::Active->value) {
            throw new AuthorizationException('An active Logistics account is required.');
        }

        return $profile;
    }

    private function singleActiveCenter(LogisticsProfile $profile): SortingCenter
    {
        $centers = SortingCenter::query()
            ->where('logistics_profile_id', $profile->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->limit(2)
            ->get();

        if ($centers->count() !== 1 || $centers->first()->status !== 'active') {
            throw new ConflictHttpException('The origin Logistics provider must have exactly one active Sorting Center.');
        }

        return $centers->first();
    }

    private function lockRun(LinehaulRun $run): LinehaulRun
    {
        return LinehaulRun::query()
            ->with([
                'courierRiderProfile.user',
                'courierRiderProfile.courierVehicleVerifier.logisticsProfile',
            ])
            ->lockForUpdate()
            ->findOrFail($run->id);
    }

    private function event(
        LinehaulRun $run,
        string $eventType,
        ?string $from,
        ?string $to,
        User $actor,
        ?string $reason,
        ?array $metadata,
        $occurredAt
    ): void {
        LinehaulRunEvent::query()->create([
            'linehaul_run_id' => $run->id,
            'actor_user_id' => $actor->id,
            'event_type' => $eventType,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'metadata' => $metadata,
            'occurred_at' => $occurredAt,
        ]);
    }
}
