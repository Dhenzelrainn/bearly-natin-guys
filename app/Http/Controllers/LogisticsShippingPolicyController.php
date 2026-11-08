<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Events\ShippingPolicySubmissionAvailable;
use App\Models\AccountApplication;
use App\Models\Policy;
use App\Models\PolicyVersion;
use App\Models\User;
use App\Services\ShippingPolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class LogisticsShippingPolicyController extends Controller
{
    public function index(Request $request, ShippingPolicyService $shippingPolicies): View
    {
        $user = $request->user();
        $isManager = $shippingPolicies->isManager($user);

        return view('logistics.shipping-policies.index', $this->layoutData($user) + [
            'isPolicyManager' => $isManager,
            'policyManager' => $shippingPolicies->managerProfile(),
            'categories' => ShippingPolicyService::CATEGORIES,
            'policies' => $shippingPolicies->policiesForManagement($isManager),
        ]);
    }

    public function store(Request $request, ShippingPolicyService $shippingPolicies): RedirectResponse
    {
        $this->authorizeManager($request->user(), $shippingPolicies);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'category' => ['required', 'string', Rule::in(ShippingPolicyService::CATEGORIES)],
            'version' => ['required', 'string', 'max:20'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'body' => ['required', 'string', 'max:50000'],
            'source_reference' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'action' => ['prohibited'],
        ]);

        $shippingPolicies->createPolicy(
            $request->user(),
            $validated,
            $validated['reason'] ?? null,
            $request->ip(),
            $request->userAgent(),
        );

        return redirect()
            ->route('logistics.shipping-policies.index')
            ->with('success', 'The Bearly Logistics policy draft was saved. Submit it for Admin review when it is ready.');
    }

    public function update(
        Request $request,
        Policy $policy,
        ShippingPolicyService $shippingPolicies,
    ): RedirectResponse {
        $this->authorizeManager($request->user(), $shippingPolicies);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'category' => ['required', 'string', Rule::in(ShippingPolicyService::CATEGORIES)],
            'version' => ['required', 'string', 'max:20'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'body' => ['required', 'string', 'max:50000'],
            'source_reference' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $shippingPolicies->createDraftVersion(
            $request->user(),
            $policy,
            $validated,
            $validated['reason'] ?? null,
            $request->ip(),
            $request->userAgent(),
        );

        return redirect()
            ->route('logistics.shipping-policies.index')
            ->with('success', 'A new Bearly Logistics policy draft was saved. The currently published version remains in effect until Admin approves this draft.');
    }

    public function updateDraftVersion(
        Request $request,
        Policy $policy,
        PolicyVersion $version,
        ShippingPolicyService $shippingPolicies,
    ): RedirectResponse {
        $this->authorizeManager($request->user(), $shippingPolicies);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'category' => ['required', 'string', Rule::in(ShippingPolicyService::CATEGORIES)],
            'summary' => ['nullable', 'string', 'max:2000'],
            'body' => ['required', 'string', 'max:50000'],
            'source_reference' => ['nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $updatedVersion = $shippingPolicies->updateDraftVersion(
            $request->user(),
            $policy,
            $version,
            $validated,
            $validated['reason'],
            $request->ip(),
            $request->userAgent(),
        );

        return redirect()
            ->route('logistics.shipping-policies.index')
            ->with('success', "Draft {$updatedVersion->version} was updated. It has not been submitted to Admin.");
    }

    public function submitForApproval(
        Request $request,
        Policy $policy,
        ShippingPolicyService $shippingPolicies,
    ): RedirectResponse {
        $this->authorizeManager($request->user(), $shippingPolicies);

        $validated = $request->validate([
            'source_reference' => ['required', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $shippingPolicies->submitForApproval(
            $request->user(),
            $policy,
            $validated['source_reference'],
            $validated['reason'],
            $request->ip(),
            $request->userAgent(),
        );

        try {
            $activeAdminIds = User::query()
                ->where('status', AccountStatus::Active->value)
                ->where(fn ($query) => $query
                    ->where('role', UserRole::Admin->value)
                    ->orWhereHas('roles', fn ($roles) => $roles->where('name', UserRole::Admin->value)))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if ($activeAdminIds !== []) {
                event(new ShippingPolicySubmissionAvailable($activeAdminIds));
            }
        } catch (Throwable $exception) {
            // Realtime is an enhancement; the Admin queue polling fallback must still work.
            report($exception);
        }

        return redirect()
            ->route('logistics.shipping-policies.index')
            ->with('success', 'The Bearly Logistics policy draft was submitted to Admin for review.');
    }

    public function archive(
        Request $request,
        Policy $policy,
        ShippingPolicyService $shippingPolicies,
    ): RedirectResponse {
        $this->authorizeManager($request->user(), $shippingPolicies);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $shippingPolicies->archivePolicy(
            $request->user(),
            $policy,
            $validated['reason'],
            $request->ip(),
            $request->userAgent(),
        );

        return redirect()
            ->route('logistics.shipping-policies.index')
            ->with('success', 'The Bearly Logistics policy was archived; its version history was retained.');
    }

    private function authorizeManager(User $user, ShippingPolicyService $shippingPolicies): void
    {
        abort_unless($shippingPolicies->isManager($user), 403, 'Only the appointed Bearly Logistics policy manager can change provider policies.');
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
