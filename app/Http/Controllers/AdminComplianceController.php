<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductComplianceCheck;
use App\Models\ProductViolation;
use App\Services\ProductCategoryReviewService;
use App\Services\ProductImageCategoryCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminComplianceController extends Controller
{
    public function index(Request $request): View
    {
        $checks = ProductComplianceCheck::query()
            ->with(['product.store.sellerProfile.approvedCategory', 'product.category', 'matches'])
            ->latest()
            ->limit(100)
            ->get();
        $openViolationCount = ProductViolation::query()
            ->whereIn('status', ['flagged', 'under_review', 'correction_requested'])
            ->count();
        $admin = $request->user();
        $audits = $checks->map(fn (ProductComplianceCheck $check): array => [
            'id' => 'CHK-'.str_pad((string) $check->id, 6, '0', STR_PAD_LEFT),
            'product' => $check->product->name,
            'seller' => $check->product->store->name,
            'registered' => $check->product->store->sellerProfile?->approvedCategory?->name ?? '—',
            'listed' => $check->product->category?->name ?? '—',
            'risk' => str($check->matches->max('severity') ?? 'low')->title(),
            'status' => match ($check->result) {
                'clear' => 'Clear · no open violation',
                'blocked' => 'Blocked · hidden from Buyers',
                default => 'Held for review · hidden from Buyers',
            },
            'status_class' => match ($check->result) {
                'clear' => 'badge-neutral',
                'blocked' => 'badge-danger',
                default => 'badge-warning',
            },
        ])->all();

        return view('admin.compliance.index', [
            'admin' => ['name'=>$admin->name,'role'=>'Admin','email'=>$admin->email,
            'initials'=>collect(explode(' ', $admin->name))->map(fn ($part)=>strtoupper(substr($part,0,1)))->take(2)->implode('')],
            'topNotifications' => [],
            'audits' => $audits,
            'openViolationCount' => $openViolationCount,
        ]);
    }

    public function imageAdvisories(Request $request, ProductImageCategoryCheckService $imageChecks): JsonResponse
    {
        $validated = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:100'],
            'product_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $imageCheckEnabled = (bool) config('services.gemini.product_image_check.enabled', false);
        $products = Product::withTrashed()
            ->whereIn('id', $validated['product_ids'])
            ->with([
                'category',
                'marketplaceSubcategory.marketplaceCategory',
                'store' => fn ($query) => $query->withTrashed()->with('sellerProfile.approvedCategory'),
                'images',
                'currentImageCategoryCheck',
            ])
            ->get()
            ->keyBy('id');

        $advisories = collect($validated['product_ids'])
            ->map(function ($productId) use ($products, $imageChecks, $imageCheckEnabled): array {
                $productId = (int) $productId;
                $product = $products->get($productId);
                $check = $product ? $imageChecks->currentCheckForProduct($product) : null;
                $statusCode = $check?->status ?? ($product
                    ? ($imageCheckEnabled ? 'not_checked' : 'disabled')
                    : 'unavailable');

                return [
                    'product_id' => $productId,
                    'image_category_code' => $statusCode,
                    'image_category_status' => match ($statusCode) {
                        'match' => 'Match',
                        'possible_mismatch' => 'Possible Mismatch',
                        'uncertain' => 'Uncertain',
                        'pending' => 'Pending',
                        'unavailable' => 'Unavailable',
                        'not_checked' => 'Not checked',
                        'disabled' => 'Disabled',
                        default => 'Unavailable',
                    },
                    'image_category_class' => match ($statusCode) {
                        'match' => 'badge-success',
                        'possible_mismatch' => 'badge-warning',
                        'pending' => 'badge-info',
                        default => 'badge-neutral',
                    },
                    'image_category_description' => $check?->visible_item_description,
                    'image_category_reason' => $check?->reason,
                    'image_category_helper' => match ($statusCode) {
                        'possible_mismatch', 'uncertain' => 'AI advisory only; Admin review is still required.',
                        'pending' => 'Awaiting an advisory check; this does not affect publishing or visibility.',
                        'not_checked' => 'No image check is recorded for this listing yet.',
                        'disabled' => 'Image checks are disabled; no product photo is sent to Google.',
                        default => null,
                    },
                    'image_category_checked_at' => $check?->completed_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'data' => ['advisories' => $advisories],
            'message' => 'Current product image advisory statuses.',
        ]);
    }

    public function categoryReviews(Request $request, ProductCategoryReviewService $reviewService): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        $admin = $request->user();

        return view('admin.compliance.category-reviews', [
            'admin' => [
                'name' => $admin->name,
                'role' => 'Admin',
                'email' => $admin->email,
                'initials' => collect(explode(' ', $admin->name))
                    ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
                    ->take(2)
                    ->implode(''),
            ],
            'topNotifications' => [],
            'products' => $reviewService->paginateQueue($filters['search'] ?? null),
            'imageCheckEnabled' => (bool) config('services.gemini.product_image_check.enabled', false),
        ]);
    }

    public function markCategoryAppropriate(
        Request $request,
        Product $product,
        ProductCategoryReviewService $reviewService,
    ): RedirectResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $reviewService->markCategoryAppropriate(
            $product,
            $request->user(),
            $data['reason'],
            $request,
        );

        return back()->with('success', 'Review saved. The listing will return to this queue if its relevant text, category, or image metadata changes.');
    }

    public function flagCategoryForCorrection(
        Request $request,
        Product $product,
        ProductCategoryReviewService $reviewService,
    ): RedirectResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $violation = $reviewService->flagForCategoryCorrection(
            $product,
            $request->user(),
            $data['reason'],
            $request,
        );

        return redirect()
            ->route('admin.compliance.violations')
            ->with('success', "Listing hidden for category correction. Violation {$violation->violation_no} is ready for Admin review.");
    }
}
