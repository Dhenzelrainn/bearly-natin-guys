<?php

namespace App\Services;

use App\Events\RiderLocationUpdated;
use App\Models\RiderLocation;
use App\Models\RiderProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;

class RiderLocationService
{
    /** Location older than this is not used for proximity recommendations. */
    public const FRESHNESS_SECONDS = 180;

    /**
     * GPS fixes with wider uncertainty are shown as unavailable for proximity.
     * This keeps neighborhood-scale distance recommendations from using a
     * position whose reported uncertainty is itself larger than the trip.
     */
    public const MAX_USABLE_ACCURACY_METERS = 100;

    public function record(
        RiderProfile $rider,
        float $latitude,
        float $longitude,
        ?float $accuracyMeters = null,
    ): RiderLocation {
        $now = now();

        RiderLocation::query()->upsert(
            [[
                'rider_profile_id' => $rider->id,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy_meters' => $accuracyMeters,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['rider_profile_id'],
            [
                'latitude',
                'longitude',
                'accuracy_meters',
                'last_seen_at',
                'updated_at',
            ],
        );

        $location = RiderLocation::query()
            ->where('rider_profile_id', $rider->id)
            ->firstOrFail();

        $this->broadcastLocationChanged(
            $rider,
            false,
            $location->last_seen_at?->toIso8601String() ?? now()->toIso8601String(),
        );

        return $location;
    }

    public function clear(RiderProfile $rider): void
    {
        $deleted = RiderLocation::query()
            ->where('rider_profile_id', $rider->id)
            ->delete();

        if ($deleted > 0) {
            $this->broadcastLocationChanged($rider, true, now()->toIso8601String());
        }
    }

    public function isFresh(
        ?RiderLocation $location,
        ?Carbon $now = null,
    ): bool {
        if (! $location?->last_seen_at) {
            return false;
        }

        return $location->last_seen_at->greaterThanOrEqualTo(
            ($now ?? now())->copy()->subSeconds(self::FRESHNESS_SECONDS)
        );
    }

    public function isUsableForProximity(
        ?RiderLocation $location,
        ?Carbon $now = null,
    ): bool {
        return $this->isFresh($location, $now)
            && $this->accuracyState($location) === 'usable'
            && $this->hasValidCoordinatePair($location?->latitude, $location?->longitude);
    }

    public function hasValidCoordinatePair(mixed $latitude, mixed $longitude): bool
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return false;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        return is_finite($latitude)
            && is_finite($longitude)
            && $latitude >= -90
            && $latitude <= 90
            && $longitude >= -180
            && $longitude <= 180;
    }

    public function distanceKm(
        mixed $fromLatitude,
        mixed $fromLongitude,
        mixed $toLatitude,
        mixed $toLongitude,
    ): ?float {
        if (! $this->hasValidCoordinatePair($fromLatitude, $fromLongitude)
            || ! $this->hasValidCoordinatePair($toLatitude, $toLongitude)) {
            return null;
        }

        $fromLatitude = (float) $fromLatitude;
        $fromLongitude = (float) $fromLongitude;
        $toLatitude = (float) $toLatitude;
        $toLongitude = (float) $toLongitude;

        $latitudeDelta = deg2rad($toLatitude - $fromLatitude);
        $longitudeDelta = deg2rad($toLongitude - $fromLongitude);
        $fromLatitudeRadians = deg2rad($fromLatitude);
        $toLatitudeRadians = deg2rad($toLatitude);
        $a = sin($latitudeDelta / 2) ** 2
            + cos($fromLatitudeRadians)
                * cos($toLatitudeRadians)
                * sin($longitudeDelta / 2) ** 2;
        $a = max(0.0, min(1.0, $a));

        return 6371.0088 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function accuracyState(?RiderLocation $location): string
    {
        $accuracy = $location?->accuracy_meters;

        if ($accuracy === null || ! is_numeric($accuracy)) {
            return 'unknown';
        }

        $accuracy = (float) $accuracy;

        return is_finite($accuracy)
            && $accuracy >= 0
            && $accuracy <= self::MAX_USABLE_ACCURACY_METERS
            ? 'usable'
            : 'low_accuracy';
    }

    public function freshnessState(
        ?RiderLocation $location,
        ?Carbon $now = null,
    ): string {
        if (! $location) {
            return 'missing';
        }

        return $this->isFresh($location, $now) ? 'fresh' : 'stale';
    }

    private function broadcastLocationChanged(
        RiderProfile $rider,
        bool $locationCleared,
        string $updatedAt,
    ): void {
        $logisticsUserId = (int) $rider->logisticsProfile()->value('user_id');

        if ($logisticsUserId < 1) {
            return;
        }

        $event = new RiderLocationUpdated(
            logisticsUserId: $logisticsUserId,
            riderUserId: (int) $rider->user_id,
            locationCleared: $locationCleared,
            updatedAt: $updatedAt,
        );

        DB::afterCommit(function () use ($event): void {
            try {
                Event::dispatch($event);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
