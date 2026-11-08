<?php

namespace App\Services;

use App\Enums\ParcelStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Models\LinehaulRun;
use App\Models\LinehaulRunShipment;
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
    ) {}

    public function hasSingleActiveSortingCenter(LogisticsProfile $profile): bool
    {
        $centers = $profile->sortingCenters()
            ->orderBy('id')
            ->limit(2)
            ->get();

        return $centers->count() === 1
            && $centers->first()->status === 'active';
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
        throw new ConflictHttpException(
            'City handoffs can be sent only through an assigned Courier manifest after cargo is loaded and departed.'
        );
    }

    public function notifyCourierDeparture(ShipmentTransfer $transfer): void
    {
        $transfer->loadMissing(['fromLogisticsProfile', 'toLogisticsProfile.user']);
        $this->notifyDestinationOfHandoff(
            $transfer,
            $transfer->fromLogisticsProfile,
            $transfer->toLogisticsProfile?->user,
        );
    }

    /**
     * Resolve an incoming Waybill number or printed Parcel barcode for a
     * destination Logistics preview without exposing another provider's handoff.
     *
     * @return array{waybill_no: string, shipment_no: string, parcel_count: int, matched_by: string}
     */
    public function previewIncoming(string $identifier, User $actor): array
    {
        $identifier = trim($identifier);
        $destination = $actor->logisticsProfile;

        if (! $destination) {
            throw new AuthorizationException('Only destination Logistics can look up an incoming handoff.');
        }

        [$waybill, $matchedBy] = $this->waybillForIdentifier($identifier);
        $shipment = $waybill->shipment;

        $transfer = ShipmentTransfer::query()
            ->where('shipment_id', $shipment->id)
            ->where('to_logistics_profile_id', $destination->id)
            ->where('status', 'sent')
            ->firstOrFail();

        $runAssignment = LinehaulRunShipment::query()
            ->where('shipment_id', $shipment->id)
            ->first();

        if ($runAssignment) {
            $runStatus = LinehaulRun::query()
                ->whereKey($runAssignment->linehaul_run_id)
                ->value('status');

            if ($runStatus !== 'arrived') {
                throw new ConflictHttpException(
                    'Wait until the assigned Courier run is marked Arrived before accepting this Waybill.'
                );
            }
        }

        return [
            'waybill_no' => (string) $waybill->waybill_no,
            'shipment_no' => (string) $shipment->shipment_no,
            'parcel_count' => (int) $shipment->parcels()->count(),
            'matched_by' => $matchedBy,
        ];
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
            [$waybill, $matchedBy] = $this->waybillForIdentifier(trim($identifier));

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

            $runAssignment = LinehaulRunShipment::query()
                ->where('shipment_id', $shipment->id)
                ->lockForUpdate()
                ->first();
            $linehaulRun = $runAssignment
                ? LinehaulRun::query()->whereKey($runAssignment->linehaul_run_id)->lockForUpdate()->firstOrFail()
                : null;

            if ($linehaulRun && $linehaulRun->status !== 'arrived') {
                throw new ConflictHttpException(
                    'Destination Logistics can accept the transfer only after its assigned Courier records arrival.'
                );
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
                        'scanned_identifier_type' => $matchedBy,
                    ],
                    'occurred_at' => $now,
                ]);
            }

            if ($linehaulRun) {
                app(LinehaulRunService::class)->recordDestinationAcceptance($linehaulRun, $actor);
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
     * The printed Code 128 barcode identifies a Parcel, while the handoff
     * transaction applies to its parent Waybill and the entire shipment.
     *
     * @return array{0: Waybill, 1: 'waybill'|'parcel_barcode'}
     */
    private function waybillForIdentifier(string $identifier): array
    {
        $waybill = Waybill::query()
            ->matchingIdentifier($identifier)
            ->first();

        if ($waybill) {
            return [$waybill, 'waybill'];
        }

        $waybill = Waybill::query()
            ->whereHas('parcels', fn ($query) => $query->where('parcel_no', $identifier))
            ->first();

        if ($waybill) {
            return [$waybill, 'parcel_barcode'];
        }

        throw (new ModelNotFoundException)->setModel(Waybill::class, [$identifier]);
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
