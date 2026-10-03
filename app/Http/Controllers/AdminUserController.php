<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserAccountStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function logistics(Request $request): View
    {
        return $this->directory($request, UserRole::Logistics->value);
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
    ): RedirectResponse {
        $allowedTargets = collect($statusService->transitions())->flatten()->unique()->all();
        $validated = $request->validate([
            'status' => ['required', Rule::in($allowedTargets)],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $statusService->change($user, $request->user(), $validated['status'], $validated['reason']);

        return back()->with('success', 'Account status was updated.');
    }

    private function directory(Request $request, ?string $fixedRole = null): View
    {
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

        $users = $baseQuery
            ->with(['roles', 'sellerProfile.store', 'logisticsProfile', 'riderProfile'])
            ->withCount('buyerOrders')
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('contact_number', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%");
            }))
            ->when(in_array($role, $managedRoles, true), fn (Builder $query) => $query->where('role', $role))
            ->when(in_array($status, $managedStatuses, true), fn (Builder $query) => $query->where('status', $status))
            ->latest('created_at')
            ->paginate(10)
            ->withQueryString();

        $users->through(fn (User $user): array => $this->directoryRecord($user, $statusService = app(UserAccountStatusService::class)));

        $roleLabel = $fixedRole ? ucfirst($fixedRole) : 'User';
        $view = $fixedRole === UserRole::Logistics->value ? 'logistics' : ($fixedRole ? $fixedRole.'s' : 'index');

        return view('admin.users.'.$view, [
            'admin' => $this->adminSummary($request),
            'topNotifications' => [],
            'users' => $users,
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
            'category' => $user->business_category ?: '—',
            'products' => $store?->products()->count() ?? 0,
            'riders' => $logistics?->riders()->count() ?? 0,
            'vehicle' => $rider?->vehicle_type ?: '—',
            'plate' => $rider?->plate_number ?: '—',
            'center' => $rider?->logisticsProfile?->display_name ?: '—',
            'deliveries' => $rider?->deliveryAttempts()->where('outcome', 'delivered')->count()
                ?? $logistics?->shipments()->where('status', 'delivered')->count() ?? 0,
            'active_batches' => $rider?->pickupAssignments()->whereIn('status', ['assigned','accepted','arrived'])->count() ?? 0,
            'earnings' => ($rider?->earnings()->whereIn('status',['posted','paid'])->sum('amount_minor') ?? 0) / 100,
            'allowed_statuses' => $statusService->transitions()[$user->status] ?? [],
        ];
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
