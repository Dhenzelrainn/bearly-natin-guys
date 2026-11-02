<?php

namespace App\Services;

use App\Models\RiderLocation;
use App\Models\RiderProfile;
use Illuminate\Support\Carbon;

class RiderLocationService
{
    /** Location older than this is not used for proximity recommendations. */
    public const FRESHNESS_SECONDS = 180;

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

        return RiderLocation::query()
            ->where('rider_profile_id', $rider->id)
            ->firstOrFail();
    }

    public function clear(RiderProfile $rider): void
    {
        RiderLocation::query()
            ->where('rider_profile_id', $rider->id)
            ->delete();
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

    public function freshnessState(
        ?RiderLocation $location,
        ?Carbon $now = null,
    ): string {
        if (! $location) {
            return 'missing';
        }

        return $this->isFresh($location, $now) ? 'fresh' : 'stale';
    }
}
