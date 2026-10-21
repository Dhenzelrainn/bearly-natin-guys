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
        <p>Define the event schedule, Seller join deadline, funding source, eligibility scope, and campaign terms.</p>
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

        <label class="form-field">
            <span>Funding</span>
            <select class="select-field" name="funding_type" required>
                <option value="platform" @selected(old('funding_type', $campaign?->funding_type) === 'platform')>Bearly-funded</option>
                <option value="seller" @selected(old('funding_type', $campaign?->funding_type) === 'seller')>Seller-funded</option>
            </select>
            <small>Shared funding is not supported. Funding cannot change after Sellers have joined.</small>
        </label>

        <label class="form-field">
            <span>Starts on</span>
            <input class="date-field" type="date" name="starts_on" value="{{ old('starts_on', $campaign?->starts_on?->toDateString() ?? $defaultStart) }}" required>
        </label>
        <label class="form-field">
            <span>Start time (PHT)</span>
            <input class="date-field" type="time" name="start_time" value="{{ old('start_time', $campaign ? substr((string) $campaign->starts_time, 0, 5) : $defaultStartTime) }}" required>
        </label>

        <label class="form-field">
            <span>Ends on</span>
            <input class="date-field" type="date" name="ends_on" value="{{ old('ends_on', $campaign?->ends_on?->toDateString() ?? $defaultEnd) }}" required>
        </label>
        <label class="form-field">
            <span>End time (PHT)</span>
            <input class="date-field" type="time" name="end_time" value="{{ old('end_time', $campaign ? substr((string) $campaign->ends_time, 0, 5) : $defaultEndTime) }}" required>
        </label>

        <label class="form-field">
            <span>Seller join deadline</span>
            <input class="date-field" type="date" name="join_deadline" value="{{ old('join_deadline', $campaign?->join_deadline?->toDateString() ?? $defaultDeadline) }}" required>
            <small>Must be on or before the campaign start date. Schedule times use Philippines time (PHT); the selected end minute is included.</small>
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

        <label class="form-field">
            <span>Bearly-funded budget (₱) <small>Optional; only applies to Bearly-funded campaigns</small></span>
            <input class="text-field" type="number" min="0" step="0.01" name="budget" value="{{ old('budget', $campaign?->budget_minor !== null ? number_format($campaign->budget_minor / 100, 2, '.', '') : '') }}" placeholder="No cap">
        </label>

        <label class="form-field">
            <span>Max participating Sellers <small>Optional</small></span>
            <input class="text-field" type="number" min="1" name="max_participating_stores" value="{{ old('max_participating_stores', $campaign?->max_participating_stores) }}" placeholder="Unlimited">
        </label>

        <label class="form-field">
            <span>Max products per Seller <small>Optional</small></span>
            <input class="text-field" type="number" min="1" max="1000" name="max_products_per_store" value="{{ old('max_products_per_store', $campaign?->max_products_per_store) }}" placeholder="Unlimited">
        </label>

        <label class="form-field promotion-field-wide">
            <span>Description</span>
            <textarea class="text-field" name="description" rows="3" maxlength="2000" placeholder="Shown to Sellers and Buyers.">{{ old('description', $campaign?->description) }}</textarea>
        </label>

        <label class="form-field promotion-field-wide">
            <span>Campaign terms</span>
            <textarea class="text-field" name="terms" rows="4" maxlength="5000" placeholder="Seller funding, pricing, stock, and participation rules.">{{ old('terms', $campaign?->terms) }}</textarea>
        </label>

        <label class="form-field promotion-field-wide">
            <span>Internal notes <small>Optional</small></span>
            <textarea class="text-field" name="campaign_notes" rows="2" maxlength="2000">{{ old('campaign_notes', $campaign?->campaign_notes) }}</textarea>
        </label>

        <input type="hidden" name="scope_submitted" value="1">
        <label class="form-field promotion-field-wide">
            <span>Eligible Stores <small>Ctrl/Cmd-click to select more than one. Leave empty for all Stores.</small></span>
            <select class="select-field promotion-multi-select" name="store_ids[]" multiple size="5">
                @foreach ($stores as $store)
                    <option value="{{ $store->id }}" @selected(in_array((int) $store->id, $selectedStores, true))>{{ $store->name }}</option>
                @endforeach
            </select>
        </label>

        <label class="form-field promotion-field-wide">
            <span>Eligible categories <small>Optional. Leave empty for all categories.</small></span>
            <select class="select-field promotion-multi-select" name="category_ids[]" multiple size="5">
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(in_array((int) $category->id, $selectedCategories, true))>{{ $category->name }}</option>
                @endforeach
            </select>
        </label>

        <label class="form-field">
            <span>Banner image <small>Optional, max 4 MB</small></span>
            <input class="text-field" type="file" name="banner" accept=".jpg,.jpeg,.png,.webp">
            @if ($isEdit && $campaign->banner_path)
                <small>Leave blank to keep the current banner.</small>
            @endif
        </label>

        <label class="form-field">
            <span>Banner title</span>
            <input class="text-field" name="banner_title" value="{{ old('banner_title', $campaign?->banner_title) }}" maxlength="120">
        </label>

        <label class="form-field">
            <span>Banner link</span>
            <input class="text-field" type="url" name="banner_link" value="{{ old('banner_link', $campaign?->banner_link) }}" maxlength="255" placeholder="https://...">
        </label>
    </div>

    <div class="promotion-admin-options">
        <input type="hidden" name="requires_approval" value="0">
        <label><input type="checkbox" name="requires_approval" value="1" @checked((bool) old('requires_approval', $campaign?->requires_approval ?? true))> Seller participations require Bearly approval</label>
        <input type="hidden" name="is_featured" value="0">
        <label><input type="checkbox" name="is_featured" value="1" @checked((bool) old('is_featured', $campaign?->is_featured ?? false))> Feature on the homepage</label>
    </div>

    <div class="promotion-admin-actions">
        <button class="button button-primary" type="submit">
            <i data-lucide="{{ $isEdit ? 'save' : 'calendar-plus' }}"></i>
            {{ $isEdit ? 'Save campaign changes' : 'Create campaign' }}
        </button>
        <p>Campaign prices are set by Sellers during participation and reviewed by Bearly before activation.</p>
    </div>
</form>
@endsection
