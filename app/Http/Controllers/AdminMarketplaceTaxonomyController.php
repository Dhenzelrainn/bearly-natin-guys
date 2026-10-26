<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceSubcategory;
use App\Models\MarketplaceSubcategoryRequest;
use App\Services\MarketplaceTaxonomyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminMarketplaceTaxonomyController extends Controller
{
    public function index(Request $request, MarketplaceTaxonomyService $taxonomy): View
    {
        $adminUser = $request->user();

        return view('admin.marketplace-taxonomy.index', [
            'admin' => [
                'name' => $adminUser->name,
                'role' => 'Admin',
                'email' => $adminUser->email,
                'initials' => collect(explode(' ', $adminUser->name))
                    ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
                    ->take(2)
                    ->implode(''),
            ],
            'topNotifications' => [],
            'marketplaceCategories' => $taxonomy->adminCategories(),
            'requests' => $taxonomy->pendingRequests(),
        ]);
    }

    public function storeSubcategory(Request $request, MarketplaceTaxonomyService $taxonomy): RedirectResponse
    {
        $data = $request->validate([
            'marketplace_category_id' => ['required', 'integer', 'min:1', 'exists:marketplace_categories,id'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'position' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $subcategory = $taxonomy->createSubcategory(
            $request->user(),
            (int) $data['marketplace_category_id'],
            $data['name'],
            (int) ($data['position'] ?? 0),
            $request,
        );

        return redirect()->route('admin.marketplace-taxonomy.index')
            ->with('success_title', 'Marketplace subcategory added')
            ->with('success', $subcategory->name.' is now available under '.$subcategory->marketplaceCategory->name.'.');
    }

    public function updateSubcategory(Request $request, MarketplaceSubcategory $subcategory, MarketplaceTaxonomyService $taxonomy): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'position' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['required', 'boolean'],
        ]);

        $updated = $taxonomy->updateSubcategory($request->user(), (int) $subcategory->id, $data, $request);

        return redirect()->route('admin.marketplace-taxonomy.index')
            ->with('success_title', 'Marketplace subcategory updated')
            ->with('success', $updated->name.' was updated without changing existing product links.');
    }

    public function decideRequest(Request $request, MarketplaceSubcategoryRequest $subcategoryRequest, MarketplaceTaxonomyService $taxonomy): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reuse', 'reject'])],
            'marketplace_category_id' => [
                Rule::excludeIf($request->input('decision') !== 'approve'),
                'required_if:decision,approve', 'integer', 'min:1', 'exists:marketplace_categories,id',
            ],
            'marketplace_subcategory_id' => [
                Rule::excludeIf($request->input('decision') !== 'reuse'),
                'required_if:decision,reuse', 'integer', 'min:1', 'exists:marketplace_subcategories,id',
            ],
            'decision_reason' => [
                Rule::requiredIf($request->input('decision') === 'reject'),
                'nullable', 'string', 'min:5', 'max:1000',
            ],
        ], [
            'decision_reason.required' => 'A reason is required when rejecting a subcategory request.',
        ]);

        $decided = $taxonomy->decideRequest($request->user(), (int) $subcategoryRequest->id, $data, $request);

        return redirect()->route('admin.marketplace-taxonomy.index')
            ->with('success_title', 'Subcategory request reviewed')
            ->with('success', match ($data['decision']) {
                'reject' => 'The request for '.$decided->suggested_name.' was rejected with a recorded reason.',
                'reuse' => 'The request for '.$decided->suggested_name.' was linked to the existing canonical subcategory.',
                default => $decided->suggested_name.' was approved as a marketplace subcategory.',
            });
    }
}
