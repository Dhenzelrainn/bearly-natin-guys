<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\DeliveryAttemptOutcome;
use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\DispatchBatch;
use App\Models\Parcel;
use App\Models\Shipment;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryProof;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class RiderDeliveryService
{
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
                        fn ($query) =>
                            $query->where(
                                'parcels.shipment_id',
                                $shipment->id
                            )
                    )
                    ->with([
                        'parcels' =>
                            fn ($query) =>
                                $query->where(
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
                        $batch->parcels
                        as $parcel
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
                        fn (Parcel $parcel) =>
                            $parcel->status
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
                        'status' =>
                            ParcelStatus::OutForDelivery
                                ->value,

                        'last_event_at' =>
                            $now,
                    ]);

                    $shipment
                        ->events()
                        ->create([
                            'parcel_id' =>
                                $parcel->id,

                            'event_type' =>
                                'parcel_out_for_delivery',

                            'from_status' =>
                                $fromStatus,

                            'to_status' =>
                                ParcelStatus::OutForDelivery
                                    ->value,

                            'sorting_center_id' =>
                                $parcel
                                    ->current_sorting_center_id,

                            'sorting_zone_id' =>
                                $parcel
                                    ->current_zone_id,

                            'actor_user_id' =>
                                $actor->id,

                            'source' =>
                                'rider',

                            'metadata' => [
                                'dispatch_batch_id' =>
                                    $parcelBatchIds[
                                        $parcel->id
                                    ],

                                'rider_profile_id' =>
                                    $rider->id,
                            ],

                            'occurred_at' =>
                                $now,
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
                        'status' =>
                            ShipmentStatus::OutForDelivery
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
                            'event_type' =>
                                'shipment_out_for_delivery',

                            'from_status' =>
                                $fromStatus,

                            'to_status' =>
                                ShipmentStatus::OutForDelivery
                                    ->value,

                            'sorting_center_id' =>
                                $centerIds->count() === 1
                                    ? $centerIds->first()
                                    : null,

                            'sorting_zone_id' =>
                                $zoneIds->count() === 1
                                    ? $zoneIds->first()
                                    : null,

                            'actor_user_id' =>
                                $actor->id,

                            'source' =>
                                'rider',

                            'metadata' => [
                                'dispatch_batch_ids' =>
                                    $batches
                                        ->pluck('id')
                                        ->unique()
                                        ->values()
                                        ->all(),

                                'rider_profile_id' =>
                                    $rider->id,
                            ],

                            'occurred_at' =>
                                $now,
                        ]);
                }

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
                        fn ($query) =>
                            $query->where(
                                'parcels.shipment_id',
                                $shipment->id
                            )
                    )
                    ->with([
                        'parcels' =>
                            fn ($query) =>
                                $query->where(
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
                        fn (Parcel $parcel) =>
                            $parcel->status
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
                                'parcel_id' =>
                                    $parcel->id,

                                'dispatch_batch_id' =>
                                    $parcelBatchIds[
                                        $parcel->id
                                    ],

                                'rider_profile_id' =>
                                    $rider->id,

                                'attempt_no' =>
                                    $latestAttemptNo + 1,

                                'outcome' =>
                                    $outcome->value,

                                'failure_reason' =>
                                    $outcome
                                        === DeliveryAttemptOutcome::Failed
                                        ? $failureReason
                                        : null,

                                'notes' =>
                                    $notes,

                                'latitude' =>
                                    $latitude,

                                'longitude' =>
                                    $longitude,

                                'attempted_at' =>
                                    $now,

                                'next_attempt_at' =>
                                    $outcome
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

    public function completeDelivery(
        string $shipmentNo,
        User $actor,
        UploadedFile $proofPhoto,
        string $recipientName,
        ?string $notes = null,
        ?float $latitude = null,
        ?float $longitude = null
    ): Shipment {
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

            return DB::transaction(
                function () use (
                    $shipmentNo,
                    $actor,
                    $proofPath,
                    $recipientName,
                    $notes,
                    $latitude,
                    $longitude
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
                            fn ($query) =>
                                $query->where(
                                    'parcels.shipment_id',
                                    $shipment->id
                                )
                        )
                        ->with([
                            'parcels' =>
                                fn ($query) =>
                                    $query->where(
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

                    if (
                        $parcels->contains(
                            fn (Parcel $parcel) =>
                                $parcel->status
                                !== ParcelStatus::OutForDelivery->value
                        )
                    ) {
                        throw new ConflictHttpException(
                            'Only out-for-delivery parcels can be completed.'
                        );
                    }

                    $recipientName = trim(
                        $recipientName
                    );

                    if ($recipientName === '') {
                        throw new InvalidArgumentException(
                            'The recipient name is required.'
                        );
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
                                    'parcel_id' =>
                                        $parcel->id,

                                    'dispatch_batch_id' =>
                                        $parcelBatchIds[
                                            $parcel->id
                                        ],

                                    'rider_profile_id' =>
                                        $rider->id,

                                    'attempt_no' =>
                                        $latestAttemptNo + 1,

                                    'outcome' =>
                                        DeliveryAttemptOutcome::Delivered
                                            ->value,

                                    'failure_reason' =>
                                        null,

                                    'notes' =>
                                        $notes,

                                    'latitude' =>
                                        $latitude,

                                    'longitude' =>
                                        $longitude,

                                    'attempted_at' =>
                                        $now,

                                    'next_attempt_at' =>
                                        null,
                                ]);

                        DeliveryProof::query()
                            ->create([
                                'delivery_attempt_id' =>
                                    $attempt->id,

                                'type' =>
                                    'photo',

                                'file_path' =>
                                    $proofPath,

                                'recipient_name' =>
                                    $recipientName,

                                'recipient_signature_path' =>
                                    null,

                                'otp_verified_at' =>
                                    null,

                                'captured_at' =>
                                    $now,

                                'uploaded_by' =>
                                    $actor->id,
                            ]);

                        $fromStatus =
                            $parcel->status;

                        $parcel->update([
                            'status' =>
                                ParcelStatus::Delivered->value,

                            'last_event_at' =>
                                $now,
                        ]);

                        $shipment
                            ->events()
                            ->create([
                                'parcel_id' =>
                                    $parcel->id,

                                'event_type' =>
                                    'parcel_delivered',

                                'from_status' =>
                                    $fromStatus,

                                'to_status' =>
                                    ParcelStatus::Delivered->value,

                                'sorting_center_id' =>
                                    $parcel
                                        ->current_sorting_center_id,

                                'sorting_zone_id' =>
                                    $parcel
                                        ->current_zone_id,

                                'actor_user_id' =>
                                    $actor->id,

                                'source' =>
                                    'rider',

                                'metadata' => [
                                    'dispatch_batch_id' =>
                                        $parcelBatchIds[
                                            $parcel->id
                                        ],

                                    'rider_profile_id' =>
                                        $rider->id,

                                    'delivery_attempt_id' =>
                                        $attempt->id,

                                    'proof_type' =>
                                        'photo',
                                ],

                                'occurred_at' =>
                                    $now,
                            ]);
                    }

                    $hasUndeliveredParcels =
                        $shipment
                            ->parcels()
                            ->where(
                                'status',
                                '!=',
                                ParcelStatus::Delivered->value
                            )
                            ->exists();

                    if (! $hasUndeliveredParcels) {
                        $fromStatus =
                            $shipment->status;

                        $shipment->update([
                            'status' =>
                                ShipmentStatus::Delivered->value,
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
                                'event_type' =>
                                    'shipment_delivered',

                                'from_status' =>
                                    $fromStatus,

                                'to_status' =>
                                    ShipmentStatus::Delivered->value,

                                'sorting_center_id' =>
                                    $centerIds->count() === 1
                                        ? $centerIds->first()
                                        : null,

                                'sorting_zone_id' =>
                                    $zoneIds->count() === 1
                                        ? $zoneIds->first()
                                        : null,

                                'actor_user_id' =>
                                    $actor->id,

                                'source' =>
                                    'rider',

                                'metadata' => [
                                    'dispatch_batch_ids' =>
                                        $batches
                                            ->pluck('id')
                                            ->unique()
                                            ->values()
                                            ->all(),

                                    'rider_profile_id' =>
                                        $rider->id,
                                ],

                                'occurred_at' =>
                                    $now,
                            ]);
                    }

                    return $shipment->fresh([
                        'parcels',
                        'events',
                    ]);
                },
                3
            );
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

}