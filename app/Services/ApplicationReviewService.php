<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\User;
use Illuminate\Http\Request;

class ApplicationReviewService
{
    public const SESSION_KEY = 'application_review_user_id';

    public function remember(Request $request, User $user): void
    {
        $request->session()->put(self::SESSION_KEY, (int) $user->id);
    }

    /**
     * Resolve the review state only from the server-side registration/session
     * identity. Never accept an email, user ID, or application ID from input.
     */
    public function forSession(Request $request): ?array
    {
        $userId = (int) $request->session()->get(self::SESSION_KEY, 0);

        if ($userId < 1) {
            return null;
        }

        $user = User::withTrashed()->find($userId);

        return $user ? $this->forUser($user) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $application = AccountApplication::query()
            ->where('user_id', $user->id)
            ->with(['requestedRole', 'sponsorLogisticsProfile'])
            ->latest('submitted_at')
            ->latest('id')
            ->first();

        $applicationStatus = (string) ($application?->status ?? '');
        $userStatus = (string) $user->status;
        $role = (string) ($application?->requestedRole?->name ?: $user->role);
        $state = $this->resolveState($user, $userStatus, $applicationStatus);

        $companyName = $application?->business_name ?: ($user->business_name ?: null);
        $authority = $role === UserRole::Rider->value
            ? ($application?->sponsorLogisticsProfile?->display_name
                ?: 'Selected Logistics provider')
            : 'Bearly Administrator';
        $statusLabel = match ($state) {
            'approved' => 'Approved',
            'rejected' => 'Not approved',
            'needs_revision' => 'Needs revision',
            'under_review' => 'Under review',
            'unavailable' => 'Account unavailable',
            default => 'Pending review',
        };

        return [
            'role' => $role,
            'role_label' => $this->roleLabel($role),
            'name' => (string) ($user->name ?: $this->fallbackName($user)),
            'company' => $companyName,
            'application_no' => $application?->application_no,
            'submitted_at' => $application?->submitted_at?->format('M j, Y'),
            'authority' => $authority,
            'state' => $state,
            'status_label' => $statusLabel,
            'status_raw' => $applicationStatus,
            'final' => in_array($state, ['approved', 'rejected', 'unavailable'], true),
            'can_sign_in' => $state === 'approved',
            'heading' => match ($state) {
                'approved' => 'Account Verified',
                'rejected' => 'Application not approved',
                'needs_revision' => 'Your application needs an update',
                'under_review' => 'Your application is being reviewed',
                'unavailable' => 'Account unavailable',
                default => 'Application received',
            },
            'message' => match ($state) {
                'approved' => 'Your '.strtolower($this->roleLabel($role)).' account is active. Sign in to continue to your dashboard.',
                'rejected' => 'Bearly was unable to approve this application. Review the decision note below or contact Support.',
                'needs_revision' => 'Bearly reviewed your application and needs a correction before it can be approved. Check the note below and update your details.',
                'under_review' => 'Your documents are with '.$authority.'. This page will update when the review is complete.',
                'unavailable' => 'This account can no longer access the pending application page. Contact Bearly Support if you need help.',
                default => 'Your application was submitted successfully. '.$authority.' will review your details before dashboard access is activated.',
            },
            'review_note' => match ($state) {
                'rejected', 'needs_revision' => trim((string) ($application?->revision_notes
                    ?: $application?->decision_reason)) ?: null,
                default => null,
            },
        ];
    }

    private function resolveState(User $user, string $userStatus, string $applicationStatus): string
    {
        if (
            $userStatus === AccountStatus::Active->value
            && $applicationStatus === 'approved'
        ) {
            return 'approved';
        }

        if (
            $userStatus === AccountStatus::Rejected->value
            || $applicationStatus === 'rejected'
        ) {
            return 'rejected';
        }

        if (
            $userStatus === AccountStatus::NeedsRevision->value
            || $applicationStatus === 'needs_revision'
        ) {
            return 'needs_revision';
        }

        if (
            in_array($userStatus, [
                AccountStatus::Suspended->value,
                AccountStatus::Deactivated->value,
                AccountStatus::Banned->value,
            ], true)
            || $user->trashed()
        ) {
            return 'unavailable';
        }

        if ($applicationStatus === 'under_review') {
            return 'under_review';
        }

        return 'pending';
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            UserRole::Buyer->value => 'Buyer',
            UserRole::Seller->value => 'Seller',
            UserRole::Logistics->value => 'Logistics',
            UserRole::Rider->value => 'Rider',
            UserRole::Admin->value => 'Administrator',
            default => 'Bearly',
        };
    }

    private function fallbackName(User $user): string
    {
        return trim(implode(' ', array_filter([
            $user->first_name,
            $user->middle_initial,
            $user->last_name,
        ]))) ?: 'Applicant';
    }
}
