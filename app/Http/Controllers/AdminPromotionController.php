<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\PlatformPromotion;
use App\Models\Product;
use App\Models\PromotionRedemption;
use App\Models\Store;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            ->withSum([
                'redemptions as active_discount_minor' => fn ($query) => $query
                    ->whereIn('status', [PromotionRedemption::STATUS_RESERVED, PromotionRedemption::STATUS_REDEEMED]),
            ], 'discount_minor')
            ->withSum([
                'redemptions as active_shipping_discount_minor' => fn ($query) => $query
                    ->whereIn('status', [PromotionRedemption::STATUS_RESERVED, PromotionRedemption::STATUS_REDEEMED]),
            ], 'shipping_discount_minor')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.promotions.index', [
            'promotions' => $promotions,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'stores' => Store::query()->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()
                ->visibleToBuyers()
                ->with('store:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'store_id']),
        ]);
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $request->merge([
            'start_time' => $request->input('start_time', '00:00'),
            'end_time' => $request->input('end_time', '23:59'),
            'code' => filled($request->input('code'))
                ? Str::upper(trim((string) $request->input('code')))
                : null,
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
            'value' => [
                Rule::excludeIf(! in_array((string) $request->input('discount_type'), [
                    PlatformPromotion::DISCOUNT_FIXED,
                    PlatformPromotion::DISCOUNT_FIXED_SHIPPING,
                ], true)),
                'nullable', 'numeric', 'min:0.01', 'max:10000000', 'decimal:0,2',
            ],
            'discount_percent' => [
                Rule::excludeIf(! in_array((string) $request->input('discount_type'), [
                    PlatformPromotion::DISCOUNT_PERCENT,
                    PlatformPromotion::DISCOUNT_PERCENT_SHIPPING,
                ], true)),
                'nullable', 'integer', 'min:1', 'max:100',
            ],
            'max_discount' => [
                Rule::excludeIf(! in_array((string) $request->input('discount_type'), [
                    PlatformPromotion::DISCOUNT_PERCENT,
                    PlatformPromotion::DISCOUNT_FREE_SHIPPING,
                    PlatformPromotion::DISCOUNT_PERCENT_SHIPPING,
                ], true)),
                'nullable', 'numeric', 'min:0.01', 'max:10000000', 'decimal:0,2',
            ],
            'minimum_spend' => ['nullable', 'numeric', 'min:0', 'max:10000000', 'decimal:0,2'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'redemption_limit' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'per_buyer_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:100000000', 'decimal:0,2'],
            'status' => ['required', Rule::in([PlatformPromotion::STATUS_DRAFT, PlatformPromotion::STATUS_ACTIVE, PlatformPromotion::STATUS_PAUSED])],
            'description' => ['nullable', 'string', 'max:255'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'is_claimable' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
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
                'is_featured' => false,
                'banner_path' => null,
                'banner_disk' => null,
                'banner_title' => null,
                'banner_link' => null,
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

        [$successTitle, $successMessage] = match ($promotion->kind) {
            PlatformPromotion::KIND_FREE_SHIPPING => [
                'Promotion created',
                'Free shipping voucher created successfully: '.$promotion->name.'.',
            ],
            PlatformPromotion::KIND_AUTOMATIC_DISCOUNT => [
                'Promotion created',
                'Automatic discount created successfully: '.$promotion->name.'.',
            ],
            default => [
                'Promotion created',
                'Platform voucher created successfully: '.$promotion->name.'.',
            ],
        };

        return redirect()->route('admin.promotions')
            ->with('success_title', $successTitle)
            ->with('success', $successMessage);
    }

    public function updateStatus(Request $request, PlatformPromotion $promotion, AuditService $audit): RedirectResponse
    {
        abort_if($promotion->isCampaign(), 404);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                PlatformPromotion::STATUS_ACTIVE,
                PlatformPromotion::STATUS_PAUSED,
            ])],
        ]);

        [$promotion, $oldStatus] = DB::transaction(function () use ($promotion, $data): array {
            $locked = PlatformPromotion::query()
                ->whereKey($promotion->id)
                ->whereNull('campaign_type')
                ->lockForUpdate()
                ->firstOrFail();

            if ($data['status'] === PlatformPromotion::STATUS_ACTIVE) {
                $now = now('Asia/Manila');
                if ($locked->statusKey($now) === PlatformPromotion::STATUS_EXPIRED) {
                    throw ValidationException::withMessages([
                        'status' => 'An expired promotion cannot be reactivated. Create a new scheduled promotion instead.',
                    ]);
                }

                $redemptions = PromotionRedemption::query()
                    ->where('platform_promotion_id', $locked->id)
                    ->whereIn('status', [PromotionRedemption::STATUS_RESERVED, PromotionRedemption::STATUS_REDEEMED])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['buyer_id', 'discount_minor', 'shipping_discount_minor']);
                $budgetSpendMinor = (int) $redemptions->sum(fn (PromotionRedemption $redemption): int =>
                    (int) $redemption->discount_minor + (int) $redemption->shipping_discount_minor);

                if ($locked->isExhausted($redemptions->count(), $budgetSpendMinor)) {
                    throw ValidationException::withMessages([
                        'status' => 'This promotion has exhausted its redemption limit or platform budget and cannot be reactivated.',
                    ]);
                }
            }

            $oldStatus = (string) $locked->status;
            $locked->update(['status' => $data['status']]);

            return [$locked, $oldStatus];
        }, attempts: 3);

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
        $discountType = $data['discount_type'];
        $fixedType = in_array($discountType, [
            PlatformPromotion::DISCOUNT_FIXED,
            PlatformPromotion::DISCOUNT_FIXED_SHIPPING,
        ], true);
        $percentageType = in_array($discountType, [
            PlatformPromotion::DISCOUNT_PERCENT,
            PlatformPromotion::DISCOUNT_PERCENT_SHIPPING,
        ], true);
        $cappedType = in_array($discountType, [
            PlatformPromotion::DISCOUNT_PERCENT,
            PlatformPromotion::DISCOUNT_FREE_SHIPPING,
            PlatformPromotion::DISCOUNT_PERCENT_SHIPPING,
        ], true);
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

        if (! in_array($data['kind'], [PlatformPromotion::KIND_VOUCHER, PlatformPromotion::KIND_FREE_SHIPPING], true)
            && filled($data['code'] ?? null)) {
            throw ValidationException::withMessages([
                'code' => 'Voucher codes are available only for platform vouchers and shipping vouchers.',
            ]);
        }

        if ($data['kind'] === PlatformPromotion::KIND_VOUCHER
            && blank($data['code'] ?? null)
            && ! (bool) ($data['is_claimable'] ?? false)) {
            throw ValidationException::withMessages([
                'is_claimable' => 'A platform voucher needs a code or must be claimable in My Vouchers.',
            ]);
        }

        if (in_array($data['kind'], [PlatformPromotion::KIND_AUTOMATIC_DISCOUNT, PlatformPromotion::KIND_FREE_SHIPPING], true)
            && ! (bool) ($data['is_public'] ?? true)) {
            throw ValidationException::withMessages([
                'is_public' => 'Automatic offers and shipping offers must be publicly available.',
            ]);
        }

        if (($data['kind'] === PlatformPromotion::KIND_VOUCHER && (bool) ($data['is_claimable'] ?? false))
            && ! (bool) ($data['is_public'] ?? true)) {
            throw ValidationException::withMessages([
                'is_public' => 'A claimable voucher must be publicly visible in My Vouchers.',
            ]);
        }

        if ($fixedType && (blank($data['value'] ?? null) || $this->minor($data['value'], 'value') < 1)) {
            throw ValidationException::withMessages([
                'value' => 'Enter a fixed discount amount greater than ₱0.00.',
            ]);
        }

        if ($percentageType && blank($data['discount_percent'] ?? null)) {
            throw ValidationException::withMessages([
                'discount_percent' => 'Enter the discount percentage.',
            ]);
        }

        if (! $fixedType && filled($data['value'] ?? null)) {
            throw ValidationException::withMessages([
                'value' => 'A fixed amount is not used by this discount type.',
            ]);
        }

        if (! $percentageType && filled($data['discount_percent'] ?? null)) {
            throw ValidationException::withMessages([
                'discount_percent' => 'A percentage is not used by this discount type.',
            ]);
        }

        if (! $cappedType && filled($data['max_discount'] ?? null)) {
            throw ValidationException::withMessages([
                'max_discount' => 'A maximum discount cap is not used by this discount type.',
            ]);
        }

        if ($cappedType && filled($data['max_discount'] ?? null)
            && $this->minor($data['max_discount'], 'max_discount') < 1) {
            throw ValidationException::withMessages([
                'max_discount' => 'Enter a maximum discount greater than ₱0.00, or leave the cap blank.',
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
