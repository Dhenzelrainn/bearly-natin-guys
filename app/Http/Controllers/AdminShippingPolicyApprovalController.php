<?php

namespace App\Http\Controllers;

use App\Models\Policy;
use App\Models\PolicyVersion;
use App\Services\ShippingPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminShippingPolicyApprovalController extends Controller
{
    public function index(Request $request, ShippingPolicyService $shippingPolicies): View
    {
        $adminUser = $request->user();
        $name = $adminUser->name ?: 'Bearly Admin';
        $initials = collect(preg_split('/\s+/', trim($name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');

        return view('admin.system.shipping-policy-approvals', [
            'admin' => [
                'name' => $name,
                'initials' => $initials ?: 'BA',
                'email' => $adminUser->email ?: '',
                'role' => 'Admin',
            ],
            'pendingPolicies' => $shippingPolicies->pendingApprovals(),
            'approvalHistory' => $shippingPolicies->approvalHistory(),
        ]);
    }

    public function updates(Request $request, ShippingPolicyService $shippingPolicies): JsonResponse
    {
        $validated = $request->validate([
            'known_ids' => ['sometimes', 'array', 'max:500'],
            'known_ids.*' => ['required', 'integer', 'min:1'],
        ]);

        $knownIds = collect($validated['known_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $pendingPolicies = $shippingPolicies->pendingApprovals();
        $pendingIds = collect($pendingPolicies)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($knownIds === $pendingIds) {
            return response()->json([], 204)->header('Cache-Control', 'no-store');
        }

        $knownIdLookup = array_fill_keys($knownIds, true);
        $addedHtml = collect($pendingPolicies)
            ->reject(fn (array $submission): bool => isset($knownIdLookup[(int) $submission['id']]))
            ->map(fn (array $submission): string => view('admin.system.partials.shipping-policy-submission', [
                'submission' => $submission,
                'preserveOldInput' => false,
            ])->render())
            ->implode('');

        return response()->json([
            'pending_ids' => $pendingIds,
            'pending_count' => count($pendingIds),
            'added_html' => $addedHtml,
            'empty_html' => $pendingIds === []
                ? view('admin.system.partials.shipping-policy-empty')->render()
                : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function approve(
        Request $request,
        Policy $policy,
        PolicyVersion $version,
        ShippingPolicyService $shippingPolicies,
    ): RedirectResponse {
        $validated = $request->validate([
            'source_reviewed' => ['accepted'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $shippingPolicies->approveAndPublish(
            $request->user(),
            $policy,
            $version,
            $validated['reason'],
            true,
            $request->ip(),
            $request->userAgent(),
        );

        return redirect()
            ->route('admin.shipping-policy-approvals.index')
            ->with('success', 'The Bearly Logistics policy was approved and published.');
    }

    public function requestChanges(
        Request $request,
        Policy $policy,
        PolicyVersion $version,
        ShippingPolicyService $shippingPolicies,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $shippingPolicies->requestChanges(
            $request->user(),
            $policy,
            $version,
            $validated['reason'],
            $request->ip(),
            $request->userAgent(),
        );

        return redirect()
            ->route('admin.shipping-policy-approvals.index')
            ->with('success', 'The policy was returned to the Logistics manager with your review note.');
    }
}
