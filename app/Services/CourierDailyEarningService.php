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
     * Reconcile the QR-recorded attendance for a closed shift's PHT work date.
     * The existing rider/type/work-date unique constraint remains the final
     * duplicate guard; legacy fixed-rate rows are never rewritten.
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

            return $this->reconcileWorkDate(
                (int) $lockedShift->rider_profile_id,
                $this->workDateFor($lockedShift),
            );
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
                ->where('check_in_source', 'qr')
                ->where('check_out_source', 'qr')
                ->with('attendanceScans')
                ->orderBy('checked_in_at')
                ->orderBy('id')
                ->get();

            return $shifts
                ->filter(fn (RiderShift $shift): bool => $this->hasQrAttendanceProof($shift))
                ->map(fn (RiderShift $shift): ?RiderEarning => $this->reconcileWorkDate(
                    (int) $shift->rider_profile_id,
                    $this->workDateFor($shift),
                ))
                ->filter()
                ->unique('id')
                ->values();
        }, attempts: 3);
    }

    /**
     * @return array{
     *     rate_minor: int,
     *     rate: string,
     *     daily_cap_minor: int,
     *     daily_cap: string,
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
            'rate_minor' => $this->hourlyRateMinor(),
            'rate' => $this->formatMinor($this->hourlyRateMinor()),
            'daily_cap_minor' => $this->dailyCapMinor(),
            'daily_cap' => $this->formatMinor($this->dailyCapMinor()),
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

    private function reconcileWorkDate(int $riderProfileId, string $workDate): ?RiderEarning
    {
        $rider = RiderProfile::query()
            ->whereKey($riderProfileId)
            ->lockForUpdate()
            ->first();

        if (! $rider || ! $this->isEligibleCourierProfile($rider)) {
            return null;
        }

        $shifts = $this->closedQrShiftsForWorkDate($rider, $workDate);

        if ($shifts->isEmpty()) {
            return null;
        }

        $qualifyingPair = $shifts
            ->map(fn (RiderShift $shift): ?array => ($run = $this->qualifyingRunFor($rider, $shift))
                ? ['shift' => $shift, 'run' => $run]
                : null)
            ->filter()
            ->sortBy(fn (array $pair): string => $pair['run']->completed_at?->toDateTimeString() ?? '')
            ->first();

        if (! $qualifyingPair) {
            return null;
        }

        /** @var RiderShift $qualifyingShift */
        $qualifyingShift = $qualifyingPair['shift'];
        /** @var LinehaulRun $run */
        $run = $qualifyingPair['run'];
        $workedSeconds = min(
            $this->dailyMaxPaidSeconds(),
            (int) $shifts->sum(fn (RiderShift $shift): int => max(
                0,
                $shift->checked_out_at->getTimestamp() - $shift->checked_in_at->getTimestamp(),
            )),
        );
        $amountMinor = $this->amountForWorkedSeconds($workedSeconds);

        if ($workedSeconds < 1 || $amountMinor < 1) {
            return null;
        }

        $earnedAt = $shifts
            ->pluck('checked_out_at')
            ->push($run->completed_at)
            ->filter()
            ->map(fn ($value): Carbon => Carbon::parse($value))
            ->max() ?? now();
        $now = now();
        $earningQuery = RiderEarning::query()
            ->where('rider_profile_id', $rider->id)
            ->where('type', $this->earningType())
            ->whereDate('work_date', $workDate);
        $existing = (clone $earningQuery)->lockForUpdate()->first();

        // A row without the new work-time snapshot is a historical daily-rate
        // earning. Keep that financial history exactly as recorded.
        if ($existing && $existing->worked_seconds_snapshot === null) {
            return $existing;
        }

        if ($existing && (
            $existing->status !== 'posted'
            || $existing->paid_at !== null
            || $existing->payout()->exists()
            || $existing->payoutBatchItem()->exists()
        )) {
            return $existing;
        }

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
            'description' => "Courier hourly pay for {$workDate} · {$run->run_no}",
            'earned_at' => $earnedAt,
            'posted_at' => $now,
            'paid_at' => null,
            'work_date' => $workDate,
            'rider_shift_id' => (int) $qualifyingShift->id,
            'linehaul_run_id' => (int) $run->id,
            'size_class_snapshot' => null,
            'rate_minor' => $this->hourlyRateMinor(),
            'worked_seconds_snapshot' => $workedSeconds,
            'daily_cap_minor_snapshot' => $this->dailyCapMinor(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $earning = (clone $earningQuery)->lockForUpdate()->firstOrFail();

        if ($earning->worked_seconds_snapshot === null
            || $earning->status !== 'posted'
            || $earning->paid_at !== null
            || $earning->payout()->exists()
            || $earning->payoutBatchItem()->exists()) {
            return $earning;
        }

        $earning->update([
            'logistics_profile_id' => (int) $run->origin_logistics_profile_id,
            'amount_minor' => $amountMinor,
            'description' => "Courier hourly pay for {$workDate} · {$run->run_no}",
            'earned_at' => $earnedAt,
            'posted_at' => $earning->posted_at ?? $now,
            'rider_shift_id' => (int) $qualifyingShift->id,
            'linehaul_run_id' => (int) $run->id,
            'rate_minor' => $this->hourlyRateMinor(),
            'worked_seconds_snapshot' => $workedSeconds,
            'daily_cap_minor_snapshot' => $this->dailyCapMinor(),
        ]);

        return $earning->fresh();
    }

    /**
     * Total paid seconds from fully QR-proven closed shifts for a PHT work date,
     * capped at the configured daily paid-time maximum.
     */
    public function workedSecondsForWorkDate(RiderProfile $rider, string $workDate): int
    {
        $shifts = $this->closedQrShiftsForWorkDate($rider, $workDate);

        return min(
            $this->dailyMaxPaidSeconds(),
            (int) $shifts->sum(fn (RiderShift $shift): int => max(
                0,
                $shift->checked_out_at->getTimestamp() - $shift->checked_in_at->getTimestamp(),
            )),
        );
    }

    public function hasOpenShiftForWorkDate(RiderProfile $rider, string $workDate): bool
    {
        $start = Carbon::parse($workDate, self::TIMEZONE)->startOfDay()->utc();
        $end = $start->copy()->addDay();

        return RiderShift::query()
            ->where('rider_profile_id', $rider->id)
            ->whereNull('checked_out_at')
            ->where('checked_in_at', '>=', $start)
            ->where('checked_in_at', '<', $end)
            ->exists();
    }

    public function isValidPayoutSnapshot(RiderEarning $earning): bool
    {
        if ($earning->worked_seconds_snapshot === null) {
            // Preserve the existing payout rule for historical fixed daily rows.
            return $earning->rate_minor !== null
                && (int) $earning->rate_minor === (int) $earning->amount_minor;
        }

        $rider = $earning->riderProfile;

        if (! $rider
            || $earning->work_date === null
            || $earning->rate_minor === null
            || $earning->daily_cap_minor_snapshot === null
            || $this->hasOpenShiftForWorkDate($rider, $earning->work_date->toDateString())) {
            return false;
        }

        $seconds = (int) $earning->worked_seconds_snapshot;
        $rate = (int) $earning->rate_minor;
        $cap = (int) $earning->daily_cap_minor_snapshot;

        return $seconds > 0
            && $seconds <= $this->dailyMaxPaidSeconds()
            && $seconds === $this->workedSecondsForWorkDate($rider, $earning->work_date->toDateString())
            && $rate > 0
            && $cap > 0
            && (int) $earning->amount_minor === $this->amountForWorkedSeconds($seconds, $rate, $cap);
    }

    public function amountForWorkedSeconds(int $seconds, ?int $rateMinor = null, ?int $capMinor = null): int
    {
        $seconds = max(0, $seconds);
        $rateMinor ??= $this->hourlyRateMinor();
        $capMinor ??= $this->dailyCapMinor();

        return min(
            max(0, $capMinor),
            intdiv(($seconds * max(0, $rateMinor)) + 1800, 3600),
        );
    }

    private function closedQrShiftsForWorkDate(RiderProfile $rider, string $workDate): Collection
    {
        $start = Carbon::parse($workDate, self::TIMEZONE)->startOfDay()->utc();
        $end = $start->copy()->addDay();

        return RiderShift::query()
            ->where('rider_profile_id', $rider->id)
            ->where('check_in_source', 'qr')
            ->where('check_out_source', 'qr')
            ->whereNotNull('checked_out_at')
            ->where('checked_in_at', '>=', $start)
            ->where('checked_in_at', '<', $end)
            ->with('attendanceScans')
            ->orderBy('checked_in_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (RiderShift $shift): bool => $this->hasQrAttendanceProof($shift))
            ->values();
    }

    private function hasQrAttendanceProof(RiderShift $shift): bool
    {
        if ($shift->check_in_source !== 'qr'
            || $shift->check_out_source !== 'qr'
            || $shift->checked_in_at === null
            || $shift->checked_out_at === null) {
            return false;
        }

        $scans = $shift->relationLoaded('attendanceScans')
            ? $shift->attendanceScans
            : $shift->attendanceScans()->get();

        return $scans->contains(fn ($scan): bool => $scan->scan_type === 'check_in')
            && $scans->contains(fn ($scan): bool => $scan->scan_type === 'check_out');
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

    private function hourlyRateMinor(): int
    {
        return max(0, (int) config('bearly_rider_compensation.courier_hourly_rate_minor', 8125));
    }

    private function dailyCapMinor(): int
    {
        return max(0, (int) config('bearly_rider_compensation.courier_daily_cap_minor', 65000));
    }

    private function dailyMaxPaidSeconds(): int
    {
        return max(0, (int) config('bearly_rider_compensation.courier_daily_max_paid_seconds', 28800));
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
