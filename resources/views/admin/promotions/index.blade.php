@extends('layouts.admin')

@section('title', 'Platform Vouchers & Automatic Offers')
@section('page-title', 'Platform Vouchers & Automatic Offers')

@section('content')
@php
    $defaultPromotionStart = now('Asia/Manila');
    $defaultPromotionEnd = now('Asia/Manila')->addDays(30);
    $initialKind = old('kind', 'voucher');
    $codeAvailable = in_array($initialKind, ['voucher', 'free_shipping'], true);
    $oldCategoryIds = old('category_ids', []);
    $oldStoreIds = old('store_ids', []);
    $oldProductIds = old('product_ids', []);
    $oldCategoryIds = is_array($oldCategoryIds) ? array_map('intval', $oldCategoryIds) : [];
    $oldStoreIds = is_array($oldStoreIds) ? array_map('intval', $oldStoreIds) : [];
    $oldProductIds = is_array($oldProductIds) ? array_map('intval', $oldProductIds) : [];
    $claimableAvailable = in_array($initialKind, ['voucher', 'free_shipping'], true);
    $publicToggleAvailable = $initialKind === 'voucher';
    $claimableHelp = $initialKind === 'free_shipping'
        ? 'Checked: buyers can claim it in My Vouchers. Unchecked: it applies automatically when eligible.'
        : 'Buyers can claim this voucher, or enter its code at checkout.';
    $initialDiscountType = old('discount_type', $initialKind === 'free_shipping' ? 'free_shipping' : 'fixed');
    $fixedDiscountApplies = in_array($initialDiscountType, ['fixed', 'fixed_shipping'], true);
    $percentageDiscountApplies = in_array($initialDiscountType, ['percent', 'percent_shipping'], true);
    $maximumDiscountApplies = in_array($initialDiscountType, ['percent', 'free_shipping', 'percent_shipping'], true);
    $maximumDiscountLabel = in_array($initialDiscountType, ['free_shipping', 'percent_shipping'], true)
        ? 'Maximum shipping discount (₱)'
        : 'Maximum discount (₱)';
    $maximumDiscountHelp = match ($initialDiscountType) {
        'free_shipping' => 'Optional cap per checkout. Blank covers the full eligible shipping fee, within the offer budget.',
        'percent_shipping' => 'Optional cap per checkout on the shipping discount.',
        'percent' => 'Optional cap per checkout on the merchandise discount.',
        default => 'An optional maximum applies to percentage and full free-shipping offers.',
    };
@endphp
<section class="page-hero">
    <div>
        <span class="eyebrow">Marketing</span>
        <h1>Platform Vouchers & Automatic Offers</h1>
        <p>Create Bearly-funded vouchers and automatic offers. Campaign events, banners, and homepage features are managed separately in Bearly Campaigns.</p>
    </div>
    <div class="hero-context-stat">
        <span class="hero-context-icon"><i data-lucide="badge-percent"></i></span>
        <div>
            <strong>{{ $promotions->total() }}</strong>
            <span>Platform offers</span>
        </div>
    </div>
</section>

@include('admin.partials.success-toast')

@if ($errors->any())
    <div class="panel" role="alert">
        <strong>Check the promotion details.</strong>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="promotion-admin-toolbar">
    <p class="promotion-admin-toolbar-hint">Go here to create and manage campaigns.</p>
    <a class="button button-secondary" href="{{ route('admin.campaigns') }}">
        <i data-lucide="calendar-range"></i> Manage Bearly Campaigns
    </a>
</div>

