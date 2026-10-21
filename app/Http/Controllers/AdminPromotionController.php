<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\PlatformPromotion;
use App\Models\PromotionRedemption;
use App\Models\Store;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminPromotionController extends Controller
{
    public function index(): View
    {
        $promotions = PlatformPromotion::query()
            ->whereNull('campaign_type')
            ->whereIn('kind', [
                PlatformPromotion::KIND_VOUCHER,
                PlatformPromotion::KIND_AUTOMATIC_DISCOUNT,
                PlatformPromotion::KIND_FREE_SHIPPING,
            ])
            ->with(['products:id,name', 'categories:id,name', 'stores:id,name'])
            ->withCount([
                'redemptions as active_redemptions_count' => fn ($query) => $query
                    ->whereIn('status', [PromotionRedemption::STATUS_RESERVED, PromotionRedemption::STATUS_REDEEMED]),
                'claims',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.promotions.index', [
            'promotions' => $promotions,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'stores' => Store::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $request->merge([
            'start_time' => $request->input('start_time', '00:00'),
            'end_time' => $request->input('end_time', '23:59'),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'nullable',
                'string',
                'max:40',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('platform_promotions', 'code'),
                Rule::unique('store_promotions', 'code'),
            ],
            'kind' => ['required', Rule::in([
                PlatformPromotion::KIND_VOUCHER,
                PlatformPromotion::KIND_AUTOMATIC_DISCOUNT,
                PlatformPromotion::KIND_FREE_SHIPPING,
            ])],
            'discount_type' => ['required', Rule::in([
                PlatformPromotion::DISCOUNT_FIXED,
                PlatformPromotion::DISCOUNT_PERCENT,
                PlatformPromotion::DISCOUNT_FREE_SHIPPING,
                PlatformPromotion::DISCOUNT_FIXED_SHIPPING,
                PlatformPromotion::DISCOUNT_PERCENT_SHIPPING,
            ])],
            'value' => ['nullable', 'numeric', 'min:0', 'max:10000000', 'decimal:0,2'],
            'discount_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'max_discount' => ['nullable', 'numeric', 'min:0', 'max:10000000', 'decimal:0,2'],
            'minimum_spend' => ['nullable', 'numeric', 'min:0', 'max:10000000', 'decimal:0,2'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'redemption_limit' => ['nullable', 'integer', 'min:1'],
            'per_buyer_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:100000000', 'decimal:0,2'],
            'status' => ['required', Rule::in([PlatformPromotion::STATUS_ACTIVE, PlatformPromotion::STATUS_PAUSED])],
            'description' => ['nullable', 'string', 'max:255'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'is_claimable' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'banner_title' => ['nullable', 'string', 'max:160'],
            'banner_link' => ['nullable', 'url', 'max:500'],
            'position' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'scope_type' => ['required', Rule::in(['all', 'category', 'store', 'product'])],
            'category_ids' => ['nullable', 'array', 'max:100'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'store_ids' => ['nullable', 'array', 'max:100'],
            'store_ids.*' => ['integer', 'exists:stores,id'],
            'product_ids' => ['nullable', 'array', 'max:200'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ]);

        if (PlatformPromotion::dateTimeFromInput($data['ends_on'], $data['end_time'], true)
            ->lessThan(PlatformPromotion::dateTimeFromInput($data['starts_on'], $data['start_time']))) {
            throw ValidationException::withMessages([
                'end_time' => 'The end time must be after the start time.',
            ]);
        }

        $this->validatePromotionShape($data);

        $promotion = DB::transaction(function () use ($request, $data): PlatformPromotion {
            $bannerPath = $request->file('banner')?->store('promotions/banners', 'public');

            $promotion = PlatformPromotion::query()->create([
                'created_by' => $request->user()->id,
                'name' => trim((string) $data['name']),
                'code' => filled($data['code'] ?? null) ? strtoupper(trim((string) $data['code'])) : null,
                'kind' => $data['kind'],
                'funding_type' => PlatformPromotion::FUNDING_PLATFORM,
                'discount_type' => $data['discount_type'],
                'status' => $data['status'],
                'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
                'terms' => filled($data['terms'] ?? null) ? trim((string) $data['terms']) : null,
                'value_minor' => $this->minor($data['value'] ?? null, 'value'),
                'discount_percent' => $data['discount_percent'] ?? null,
                'max_discount_minor' => $this->maximumDiscountMinor($data),
                'minimum_spend_minor' => $this->minor($data['minimum_spend'] ?? 0, 'minimum_spend'),
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'starts_time' => PlatformPromotion::timeFromInput($data['start_time']),
                'ends_time' => PlatformPromotion::timeFromInput($data['end_time'], inclusiveMinute: true),
                'redemption_limit' => $data['redemption_limit'] ?? null,
                'per_buyer_limit' => $data['per_buyer_limit'],
                'budget_minor' => $this->minor($data['budget'] ?? null, 'budget'),
                'is_claimable' => in_array($data['kind'], [
                    PlatformPromotion::KIND_VOUCHER,
                    PlatformPromotion::KIND_FREE_SHIPPING,
                ], true)
                    && (bool) ($data['is_claimable'] ?? false),
                'is_public' => (bool) ($data['is_public'] ?? true),
                'is_featured' => (bool) ($data['is_featured'] ?? false),
                'banner_path' => $bannerPath,
                'banner_disk' => $bannerPath ? 'public' : null,
                'banner_title' => filled($data['banner_title'] ?? null) ? trim((string) $data['banner_title']) : null,
                'banner_link' => filled($data['banner_link'] ?? null) ? trim((string) $data['banner_link']) : null,
                'position' => (int) ($data['position'] ?? 0),
            ]);

            $this->syncTargets($promotion, $data);

            return $promotion;
        }, attempts: 3);

        $audit->record('promotion.created', 'marketing', $promotion, 'Platform promotion created.', [], [
            'name' => $promotion->name,
            'code' => $promotion->code,
            'kind' => $promotion->kind,
            'discount_type' => $promotion->discount_type,
            'status' => $promotion->status,
            'starts_on' => $promotion->starts_on?->toDateString(),
            'ends_on' => $promotion->ends_on?->toDateString(),
            'starts_time' => $promotion->starts_time,
            'ends_time' => $promotion->ends_time,
            'is_claimable' => (bool) $promotion->is_claimable,
            'is_featured' => (bool) $promotion->is_featured,
        ], 'info', $request);

        return redirect()->route('admin.promotions')
            ->with('success', 'Platform promotion created.');
    }

    public function updateStatus(Request $request, PlatformPromotion $promotion, AuditService $audit): RedirectResponse
    {
        abort_if($promotion->isCampaign(), 404);

        $data = $request->validate([
            'status' => ['required', Rule::in([PlatformPromotion::STATUS_ACTIVE, PlatformPromotion::STATUS_PAUSED])],
        ]);

        $oldStatus = $promotion->status;
        $promotion->update(['status' => $data['status']]);

        $audit->record('promotion.status_updated', 'marketing', $promotion, 'Platform promotion status updated.', [
            'status' => $oldStatus,
        ], [
            'status' => $promotion->status,
        ], 'info', $request);

        return redirect()->route('admin.promotions')
            ->with('success', $data['status'] === PlatformPromotion::STATUS_ACTIVE
                ? 'Promotion activated.'
                : 'Promotion paused.');
    }

    private function validatePromotionShape(array $data): void
    {
        $shippingType = in_array($data['discount_type'], [
            PlatformPromotion::DISCOUNT_FREE_SHIPPING,
            PlatformPromotion::DISCOUNT_FIXED_SHIPPING,
            PlatformPromotion::DISCOUNT_PERCENT_SHIPPING,
        ], true);

        if ($data['kind'] === PlatformPromotion::KIND_FREE_SHIPPING && ! $shippingType) {
            throw ValidationException::withMessages([
                'discount_type' => 'A free shipping promotion must use a shipping discount type.',
            ]);
        }

        if ($data['kind'] !== PlatformPromotion::KIND_FREE_SHIPPING && $shippingType) {
            throw ValidationException::withMessages([
                'discount_type' => 'Shipping discount types are reserved for free shipping promotions.',
            ]);
        }

        if ($data['kind'] !== PlatformPromotion::KIND_VOUCHER && filled($data['code'] ?? null)) {
            throw ValidationException::withMessages([
                'code' => 'Only platform vouchers can use a voucher code.',
            ]);
        }

        if ($data['kind'] === PlatformPromotion::KIND_VOUCHER
            && blank($data['code'] ?? null)
            && ! (bool) ($data['is_claimable'] ?? false)) {
            throw ValidationException::withMessages([
                'is_claimable' => 'A platform voucher needs a code or must be claimable in My Vouchers.',
            ]);
        }

        if (in_array($data['discount_type'], [
            PlatformPromotion::DISCOUNT_FIXED,
            PlatformPromotion::DISCOUNT_FIXED_SHIPPING,
        ], true) && blank($data['value'] ?? null)) {
            throw ValidationException::withMessages([
                'value' => 'Enter the fixed discount amount.',
            ]);
        }

        if (in_array($data['discount_type'], [
            PlatformPromotion::DISCOUNT_PERCENT,
            PlatformPromotion::DISCOUNT_PERCENT_SHIPPING,
        ], true) && blank($data['discount_percent'] ?? null)) {
            throw ValidationException::withMessages([
                'discount_percent' => 'Enter the discount percentage.',
            ]);
        }

        $scopeType = $data['scope_type'];
        if ($scopeType === 'category' && empty($data['category_ids'])) {
            throw ValidationException::withMessages(['category_ids' => 'Select at least one category.']);
        }
        if ($scopeType === 'store' && empty($data['store_ids'])) {
            throw ValidationException::withMessages(['store_ids' => 'Select at least one Store.']);
        }
        if ($scopeType === 'product' && empty($data['product_ids'])) {
            throw ValidationException::withMessages(['product_ids' => 'Select at least one product.']);
        }
    }

    private function syncTargets(PlatformPromotion $promotion, array $data): void
    {
        $promotion->categories()->sync($data['scope_type'] === 'category' ? ($data['category_ids'] ?? []) : []);
        $promotion->stores()->sync($data['scope_type'] === 'store' ? ($data['store_ids'] ?? []) : []);
        $promotion->products()->sync($data['scope_type'] === 'product' ? ($data['product_ids'] ?? []) : []);
    }

    private function minor(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);
        if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $value)) {
            throw ValidationException::withMessages([
                $field => 'Enter a valid peso amount with up to two decimal places.',
            ]);
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function maximumDiscountMinor(array $data): ?int
    {
        if (! in_array($data['discount_type'], [
            PlatformPromotion::DISCOUNT_PERCENT,
            PlatformPromotion::DISCOUNT_FREE_SHIPPING,
            PlatformPromotion::DISCOUNT_PERCENT_SHIPPING,
        ], true)) {
            return null;
        }

        return $this->minor($data['max_discount'] ?? null, 'max_discount');
    }
}
