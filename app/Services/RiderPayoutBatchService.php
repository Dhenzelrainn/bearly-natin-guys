<?php

namespace App\Services;

use App\Events\RiderShippingEarningUpdated;
use App\Models\LogisticsProfile;
use App\Models\RiderEarning;
use App\Models\RiderEarningPayout;
use App\Models\RiderPayoutBatch;
use App\Models\RiderPayoutBatchItem;
use App\Models\RiderProfile;
use App\Models\User;
use App\Enums\AccountStatus;
use App\Enums\UserRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RiderPayoutBatchService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly CourierDailyEarningService $courierDaily,
    ) {}

    /** @return Collection<int, RiderEarning> */
    public function eligibleEarningsFor(User $logisticsUser, RiderProfile $rider): Collection
    {
        $profile = $this->logisticsProfile($logisticsUser);
        $this->assertRiderOwned($profile, $rider);

        return $this->pendingEligibleEarningsQuery()
            ->where('rider_profile_id', $rider->id)
            ->where('logistics_profile_id', $profile->id)
            ->orderBy('earned_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (RiderEarning $earning): bool => $this->earningPassesPayoutGate($earning))
            ->values();
    }

    /** @return Collection<int, RiderEarning> */
    public function heldPickupEarningsFor(User $logisticsUser): Collection
    {
        $profile = $this->logisticsProfile($logisticsUser);

        return RiderEarning::query()
            ->where('logistics_profile_id', $profile->id)
            ->where('type', config('bearly_rider_compensation.pickup_earning_type', 'pickup_parcel'))
            ->where('status', RiderPickupEarningService::STATUS_HELD)
            ->with(['riderProfile.user', 'parcel', 'pickupAssignment.pickupRequest'])
            ->orderBy('earned_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, array{rider: RiderProfile, earnings: Collection<int, RiderEarning>, total_minor: int}>
     */
    public function eligibleEarningsByRider(User $logisticsUser): Collection
    {
        $profile = $this->logisticsProfile($logisticsUser);

        $earnings = $this->pendingEligibleEarningsQuery()
            ->where('logistics_profile_id', $profile->id)
            ->with(['riderProfile.user', 'pickupAssignment.pickupRequest', 'parcel'])
            ->orderBy('rider_profile_id')
            ->orderBy('earned_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (RiderEarning $earning): bool => $this->earningPassesPayoutGate($earning))
            ->values();

        return $earnings
            ->groupBy('rider_profile_id')
            ->map(function (Collection $rows): array {
                /** @var RiderEarning $first */
                $first = $rows->first();

                return [
                    'rider' => $first->riderProfile,
                    'earnings' => $rows->values(),
                    'total_minor' => (int) $rows->sum('amount_minor'),
                ];
            })
            ->values();
    }

    public function createBatch(
        User $logisticsUser,
        RiderProfile $rider,
        ?string $notes,
        Request $request,
    ): RiderPayoutBatch {
        $this->assertAuthenticatedActor($logisticsUser, $request);
        $profile = $this->logisticsProfile($logisticsUser);
        $this->assertRiderOwned($profile, $rider);

        return DB::transaction(function () use ($logisticsUser, $profile, $rider, $notes, $request): RiderPayoutBatch {
            $lockedRider = RiderProfile::query()
                ->whereKey($rider->id)
                ->where('logistics_profile_id', $profile->id)
                ->lockForUpdate()
                ->firstOrFail();

            $earnings = $this->pendingEligibleEarningsQuery()
                ->where('rider_profile_id', $lockedRider->id)
                ->where('logistics_profile_id', $profile->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter(fn (RiderEarning $earning): bool => $this->earningPassesPayoutGate($earning))
                ->values();

            if ($earnings->isEmpty()) {
                throw ValidationException::withMessages([
                    'rider_profile_id' => 'This Rider has no payout-eligible earnings right now.',
                ]);
            }

            $totalMinor = (int) $earnings->sum('amount_minor');

            $batch = RiderPayoutBatch::query()->create([
                'batch_no' => $this->nextBatchNumber(),
                'logistics_profile_id' => $profile->id,
                'rider_profile_id' => $lockedRider->id,
                'status' => 'pending_review',
                'total_minor' => $totalMinor,
                'review_notes' => filled($notes) ? trim((string) $notes) : null,
            ]);

            foreach ($earnings as $earning) {
                RiderPayoutBatchItem::query()->create([
                    'rider_payout_batch_id' => $batch->id,
                    'rider_earning_id' => $earning->id,
                    'amount_minor' => (int) $earning->amount_minor,
                    'earning_type' => (string) $earning->type,
                ]);
            }

            $this->audit->record(
                action: 'rider_payout_batch.created',
                module: 'Logistics',
                subject: $batch,
                description: "Rider payout batch {$batch->batch_no} was prepared for review.",
                newValues: [
                    'batch_no' => $batch->batch_no,
                    'rider_profile_id' => (int) $lockedRider->id,
                    'logistics_profile_id' => (int) $profile->id,
                    'earning_ids' => $earnings->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                    'total_minor' => $totalMinor,
                    'status' => 'pending_review',
                ],
                severity: 'warning',
                request: $request,
            );

            return $batch->fresh(['items.riderEarning', 'riderProfile.user']);
        }, attempts: 3);
    }

    public function approveBatch(
        User $logisticsUser,
        int $batchId,
        Request $request,
    ): RiderPayoutBatch {
        $this->assertAuthenticatedActor($logisticsUser, $request);
        $profile = $this->logisticsProfile($logisticsUser);

        return DB::transaction(function () use ($logisticsUser, $profile, $batchId, $request): RiderPayoutBatch {
            $batch = $this->lockedOwnedBatch($profile, $batchId);

            if ($batch->status === 'approved') {
                return $batch->fresh(['items.riderEarning', 'riderProfile.user']);
            }

            if ($batch->status !== 'pending_review') {
                throw new ConflictHttpException(
                    'Only a pending-review payout batch can be approved.'
                );
            }

            $this->assertBatchEarningsStillEligible($batch);

            $batch->update([
                'status' => 'approved',
                'reviewed_by' => $logisticsUser->id,
                'reviewed_at' => now(),
                'approved_by' => $logisticsUser->id,
                'approved_at' => now(),
            ]);

            $this->audit->record(
                action: 'rider_payout_batch.approved',
                module: 'Logistics',
                subject: $batch,
                description: "Rider payout batch {$batch->batch_no} was reviewed and approved.",
                oldValues: ['status' => 'pending_review'],
                newValues: [
                    'status' => 'approved',
                    'approved_by' => (int) $logisticsUser->id,
                    'total_minor' => (int) $batch->total_minor,
                    'earning_ids' => $batch->items->pluck('rider_earning_id')->map(fn ($id): int => (int) $id)->all(),
                ],
                severity: 'warning',
                request: $request,
            );

            return $batch->fresh(['items.riderEarning', 'riderProfile.user']);
        }, attempts: 3);
    }

    public function recordPayment(
        User $logisticsUser,
        int $batchId,
        string $method,
        string $reference,
        string $paidOn,
        string $reason,
        Request $request,
    ): RiderPayoutBatch {
        $this->assertAuthenticatedActor($logisticsUser, $request);
        $profile = $this->logisticsProfile($logisticsUser);

        if ($method === 'cash') {
            $reference = '';
        }

        $this->validatePaymentDetails($method, $reference, $paidOn, $reason);

        return DB::transaction(function () use (
            $logisticsUser,
            $profile,
            $batchId,
            $method,
            $reference,
            $paidOn,
            $reason,
            $request,
        ): RiderPayoutBatch {
            $batch = $this->lockedOwnedBatch($profile, $batchId);

            if ($batch->status === 'paid') {
                throw new ConflictHttpException(
                    'This payout batch has already been recorded as paid.'
                );
            }

            if ($batch->status !== 'approved') {
                throw new ConflictHttpException(
                    'Review and approve the payout batch before recording an external payment.'
                );
            }

            $items = RiderPayoutBatchItem::query()
                ->where('rider_payout_batch_id', $batch->id)
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw new ConflictHttpException(
                    'This payout batch has no earnings to pay.'
                );
            }

            $earnings = RiderEarning::query()
                ->whereIn('id', $items->pluck('rider_earning_id')->all())
                ->with(['shippingSettlement', 'pickupAssignment.pickupRequest', 'parcel.shipment', 'riderProfile', 'linehaulRun', 'shift'])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $totalMinor = 0;

            foreach ($items as $item) {
                $earning = $earnings->get($item->rider_earning_id);

                if (
                    ! $earning
                    || (int) $earning->logistics_profile_id !== (int) $profile->id
                    || (int) $earning->rider_profile_id !== (int) $batch->rider_profile_id
                    || $earning->status !== 'posted'
                    || $earning->paid_at !== null
                    || (int) $earning->amount_minor !== (int) $item->amount_minor
                    || (string) $earning->type !== (string) $item->earning_type
                    || ! $this->earningPassesPayoutGate($earning)
                ) {
                    throw new ConflictHttpException(
                        'One or more earnings in this batch are no longer payout-eligible.'
                    );
                }

                if (RiderEarningPayout::query()->where('rider_earning_id', $earning->id)->exists()) {
                    throw new ConflictHttpException(
                        'A payout has already been recorded for an earning in this batch.'
                    );
                }

                $totalMinor += (int) $item->amount_minor;
            }

            if ($totalMinor !== (int) $batch->total_minor || $totalMinor < 1) {
                throw new ConflictHttpException(
                    'The payout batch total no longer matches its earnings.'
                );
            }

            $payoutReference = $method === 'cash'
                ? 'LRP-'.Str::upper((string) Str::ulid())
                : trim($reference);
            $paidDate = $paidOn;

            foreach ($items as $item) {
                $earning = $earnings->get($item->rider_earning_id);

                RiderEarningPayout::query()->create([
                    'rider_earning_id' => $earning->id,
                    'logistics_profile_id' => $profile->id,
                    'amount_minor' => (int) $item->amount_minor,
                    'method' => $method,
                    'reference' => $payoutReference,
                    'paid_on' => $paidDate,
                    'recorded_by' => $logisticsUser->id,
                    'reason' => trim($reason),
                ]);

                $earning->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);

                $riderUserId = (int) $earning->riderProfile()->value('user_id');
                $logisticsUserId = (int) $profile->user_id;
                $earningId = (int) $earning->id;
                DB::afterCommit(static function () use ($riderUserId, $logisticsUserId, $earningId): void {
                    if ($riderUserId > 0 && $logisticsUserId > 0) {
                        RiderShippingEarningUpdated::dispatch(
                            $riderUserId,
                            $logisticsUserId,
                            $earningId,
                            'paid',
                            now()->toIso8601String(),
                        );
                    }
                });
            }

            $batch->update([
                'status' => 'paid',
                'method' => $method,
                'reference' => $payoutReference,
                'paid_on' => $paidDate,
                'recorded_by' => $logisticsUser->id,
                'reason' => trim($reason),
            ]);

            $this->audit->record(
                action: 'rider_payout_batch.externally_paid',
                module: 'Logistics',
                subject: $batch,
                description: "External Rider payment recorded for batch {$batch->batch_no}.",
                oldValues: ['status' => 'approved'],
                newValues: [
                    'status' => 'paid',
                    'method' => $method,
                    'reference' => $payoutReference,
                    'paid_on' => $paidDate,
                    'reason' => trim($reason),
                    'total_minor' => $totalMinor,
                    'earning_ids' => $items->pluck('rider_earning_id')->map(fn ($id): int => (int) $id)->all(),
                    'recorded_by' => (int) $logisticsUser->id,
                ],
                severity: 'warning',
                request: $request,
            );

            return $batch->fresh([
                'items.riderEarning',
                'riderProfile.user',
                'recorder',
            ]);
        }, attempts: 3);
    }

    /** @return Collection<int, RiderPayoutBatch> */
    public function batchesFor(User $logisticsUser): Collection
    {
        $profile = $this->logisticsProfile($logisticsUser);

        return RiderPayoutBatch::query()
            ->where('logistics_profile_id', $profile->id)
            ->with([
                'riderProfile.user',
                'items.riderEarning.parcel',
                'items.riderEarning.shipment',
                'items.riderEarning.linehaulRun',
                'items.riderEarning.shift',
                'reviewer',
                'approver',
                'recorder',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    public function cashBatchForLogistics(User $logisticsUser, int $batchId): RiderPayoutBatch
    {
        $profile = $this->logisticsProfile($logisticsUser);

        return RiderPayoutBatch::query()
            ->whereKey($batchId)
            ->where('logistics_profile_id', $profile->id)
            ->where('status', 'paid')
            ->where('method', 'cash')
            ->where('reference', 'like', 'LRP-%')
            ->with([
                'riderProfile.user',
                'logisticsProfile',
                'recorder',
                'items.riderEarning.parcel',
                'items.riderEarning.shipment',
                'items.riderEarning.linehaulRun',
                'items.riderEarning.shift',
            ])
            ->firstOrFail();
    }

    private function lockedOwnedBatch(LogisticsProfile $profile, int $batchId): RiderPayoutBatch
    {
        return RiderPayoutBatch::query()
            ->whereKey($batchId)
            ->where('logistics_profile_id', $profile->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertBatchEarningsStillEligible(RiderPayoutBatch $batch): void
    {
        $items = $batch->items()->orderBy('id')->lockForUpdate()->get();

        if ($items->isEmpty()) {
            throw new ConflictHttpException('This payout batch has no earnings to review.');
        }

        $earnings = RiderEarning::query()
            ->whereIn('id', $items->pluck('rider_earning_id')->all())
            ->with(['shippingSettlement', 'pickupAssignment.pickupRequest', 'parcel.shipment', 'riderProfile', 'linehaulRun', 'shift'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($items as $item) {
            /** @var RiderEarning|null $earning */
            $earning = $earnings->get((int) $item->rider_earning_id);

            if (
                ! $earning
                || (int) $earning->logistics_profile_id !== (int) $batch->logistics_profile_id
                || (int) $earning->rider_profile_id !== (int) $batch->rider_profile_id
                || $earning->status !== 'posted'
                || $earning->paid_at !== null
                || (int) $earning->amount_minor !== (int) $item->amount_minor
                || (string) $earning->type !== (string) $item->earning_type
                || RiderEarningPayout::query()->where('rider_earning_id', $earning->id)->exists()
                || ! $this->earningPassesPayoutGate($earning)
            ) {
                throw new ConflictHttpException(
                    'One or more earnings in this batch are no longer payout-eligible.'
                );
            }
        }
    }

    private function pendingEligibleEarningsQuery(): Builder
    {
        return RiderEarning::query()
            ->where('status', 'posted')
            ->whereNull('paid_at')
            ->whereDoesntHave('payout')
            ->whereDoesntHave('payoutBatchItem')
            ->whereIn('type', [
                config('bearly_rider_compensation.pickup_earning_type', 'pickup_parcel'),
                'shipping_delivery',
                'return_shipping_delivery',
                config('bearly_rider_compensation.courier_daily_earning_type', 'courier_daily'),
            ])
            ->with([
                'shippingSettlement',
                'pickupAssignment.pickupRequest',
                'parcel.shipment',
                'riderProfile',
                'linehaulRun',
                'shift',
            ]);
    }

    private function earningPassesPayoutGate(RiderEarning $earning): bool
    {
        $pickupType = config('bearly_rider_compensation.pickup_earning_type', 'pickup_parcel');

        if ($earning->type === $pickupType) {
            $assignment = $earning->pickupAssignment;
            $pickup = $assignment?->pickupRequest;
            $parcel = $earning->parcel;
            $shipment = $parcel?->shipment;

            return $earning->shipping_settlement_id === null
                && $earning->pickup_assignment_id !== null
                && $earning->parcel_id !== null
                && $assignment !== null
                && (int) $assignment->rider_profile_id === (int) $earning->rider_profile_id
                && in_array($assignment->status, ['picked_up', 'completed'], true)
                && $pickup !== null
                && (int) $pickup->logistics_profile_id === (int) $earning->logistics_profile_id
                && $parcel !== null
                && $shipment !== null
                && $shipment->purpose === 'outbound'
                && (int) $shipment->logistics_profile_id === (int) $earning->logistics_profile_id
                && $earning->rate_minor !== null
                && (int) $earning->rate_minor === (int) $earning->amount_minor;
        }

        if ($earning->type === config('bearly_rider_compensation.courier_daily_earning_type', 'courier_daily')) {
            $run = $earning->linehaulRun;
            $shift = $earning->shift;
            $rider = $earning->riderProfile;

            if (! $run || ! $shift || ! $rider
                || $earning->shipping_settlement_id !== null
                || $earning->pickup_assignment_id !== null
                || $earning->parcel_id !== null
                || $earning->linehaul_run_id === null
                || $earning->work_date === null
                || (int) $rider->logistics_profile_id !== (int) $earning->logistics_profile_id
                || (int) $run->courier_rider_profile_id !== (int) $earning->rider_profile_id
                || (int) $shift->rider_profile_id !== (int) $earning->rider_profile_id
                || (int) $run->origin_logistics_profile_id !== (int) $earning->logistics_profile_id
                || $run->status !== 'completed'
                || $run->completed_at === null
                || $shift->checked_out_at === null
                || $shift->checked_in_at === null
                || $run->assigned_at === null
                || $run->assigned_at->lt($shift->checked_in_at)
                || $run->assigned_at->gt($shift->checked_out_at)
                || $earning->work_date->toDateString() !== $this->courierDaily->workDateFor($shift)
                || $earning->rate_minor === null
                || (int) $earning->rate_minor !== (int) $earning->amount_minor
                || ! $this->courierDaily->runHasAcceptedManifest($run)) {
                return false;
            }

            return true;
        }

        if (! in_array($earning->type, ['shipping_delivery', 'return_shipping_delivery'], true)) {
            return false;
        }

        $settlement = $earning->shippingSettlement;

        return $settlement !== null
            && $settlement->status === 'remitted'
            && $settlement->remitted_at !== null
            && (int) $settlement->rider_profile_id === (int) $earning->rider_profile_id
            && (int) $settlement->logistics_profile_id === (int) $earning->logistics_profile_id
            && (int) $settlement->rider_share_minor === (int) $earning->amount_minor
            && (string) $settlement->settlement_type === ($earning->type === 'return_shipping_delivery' ? 'return' : 'outbound');
    }

    private function assertAuthenticatedActor(User $logisticsUser, Request $request): void
    {
        if ((int) $request->user()?->id !== (int) $logisticsUser->id) {
            throw new AuthorizationException('The Logistics audit actor does not match the authenticated user.');
        }
    }

    private function assertRiderOwned(LogisticsProfile $profile, RiderProfile $rider): void
    {
        if ((int) $rider->logistics_profile_id !== (int) $profile->id) {
            throw new AuthorizationException(
                'This Rider does not belong to your Logistics provider.'
            );
        }
    }

    private function validatePaymentDetails(string $method, string $reference, string $paidOn, string $reason): void
    {
        if (! in_array($method, ['bank_transfer', 'gcash', 'cash', 'other'], true)) {
            throw ValidationException::withMessages([
                'method' => 'Choose a supported payout method.',
            ]);
        }

        if ($method !== 'cash' && trim($reference) === '') {
            throw ValidationException::withMessages([
                'reference' => 'Enter the external payout reference.',
            ]);
        }

        if (trim($reason) === '' || mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'Record a short reason for the external payout.',
            ]);
        }

        try {
            $date = \Illuminate\Support\Carbon::createFromFormat('!Y-m-d', $paidOn, config('app.timezone'));
        } catch (\Throwable) {
            $date = null;
        }

        if (! $date || $date->toDateString() !== $paidOn || $date->toDateString() > now()->toDateString()) {
            throw ValidationException::withMessages([
                'paid_on' => 'Record a valid payout date that is not in the future.',
            ]);
        }
    }

    private function nextBatchNumber(): string
    {
        do {
            $number = 'RPB-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (RiderPayoutBatch::query()->where('batch_no', $number)->exists());

        return $number;
    }

    private function logisticsProfile(User $user): LogisticsProfile
    {
        if ($user->role !== UserRole::Logistics->value || $user->status !== AccountStatus::Active->value) {
            throw new AuthorizationException('An active Logistics account is required.');
        }

        $profile = $user->logisticsProfile()->first();

        if (! $profile) {
            throw new AuthorizationException(
                'The authenticated user has no Logistics profile.'
            );
        }

        return $profile;
    }
}
