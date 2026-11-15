<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\RiderWorkType;
use App\Enums\UserRole;
use App\Models\LogisticsProfile;
use App\Models\RefundCashPayout;
use App\Models\RiderProfile;
use App\Models\SortingCenter;
use App\Models\SortingZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RefundCashRiderRecommendationService
{
    /** @var list<string> */
    private const ACTIVE_CASH_ASSIGNMENT_STATUSES = [
        'assigned_to_rider',
        'cash_with_rider',
        'out_for_delivery',
        'cash_return_pending',
        'logistics_return_verification',
        'delivered_pending_verification',
    ];

    public function __construct(
        private readonly SortingZoneMatcher $zoneMatcher,
        private readonly RiderLocationService $locations,
        private readonly RiderAssignmentEligibilityService $eligibility,
        private readonly RiderWorkloadService $workload,
    ) {}

    /**
     * Recommend eligible Local delivery Riders for a refund to the immutable
     * Buyer address. Cross-zone candidates are returned only when no eligible
     * Rider is available in the matching destination zone, and are constrained
     * to that zone's active Sorting Center and Logistics provider.
     *
     * @return array{
     *     status: string,
     *     mode: string|null,
     *     destination_zone: SortingZone|null,
     *     sorting_center: SortingCenter|null,
     *     candidates: Collection<int, array{
     *         rider: RiderProfile,
     *         mode: string,
     *         active_jobs: int,
     *         distance_label: string|null,
     *         recommendation_label: string|null
     *     }>,
     *     message: string,
     *     guidance: string|null
     * }
     */
    public function recommend(
        RefundCashPayout $payout,
        LogisticsProfile $provider,
        ?Carbon $now = null,
    ): array {
        $now ??= now();
        $payout->loadMissing('refund.order');
        $order = $payout->refund?->order;

        if (! $order) {
            return $this->emptyResult(
                'destination_unavailable',
                'The Buyer order destination is unavailable, so no Rider can be safely recommended.',
            );
        }

        $destination = [
            'province' => (string) $order->province,
            'city_municipality' => (string) $order->city_municipality,
            'barangay' => (string) $order->barangay,
            'psgc_city_code' => (string) ($order->psgc_city_code ?? ''),
        ];

        $destinationZone = SortingZone::query()
            ->where('status', 'active')
            ->whereHas('sortingCenter', fn ($query) => $query
                ->where('logistics_profile_id', $provider->id)
                ->where('status', 'active'))
            ->with('sortingCenter')
            ->orderBy('sorting_center_id')
            ->orderBy('id')
            ->get()
            ->first(fn (SortingZone $zone): bool => $this->zoneMatcher->coversDestination(
                $zone->destination_rules,
                $destination,
            ));

        if (! $destinationZone || ! $destinationZone->sortingCenter) {
            return $this->emptyResult(
                'destination_zone_missing',
                'No active Sorting Zone at this Logistics provider covers the Buyer’s saved order destination. Configure the coverage before assigning refund cash.',
            );
        }

        $center = $destinationZone->sortingCenter;
        $activeCashRiderIds = RefundCashPayout::query()
            ->whereKeyNot($payout->id)
            ->whereNotNull('rider_profile_id')
            ->whereIn('status', self::ACTIVE_CASH_ASSIGNMENT_STATUSES)
            ->pluck('rider_profile_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $riders = RiderProfile::query()
            ->where('logistics_profile_id', $provider->id)
            ->where('home_sorting_center_id', $center->id)
            ->where('verification_status', 'approved')
            ->where('availability_status', 'available')
            ->whereHas('user', fn ($query) => $query
                ->where('role', UserRole::Rider->value)
                ->where('status', AccountStatus::Active->value))
            ->whereHas('currentZone', fn ($query) => $query
                ->where('status', 'active')
                ->where('sorting_center_id', $center->id)
                ->whereHas('sortingCenter', fn ($centerQuery) => $centerQuery
                    ->where('logistics_profile_id', $provider->id)
                    ->where('status', 'active')))
            ->with(['user', 'currentZone.sortingCenter', 'liveLocation'])
            ->orderBy('id')
            ->get()
            ->filter(function (RiderProfile $rider) use ($activeCashRiderIds): bool {
                if (in_array((int) $rider->id, $activeCashRiderIds, true)
                    || ! RiderWorkType::isLocal($rider->work_type)
                    || ! $rider->allowsDeliveryWork()) {
                    return false;
                }

                try {
                    $this->eligibility->assertDeliveryEligible($rider);
                } catch (ConflictHttpException) {
                    return false;
                }

                return true;
            })
            ->values();

        $inZoneRiders = $riders
            ->filter(fn (RiderProfile $rider): bool => (int) $rider->current_zone_id === (int) $destinationZone->id)
            ->values();
        $mode = $inZoneRiders->isNotEmpty() ? 'zone_match' : 'nearby_zone';
        $candidates = $mode === 'zone_match'
            ? $inZoneRiders
            : $riders
                ->reject(fn (RiderProfile $rider): bool => (int) $rider->current_zone_id === (int) $destinationZone->id)
                ->values();

        if ($candidates->isEmpty()) {
            return [
                'status' => 'no_eligible_rider',
                'mode' => null,
                'destination_zone' => $destinationZone,
                'sorting_center' => $center,
                'candidates' => collect(),
                'message' => 'No eligible Local delivery Rider is available in the Buyer zone or another active zone at this Sorting Center. The payout remains awaiting assignment.',
                'guidance' => null,
            ];
        }

        $ranked = $this->rankCandidates(
            $candidates,
            $mode,
            $order->delivery_latitude,
            $order->delivery_longitude,
            $now,
        );

        $message = $mode === 'zone_match'
            ? "Eligible Riders assigned to the Buyer destination zone {$destinationZone->name} are shown first. Nearby-zone Riders stay hidden while an in-zone Rider is eligible."
            : "No eligible Rider is available in {$destinationZone->name}. Only eligible Riders from other active zones at {$center->name}, under this provider, are offered as fallback; a reason is required.";

        return [
            'status' => $mode,
            'mode' => $mode,
            'destination_zone' => $destinationZone,
            'sorting_center' => $center,
            'candidates' => $ranked,
            'message' => $message,
            'guidance' => $this->guidanceFor(
                $ranked,
                $order->delivery_latitude,
                $order->delivery_longitude,
                $now,
            ),
        ];
    }

    /**
     * @param  Collection<int, RiderProfile>  $riders
     * @return Collection<int, array{
     *     rider: RiderProfile,
     *     mode: string,
     *     active_jobs: int,
     *     distance_label: string|null,
     *     recommendation_label: string|null
     * }>
     */
    private function rankCandidates(
        Collection $riders,
        string $mode,
        mixed $destinationLatitude,
        mixed $destinationLongitude,
        Carbon $now,
    ): Collection {
        $workload = $this->workload->metricsForRiders($riders, $now);
        $destinationPinUsable = $this->locations->hasValidCoordinatePair(
            $destinationLatitude,
            $destinationLongitude,
        );
        $gpsComplete = $destinationPinUsable && $riders->every(
            fn (RiderProfile $rider): bool => $this->locations->isUsableForProximity(
                $rider->liveLocation,
                $now,
            ),
        );

        $ranked = $riders->map(function (RiderProfile $rider) use (
            $mode,
            $destinationLatitude,
            $destinationLongitude,
            $gpsComplete,
            $workload,
        ): array {
            $metrics = $workload->get((int) $rider->id, [
                'active_jobs' => 0,
                'completed_today' => 0,
            ]);
            $distanceKm = $gpsComplete
                ? $this->locations->distanceKm(
                    $destinationLatitude,
                    $destinationLongitude,
                    $rider->liveLocation?->latitude,
                    $rider->liveLocation?->longitude,
                )
                : null;

            return [
                'rider' => $rider,
                'mode' => $mode,
                'active_jobs' => (int) ($metrics['active_jobs'] ?? 0),
                'completed_today' => (int) ($metrics['completed_today'] ?? 0),
                'distance_km' => $distanceKm,
                'accuracy_meters' => is_numeric($rider->liveLocation?->accuracy_meters)
                    ? (float) $rider->liveLocation->accuracy_meters
                    : null,
            ];
        });

        $workloadSort = fn (array $left, array $right): int => ($left['active_jobs'] <=> $right['active_jobs'])
            ?: ($left['completed_today'] <=> $right['completed_today'])
            ?: (strtolower((string) $left['rider']->user?->name) <=> strtolower((string) $right['rider']->user?->name))
            ?: ((int) $left['rider']->id <=> (int) $right['rider']->id);

        $nearestId = null;
        if ($gpsComplete && $ranked->count() > 1) {
            $byDistance = $ranked->sort(function (array $left, array $right) use ($workloadSort): int {
                return ((float) $left['distance_km'] <=> (float) $right['distance_km'])
                    ?: $workloadSort($left, $right);
            })->values();
            $nearest = $byDistance->first();
            $runnerUp = $byDistance->get(1);
            $separationKm = (float) $runnerUp['distance_km'] - (float) $nearest['distance_km'];
            $accuracyMarginKm = ((float) $nearest['accuracy_meters'] + (float) $runnerUp['accuracy_meters']) / 1000;

            if ($separationKm > $accuracyMarginKm) {
                $nearestId = (int) $nearest['rider']->id;
                $ranked = $byDistance;
            }
        }

        if ($nearestId === null) {
            $ranked = $ranked->sort($workloadSort)->values();
        }

        $workloadPreferredId = null;
        if ($ranked->count() > 1) {
            $lowestWorkloads = $ranked->sort($workloadSort)->values();
            $first = $lowestWorkloads->first();
            $secondLowestActiveJobs = $lowestWorkloads->get(1)['active_jobs'];

            if ((int) $first['active_jobs'] < (int) $secondLowestActiveJobs) {
                $workloadPreferredId = (int) $first['rider']->id;
            }
        }

        return $ranked->map(function (array $entry) use ($nearestId, $workloadPreferredId): array {
            $distance = $entry['distance_km'];

            return [
                'rider' => $entry['rider'],
                'mode' => $entry['mode'],
                'active_jobs' => $entry['active_jobs'],
                'distance_label' => $distance === null ? null : $this->distanceLabel((float) $distance),
                'recommendation_label' => (int) $entry['rider']->id === $nearestId
                    ? 'Closest fresh GPS estimate'
                    : ((int) $entry['rider']->id === $workloadPreferredId
                        ? 'Lowest active workload'
                        : null),
            ];
        })->values();
    }

    /**
     * @param  Collection<int, array{rider: RiderProfile, mode: string, active_jobs: int, distance_label: string|null, recommendation_label: string|null}>  $candidates
     */
    private function guidanceFor(
        Collection $candidates,
        mixed $destinationLatitude,
        mixed $destinationLongitude,
        Carbon $now,
    ): string {
        if (! $this->locations->hasValidCoordinatePair($destinationLatitude, $destinationLongitude)) {
            return 'The Buyer order has no valid saved map pin. Candidates are ordered by active delivery workload; no Rider is described as nearest.';
        }

        $allLocationsUsable = $candidates->every(fn (array $candidate): bool => $this->locations->isUsableForProximity($candidate['rider']->liveLocation, $now)
        );

        if (! $allLocationsUsable) {
            return 'Fresh accurate GPS is not available for every eligible Rider. Candidates are ordered by active delivery workload; no Rider is described as nearest.';
        }

        if ($candidates->count() < 2) {
            return 'The shown direct-distance estimate uses fresh Rider GPS (updated within 3 minutes, accuracy up to 100 m). It is not road distance or an ETA; there is no second Rider for a nearest comparison.';
        }

        if ($candidates->contains(fn (array $candidate): bool => $candidate['recommendation_label'] === 'Closest fresh GPS estimate')) {
            return 'The closest estimate uses Rider GPS updated within 3 minutes with accuracy up to 100 m. Distance is straight-line, not road distance or an ETA.';
        }

        return 'Fresh GPS distance estimates overlap within their reported accuracy. No Rider is marked nearest; candidates are ordered by active delivery workload.';
    }

    private function distanceLabel(float $distanceKm): string
    {
        return $distanceKm < 1
            ? 'Approx. '.(int) round($distanceKm * 1000).' m direct'
            : 'Approx. '.number_format($distanceKm, 2).' km direct';
    }

    /** @return array{status: string, mode: null, destination_zone: null, sorting_center: null, candidates: Collection<int, array{}>, message: string, guidance: null} */
    private function emptyResult(string $status, string $message): array
    {
        return [
            'status' => $status,
            'mode' => null,
            'destination_zone' => null,
            'sorting_center' => null,
            'candidates' => collect(),
            'message' => $message,
            'guidance' => null,
        ];
    }
}
