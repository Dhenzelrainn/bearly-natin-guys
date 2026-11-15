<?php

namespace App\Services;

use App\Models\Parcel;
use App\Models\PickupAssignment;
use App\Models\RiderEarning;
use App\Models\RiderShift;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RiderPickupEarningService
{
    public const STATUS_HELD = 'held';

    public const STATUS_POSTED = 'posted';

    public function __construct(private readonly AuditService $audit) {}

    /** @return array{regular_minor: int, bulky_minor: int, bulky_class: string, regular_classes: list<string>} */
    public function rates(): array
    {
        $regularClasses = $this->settingValue('rider.pickup_regular_size_classes');
        $regularClasses = is_string($regularClasses)
            ? json_decode($regularClasses, true)
            : $regularClasses;

        if (! is_array($regularClasses)) {
            $regularClasses = config('bearly_rider_compensation.pickup_regular_size_classes', []);
        }

        return [
            'regular_minor' => max(0, (int) (
                $this->settingValue('rider.pickup_rate_regular_minor')
                ?? config('bearly_rider_compensation.pickup_rate_regular_minor', 1500)
            )),
            'bulky_minor' => max(0, (int) (
                $this->settingValue('rider.pickup_rate_bulky_minor')
                ?? config('bearly_rider_compensation.pickup_rate_bulky_minor', 3000)
            )),
            'bulky_class' => (string) (
                $this->settingValue('rider.pickup_bulky_size_class')
                ?? config('bearly_rider_compensation.pickup_bulky_size_class', 'bulky')
            ),
            'regular_classes' => collect($regularClasses)
                ->map(fn ($value): string => strtolower(trim((string) $value)))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /** @return list<string> */
    public function sizeClasses(): array
    {
        $rates = $this->rates();

        return collect([
            ...$rates['regular_classes'],
            $rates['bulky_class'],
        ])
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{classification: string, rate_minor: int|null}
     */
    public function classify(?string $sizeClass): array
    {
        $rates = $this->rates();
        $savedClass = (string) $sizeClass;

        if ($savedClass !== '' && $savedClass === $rates['bulky_class']) {
            return [
                'classification' => 'bulky',
                'rate_minor' => $rates['bulky_minor'],
            ];
        }

        if ($savedClass !== '' && in_array($savedClass, $rates['regular_classes'], true)) {
            return [
                'classification' => 'regular',
                'rate_minor' => $rates['regular_minor'],
            ];
        }

        return [
            'classification' => 'unknown',
            'rate_minor' => null,
        ];
    }

    /**
     * Create exactly one earning per successfully collected outbound parcel.
     * A missing or unknown persisted size class records a held earning instead
     * of silently guessing from parcel count or dimensions.
     *
     * @param  Collection<int, Parcel>  $parcels
     * @return Collection<int, RiderEarning>
     */
    public function recordForPickup(
        PickupAssignment $assignment,
        Collection $parcels,
        Carbon $pickedUpAt,
    ): Collection {
        $rider = $assignment->riderProfile()->firstOrFail();
        $pickup = $assignment->pickupRequest;
        $shift = $this->shiftForEvent($rider->id, $pickedUpAt);
        $rates = $this->rates();
        $earnings = collect();

        foreach ($parcels as $parcel) {
            if ($parcel->shipment?->purpose !== 'outbound') {
                continue;
            }

            $existing = RiderEarning::query()
                ->where('pickup_assignment_id', $assignment->id)
                ->where('parcel_id', $parcel->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $earnings->push($existing);

                continue;
            }

            $classification = $this->classify($parcel->size_class);
            $isClassified = $classification['classification'] !== 'unknown';
            $rate = $isClassified ? (int) $classification['rate_minor'] : 0;

            $earning = RiderEarning::query()->create([
                'rider_profile_id' => $rider->id,
                'pickup_assignment_id' => $assignment->id,
                'parcel_id' => $parcel->id,
                'rider_shift_id' => $shift?->id,
                'shipment_id' => $parcel->shipment_id,
                'logistics_profile_id' => $pickup?->logistics_profile_id
                    ?? $parcel->shipment?->logistics_profile_id,
                'type' => config('bearly_rider_compensation.pickup_earning_type', 'pickup_parcel'),
                'amount_minor' => $rate,
                'rate_minor' => $isClassified ? $rate : null,
                'size_class_snapshot' => $parcel->size_class,
                'status' => $isClassified ? self::STATUS_POSTED : self::STATUS_HELD,
                'description' => $this->description(
                    $parcel,
                    $classification['classification'],
                    $rates,
                ),
                'earned_at' => $pickedUpAt,
                'posted_at' => $isClassified ? now() : null,
            ]);

            $this->audit->record(
                action: $isClassified
                    ? 'rider_pickup_earning.created'
                    : 'rider_pickup_earning.held_for_review',
                module: 'Logistics',
                subject: $earning,
                description: $isClassified
                    ? "Pickup earning created for parcel {$parcel->parcel_no}."
                    : "Pickup earning held for size-class review for parcel {$parcel->parcel_no}.",
                newValues: [
                    'rider_profile_id' => (int) $rider->id,
                    'pickup_assignment_id' => (int) $assignment->id,
                    'parcel_id' => (int) $parcel->id,
                    'size_class' => $parcel->size_class,
                    'classification' => $classification['classification'],
                    'rate_minor' => $rate,
                    'status' => $earning->status,
                ],
                severity: $isClassified ? 'info' : 'warning',
            );

            $earnings->push($earning);
        }

        return $earnings;
    }

    public function classifyHeldEarning(
        RiderEarning $earning,
        string $sizeClass,
        User $actor,
    ): RiderEarning {
        return DB::transaction(function () use ($earning, $sizeClass, $actor): RiderEarning {
            $locked = RiderEarning::query()
                ->whereKey($earning->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $locked->type !== config('bearly_rider_compensation.pickup_earning_type', 'pickup_parcel')
                || $locked->status !== self::STATUS_HELD
                || $locked->paid_at !== null
                || $locked->payout()->exists()
                || $locked->payoutBatchItem()->exists()
            ) {
                throw ValidationException::withMessages([
                    'size_class' => 'Only an unpaid, held pickup earning can be classified.',
                ]);
            }

            if (! $locked->parcel_id || ! $locked->pickup_assignment_id) {
                throw new ConflictHttpException(
                    'The held pickup earning no longer has its source parcel and assignment.'
                );
            }

            $parcel = Parcel::query()
                ->whereKey($locked->parcel_id)
                ->lockForUpdate()
                ->firstOrFail();
            $shipment = $parcel->shipment()->with('sellerOrder')->first();
            $assignment = PickupAssignment::query()
                ->whereKey($locked->pickup_assignment_id)
                ->where('rider_profile_id', $locked->rider_profile_id)
                ->with('pickupRequest')
                ->lockForUpdate()
                ->first();
            $providerId = (int) $actor->logisticsProfile()->value('id');

            if (
                $providerId < 1
                || $providerId !== (int) $locked->logistics_profile_id
                || ! $assignment
                || ! in_array($assignment->status, ['picked_up', 'completed'], true)
                || (int) $assignment->pickupRequest?->logistics_profile_id !== $providerId
                || ! $shipment
                || $shipment->purpose !== 'outbound'
                || (int) $shipment->logistics_profile_id !== (int) $locked->logistics_profile_id
                || (int) $shipment->sellerOrder?->store_id !== (int) $assignment->pickupRequest?->store_id
            ) {
                throw new ConflictHttpException(
                    'The source parcel is not an outbound shipment owned by this Logistics provider.'
                );
            }

            $classification = $this->classify($sizeClass);

            if ($classification['classification'] === 'unknown') {
                throw ValidationException::withMessages([
                    'size_class' => 'Choose the authoritative bulky class or a recognized regular parcel size class.',
                ]);
            }

            $old = [
                'status' => $locked->status,
                'amount_minor' => (int) $locked->amount_minor,
                'size_class_snapshot' => $locked->size_class_snapshot,
                'parcel_size_class' => $parcel->size_class,
            ];

            $normalizedClass = $sizeClass;
            $parcel->update(['size_class' => $normalizedClass]);

            $locked->update([
                'size_class_snapshot' => $normalizedClass,
                'rate_minor' => (int) $classification['rate_minor'],
                'amount_minor' => (int) $classification['rate_minor'],
                'status' => self::STATUS_POSTED,
                'posted_at' => now(),
                'description' => trim((string) $locked->description)
                    .' Classified as '.$classification['classification']
                    .' by Logistics.',
            ]);

            $this->audit->record(
                action: 'rider_pickup_earning.classified',
                module: 'Logistics',
                subject: $locked,
                description: "Held pickup earning {$locked->id} was classified by Logistics.",
                oldValues: $old,
                newValues: [
                    'status' => $locked->status,
                    'amount_minor' => (int) $locked->amount_minor,
                    'size_class_snapshot' => $locked->size_class_snapshot,
                    'parcel_size_class' => $normalizedClass,
                    'classification' => $classification['classification'],
                    'classified_by' => (int) $actor->id,
                ],
                severity: 'warning',
                actor: $actor,
            );

            return $locked->fresh();
        }, attempts: 3);
    }

    private function shiftForEvent(int $riderProfileId, Carbon $occurredAt): ?RiderShift
    {
        return RiderShift::query()
            ->where('rider_profile_id', $riderProfileId)
            ->where('checked_in_at', '<=', $occurredAt)
            ->where(function ($query) use ($occurredAt): void {
                $query->whereNull('checked_out_at')
                    ->orWhere('checked_out_at', '>=', $occurredAt);
            })
            ->orderByDesc('checked_in_at')
            ->first();
    }

    /**
     * @param  array{regular_minor: int, bulky_minor: int, bulky_class: string, regular_classes: list<string>}  $rates
     */
    private function description(Parcel $parcel, string $classification, array $rates): string
    {
        if ($classification === 'unknown') {
            return "Pickup earning for parcel {$parcel->parcel_no} is held for Logistics size-class review.";
        }

        $label = $classification === 'bulky' ? 'bulky' : 'regular';

        return "Pickup earning for parcel {$parcel->parcel_no} ({$label}"
            .($parcel->size_class ? ': '.$parcel->size_class : '')
            .").";
    }

    private function settingValue(string $key): mixed
    {
        return SystemSetting::query()
            ->where('key', $key)
            ->value('value');
    }
}