<section class="panel promotion-admin-panel">
    <div class="panel-heading">
        <div>
            <span class="eyebrow">Create promotion</span>
            <h2>Platform-funded offer</h2>
        </div>
        <p class="promotion-admin-note">Bearly funds these discounts. Seller earnings and commission are calculated before the platform discount.</p>
    </div>

    <form class="promotion-admin-form" method="POST" action="{{ route('admin.promotions.store') }}" data-promotion-form>
        @csrf

        <section class="promotion-form-section" aria-labelledby="promotion-details-title">
            <div class="promotion-form-section-heading">
                <h3 id="promotion-details-title">1. Promotion Details</h3>
                <p>Name the offer and choose how it is applied.</p>
            </div>
            <div class="promotion-form-grid">
                <label class="form-field promotion-field-wide">
                    <span>Promotion name</span>
                    <input class="text-field" name="name" value="{{ old('name') }}" maxlength="120" placeholder="e.g. Payday Platform Voucher" required>
                    @error('name')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
                <label class="form-field">
                    <span>Promotion kind</span>
                    <select class="select-field" name="kind" required data-promotion-kind>
                        @foreach ([
                            'voucher' => 'Platform voucher',
                            'automatic_discount' => 'Automatic discount',
                            'free_shipping' => 'Shipping offer',
                        ] as $value => $label)
                            <option value="{{ $value }}" @selected($initialKind === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('kind')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
                <label class="form-field promotion-field-wide">
                    <span>Short description <small>Optional</small></span>
                    <input class="text-field" name="description" value="{{ old('description') }}" maxlength="255" placeholder="Shown in My Vouchers and checkout.">
                    @error('description')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
            </div>
        </section>

        <section class="promotion-form-section" aria-labelledby="promotion-discount-title">
            <div class="promotion-form-section-heading">
                <h3 id="promotion-discount-title">2. Discount Configuration</h3>
                <p>Choose one discount method. Shipping offers use the saved checkout shipping quote.</p>
            </div>
            <div class="promotion-form-grid">
                <label class="form-field">
                    <span>Discount type</span>
                    <select class="select-field" name="discount_type" required data-promotion-discount-type>
                        <optgroup label="Merchandise discount" data-discount-group="merchandise">
                            <option value="fixed" @selected($initialDiscountType === 'fixed')>Fixed amount off</option>
                            <option value="percent" @selected($initialDiscountType === 'percent')>Percentage off</option>
                        </optgroup>
                        <optgroup label="Shipping discount" data-discount-group="shipping">
                            <option value="free_shipping" @selected($initialDiscountType === 'free_shipping')>Full free shipping (optional cap)</option>
                            <option value="fixed_shipping" @selected($initialDiscountType === 'fixed_shipping')>Fixed amount off shipping</option>
                            <option value="percent_shipping" @selected($initialDiscountType === 'percent_shipping')>Percentage off shipping</option>
                        </optgroup>
                    </select>
                    @error('discount_type')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
                <label class="form-field" data-promotion-fixed-field @unless ($fixedDiscountApplies) hidden @endunless>
                    <span data-promotion-fixed-label>{{ in_array($initialDiscountType, ['fixed_shipping', 'percent_shipping', 'free_shipping'], true) ? 'Fixed shipping discount (₱)' : 'Fixed discount (₱)' }}</span>
                    <input class="text-field" type="number" min="0.01" max="10000000" step="0.01" name="value" value="{{ old('value') }}" placeholder="100.00" data-promotion-fixed-input @disabled(! $fixedDiscountApplies) @required($fixedDiscountApplies)>
                    @error('value')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
                <label class="form-field" data-promotion-percent-field @unless ($percentageDiscountApplies) hidden @endunless>
                    <span data-promotion-percent-label>{{ in_array($initialDiscountType, ['percent_shipping'], true) ? 'Shipping discount percentage' : 'Discount percentage' }}</span>
                    <input class="text-field" type="number" min="1" max="100" step="1" name="discount_percent" value="{{ old('discount_percent') }}" placeholder="15" data-promotion-percent-input @disabled(! $percentageDiscountApplies) @required($percentageDiscountApplies)>
                    @error('discount_percent')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
                <label class="form-field" data-promotion-max-discount-field @unless ($maximumDiscountApplies) hidden @endunless>
                    <span data-promotion-max-discount-label>{{ $maximumDiscountLabel }}</span>
                    <input class="text-field" type="number" min="0.01" max="10000000" step="0.01" name="max_discount" value="{{ old('max_discount') }}" placeholder="500.00" data-promotion-max-discount-input @disabled(! $maximumDiscountApplies)>
                    <small data-promotion-max-discount-help>{{ $maximumDiscountHelp }}</small>
                    @error('max_discount')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
            </div>
        </section>

        <section class="promotion-form-section" aria-labelledby="promotion-eligibility-title">
            <div class="promotion-form-section-heading">
                <h3 id="promotion-eligibility-title">3. Redemption &amp; Eligibility</h3>
                <p data-redemption-method-copy>
                    {{ $initialKind === 'automatic_discount'
                        ? 'This offer applies automatically when eligible; no code or claim is needed.'
                        : ($initialKind === 'free_shipping'
                            ? 'Choose whether buyers claim this shipping offer or it applies automatically.'
                            : 'Buyers can claim this voucher or enter its code directly at checkout.') }}
                </p>
            </div>
            <div class="promotion-form-grid">
                <label class="form-field" data-promotion-code-field @unless ($codeAvailable) hidden @endunless>
                    <span>Voucher code <small data-promotion-code-label>Optional when claimable</small></span>
                    <input class="text-field" name="code" value="{{ old('code') }}" maxlength="40" pattern="[A-Za-z0-9_-]+" placeholder="BEARLY100" data-promotion-code-input @disabled(! $codeAvailable) @required($initialKind === 'voucher' && ! (bool) old('is_claimable', true))>
                    <small>Codes are unique across platform and Store offers and work at checkout without prior claiming. A shipping offer without a code can be claimable or automatic.</small>
                    @error('code')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
                <label class="form-field">
                    <span>Minimum spend (₱)</span>
                    <input class="text-field" type="number" min="0" max="10000000" step="0.01" name="minimum_spend" value="{{ old('minimum_spend', '0') }}" required>
                    <small>Based on eligible merchandise; enter 0 for no minimum.</small>
                    @error('minimum_spend')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
                <label class="form-field">
                    <span>Audience scope</span>
                    <select class="select-field" name="scope_type" required data-promotion-scope>
                        <option value="all" @selected(old('scope_type', 'all') === 'all')>All eligible products</option>
                        <option value="category" @selected(old('scope_type') === 'category')>Selected categories</option>
                        <option value="store" @selected(old('scope_type') === 'store')>Selected Stores</option>
                        <option value="product" @selected(old('scope_type') === 'product')>Selected products</option>
                    </select>
                    @error('scope_type')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
                <label class="form-field promotion-field-wide" data-promotion-category-field @if(old('scope_type', 'all') !== 'category') hidden @endif>
                    <span>Eligible categories</span>
                    <select class="select-field promotion-multi-select" name="category_ids[]" multiple size="5" @disabled(old('scope_type', 'all') !== 'category')>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected(in_array($category->id, $oldCategoryIds, true))>{{ $category->name }}</option>
                        @endforeach
                    </select>
                    <small>Select one or more categories.</small>
                    @error('category_ids')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
                <label class="form-field promotion-field-wide" data-promotion-store-field @if(old('scope_type', 'all') !== 'store') hidden @endif>
                    <span>Eligible Stores</span>
                    <select class="select-field promotion-multi-select" name="store_ids[]" multiple size="5" @disabled(old('scope_type', 'all') !== 'store')>
                        @foreach ($stores as $store)
                            <option value="{{ $store->id }}" @selected(in_array($store->id, $oldStoreIds, true))>{{ $store->name }}</option>
                        @endforeach
                    </select>
                    <small>Select one or more Stores.</small>
                    @error('store_ids')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
                <label class="form-field promotion-field-wide" data-promotion-product-field @if(old('scope_type', 'all') !== 'product') hidden @endif>
                    <span>Eligible products</span>
                    <select class="select-field promotion-multi-select" name="product_ids[]" multiple size="5" @disabled(old('scope_type', 'all') !== 'product')>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected(in_array($product->id, $oldProductIds, true))>{{ $product->store?->name ?? 'Store' }} · {{ $product->name }}</option>
                        @endforeach
                    </select>
                    <small>Select one or more buyer-visible products.</small>
                    @error('product_ids')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
            </div>
            <div class="promotion-admin-options promotion-redemption-options">
                <input type="hidden" name="is_public" value="{{ $publicToggleAvailable ? '0' : '1' }}" data-promotion-public-fallback>
                <label data-promotion-public-field @unless ($publicToggleAvailable) hidden @endunless>
                    <input type="checkbox" name="is_public" value="1" data-promotion-public-input @checked((bool) old('is_public', true)) @disabled(! $publicToggleAvailable)>
                    <span>Publicly visible <small>Show this voucher in My Vouchers and public offers. Uncheck for a code-only private voucher.</small></span>
                </label>
                <input type="hidden" name="is_claimable" value="0" data-promotion-claimable-fallback @disabled($claimableAvailable)>
                <label data-promotion-claimable-field @unless ($claimableAvailable) hidden @endunless>
                    <input type="checkbox" name="is_claimable" value="1" data-promotion-claimable-input @checked((bool) old('is_claimable', true)) @disabled(! $claimableAvailable)>
                    <span class="promotion-admin-claimable-copy">
                        <span data-promotion-claimable-label>{{ $initialKind === 'free_shipping' ? 'Claimable in My Vouchers' : 'Claimable voucher' }}</span>
                        <small data-promotion-claimable-help>{{ $claimableHelp }}</small>
                    </span>
                </label>
            </div>
            @error('is_public')<small class="form-error" role="alert">{{ $message }}</small>@enderror
            @error('is_claimable')<small class="form-error" role="alert">{{ $message }}</small>@enderror
        </section>

        <section class="promotion-form-section" aria-labelledby="promotion-limits-title">
            <div class="promotion-form-section-heading">
                <h3 id="promotion-limits-title">4. Usage Limits &amp; Budget</h3>
                <p>Limits include orders with a reserved voucher redemption.</p>
            </div>
            <div class="promotion-form-grid">
                <label class="form-field">
                    <span>Total redemption limit <small>Optional</small></span>
                    <input class="text-field" type="number" min="1" max="4294967295" name="redemption_limit" value="{{ old('redemption_limit') }}" placeholder="Unlimited">
                    @error('redemption_limit')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
                <label class="form-field">
                    <span>Per-Buyer limit</span>
                    <input class="text-field" type="number" min="1" max="1000" name="per_buyer_limit" value="{{ old('per_buyer_limit', '1') }}" required>
                    @error('per_buyer_limit')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
                <label class="form-field">
                    <span>Platform budget (₱) <small>Optional; blank means no cap</small></span>
                    <input class="text-field" type="number" min="0" max="100000000" step="0.01" name="budget" value="{{ old('budget') }}" placeholder="No cap">
                    <small>Redemptions stop if the full discount will not fit. A ₱0 budget disables redemptions; blank means no cap.</small>
                    @error('budget')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
            </div>
        </section>

        <section class="promotion-form-section" aria-labelledby="promotion-schedule-title">
            <div class="promotion-form-section-heading">
                <h3 id="promotion-schedule-title">5. Schedule &amp; Availability</h3>
                <p>Times use Philippines time. Scheduled, active, expired, and exhausted labels are calculated from the saved settings and usage.</p>
            </div>
            <div class="promotion-form-grid">
                <label class="form-field">
                    <span>Starts on</span>
                    <input class="date-field" type="date" name="starts_on" value="{{ old('starts_on', $defaultPromotionStart->toDateString()) }}" required>
                    @error('starts_on')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
                <label class="form-field">
                    <span>Start time (PHT)</span>
                    <input class="date-field" type="time" name="start_time" value="{{ old('start_time', $defaultPromotionStart->format('H:i')) }}" required>
                    @error('start_time')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
                <label class="form-field">
                    <span>Ends on</span>
                    <input class="date-field" type="date" name="ends_on" value="{{ old('ends_on', $defaultPromotionEnd->toDateString()) }}" required>
                    @error('ends_on')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
                <label class="form-field">
                    <span>End time (PHT)</span>
                    <input class="date-field" type="time" name="end_time" value="{{ old('end_time', '23:59') }}" required>
                    @error('end_time')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
                <label class="form-field">
                    <span>Initial status</span>
                    <select class="select-field" name="status" required>
                        <option value="draft" @selected(old('status', 'draft') === 'draft')>Draft</option>
                        <option value="active" @selected(old('status') === 'active')>Active / publish</option>
                        <option value="paused" @selected(old('status') === 'paused')>Paused</option>
                    </select>
                    @error('status')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
            </div>
        </section>

        <section class="promotion-form-section" aria-labelledby="promotion-advanced-title">
            <div class="promotion-form-section-heading">
                <h3 id="promotion-advanced-title">6. Advanced Settings</h3>
                <p>Optional internal ordering and full terms.</p>
            </div>
            <div class="promotion-form-grid">
                <label class="form-field">
                    <span>Display order <small>Optional</small></span>
                    <input class="text-field" type="number" min="0" max="10000" name="position" value="{{ old('position', '0') }}">
                    @error('position')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
                <label class="form-field promotion-field-wide">
                    <span>Terms and conditions <small>Optional</small></span>
                    <textarea class="text-field" name="terms" rows="3" maxlength="2000">{{ old('terms') }}</textarea>
                    @error('terms')<small class="form-error" role="alert">{{ $message }}</small>@enderror
                </label>
            </div>
        </section>

        <div class="promotion-admin-actions">
            <button class="button button-primary" type="submit"><i data-lucide="plus"></i> Create promotion</button>
            <p>Platform-funded discounts do not reduce Seller earnings. Store vouchers remain Seller-funded.</p>
        </div>
    </form>
</section>

<section class="panel">
    <div class="panel-heading panel-heading-wrap">
        <div>
            <span class="eyebrow">Current offers</span>
            <h2>Promotion schedule</h2>
        </div>
        <label class="field-with-icon compact-field">
            <i data-lucide="search"></i>
            <input type="search" placeholder="Search promotions..." data-table-search="promotions-table">
        </label>
    </div>

    <div class="table-wrap">
        <table class="admin-table" id="promotions-table">
            <thead>
                <tr>
                    <th>Promotion</th>
                    <th>Type</th>
                    <th>Value</th>
                    <th>Scope</th>
                    <th>Schedule</th>
                    <th>Usage</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($promotions as $promotion)
                    @php
                        $status = $promotion->statusKey();
                        if ($status === 'ended') {
                            $status = 'expired';
                        }
                        $activePromotionSpendMinor = (int) ($promotion->active_discount_minor ?? 0)
                            + (int) ($promotion->active_shipping_discount_minor ?? 0);
                        if ($status === \App\Models\PlatformPromotion::STATUS_ACTIVE
                            && $promotion->isExhausted((int) $promotion->active_redemptions_count, $activePromotionSpendMinor)) {
                            $status = 'exhausted';
                        }
                        $statusBadge = match ($status) {
                            'active' => 'badge-success',
                            'scheduled' => 'badge-info',
                            'paused' => 'badge-warning',
                            'exhausted', 'expired' => 'badge-danger',
                            default => 'badge-neutral',
                        };
                    @endphp
                    <tr data-table-row data-search="{{ strtolower($promotion->name.' '.$promotion->code.' '.$promotion->kind) }}">
                        <td>
                            <div class="table-primary-secondary">
                                <strong>{{ $promotion->name }}</strong>
                                <small>{{ $promotion->code ?: 'No code' }}</small>
                            </div>
                        </td>
                        <td>{{ str_replace('_', ' ', ucfirst($promotion->kind)) }}</td>
                        <td>{{ $promotion->discountLabel() }}</td>
                        <td>
                            @if ($promotion->stores->isNotEmpty())
                                {{ $promotion->stores->pluck('name')->join(', ') }}
                            @elseif ($promotion->categories->isNotEmpty())
                                {{ $promotion->categories->pluck('name')->join(', ') }}
                            @elseif ($promotion->products->isNotEmpty())
                                {{ $promotion->products->pluck('name')->take(2)->join(', ') }}{{ $promotion->products->count() > 2 ? ' +'.($promotion->products->count() - 2) : '' }}
                            @else
                                All products
                            @endif
                        </td>
                        <td>
                            <div class="table-primary-secondary">
                                <strong>{{ $promotion->startsAt()->format('M j, Y g:i A') }} PHT</strong>
                                <small>to {{ $promotion->endsAt()->format('M j, Y g:i A') }} PHT</small>
                            </div>
                        </td>
                        <td>
                            <div class="table-primary-secondary">
                                <strong>{{ $promotion->active_redemptions_count }} used</strong>
                                <small>{{ $promotion->claims_count }} claimed</small>
                                @if ($promotion->budget_minor !== null)
                                    <small>₱{{ number_format($activePromotionSpendMinor / 100, 2) }} of ₱{{ number_format($promotion->budget_minor / 100, 2) }} budget</small>
                                @endif
                            </div>
                        </td>
                        <td>
                            <span class="status-badge {{ $statusBadge }}">
                                {{ ucfirst($status) }}
                            </span>
                        </td>
                        <td>
                            @if (in_array($status, ['draft', 'active', 'scheduled', 'paused'], true))
                                <form method="POST" action="{{ route('admin.promotions.status', $promotion) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="{{ in_array($promotion->status, ['paused', 'draft'], true) ? 'active' : 'paused' }}">
                                    <button class="button button-ghost promotion-status-button" type="submit">
                                        {{ $promotion->status === 'paused' ? 'Activate' : ($promotion->status === 'draft' ? 'Publish' : 'Pause') }}
                                    </button>
                                </form>
                            @else
                                <span class="empty-copy">No action</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <div class="empty-state">
                                <strong>No platform promotions yet.</strong>
                                <p>Create a Bearly-funded voucher or automatic offer above. Seller store vouchers are managed in Seller Center.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($promotions->hasPages())
        <div class="panel-footer">{{ $promotions->links() }}</div>
    @endif
</section>
@endsection

@push('scripts')
<script>
(() => {
    const form = document.querySelector('[data-promotion-form]');
    if (!form) return;

    const scope = form.querySelector('[data-promotion-scope]');
    const kind = form.querySelector('[data-promotion-kind]');
    const discountType = form.querySelector('[data-promotion-discount-type]');
    const categoryField = form.querySelector('[data-promotion-category-field]');
    const storeField = form.querySelector('[data-promotion-store-field]');
    const productField = form.querySelector('[data-promotion-product-field]');
    const fixedField = form.querySelector('[data-promotion-fixed-field]');
    const percentField = form.querySelector('[data-promotion-percent-field]');
    const fixedInput = form.querySelector('[data-promotion-fixed-input]');
    const percentInput = form.querySelector('[data-promotion-percent-input]');
    const fixedLabel = form.querySelector('[data-promotion-fixed-label]');
    const percentLabel = form.querySelector('[data-promotion-percent-label]');
    const maximumDiscountField = form.querySelector('[data-promotion-max-discount-field]');
    const maximumDiscountInput = form.querySelector('[data-promotion-max-discount-input]');
    const maximumDiscountLabel = form.querySelector('[data-promotion-max-discount-label]');
    const maximumDiscountHelp = form.querySelector('[data-promotion-max-discount-help]');
    const claimableField = form.querySelector('[data-promotion-claimable-field]');
    const claimableFallback = form.querySelector('[data-promotion-claimable-fallback]');
    const claimableInput = form.querySelector('[data-promotion-claimable-input]');
    const claimableHelp = form.querySelector('[data-promotion-claimable-help]');
    const claimableLabel = form.querySelector('[data-promotion-claimable-label]');
    const publicField = form.querySelector('[data-promotion-public-field]');
    const publicFallback = form.querySelector('[data-promotion-public-fallback]');
    const publicInput = form.querySelector('[data-promotion-public-input]');
    const codeField = form.querySelector('[data-promotion-code-field]');
    const codeInput = form.querySelector('[data-promotion-code-input]');
    const codeLabel = form.querySelector('[data-promotion-code-label]');
    const redemptionMethodCopy = form.querySelector('[data-redemption-method-copy]');

    const syncScope = () => {
        const value = scope?.value || 'all';
        if (categoryField) categoryField.hidden = value !== 'category';
        if (storeField) storeField.hidden = value !== 'store';
        if (productField) productField.hidden = value !== 'product';
        [categoryField, storeField, productField].forEach((field) => {
            const input = field?.querySelector('select');
            if (input) input.disabled = field.hidden;
        });
    };

    const syncDiscount = () => {
        const kindValue = kind?.value || 'voucher';
        const shippingKind = kindValue === 'free_shipping';
        const merchandiseGroup = form.querySelector('[data-discount-group="merchandise"]');
        const shippingGroup = form.querySelector('[data-discount-group="shipping"]');
        if (merchandiseGroup) {
            merchandiseGroup.hidden = shippingKind;
            merchandiseGroup.disabled = shippingKind;
        }
        if (shippingGroup) {
            shippingGroup.hidden = !shippingKind;
            shippingGroup.disabled = !shippingKind;
        }

        const validTypes = shippingKind
            ? ['free_shipping', 'fixed_shipping', 'percent_shipping']
            : ['fixed', 'percent'];
        let value = discountType?.value || (shippingKind ? 'free_shipping' : 'fixed');
        if (!validTypes.includes(value)) {
            value = shippingKind ? 'free_shipping' : 'fixed';
            if (discountType) discountType.value = value;
        }

        const isPercent = ['percent', 'percent_shipping'].includes(value);
        const isFixed = ['fixed', 'fixed_shipping'].includes(value);
        const isFullShipping = value === 'free_shipping';

        if (fixedField) fixedField.hidden = !isFixed;
        if (percentField) percentField.hidden = !isPercent;
        if (fixedInput) {
            fixedInput.disabled = !isFixed;
            fixedInput.required = isFixed;
        }
        if (percentInput) {
            percentInput.disabled = !isPercent;
            percentInput.required = isPercent;
        }
        if (fixedLabel) fixedLabel.textContent = value === 'fixed_shipping' ? 'Fixed shipping discount (₱)' : 'Fixed discount (₱)';
        if (percentLabel) percentLabel.textContent = value === 'percent_shipping' ? 'Shipping discount percentage' : 'Discount percentage';

        const maximumApplies = isPercent || isFullShipping;
        if (maximumDiscountField) maximumDiscountField.hidden = !maximumApplies;
        if (maximumDiscountInput) maximumDiscountInput.disabled = !maximumApplies;
        if (maximumDiscountLabel) {
            maximumDiscountLabel.textContent = isFullShipping || value === 'percent_shipping'
                ? 'Maximum shipping discount (₱)'
                : 'Maximum discount (₱)';
        }
        if (maximumDiscountHelp) {
            maximumDiscountHelp.textContent = isFullShipping
                ? 'Optional cap per checkout. Blank covers the full eligible shipping fee, within the offer budget.'
                : value === 'percent_shipping'
                    ? 'Optional cap per checkout on the shipping discount.'
                    : value === 'percent'
                        ? 'Optional cap per checkout on the merchandise discount.'
                        : 'An optional maximum applies to percentage and full free-shipping offers.';
        }

        const claimableAvailable = ['voucher', 'free_shipping'].includes(kindValue);
        if (claimableField) claimableField.hidden = !claimableAvailable;
        if (claimableFallback) claimableFallback.disabled = claimableAvailable;
        if (claimableInput) claimableInput.disabled = !claimableAvailable;
        const publicToggleAvailable = kindValue === 'voucher';
        if (publicField) publicField.hidden = !publicToggleAvailable;
        if (publicFallback) {
            publicFallback.disabled = false;
            publicFallback.value = publicToggleAvailable ? '0' : '1';
        }
        if (publicInput) publicInput.disabled = !publicToggleAvailable;
        const codeAvailable = ['voucher', 'free_shipping'].includes(kindValue);
        if (codeField) codeField.hidden = !codeAvailable;
        const codeRequired = kindValue === 'voucher' && Boolean(claimableInput) && !claimableInput.checked;
        if (codeInput) {
            codeInput.disabled = !codeAvailable;
            codeInput.required = codeRequired;
        }
        if (codeLabel) codeLabel.textContent = codeRequired ? 'Required when claimable is off' : 'Optional';
        if (claimableLabel) claimableLabel.textContent = kindValue === 'free_shipping' ? 'Claimable shipping voucher' : 'Claimable voucher';
        if (claimableHelp) {
            claimableHelp.textContent = kindValue === 'free_shipping'
                ? (codeInput?.value
                    ? 'A voucher code can be applied directly at checkout; checking this also lets buyers claim it in My Vouchers.'
                    : 'Checked: buyers claim it in My Vouchers. Unchecked: it applies automatically when eligible.')
                : 'Buyers can claim it from My Vouchers. Voucher codes can also be entered directly at checkout.';
        }
        if (redemptionMethodCopy) {
            redemptionMethodCopy.textContent = kindValue === 'automatic_discount'
                ? 'This offer applies automatically when eligible; no code or claim is required.'
                : kindValue === 'free_shipping'
                    ? (codeInput?.value
                        ? 'Buyers can enter this shipping voucher code at checkout. Claiming is optional.'
                        : (claimableInput?.checked
                            ? 'Buyers claim this shipping voucher in My Vouchers, then select it at checkout.'
                            : 'This shipping offer applies automatically when eligible; no code or claim is required.'))
                    : 'Buyers can claim this voucher or enter its code directly at checkout. A code does not require prior claiming.';
        }
    };

    scope?.addEventListener('change', syncScope);
    kind?.addEventListener('change', syncDiscount);
    discountType?.addEventListener('change', syncDiscount);
    claimableInput?.addEventListener('change', syncDiscount);
    codeInput?.addEventListener('input', syncDiscount);
    syncScope();
    syncDiscount();
})();
</script>
@endpush
