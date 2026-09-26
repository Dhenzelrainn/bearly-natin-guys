<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\AuditLog;
use App\Models\LogisticsProfile;
use App\Models\SellerProfile;
use App\Models\Store;
use App\Models\User;
use App\Notifications\ApplicationDecisionNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountApplicationReviewService
{
    public function approve(AccountApplication $application, User $reviewer, ?string $reason = null): AccountApplication
    {
        return DB::transaction(function () use ($application, $reviewer, $reason): AccountApplication {
            $application = $this->lockApplication($application);
            $user = User::query()->lockForUpdate()->findOrFail($application->user_id);
            $alreadyApproved = $application->status === 'approved';

            abort_unless($alreadyApproved || in_array($application->status, $this->reviewableStatuses(), true), 422);
            $this->ensureAdminOwnedRole($application);
            $this->ensureRequiredDocumentsAreVerified($application);

            $role = $application->requestedRole;
            $user->roles()->syncWithoutDetaching([
                $role->id => ['assigned_by' => $reviewer->id, 'assigned_at' => now()],
            ]);

            $this->provisionRoleProfile($application, $user);

            $application->forceFill([
                'status' => 'approved',
                'review_started_at' => $application->review_started_at ?? now(),
                'decided_at' => $application->decided_at ?? now(),
                'reviewed_by' => $reviewer->id,
                'decision_reason' => $reason,
                'revision_notes' => null,
            ])->save();

            $user->forceFill([
                'role' => $role->name,
                'status' => AccountStatus::Active->value,
                'approved_by' => $reviewer->id,
                'approved_at' => $user->approved_at ?? now(),
                'rejection_reason' => null,
            ])->save();

            if (! $alreadyApproved) {
                $this->recordDecision($application, $reviewer, 'approved', $reason);
                $user->notify(new ApplicationDecisionNotification($application, 'approved', $reason));
            }

            return $application->fresh(['user', 'requestedRole', 'documents']);
        }, 3);
    }

    public function reject(AccountApplication $application, User $reviewer, string $reason): AccountApplication
    {
        return $this->decideWithoutProvisioning($application, $reviewer, 'rejected', $reason);
    }

    public function requestRevision(AccountApplication $application, User $reviewer, string $notes): AccountApplication
    {
        return DB::transaction(function () use ($application, $reviewer, $notes): AccountApplication {
            $application = $this->lockApplication($application);
            $user = User::query()->lockForUpdate()->findOrFail($application->user_id);

            abort_unless(in_array($application->status, ['submitted', 'under_review'], true), 422);
            $this->ensureAdminOwnedRole($application);

            $application->forceFill([
                'status' => 'needs_revision',
                'review_started_at' => $application->review_started_at ?? now(),
                'reviewed_by' => $reviewer->id,
                'revision_notes' => $notes,
                'decision_reason' => null,
                'decided_at' => null,
            ])->save();

            $user->forceFill([
                'status' => AccountStatus::NeedsRevision->value,
                'approved_by' => $reviewer->id,
                'approved_at' => null,
                'rejection_reason' => null,
            ])->save();

            $this->recordDecision($application, $reviewer, 'needs_revision', $notes);
            $user->notify(new ApplicationDecisionNotification($application, 'needs_revision', $notes));

            return $application->fresh(['user', 'requestedRole', 'documents']);
        }, 3);
    }

    private function decideWithoutProvisioning(
        AccountApplication $application,
        User $reviewer,
        string $decision,
        string $reason,
    ): AccountApplication {
        return DB::transaction(function () use ($application, $reviewer, $decision, $reason): AccountApplication {
            $application = $this->lockApplication($application);
            $user = User::query()->lockForUpdate()->findOrFail($application->user_id);

            abort_unless(in_array($application->status, $this->reviewableStatuses(), true), 422);
            $this->ensureAdminOwnedRole($application);

            $application->forceFill([
                'status' => $decision,
                'review_started_at' => $application->review_started_at ?? now(),
                'decided_at' => now(),
                'reviewed_by' => $reviewer->id,
                'decision_reason' => $reason,
                'revision_notes' => null,
            ])->save();

            $user->forceFill([
                'status' => AccountStatus::Rejected->value,
                'approved_by' => $reviewer->id,
                'approved_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $this->recordDecision($application, $reviewer, $decision, $reason);
            $user->notify(new ApplicationDecisionNotification($application, $decision, $reason));

            return $application->fresh(['user', 'requestedRole', 'documents']);
        }, 3);
    }

    private function lockApplication(AccountApplication $application): AccountApplication
    {
        return AccountApplication::query()
            ->with(['requestedRole', 'documents'])
            ->lockForUpdate()
            ->findOrFail($application->id);
    }

    private function ensureAdminOwnedRole(AccountApplication $application): void
    {
        abort_unless(in_array($application->requestedRole->name, UserRole::adminApproved(), true), 422);
    }

    private function ensureRequiredDocumentsAreVerified(AccountApplication $application): void
    {
        $required = match ($application->requestedRole->name) {
            UserRole::Buyer->value => ['government_id'],
            UserRole::Seller->value, UserRole::Logistics->value => ['government_id', 'business_permit'],
            default => [],
        };

        $documents = $application->documents->keyBy('document_type');
        $unverified = collect($required)->filter(fn (string $type) =>
            ! $documents->has($type) || $documents->get($type)->verification_status !== 'verified'
        );

        if ($unverified->isNotEmpty()) {
            throw ValidationException::withMessages([
                'documents' => 'All required documents must be verified before approval.',
            ]);
        }
    }

    private function provisionRoleProfile(AccountApplication $application, User $user): void
    {
        if ($application->requestedRole->name === UserRole::Seller->value) {
            $profile = SellerProfile::withTrashed()->firstOrNew(['user_id' => $user->id]);
            $profile->fill([
                'application_id' => $application->id,
                'legal_business_name' => $application->business_name ?: $user->business_name ?: $user->name,
                'approved_category_id' => $application->business_category_id,
                'approved_at' => $profile->approved_at ?? now(),
            ]);
            $profile->save();
            if ($profile->trashed()) {
                $profile->restore();
            }

            $store = Store::withTrashed()->firstOrNew(['seller_profile_id' => $profile->id]);
            $store->fill([
                'name' => $application->business_name ?: $user->business_name ?: $user->name,
                'slug' => $store->slug ?: Str::slug($application->business_name ?: $user->business_name ?: $user->name).'-'.$profile->id,
                'contact_email' => $user->email,
                'contact_phone' => $user->contact_number,
                'publication_status' => $store->publication_status ?: 'draft',
            ]);
            $store->save();
            if ($store->trashed()) {
                $store->restore();
            }
        }

        if ($application->requestedRole->name === UserRole::Logistics->value) {
            $profile = LogisticsProfile::withTrashed()->firstOrNew(['user_id' => $user->id]);
            $name = $application->business_name ?: $user->business_name ?: $user->name;
            $profile->fill([
                'application_id' => $application->id,
                'legal_name' => $name,
                'display_name' => $name,
                'contact_phone' => $user->contact_number,
                'status' => 'active',
            ]);
            $profile->save();
            if ($profile->trashed()) {
                $profile->restore();
            }
        }
    }

    private function recordDecision(
        AccountApplication $application,
        User $reviewer,
        string $decision,
        ?string $reason,
    ): void {
        AuditLog::query()->create([
            'actor_user_id' => $reviewer->id,
            'action' => 'application.'.$decision,
            'module' => 'registrations',
            'auditable_type' => AccountApplication::class,
            'auditable_id' => $application->id,
            'description' => "Application {$application->application_no} was {$decision}.",
            'new_values' => ['status' => $decision, 'reason' => $reason],
            'severity' => $decision === 'rejected' ? 'warning' : 'info',
            'created_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function reviewableStatuses(): array
    {
        return ['submitted', 'under_review', 'needs_revision'];
    }
}
