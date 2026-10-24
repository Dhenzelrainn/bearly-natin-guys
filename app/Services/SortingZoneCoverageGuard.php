<?php

namespace App\Services;

use App\Enums\ParcelStatus;
use App\Models\LogisticsProfile;
use App\Models\Parcel;
use App\Models\ShipmentTransfer;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SortingZoneCoverageGuard
{
    public function __construct(
        private readonly SortingZoneMatcher $zoneMatcher,
    ) {}

    /**
     * Ensure every parcel in a handoff has a usable active zone at its center.
     * The caller locks the owning Logistics profile and center before this check.
     *
     * @param  iterable<Parcel>  $parcels
     */
    public function assertParcelsCovered(
        SortingCenter $center,
        iterable $parcels,
        string $message,
    ): void {
        $zones = $this->activeZones($center, lock: true);

        foreach ($parcels as $parcel) {
            if (! $this->coveredBy($zones, $parcel)) {
                throw new ConflictHttpException($message);
            }
        }
    }

    /** @param  iterable<Parcel>  $parcels */
    public function coversAllParcels(
        SortingCenter $center,
        iterable $parcels,
    ): bool {
        $zones = $this->activeZones($center, lock: false);

        foreach ($parcels as $parcel) {
            if (! $this->coveredBy($zones, $parcel)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reject a zone removal or coverage edit only when it removes the last
     * currently usable match for an in-progress parcel or incoming transfer.
     *
     * @param  array<string, mixed>|null  $replacementCoverage
     */
    public function assertZoneChangePreservesCoverage(
        LogisticsProfile $profile,
        SortingCenter $center,
        SortingZone $zone,
        bool $willBeActive,
        ?array $replacementCoverage,
        string $errorKey = 'status',
    ): void {
        $activeZones = $this->activeZones($center, lock: true);
        $remainingZones = $activeZones
            ->reject(fn (SortingZone $activeZone): bool => (int) $activeZone->id === (int) $zone->id)
            ->values();

        if ($willBeActive) {
            $replacement = clone $zone;
            $replacement->setRelation('sortingCenter', $center);
            $replacement->setAttribute('status', 'active');
            $replacement->setAttribute(
                'destination_rules',
                $replacementCoverage ?? $zone->destination_rules
            );
            $remainingZones->push($replacement);
        }

        foreach ($this->requiredParcels($profile, $center) as $parcel) {
            if (
                $this->coveredBy($activeZones, $parcel)
                && ! $this->coveredBy($remainingZones, $parcel)
            ) {
                throw ValidationException::withMessages([
                    $errorKey => 'This change would remove the last active zone covering an in-progress shipment. Keep or add matching destination coverage before changing this zone.',
                ]);
            }
        }
    }

    /**
     * Keep an archived zone when its saved rules are the only existing
     * configuration that can repair an in-progress parcel's missing coverage.
     */
    public function assertArchivedZoneCanBePurged(
        LogisticsProfile $profile,
        SortingCenter $center,
        SortingZone $zone,
    ): void {
        $activeZones = $this->activeZones($center, lock: true);
        $requiredParcels = $this->requiredParcels($profile, $center);

        foreach ($requiredParcels as $parcel) {
            if (
                ! $this->coveredBy($activeZones, $parcel)
                && $this->zoneMatcher->matches($zone, $parcel)
            ) {
                throw ValidationException::withMessages([
                    'status' => 'This archived zone contains the only saved coverage matching an in-progress shipment. Restore or replace its coverage before permanently deleting it.',
                ]);
            }
        }
    }

    /** @return Collection<int, SortingZone> */
    private function activeZones(SortingCenter $center, bool $lock): Collection
    {
        $query = SortingZone::query()
            ->with('sortingCenter.logisticsProfile')
            ->where('sorting_center_id', $center->id)
            ->where('status', 'active')
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /** @param  Collection<int, SortingZone>  $zones */
    private function coveredBy(Collection $zones, Parcel $parcel): bool
    {
        return $zones->contains(
            fn (SortingZone $zone): bool => $this->zoneMatcher->matches($zone, $parcel)
        );
    }

    /**
     * Received parcels at this center are awaiting sorting. Parcels on sent
     * transfers are checked against the receiving center before the transfer
     * can be accepted.
     *
     * @return Collection<int, Parcel>
     */
    private function requiredParcels(
        LogisticsProfile $profile,
        SortingCenter $center,
    ): Collection {
        $destinationRelations = [
            'shipment.destinationAddress',
            'shipment.sellerOrder.order',
        ];

        $parcels = Parcel::query()
            ->with($destinationRelations)
            ->where('current_sorting_center_id', $center->id)
            ->where('status', ParcelStatus::Received->value)
            ->get()
            ->keyBy('id');

        $sentShipmentIds = ShipmentTransfer::query()
            ->where('to_logistics_profile_id', $profile->id)
            ->where('status', 'sent')
            ->pluck('shipment_id');

        if ($sentShipmentIds->isNotEmpty()) {
            $incomingParcels = Parcel::query()
                ->with($destinationRelations)
                ->whereIn('shipment_id', $sentShipmentIds)
                ->where('status', ParcelStatus::InTransit->value)
                ->get();

            foreach ($incomingParcels as $parcel) {
                $parcels->put($parcel->id, $parcel);
            }
        }

        return $parcels->values();
    }
}
