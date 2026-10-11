<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Mail\ApplicationDecisionNotice;
use App\Models\User;
use App\Services\RegistrationLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class AccountApprovalController extends Controller
{
    public function approveByAdmin(
        Request $request,
        User $user,
        RegistrationLifecycleService $lifecycle
    ): RedirectResponse {
        $this->ensureAdminReviewable($user);

        $application = $lifecycle->approve($user, $request->user());
        $this->notifyApplicant($application->user, 'approved');

        return back()
            ->with('success', "{$user->name}'s {$user->role} account was approved.")
            ->with('review_application_id', $application->id);
    }

    public function requestRevisionByAdmin(
        Request $request,
        User $user,
        RegistrationLifecycleService $lifecycle
    ): RedirectResponse {
        $this->ensureAdminReviewable($user);

        $validated = $request->validate([
            'revision_notes' => ['required', 'string', 'max:1000'],
        ]);

        $application = $lifecycle->requestRevision(
            $user,
            $request->user(),
            $validated['revision_notes']
        );
        $this->notifyApplicant(
            $application->user,
            'needs_revision',
            $validated['revision_notes']
        );

        return back()
            ->with('success', "Revision was requested from {$user->name}.")
            ->with('review_application_id', $application->id);
    }

    public function rejectByAdmin(
        Request $request,
        User $user,
        RegistrationLifecycleService $lifecycle
    ): RedirectResponse {
        $this->ensureAdminReviewable($user);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $application = $lifecycle->reject(
            $user,
            $request->user(),
            $validated['reason']
        );
        $this->notifyApplicant(
            $application->user,
            'rejected',
            $validated['reason']
        );

        return back()
            ->with('success', "{$user->name}'s application was rejected.")
            ->with('review_application_id', $application->id);
    }

    public function approveRider(
        Request $request,
        User $user,
        RegistrationLifecycleService $lifecycle
    ): RedirectResponse {
        $this->ensureRiderReviewable($request, $user);

        $application = $lifecycle->approve($user, $request->user());
        $this->notifyApplicant($application->user, 'approved');

        return back()->with(
            'success',
            "{$user->name}'s Rider account was approved."
        );
    }

    public function rejectRider(
        Request $request,
        User $user,
        RegistrationLifecycleService $lifecycle
    ): RedirectResponse {
        $this->ensureRiderReviewable($request, $user);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $application = $lifecycle->reject(
            $user,
            $request->user(),
            $validated['reason']
        );
        $this->notifyApplicant(
            $application->user,
            'rejected',
            $validated['reason']
        );

        return back()->with(
            'success',
            "{$user->name}'s Rider application was rejected."
        );
    }

    private function ensureAdminReviewable(User $user): void
    {
        abort_unless(
            in_array($user->role, UserRole::adminApproved(), true),
            422
        );

        $this->ensurePending($user);
    }

    private function ensureRiderReviewable(Request $request, User $user): void
    {
        abort_unless($user->role === UserRole::Rider->value, 422);
        abort_unless($user->logistics_id === $request->user()->id, 403);

        $this->ensurePending($user);
    }

    private function ensurePending(User $user): void
    {
        abort_unless(
            in_array(
                $user->status,
                [
                    AccountStatus::Pending->value,
                    AccountStatus::NeedsRevision->value,
                ],
                true
            ),
            422
        );
    }

    private function notifyApplicant(
        User $user,
        string $decision,
        ?string $reason = null
    ): void {
        Mail::to($user->email)->queue(
            new ApplicationDecisionNotice(
                $user->name ?: trim($user->first_name.' '.$user->last_name),
                (string) $user->role,
                $decision,
                $reason
            )
        );
    }
}
