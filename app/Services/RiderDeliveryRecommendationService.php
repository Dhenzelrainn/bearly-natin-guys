<?php

namespace App\Services;

use App\Models\Parcel;
use App\Models\RiderProfile;
use App\Models\Shipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RiderDeliveryRecommendationService
{
    public function __construct(private readonly RiderLocationService $locations) {}

    /**
     * Rank eligible overflow Riders against every distinct destination in a
     * ready batch. A “closest” recommendation is withheld unless all target
     * pins and all candidate Rider fixes are usable and the nearest fix is
     * clearly separated from the next candidate by their reported GPS error.
     *
     * @param  Collection<int, Parcel>  $parcels
     * @param  Collection<int, RiderProfile>  $riders
     * @return array{
     *     candidates: Collection<int, array<string, mixed>>,
     *     proximity_status: string,
     *     proximity_message: string
     * }
     */
    public function recommend(
        Collection $parcels,
        Collection $riders,
        ?Carbon $now = null,
    ): array {
        $now ??= now();
        $destinationPoints = $this->destinationPoints($parcels);
        $destinationPinsComplete = $destinationPoints !== null;

        $ranked = $riders
            ->map(function (RiderProfile $rider) use ($destinationPoints, $now): array {
                $location = $rider->liveLocation;
                $gpsFresh = $this->locations->isFresh($location, $now);
                $gpsAccuracyState = $this->locations->accuracyState($location);
                $gpsCoordinatesValid = $this->locations->hasValidCoordinatePair(
                    $location?->latitude,
                    $location?->longitude,
                );
                $gpsUsable = $this->locations->isUsableForProximity($location, $now);
                $distances = [];

                if ($destinationPoints !== null && $gpsUsable) {
                    foreach ($destinationPoints as $destination) {
                        $distance = $this->locations->distanceKm(
                            $location?->latitude,
                            $location?->longitude,
                            $destination['latitude'],
                            $destination['longitude'],
                        );

                        if ($distance === null) {
                            $distances = [];
                            break;
                        }

                        $distances[] = $distance;
                    }
                }

                $averageDistanceKm = $distances !== []
                    ? array_sum($distances) / count($distances)
                    : null;
                $activeLoad = (int) $rider->dispatchBatches
                    ->sum(fn ($batch): int => (int) ($batch->parcels_count ?? $batch->parcels->count()));
                $capacity = (int) ($rider->parcel_capacity ?? 0);

                return [
                    'id' => (int) $rider->id,
                    'name' => $rider->user?->name ?: 'Unknown Rider',
                    'zone_id' => (int) ($rider->current_zone_id ?? 0),
                    'zone_code' => (string) ($rider->currentZone?->code ?? 'Zone unavailable'),
                    'zone_name' => (string) ($rider->currentZone?->name ?? ''),
                    'specialization' => $rider->specializationValue()->label(),
                    'active_load' => $activeLoad,
                    'capacity' => $capacity,
                    'remaining' => max(0, $capacity - $activeLoad),
                    'gps_freshness' => $location === null
                        ? 'missing'
                        : ($gpsFresh ? 'fresh' : 'stale'),
                    'gps_accuracy_state' => $gpsAccuracyState,
                    'gps_coordinates_valid' => $gpsCoordinatesValid,
                    'gps_usable' => $gpsUsable,
                    'gps_accuracy_meters' => is_numeric($location?->accuracy_meters)
                        ? (float) $location->accuracy_meters
                        : null,
                    'gps_status_label' => $this->gpsStatusLabel(
                        $location !== null,
                        $gpsFresh,
                        $gpsAccuracyState,
                        $gpsCoordinatesValid,
                        $gpsUsable,
                        is_numeric($location?->accuracy_meters)
                            ? (float) $location->accuracy_meters
                            : null,
                    ),
                    'distance_km' => $averageDistanceKm,
                    'distance_label' => $averageDistanceKm === null
                        ? null
                        : $this->distanceLabel($averageDistanceKm),
                    'is_recommended' => false,
                ];
            })
            ->sort(function (array $left, array $right): int {
                if ($left['distance_km'] !== null && $right['distance_km'] !== null) {
                    return $left['distance_km'] <=> $right['distance_km']
                        ?: ($right['remaining'] <=> $left['remaining'])
                        ?: ($left['active_load'] <=> $right['active_load'])
                        ?: (strtolower($left['name']) <=> strtolower($right['name']))
                        ?: ($left['id'] <=> $right['id']);
                }

                if ($left['distance_km'] !== null || $right['distance_km'] !== null) {
                    return $left['distance_km'] === null ? 1 : -1;
                }

                return ($right['remaining'] <=> $left['remaining'])
                    ?: ($left['active_load'] <=> $right['active_load'])
                    ?: (strtolower($left['name']) <=> strtolower($right['name']))
                    ?: ($left['id'] <=> $right['id']);
            })
            ->values();

        [$status, $message, $recommendedId] = $this->proximityAssessment(
            $ranked,
            $destinationPinsComplete,
        );

        if ($recommendedId !== null) {
            $ranked = $ranked->map(function (array $rider) use ($recommendedId): array {
                $rider['is_recommended'] = $rider['id'] === $recommendedId;

                return $rider;
            });
        }

        return [
            'candidates' => $ranked,
            'proximity_status' => $status,
            'proximity_message' => $message,
        ];
    }

    /**
     * Every distinct destination in the batch must have a valid immutable
     * coordinate snapshot before any distance is calculated.
     *
     * @param  Collection<int, Parcel>  $parcels
     * @return list<array{latitude: float, longitude: float}>|null
     */
    private function destinationPoints(Collection $parcels): ?array
    {
        if ($parcels->isEmpty()) {
            return null;
        }

        $shipments = $parcels
            ->map(fn (Parcel $parcel) => $parcel->shipment)
            ->filter()
            ->unique('id')
            ->values();
        $sellerOrders = $shipments
            ->flatMap(fn (Shipment $shipment) => $shipment->includedSellerOrders())
            ->unique('id')
            ->values();
        $sellerOrders->loadMissing('order');
        $orders = $sellerOrders->map(fn ($sellerOrder) => $sellerOrder->order);

        if ($orders->contains(null)) {
            return null;
        }

        $orders = $orders->unique(fn ($order): int => (int) $order->id)->values();
        $pointsByCoordinate = [];

        foreach ($orders as $order) {
            $latitude = $order->delivery_latitude;
            $longitude = $order->delivery_longitude;

            if (! $this->locations->hasValidCoordinatePair($latitude, $longitude)) {
                return null;
            }

            $latitude = (float) $latitude;
            $longitude = (float) $longitude;
            $key = sprintf('%.7F:%.7F', $latitude, $longitude);
            $pointsByCoordinate[$key] = [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ];
        }

        return array_values($pointsByCoordinate) ?: null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $ranked
     * @return array{string, string, int|null}
     */
    private function proximityAssessment(Collection $ranked, bool $destinationPinsComplete): array
    {
        if ($ranked->isEmpty()) {
            return [
                'no_nearby_candidates',
                'No other eligible local Rider with capacity is assigned to an active zone at this Sorting Center.',
                null,
            ];
        }

        if (! $destinationPinsComplete) {
            return [
                'destination_pin_missing',
                'At least one delivery address has no saved map pin. Distances are hidden; select by zone and workload.',
                null,
            ];
        }

        if ($ranked->contains(fn (array $rider): bool => $rider['distance_km'] === null)) {
            return [
                'rider_gps_incomplete',
                'No nearest Rider is suggested because one or more candidates have missing, stale, inaccurate, or invalid GPS.',
                null,
            ];
        }

        $nearest = $ranked->first();
        $runnerUp = $ranked->get(1);

        if ($runnerUp === null) {
            return [
                'single_gps_candidate',
                'Only one candidate has usable GPS. The direct-distance estimate is approximate, not a road distance or ETA.',
                (int) $nearest['id'],
            ];
        }

        $nearestUncertaintyKm = ((float) ($nearest['gps_accuracy_meters'] ?? 0)) / 1000;
        $runnerUpUncertaintyKm = ((float) ($runnerUp['gps_accuracy_meters'] ?? 0)) / 1000;
        $separationKm = (float) $runnerUp['distance_km'] - (float) $nearest['distance_km'];
        $requiredSeparationKm = $nearestUncertaintyKm + $runnerUpUncertaintyKm;

        if ($separationKm <= $requiredSeparationKm) {
            return [
                'gps_estimate_tie',
                'GPS estimates overlap within their reported accuracy. No single Rider is marked nearest; Logistics should choose based on workload and route judgment.',
                null,
            ];
        }

        return [
            'nearest_estimate',
            'Closest usable GPS estimate to the batch destinations. Distances are approximate straight-line averages, not road mileage or ETA.',
            (int) $nearest['id'],
        ];
    }

    private function distanceLabel(float $distanceKm): string
    {
        $meters = (int) round($distanceKm * 1000);

        return $meters < 1000
            ? 'Approx. '.$meters.' m average direct'
            : 'Approx. '.number_format($distanceKm, 2).' km average direct';
    }

    private function gpsStatusLabel(
        bool $hasLocation,
        bool $fresh,
        string $accuracyState,
        bool $coordinatesValid,
        bool $usable,
        ?float $accuracyMeters,
    ): string {
        if (! $hasLocation) {
            return 'GPS missing';
        }

        if (! $fresh) {
            return 'GPS stale';
        }

        if (! $coordinatesValid) {
            return 'GPS coordinates invalid';
        }

        if ($accuracyState === 'low_accuracy') {
            return $accuracyMeters === null
                ? 'GPS accuracy unavailable'
                : 'GPS ±'.number_format($accuracyMeters, 0).' m (over 100 m limit)';
        }

        if (! $usable) {
            return 'GPS accuracy unavailable';
        }

        return 'GPS ±'.number_format($accuracyMeters ?? 0, 0).' m';
    }
}
