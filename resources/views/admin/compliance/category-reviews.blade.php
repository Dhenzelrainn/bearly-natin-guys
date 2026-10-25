@extends('layouts.admin')

@section('title', 'Manual Category Review')
@section('page-title', 'Manual Category Review')

@section('content')
@if (session('success'))
    <div class="admin-violation-feedback is-success" role="status">{{ session('success') }}</div>
@endif

@if ($errors->any())
    <div class="admin-violation-feedback is-error" role="alert">{{ $errors->first() }}</div>
@endif

<section class="page-hero admin-category-review-hero">
    <div>
        <span class="eyebrow">Compliance &amp; Disputes</span>
        <h1>Manual product category review</h1>
        <p>Check Buyer-visible listings when text rules are inconclusive, including image-only or ambiguous mismatches.</p>
    </div>
    <div class="hero-context-stat">
        <span class="hero-context-icon"><i data-lucide="scan-search"></i></span>
        <div>
            <strong>{{ $products->total() }}</strong>
            <span>Listings needing review</span>
        </div>
    </div>
</section>

<section class="panel admin-category-review-panel">
    <div class="panel-heading panel-heading-wrap">
        <div>
            <span class="eyebrow">Buyer-visible listings</span>
            <h2>Category review queue</h2>
            <p class="admin-category-review-guidance">
                The text scan does not inspect product photos. Review images manually; no product photo is sent to an AI service.
            </p>
        </div>
        <form method="GET" action="{{ route('admin.compliance.category-reviews') }}" class="table-toolbar admin-category-review-search">
            <label class="field-with-icon compact-field">
                <i data-lucide="search" aria-hidden="true"></i>
                <input
                    type="search"
                    name="search"
                    value="{{ request('search') }}"
                    maxlength="120"
                    placeholder="Search products, Sellers, or categories"
                    aria-label="Search category review queue"
                >
            </label>
            <button class="button button-primary button-small" type="submit">
                <i data-lucide="search" aria-hidden="true"></i> Search
            </button>
            @if (filled(request('search')))
                <a class="button button-ghost button-small" href="{{ route('admin.compliance.category-reviews') }}">Clear</a>
            @endif
        </form>
    </div>

    <div class="table-wrap admin-category-review-table-wrap">
        <table class="admin-table admin-category-review-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Seller</th>
                    <th>Approved business category</th>
                    <th>Listed business category</th>
                    <th>Marketplace category</th>
                    <th>Latest scan</th>
                    <th>Status</th>
                    <th>Review actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $product)
                    <tr>
                        <td>
                            <div class="admin-category-review-product">
                                @if ($product['image_url'])
                                    <img src="{{ $product['image_url'] }}" alt="{{ $product['image_alt'] }}" loading="lazy">
                                @else
                                    <span class="admin-category-review-no-image" aria-label="No product image available">
                                        <i data-lucide="image-off" aria-hidden="true"></i>
                                    </span>
                                @endif
                                <div>
                                    <strong>{{ $product['name'] }}</strong>
                                    <small>Product #{{ $product['id'] }}</small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="admin-category-review-cell">
                                <strong>{{ $product['seller'] }}</strong>
                                @if ($product['seller_business_name'])
                                    <small>{{ $product['seller_business_name'] }}</small>
                                @endif
                            </div>
                        </td>
                        <td>{{ $product['approved_business_category'] }}</td>
                        <td>{{ $product['listed_business_category'] }}</td>
                        <td>
                            <div class="admin-category-review-cell">
                                <strong>{{ $product['marketplace_category'] }}</strong>
                                <small>{{ $product['marketplace_subcategory'] }}</small>
                            </div>
                        </td>
                        <td>
                            <div class="admin-category-review-cell">
                                <strong>{{ $product['latest_scan_result'] }}</strong>
                                <small>{{ $product['latest_scan_at'] ?? 'No scan time recorded' }}</small>
                                @if ($product['scan_is_stale'])
                                    <span class="admin-category-review-stale">Stale text/category scan</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            <div class="admin-category-review-cell">
                                <strong>{{ $product['product_status'] }}</strong>
                                <small>Compliance: {{ $product['compliance_status'] }}</small>
                            </div>
                        </td>
                        <td>
                            <div class="admin-category-review-actions">
                                <details class="admin-category-review-action">
                                    <summary class="admin-category-review-summary">
                                        <i data-lucide="check" aria-hidden="true"></i>
                                        Mark category appropriate
                                    </summary>
                                    <form method="POST" action="{{ route('admin.compliance.category-reviews.reviewed', $product['id']) }}">
                                        @csrf
                                        <label for="review-reason-{{ $product['id'] }}">
                                            Reason <span>Required for the audit record</span>
                                        </label>
                                        <textarea id="review-reason-{{ $product['id'] }}" name="reason" maxlength="2000" required></textarea>
                                        <button class="button button-primary button-small" type="submit">Save review</button>
                                    </form>
                                </details>
                                <details class="admin-category-review-action is-correction">
                                    <summary class="admin-category-review-summary">
                                        <i data-lucide="flag" aria-hidden="true"></i>
                                        Flag for correction
                                    </summary>
                                    <form method="POST" action="{{ route('admin.compliance.category-reviews.flag', $product['id']) }}">
                                        @csrf
                                        <label for="flag-reason-{{ $product['id'] }}">
                                            Reason <span>Required; this hides the listing</span>
                                        </label>
                                        <textarea id="flag-reason-{{ $product['id'] }}" name="reason" maxlength="2000" required></textarea>
                                        <button class="button button-danger button-small" type="submit">Create Product Violation</button>
                                        <small class="admin-category-review-no-warning">No Seller warning is issued unless an Admin later selects that decision.</small>
                                    </form>
                                </details>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <div class="admin-category-review-empty">
                                <i data-lucide="shield-check" aria-hidden="true"></i>
                                <strong>No listings need manual category review</strong>
                                <span>Buyer-visible products with actionable violations and unchanged Admin clear reviews are not duplicated here.</span>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($products->hasPages())
        <div class="panel-footer admin-category-review-pagination">{{ $products->links() }}</div>
    @endif
</section>
@endsection
