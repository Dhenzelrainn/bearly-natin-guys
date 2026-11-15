<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

trait HasPromotionSchedule
{
    public function startsAt(): ?Carbon
    {
        return $this->scheduleDateTime('starts_on', 'starts_time');
    }

    public function endsAt(): ?Carbon
    {
        return $this->scheduleDateTime('ends_on', 'ends_time');
    }

    public function schedulePeriod(): string
    {
        $startsAt = $this->startsAt();
        $endsAt = $this->endsAt();

        if (! $startsAt || ! $endsAt) {
            return '—';
        }

        return $startsAt->format('M j, Y g:i A').' – '.$endsAt->format('M j, Y g:i A').' PHT';
    }

    public static function timeFromInput(string $time, bool $inclusiveMinute = false): string
    {
        return $time.($inclusiveMinute ? ':59' : ':00');
    }

    public static function dateTimeFromInput(string $date, string $time, bool $inclusiveMinute = false): Carbon
    {
        return Carbon::createFromFormat(
            'Y-m-d H:i:s',
            $date.' '.self::timeFromInput($time, $inclusiveMinute),
            'Asia/Manila',
        );
    }

    public function isWithinScheduleAt(?Carbon $at = null): bool
    {
        $at = ($at ?? now('Asia/Manila'))->copy()->timezone('Asia/Manila');
        $startsAt = $this->startsAt();
        $endsAt = $this->endsAt();

        return $startsAt !== null
            && $endsAt !== null
            && $startsAt->lessThanOrEqualTo($at)
            && $endsAt->greaterThanOrEqualTo($at);
    }

    public function scopeWithinScheduleAt(Builder $query, ?Carbon $at = null): Builder
    {
        $at = ($at ?? now('Asia/Manila'))->copy()->timezone('Asia/Manila');
        $date = $at->toDateString();
        $time = $at->format('H:i:s');
        $startsOn = $query->getModel()->qualifyColumn('starts_on');
        $startsTime = $query->getModel()->qualifyColumn('starts_time');
        $endsOn = $query->getModel()->qualifyColumn('ends_on');
        $endsTime = $query->getModel()->qualifyColumn('ends_time');

        return $query
            ->where(function (Builder $window) use ($date, $time, $startsOn, $startsTime): void {
                $window->whereDate($startsOn, '<', $date)
                    ->orWhere(function (Builder $sameDay) use ($date, $time, $startsOn, $startsTime): void {
                        $sameDay->whereDate($startsOn, $date)
                            ->where($startsTime, '<=', $time);
                    });
            })
            ->where(function (Builder $window) use ($date, $time, $endsOn, $endsTime): void {
                $window->whereDate($endsOn, '>', $date)
                    ->orWhere(function (Builder $sameDay) use ($date, $time, $endsOn, $endsTime): void {
                        $sameDay->whereDate($endsOn, $date)
                            ->where($endsTime, '>=', $time);
                    });
            });
    }

    public function scopeNotEndedAt(Builder $query, ?Carbon $at = null): Builder
    {
        $at = ($at ?? now('Asia/Manila'))->copy()->timezone('Asia/Manila');
        $date = $at->toDateString();
        $time = $at->format('H:i:s');
        $endsOn = $query->getModel()->qualifyColumn('ends_on');
        $endsTime = $query->getModel()->qualifyColumn('ends_time');

        return $query->where(function (Builder $window) use ($date, $time, $endsOn, $endsTime): void {
            $window->whereDate($endsOn, '>', $date)
                ->orWhere(function (Builder $sameDay) use ($date, $time, $endsOn, $endsTime): void {
                    $sameDay->whereDate($endsOn, $date)
                        ->where($endsTime, '>=', $time);
                });
        });
    }

    public function scopeOverlappingSchedule(Builder $query, Carbon $startsAt, Carbon $endsAt): Builder
    {
        $startsAt = $startsAt->copy()->timezone('Asia/Manila');
        $endsAt = $endsAt->copy()->timezone('Asia/Manila');
        $startDate = $startsAt->toDateString();
        $startTime = $startsAt->format('H:i:s');
        $endDate = $endsAt->toDateString();
        $endTime = $endsAt->format('H:i:s');
        $startsOn = $query->getModel()->qualifyColumn('starts_on');
        $startsTime = $query->getModel()->qualifyColumn('starts_time');
        $endsOn = $query->getModel()->qualifyColumn('ends_on');
        $endsTime = $query->getModel()->qualifyColumn('ends_time');

        return $query
            ->where(function (Builder $window) use ($endDate, $endTime, $startsOn, $startsTime): void {
                $window->whereDate($startsOn, '<', $endDate)
                    ->orWhere(function (Builder $sameDay) use ($endDate, $endTime, $startsOn, $startsTime): void {
                        $sameDay->whereDate($startsOn, $endDate)
                            ->where($startsTime, '<=', $endTime);
                    });
            })
            ->where(function (Builder $window) use ($startDate, $startTime, $endsOn, $endsTime): void {
                $window->whereDate($endsOn, '>', $startDate)
                    ->orWhere(function (Builder $sameDay) use ($startDate, $startTime, $endsOn, $endsTime): void {
                        $sameDay->whereDate($endsOn, $startDate)
                            ->where($endsTime, '>=', $startTime);
                    });
            });
    }

    private function scheduleDateTime(string $dateColumn, string $timeColumn): ?Carbon
    {
        $date = $this->getAttribute($dateColumn)?->toDateString();
        if (! $date) {
            return null;
        }

        $time = (string) ($this->getAttribute($timeColumn) ?: ($dateColumn === 'starts_on' ? '00:00:00' : '23:59:59'));
        if (strlen($time) === 5) {
            $time .= ':00';
        }

        return Carbon::createFromFormat('Y-m-d H:i:s', $date.' '.$time, 'Asia/Manila');
    }
}
