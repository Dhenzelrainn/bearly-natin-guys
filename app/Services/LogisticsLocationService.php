<?php

namespace App\Services;

use App\Models\LogisticsLocation;
use App\Models\LogisticsProfile;
use Illuminate\Support\Carbon;

class LogisticsLocationService
{
    public const FRESHNESS_SECONDS = 180;

    public function record(
        LogisticsProfile $profile,
        float $latitude,
        float $longitude,
        ?float $accuracyMeters,
    ): LogisticsLocation {
        $now = now();

        LogisticsLocation::query()->upsert(
            [[
                'logistics_profile_id' => $profile->id,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy_meters' => $accuracyMeters,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['logistics_profile_id'],
            [
                'latitude',
                'longitude',
                'accuracy_meters',
                'last_seen_at',
                'updated_at',
            ],
        );

        return LogisticsLocation::query()
            ->where('logistics_profile_id', $profile->id)
            ->firstOrFail();
    }

    public function clear(LogisticsProfile $profile): void
    {
        LogisticsLocation::query()
            ->where('logistics_profile_id', $profile->id)
            ->delete();
    }

    public function freshLocation(
        LogisticsProfile $profile,
        ?Carbon $now = null,
    ): ?LogisticsLocation {
        $location = LogisticsLocation::query()
            ->where('logistics_profile_id', $profile->id)
            ->first();

        if (! $location || ! $this->hasValidCoordinatePair($location->latitude, $location->longitude)) {
            return null;
        }

        $currentTime = $now ?? now();

        if (! $location->last_seen_at
            || $location->last_seen_at->greaterThan($currentTime)
            || $location->last_seen_at->lessThan($currentTime->copy()->subSeconds(self::FRESHNESS_SECONDS))) {
            return null;
        }

        return $location;
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
}
