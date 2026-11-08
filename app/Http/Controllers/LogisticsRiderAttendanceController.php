<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\RiderProfile;
use App\Models\User;
use App\Services\RiderAttendanceQrService;
use App\Services\RiderAttendanceRosterService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LogisticsRiderAttendanceController extends Controller
{
    public function index(
        Request $request,
        RiderAttendanceRosterService $attendanceRoster,
    ): View {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $profile = $user->logisticsProfile;

        if ($user->status !== AccountStatus::Active->value
            || ! $user->hasRole(UserRole::Logistics->value)
            || $profile?->status !== AccountStatus::Active->value) {
            throw new AuthorizationException('An active Logistics profile is required.');
        }

        return view('logistics.rider-attendance.index', $this->layoutData($user) + [
            'attendance' => $attendanceRoster->forAttendancePage($profile),
        ]);
    }

    public function markAbsent(
        Request $request,
        RiderProfile $rider,
        RiderAttendanceRosterService $attendanceRoster,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $absence = $attendanceRoster->markAbsent($user, $rider);

        return redirect()
            ->route('logistics.rider-attendance.index')
            ->with('success', ($rider->user?->name ?: 'Rider').' is marked absent for '.$absence->work_date->format('M j, Y').'.');
    }

    public function issueChallenge(
        Request $request,
        RiderAttendanceQrService $attendanceQr,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return response()->json([
            'data' => $attendanceQr->issueChallenge($user),
            'message' => 'A fresh Rider attendance QR is ready.',
        ])->header('Cache-Control', 'private, no-store');
    }

    /** @return array<string, mixed> */
    private function layoutData(User $user): array
    {
        $profile = $user->logisticsProfile;
        $facility = $profile?->sortingCenters()
            ->where('status', 'active')
            ->orderBy('id')
            ->first();
        $pendingRiderCount = $profile === null ? 0 : AccountApplication::query()
            ->where('sponsor_logistics_profile_id', $profile->id)
            ->whereHas('requestedRole', fn ($query) => $query->where('name', UserRole::Rider->value))
            ->whereIn('status', ['submitted', 'under_review', 'needs_revision'])
            ->count();

        $name = $user->name ?: 'Bearly Logistics';
        $initials = collect(preg_split('/\s+/', trim($name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part) => strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        return [
            'operator' => [
                'name' => $name,
                'initials' => $initials ?: 'BL',
                'email' => $user->email ?: '',
                'contact' => $user->contact_number ?: $user->phone ?: '',
                'role' => 'Logistics Operator',
                'business_name' => $facility?->name ?: $profile?->display_name ?: $name,
            ],
            'activeFacility' => [
                'id' => $facility?->id,
                'name' => $facility?->name ?: $profile?->display_name ?: $name,
                'code' => $facility?->code,
                'status' => $facility?->status,
            ],
            'pendingRiderCount' => $pendingRiderCount,
            'topNotifications' => $pendingRiderCount > 0 ? [[
                'title' => $pendingRiderCount.' rider application'.($pendingRiderCount === 1 ? '' : 's').' awaiting review',
                'time' => 'Current',
                'type' => 'warning',
            ]] : [],
        ];
    }
}
