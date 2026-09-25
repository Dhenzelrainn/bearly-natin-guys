<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AccountStatusChangedNotification;
use Illuminate\Support\Facades\DB;

class UserAccountStatusService
{
    public function change(User $user, User $actor, string $targetStatus, string $reason): User
    {
        return DB::transaction(function () use ($user, $actor, $targetStatus, $reason): User {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $fromStatus = $user->status;

            abort_unless(in_array($user->role, $this->managedRoles(), true), 422);
            abort_unless(array_key_exists($fromStatus, $this->transitions()), 422);
            abort_unless(in_array($targetStatus, $this->transitions()[$fromStatus], true), 422);

            $user->forceFill([
                'status' => $targetStatus,
                'suspended_at' => $targetStatus === AccountStatus::Suspended->value ? now() : null,
                'deactivated_at' => $targetStatus === AccountStatus::Deactivated->value ? now() : null,
            ])->save();

            AuditLog::query()->create([
                'actor_user_id' => $actor->id,
                'action' => 'user.status_changed',
                'module' => 'user_management',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'description' => "{$user->name}'s account status changed from {$fromStatus} to {$targetStatus}.",
                'old_values' => ['status' => $fromStatus],
                'new_values' => ['status' => $targetStatus, 'reason' => $reason],
                'severity' => $targetStatus === AccountStatus::Active->value ? 'info' : 'warning',
                'created_at' => now(),
            ]);

            $user->notify(new AccountStatusChangedNotification($fromStatus, $targetStatus, $reason));

            return $user->fresh();
        }, 3);
    }

    /** @return array<string, list<string>> */
    public function transitions(): array
    {
        return [
            AccountStatus::Active->value => [
                AccountStatus::Suspended->value,
                AccountStatus::Deactivated->value,
            ],
            AccountStatus::Suspended->value => [
                AccountStatus::Active->value,
                AccountStatus::Deactivated->value,
            ],
            AccountStatus::Deactivated->value => [
                AccountStatus::Active->value,
            ],
        ];
    }

    /** @return list<string> */
    public function managedRoles(): array
    {
        return [
            UserRole::Buyer->value,
            UserRole::Seller->value,
            UserRole::Logistics->value,
            UserRole::Rider->value,
        ];
    }
}
