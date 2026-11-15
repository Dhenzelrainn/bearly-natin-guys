@extends('layouts.seller')

@section('title', 'Marketplace Subcategory Requests')
@section('page-title', 'Marketplace Subcategory Requests')

@section('content')
<section class="page-heading marketplace-subcategory-requests-heading">
    <div>
        <a class="back-link" href="{{ route('seller.products') }}"><i data-lucide="arrow-left"></i>Products</a>
        <h2>Marketplace subcategory requests</h2>
        <p>Requests are reviewed by Admin and are not Buyer-visible until approved.</p>
    </div>
    <a class="seller-primary-button" href="{{ route('seller.products.subcategory-requests.create') }}"><i data-lucide="plus"></i>Request a subcategory</a>
</section>

@if (session('success'))
    <div class="seller-success-alert" role="status">{{ session('success') }}</div>
@endif

<section class="seller-panel marketplace-subcategory-request-panel">
    <div class="seller-panel-heading">
        <div><h3>Your requests</h3><p>Approved business category: <strong>{{ $approvedCategory->name }}</strong></p></div>
    </div>
    <div class="marketplace-subcategory-request-list">
        @forelse ($requests as $subcategoryRequest)
            @php
                $requestStatusLabel = match ($subcategoryRequest->status) {
                    'pending_review' => 'Pending review',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                    default => str($subcategoryRequest->status)->replace('_', ' ')->title(),
                };
                $requestStatusClass = match ($subcategoryRequest->status) {
                    'approved' => 'is-approved',
                    'rejected' => 'is-rejected',
                    default => 'is-pending',
                };
            @endphp
            <article class="marketplace-subcategory-request-row">
                <div>
                    <span class="marketplace-subcategory-request-status {{ $requestStatusClass }}">{{ $requestStatusLabel }}</span>
                    <h4>{{ $subcategoryRequest->suggested_name }}</h4>
                    <p>{{ $subcategoryRequest->reason }}</p>
                </div>
                <div class="marketplace-subcategory-request-meta">
                    <span>Requested {{ $subcategoryRequest->created_at?->format('M j, Y') ?? '—' }}</span>
                    @if ($subcategoryRequest->marketplaceCategory)
                        <span>Buyer category: {{ $subcategoryRequest->marketplaceCategory->name }}</span>
                    @endif
                    @if ($subcategoryRequest->marketplaceSubcategory)
                        <span>Canonical entry: {{ $subcategoryRequest->marketplaceSubcategory->name }}</span>
                    @endif
                    @if ($subcategoryRequest->decision_reason)
                        <span>Admin note: {{ $subcategoryRequest->decision_reason }}</span>
                    @endif
                </div>
            </article>
        @empty
            <div class="marketplace-subcategory-request-empty">
                <strong>No requests yet.</strong>
                <p>Request a canonical marketplace subcategory if your product does not fit an available option.</p>
            </div>
        @endforelse
    </div>
    @if ($requests->hasPages())<div class="panel-footer">{{ $requests->links() }}</div>@endif
</section>
@endsection
