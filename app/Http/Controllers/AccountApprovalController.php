<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\User;
use App\Services\AccountApplicationReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountApprovalController extends Controller
{
    public function approveByAdmin(
        Request $request,
        AccountApplication $application,
        AccountApplicationReviewService $review,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $application = $review->approve($application, $request->user(), $validated['reason'] ?? null);
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->with('review_application_id', $application->id);
        }

        return back()->with('success', "{$application->user->name}'s application was approved.");
    }

    public function rejectByAdmin(
        Request $request,
        AccountApplication $application,
        AccountApplicationReviewService $review,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $application = $review->reject($application, $request->user(), $validated['reason']);

        return back()->with('success', "{$application->user->name}'s application was rejected.");
    }

    public function requestRevisionByAdmin(
        Request $request,
        AccountApplication $application,
        AccountApplicationReviewService $review,
    ): RedirectResponse {
        $validated = $request->validate([
            'revision_notes' => ['required', 'string', 'max:1000'],
        ]);

        $application = $review->requestRevision($application, $request->user(), $validated['revision_notes']);

        return back()->with('success', "Revision was requested from {$application->user->name}.");
    }

    public function approveRider(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === UserRole::Rider->value, 422);
        abort_unless($user->logistics_id === $request->user()->id, 403);
        abort_unless(in_array($user->status, [AccountStatus::Pending->value, AccountStatus::NeedsRevision->value], true), 422);

        $this->approveLegacyRider($user, $request->user());

        return back()->with('success', "{$user->name}'s Rider account was approved.");
    }

    public function rejectRider(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === UserRole::Rider->value, 422);
        abort_unless($user->logistics_id === $request->user()->id, 403);
        abort_unless(in_array($user->status, [AccountStatus::Pending->value, AccountStatus::NeedsRevision->value], true), 422);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $user->forceFill([
            'status' => AccountStatus::Rejected->value,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'rejection_reason' => $validated['reason'],
        ])->save();

        return back()->with('success', "{$user->name}'s Rider application was rejected.");
    }

    private function approveLegacyRider(User $application, User $approver): void
    {
        $application->forceFill([
            'status' => AccountStatus::Active->value,
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ])->save();
    }
}
