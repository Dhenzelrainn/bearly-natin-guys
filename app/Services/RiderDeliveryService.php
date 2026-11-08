<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\DeliveryAttemptOutcome;
use App\Enums\ParcelStatus;
use App\Enums\RiderWorkType;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryProof;
use App\Models\DispatchBatch;
use App\Models\Parcel;
use App\Models\RiderProfile;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class RiderDeliveryService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Persist a successful exact Parcel barcode scan immediately. Verification
     * is an audit event only: it does not move the Parcel or Shipment status.
     *
     * @return array{parcel_no: string, verified_at: string, newly_verified: bool, assigned_parcel_nos: list<string>, verified_parcel_nos: list<string>, verified_count: int, total_count: int, all_verified: bool}
     */
    public function verifyParcelBarcode(
        string $shipmentNo,
        User $actor,
        string $barcode,
        string $scanMethod = 'manual',
    ): array {
        return DB::transaction(function () use ($shipmentNo, $actor, $barcode, $scanMethod): array {
            if (! in_array($scanMethod, ['camera', 'manual', 'barcode'], true)) {
                throw ValidationException::withMessages([
                    'scan_method' => 'Choose a supported Parcel barcode scan method.',
                ]);
            }

            $rider = $this->activeApprovedLocalRider($actor, 'verify delivery Parcels');

            $shipment = Shipment::query()
                ->where('shipment_no', $shipmentNo)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertShipmentOwnedByRiderProvider($shipment, $rider);

            ['batches' => $batches, 'parcels' => $parcels, 'parcel_batch_ids' => $parcelBatchIds]
                = $this->assignedDeliveryParcels($shipment, $rider, lock: true);

            if ($parcels->isEmpty() || $parcels->contains(
                fn (Parcel $parcel): bool => $parcel->status !== ParcelStatus::OutForDelivery->value,
            )) {
                throw new ConflictHttpException(
                    'Only Parcels currently out for delivery can be barcode-verified.',
                );
            }

            /** @var Parcel|null $parcel */
            $parcel = $parcels->first(
                fn (Parcel $assignedParcel): bool => $assignedParcel->parcel_no === $barcode,
            );

            if (! $parcel) {
                throw ValidationException::withMessages([
                    'barcode' => 'That exact Parcel barcode is not assigned to this delivery.',
                ]);
            }

            $batchId = $parcelBatchIds[$parcel->id] ?? null;

            if (! $batchId) {
                throw new AuthorizationException('This Parcel is not assigned to this Rider.');
            }

            $verificationEvent = ShipmentEvent::query()
                ->where('shipment_id', $shipment->id)
                ->where('parcel_id', $parcel->id)
                ->where('event_type', 'parcel_barcode_verified')
                ->where('metadata->dispatch_batch_id', $batchId)
                ->where('metadata->rider_profile_id', $rider->id)
                ->first();

            $newlyVerified = ! $verificationEvent;

            if ($newlyVerified) {
                $verificationEvent = $shipment->events()->create([
                    'parcel_id' => $parcel->id,
                    'event_type' => 'parcel_barcode_verified',
                    'from_status' => ParcelStatus::OutForDelivery->value,
                    'to_status' => ParcelStatus::OutForDelivery->value,
                    'sorting_center_id' => $parcel->current_sorting_center_id,
                    'sorting_zone_id' => $parcel->current_zone_id,
                    'actor_user_id' => $actor->id,
                    'source' => 'rider',
                    'scan_method' => $scanMethod,
                    'metadata' => [
                        'dispatch_batch_id' => (int) $batchId,
                        'rider_profile_id' => (int) $rider->id,
                    ],
                    'occurred_at' => now(),
                ]);
            }

            $progress = $this->deliveryVerificationProgressFor(
                $shipment,
                $rider,
                $parcels,
                $parcelBatchIds,
            );

            if ($newlyVerified && $progress['all_verified']) {
                $this->notifyLogisticsDeliveryParcelsVerified(
                    $shipment,
                    $rider,
                    $actor,
                    $batches,
                    $progress['total_count'],
                );
            }

            return [
                'parcel_no' => (string) $parcel->parcel_no,
                'verified_at' => $verificationEvent->occurred_at->toIso8601String(),
                'newly_verified' => $newlyVerified,
                ...$progress,
            ];
        }, 3);
    }

    /**
     * Read server-authoritative scan progress for the authenticated Rider's
     * active delivery assignment.
     *
     * @return array{assigned_parcel_nos: list<string>, verified_parcel_nos: list<string>, verified_count: int, total_count: int, all_verified: bool}
     */
    public function deliveryVerificationProgress(string $shipmentNo, User $actor): array
    {
        $rider = $this->activeApprovedLocalRider($actor, 'view delivery verification progress');
        $shipment = Shipment::query()
            ->where('shipment_no', $shipmentNo)
            ->firstOrFail();

        $this->assertShipmentOwnedByRiderProvider($shipment, $rider);

        ['parcels' => $parcels, 'parcel_batch_ids' => $parcelBatchIds]
            = $this->assignedDeliveryParcels($shipment, $rider, lock: false);

        if ($parcels->isEmpty() || $parcels->contains(
            fn (Parcel $parcel): bool => $parcel->status !== ParcelStatus::OutForDelivery->value,
        )) {
            throw new ConflictHttpException(
                'This delivery is no longer accepting Parcel barcode verification.',
            );
        }

        return $this->deliveryVerificationProgressFor(
            $shipment,
            $rider,
            $parcels,
            $parcelBatchIds,
        );
    }

    public function startDelivery(
        string $shipmentNo,
        User $actor
    ): Shipment {
        return DB::transaction(
            function () use (
                $shipmentNo,
                $actor
            ) {
                if (
                    $actor->role
                        !== UserRole::Rider->value
                    || $actor->status
                        !== AccountStatus::Active->value
                ) {
                    throw new AuthorizationException(
                        'Only an active Rider can start delivery.'
                    );
                }

                $rider = $actor
                    ->riderProfile()
                    ->first();
                $this->assertLocalRider($rider);

                if (
                    ! $rider
                    || $rider->verification_status
                        !== 'approved'
                ) {
                    throw new AuthorizationException(
                        'An approved Rider profile is required.'
                    );
                }

                $shipment = Shipment::query()
                    ->where(
                        'shipment_no',
                        $shipmentNo
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    (int) $shipment
                        ->logistics_profile_id
                    !== (int) $rider
                        ->logistics_profile_id
                ) {
                    throw new AuthorizationException(
                        'This shipment does not belong to your Logistics provider.'
                    );
                }

                $batches = DispatchBatch::query()
                    ->where(
                        'rider_profile_id',
                        $rider->id
                    )
                    ->where(
                        'status',
                        'dispatched'
                    )
                    ->whereHas(
                        'parcels',
                        fn ($query) => $query->where(
                            'parcels.shipment_id',
                            $shipment->id
                        )
                    )
                    ->with([
                        'parcels' => fn ($query) => $query->where(
                            'parcels.shipment_id',
                            $shipment->id
                        ),
                    ])
                    ->lockForUpdate()
                    ->get();

                if ($batches->isEmpty()) {
                    throw new AuthorizationException(
                        'This shipment is not assigned to this Rider.'
                    );
                }

                $parcelBatchIds = [];

                foreach (
                    $batches as $batch
                ) {
                    foreach (
                        $batch->parcels as $parcel
                    ) {
                        $parcelBatchIds[
                            $parcel->id
                        ] = $batch->id;
                    }
                }

                $parcelIds = collect(
                    array_keys(
                        $parcelBatchIds
                    )
                );

                if ($parcelIds->isEmpty()) {
                    throw new AuthorizationException(
                        'No assigned parcels were found.'
                    );
                }

                $parcels = Parcel::query()
                    ->whereIn(
                        'id',
                        $parcelIds
                    )
                    ->where(
                        'shipment_id',
                        $shipment->id
                    )
                    ->lockForUpdate()
                    ->get();

                if (
                    $parcels->contains(
                        fn (Parcel $parcel) => $parcel->status
                            !== ParcelStatus::Dispatched
                                ->value
                    )
                ) {
                    throw new ConflictHttpException(
                        'Only dispatched parcels can start delivery.'
                    );
                }

                if (
                    $shipment->status
                    !== ShipmentStatus::Dispatched
                        ->value
                ) {
                    throw new ConflictHttpException(
                        'This shipment is not ready to start delivery.'
                    );
                }

                $now = now();

                foreach (
                    $parcels as $parcel
                ) {
                    $fromStatus =
                        $parcel->status;

                    $parcel->update([
                        'status' => ParcelStatus::OutForDelivery
                            ->value,

                        'last_event_at' => $now,
                    ]);

                    $shipment
                        ->events()
                        ->create([
                            'parcel_id' => $parcel->id,

                            'event_type' => 'parcel_out_for_delivery',

                            'from_status' => $fromStatus,

                            'to_status' => ParcelStatus::OutForDelivery
                                ->value,

                            'sorting_center_id' => $parcel
                                ->current_sorting_center_id,

                            'sorting_zone_id' => $parcel
                                ->current_zone_id,

                            'actor_user_id' => $actor->id,

                            'source' => 'rider',

                            'metadata' => [
                                'dispatch_batch_id' => $parcelBatchIds[
                                        $parcel->id
                                    ],

                                'rider_profile_id' => $rider->id,
                            ],

                            'occurred_at' => $now,
                        ]);
                }

                /*
                 * A shipment may contain parcels
                 * assigned across more than one
                 * dispatch batch / Rider.
                 *
                 * Only promote the shipment after
                 * every parcel has left the
                 * dispatched state.
                 */
                $hasDispatchedParcels =
                    $shipment
                        ->parcels()
                        ->where(
                            'status',
                            ParcelStatus::Dispatched
                                ->value
                        )
                        ->exists();

                if (! $hasDispatchedParcels) {
                    $fromStatus =
                        $shipment->status;

                    $shipment->update([
                        'status' => ShipmentStatus::OutForDelivery
                            ->value,
                    ]);

                    $centerIds = $parcels
                        ->pluck(
                            'current_sorting_center_id'
                        )
                        ->filter()
                        ->unique()
                        ->values();

                    $zoneIds = $parcels
                        ->pluck(
                            'current_zone_id'
                        )
                        ->filter()
                        ->unique()
                        ->values();

                    $shipment
                        ->events()
                        ->create([
                            'event_type' => 'shipment_out_for_delivery',

                            'from_status' => $fromStatus,

                            'to_status' => ShipmentStatus::OutForDelivery
                                ->value,

                            'sorting_center_id' => $centerIds->count() === 1
                                    ? $centerIds->first()
                                    : null,

                            'sorting_zone_id' => $zoneIds->count() === 1
                                    ? $zoneIds->first()
                                    : null,

                            'actor_user_id' => $actor->id,

                            'source' => 'rider',

                            'metadata' => [
                                'dispatch_batch_ids' => $batches
                                    ->pluck('id')
                                    ->unique()
                                    ->values()
                                    ->all(),

                                'rider_profile_id' => $rider->id,
                            ],

                            'occurred_at' => $now,
                        ]);
                }

                $this->notifyBuyerOutForDelivery($shipment);

                return $shipment->fresh([
                    'parcels',
                    'events',
                ]);
            },
            3
        );
    }

    /**
     * @return Collection<int, DeliveryAttempt>
     */
    public function recordAttempt(
        string $shipmentNo,
        User $actor,
        DeliveryAttemptOutcome $outcome,
        ?string $failureReason = null,
        ?string $notes = null,
        ?float $latitude = null,
        ?float $longitude = null,
        mixed $nextAttemptAt = null
    ): Collection {
        return DB::transaction(
            function () use (
                $shipmentNo,
                $actor,
                $outcome,
                $failureReason,
                $notes,
                $latitude,
                $longitude,
                $nextAttemptAt
            ) {
                if (
                    $actor->role
                        !== UserRole::Rider->value
                    || $actor->status
                        !== AccountStatus::Active->value
                ) {
                    throw new AuthorizationException(
                        'Only an active Rider can record a delivery attempt.'
                    );
                }

                $rider = $actor
                    ->riderProfile()
                    ->first();
                $this->assertLocalRider($rider);

                if (
                    ! $rider
                    || $rider->verification_status
                        !== 'approved'
                ) {
                    throw new AuthorizationException(
                        'An approved Rider profile is required.'
                    );
                }

                if (
                    $outcome === DeliveryAttemptOutcome::Failed
                    && blank($failureReason)
                ) {
                    throw new InvalidArgumentException(
                        'A failure reason is required for a failed delivery attempt.'
                    );
                }

                if (
                    $outcome === DeliveryAttemptOutcome::Delivered
                    && (
                        filled($failureReason)
                        || $nextAttemptAt !== null
                    )
                ) {
                    throw new InvalidArgumentException(
                        'Successful attempts cannot have a failure reason or retry schedule.'
                    );
                }

                $shipment = Shipment::query()
                    ->where(
                        'shipment_no',
                        $shipmentNo
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    (int) $shipment->logistics_profile_id
                    !== (int) $rider->logistics_profile_id
                ) {
                    throw new AuthorizationException(
                        'This shipment does not belong to your Logistics provider.'
                    );
                }

                $batches = DispatchBatch::query()
                    ->where(
                        'rider_profile_id',
                        $rider->id
                    )
                    ->where(
                        'status',
                        'dispatched'
                    )
                    ->whereHas(
                        'parcels',
                        fn ($query) => $query->where(
                            'parcels.shipment_id',
                            $shipment->id
                        )
                    )
                    ->with([
                        'parcels' => fn ($query) => $query->where(
                            'parcels.shipment_id',
                            $shipment->id
                        ),
                    ])
                    ->lockForUpdate()
                    ->get();

                if ($batches->isEmpty()) {
                    throw new AuthorizationException(
                        'This shipment is not assigned to this Rider.'
                    );
                }

                $parcelBatchIds = [];

                foreach ($batches as $batch) {
                    foreach ($batch->parcels as $parcel) {
                        $parcelBatchIds[
                            $parcel->id
                        ] = $batch->id;
                    }
                }

                $parcelIds = collect(
                    array_keys(
                        $parcelBatchIds
                    )
                );

                if ($parcelIds->isEmpty()) {
                    throw new AuthorizationException(
                        'No assigned parcels were found.'
                    );
                }

                /*
                * Lock the parcel rows themselves.
                *
                * Attempt numbering is unique per
                * parcel, so serializing access to
                * these parcel rows protects the
                * max(attempt_no) + 1 calculation.
                */
                $parcels = Parcel::query()
                    ->whereIn(
                        'id',
                        $parcelIds
                    )
                    ->where(
                        'shipment_id',
                        $shipment->id
                    )
                    ->lockForUpdate()
                    ->get();

                if (
                    $parcels->contains(
                        fn (Parcel $parcel) => $parcel->status
                            !== ParcelStatus::OutForDelivery
                                ->value
                    )
                ) {
                    throw new ConflictHttpException(
                        'Only out-for-delivery parcels can record a delivery attempt.'
                    );
                }

                $now = now();

                $attempts = collect();

                foreach ($parcels as $parcel) {
                    $latestAttemptNo =
                        (int) DeliveryAttempt::query()
                            ->where(
                                'parcel_id',
                                $parcel->id
                            )
                            ->max(
                                'attempt_no'
                            );

                    $attempts->push(
                        DeliveryAttempt::query()
                            ->create([
                                'parcel_id' => $parcel->id,

                                'dispatch_batch_id' => $parcelBatchIds[
                                        $parcel->id
                                    ],

                                'rider_profile_id' => $rider->id,

                                'attempt_no' => $latestAttemptNo + 1,

                                'outcome' => $outcome->value,

                                'failure_reason' => $outcome
                                        === DeliveryAttemptOutcome::Failed
                                        ? $failureReason
                                        : null,

                                'notes' => $notes,

                                'latitude' => $latitude,

                                'longitude' => $longitude,

                                'attempted_at' => $now,

                                'next_attempt_at' => $outcome
                                        === DeliveryAttemptOutcome::Failed
                                        ? $nextAttemptAt
                                        : null,
                            ])
                    );
                }

                return $attempts;
            },
            3
        );
    }

    public function failDelivery(
        string $shipmentNo,
        User $actor,
        string $failureReason,
        ?string $notes = null,
        ?float $latitude = null,
        ?float $longitude = null,
        mixed $nextAttemptAt = null
    ): Shipment {
        return DB::transaction(
            function () use (
                $shipmentNo,
                $actor,
                $failureReason,
                $notes,
                $latitude,
                $longitude,
                $nextAttemptAt
            ) {
                if (
                    $actor->role !== UserRole::Rider->value
                    || $actor->status !== AccountStatus::Active->value
                ) {
                    throw new AuthorizationException(
                        'Only an active Rider can record delivery failure.'
                    );
                }

                $rider = $actor
                    ->riderProfile()
                    ->first();
                $this->assertLocalRider($rider);

                if (
                    ! $rider
                    || $rider->verification_status !== 'approved'
                ) {
                    throw new AuthorizationException(
                        'An approved Rider profile is required.'
                    );
                }

                $failureReason = trim($failureReason);

                if ($failureReason === '') {
                    throw new InvalidArgumentException(
                        'A failure reason is required.'
                    );
                }

                $shipment = Shipment::query()
                    ->where('shipment_no', $shipmentNo)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    (int) $shipment->logistics_profile_id
                    !== (int) $rider->logistics_profile_id
                ) {
                    throw new AuthorizationException(
                        'This shipment does not belong to your logistics provider.'
                    );
                }

                $batches = DispatchBatch::query()
                    ->where(
                        'rider_profile_id',
                        $rider->id
                    )
                    ->where(
                        'status',
                        'dispatched'
                    )
                    ->whereHas(
                        'parcels',
                        fn ($query) => $query
                            ->where(
                                'parcels.shipment_id',
                                $shipment->id
                            )
                    )
                    ->with([
                        'parcels' => fn ($query) => $query->where(
                            'parcels.shipment_id',
                            $shipment->id
                        ),
                    ])
                    ->lockForUpdate()
                    ->get();

                if ($batches->isEmpty()) {
                    throw new AuthorizationException(
                        'This delivery is not assigned to the Rider.'
                    );
                }

                $parcelBatchIds = collect();

                foreach ($batches as $batch) {
                    foreach ($batch->parcels as $parcel) {
                        $parcelBatchIds->put(
                            $parcel->id,
                            $batch->id
                        );
                    }
                }

                $parcels = Parcel::query()
                    ->where(
                        'shipment_id',
                        $shipment->id
                    )
                    ->whereIn(
                        'id',
                        $parcelBatchIds->keys()
                    )
                    ->lockForUpdate()
                    ->get();

                if ($parcels->isEmpty()) {
                    throw new AuthorizationException(
                        'No assigned parcels were found for this delivery.'
                    );
                }

                foreach ($parcels as $parcel) {
                    if (
                        $parcel->status
                        !== ParcelStatus::OutForDelivery->value
                    ) {
                        throw new ConflictHttpException(
                            'Only out-for-delivery parcels can be marked as failed.'
                        );
                    }
                }

                $now = now();

                foreach ($parcels as $parcel) {
                    $attemptNo =
                        (int) DeliveryAttempt::query()
                            ->where(
                                'parcel_id',
                                $parcel->id
                            )
                            ->max('attempt_no')
                        + 1;

                    $attempt =
                        DeliveryAttempt::query()->create([
                            'parcel_id' => $parcel->id,

                            'dispatch_batch_id' => $parcelBatchIds->get(
                                $parcel->id
                            ),

                            'rider_profile_id' => $rider->id,

                            'attempt_no' => $attemptNo,

                            'outcome' => DeliveryAttemptOutcome::Failed
                                ->value,

                            'failure_reason' => $failureReason,

                            'notes' => $notes,

                            'latitude' => $latitude,

                            'longitude' => $longitude,

                            'attempted_at' => $now,

                            'next_attempt_at' => $nextAttemptAt,
                        ]);

                    $parcel->forceFill([
                        'status' => ParcelStatus::Failed->value,

                        'last_event_at' => $now,
                    ])->save();

                    ShipmentEvent::query()->create([
                        'shipment_id' => $shipment->id,

                        'parcel_id' => $parcel->id,

                        'event_type' => 'parcel_delivery_failed',

                        'from_status' => ParcelStatus::OutForDelivery
                            ->value,

                        'to_status' => ParcelStatus::Failed
                            ->value,

                        'sorting_center_id' => $parcel
                            ->current_sorting_center_id,

                        'sorting_zone_id' => $parcel->current_zone_id,

                        'actor_user_id' => $actor->id,

                        'source' => 'rider',

                        'occurred_at' => $now,

                        'metadata' => [
                            'dispatch_batch_id' => $parcelBatchIds->get(
                                $parcel->id
                            ),

                            'rider_profile_id' => $rider->id,

                            'delivery_attempt_id' => $attempt->id,
                        ],
                    ]);
                }

                $shipmentParcels =
                    Parcel::query()
                        ->where(
                            'shipment_id',
                            $shipment->id
                        )
                        ->lockForUpdate()
                        ->get();

                $terminalStatuses = [
                    ParcelStatus::Delivered->value,
                    ParcelStatus::Failed->value,
                ];

                $failureStatuses = [
                    ParcelStatus::Failed->value,
                ];

                $allTerminal =
                    $shipmentParcels->isNotEmpty()
                    && $shipmentParcels->every(
                        fn (Parcel $parcel) => in_array(
                            $parcel->status,
                            $terminalStatuses,
                            true
                        )
                    );

                $hasFailure =
                    $shipmentParcels->contains(
                        fn (Parcel $parcel) => in_array(
                            $parcel->status,
                            $failureStatuses,
                            true
                        )
                    );

                if (
                    $allTerminal
                    && $hasFailure
                    && $shipment->status
                        !== ShipmentStatus::DeliveryFailed->value
                ) {
                    $fromStatus =
                        $shipment->status;

                    $shipment->forceFill([
                        'status' => ShipmentStatus::DeliveryFailed
                            ->value,
                    ])->save();

                    ShipmentEvent::query()->create([
                        'shipment_id' => $shipment->id,

                        'parcel_id' => null,

                        'event_type' => 'shipment_delivery_failed',

                        'from_status' => $fromStatus,

                        'to_status' => ShipmentStatus::DeliveryFailed
                            ->value,

                        'actor_user_id' => $actor->id,

                        'source' => 'rider',

                        'occurred_at' => $now,

                        'metadata' => [
                            'rider_profile_id' => $rider->id,

                            'dispatch_batch_ids' => $parcelBatchIds
                                ->values()
                                ->unique()
                                ->values()
                                ->all(),
                        ],
                    ]);
                }

                $this->notifyBuyerOfFailedDelivery($shipment);

                return $shipment
                    ->fresh([
                        'parcels',
                        'events',
                    ]);
            },
            3
        );
    }

    public function retryDelivery(
        string $shipmentNo,
        User $actor
    ): Shipment {
        return DB::transaction(
            function () use (
                $shipmentNo,
                $actor
            ) {
                if (
                    $actor->role !== UserRole::Rider->value
                    || $actor->status !== AccountStatus::Active->value
                ) {
                    throw new AuthorizationException(
                        'Only an active Rider can retry delivery.'
                    );
                }

                $rider = $actor
                    ->riderProfile()
                    ->first();
                $this->assertLocalRider($rider);

                if (
                    ! $rider
                    || $rider->verification_status !== 'approved'
                ) {
                    throw new AuthorizationException(
                        'An approved Rider profile is required.'
                    );
                }

                $shipment = Shipment::query()
                    ->where(
                        'shipment_no',
                        $shipmentNo
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    (int) $shipment->logistics_profile_id
                    !== (int) $rider->logistics_profile_id
                ) {
                    throw new AuthorizationException(
                        'This shipment does not belong to your logistics provider.'
                    );
                }

                $batches = DispatchBatch::query()
                    ->where(
                        'rider_profile_id',
                        $rider->id
                    )
                    ->where(
                        'status',
                        'dispatched'
                    )
                    ->whereHas(
                        'parcels',
                        fn ($query) => $query
                            ->where(
                                'parcels.shipment_id',
                                $shipment->id
                            )
                    )
                    ->with([
                        'parcels' => fn ($query) => $query->where(
                            'parcels.shipment_id',
                            $shipment->id
                        ),
                    ])
                    ->lockForUpdate()
                    ->get();

                if ($batches->isEmpty()) {
                    throw new AuthorizationException(
                        'This delivery is not assigned to the Rider.'
                    );
                }

                $parcelBatchIds = collect();

                foreach ($batches as $batch) {
                    foreach ($batch->parcels as $parcel) {
                        $parcelBatchIds->put(
                            $parcel->id,
                            $batch->id
                        );
                    }
                }

                $parcels = Parcel::query()
                    ->where(
                        'shipment_id',
                        $shipment->id
                    )
                    ->whereIn(
                        'id',
                        $parcelBatchIds->keys()
                    )
                    ->lockForUpdate()
                    ->get();

                if ($parcels->isEmpty()) {
                    throw new AuthorizationException(
                        'No assigned parcels were found for this delivery.'
                    );
                }

                foreach ($parcels as $parcel) {
                    if (
                        $parcel->status
                        !== ParcelStatus::Failed->value
                    ) {
                        throw new ConflictHttpException(
                            'Only failed parcels can retry delivery.'
                        );
                    }
                }

                $now = now();

                foreach ($parcels as $parcel) {
                    $parcel->forceFill([
                        'status' => ParcelStatus::OutForDelivery->value,

                        'last_event_at' => $now,
                    ])->save();

                    ShipmentEvent::query()->create([
                        'shipment_id' => $shipment->id,

                        'parcel_id' => $parcel->id,

                        'event_type' => 'parcel_delivery_retried',

                        'from_status' => ParcelStatus::Failed->value,

                        'to_status' => ParcelStatus::OutForDelivery->value,

                        'sorting_center_id' => $parcel
                            ->current_sorting_center_id,

                        'sorting_zone_id' => $parcel->current_zone_id,

                        'actor_user_id' => $actor->id,

                        'source' => 'rider',

                        'occurred_at' => $now,

                        'metadata' => [
                            'dispatch_batch_id' => $parcelBatchIds->get(
                                $parcel->id
                            ),

                            'rider_profile_id' => $rider->id,
                        ],
                    ]);
                }

                if (
                    $shipment->status
                    !== ShipmentStatus::OutForDelivery->value
                ) {
                    $fromStatus =
                        $shipment->status;

                    $shipment->forceFill([
                        'status' => ShipmentStatus::OutForDelivery->value,
                    ])->save();

                    ShipmentEvent::query()->create([
                        'shipment_id' => $shipment->id,

                        'parcel_id' => null,

                        'event_type' => 'shipment_delivery_retried',

                        'from_status' => $fromStatus,

                        'to_status' => ShipmentStatus::OutForDelivery->value,

                        'actor_user_id' => $actor->id,

                        'source' => 'rider',

                        'occurred_at' => $now,

                        'metadata' => [
                            'rider_profile_id' => $rider->id,

                            'dispatch_batch_ids' => $parcelBatchIds
                                ->values()
                                ->unique()
                                ->values()
                                ->all(),
                        ],
                    ]);
                }

                return $shipment
                    ->fresh([
                        'parcels',
                        'events',
                    ]);
            },
            3
        );
    }

    public function completeDelivery(
        string $shipmentNo,
        User $actor,
        UploadedFile $proofPhoto,
        string $recipientName,
        ?string $notes = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?ReturnRefundService $returns = null,
        ?int $codCollectedAmountMinor = null,
        ?CodCashCollectionService $codCash = null,
    ): DeliveryCompletionResult {
        $proofPath = null;

        try {
            $proofPath = $proofPhoto->store(
                'delivery-proofs',
                'local'
            );

            if (! $proofPath) {
                throw new \RuntimeException(
                    'The delivery proof photo could not be stored.'
                );
            }

            $result = DB::transaction(
                function () use (
                    $shipmentNo,
                    $actor,
                    $proofPath,
                    $recipientName,
                    $notes,
                    $latitude,
                    $longitude,
                    $returns,
                    $codCollectedAmountMinor,
                    $codCash,
                ) {
                    if (
                        $actor->role
                            !== UserRole::Rider->value
                        || $actor->status
                            !== AccountStatus::Active->value
                    ) {
                        throw new AuthorizationException(
                            'Only an active Rider can complete delivery.'
                        );
                    }

                    $rider = $actor
                        ->riderProfile()
                        ->first();
                    $this->assertLocalRider($rider);

                    if (
                        ! $rider
                        || $rider->verification_status
                            !== 'approved'
                    ) {
                        throw new AuthorizationException(
                            'An approved Rider profile is required.'
                        );
                    }

                    $shipment = Shipment::query()
                        ->where(
                            'shipment_no',
                            $shipmentNo
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (
                        (int) $shipment->logistics_profile_id
                        !== (int) $rider->logistics_profile_id
                    ) {
                        throw new AuthorizationException(
                            'This shipment does not belong to your Logistics provider.'
                        );
                    }

                    $batches = DispatchBatch::query()
                        ->where(
                            'rider_profile_id',
                            $rider->id
                        )
                        ->where(
                            'status',
                            'dispatched'
                        )
                        ->whereHas(
                            'parcels',
                            fn ($query) => $query->where(
                                'parcels.shipment_id',
                                $shipment->id
                            )
                        )
                        ->with([
                            'parcels' => fn ($query) => $query->where(
                                'parcels.shipment_id',
                                $shipment->id
                            ),
                        ])
                        ->lockForUpdate()
                        ->get();

                    if ($batches->isEmpty()) {
                        throw new AuthorizationException(
                            'This shipment is not assigned to this Rider.'
                        );
                    }

                    $parcelBatchIds = [];

                    foreach ($batches as $batch) {
                        foreach ($batch->parcels as $parcel) {
                            $parcelBatchIds[
                                $parcel->id
                            ] = $batch->id;
                        }
                    }

                    $parcelIds = collect(
                        array_keys(
                            $parcelBatchIds
                        )
                    );

                    if ($parcelIds->isEmpty()) {
                        throw new AuthorizationException(
                            'No assigned parcels were found.'
                        );
                    }

                    $parcels = Parcel::query()
                        ->whereIn(
                            'id',
                            $parcelIds
                        )
                        ->where(
                            'shipment_id',
                            $shipment->id
                        )
                        ->lockForUpdate()
                        ->get();

                    $recipientName = trim($recipientName);

                    if ($recipientName === '') {
                        throw new InvalidArgumentException(
                            'The recipient name is required.'
                        );
                    }

                    if (
                        $parcels->isNotEmpty()
                        && $parcels->every(
                            fn (Parcel $parcel) => $parcel->status
                                === ParcelStatus::Delivered->value
                        )
                    ) {
                        return new DeliveryCompletionResult(
                            $shipment->fresh([
                                'parcels',
                                'events',
                                'codCashCollection',
                            ]),
                            true,
                        );
                    }

                    if (
                        $parcels->contains(
                            fn (Parcel $parcel) => $parcel->status
                                !== ParcelStatus::OutForDelivery->value
                        )
                    ) {
                        throw new ConflictHttpException(
                            'Only out-for-delivery parcels can be completed.'
                        );
                    }

                    $verificationProgress = $this->deliveryVerificationProgressFor(
                        $shipment,
                        $rider,
                        $parcels,
                        $parcelBatchIds,
                    );

                    if (! $verificationProgress['all_verified']) {
                        throw ValidationException::withMessages([
                            'parcel_verification' => 'Scan each assigned Parcel barcode before confirming delivery.',
                        ]);
                    }

                    $now = now();

                    foreach ($parcels as $parcel) {
                        $latestAttemptNo =
                            (int) DeliveryAttempt::query()
                                ->where(
                                    'parcel_id',
                                    $parcel->id
                                )
                                ->max(
                                    'attempt_no'
                                );

                        $attempt =
                            DeliveryAttempt::query()
                                ->create([
                                    'parcel_id' => $parcel->id,

                                    'dispatch_batch_id' => $parcelBatchIds[
                                            $parcel->id
                                        ],

                                    'rider_profile_id' => $rider->id,

                                    'attempt_no' => $latestAttemptNo + 1,

                                    'outcome' => DeliveryAttemptOutcome::Delivered
                                        ->value,

                                    'failure_reason' => null,

                                    'notes' => $notes,

                                    'latitude' => $latitude,

                                    'longitude' => $longitude,

                                    'attempted_at' => $now,

                                    'next_attempt_at' => null,
                                ]);

                        DeliveryProof::query()
                            ->create([
                                'delivery_attempt_id' => $attempt->id,

                                'type' => 'photo',

                                'file_path' => $proofPath,

                                'recipient_name' => $recipientName,

                                'recipient_signature_path' => null,

                                'otp_verified_at' => null,

                                'captured_at' => $now,

                                'uploaded_by' => $actor->id,
                            ]);

                        $fromStatus =
                            $parcel->status;

                        $parcel->update([
                            'status' => ParcelStatus::Delivered->value,

                            'last_event_at' => $now,
                        ]);

                        $shipment
                            ->events()
                            ->create([
                                'parcel_id' => $parcel->id,

                                'event_type' => 'parcel_delivered',

                                'from_status' => $fromStatus,

                                'to_status' => ParcelStatus::Delivered->value,

                                'sorting_center_id' => $parcel
                                    ->current_sorting_center_id,

                                'sorting_zone_id' => $parcel
                                    ->current_zone_id,

                                'actor_user_id' => $actor->id,

                                'source' => 'rider',

                                'metadata' => [
                                    'dispatch_batch_id' => $parcelBatchIds[
                                            $parcel->id
                                        ],

                                    'rider_profile_id' => $rider->id,

                                    'delivery_attempt_id' => $attempt->id,

                                    'proof_type' => 'photo',
                                ],

                                'occurred_at' => $now,
                            ]);
                    }

                    $shipmentParcels = $shipment
                        ->parcels()
                        ->lockForUpdate()
                        ->get([
                            'id',
                            'status',
                        ]);

                    $allDelivered =
                        $shipmentParcels->isNotEmpty()
                        && $shipmentParcels->every(
                            fn (Parcel $parcel) => $parcel->status
                                    === ParcelStatus::Delivered->value
                        );

                    $allDeliveryAttemptsResolved =
                        $shipmentParcels->isNotEmpty()
                        && $shipmentParcels->every(
                            fn (Parcel $parcel) => in_array(
                                $parcel->status,
                                [
                                    ParcelStatus::Delivered->value,
                                    ParcelStatus::Failed->value,
                                ],
                                true
                            )
                        );

                    $hasFailedParcel =
                        $shipmentParcels->contains(
                            fn (Parcel $parcel) => $parcel->status
                                    === ParcelStatus::Failed->value
                        );

                    $aggregateStatus = null;
                    $aggregateEventType = null;

                    if ($allDelivered) {
                        $aggregateStatus =
                            ShipmentStatus::Delivered->value;

                        $aggregateEventType =
                            'shipment_delivered';
                    } elseif (
                        $allDeliveryAttemptsResolved
                        && $hasFailedParcel
                    ) {
                        $aggregateStatus =
                            ShipmentStatus::DeliveryFailed->value;

                        $aggregateEventType =
                            'shipment_delivery_failed';
                    }

                    if (
                        $aggregateStatus !== null
                        && $shipment->status !== $aggregateStatus
                    ) {
                        $fromStatus =
                            $shipment->status;

                        $shipment->update([
                            'status' => $aggregateStatus,
                            'delivered_at' => $aggregateStatus === ShipmentStatus::Delivered->value
                                ? $now
                                : $shipment->delivered_at,
                        ]);

                        $centerIds = $parcels
                            ->pluck(
                                'current_sorting_center_id'
                            )
                            ->filter()
                            ->unique()
                            ->values();

                        $zoneIds = $parcels
                            ->pluck(
                                'current_zone_id'
                            )
                            ->filter()
                            ->unique()
                            ->values();

                        $shipment
                            ->events()
                            ->create([
                                'event_type' => $aggregateEventType,

                                'from_status' => $fromStatus,

                                'to_status' => $aggregateStatus,

                                'sorting_center_id' => $centerIds->count() === 1
                                        ? $centerIds->first()
                                        : null,

                                'sorting_zone_id' => $zoneIds->count() === 1
                                        ? $zoneIds->first()
                                        : null,

                                'actor_user_id' => $actor->id,

                                'source' => 'rider',

                                'metadata' => [
                                    'dispatch_batch_ids' => $batches
                                        ->pluck('id')
                                        ->unique()
                                        ->values()
                                        ->all(),

                                    'rider_profile_id' => $rider->id,
                                ],

                                'occurred_at' => $now,
                            ]);

                        if (
                            $aggregateStatus === ShipmentStatus::Delivered->value
                            && $shipment->purpose === 'outbound'
                        ) {
                            $this->notifyDeliveryCompleted($shipment);
                        }

                        if (
                            $aggregateStatus === ShipmentStatus::Delivered->value
                            && $shipment->purpose === 'return'
                            && $returns
                        ) {
                            $returns->recordReturnDelivered((int) $shipment->id, $actor);
                        }

                        if (
                            $aggregateStatus === ShipmentStatus::Delivered->value
                            && $shipment->purpose === 'outbound'
                            && (int) $shipment->cod_amount_minor > 0
                        ) {
                            if ($codCollectedAmountMinor === null || ! $codCash) {
                                throw ValidationException::withMessages([
                                    'cod_collected_amount' => 'Record the exact COD cash collected before completing this delivery.',
                                ]);
                            }

                            $codCash->recordRiderCollection(
                                $shipment,
                                $actor,
                                $codCollectedAmountMinor,
                            );
                        }
                    }

                    return new DeliveryCompletionResult(
                        $shipment->fresh([
                            'parcels',
                            'events',
                            'codCashCollection',
                        ]),
                        false,
                    );
                },
                3
            );

            if ($result->alreadyCompleted) {
                Storage::disk('local')->delete($proofPath);
            }

            return $result;
        } catch (Throwable $exception) {
            if (
                $proofPath
                && Storage::disk('local')
                    ->exists($proofPath)
            ) {
                Storage::disk('local')
                    ->delete($proofPath);
            }

            throw $exception;
        }
    }

    private function activeApprovedLocalRider(User $actor, string $action): RiderProfile
    {
        if (
            $actor->role !== UserRole::Rider->value
            || $actor->status !== AccountStatus::Active->value
        ) {
            throw new AuthorizationException("Only an active Rider can {$action}.");
        }

        $rider = $actor->riderProfile()->first();
        $this->assertLocalRider($rider);

        if (! $rider || $rider->verification_status !== 'approved') {
            throw new AuthorizationException('An approved Rider profile is required.');
        }

        return $rider;
    }

    private function assertShipmentOwnedByRiderProvider(Shipment $shipment, RiderProfile $rider): void
    {
        if ((int) $shipment->logistics_profile_id !== (int) $rider->logistics_profile_id) {
            throw new AuthorizationException(
                'This shipment does not belong to your Logistics provider.',
            );
        }
    }

    /**
     * @return array{batches: Collection<int, DispatchBatch>, parcels: Collection<int, Parcel>, parcel_batch_ids: array<int, int>}
     */
    private function assignedDeliveryParcels(
        Shipment $shipment,
        RiderProfile $rider,
        bool $lock,
    ): array {
        $batchQuery = DispatchBatch::query()
            ->where('rider_profile_id', $rider->id)
            ->where('status', 'dispatched')
            ->whereHas(
                'parcels',
                fn ($query) => $query->where('parcels.shipment_id', $shipment->id),
            );

        if ($lock) {
            $batchQuery->lockForUpdate();
        }

        $batches = $batchQuery
            ->with([
                'parcels' => fn ($query) => $query->where('parcels.shipment_id', $shipment->id),
            ])
            ->orderBy('id')
            ->get();

        if ($batches->isEmpty()) {
            throw new AuthorizationException('This shipment is not assigned to this Rider.');
        }

        $parcelBatchIds = [];

        foreach ($batches as $batch) {
            foreach ($batch->parcels as $parcel) {
                $parcelBatchIds[$parcel->id] = (int) $batch->id;
            }
        }

        if ($parcelBatchIds === []) {
            throw new AuthorizationException('No assigned Parcels were found.');
        }

        $parcelQuery = Parcel::query()
            ->whereIn('id', array_keys($parcelBatchIds))
            ->where('shipment_id', $shipment->id);

        if ($lock) {
            $parcelQuery->lockForUpdate();
        }

        $parcels = $parcelQuery->get();

        if ($parcels->isEmpty()) {
            throw new AuthorizationException('No assigned Parcels were found.');
        }

        return [
            'batches' => $batches,
            'parcels' => $parcels,
            'parcel_batch_ids' => $parcelBatchIds,
        ];
    }

    /**
     * @param  Collection<int, Parcel>  $parcels
     * @param  array<int, int>  $parcelBatchIds
     * @return array{assigned_parcel_nos: list<string>, verified_parcel_nos: list<string>, verified_count: int, total_count: int, all_verified: bool}
     */
    private function deliveryVerificationProgressFor(
        Shipment $shipment,
        RiderProfile $rider,
        Collection $parcels,
        array $parcelBatchIds,
    ): array {
        $verifiedParcelIds = [];

        ShipmentEvent::query()
            ->where('shipment_id', $shipment->id)
            ->whereIn('parcel_id', $parcels->modelKeys())
            ->where('event_type', 'parcel_barcode_verified')
            ->get(['parcel_id', 'metadata'])
            ->each(function (ShipmentEvent $event) use ($parcelBatchIds, $rider, &$verifiedParcelIds): void {
                $parcelId = (int) $event->parcel_id;
                $metadata = $event->metadata ?? [];

                if (
                    isset($parcelBatchIds[$parcelId])
                    && (int) ($metadata['dispatch_batch_id'] ?? 0) === $parcelBatchIds[$parcelId]
                    && (int) ($metadata['rider_profile_id'] ?? 0) === (int) $rider->id
                ) {
                    $verifiedParcelIds[$parcelId] = true;
                }
            });

        $verifiedParcelNos = $parcels
            ->filter(fn (Parcel $parcel): bool => isset($verifiedParcelIds[(int) $parcel->id]))
            ->pluck('parcel_no')
            ->map(fn ($parcelNo): string => (string) $parcelNo)
            ->values()
            ->all();
        $verifiedCount = count($verifiedParcelNos);
        $totalCount = $parcels->count();
        $assignedParcelNos = $parcels
            ->pluck('parcel_no')
            ->map(fn ($parcelNo): string => (string) $parcelNo)
            ->values()
            ->all();

        return [
            'assigned_parcel_nos' => $assignedParcelNos,
            'verified_parcel_nos' => $verifiedParcelNos,
            'verified_count' => $verifiedCount,
            'total_count' => $totalCount,
            'all_verified' => $totalCount > 0 && $verifiedCount === $totalCount,
        ];
    }

    /**
     * @param  Collection<int, DispatchBatch>  $batches
     */
    private function notifyLogisticsDeliveryParcelsVerified(
        Shipment $shipment,
        RiderProfile $rider,
        User $actor,
        Collection $batches,
        int $parcelCount,
    ): void {
        $shipment->loadMissing('logisticsProfile.user');
        $logisticsUser = $shipment->logisticsProfile?->user;

        if (! $logisticsUser) {
            return;
        }

        $parcelLabel = $parcelCount === 1 ? '1 Parcel' : "{$parcelCount} Parcels";

        $this->notifications->sendAfterCommit($logisticsUser, new WorkflowNotification(
            eventKey: 'logistics_delivery_parcels_verified',
            title: 'Delivery Parcels verified',
            body: "All {$parcelLabel} assigned to {$actor->name} for shipment {$shipment->shipment_no} have been barcode-verified. Delivery remains Out for Delivery until the Rider confirms the handoff.",
            actionUrl: route('logistics.dispatch.monitoring'),
            type: 'info',
            meta: [
                'shipment_id' => (int) $shipment->id,
                'shipment_no' => $shipment->shipment_no,
                'rider_profile_id' => (int) $rider->id,
                'dispatch_batch_ids' => $batches->modelKeys(),
                'parcel_count' => $parcelCount,
            ],
        ));
    }

    private function assertLocalRider(?\App\Models\RiderProfile $rider): void
    {
        if ($rider && ! RiderWorkType::isLocal($rider->work_type)) {
            throw new AuthorizationException(
                'Courier profiles cannot receive local delivery assignments.'
            );
        }
    }

    private function notifyBuyerOutForDelivery(Shipment $shipment): void
    {
        if ($shipment->purpose !== 'outbound') {
            return;
        }

        $sellerOrders = $shipment->includedSellerOrders();
        $sellerOrders->loadMissing(['order.buyer']);

        foreach ($sellerOrders as $sellerOrder) {
            $buyer = $sellerOrder->order?->buyer;
            if (! $buyer) {
                continue;
            }

            $this->notifications->sendAfterCommit($buyer, new WorkflowNotification(
                eventKey: 'buyer_shipment_updates',
                title: 'Out for delivery',
                body: "{$sellerOrder->seller_order_no} is out for delivery today.",
                actionUrl: route('buyer.profile').'#tracking',
                type: 'info',
                meta: ['seller_order_id' => (int) $sellerOrder->id, 'shipment_id' => (int) $shipment->id],
            ));
        }
    }

    private function notifyBuyerOfFailedDelivery(Shipment $shipment): void
    {
        if ($shipment->purpose !== 'outbound') {
            return;
        }

        $sellerOrders = $shipment->includedSellerOrders();
        $sellerOrders->loadMissing(['order.buyer']);

        foreach ($sellerOrders as $sellerOrder) {
            $buyer = $sellerOrder->order?->buyer;
            if (! $buyer) {
                continue;
            }

            $this->notifications->sendAfterCommit($buyer, new WorkflowNotification(
                eventKey: 'buyer_shipment_updates',
                title: 'Delivery attempt failed',
                body: "The Rider could not deliver {$sellerOrder->seller_order_no}. Logistics will schedule another attempt.",
                actionUrl: route('buyer.profile').'#tracking',
                type: 'warning',
                meta: ['seller_order_id' => (int) $sellerOrder->id, 'shipment_id' => (int) $shipment->id],
            ));
        }
    }

    private function notifyDeliveryCompleted(Shipment $shipment): void
    {
        $sellerOrders = $shipment->includedSellerOrders();
        if ($sellerOrders->isEmpty()) {
            return;
        }

        $sellerOrders->loadMissing(['order.buyer', 'store.sellerProfile.user']);

        foreach ($sellerOrders as $sellerOrder) {
            $buyer = $sellerOrder->order?->buyer;
            if ($buyer) {
                $this->notifications->sendAfterCommit($buyer, new WorkflowNotification(
                    eventKey: 'buyer_shipment_updates',
                    title: 'Order delivered',
                    body: "The shipment for {$sellerOrder->seller_order_no} was delivered.",
                    actionUrl: route('buyer.profile').'#tracking',
                    type: 'success',
                    meta: ['seller_order_id' => (int) $sellerOrder->id, 'shipment_id' => (int) $shipment->id],
                ));
            }

            $sellerUser = $sellerOrder->store?->sellerProfile?->user;
            if ($sellerUser) {
                $this->notifications->sendAfterCommit($sellerUser, new WorkflowNotification(
                    eventKey: 'seller_shipment_updates',
                    title: 'Shipment delivered',
                    body: "{$sellerOrder->seller_order_no} was delivered to the buyer.",
                    actionUrl: route('seller.fulfillment.tracking'),
                    type: 'success',
                    meta: ['seller_order_id' => (int) $sellerOrder->id, 'shipment_id' => (int) $shipment->id],
                ));
            }
        }
    }
}
