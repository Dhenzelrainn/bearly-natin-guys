@extends('layouts.admin')

@section('title', $campaign ? 'Edit Bearly Campaign' : 'Create Bearly Campaign')
@section('page-title', $campaign ? 'Edit Bearly Campaign' : 'Create Bearly Campaign')

@section('content')
@php
    $isEdit = $campaign !== null;
    $selectedStores = array_map('intval', old('store_ids', $isEdit ? $campaign->stores->pluck('id')->all() : []));
    $selectedCategories = array_map('intval', old('category_ids', $isEdit ? $campaign->categories->pluck('id')->all() : []));
    $defaultStart = now('Asia/Manila')->addDays(7)->toDateString();
    $defaultEnd = now('Asia/Manila')->addDays(14)->toDateString();
    $defaultDeadline = now('Asia/Manila')->addDays(5)->toDateString();
    $defaultStartTime = now('Asia/Manila')->addDays(7)->format('H:i');
    $defaultEndTime = '23:59';
    $formAction = $isEdit ? route('admin.campaigns.update', $campaign) : route('admin.campaigns.store');
@endphp

<section class="page-hero">
    <div>
        <span class="eyebrow">Marketing</span>
        <h1>{{ $isEdit ? 'Edit ' . $campaign->name : 'Create a Bearly campaign' }}</h1>
        <p>Set the Bearly-funded event schedule and terms. Sellers select their products when they join.</p>
    </div>
    <div class="promotion-admin-actions">
        @if ($isEdit)
            <a class="button button-secondary" href="{{ route('admin.campaigns.show', $campaign) }}">View campaign</a>
        @endif
        <a class="button button-secondary" href="{{ route('admin.campaigns') }}">Back to campaigns</a>
    </div>
</section>

