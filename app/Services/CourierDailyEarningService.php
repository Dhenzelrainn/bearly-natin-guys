<?php

namespace App\Services;

use App\Enums\RiderWorkType;
use App\Models\LinehaulRun;
use App\Models\RiderEarning;
use App\Models\RiderProfile;
use App\Models\RiderShift;
use App\Models\ShipmentTransfer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CourierDailyEarningService
{
    /**
     * Compensation is attributed to the Courier's local work date even when the
     * destination accepts the final manifest shipment on a later day.
     */
    public const TIMEZONE = RiderShift::TIMEZONE;

    /** @var list<string> */
    private const EXCLUDED_STATUSES = ['reversed', 'cancelled'];

    /**
     * Reconcile a closed attendance shift. Safe to call repeatedly; the
     * rider/type/work-date unique constraint remains the final duplicate guard.
     */
    public function reconcileShift(RiderShift $shift): ?RiderEarning
    {
        return DB::transaction(function () use ($shift): ?RiderEarning {
            $lockedShift = RiderShift::query()
                ->whereKey($shift->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedShift || $lockedShift->checked_out_at === null) {
                return null;
            }

            return $this->reconcileClosedShift($lockedShift);
        }, attempts: 3);
    }

    /**
     * Reconcile every closed shift whose attendance window contains the run's
     * assignment time. Called after destination Logistics completes the run.
     *
     * @return Collection<int, RiderEarning>
     */
    public function reconcileRun(LinehaulRun $run): Collection
    {
        return DB::transaction(function () use ($run): Collection {
            $lockedRun = LinehaulRun::query()
                ->whereKey($run->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedRun
                || $lockedRun->status !== 'completed'
                || $lockedRun->completed_at === null) {
                return collect();
            }

            $shifts = RiderShift::query()
                ->where('rider_profile_id', $lockedRun->courier_rider_profile_id)
                ->whereNotNull('checked_out_at')
                ->where('checked_in_at', '<=', $lockedRun->assigned_at)
                ->where('checked_out_at', '>=', $lockedRun->assigned_at)
                ->orderBy('checked_in_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            return $shifts
                ->map(fn (RiderShift $shift): ?RiderEarning => $this->reconcileClosedShift($shift))
                ->filter()
                ->unique('id')
                ->values();
        }, attempts: 3);
    }

    /**
     * @return array{
     *     rate_minor: int,
     *     rate: string,
     *     today_work_date: string,
     *     today: RiderEarning|null,
     *     qualifying_days: int,
     *     total_minor: int,
     *     total: string,
     *     eligible_minor: int,
     *     eligible: string,
     *     paid_minor: int,
     *     paid: string,
     * }
     */
    public function summaryFor(RiderProfile $rider): array
    {
        $base = RiderEarning::query()
            ->where('rider_profile_id', $rider->id)
            ->where('type', $this->earningType());

        $active = (clone $base)->whereNotIn('status', self::EXCLUDED_STATUSES);

        $totalMinor = (int) (clone $active)->sum('amount_minor');
        $eligibleMinor = (int) (clone $base)
            ->where('status', 'posted')
            ->whereNull('paid_at')
            ->sum('amount_minor');
        $paidMinor = (int) (clone $base)
            ->where('status', 'paid')
            ->sum('amount_minor');
        $todayWorkDate = now(self::TIMEZONE)->toDateString();

        return [
            'rate_minor' => $this->dailyRateMinor(),
            'rate' => $this->formatMinor($this->dailyRateMinor()),
            'today_work_date' => $todayWorkDate,
            'today' => (clone $base)->whereDate('work_date', $todayWorkDate)->first(),
            'qualifying_days' => (int) (clone $active)->count(),
            'total_minor' => $totalMinor,
            'total' => $this->formatMinor($totalMinor),
            'eligible_minor' => $eligibleMinor,
            'eligible' => $this->formatMinor($eligibleMinor),
            'paid_minor' => $paidMinor,
            'paid' => $this->formatMinor($paidMinor),
        ];
    }

    /** @return Collection<int, RiderEarning> */
    public function historyFor(RiderProfile $rider, int $limit = 10): Collection
    {
        return RiderEarning::query()
            ->where('rider_profile_id', $rider->id)
            ->where('type', $this->earningType())
            ->with([
                'linehaulRun.originLogisticsProfile',
                'linehaulRun.destinationLogisticsProfile',
                'shift',
                'payout',
                'payoutBatchItem.batch',
            ])
            ->orderByDesc('work_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function workDateFor(RiderShift $shift): string
    {
        return Carbon::parse($shift->checked_in_at)
            ->timezone(self::TIMEZONE)
            ->toDateString();
    }

    private function reconcileClosedShift(RiderShift $shift): ?RiderEarning
    {
        if ($shift->checked_out_at === null) {
            return null;
        }

        $rider = RiderProfile::query()
            ->whereKey($shift->rider_profile_id)
            ->first();

        if (! $rider || ! $this->isEligibleCourierProfile($rider)) {
            return null;
        }

        $run = $this->qualifyingRunFor($rider, $shift);

        if (! $run) {
            return null;
        }

        $workDate = $this->workDateFor($shift);
        $earnedAt = collect([$shift->checked_out_at, $run->completed_at])
            ->filter()
            ->map(fn ($value): Carbon => Carbon::parse($value))
            ->max() ?? now();

        $amountMinor = $this->dailyRateMinor();
        $now = now();

        // insertOrIgnore keeps concurrent reconciliation from failing an
        // acceptance/check-out transaction; the unique key is the final guard.
        DB::table('rider_earnings')->insertOrIgnore([
            'rider_profile_id' => (int) $rider->id,
            'pickup_assignment_id' => null,
            'delivery_attempt_id' => null,
            'shipping_settlement_id' => null,
            'shipment_id' => null,
            'logistics_profile_id' => (int) $run->origin_logistics_profile_id,
            'type' => $this->earningType(),
            'amount_minor' => $amountMinor,
            'status' => 'posted',
            'description' => "Courier daily pay for {$workDate} · {$run->run_no}",
            'earned_at' => $earnedAt,
            'posted_at' => $now,
            'paid_at' => null,
            'work_date' => $workDate,
            'rider_shift_id' => (int) $shift->id,
            'linehaul_run_id' => (int) $run->id,
            'size_class_snapshot' => null,
            'rate_minor' => $amountMinor,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return RiderEarning::query()
            ->where('rider_profile_id', $rider->id)
            ->where('type', $this->earningType())
            ->whereDate('work_date', $workDate)
            ->firstOrFail();
    }

    private function qualifyingRunFor(RiderProfile $rider, RiderShift $shift): ?LinehaulRun
    {
        return LinehaulRun::query()
            ->where('courier_rider_profile_id', $rider->id)
            ->where('origin_logistics_profile_id', $rider->logistics_profile_id)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->where('assigned_at', '>=', $shift->checked_in_at)
            ->where('assigned_at', '<=', $shift->checked_out_at)
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get()
            ->first(fn (LinehaulRun $run): bool => $this->runHasAcceptedManifest($run));
    }

    public function runHasAcceptedManifest(LinehaulRun $run): bool
    {
        $manifestCount = $run->shipments()->count();

        if ($manifestCount < 1) {
            return false;
        }

        $acceptedCount = ShipmentTransfer::query()
            ->whereIn('shipment_id', $run->shipments()->select('shipments.id'))
            ->where('status', 'accepted')
            ->where('from_logistics_profile_id', $run->origin_logistics_profile_id)
            ->where('to_logistics_profile_id', $run->destination_logistics_profile_id)
            ->count();

        return $acceptedCount === $manifestCount;
    }

    private function isEligibleCourierProfile(RiderProfile $rider): bool
    {
        return $rider->work_type === RiderWorkType::Courier->value
            && $rider->verification_status === 'approved'
            && $rider->courier_vehicle_verified_at !== null;
    }

    private function dailyRateMinor(): int
    {
        return max(0, (int) config('bearly_rider_compensation.courier_daily_rate_minor', 65000));
    }

    private function earningType(): string
    {
        return (string) config('bearly_rider_compensation.courier_daily_earning_type', 'courier_daily');
    }

    private function formatMinor(int $amountMinor): string
    {
        $whole = intdiv(abs($amountMinor), 100);
        $fraction = str_pad((string) (abs($amountMinor) % 100), 2, '0', STR_PAD_LEFT);
        $sign = $amountMinor < 0 ? '−' : '';

        return $sign.'₱'.number_format($whole).'.'.$fraction;
    }
}
