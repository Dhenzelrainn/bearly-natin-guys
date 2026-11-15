<?php

namespace App\Http\Controllers;

use App\Models\StorePromotion;
use App\Services\SellerIdentityService;
use App\Services\SellerNotificationService;
use App\Services\SellerProductDiscountService;
use App\Services\SellerProductService;
use App\Services\SellerPromotionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SellerProductDiscountController extends Controller
{
    public function create(
        Request $request,
        SellerPromotionService $promotions,
        SellerProductService $products,
    ): View {
        $selected = collect($request->query('product'))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        return view('seller.Products.product-discount-form', [
            'seller' => app(SellerIdentityService::class)->current(),
            'notifications' => app(SellerNotificationService::class)->recentFor($request->user()),
            'products' => $promotions->eligibleProductsFor($request->user()),
            'selectedProducts' => $selected,
            'promotion' => null,
            'formAction' => route('seller.promotions.product-discounts.store'),
            'formMethod' => 'POST',
        ]);
    }

    public function store(Request $request, SellerProductDiscountService $discounts): RedirectResponse
    {
        $validated = $this->validated($request);
        $discounts->create($request->user(), $validated);

        return redirect()
            ->route('seller.products.pricing', ['tab' => 'product-discounts'])
            ->with('success', 'Product discount scheduled. It will replace the regular Seller discount while active.');
    }

    public function edit(
        Request $request,
        StorePromotion $promotion,
        SellerPromotionService $promotions,
        SellerProductService $products,
    ): View {
        $store = $products->storeFor($request->user());
        abort_unless((int) $promotion->store_id === (int) $store->id
            && $promotion->type === StorePromotion::TYPE_PRODUCT_DISCOUNT, 404);

        return view('seller.Products.product-discount-form', [
            'seller' => app(SellerIdentityService::class)->current(),
            'notifications' => app(SellerNotificationService::class)->recentFor($request->user()),
            'products' => $promotions->eligibleProductsFor($request->user()),
            'selectedProducts' => $promotion->products()->pluck('products.id')->map(fn ($id): int => (int) $id)->all(),
            'promotion' => $promotion,
            'formAction' => route('seller.promotions.product-discounts.update', $promotion),
            'formMethod' => 'PATCH',
        ]);
    }

    public function update(
        Request $request,
        StorePromotion $promotion,
        SellerProductDiscountService $discounts,
    ): RedirectResponse {
        $discounts->update($request->user(), $promotion, $this->validated($request));

        return redirect()
            ->route('seller.products.pricing', ['tab' => 'product-discounts'])
            ->with('success', 'Product discount updated.');
    }

    public function pause(Request $request, StorePromotion $promotion, SellerProductDiscountService $discounts): RedirectResponse
    {
        $discounts->pause($request->user(), $promotion);

        return back()->with('success', 'Product discount paused. Buyers now see the regular Seller price.');
    }

    public function resume(Request $request, StorePromotion $promotion, SellerProductDiscountService $discounts): RedirectResponse
    {
        $discounts->resume($request->user(), $promotion);

        return back()->with('success', 'Product discount resumed.');
    }

    public function end(Request $request, StorePromotion $promotion, SellerProductDiscountService $discounts): RedirectResponse
    {
        $discounts->end($request->user(), $promotion);

        return back()->with('success', 'Product discount ended. Historical orders keep their original price snapshots.');
    }

    public function duplicate(Request $request, StorePromotion $promotion, SellerProductDiscountService $discounts): RedirectResponse
    {
        $copy = $discounts->duplicate($request->user(), $promotion);

        return redirect()
            ->route('seller.promotions.product-discounts.edit', $copy)
            ->with('success', 'Product discount duplicated. Review the schedule before publishing.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $request->merge([
            'start_time' => $request->input('start_time', '00:00'),
            'end_time' => $request->input('end_time', '23:59'),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'buyer_campaign_name' => ['nullable', 'string', 'max:80'],
            'discount_percent' => ['required', 'integer', 'between:1,90'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'products' => ['required', 'array', 'min:1', 'max:100'],
            'products.*' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')],
        ]);

        if (StorePromotion::dateTimeFromInput($validated['end_date'], $validated['end_time'], true)
            ->lessThan(StorePromotion::dateTimeFromInput($validated['start_date'], $validated['start_time']))) {
            throw ValidationException::withMessages([
                'end_time' => 'The end time must be after the start time.',
            ]);
        }

        return $validated;
    }
}
