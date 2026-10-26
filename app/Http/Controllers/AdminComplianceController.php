<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductComplianceCheck;
use App\Models\ProductViolation;
use App\Services\ProductCategoryReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminComplianceController extends Controller
{
    public function index(Request $request): View
    {
        $checks = ProductComplianceCheck::query()->with(['product.store.sellerProfile.approvedCategory', 'product.category', 'matches'])
            ->latest()->limit(100)->get();
        $violations = ProductViolation::query()->with(['product.store', 'sellerProfile.warnings'])
            ->whereIn('status', ['flagged', 'under_review', 'correction_requested'])->latest()->get();
        $admin = $request->user();

        return view('admin.compliance.index', [
            'admin' => ['name'=>$admin->name,'role'=>'Admin','email'=>$admin->email,
                'initials'=>collect(explode(' ', $admin->name))->map(fn ($part)=>strtoupper(substr($part,0,1)))->take(2)->implode('')],
            'topNotifications' => [],
            'audits' => $checks->map(fn ($check) => [
                'id'=>'CHK-'.str_pad((string)$check->id, 6, '0', STR_PAD_LEFT), 'product'=>$check->product->name,
                'seller'=>$check->product->store->name, 'registered'=>$check->product->store->sellerProfile?->approvedCategory?->name ?? '—',
                'listed'=>$check->product->category?->name ?? '—', 'risk'=>str($check->matches->max('severity') ?? 'low')->title(),
                'status'=>match($check->result){'clear'=>'Clear · no open violation','blocked'=>'Blocked · hidden from Buyers',default=>'Held for review · hidden from Buyers'},
                'status_class'=>match($check->result){'clear'=>'badge-neutral','blocked'=>'badge-danger',default=>'badge-warning'},
            ])->all(),
            'flagged' => $violations->map(fn ($violation) => [
                'id'=>$violation->violation_no,'product'=>$violation->product->name,'seller'=>$violation->product->store->name,
                'reason'=>$violation->reason,'risk'=>str($violation->severity)->title(),
                'warnings'=>$violation->sellerProfile?->warnings?->where('status','active')->count() ?? 0,
                'decision_url'=>route('admin.compliance.decide', $violation),
            ])->all(),
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
