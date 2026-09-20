<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountApprovalController extends Controller
{
    public function approveByAdmin(Request $request, User $user): RedirectResponse
    {
        abort_unless(in_array($user->role, UserRole::adminApproved(), true), 422);
        abort_unless(in_array($user->status, [AccountStatus::Pending->value, AccountStatus::NeedsRevision->value], true), 422);

        $this->approve($user, $request->user());

        return back()->with('success', "{$user->name}'s {$user->role} account was approved.");
    }

    public function rejectByAdmin(Request $request, User $user): RedirectResponse
    {
        abort_unless(in_array($user->role, UserRole::adminApproved(), true), 422);
        abort_unless(in_array($user->status, [AccountStatus::Pending->value, AccountStatus::NeedsRevision->value], true), 422);

        $this->reject($request, $user);

        return back()->with('success', "{$user->name}'s application was rejected.");
    }

    public function approveRider(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === UserRole::Rider->value, 422);
        abort_unless($user->logistics_id === $request->user()->id, 403);
        abort_unless(in_array($user->status, [AccountStatus::Pending->value, AccountStatus::NeedsRevision->value], true), 422);

        $this->approve($user, $request->user());

        return back()->with('success', "{$user->name}'s Rider account was approved.");
    }

    public function rejectRider(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === UserRole::Rider->value, 422);
        abort_unless($user->logistics_id === $request->user()->id, 403);
        abort_unless(in_array($user->status, [AccountStatus::Pending->value, AccountStatus::NeedsRevision->value], true), 422);

        $this->reject($request, $user);

        return back()->with('success', "{$user->name}'s Rider application was rejected.");
    }

    private function approve(User $application, User $approver): void
    {
        $application->forceFill([
            'status' => AccountStatus::Active->value,
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ])->save();
    }

    private function reject(Request $request, User $application): void
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $application->forceFill([
            'status' => AccountStatus::Rejected->value,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'rejection_reason' => $validated['reason'],
        ])->save();
    }
}
