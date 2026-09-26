<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AccountApplication;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminRegistrationController extends Controller
{
    public function buyers(Request $request): View
    {
        return $this->roleQueue($request, UserRole::Buyer->value, 'user-plus');
    }

    public function sellers(Request $request): View
    {
        return $this->roleQueue($request, UserRole::Seller->value, 'store');
    }

    public function logistics(Request $request): View
    {
        return $this->roleQueue($request, UserRole::Logistics->value, 'warehouse');
    }

    private function roleQueue(Request $request, string $role, string $icon): View
    {
        $search = trim((string) $request->query('search'));
        $status = (string) $request->query('status');
        $sex = (string) $request->query('sex');
        $category = (string) $request->query('category');
        $reviewableStatuses = ['submitted', 'under_review', 'needs_revision'];

        $applications = AccountApplication::query()
            ->with(['user', 'requestedRole', 'businessCategory', 'documents'])
            ->whereIn('status', $reviewableStatuses)
            ->whereHas('requestedRole', fn (Builder $query) => $query->where('name', $role))
            ->whereHas('user', fn (Builder $query) => $query->whereIn('status', [
                AccountStatus::Pending->value,
                AccountStatus::NeedsRevision->value,
            ]))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('application_no', 'like', "%{$search}%")
                        ->orWhere('business_name', 'like', "%{$search}%")
                        ->orWhereHas('user', fn (Builder $userQuery) => $userQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('contact_number', 'like', "%{$search}%"));
                });
            })
            ->when(in_array($status, $reviewableStatuses, true), fn (Builder $query) => $query->where('status', $status))
            ->when($role === UserRole::Buyer->value && in_array($sex, ['female', 'male', 'prefer_not_to_say'], true),
                fn (Builder $query) => $query->whereHas('user', fn (Builder $userQuery) => $userQuery->where('sex', $sex)))
            ->when($role === UserRole::Seller->value && ctype_digit($category),
                fn (Builder $query) => $query->where('business_category_id', (int) $category))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        $applications->through(fn (AccountApplication $application): array => $this->registrationRecord($application));

        $roleLabel = ucfirst($role);
        $descriptions = [
            UserRole::Buyer->value => 'Review Buyer identity information and submitted identification documents before granting marketplace access.',
            UserRole::Seller->value => 'Review Seller business information, approved category, identity documents, and business permits.',
            UserRole::Logistics->value => 'Review Logistics business information and submitted requirements before granting operations access.',
        ];

        $view = $role === UserRole::Logistics->value ? 'logistics' : $role.'s';

        return view('admin.registrations.'.$view, [
            'admin' => $this->adminSummary($request),
            'topNotifications' => [],
            'applications' => $applications,
            'role' => $role,
            'roleLabel' => $roleLabel,
            'icon' => $icon,
            'description' => $descriptions[$role],
            'queueRoute' => 'admin.registrations.'.$view,
            'filters' => compact('search', 'status', 'sex', 'category'),
            'categories' => $role === UserRole::Seller->value
                ? Category::query()->where('is_active', true)->orderBy('position')->get(['id', 'name'])
                : collect(),
        ]);
    }

    /** @return array<string, mixed> */
    private function registrationRecord(AccountApplication $application): array
    {
        $user = $application->user;
        $birthday = $user->birthday ?? $user->birth_date;

        return [
            'database_id' => $application->id,
            'id' => $application->application_no,
            'first_name' => $user->first_name,
            'middle_initial' => $user->middle_initial,
            'last_name' => $user->last_name,
            'name' => $user->name,
            'sex' => str($user->sex ?: 'Not specified')->replace('_', ' ')->title()->toString(),
            'email' => $user->email,
            'contact' => $user->contact_number ?: '—',
            'birthday' => $birthday?->format('F j, Y') ?? '—',
            'age' => $birthday?->age ?? '—',
            'province' => $user->province ?: '—',
            'municipality' => $user->city ?: '—',
            'barangay' => $user->barangay ?: '—',
            'street' => $user->street_address ?: '—',
            'house_number' => '—',
            'business_name' => $application->business_name ?: '—',
            'business_category' => $application->businessCategory?->name ?: '—',
            'submitted' => $application->submitted_at?->format('M j, Y') ?? 'Not submitted',
            'status' => str($application->status)->replace('_', ' ')->title()->toString(),
            'documents' => $application->documents->map(fn ($document) => str($document->document_type)->replace('_', ' ')->title()->toString())->all(),
            'document_records' => $application->documents,
        ];
    }

    private function adminSummary(Request $request): array
    {
        $admin = $request->user();

        return [
            'name' => $admin->name,
            'role' => 'Admin',
            'email' => $admin->email,
            'initials' => collect(explode(' ', $admin->name))
                ->map(fn (string $part) => strtoupper(substr($part, 0, 1)))
                ->take(2)
                ->implode(''),
        ];
    }
}
