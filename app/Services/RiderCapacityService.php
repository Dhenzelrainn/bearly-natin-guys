<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\RiderWorkType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\LogisticsProfile;
use App\Models\RiderProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RiderCapacityService
{
    public const MAX_CAPACITY = 1000;

    public function update(
        RiderProfile $rider,
        LogisticsProfile $provider,
        User $actor,
        int $capacity,
    ): RiderProfile {
        if ($capacity < 1 || $capacity > self::MAX_CAPACITY) {
            throw ValidationException::withMessages([
                'parcel_capacity' => 'Parcel capacity must be between 1 and 1000 parcels.',
            ]);
        }

        return DB::transaction(function () use (
            $rider,
            $provider,
            $actor,
            $capacity,
        ): RiderProfile {
            $lockedActor = User::query()
                ->whereKey($actor->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedProvider = LogisticsProfile::query()
                ->whereKey($provider->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActiveProvider($lockedProvider, $lockedActor);

            $lockedRider = RiderProfile::query()
                ->whereKey($rider->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedUser = User::query()
                ->whereKey($lockedRider->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedRider->logistics_profile_id !== (int) $lockedProvider->id) {
                throw new AuthorizationException(
                    'Only the owning Logistics provider can update this Rider capacity.'
                );
            }

            if ($lockedUser->role !== UserRole::Rider->value
                || $lockedUser->status !== AccountStatus::Active->value
                || $lockedRider->verification_status !== 'approved') {
                throw new ConflictHttpException(
                    'Only an active, approved Rider can receive a parcel capacity.'
                );
            }

            if (! RiderWorkType::isLocal($lockedRider->work_type)) {
                throw new ConflictHttpException(
                    'Courier capacity is managed through the verified line-haul workflow.'
                );
            }

            $activeLoad = $this->activeParcelLoad($lockedRider->id);

            if ($capacity < $activeLoad) {
                throw ValidationException::withMessages([
                    'parcel_capacity' => "Capacity cannot be lower than the Rider's current active load of {$activeLoad} parcels.",
                ]);
            }

            $oldCapacity = $lockedRider->parcel_capacity === null
                ? null
                : (int) $lockedRider->parcel_capacity;

            if ($oldCapacity === $capacity) {
                return $lockedRider->fresh(['user']);
            }

            $lockedRider->forceFill([
                'parcel_capacity' => $capacity,
            ])->save();

            AuditLog::query()->create([
                'actor_user_id' => $lockedActor->id,
                'action' => 'rider.parcel_capacity_updated',
                'module' => 'rider_management',
                'auditable_type' => RiderProfile::class,
                'auditable_id' => $lockedRider->id,
                'description' => "{$lockedUser->name}'s local Rider parcel capacity was updated by Logistics.",
                'old_values' => [
                    'parcel_capacity' => $oldCapacity,
                    'active_parcel_load' => $activeLoad,
                ],
                'new_values' => [
                    'parcel_capacity' => $capacity,
                    'active_parcel_load' => $activeLoad,
                ],
                'severity' => 'info',
                'created_at' => now(),
            ]);

            return $lockedRider->fresh(['user']);
        }, attempts: 3);
    }

    private function assertActiveProvider(
        LogisticsProfile $provider,
        User $actor,
    ): void {
        if ($actor->id !== $provider->user_id
            || $actor->role !== UserRole::Logistics->value
            || $actor->status !== AccountStatus::Active->value
            || $provider->status !== AccountStatus::Active->value) {
            throw new AuthorizationException(
                'An active owning Logistics account is required to update Rider capacity.'
            );
        }
    }

    private function activeParcelLoad(int $riderProfileId): int
    {
        return (int) DB::table('dispatch_batch_parcels')
            ->join(
                'dispatch_batches',
                'dispatch_batches.id',
                '=',
                'dispatch_batch_parcels.dispatch_batch_id',
            )
            ->where('dispatch_batches.rider_profile_id', $riderProfileId)
            ->whereNotIn('dispatch_batches.status', [
                'completed',
                'cancelled',
            ])
            ->count('dispatch_batch_parcels.parcel_id');
    }
}