@if ($errors->any())
    <div class="panel" role="alert">
        <strong>Check the campaign details.</strong>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form class="panel" method="POST" action="{{ $formAction }}" enctype="multipart/form-data">
    @csrf
    @if ($isEdit)
        @method('PATCH')
    @endif

    <div class="panel-heading">
        <div>
            <span class="eyebrow">Campaign setup</span>
            <h2>Event details</h2>
        </div>
        @if ($isEdit)
            @php
                $campaignStatusClass = match ($campaign->statusKey()) {
                    'active' => 'badge-success',
                    'open', 'scheduled' => 'badge-info',
                    'paused' => 'badge-warning',
                    default => 'badge-neutral',
                };
            @endphp
            <span class="status-badge {{ $campaignStatusClass }}">{{ ucfirst(str_replace('_', ' ', $campaign->statusKey())) }}</span>
        @endif
    </div>

    <div class="form-grid promotion-form-grid">
        <label class="form-field promotion-field-wide">
            <span>Campaign name</span>
            <input class="text-field" name="name" value="{{ old('name', $campaign?->name) }}" maxlength="120" required placeholder="e.g. 11.11 Mega Sale">
        </label>

        <label class="form-field">
            <span>Campaign type</span>
            <select class="select-field" name="campaign_type" required>
                @foreach ($campaignTypes as $value => $label)
                    <option value="{{ $value }}" @selected(old('campaign_type', $campaign?->campaign_type) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <div class="form-field">
            <span>Funding</span>
            @if ($isEdit && $campaign->funding_type === \App\Models\PlatformPromotion::FUNDING_SELLER)
                <input type="hidden" name="funding_type" value="seller">
                <p class="campaign-form-note">Legacy Seller-funded campaign. Its funding cannot be changed; Admin creates Bearly-funded campaigns only. Sellers manage Product Discounts and Store Vouchers independently in Pricing &amp; Promotions.</p>
            @else
                <input type="hidden" name="funding_type" value="platform">
                <p class="campaign-form-note">Bearly-funded. Set a required campaign budget below. Sellers manage their own Product Discounts and Store Vouchers independently in Pricing &amp; Promotions.</p>
            @endif
        </div>

        <label class="form-field">
            <span>Minimum Seller discount (%)</span>
            <input class="text-field" type="number" name="minimum_discount_percent" min="1" max="90" step="1" inputmode="numeric" value="{{ old('minimum_discount_percent', $campaign?->minimum_discount_percent) }}" @required(! $isEdit || $campaign->minimum_discount_percent !== null)>
            <small>Every Seller-submitted product discount must meet this whole-number minimum. Applies to Bearly-funded and Seller-funded campaigns. Range: 1–90%.</small>
            @if ($isEdit && $campaign->minimum_discount_percent === null)
                <small>Legacy campaign with no configured minimum. Leave blank to keep it unset; Sellers must still submit at least 1%.</small>
            @endif
        </label>

        <label class="form-field">
            <span>Starts on</span>
            <input class="date-field" type="date" name="starts_on" value="{{ old('starts_on', $campaign?->starts_on?->toDateString() ?? $defaultStart) }}" required>
            @error('starts_on')<small class="campaign-form-error" role="alert">{{ $message }}</small>@enderror
        </label>
        <label class="form-field">
            <span>Start time (PHT)</span>
            <input class="date-field" type="time" name="start_time" value="{{ old('start_time', $campaign ? substr((string) $campaign->starts_time, 0, 5) : $defaultStartTime) }}" required>
            @error('start_time')<small class="campaign-form-error" role="alert">{{ $message }}</small>@enderror
        </label>

        <label class="form-field">
            <span>Ends on</span>
            <input class="date-field" type="date" name="ends_on" value="{{ old('ends_on', $campaign?->ends_on?->toDateString() ?? $defaultEnd) }}" required>
            @error('ends_on')<small class="campaign-form-error" role="alert">{{ $message }}</small>@enderror
        </label>
        <label class="form-field">
            <span>End time (PHT)</span>
            <input class="date-field" type="time" name="end_time" value="{{ old('end_time', $campaign ? substr((string) $campaign->ends_time, 0, 5) : $defaultEndTime) }}" required>
            @error('end_time')<small class="campaign-form-error" role="alert">{{ $message }}</small>@enderror
        </label>

        <label class="form-field">
            <span>Seller join deadline</span>
            <input class="date-field" type="date" name="join_deadline" value="{{ old('join_deadline', $campaign?->join_deadline?->toDateString() ?? $defaultDeadline) }}" required>
            @error('join_deadline')<small class="campaign-form-error" role="alert">{{ $message }}</small>@enderror
            <small>Must be on or before the campaign start date. If you move the start earlier, update the deadline too. Schedule times use Philippines time (PHT); the selected end minute is included.</small>
        </label>

        @unless ($isEdit)
            <label class="form-field">
                <span>Initial status</span>
                <select class="select-field" name="status" required>
                    <option value="draft" @selected(old('status', 'draft') === 'draft')>Draft</option>
                    <option value="open" @selected(old('status') === 'open')>Open for Seller joining</option>
                </select>
            </label>
        @else
            <div class="form-field">
                <span>Campaign status</span>
                <p class="campaign-form-note">Use the campaign detail page to open, activate, pause, resume, or end this event.</p>
            </div>
        @endunless

        @if (! $isEdit || $campaign->funding_type === \App\Models\PlatformPromotion::FUNDING_PLATFORM)
            <label class="form-field">
                <span>Bearly-funded budget (₱)</span>
                <input class="text-field" type="number" min="0.01" step="0.01" name="budget" value="{{ old('budget', $campaign?->budget_minor !== null ? number_format($campaign->budget_minor / 100, 2, '.', '') : '') }}" required placeholder="Required budget cap">
                <small>Required and greater than ₱0.00. Checkout stops applying Bearly-funded campaign discounts when this budget is reached; uncapped Admin campaigns are not available.</small>
            </label>
        @else
            <div class="form-field">
                <span>Bearly-funded budget</span>
                <p class="campaign-form-note">Not applicable to this legacy Seller-funded campaign. Existing funding and Seller-proceeds behavior are preserved.</p>
            </div>
        @endif

        <label class="form-field promotion-field-wide">
            <span>Description</span>
            <textarea class="text-field" name="description" rows="3" maxlength="2000" placeholder="Shown to Sellers and Buyers.">{{ old('description', $campaign?->description) }}</textarea>
        </label>

        <label class="form-field promotion-field-wide">
            <span>Campaign terms</span>
            <textarea class="text-field" name="terms" rows="4" maxlength="5000" placeholder="Seller funding, pricing, stock, and participation rules.">{{ old('terms', $campaign?->terms) }}</textarea>
        </label>

    </div>

    <input type="hidden" name="scope_submitted" value="1">
    <details class="campaign-advanced-settings">
        <summary>
            <span>
                <strong>Optional targeting, limits and settings</strong>
                <small>Stores, categories, caps, approvals and optional banner</small>
            </span>
            <i data-lucide="chevron-down" aria-hidden="true"></i>
        </summary>
        <div class="campaign-advanced-content">
            <p class="campaign-form-note">Leave Store/category targeting empty for broad eligibility. Sellers choose products when they join; Admin does not select campaign products here.</p>

            <div class="form-grid promotion-form-grid">
                <label class="form-field">
                    <span>Max participating Sellers <small>Optional</small></span>
                    <input class="text-field" type="number" min="1" name="max_participating_stores" value="{{ old('max_participating_stores', $campaign?->max_participating_stores) }}" placeholder="Unlimited">
                </label>

                <label class="form-field">
                    <span>Max products per Seller <small>Optional</small></span>
                    <input class="text-field" type="number" min="1" max="1000" name="max_products_per_store" value="{{ old('max_products_per_store', $campaign?->max_products_per_store) }}" placeholder="Unlimited">
                </label>

                <div class="form-field promotion-field-wide">
                    <span>Eligible Stores <small>Optional. Leave empty for all Stores.</small></span>
                    <details class="admin-multi-dropdown" data-multi-dropdown data-empty-label="All Stores" data-many-label="Stores selected">
                        <summary class="admin-multi-dropdown-trigger">
                            <span data-multi-dropdown-summary>All Stores</span>
                            <i data-lucide="chevron-down" class="admin-multi-dropdown-chevron" aria-hidden="true"></i>
                        </summary>
                        <div class="admin-multi-dropdown-panel">
                            <p class="admin-multi-dropdown-note">No selection means all Stores.</p>
                            <div class="admin-multi-dropdown-options">
                                @forelse ($stores as $store)
                                    <label class="admin-multi-dropdown-option">
                                        <input type="checkbox" name="store_ids[]" value="{{ $store->id }}" @checked(in_array((int) $store->id, $selectedStores, true))>
                                        <span>{{ $store->name }}</span>
                                    </label>
                                @empty
                                    <p class="admin-multi-dropdown-empty">No Stores available yet.</p>
                                @endforelse
                            </div>
                        </div>
                    </details>
                </div>

                <div class="form-field promotion-field-wide">
                    <span>Eligible categories <small>Optional. Leave empty for all categories.</small></span>
                    <details class="admin-multi-dropdown" data-multi-dropdown data-empty-label="All categories" data-many-label="categories selected">
                        <summary class="admin-multi-dropdown-trigger">
                            <span data-multi-dropdown-summary>All categories</span>
                            <i data-lucide="chevron-down" class="admin-multi-dropdown-chevron" aria-hidden="true"></i>
                        </summary>
                        <div class="admin-multi-dropdown-panel">
                            <p class="admin-multi-dropdown-note">No selection means all categories.</p>
                            <div class="admin-multi-dropdown-options">
                                @forelse ($categories as $category)
                                    <label class="admin-multi-dropdown-option">
                                        <input type="checkbox" name="category_ids[]" value="{{ $category->id }}" @checked(in_array((int) $category->id, $selectedCategories, true))>
                                        <span>{{ $category->name }}</span>
                                    </label>
                                @empty
                                    <p class="admin-multi-dropdown-empty">No categories available yet.</p>
                                @endforelse
                            </div>
                        </div>
                    </details>
                </div>

                <div class="form-field promotion-field-wide promotion-admin-options campaign-advanced-options">
                    <input type="hidden" name="requires_approval" value="0">
                    <label><input type="checkbox" name="requires_approval" value="1" @checked((bool) old('requires_approval', $campaign?->requires_approval ?? true))> Seller participations require Bearly approval</label>
                    <input type="hidden" name="is_featured" value="0">
                    <label><input type="checkbox" name="is_featured" value="1" @checked((bool) old('is_featured', $campaign?->is_featured ?? false))> Feature on the homepage</label>
                </div>

                <label class="form-field promotion-field-wide">
                    <span>Internal Admin notes <small>Optional; not shared with Sellers or Buyers</small></span>
                    <textarea class="text-field" name="campaign_notes" rows="2" maxlength="2000">{{ old('campaign_notes', $campaign?->campaign_notes) }}</textarea>
                </label>

                <label class="form-field">
                    <span>Banner image <small>Optional, max 10 MB</small></span>
                    <input class="text-field" type="file" name="banner" accept=".jpg,.jpeg,.png,.webp">
                    @if ($isEdit && $campaign->banner_path)
                        <small>Leave blank to keep the current banner.</small>
                    @endif
                </label>

                <label class="form-field">
                    <span>Banner title <small>Optional banner alt text</small></span>
                    <input class="text-field" name="banner_title" value="{{ old('banner_title', $campaign?->banner_title) }}" maxlength="120">
                </label>
            </div>
        </div>
    </details>

    <div class="promotion-admin-actions">
        <button class="button button-primary" type="submit">
            <i data-lucide="{{ $isEdit ? 'save' : 'calendar-plus' }}"></i>
            {{ $isEdit ? 'Save campaign changes' : 'Create campaign' }}
        </button>
        <p>Campaign prices are set by Sellers during participation and reviewed by Bearly before activation.</p>
    </div>
</form>
@endsection

@push('scripts')
<script>
(() => {
    const dropdowns = [...document.querySelectorAll('[data-multi-dropdown]')];
    if (!dropdowns.length) return;

    const updateSummary = (dropdown) => {
        const summary = dropdown.querySelector('[data-multi-dropdown-summary]');
        if (!summary) return;

        const checked = [...dropdown.querySelectorAll('input[type="checkbox"]')]
            .filter((box) => box.checked);

        if (checked.length === 0) {
            summary.textContent = dropdown.dataset.emptyLabel || 'All';
            return;
        }

        if (checked.length === 1) {
            const label = checked[0].closest('label')?.querySelector('span')?.textContent?.trim();
            summary.textContent = label || dropdown.dataset.emptyLabel || '1 selected';
            return;
        }

        summary.textContent = `${checked.length} ${dropdown.dataset.manyLabel || 'selected'}`;
    };

    dropdowns.forEach((dropdown) => {
        dropdown.querySelectorAll('input[type="checkbox"]').forEach((box) => {
            box.addEventListener('change', () => updateSummary(dropdown));
        });

        dropdown.addEventListener('toggle', () => {
            if (!dropdown.open) return;
            dropdowns.forEach((other) => {
                if (other !== dropdown) other.open = false;
            });
        });

        updateSummary(dropdown);
    });

    document.addEventListener('click', (event) => {
        dropdowns.forEach((dropdown) => {
            if (dropdown.open && !dropdown.contains(event.target)) dropdown.open = false;
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        dropdowns.forEach((dropdown) => {
            if (!dropdown.open) return;
            dropdown.open = false;
            dropdown.querySelector('summary')?.focus();
        });
    });
})();
</script>
@endpush
