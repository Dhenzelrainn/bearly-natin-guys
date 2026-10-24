<?php

namespace App\Services;

use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\LogisticsProfile;
use App\Models\Parcel;
use App\Models\Shipment;
use App\Models\ShipmentTransfer;
use App\Models\SortingCenter;
use App\Models\User;
use App\Models\Waybill;
use App\Notifications\WorkflowNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShipmentHandoffService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SortingZoneCoverageGuard $coverageGuard,
    ) {}

    public function hasSingleActiveSortingCenter(LogisticsProfile $profile): bool
    {
        return $this->activeSortingCenterFor($profile) !== null;
    }

    public function activeSortingCenterFor(LogisticsProfile $profile): ?SortingCenter
    {
        $centers = $profile->sortingCenters()
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($centers->count() !== 1 || $centers->first()->status !== 'active') {
            return null;
        }

        return $centers->first();
    }

    public function destinationProviderFor(Shipment $shipment): ?LogisticsProfile
    {
        $cityCode = $this->destinationCityCode($shipment);

        if (! $cityCode) {
            return null;
        }

        return LogisticsProfile::query()
            ->where('psgc_city_code', $cityCode)
                ->where('status', 'active')
                ->whereHas('user', fn ($query) => $query
                    ->where('status', 'active')
                    ->where(fn ($roles) => $roles
                    ->where('role', UserRole::Logistics->value)
                    ->orWhereHas('roles', fn ($roleQuery) => $roleQuery
                        ->where('roles.name', UserRole::Logistics->value))))
            ->first();
    }

    public function isCrossCityFor(
        Shipment $shipment,
        LogisticsProfile $profile
    ): bool {
        $destinationCityCode = $this->destinationCityCode($shipment);
        $profileCityCode = trim((string) $profile->psgc_city_code);

        return filled($destinationCityCode)
            && $profileCityCode !== ''
            && trim((string) $destinationCityCode) !== $profileCityCode;
    }

    public function send(Shipment $shipment, User $actor): ShipmentTransfer
    {
        return DB::transaction(function () use ($shipment, $actor): ShipmentTransfer {
            $shipment = Shipment::query()
                ->with([
                    'sellerOrder.order',
                    'destinationAddress',
                ])
                ->lockForUpdate()
                ->findOrFail($shipment->id);

            $originProfileId = (int) $shipment->logistics_profile_id;
            $actorProfileId = (int) ($actor->logisticsProfile?->id ?? 0);

            if ($actorProfileId < 1 || $actorProfileId !== $originProfileId) {
                throw new AuthorizationException(
                    'You cannot transfer a shipment owned by another Logistics account.'
                );
            }

            if (! in_array($shipment->purpose, ['outbound', 'return'], true)) {
                throw new ConflictHttpException('This shipment purpose cannot be transferred.');
            }

            if ($shipment->status !== ShipmentStatus::AtSortingCenter->value) {
                throw new ConflictHttpException(
                    'A shipment can be transferred only after all parcels are received at a Sorting Center.'
                );
            }

            if (ShipmentTransfer::query()->where('shipment_id', $shipment->id)->exists()) {
                throw new ConflictHttpException('This shipment already has a recorded Logistics handoff.');
            }

            $target = $this->destinationProviderFor($shipment);

            if (! $target) {
                throw new ConflictHttpException(
                    'No active Logistics account is configured for the shipment destination municipality/city.'
                );
            }

            if ((int) $target->id === $originProfileId) {
                throw new ConflictHttpException(
                    'This shipment is already assigned to the Logistics account serving its destination city.'
                );
            }

            $targetProfileId = (int) $target->id;
            $lockedProfiles = LogisticsProfile::query()
                ->whereIn('id', [$originProfileId, $targetProfileId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $origin = $lockedProfiles->get($originProfileId);
            $target = $lockedProfiles->get($targetProfileId);

            if (! $origin || ! $target) {
                throw new ConflictHttpException(
                    'The origin or destination Logistics account is no longer available for this handoff.'
                );
            }

            if (
                $actorProfileId !== (int) $origin->id
                || $this->destinationCityCode($shipment) !== trim((string) $target->psgc_city_code)
            ) {
                throw new ConflictHttpException(
                    'The destination Logistics account no longer serves this shipment city.'
                );
            }

            $targetUser = User::query()
                ->whereKey($target->user_id)
                ->lockForUpdate()
                ->first();

            $targetCenter = $this->singleActiveSortingCenterForUpdate($target);

            if (
                $target->status !== 'active'
                || ! $targetUser
                || $targetUser->status !== 'active'
                || ! $targetUser->hasRole(UserRole::Logistics->value)
                || ! $targetCenter
            ) {
                throw new ConflictHttpException(
                    'The destination Logistics account is not currently able to receive parcels.'
                );
            }

            $parcels = Parcel::query()
                ->where('shipment_id', $shipment->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($parcels->isEmpty() || $parcels->contains(
                fn (Parcel $parcel): bool => $parcel->status !== ParcelStatus::Received->value
            )) {
                throw new ConflictHttpException(
                    'Every parcel must be received and unsorted before a Logistics handoff.'
                );
            }

            $centerIds = $parcels->pluck('current_sorting_center_id')->unique()->values();

            if ($centerIds->count() !== 1 || ! $centerIds->first()) {
                throw new ConflictHttpException(
                    'All shipment parcels must be at the same Sorting Center before transfer.'
                );
            }

            $originCenter = SortingCenter::query()
                ->whereKey((int) $centerIds->first())
                ->where('logistics_profile_id', $origin->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $originCenter) {
                throw new ConflictHttpException(
                    'The shipment is not at an active Sorting Center owned by the current Logistics account.'
                );
            }

            $this->coverageGuard->assertParcelsCovered(
                $targetCenter,
                $parcels,
                'The destination barangay is not covered by an active zone at the destination Sorting Center. Restore valid coverage before sending this handoff.'
            );

            $now = now();
            $transfer = ShipmentTransfer::query()->create([
                'shipment_id' => $shipment->id,
                'from_logistics_profile_id' => $origin->id,
                'to_logistics_profile_id' => $target->id,
                'from_sorting_center_id' => $originCenter->id,
                'status' => 'sent',
                'sent_by' => $actor->id,
                'sent_at' => $now,
            ]);

            $shipment->update(['status' => ShipmentStatus::HandoffPending->value]);

            foreach ($parcels as $parcel) {
                $parcel->update([
                    'status' => ParcelStatus::InTransit->value,
                    'last_event_at' => $now,
                ]);

                $shipment->events()->create([
                    'parcel_id' => $parcel->id,
                    'event_type' => 'logistics_handoff_sent',
                    'from_status' => ParcelStatus::Received->value,
                    'to_status' => ParcelStatus::InTransit->value,
                    'sorting_center_id' => $originCenter->id,
                    'actor_user_id' => $actor->id,
                    'source' => 'logistics',
                    'metadata' => [
                        'shipment_transfer_id' => $transfer->id,
                        'from_logistics_profile_id' => $origin->id,
                        'to_logistics_profile_id' => $target->id,
                    ],
                    'occurred_at' => $now,
                ]);
            }

            $this->notifyDestinationOfHandoff($transfer, $origin, $targetUser);

            return $transfer->fresh([
                'shipment.waybill',
                'fromLogisticsProfile',
                'toLogisticsProfile',
            ]);
        }, attempts: 3);
    }

    /**
     * Tell the receiving Logistics account that parcels are on the way.
     */
    private function notifyDestinationOfHandoff(ShipmentTransfer $transfer, LogisticsProfile $origin, ?User $targetUser): void
    {
        if (! $targetUser) {
            return;
        }

        $reference = $this->shipmentReference($transfer);

        $this->notifications->sendAfterCommit($targetUser, new WorkflowNotification(
            eventKey: 'logistics_handoff_sent',
            title: 'Incoming parcel handoff',
            body: "{$reference} was sent from {$origin->display_name} and is arriving at your facility.",
            actionUrl: route('logistics.transfers.index'),
            type: 'info',
            meta: ['shipment_transfer_id' => (int) $transfer->id],
        ));
    }

    public function accept(
        string $identifier,
        User $actor,
        string $scanMethod = 'manual'
    ): ShipmentTransfer {
        return DB::transaction(function () use (
            $identifier,
            $actor,
            $scanMethod
        ): ShipmentTransfer {
            $waybill = Waybill::query()
                ->matchingIdentifier(trim($identifier))
                ->firstOrFail();

            $shipment = Shipment::query()
                ->lockForUpdate()
                ->findOrFail($waybill->shipment_id);

            $transfer = ShipmentTransfer::query()
                ->where('shipment_id', $shipment->id)
                ->lockForUpdate()
                ->first();

            if (! $transfer) {
                throw (new ModelNotFoundException)->setModel(ShipmentTransfer::class);
            }

            $recipient = LogisticsProfile::query()
                ->whereKey($transfer->to_logistics_profile_id)
                ->lockForUpdate()
                ->firstOrFail();
            $recipientUser = User::query()
                ->whereKey($recipient->user_id)
                ->lockForUpdate()
                ->first();
            $actorProfileId = (int) ($actor->logisticsProfile?->id ?? 0);

            if ($actorProfileId < 1 || $actorProfileId !== (int) $recipient->id) {
                throw new AuthorizationException(
                    'Only the destination Logistics account may accept this handoff.'
                );
            }

            if ($transfer->status !== 'sent') {
                throw new ConflictHttpException('This Logistics handoff has already been accepted.');
            }

            if (
                $recipient->status !== 'active'
                || ! $recipientUser
                || $recipientUser->status !== 'active'
                || ! $recipientUser->hasRole(UserRole::Logistics->value)
            ) {
                throw new ConflictHttpException(
                    'The destination Logistics account is no longer active and cannot accept this handoff.'
                );
            }

            $center = $this->singleActiveSortingCenterForUpdate($recipient);

            if (! $center) {
                throw new ConflictHttpException(
                    'The destination Logistics account must have exactly one active Sorting Center to accept this handoff.'
                );
            }

            if (
                $shipment->status !== ShipmentStatus::HandoffPending->value
                || (int) $shipment->logistics_profile_id !== (int) $transfer->from_logistics_profile_id
            ) {
                throw new ConflictHttpException(
                    'The shipment is no longer awaiting this Logistics handoff.'
                );
            }

            if (
                ! in_array($shipment->purpose, ['outbound', 'return'], true)
                || $this->destinationCityCode($shipment) !== (string) $recipient->psgc_city_code
            ) {
                throw new ConflictHttpException(
                    'This Logistics account does not serve the shipment destination municipality/city.'
                );
            }

            $parcels = Parcel::query()
                ->where('shipment_id', $shipment->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($parcels->isEmpty() || $parcels->contains(
                fn (Parcel $parcel): bool =>
                    $parcel->status !== ParcelStatus::InTransit->value
                    || (int) $parcel->current_sorting_center_id !== (int) $transfer->from_sorting_center_id
            )) {
                throw new ConflictHttpException(
                    'The transfer contents or last recorded Sorting Center no longer match this handoff.'
                );
            }

            $this->coverageGuard->assertParcelsCovered(
                $center,
                $parcels,
                'Destination zone coverage changed and no longer covers the shipment barangay. Restore active coverage before accepting this handoff.'
            );

            $now = now();
            $shipment->update([
                'logistics_profile_id' => $recipient->id,
                'status' => ShipmentStatus::AtSortingCenter->value,
            ]);
            $transfer->update([
                'to_sorting_center_id' => $center->id,
                'status' => 'accepted',
                'accepted_by' => $actor->id,
                'accepted_at' => $now,
            ]);

            foreach ($parcels as $parcel) {
                $parcel->update([
                    'status' => ParcelStatus::Received->value,
                    'current_sorting_center_id' => $center->id,
                    'current_zone_id' => null,
                    'last_event_at' => $now,
                ]);

                $shipment->events()->create([
                    'parcel_id' => $parcel->id,
                    'event_type' => 'logistics_handoff_accepted',
                    'from_status' => ParcelStatus::InTransit->value,
                    'to_status' => ParcelStatus::Received->value,
                    'sorting_center_id' => $center->id,
                    'actor_user_id' => $actor->id,
                    'source' => 'scan',
                    'scan_method' => $scanMethod,
                    'metadata' => [
                        'shipment_transfer_id' => $transfer->id,
                        'from_logistics_profile_id' => $transfer->from_logistics_profile_id,
                        'to_logistics_profile_id' => $recipient->id,
                    ],
                    'occurred_at' => $now,
                ]);
            }

            $this->notifyOriginOfAcceptance($transfer, $recipient);

            return $transfer->fresh([
                'shipment.waybill',
                'fromLogisticsProfile',
                'toLogisticsProfile',
                'toSortingCenter',
            ]);
        }, attempts: 3);
    }

    /**
     * Confirm to the sending Logistics account that the handoff was accepted.
     */
    private function notifyOriginOfAcceptance(ShipmentTransfer $transfer, LogisticsProfile $recipient): void
    {
        $originUser = LogisticsProfile::query()
            ->whereKey($transfer->from_logistics_profile_id)
            ->first()
            ?->user;

        if (! $originUser) {
            return;
        }

        $reference = $this->shipmentReference($transfer);

        $this->notifications->sendAfterCommit($originUser, new WorkflowNotification(
            eventKey: 'logistics_handoff_accepted',
            title: 'Handoff accepted',
            body: "{$reference} was received and accepted at {$recipient->display_name}.",
            actionUrl: route('logistics.transfers.index'),
            type: 'success',
            meta: ['shipment_transfer_id' => (int) $transfer->id],
        ));
    }

    private function shipmentReference(ShipmentTransfer $transfer): string
    {
        $waybillNo = $transfer->shipment?->waybill?->waybill_no;

        return $waybillNo ? "Waybill {$waybillNo}" : 'The shipment';
    }

    private function destinationCityCode(Shipment $shipment): ?string
    {
        if (filled($shipment->destination_city_code)) {
            return trim((string) $shipment->destination_city_code);
        }

        $snapshot = $shipment->destination_address_snapshot;

        if (
            $shipment->purpose === 'return'
            && is_array($snapshot)
            && filled($snapshot['psgc_city_code'] ?? null)
        ) {
            return trim((string) $snapshot['psgc_city_code']);
        }

        $shipment->loadMissing([
            'sellerOrder.order',
            'destinationAddress',
        ]);

        $destination = $shipment->purpose === 'return'
            ? (is_array($snapshot)
                ? (object) $snapshot
                : $shipment->destinationAddress)
            : $shipment->sellerOrder?->order;
        $code = $destination?->psgc_city_code;

        if (filled($code)) {
            return trim((string) $code);
        }

        if (! $destination) {
            return null;
        }

        try {
            return app(PostalCodeLookup::class)->canonicalCityCode(
                null,
                (string) ($destination->province ?? ''),
                (string) ($destination->city_municipality ?? '')
            );
        } catch (\Illuminate\Validation\ValidationException) {
            return null;
        }
    }

    private function singleActiveSortingCenterForUpdate(
        LogisticsProfile $profile
    ): ?SortingCenter {
        $centers = SortingCenter::query()
            ->where('logistics_profile_id', $profile->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->limit(2)
            ->get();

        if ($centers->count() !== 1 || $centers->first()->status !== 'active') {
            return null;
        }

        return $centers->first();
    }
}
