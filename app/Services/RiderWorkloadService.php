<?php

namespace App\Services;

use App\Enums\DeliveryAttemptOutcome;
use App\Enums\ParcelStatus;
use App\Models\RiderProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RiderWorkloadService
{
    private const TIMEZONE = 'Asia/Manila';

    /**
     * @param  Collection<int, RiderProfile>  $riders
     * @return Collection<int, array{active_jobs: int, completed_today: int}>
     */
    public function metricsForRiders(
        Collection $riders,
        ?Carbon $now = null,
    ): Collection {
        $riderIds = $riders
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($riderIds->isEmpty()) {
            return collect();
        }

        $activeJobs = $this->activeJobsByRider($riderIds);
        $completedToday = $this->completedTodayByRider(
            $riderIds,
            $now,
        );

        return $riderIds->mapWithKeys(
            fn (int $riderId): array => [
                $riderId => [
                    'active_jobs' => (int) ($activeJobs[$riderId] ?? 0),
                    'completed_today' => (int) ($completedToday[$riderId] ?? 0),
                ],
            ],
        );
    }

    /**
     * @return array{active_jobs: int, completed_today: int}
     */
    public function metricsFor(
        RiderProfile $rider,
        ?Carbon $now = null,
    ): array {
        return $this->metricsForRiders(
            collect([$rider]),
            $now,
        )->get($rider->id, [
            'active_jobs' => 0,
            'completed_today' => 0,
        ]);
    }

    /**
     * Active jobs follow the Rider delivery dashboard's shipment-level stop
     * semantics. A shipment remains active while any parcel attached to a
     * non-cancelled dispatch batch is not delivered. This counts a shipment
     * once even when it has multiple parcels or appears in multiple batches.
     *
     * @param  Collection<int, int>  $riderIds
     * @return Collection<int, int>
     */
    private function activeJobsByRider(Collection $riderIds): Collection
    {
        return DB::table('dispatch_batches as batches')
            ->join(
                'dispatch_batch_parcels as batch_parcels',
                'batch_parcels.dispatch_batch_id',
                '=',
                'batches.id',
            )
            ->join(
                'parcels',
                'parcels.id',
                '=',
                'batch_parcels.parcel_id',
            )
            ->whereIn('batches.rider_profile_id', $riderIds->all())
            ->where('batches.status', '!=', 'cancelled')
            ->where('parcels.status', '!=', ParcelStatus::Delivered->value)
            ->select('batches.rider_profile_id')
            ->selectRaw('COUNT(DISTINCT parcels.shipment_id) as workload_count')
            ->groupBy('batches.rider_profile_id')
            ->get()
            ->pluck('workload_count', 'rider_profile_id')
            ->map(fn ($count): int => (int) $count);
    }

    /**
     * Completed deliveries are outbound shipment stops, not parcel attempts.
     * Delivery attempts retain the Rider owner, so distinct shipment IDs keep
     * the metric provider- and Rider-scoped while avoiding multi-parcel
     * double-counting.
     *
     * @param  Collection<int, int>  $riderIds
     * @return Collection<int, int>
     */
    private function completedTodayByRider(
        Collection $riderIds,
        ?Carbon $now = null,
    ): Collection {
        [$start, $end] = $this->manilaDayBounds($now);

        return DB::table('delivery_attempts as attempts')
            ->join('parcels', 'parcels.id', '=', 'attempts.parcel_id')
            ->join('shipments', 'shipments.id', '=', 'parcels.shipment_id')
            ->whereIn('attempts.rider_profile_id', $riderIds->all())
            ->where('attempts.outcome', DeliveryAttemptOutcome::Delivered->value)
            ->where('shipments.purpose', 'outbound')
            ->where('attempts.attempted_at', '>=', $start)
            ->where('attempts.attempted_at', '<', $end)
            ->select('attempts.rider_profile_id')
            ->selectRaw('COUNT(DISTINCT parcels.shipment_id) as workload_count')
            ->groupBy('attempts.rider_profile_id')
            ->get()
            ->pluck('workload_count', 'rider_profile_id')
            ->map(fn ($count): int => (int) $count);
    }

    /**
     * Return UTC bounds for the current calendar day in the business timezone.
     * The application/database timezone is UTC, so binding Manila wall-clock
     * values directly would shift the reporting window.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function manilaDayBounds(?Carbon $now = null): array
    {
        $localNow = ($now ?? Carbon::now(self::TIMEZONE))
            ->copy()
            ->setTimezone(self::TIMEZONE);

        return [
            $localNow->copy()->startOfDay()->utc(),
            $localNow->copy()->addDay()->startOfDay()->utc(),
        ];
    }
}
