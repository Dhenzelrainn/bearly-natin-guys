<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\LogisticsProfile;
use App\Models\User;
use App\Services\ShippingPolicyService;
use App\Services\UserAccountStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    public function index(Request $request): View
    {
        return $this->directory($request);
    }

    public function buyers(Request $request): View
    {
        return $this->directory($request, UserRole::Buyer->value);
    }

    public function sellers(Request $request): View
    {
        return $this->directory($request, UserRole::Seller->value);
    }

    public function logistics(Request $request, ShippingPolicyService $shippingPolicies): View
    {
        return $this->directory($request, UserRole::Logistics->value, $shippingPolicies);
    }

    public function updateShippingPolicyManager(
        Request $request,
        ShippingPolicyService $shippingPolicies,
    ): RedirectResponse {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['assign', 'remove'])],
            'logistics_profile_id' => ['nullable', 'required_if:action,assign', 'integer', 'exists:logistics_profiles,id'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $profileId = $validated['action'] === 'assign'
            ? (int) $validated['logistics_profile_id']
            : null;

        $shippingPolicies->assignManager(
            $request->user(),
            $profileId,
            $validated['reason'],
            $request->ip(),
            $request->userAgent(),
        );

        return back()->with(
            'success',
            $validated['action'] === 'remove'
                ? 'The Bearly Logistics policy manager assignment was removed.'
                : 'The Bearly Logistics policy manager was updated.'
        );
    }

    public function riders(Request $request): View
    {
        return $this->directory($request, UserRole::Rider->value);
    }

    public function show(Request $request, User $user, UserAccountStatusService $statusService): View
    {
        abort_unless(in_array($user->role, $statusService->managedRoles(), true), 404);
        abort_unless(in_array($user->status, array_keys($statusService->transitions()), true), 404);

        $user->load([
            'roles',
            'applications' => fn ($query) => $query->with(['requestedRole', 'businessCategory'])->latest('submitted_at'),
            'sellerProfile.store',
            'logisticsProfile',
            'riderProfile.logisticsProfile',
        ]);

        $auditLogs = AuditLog::query()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->latest('created_at')
            ->limit(20)
            ->get();

        return view('admin.users.show', [
            'admin' => $this->adminSummary($request),
            'topNotifications' => [],
            'user' => $user,
            'auditLogs' => $auditLogs,
            'allowedStatuses' => $statusService->transitions()[$user->status],
        ]);
    }

    public function updateStatus(
        Request $request,
        User $user,
        UserAccountStatusService $statusService,
    ): JsonResponse|RedirectResponse {
        abort_if(
            $user->role === UserRole::Rider->value,
            403,
            'Rider account status is managed by the owning Logistics provider.'
        );

        $allowedTargets = collect($statusService->transitions())->flatten()->unique()->all();
        $validated = $request->validate([
            'status' => ['required', Rule::in($allowedTargets)],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $updatedUser = $statusService->change(
            $user,
            $request->user(),
            $validated['status'],
            $validated['reason']
        );

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'user_id' => $updatedUser->id,
                    'status' => $updatedUser->status,
                    'allowed_statuses' => $statusService->transitions()[$updatedUser->status] ?? [],
                ],
                'message' => 'Account status was updated.',
            ]);
        }

        return back()->with('success', 'Account status was updated.');
    }

    private function directory(
        Request $request,
        ?string $fixedRole = null,
        ?ShippingPolicyService $shippingPolicies = null,
    ): View {
        $managedRoles = [
            UserRole::Buyer->value,
            UserRole::Seller->value,
            UserRole::Logistics->value,
            UserRole::Rider->value,
        ];
        $managedStatuses = [
            AccountStatus::Active->value,
            AccountStatus::Suspended->value,
            AccountStatus::Deactivated->value,
        ];
        $search = trim((string) $request->query('search'));
        $role = $fixedRole ?? (string) $request->query('role');
        $status = (string) $request->query('status');

        $baseQuery = User::query()
            ->whereIn('role', $managedRoles)
            ->whereIn('status', $managedStatuses)
            ->when($fixedRole !== null, fn (Builder $query) => $query->where('role', $fixedRole));

        $stats = (clone $baseQuery)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $userQuery = $baseQuery
            ->with([
                'roles',
                'sellerProfile.store',
                'sellerProfile.approvedCategory',
                'logisticsProfile',
                'riderProfile',
            ])
            ->withCount('buyerOrders')
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('contact_number', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%");
            }))
            ->when(in_array($role, $managedRoles, true), fn (Builder $query) => $query->where('role', $role))
            ->when(in_array($status, $managedStatuses, true), fn (Builder $query) => $query->where('status', $status))
            ->latest('created_at');

        $statusService = app(UserAccountStatusService::class);
        $mapUser = fn (User $user): array => $this->directoryRecord($user, $statusService);

        if ($fixedRole === null) {
            // The All Users controls filter rows in the browser, so load the
            // complete directory instead of paginating rows the UI cannot reach.
            $users = $userQuery->get()->map($mapUser);
        } else {
            $users = $userQuery->paginate(10)->withQueryString();
            $users->through($mapUser);
        }

        $roleLabel = $fixedRole ? ucfirst($fixedRole) : 'User';
        $view = $fixedRole === UserRole::Logistics->value ? 'logistics' : ($fixedRole ? $fixedRole.'s' : 'index');
        $categoryOptions = $fixedRole === UserRole::Seller->value
            ? $this->sellerCategoryOptions($baseQuery)
            : collect();

        $policyManagerProfile = null;
        $hasPolicyManagerAssignment = false;
        $eligiblePolicyManagers = collect();

        if ($fixedRole === UserRole::Logistics->value && $shippingPolicies !== null) {
            $assignment = $shippingPolicies->managerAssignment();
            $policyManagerProfile = $assignment?->managerLogisticsProfile;
            $hasPolicyManagerAssignment = $assignment?->manager_logistics_profile_id !== null;
            $eligiblePolicyManagers = LogisticsProfile::query()
                ->where('status', 'active')
                ->whereHas('user', fn (Builder $query) => $query
                    ->where('role', UserRole::Logistics->value)
                    ->where('status', AccountStatus::Active->value))
                ->with('user')
                ->orderBy('display_name')
                ->get();
        }

        return view('admin.users.'.$view, [
            'admin' => $this->adminSummary($request),
            'topNotifications' => [],
            'users' => $users,
            'categoryOptions' => $categoryOptions,
            'role' => $fixedRole,
            'roleLabel' => $roleLabel,
            'filters' => compact('search', 'role', 'status'),
            'userStats' => [
                'total' => $stats->sum(),
                'active' => (int) ($stats[AccountStatus::Active->value] ?? 0),
                'suspended' => (int) ($stats[AccountStatus::Suspended->value] ?? 0),
                'deactivated' => (int) ($stats[AccountStatus::Deactivated->value] ?? 0),
            ],
            'directoryRoute' => $fixedRole ? 'admin.users.'.$view : 'admin.users',
            'policyManagerProfile' => $policyManagerProfile,
            'hasPolicyManagerAssignment' => $hasPolicyManagerAssignment,
            'eligiblePolicyManagers' => $eligiblePolicyManagers,
        ]);
    }

    /** @return array<string, mixed> */
    private function directoryRecord(User $user, UserAccountStatusService $statusService): array
    {
        $location = collect([$user->city, $user->province])->filter()->implode(', ') ?: '—';
        $store = $user->sellerProfile?->store;
        $logistics = $user->logisticsProfile;
        $rider = $user->riderProfile;

        return [
            'database_id' => $user->id,
            'id' => strtoupper(substr($user->role, 0, 3)).'-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'name' => $store?->name ?? $logistics?->display_name ?? $user->name,
            'owner' => $user->name,
            'manager' => $user->name,
            'email' => $user->email,
            'contact' => $user->contact_number ?: '—',
            'location' => $location,
            'role' => ucfirst($user->role),
            'joined' => $user->created_at?->format('M j, Y') ?? '—',
            'status' => ucfirst($user->status),
            'orders' => (int) $user->buyer_orders_count,
            'category' => $this->sellerCategoryName($user) ?? '—',
            'products' => $store?->products()->count() ?? 0,
            'riders' => $logistics?->riders()->count() ?? 0,
            'vehicle' => $rider?->vehicle_type ?: '—',
            'plate' => $rider?->plate_number ?: '—',
            'center' => $rider?->logisticsProfile?->display_name ?: '—',
            'deliveries' => $rider?->deliveryAttempts()->where('outcome', 'delivered')->count()
                ?? $logistics?->shipments()->where('status', 'delivered')->count() ?? 0,
            'active_batches' => $rider?->pickupAssignments()->whereIn('status', ['assigned', 'accepted', 'arrived'])->count() ?? 0,
            'earnings' => ($rider?->earnings()->whereIn('status', ['posted', 'paid'])->sum('amount_minor') ?? 0) / 100,
            'allowed_statuses' => $statusService->transitions()[$user->status] ?? [],
            'can_manage_status' => $user->role !== UserRole::Rider->value,
        ];
    }

    /**
     * Return categories represented by the managed Seller accounts, including
     * Sellers that are on a later pagination page.
     *
     * @return Collection<int, string>
     */
    private function sellerCategoryOptions(Builder $sellerQuery): Collection
    {
        return (clone $sellerQuery)
            ->with('sellerProfile.approvedCategory:id,name')
            ->get(['id', 'business_category'])
            ->map(fn (User $user): ?string => $this->sellerCategoryName($user))
            ->filter(fn (?string $category): bool => filled($category))
            ->unique()
            ->sortBy(fn (string $category): string => mb_strtolower($category))
            ->values();
    }

    private function sellerCategoryName(User $user): ?string
    {
        $category = $user->sellerProfile?->approvedCategory?->name ?: $user->business_category;

        return filled($category) ? $category : null;
    }

    private function adminSummary(Request $request): array
    {
        $admin = $request->user();

        return [
            'name' => $admin->name,
            'role' => 'Admin',
            'email' => $admin->email,
            'initials' => collect(explode(' ', $admin->name))->map(fn (string $part) => strtoupper(substr($part, 0, 1)))->take(2)->implode(''),
        ];
    }
}
