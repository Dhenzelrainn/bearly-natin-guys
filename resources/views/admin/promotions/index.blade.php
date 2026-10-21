@extends('layouts.admin')

@section('title', 'Platform Vouchers & Automatic Offers')
@section('page-title', 'Platform Vouchers & Automatic Offers')

@section('content')
@php
    $defaultPromotionStart = now('Asia/Manila');
    $defaultPromotionEnd = now('Asia/Manila')->addDays(30);
    $initialKind = old('kind', 'voucher');
    $claimableAvailable = in_array($initialKind, ['voucher', 'free_shipping'], true);
    $claimableHelp = $initialKind === 'free_shipping'
        ? 'Checked: buyers claim it in My Vouchers and select it at checkout. Unchecked: it applies automatically when eligible.'
        : 'Buyers can claim this platform voucher and select it at checkout.';
    $initialDiscountType = old('discount_type', 'fixed');
    $maximumDiscountApplies = in_array($initialDiscountType, ['percent', 'free_shipping', 'percent_shipping'], true);
    $maximumDiscountLabel = in_array($initialDiscountType, ['free_shipping', 'percent_shipping'], true)
        ? 'Maximum shipping discount (₱)'
        : 'Maximum discount (₱)';
    $maximumDiscountHelp = match ($initialDiscountType) {
        'free_shipping' => 'Optional cap per checkout; blank covers the full eligible shipping cost.',
        'percent_shipping' => 'Optional cap per checkout; limits the percentage-based shipping discount.',
        'percent' => 'Optional cap per checkout on the percentage discount.',
        default => 'Optional cap; this discount type has no maximum-discount setting.',
    };
@endphp
<section class="page-hero">
    <div>
        <span class="eyebrow">Marketing</span>
        <h1>Platform Vouchers & Automatic Offers</h1>
        <p>Create Bearly-funded platform vouchers, automatic discounts, and free shipping offers. Seller opt-in events such as Flash Deals and Piso Sales are managed separately in Bearly Campaigns.</p>
    </div>
    <div class="hero-context-stat">
        <span class="hero-context-icon"><i data-lucide="badge-percent"></i></span>
        <div>
            <strong>{{ $promotions->total() }}</strong>
            <span>Platform offers</span>
        </div>
    </div>
</section>

@if (session('success'))
    <div class="info-box" role="status"><strong>Saved.</strong> {{ session('success') }}</div>
@endif

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

