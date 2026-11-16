<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceCategory;
use App\Models\Product;
use App\Models\ProductComplianceCheck;
use App\Models\ProductViolation;
use App\Services\ProductCategoryReviewService;
use App\Services\ProductImageCategoryCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AdminComplianceController extends Controller
{
    public function index(Request $request): View
    {
        $checks = ProductComplianceCheck::query()
            ->with([
                'product' => fn ($query) => $query->withTrashed(),
                'product.store' => fn ($query) => $query->withTrashed()->with('sellerProfile.approvedCategory'),
                'product.images',
                'product.category',
                'matches',
            ])
            ->latest()
            ->limit(100)
            ->get();
        $openViolationCount = ProductViolation::query()
            ->whereIn('status', ['flagged', 'under_review', 'correction_requested'])
            ->count();
        $admin = $request->user();
        $audits = $checks->map(function (ProductComplianceCheck $check): array {
            $product = $check->product;
            $images = $product?->images ?? collect();
            $image = $images->whereNull('variant_id')->firstWhere('is_primary', true)
                ?? $images->whereNull('variant_id')->first()
                ?? $images->first();
            $imageDisk = $image?->disk ?: 'public';

            return [
                'id' => 'CHK-'.str_pad((string) $check->id, 6, '0', STR_PAD_LEFT),
                'product_id' => (int) $check->product_id,
                'product' => $product?->name ?? $check->name_snapshot ?? 'Product record unavailable',
                'image_url' => $image && filled($image->path) && config("filesystems.disks.{$imageDisk}")
                    ? Storage::disk($imageDisk)->url($image->path)
                    : null,
                'image_alt' => $image?->alt_text ?: ($product?->name ?? $check->name_snapshot ?? 'Product image'),
                'seller' => $product?->store?->name ?? 'Seller record unavailable',
                'registered' => $product?->store?->sellerProfile?->approvedCategory?->name ?? '—',
                'listed' => $product?->category?->name ?? '—',
                'risk' => str($check->matches->max('severity') ?? 'low')->title(),
                'status' => match ($check->result) {
                    'clear' => 'Text rules clear',
                    'blocked' => 'Text rules blocked',
                    default => 'Review required',
                },
                'status_class' => match ($check->result) {
                    'clear' => 'badge-neutral',
                    'blocked' => 'badge-danger',
                    default => 'badge-warning',
                },
            ];
        })->all();

        return view('admin.compliance.index', [
            'admin' => ['name'=>$admin->name,'role'=>'Admin','email'=>$admin->email,
            'initials'=>collect(explode(' ', $admin->name))->map(fn ($part)=>strtoupper(substr($part,0,1)))->take(2)->implode('')],
            'topNotifications' => [],
            'audits' => $audits,
            'openViolationCount' => $openViolationCount,
            'imageCheckEnabled' => (bool) config('services.gemini.product_image_check.enabled', false),
            'imageSafetyEnforced' => (bool) config('services.gemini.product_image_check.enforce_on_publish', false),
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
                    'currentImageCategoryChecks',
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
                    'image_category_helper' => $product ? $imageChecks->categoryCheckHelper($product, $statusCode) : null,
                    'image_category_checked_at' => $check?->completed_at?->toIso8601String(),
                    'image_reviews' => $product ? $imageChecks->imageReviewPayload($product) : [],
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'data' => ['advisories' => $advisories],
            'message' => 'Current product image advisory statuses.',
        ]);
    }

    public function categoryReviews(
        Request $request,
        ProductCategoryReviewService $reviewService,
    ): View {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'marketplace_category_id' => ['nullable', 'integer', 'exists:marketplace_categories,id'],
        ]);
        $admin = $request->user();
        $marketplaceCategoryId = isset($filters['marketplace_category_id'])
            ? (int) $filters['marketplace_category_id']
            : null;

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
            'products' => $reviewService->paginateQueue(
                $filters['search'] ?? null,
                $marketplaceCategoryId,
            ),
            'marketplaceCategories' => MarketplaceCategory::query()
                ->orderBy('position')
                ->orderBy('name')
                ->get(['id', 'name']),
            'selectedMarketplaceCategoryId' => $marketplaceCategoryId,
            'imageCheckEnabled' => (bool) config('services.gemini.product_image_check.enabled', false),
            'imageSafetyEnforced' => (bool) config('services.gemini.product_image_check.enforce_on_publish', false),
        ]);
    }

    public function approveImageReview(
        Request $request,
        Product $product,
        ProductCategoryReviewService $reviewService,
    ): RedirectResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $reviewService->approvePendingImageReview(
            $product,
            $request->user(),
            $data['reason'],
            $request,
        );

        return back()->with('success', 'Image review recorded. The listing is now visible to Buyers.');
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
