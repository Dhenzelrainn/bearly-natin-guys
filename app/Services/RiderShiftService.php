<?php

namespace App\Services;

use App\Models\RiderProfile;
use App\Models\RiderShift;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RiderShiftService
{
    public function checkIn(RiderProfile $rider, User $actor, string $source = 'rider'): RiderShift
    {
        return DB::transaction(function () use ($rider, $actor, $source): RiderShift {
            $lockedRider = RiderProfile::query()
                ->whereKey($rider->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertActorCanReport($lockedRider, $actor, $source);

            $open = RiderShift::query()
                ->where('rider_profile_id', $lockedRider->id)
                ->whereNull('checked_out_at')
                ->lockForUpdate()
                ->first();

            if ($open) {
                throw new ConflictHttpException(
                    'You are already checked in for the current shift.'
                );
            }

            $shift = RiderShift::query()->create([
                'rider_profile_id' => $lockedRider->id,
                'checked_in_at' => now(),
                'check_in_source' => $source,
            ]);

            $this->audit->record(
                action: 'rider_shift.checked_in',
                module: 'Rider',
                subject: $shift,
                description: 'Rider attendance check-in recorded for reporting.',
                newValues: [
                    'rider_profile_id' => (int) $lockedRider->id,
                    'checked_in_at' => $shift->checked_in_at?->toIso8601String(),
                    'actor_user_id' => (int) $actor->id,
                ],
                actor: $actor,
            );

            return $shift;
        }, attempts: 3);
    }

    public function checkOut(RiderProfile $rider, User $actor, string $source = 'rider'): RiderShift
    {
        return DB::transaction(function () use ($rider, $actor, $source): RiderShift {
            $lockedRider = RiderProfile::query()
                ->whereKey($rider->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertActorCanReport($lockedRider, $actor, $source);

            $shift = RiderShift::query()
                ->where('rider_profile_id', $lockedRider->id)
                ->whereNull('checked_out_at')
                ->lockForUpdate()
                ->first();

            if (! $shift) {
                throw new ConflictHttpException(
                    'You are not currently checked in.'
                );
            }

            $shift->update([
                'checked_out_at' => now(),
                'check_out_source' => $source,
            ]);

            $this->audit->record(
                action: 'rider_shift.checked_out',
                module: 'Rider',
                subject: $shift,
                description: 'Rider attendance check-out recorded for reporting.',
                newValues: [
                    'rider_profile_id' => (int) $lockedRider->id,
                    'checked_out_at' => $shift->checked_out_at?->toIso8601String(),
                    'actor_user_id' => (int) $actor->id,
                ],
                actor: $actor,
            );

            $this->courierDaily->reconcileShift($shift);

            return $shift;
        }, attempts: 3);
    }

    public function currentShift(RiderProfile $rider): ?RiderShift
    {
        return RiderShift::query()
            ->where('rider_profile_id', $rider->id)
            ->whereNull('checked_out_at')
            ->orderByDesc('checked_in_at')
            ->first();
    }

    public function attendanceHistoryFor(RiderProfile $rider): LengthAwarePaginator
    {
        return $rider->shifts()
            ->latest('checked_in_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * Reporting-only grouping by shift and event time. A missing shift never
     * removes or changes an earning.
     *
     * @return Collection<int, array{shift: RiderShift|null, total_minor: int, entries: int}>
     */
    public function earningGroupsFor(RiderProfile $rider): Collection
    {
        $totals = DB::table('rider_earnings')
            ->where('rider_profile_id', $rider->id)
            ->whereNotIn('status', ['reversed', 'cancelled'])
            ->select('rider_shift_id')
            ->selectRaw('COUNT(*) as entries')
            ->selectRaw('COALESCE(SUM(amount_minor), 0) as total_minor')
            ->groupBy('rider_shift_id')
            ->get();

        $shiftIds = $totals
            ->pluck('rider_shift_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->values();
        $shifts = $shiftIds->isEmpty()
            ? collect()
            : RiderShift::query()
                ->where('rider_profile_id', $rider->id)
                ->whereIn('id', $shiftIds)
                ->get()
                ->keyBy('id');

        return $totals
            ->map(function (object $row) use ($shifts): array {
                $shift = $row->rider_shift_id === null
                    ? null
                    : $shifts->get((int) $row->rider_shift_id);

                return [
                    'shift' => $shift,
                    'total_minor' => (int) $row->total_minor,
                    'entries' => (int) $row->entries,
                ];
            })
            ->sortByDesc(fn (array $group): int => $group['shift']?->checked_in_at?->getTimestamp() ?? 0)
            ->values();
    }

    public function shiftForEvent(RiderProfile $rider, Carbon $occurredAt): ?RiderShift
    {
        return RiderShift::query()
            ->where('rider_profile_id', $rider->id)
            ->where('checked_in_at', '<=', $occurredAt)
            ->where(function ($query) use ($occurredAt): void {
                $query->whereNull('checked_out_at')
                    ->orWhere('checked_out_at', '>=', $occurredAt);
            })
            ->orderByDesc('checked_in_at')
            ->first();
    }

    private function assertActorCanReport(RiderProfile $rider, User $actor, string $source): void
    {
        if (in_array($source, ['rider', 'qr'], true)
            && (int) $rider->user_id === (int) $actor->id) {
            return;
        }

        $providerId = $source === 'logistics'
            ? (int) $actor->logisticsProfile()->value('id')
            : 0;

        if ($providerId > 0 && $providerId === (int) $rider->logistics_profile_id) {
            return;
        }

        throw new AuthorizationException('Only the Rider or their owning Logistics provider may report attendance.');
    }

    public function __construct(
        private readonly AuditService $audit,
        private readonly CourierDailyEarningService $courierDaily,
    ) {}
}
