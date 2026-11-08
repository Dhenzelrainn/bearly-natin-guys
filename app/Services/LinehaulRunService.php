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
use App\Notifications\WorkflowNotification;
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
        private readonly NotificationService $notifications,
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

            $this->notifyOriginOfRunMilestone(
                $run,
                'logistics_linehaul_assigned',
                'Courier assigned',
                "Run {$run->run_no} was assigned to a Courier and is ready for loading at {$center->name}.",
                ['shipment_count' => $shipments->count(), 'parcel_count' => $pieces],
            );

            $this->notifications->sendAfterCommit($courier->user, new WorkflowNotification(
                eventKey: 'rider_linehaul_assigned',
                title: 'Courier run assigned',
                body: "Run {$run->run_no} is ready for loading: {$center->name} to {$targetProfile->display_name} ({$pieces} parcels).",
                actionUrl: route('rider.courier.dashboard'),
                type: 'info',
                meta: [
                    'linehaul_run_id' => (int) $run->id,
                    'run_no' => $run->run_no,
                    'shipment_count' => $shipments->count(),
                    'parcel_count' => $pieces,
                ],
            ));

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

            $this->notifyOriginOfRunMilestone(
                $lockedRun,
                'logistics_linehaul_cargo_loaded',
                'Courier cargo loaded',
                "The Courier confirmed that all cargo on run {$lockedRun->run_no} is loaded and ready to depart.",
                ['shipment_count' => $lockedRun->shipments()->count()],
            );

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

            $destinationName = $lockedRun->destinationLogisticsProfile?->display_name ?? 'destination Logistics';
            $this->notifyOriginOfRunMilestone(
                $lockedRun,
                'logistics_linehaul_departed',
                'Courier departed',
                "Transfer {$lockedRun->run_no} departed for {$destinationName} with {$shipments->count()} shipment(s).",
                ['shipment_count' => $shipments->count()],
            );

            return $lockedRun->fresh(['shipments.waybill', 'events']);
        }, attempts: 3);
    }

    public function arrive(LinehaulRun $run, User $actor): LinehaulRun
    {
        return DB::transaction(function () use ($run, $actor): LinehaulRun {
            $lockedRun = $this->lockRun($run);
            $this->assertCourierOwnsRun($lockedRun, $actor);

            if ($lockedRun->status !== 'departed') {
                throw new ConflictHttpException("This run cannot move from {$lockedRun->status} to arrived.");
            }

            if (! $lockedRun->departed_at) {
                throw new ConflictHttpException('A run must depart before the Courier can record arrival.');
            }

            $now = now();
            $lockedRun->update(['status' => 'arrived', 'arrived_at' => $now]);
            $this->event($lockedRun, 'arrived', 'departed', 'arrived', $actor, null, null, $now);

            $destinationUser = $lockedRun->destinationLogisticsProfile?->user;

            if ($destinationUser) {
                $this->notifications->sendAfterCommit($destinationUser, new WorkflowNotification(
                    eventKey: 'logistics_linehaul_arrived',
                    title: 'Courier arrived',
                    body: "Courier run {$lockedRun->run_no} has arrived. Scan and accept its shipments at your facility.",
                    actionUrl: route('logistics.transfers.index'),
                    type: 'info',
                    meta: ['linehaul_run_id' => (int) $lockedRun->id, 'run_no' => $lockedRun->run_no],
                ));
            }

            return $lockedRun->fresh(['shipments.waybill', 'events']);
        }, attempts: 3);
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

            $this->notifyLogisticsOfRunException(
                $lockedRun,
                $from,
                'logistics_linehaul_exception_reported',
                'Courier reported a run exception',
                "Courier reported an exception on run {$lockedRun->run_no}. Review the run in City Handoffs.",
            );

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

            if ($actor->role === UserRole::Rider->value) {
                $this->notifyLogisticsOfRunException(
                    $lockedRun,
                    $resume,
                    'logistics_linehaul_exception_resolved',
                    'Courier run exception resolved',
                    "The Courier resolved the exception on run {$lockedRun->run_no}. Current status: ".str($resume)->replace('_', ' ')->title().'.',
                );
            } else {
                $courierUser = $lockedRun->courierRiderProfile?->user;

                if ($courierUser) {
                    $this->notifications->sendAfterCommit($courierUser, new WorkflowNotification(
                        eventKey: 'rider_linehaul_exception_resolved',
                        title: 'Run exception resolved',
                        body: "The exception on run {$lockedRun->run_no} was resolved. Current status: ".str($resume)->replace('_', ' ')->title().'.',
                        actionUrl: route('rider.courier.dashboard'),
                        type: 'success',
                        meta: ['linehaul_run_id' => (int) $lockedRun->id, 'run_no' => $lockedRun->run_no],
                    ));
                }

                $destinationUser = in_array($resume, ['departed', 'arrived'], true)
                    ? $lockedRun->destinationLogisticsProfile?->user
                    : null;

                if ($destinationUser) {
                    $this->notifications->sendAfterCommit($destinationUser, new WorkflowNotification(
                        eventKey: 'logistics_linehaul_exception_resolved',
                        title: 'Courier run exception resolved',
                        body: "Origin Logistics resolved the exception on run {$lockedRun->run_no}. Current status: ".str($resume)->replace('_', ' ')->title().'.',
                        actionUrl: route('logistics.transfers.index'),
                        type: 'success',
                        meta: ['linehaul_run_id' => (int) $lockedRun->id, 'run_no' => $lockedRun->run_no],
                    ));
                }
            }

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
        $lockedRun = LinehaulRun::query()
            ->with('courierRiderProfile.user')
            ->lockForUpdate()
            ->findOrFail($run->id);

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

        $courierUser = $lockedRun->courierRiderProfile?->user;

        if ($courierUser) {
            $this->notifications->sendAfterCommit($courierUser, new WorkflowNotification(
                eventKey: 'rider_linehaul_completed',
                title: 'Courier run completed',
                body: "All shipments on run {$lockedRun->run_no} were accepted by destination Logistics.",
                actionUrl: route('rider.courier.dashboard'),
                type: 'success',
                meta: ['linehaul_run_id' => (int) $lockedRun->id, 'run_no' => $lockedRun->run_no],
            ));
        }
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

            if ($eventType === 'loading_started') {
                $originName = $lockedRun->originLogisticsProfile?->display_name ?? 'your facility';
                $this->notifyOriginOfRunMilestone(
                    $lockedRun,
                    'logistics_linehaul_loading_started',
                    'Courier started loading',
                    "The Courier started loading run {$lockedRun->run_no} at {$originName}.",
                );
            }

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
                'originLogisticsProfile.user',
                'destinationLogisticsProfile.user',
            ])
            ->lockForUpdate()
            ->findOrFail($run->id);
    }

    /**
     * Notify the Logistics providers responsible for the run at the point the
     * exception occurred or was resolved. Before departure, only the origin
     * provider owns the operational response; after departure, both providers
     * need the update.
     */
    private function notifyLogisticsOfRunException(
        LinehaulRun $run,
        string $operationalStatus,
        string $eventKey,
        string $title,
        string $body,
    ): void {
        $run->loadMissing(['originLogisticsProfile.user', 'destinationLogisticsProfile.user']);

        $recipients = [$run->originLogisticsProfile?->user];

        if (in_array($operationalStatus, ['departed', 'arrived'], true)) {
            $recipients[] = $run->destinationLogisticsProfile?->user;
        }

        $this->notifications->sendManyAfterCommit($recipients, new WorkflowNotification(
            eventKey: $eventKey,
            title: $title,
            body: $body,
            actionUrl: route('logistics.transfers.index'),
            type: $eventKey === 'logistics_linehaul_exception_reported' ? 'warning' : 'success',
            meta: ['linehaul_run_id' => (int) $run->id, 'run_no' => $run->run_no],
        ));
    }

    /**
     * Send one preference-aware operational update to the run's origin account.
     * The helper is called inside the transition transaction so no notice is
     * emitted if the corresponding activity fails to commit.
     *
     * @param  array<string, mixed>  $meta
     */
    private function notifyOriginOfRunMilestone(
        LinehaulRun $run,
        string $eventKey,
        string $title,
        string $body,
        array $meta = [],
    ): void {
        $run->loadMissing('originLogisticsProfile.user');
        $originUser = $run->originLogisticsProfile?->user;

        if (! $originUser) {
            return;
        }

        $this->notifications->sendAfterCommit($originUser, new WorkflowNotification(
            eventKey: $eventKey,
            title: $title,
            body: $body,
            actionUrl: route('logistics.transfers.index'),
            type: 'info',
            meta: ['linehaul_run_id' => (int) $run->id, 'run_no' => $run->run_no] + $meta,
        ));
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
