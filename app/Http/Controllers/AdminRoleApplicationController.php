<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AdminRoleApplicationController extends Controller
{
    public function buyers(): View
    {
        return $this->renderRoleQueue(
            role: UserRole::Buyer->value,
            view: 'admin.registrations.buyers'
        );
    }

    public function sellers(): View
    {
        return $this->renderRoleQueue(
            role: UserRole::Seller->value,
            view: 'admin.registrations.sellers'
        );
    }

    public function logistics(): View
    {
        return $this->renderRoleQueue(
            role: UserRole::Logistics->value,
            view: 'admin.registrations.logistics'
        );
    }

    public function document(User $user, string $type)
    {
        abort_unless(
            in_array($user->role, UserRole::adminApproved(), true),
            404
        );

        $path = match ($type) {
            'valid-id' => $user->valid_id_path,
            'business-permit' => $user->business_permit_path,
            default => null,
        };

        abort_unless(
            $path && Storage::disk('local')->exists($path),
            404,
            'Registration document not found.'
        );

        return response()->file(
            Storage::disk('local')->path($path),
            [
                'Content-Disposition' => 'inline; filename="'.basename($path).'"',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    private function renderRoleQueue(string $role, string $view): View
    {
        $applications = $this->pendingUsers($role)
            ->map(fn (User $user) => $this->toApplication($user))
            ->values()
            ->all();

        return view($view, [
            'admin' => $this->adminContext(),
            'topNotifications' => $this->notifications(),
            'applications' => $applications,
        ]);
    }

    private function pendingUsers(string $role): Collection
    {
        return User::query()
            ->where('role', $role)
            ->whereIn('status', [
                AccountStatus::Pending->value,
                AccountStatus::NeedsRevision->value,
            ])
            ->latest('created_at')
            ->get();
    }

    private function toApplication(User $user): array
    {
        $documents = [];

        if ($user->valid_id_path) {
            $documents[] = [
                'label' => 'Government ID',
                'type' => 'valid-id',
            ];
        }

        if ($user->business_permit_path) {
            $documents[] = [
                'label' => $user->role === UserRole::Logistics->value
                    ? 'Business / DTI Permit'
                    : 'Business Permit',
                'type' => 'business-permit',
            ];
        }

        return [
            'id' => 'DB-'.$user->id,
            'database_id' => $user->id,
            'first_name' => $user->first_name ?: '—',
            'middle_initial' => $user->middle_initial ?: '—',
            'last_name' => $user->last_name ?: '—',
            'name' => $user->name ?: trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
            'sex' => match ($user->sex) {
                'male' => 'Male',
                'female' => 'Female',
                'prefer_not_to_say' => 'Prefer not to say',
                default => $user->sex
                    ? ucwords(str_replace('_', ' ', $user->sex))
                    : '—',
            },
            'email' => $user->email,
            'contact' => $user->contact_number ?: '—',
            'birthday' => $user->birthday?->format('F j, Y') ?? '—',
            'age' => $user->birthday?->age ?? '—',
            'province' => $user->province ?: '—',
            'municipality' => $user->city ?: '—',
            'barangay' => $user->barangay ?: '—',
            'street_address' => $user->street_address ?: '—',
            'business_name' => $user->business_name ?: '—',
            'business_category' => $user->business_category ?: '—',
            'submitted' => $user->created_at?->format('M j, Y') ?? 'Recently',
            'status' => $user->status === AccountStatus::NeedsRevision->value
                ? 'Needs Review'
                : 'Pending',
            'documents' => $documents,
        ];
    }

    private function adminContext(): array
    {
        $admin = Auth::user();
        $name = $admin?->name ?: 'Bearly Admin';

        $initials = collect(preg_split('/\s+/', trim($name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part) => strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        return [
            'name' => $name,
            'role' => 'Admin',
            'email' => $admin?->email ?: '',
            'initials' => $initials ?: 'BA',
        ];
    }

    private function notifications(): array
    {
        $pendingCount = User::query()
            ->whereIn('role', UserRole::adminApproved())
            ->whereIn('status', [
                AccountStatus::Pending->value,
                AccountStatus::NeedsRevision->value,
            ])
            ->count();

        return [
            [
                'title' => $pendingCount.' registration'.($pendingCount === 1 ? '' : 's').' awaiting review',
                'time' => 'Current',
                'type' => $pendingCount > 0 ? 'warning' : 'success',
            ],
        ];
    }
}
