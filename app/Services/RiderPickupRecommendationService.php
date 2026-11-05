<?php

namespace App\Services;

use App\Models\PickupRequest;
use App\Models\RiderProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RiderPickupRecommendationService
{
    public function __construct(
        private readonly RiderLocationService $locations,
        private readonly SortingZoneMatcher $zoneMatcher,
        private readonly RiderWorkloadService $workload,
    ) {}

    /**
     * Rank provider-owned, already eligible Riders for a Seller pickup.
     *
     * Fresh GPS distance is used when both the pickup and at least one eligible
     * Rider have usable coordinates. If GPS is missing or stale, the existing
     * barangay/Sorting Zone assignment becomes the primary fallback, followed
     * by the existing shipment-level workload metric. This only recommends; it
     * never creates or moves an assignment.
     *
     * @param  Collection<int, RiderProfile>  $riders
     * @param  Collection<int, array{active_jobs: int, completed_today: int}>|null  $workloadMetrics
     * @return Collection<int, array<string, mixed>>
     */
    public function rank(
        PickupRequest $pickup,
        Collection $riders,
        ?Collection $workloadMetrics = null,
        ?Carbon $now = null,
    ): Collection {
        $now ??= now();
        $workloadMetrics ??= $this->workload->metricsForRiders($riders, $now);
        $target = $this->pickupTarget($pickup);
        $hasUsableGps = $this->locations->hasValidCoordinatePair(
            $target['latitude'],
            $target['longitude'],
        )
            && $riders->contains(
                fn (RiderProfile $rider): bool => $this->locations->isUsableForProximity(
                    $rider->liveLocation,
                    $now,
                )
            );

        $ranked = $riders
            ->map(function (RiderProfile $rider) use (
                $now,
                $target,
                $workloadMetrics,
            ): array {
                $workload = $workloadMetrics->get($rider->id, [
                    'active_jobs' => 0,
                    'completed_today' => 0,
                ]);
                $location = $rider->liveLocation;
                $locationState = $this->locations->freshnessState(
                    $location,
                    $now,
                );
                $gpsQuality = $this->locations->accuracyState($location);
                $locationUsable = $this->locations->isUsableForProximity(
                    $location,
                    $now,
                );
                $distanceKm = $locationUsable
                    ? $this->locations->distanceKm(
                        $target['latitude'],
                        $target['longitude'],
                        $location?->latitude,
                        $location?->longitude,
                    )
                    : null;
                $areaMatch = $this->areaMatches($rider, $target['destination']);

                return [
                    'id' => (int) $rider->id,
                    'name' => $rider->user?->name ?: 'Unknown Rider',
                    'vehicle' => $rider->vehicle_type,
                    'plate' => $rider->plate_number,
                    'location' => $rider->serviceLocationLabel(),
                    'active_jobs' => (int) ($workload['active_jobs'] ?? 0),
                    'completed_today' => (int) ($workload['completed_today'] ?? 0),
                    'area_match' => $areaMatch,
                    'location_state' => $locationState,
                    'gps_quality' => $gpsQuality,
                    'location_usable' => $locationUsable,
                    'latitude' => $locationUsable
                        ? $location?->latitude
                        : null,
                    'longitude' => $locationUsable
                        ? $location?->longitude
                        : null,
                    'last_seen_at' => $location?->last_seen_at?->toIso8601String(),
                    'accuracy_meters' => $location?->accuracy_meters,
                    'distance_km' => $distanceKm !== null
                        ? round($distanceKm, 2)
                        : null,
                    'distance_label' => $this->distanceLabel($distanceKm),
                ];
            })
            ->sort(function (array $left, array $right) use ($hasUsableGps): int {
                $leftKey = $this->sortKey($left, $hasUsableGps);
                $rightKey = $this->sortKey($right, $hasUsableGps);

                return $leftKey <=> $rightKey;
            })
            ->values();

        $recommendedId = $ranked->first()['id'] ?? null;

        return $ranked->map(function (array $rider) use ($recommendedId): array {
            $isRecommended = $recommendedId !== null
                && $rider['id'] === $recommendedId;

            $rider['is_recommended'] = $isRecommended;
            $rider['recommendation_reason'] = $isRecommended
                ? $this->recommendationReason($rider)
                : null;

            return $rider;
        });
    }

    /** @param array<string, mixed> $rider */
    private function sortKey(array $rider, bool $hasUsableGps): array
    {
        if ($hasUsableGps) {
            return [
                $rider['distance_km'] === null ? 1 : 0,
                $rider['distance_km'] ?? PHP_FLOAT_MAX,
                $rider['area_match'] ? 0 : 1,
                $rider['active_jobs'],
                $rider['completed_today'],
                strtolower((string) $rider['name']),
                $rider['id'],
            ];
        }

        return [
            $rider['area_match'] ? 0 : 1,
            $rider['active_jobs'],
            $rider['completed_today'],
            strtolower((string) $rider['name']),
            $rider['id'],
        ];
    }

    /**
     * @return array{
     *     latitude: float|null,
     *     longitude: float|null,
     *     destination: array{province: string, city_municipality: string, barangay: string, psgc_city_code: string}
     * }
     */
    private function pickupTarget(PickupRequest $pickup): array
    {
        $snapshot = (array) ($pickup->pickup_address_snapshot ?? []);
        $isReturnPickup = $pickup->return_request_id !== null && $snapshot !== [];
        $address = $isReturnPickup ? null : $pickup->pickupAddress;

        $latitude = $isReturnPickup ? ($snapshot['latitude'] ?? null) : $address?->latitude;
        $longitude = $isReturnPickup ? ($snapshot['longitude'] ?? null) : $address?->longitude;

        return [
            'latitude' => is_numeric($latitude) ? (float) $latitude : null,
            'longitude' => is_numeric($longitude) ? (float) $longitude : null,
            'destination' => [
                'province' => (string) ($isReturnPickup
                    ? ($snapshot['province'] ?? '')
                    : ($address?->province ?? '')),
                'city_municipality' => (string) ($isReturnPickup
                    ? ($snapshot['city_municipality'] ?? '')
                    : ($address?->city_municipality ?? '')),
                'barangay' => (string) ($isReturnPickup
                    ? ($snapshot['barangay'] ?? '')
                    : ($address?->barangay ?? '')),
                'psgc_city_code' => (string) ($isReturnPickup
                    ? ($snapshot['psgc_city_code'] ?? '')
                    : ($address?->psgc_city_code ?? '')),
            ],
        ];
    }

    /** @param array{province: string, city_municipality: string, barangay: string, psgc_city_code: string} $destination */
    private function areaMatches(RiderProfile $rider, array $destination): bool
    {
        return $rider->currentZone !== null
            && $this->zoneMatcher->coversDestination(
                $rider->currentZone->destination_rules,
                $destination,
            );
    }

    private function distanceLabel(?float $distanceKm): ?string
    {
        if ($distanceKm === null) {
            return null;
        }

        return $distanceKm < 1
            ? number_format($distanceKm * 1000, 0).' m away'
            : number_format($distanceKm, 1).' km away';
    }

    /** @param array<string, mixed> $rider */
    private function recommendationReason(array $rider): string
    {
        if ($rider['distance_label']) {
            return 'Recommended · '.$rider['distance_label'];
        }

        if ($rider['area_match']) {
            return 'Recommended · same service area';
        }

        return 'Recommended · lowest active workload';
    }
}
