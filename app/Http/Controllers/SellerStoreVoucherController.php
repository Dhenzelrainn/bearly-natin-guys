<?php

namespace App\Http\Controllers;

use App\Models\StorePromotion;
use App\Services\SellerIdentityService;
use App\Services\SellerNotificationService;
use App\Services\SellerPromotionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SellerStoreVoucherController extends Controller
{
    public function create(Request $request, SellerPromotionService $promotions): View
    {
        return view('seller.Products.promotion-form', [
            'seller' => app(SellerIdentityService::class)->current(),
            'notifications' => app(SellerNotificationService::class)->recentFor($request->user()),
            'products' => $promotions->eligibleProductsFor($request->user()),
            'formAction' => route('seller.promotions.store-vouchers.store'),
        ]);
    }

    public function store(Request $request, SellerPromotionService $promotions): RedirectResponse
    {
        $request->merge([
            'start_time' => $request->input('start_time', '00:00'),
            'end_time' => $request->input('end_time', '23:59'),
        ]);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'type' => ['required', Rule::in([StorePromotion::TYPE_STORE_VOUCHER])],
            'code' => [
                'nullable',
                'string',
                'max:40',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('store_promotions', 'code'),
                Rule::unique('platform_promotions', 'code'),
            ],
            'discount_type' => ['required', Rule::in([StorePromotion::DISCOUNT_FIXED, StorePromotion::DISCOUNT_PERCENT])],
            'value' => ['nullable', 'required_if:discount_type,fixed', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
            'discount_percent' => ['nullable', 'required_if:discount_type,percent', 'integer', 'between:1,100'],
            'max_discount' => ['nullable', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'products' => ['required', 'array', 'min:1', 'max:100'],
            'products.*' => ['required', 'integer', 'distinct'],
            'minimum_spend' => ['nullable', 'string', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D'],
            'redemption_limit' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'buyer_limit' => ['required', 'integer', 'between:1,10'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'is_claimable' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
        ]);

        if (StorePromotion::dateTimeFromInput($validated['end_date'], $validated['end_time'], true)
            ->lessThan(StorePromotion::dateTimeFromInput($validated['start_date'], $validated['start_time']))) {
            throw ValidationException::withMessages([
                'end_time' => 'The end time must be after the start time.',
            ]);
        }

        $promotions->create($request->user(), $validated);

        return redirect()
            ->route('seller.products.pricing', ['tab' => 'store-vouchers'])
            ->with('success', 'Store voucher saved and published for Buyer claims.');
    }

    public function updateStatus(
        Request $request,
        StorePromotion $promotion,
        SellerPromotionService $promotions,
    ): RedirectResponse {
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                StorePromotion::STATUS_ACTIVE,
                StorePromotion::STATUS_PAUSED,
                StorePromotion::STATUS_ENDED,
            ])],
        ]);

        $promotions->updateStatus($request->user(), $promotion, $validated['status']);

        return back()->with('success', 'Store voucher status updated.');
    }
}
