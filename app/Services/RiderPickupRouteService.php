<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\RiderWorkType;
use App\Models\PickupAssignment;
use App\Models\PickupRequest;
use App\Models\RiderLocation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class RiderPickupRouteService
{
    private const ROUTING_ENDPOINT = 'https://api.geoapify.com/v1/routing';

    public function __construct(
        private readonly RiderLocationService $locations,
    ) {}

    /** @return array<string, mixed> */
    public function routeForAcceptedPickup(PickupAssignment $assignment): array
    {
        $rider = $assignment->riderProfile;
        $pickup = $assignment->pickupRequest;

        if (! in_array($assignment->status, ['accepted', 'arrived'], true)) {
            throw new ConflictHttpException('Accept this pickup assignment before viewing its road route.');
        }

        if (! $rider || ! $pickup
            || (int) $assignment->rider_profile_id !== (int) $rider->id
            || (int) $rider->logistics_profile_id !== (int) $pickup->logistics_profile_id
            || $pickup->cancelled_at !== null
            || in_array($pickup->status, ['cancelled', 'completed'], true)
            || $rider->verification_status !== 'approved'
            || ! RiderWorkType::isLocal($rider->work_type)
            || $rider->availability_status !== 'available'
            || $rider->user?->status !== AccountStatus::Active->value) {
            throw new ConflictHttpException('This Rider or pickup is no longer eligible for a live road route.');
        }

        /** @var RiderLocation|null $location */
        $location = $rider->liveLocation;

        if (! $this->locations->isUsableForProximity($location)) {
            throw new ConflictHttpException(
                'Share a fresh Rider location with accuracy of ±100 m or better to calculate the road route.'
            );
        }

        $destination = $this->pickupCoordinates($pickup);

        if (! $this->locations->hasValidCoordinatePair($destination['latitude'], $destination['longitude'])) {
            throw new ConflictHttpException(
                'A saved pickup map pin is required. The approximate address map cannot be used for a road route.'
            );
        }

        $apiKey = config('maps.geoapify_api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new ServiceUnavailableHttpException(
                null,
                'Road routing is not configured. The pickup location is still available on the map.'
            );
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(8)
                ->withOptions(['allow_redirects' => false])
                ->get(self::ROUTING_ENDPOINT, [
                    'waypoints' => sprintf(
                        '%.7F,%.7F|%.7F,%.7F',
                        (float) $location->latitude,
                        (float) $location->longitude,
                        (float) $destination['latitude'],
                        (float) $destination['longitude'],
                    ),
                    'mode' => $this->routingMode($rider->vehicle_type),
                    'format' => 'geojson',
                    'apiKey' => trim($apiKey),
                ]);
        } catch (ConnectionException) {
            throw new HttpException(502, 'The road-routing service is temporarily unavailable.');
        }

        if (! $response->successful()) {
            throw new HttpException(502, 'The road-routing service could not calculate a route right now.');
        }

        $geometry = $this->validatedGeometry($response->json('features.0.geometry'));

        if ($geometry === null) {
            throw new HttpException(502, 'The road-routing service returned no usable route.');
        }

        // Re-check server-owned state after the external call: the pickup may
        // have been confirmed or sharing may have stopped while Geoapify ran.
        $currentAssignment = PickupAssignment::query()
            ->with([
                'riderProfile.liveLocation',
                'riderProfile.user',
                'pickupRequest.pickupAddress' => fn ($query) => $query->withTrashed(),
            ])
            ->find($assignment->id);
        $currentRider = $currentAssignment?->riderProfile;
        $currentPickup = $currentAssignment?->pickupRequest;

        if (! $currentAssignment
            || ! in_array($currentAssignment->status, ['accepted', 'arrived'], true)
            || (int) $currentAssignment->rider_profile_id !== (int) $rider->id
            || ! $currentRider
            || ! $currentPickup
            || (int) $currentRider->logistics_profile_id !== (int) $currentPickup->logistics_profile_id
            || $currentPickup->cancelled_at !== null
            || in_array($currentPickup->status, ['cancelled', 'completed'], true)
            || $currentRider->verification_status !== 'approved'
            || ! RiderWorkType::isLocal($currentRider->work_type)
            || $currentRider->availability_status !== 'available'
            || $currentRider->user?->status !== AccountStatus::Active->value) {
            throw new ConflictHttpException('The pickup assignment changed while calculating the road route.');
        }

        if (! $this->locations->isUsableForProximity($currentRider->liveLocation, now())) {
            throw new ConflictHttpException('Rider sharing stopped or the live GPS fix became stale while calculating the route.');
        }

        $currentDestination = $this->pickupCoordinates($currentPickup);

        if (! $this->locations->hasValidCoordinatePair($currentDestination['latitude'], $currentDestination['longitude'])
            || (float) $currentDestination['latitude'] !== (float) $destination['latitude']
            || (float) $currentDestination['longitude'] !== (float) $destination['longitude']) {
            throw new ConflictHttpException('The saved pickup pin changed while calculating the road route.');
        }

        $generatedAt = now();

        return [
            'route' => [
                'type' => 'Feature',
                'geometry' => $geometry,
                'properties' => [],
            ],
            'rider' => [
                'latitude' => (float) $location->latitude,
                'longitude' => (float) $location->longitude,
                'accuracy_meters' => (float) $location->accuracy_meters,
                'last_seen_at' => $location->last_seen_at?->toIso8601String(),
            ],
            'pickup' => [
                'latitude' => (float) $destination['latitude'],
                'longitude' => (float) $destination['longitude'],
            ],
            'generated_at' => $generatedAt->toIso8601String(),
            'stale_after_seconds' => RiderLocationService::FRESHNESS_SECONDS,
            'max_usable_accuracy_meters' => RiderLocationService::MAX_USABLE_ACCURACY_METERS,
        ];
    }

    /** @return array{latitude: mixed, longitude: mixed} */
    private function pickupCoordinates(PickupRequest $pickup): array
    {
        $snapshot = (array) ($pickup->pickup_address_snapshot ?? []);
        $isReturnPickup = $pickup->return_request_id !== null && $snapshot !== [];
        $address = $isReturnPickup ? null : $pickup->pickupAddress;

        return [
            'latitude' => $isReturnPickup ? ($snapshot['latitude'] ?? null) : $address?->latitude,
            'longitude' => $isReturnPickup ? ($snapshot['longitude'] ?? null) : $address?->longitude,
        ];
    }

    private function routingMode(?string $vehicleType): string
    {
        return Str::lower(trim((string) $vehicleType)) === 'e-bike'
            ? 'bicycle'
            : 'motorcycle';
    }

    /** @return array{type: string, coordinates: array<mixed>}|null */
    private function validatedGeometry(mixed $geometry): ?array
    {
        if (! is_array($geometry)) {
            return null;
        }

        $type = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;

        if (! in_array($type, ['LineString', 'MultiLineString'], true) || ! is_array($coordinates)) {
            return null;
        }

        $lines = $type === 'LineString' ? [$coordinates] : $coordinates;

        if ($lines === [] || count($lines) > 100) {
            return null;
        }

        $pointCount = 0;

        foreach ($lines as $line) {
            if (! is_array($line) || count($line) < 2) {
                return null;
            }

            foreach ($line as $point) {
                if (! is_array($point) || count($point) < 2
                    || ! is_numeric($point[0] ?? null)
                    || ! is_numeric($point[1] ?? null)) {
                    return null;
                }

                $longitude = (float) $point[0];
                $latitude = (float) $point[1];

                if (! is_finite($longitude) || ! is_finite($latitude)
                    || $longitude < -180 || $longitude > 180
                    || $latitude < -90 || $latitude > 90) {
                    return null;
                }

                $pointCount++;

                if ($pointCount > 20_000) {
                    return null;
                }
            }
        }

        return [
            'type' => $type,
            'coordinates' => $coordinates,
        ];
    }
}
