<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\RiderWorkType;
use App\Enums\UserRole;
use App\Models\LogisticsProfile;
use App\Models\RiderAttendanceAbsence;
use App\Models\RiderProfile;
use App\Models\RiderShift;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RiderAttendanceRosterService
{
    /** @return array{date: string, date_label: string, cutoff_label: string, cutoff_at: string, can_mark_absent: bool, riders: array<int, array<string, mixed>>, checked_in_count: int, absent_count: int} */
    public function forAttendancePage(?LogisticsProfile $provider): array
    {
        $now = Carbon::now(RiderShift::TIMEZONE);
        $workDate = $now->toDateString();
        $nextWorkDate = $now->copy()->addDay()->toDateString();
        $dayStartUtc = $now->copy()->startOfDay()->utc();
        $nextDayStartUtc = $now->copy()->addDay()->startOfDay()->utc();
        $cutoff = $now->copy()->setTime(23, 59, 0);

        $riders = $provider && $provider->status === AccountStatus::Active->value
            ? $provider->riders()
                ->where('verification_status', 'approved')
                ->where(function ($query): void {
                    $query->whereNull('work_type')
                        ->orWhereIn('work_type', [
                            RiderWorkType::Local->value,
                            RiderWorkType::Courier->value,
                        ]);
                })
                ->whereHas('user', fn ($query) => $query->where('status', AccountStatus::Active->value))
                ->with('user:id,name')
                ->orderBy('id')
                ->get()
            : collect();

        $riderIds = $riders->modelKeys();
        $shiftsByRider = $riderIds === []
            ? collect()
            : RiderShift::query()
                ->whereIn('rider_profile_id', $riderIds)
                ->where('checked_in_at', '>=', $dayStartUtc)
                ->where('checked_in_at', '<', $nextDayStartUtc)
                ->orderBy('checked_in_at')
                ->orderBy('id')
                ->get(['id', 'rider_profile_id', 'checked_in_at', 'checked_out_at'])
                ->groupBy('rider_profile_id');

        $absencesByRider = $provider && $riderIds !== []
            ? RiderAttendanceAbsence::query()
                ->with('markedBy:id,name')
                ->where('logistics_profile_id', $provider->id)
                ->where('work_date', '>=', $workDate)
                ->where('work_date', '<', $nextWorkDate)
                ->whereIn('rider_profile_id', $riderIds)
                ->get()
                ->keyBy('rider_profile_id')
            : collect();

        $rows = $riders->map(function (RiderProfile $rider) use ($shiftsByRider, $absencesByRider): array {
            $shifts = $shiftsByRider->get($rider->id, collect());
            $firstShift = $shifts->first();
            $lastShift = $shifts->last();
            $absence = $absencesByRider->get($rider->id);
            $lateShift = $absence?->marked_at
                ? $shifts->first(fn (RiderShift $shift): bool => $shift->checked_in_at?->greaterThan($absence->marked_at) ?? false)
                : null;

            return [
                'id' => (int) $rider->id,
                'name' => $rider->user?->name ?: 'Rider account unavailable',
                'work_type' => $rider->work_type === RiderWorkType::Courier->value ? 'Courier' : 'Local Rider',
                'check_in_at' => $firstShift?->checked_in_at?->copy()->timezone(RiderShift::TIMEZONE),
                'check_out_at' => $lastShift?->checked_out_at?->copy()->timezone(RiderShift::TIMEZONE),
                'status' => $absence
                    ? 'absent'
                    : ($firstShift ? ($lastShift?->checked_out_at ? 'checked_out' : 'checked_in') : 'not_checked_in'),
                'absence' => $absence ? [
                    'marked_at' => $absence->marked_at?->copy()->timezone(RiderShift::TIMEZONE),
                    'marked_by' => $absence->markedBy?->name ?: 'Logistics operator',
                ] : null,
                'late_check_in_at' => $lateShift?->checked_in_at?->copy()->timezone(RiderShift::TIMEZONE),
            ];
        })->all();

        return [
            'date' => $workDate,
            'date_label' => $now->format('l, F j, Y'),
            'cutoff_label' => $cutoff->format('g:i A').' PHT',
            'cutoff_at' => $cutoff->toIso8601String(),
            'can_mark_absent' => $now->greaterThanOrEqualTo($cutoff),
            'riders' => $rows,
            'checked_in_count' => collect($rows)->whereNotNull('check_in_at')->count(),
            'absent_count' => collect($rows)->whereNotNull('absence')->count(),
        ];
    }

    public function markAbsent(User $actor, RiderProfile $rider): RiderAttendanceAbsence
    {
        $provider = $this->activeProviderFor($actor);
        $requestedWorkDate = Carbon::now(RiderShift::TIMEZONE)->toDateString();

        return DB::transaction(function () use ($actor, $provider, $rider, $requestedWorkDate): RiderAttendanceAbsence {
            // QR scans lock this same Rider row before creating a shift. This
            // serializes a check-in against absence marking for the same date.
            $lockedRider = RiderProfile::query()
                ->whereKey($rider->id)
                ->where('logistics_profile_id', $provider->id)
                ->where('verification_status', 'approved')
                ->where(function ($query): void {
                    $query->whereNull('work_type')
                        ->orWhereIn('work_type', [
                            RiderWorkType::Local->value,
                            RiderWorkType::Courier->value,
                        ]);
                })
                ->whereHas('user', fn ($query) => $query->where('status', AccountStatus::Active->value))
                ->lockForUpdate()
                ->firstOrFail();

            $now = Carbon::now(RiderShift::TIMEZONE);

            if ($now->toDateString() !== $requestedWorkDate) {
                throw ValidationException::withMessages([
                    'attendance' => 'The PHT attendance date changed. Refresh the dashboard before marking an absence.',
                ]);
            }

            $cutoff = $now->copy()->setTime(23, 59, 0);
            $nextWorkDate = $now->copy()->addDay()->toDateString();

            if ($now->lessThan($cutoff)) {
                throw ValidationException::withMessages([
                    'attendance' => 'Absences can only be marked starting at 11:59 PM PHT.',
                ]);
            }

            $absence = RiderAttendanceAbsence::query()
                ->where('rider_profile_id', $lockedRider->id)
                ->where('work_date', '>=', $requestedWorkDate)
                ->where('work_date', '<', $nextWorkDate)
                ->first();

            if ($absence) {
                return $absence;
            }

            $dayStartUtc = $now->copy()->startOfDay()->utc();
            $nextDayStartUtc = $now->copy()->addDay()->startOfDay()->utc();
            $hasCheckedIn = RiderShift::query()
                ->where('rider_profile_id', $lockedRider->id)
                ->where('checked_in_at', '>=', $dayStartUtc)
                ->where('checked_in_at', '<', $nextDayStartUtc)
                ->exists();

            if ($hasCheckedIn) {
                throw ValidationException::withMessages([
                    'attendance' => 'This Rider has already checked in today and cannot be marked absent.',
                ]);
            }

            return RiderAttendanceAbsence::query()->create([
                'logistics_profile_id' => $provider->id,
                'rider_profile_id' => $lockedRider->id,
                'work_date' => $requestedWorkDate,
                'marked_by_user_id' => $actor->id,
                'marked_at' => $now->copy()->utc(),
            ]);
        }, attempts: 3);
    }

    private function activeProviderFor(User $actor): LogisticsProfile
    {
        if ($actor->status !== AccountStatus::Active->value
            || ! $actor->hasRole(UserRole::Logistics->value)) {
            throw new AuthorizationException('An active Logistics account is required to mark attendance.');
        }

        $provider = $actor->logisticsProfile()
            ->where('status', AccountStatus::Active->value)
            ->first();

        if (! $provider) {
            throw new AuthorizationException('An active Logistics profile is required to mark attendance.');
        }

        return $provider;
    }
}