<div class="promotion-admin-actions">
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

    <form class="promotion-admin-form" method="POST" action="{{ route('admin.promotions.store') }}" enctype="multipart/form-data" data-promotion-form>
        @csrf

        <div class="promotion-form-grid">
            <label class="form-field promotion-field-wide">
                <span>Promotion name</span>
                <input class="text-field" name="name" value="{{ old('name') }}" maxlength="120" placeholder="e.g. Payday Platform Voucher" required>
            </label>

            <label class="form-field">
                <span>Voucher code <small>Optional</small></span>
                <input class="text-field" name="code" value="{{ old('code') }}" maxlength="40" placeholder="BEARLY100">
            </label>

            <label class="form-field">
                <span>Promotion kind</span>
                <select class="select-field" name="kind" required data-promotion-kind>
                    @foreach ([
                        'voucher' => 'Platform voucher',
                        'automatic_discount' => 'Automatic discount',
                        'free_shipping' => 'Free shipping',
                    ] as $value => $label)
                        <option value="{{ $value }}" @selected(old('kind', 'voucher') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="form-field">
                <span>Discount type</span>
                <select class="select-field" name="discount_type" required data-promotion-discount-type>
                    @foreach ([
                        'fixed' => 'Fixed amount off',
                        'percent' => 'Percentage off',
                        'free_shipping' => 'Free shipping',
                        'fixed_shipping' => 'Fixed amount off shipping',
                        'percent_shipping' => 'Percentage off shipping',
                    ] as $value => $label)
                        <option value="{{ $value }}" @selected(old('discount_type', 'fixed') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="form-field" data-promotion-fixed-field>
                <span>Fixed discount (₱)</span>
                <input class="text-field" type="number" min="0" step="0.01" name="value" value="{{ old('value') }}" placeholder="100.00">
            </label>

            <label class="form-field" data-promotion-percent-field>
                <span>Discount percentage</span>
                <input class="text-field" type="number" min="1" max="100" name="discount_percent" value="{{ old('discount_percent') }}" placeholder="15">
            </label>

            <label class="form-field" data-promotion-max-discount-field @unless ($maximumDiscountApplies) hidden @endunless>
                <span><span data-promotion-max-discount-label>{{ $maximumDiscountLabel }}</span> <small>Optional</small></span>
                <input class="text-field" type="number" min="0" step="0.01" name="max_discount" value="{{ old('max_discount') }}" placeholder="500.00" data-promotion-max-discount-input @disabled(! $maximumDiscountApplies)>
                <small data-promotion-max-discount-help>{{ $maximumDiscountHelp }}</small>
            </label>

            <label class="form-field">
                <span>Minimum spend (₱)</span>
                <input class="text-field" type="number" min="0" step="0.01" name="minimum_spend" value="{{ old('minimum_spend', '0') }}">
            </label>

            <label class="form-field">
                <span>Starts on</span>
                <input class="date-field" type="date" name="starts_on" value="{{ old('starts_on', $defaultPromotionStart->toDateString()) }}" required>
            </label>

            <label class="form-field">
                <span>Start time (PHT)</span>
                <input class="date-field" type="time" name="start_time" value="{{ old('start_time', $defaultPromotionStart->format('H:i')) }}" required>
            </label>

            <label class="form-field">
                <span>Ends on</span>
                <input class="date-field" type="date" name="ends_on" value="{{ old('ends_on', $defaultPromotionEnd->toDateString()) }}" required>
            </label>

            <label class="form-field">
                <span>End time (PHT)</span>
                <input class="date-field" type="time" name="end_time" value="{{ old('end_time', '23:59') }}" required>
            </label>

            <label class="form-field">
                <span>Total redemption limit <small>Optional</small></span>
                <input class="text-field" type="number" min="1" name="redemption_limit" value="{{ old('redemption_limit') }}" placeholder="Unlimited">
            </label>

            <label class="form-field">
                <span>Per-Buyer limit</span>
                <input class="text-field" type="number" min="1" max="1000" name="per_buyer_limit" value="{{ old('per_buyer_limit', '1') }}" required>
            </label>

            <label class="form-field">
                <span>Platform budget (₱) <small>Optional</small></span>
                <input class="text-field" type="number" min="0" step="0.01" name="budget" value="{{ old('budget') }}" placeholder="No cap">
            </label>

            <label class="form-field">
                <span>Status</span>
                <select class="select-field" name="status" required>
                    <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
                    <option value="paused" @selected(old('status') === 'paused')>Paused</option>
                </select>
            </label>

            <label class="form-field">
                <span>Display order</span>
                <input class="text-field" type="number" min="0" name="position" value="{{ old('position', '0') }}">
            </label>

            <label class="form-field">
                <span>Audience scope</span>
                <select class="select-field" name="scope_type" required data-promotion-scope>
                    <option value="all" @selected(old('scope_type', 'all') === 'all')>All products</option>
                    <option value="category" @selected(old('scope_type') === 'category')>Selected categories</option>
                    <option value="store" @selected(old('scope_type') === 'store')>Selected Stores</option>
                </select>
            </label>

            <label class="form-field promotion-field-wide" data-promotion-category-field>
                <span>Categories <small>Ctrl/Cmd-click to select more than one</small></span>
                <select class="select-field promotion-multi-select" name="category_ids[]" multiple size="5">
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected(in_array($category->id, old('category_ids', []), true))>{{ $category->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="form-field promotion-field-wide" data-promotion-store-field>
                <span>Stores <small>Ctrl/Cmd-click to select more than one</small></span>
                <select class="select-field promotion-multi-select" name="store_ids[]" multiple size="5">
                    @foreach ($stores as $store)
                        <option value="{{ $store->id }}" @selected(in_array($store->id, old('store_ids', []), true))>{{ $store->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="form-field promotion-field-wide">
                <span>Short description</span>
                <input class="text-field" name="description" value="{{ old('description') }}" maxlength="255" placeholder="Shown in the Buyer voucher wallet and checkout.">
            </label>

            <label class="form-field promotion-field-wide">
                <span>Terms and conditions</span>
                <textarea class="text-field" name="terms" rows="3" maxlength="2000">{{ old('terms') }}</textarea>
            </label>

            <label class="form-field">
                <span>Banner image <small>Optional, max 2 MB</small></span>
                <input class="text-field" type="file" name="banner" accept=".jpg,.jpeg,.png,.webp">
            </label>

            <label class="form-field">
                <span>Banner title</span>
                <input class="text-field" name="banner_title" value="{{ old('banner_title') }}" maxlength="160">
            </label>

            <label class="form-field">
                <span>Banner link</span>
                <input class="text-field" type="url" name="banner_link" value="{{ old('banner_link') }}" maxlength="500" placeholder="https://...">
            </label>
        </div>

        <div class="promotion-admin-options">
            <input type="hidden" name="is_public" value="0">
            <label><input type="checkbox" name="is_public" value="1" @checked((bool) old('is_public', true))> Publicly visible</label>
            <input type="hidden" name="is_claimable" value="0" data-promotion-claimable-fallback @disabled(! $claimableAvailable)>
            <label data-promotion-claimable-field @unless ($claimableAvailable) hidden @endunless>
                <input type="checkbox" name="is_claimable" value="1" data-promotion-claimable-input @checked((bool) old('is_claimable', true)) @disabled(! $claimableAvailable)>
                <span class="promotion-admin-claimable-copy">
                    <span>Claimable in My Vouchers</span>
                    <small data-promotion-claimable-help>{{ $claimableHelp }}</small>
                </span>
            </label>
            <input type="hidden" name="is_featured" value="0">
            <label><input type="checkbox" name="is_featured" value="1" @checked((bool) old('is_featured'))> Feature on the homepage</label>
        </div>

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
                    @php($status = $promotion->statusKey())
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
                            </div>
                        </td>
                        <td>
                            <span class="status-badge {{ $status === 'active' ? 'badge-success' : ($status === 'paused' ? 'badge-warning' : ($status === 'scheduled' ? 'badge-info' : 'badge-neutral')) }}">
                                {{ ucfirst($status) }}
                            </span>
                        </td>
                        <td>
                            <form method="POST" action="{{ route('admin.promotions.status', $promotion) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="status" value="{{ $promotion->status === 'paused' ? 'active' : 'paused' }}">
                                <button class="button button-ghost promotion-status-button" type="submit">
                                    {{ $promotion->status === 'paused' ? 'Activate' : 'Pause' }}
                                </button>
                            </form>
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
    const fixedField = form.querySelector('[data-promotion-fixed-field]');
    const percentField = form.querySelector('[data-promotion-percent-field]');
    const maximumDiscountField = form.querySelector('[data-promotion-max-discount-field]');
    const maximumDiscountInput = form.querySelector('[data-promotion-max-discount-input]');
    const maximumDiscountLabel = form.querySelector('[data-promotion-max-discount-label]');
    const maximumDiscountHelp = form.querySelector('[data-promotion-max-discount-help]');
    const claimableField = form.querySelector('[data-promotion-claimable-field]');
    const claimableFallback = form.querySelector('[data-promotion-claimable-fallback]');
    const claimableInput = form.querySelector('[data-promotion-claimable-input]');
    const claimableHelp = form.querySelector('[data-promotion-claimable-help]');

    const syncScope = () => {
        const value = scope?.value || 'all';
        if (categoryField) categoryField.hidden = value !== 'category';
        if (storeField) storeField.hidden = value !== 'store';
    };

    const syncDiscount = () => {
        const value = discountType?.value || 'fixed';
        const isPercent = value === 'percent' || value === 'percent_shipping';
        const isFixed = value === 'fixed' || value === 'fixed_shipping';

        if (kind?.value === 'free_shipping' && !value.endsWith('shipping')) {
            discountType.value = 'free_shipping';
            syncDiscount();
            return;
        }
        if (kind && kind.value !== 'free_shipping' && value.endsWith('shipping')) {
            discountType.value = 'fixed';
            syncDiscount();
            return;
        }

        if (fixedField) fixedField.hidden = !isFixed;
        if (percentField) percentField.hidden = !isPercent;

        const maximumApplies = ['percent', 'free_shipping', 'percent_shipping'].includes(value);
        if (maximumDiscountField) maximumDiscountField.hidden = !maximumApplies;
        if (maximumDiscountInput) maximumDiscountInput.disabled = !maximumApplies;
        if (maximumDiscountLabel) {
            maximumDiscountLabel.textContent = ['free_shipping', 'percent_shipping'].includes(value)
                ? 'Maximum shipping discount (₱)'
                : 'Maximum discount (₱)';
        }
        if (maximumDiscountHelp) {
            maximumDiscountHelp.textContent = value === 'free_shipping'
                ? 'Optional cap per checkout; blank covers the full eligible shipping cost.'
                : value === 'percent_shipping'
                    ? 'Optional cap per checkout; limits the percentage-based shipping discount.'
                    : value === 'percent'
                        ? 'Optional cap per checkout on the percentage discount.'
                        : 'Optional cap; this discount type has no maximum-discount setting.';
        }

        const claimableAvailable = ['voucher', 'free_shipping'].includes(kind?.value || 'voucher');
        if (claimableField) claimableField.hidden = !claimableAvailable;
        if (claimableFallback) claimableFallback.disabled = !claimableAvailable;
        if (claimableInput) claimableInput.disabled = !claimableAvailable;
        if (claimableHelp) {
            claimableHelp.textContent = kind?.value === 'free_shipping'
                ? 'Checked: buyers claim it in My Vouchers and select it at checkout. Unchecked: it applies automatically when eligible.'
                : 'Buyers can claim this platform voucher and select it at checkout.';
        }
    };

    scope?.addEventListener('change', syncScope);
    kind?.addEventListener('change', syncDiscount);
    discountType?.addEventListener('change', syncDiscount);
    syncScope();
    syncDiscount();
})();
</script>
@endpush
