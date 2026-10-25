@extends('layouts.seller')

@section('title', 'Request a Marketplace Subcategory')
@section('page-title', 'Request a Marketplace Subcategory')

@section('content')
<section class="page-heading marketplace-subcategory-requests-heading">
    <div>
        <a class="back-link" href="{{ route('seller.products.subcategory-requests.index') }}"><i data-lucide="arrow-left"></i>Your requests</a>
        <h2>Request a marketplace subcategory</h2>
        <p>Admin will review the suggestion. It cannot be assigned to products or shown to Buyers until approved.</p>
    </div>
</section>

@if ($errors->any())
    <div class="seller-error-alert" role="alert">
        <strong>Check your request.</strong>
        <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<section class="seller-panel marketplace-subcategory-request-form-panel">
    <div class="seller-panel-heading">
        <div>
            <h3>Seller category</h3>
            <p>Your request will be scoped to the category approved for your business.</p>
        </div>
    </div>
    <div class="marketplace-request-approved-category">
        <i data-lucide="lock-keyhole" aria-hidden="true"></i>
        <span><strong>{{ $approvedCategory->name }}</strong><small>Derived from your approved Seller profile; it cannot be changed in this request.</small></span>
    </div>
    @if ($mappedMarketplaceCategories->isNotEmpty())
        <p class="marketplace-request-branch-note">
            If approved categories map to more than one Buyer section, Admin will place the new subcategory in the appropriate section during review.
        </p>
        <form method="POST" action="{{ route('seller.products.subcategory-requests.store') }}" class="marketplace-subcategory-request-form">
            @csrf
            <label class="seller-field">
                <span>Suggested subcategory name <em>*</em></span>
                <input name="suggested_name" value="{{ old('suggested_name') }}" minlength="2" maxlength="120" required placeholder="e.g. Travel Backpacks">
                @error('suggested_name')<small class="field-error">{{ $message }}</small>@enderror
            </label>
            <label class="seller-field">
                <span>Why is this subcategory needed? <em>*</em></span>
                <textarea name="reason" rows="4" maxlength="500" minlength="5" required placeholder="Briefly describe the products this category would organize.">{{ old('reason') }}</textarea>
                <small>Keep the reason brief and specific. This request does not change your approved business category.</small>
                @error('reason')<small class="field-error">{{ $message }}</small>@enderror
            </label>
            <div class="marketplace-subcategory-request-actions">
                <button class="seller-primary-button" type="submit"><i data-lucide="send"></i>Send request to Admin</button>
                <a class="seller-secondary-button" href="{{ route('seller.products') }}">Cancel</a>
            </div>
        </form>
    @else
        <div class="marketplace-subcategory-request-empty" role="status">
            <strong>No Buyer category mapping is available for {{ $approvedCategory->name }} yet.</strong>
            <p>Admin must map this approved business category before marketplace subcategory requests can be accepted.</p>
        </div>
    @endif
</section>
@endsection
