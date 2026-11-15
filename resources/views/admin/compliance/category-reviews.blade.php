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
        <p>Review Buyer-visible category concerns and listings held from Buyers while their product images need review.</p>
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
            <span class="eyebrow">Category and image review</span>
            <h2>Listings needing review</h2>
            <p class="admin-category-review-guidance">
                @if ($imageSafetyEnforced)
                    Listings held for scanning stay hidden from Buyers. Review every image before approval; a possible finding is handled in Product Violations, and uncertain or unavailable results require a documented Admin decision.
                @elseif ($imageCheckEnabled)
                    Gemini checks the primary image as an advisory only. It cannot change listing status or create violations; Admins remain the decision-makers.
                @else
                    Gemini image checks are disabled. No product photos are sent to Google. Listings held for review remain hidden until an Admin decision.
                @endif
            </p>
        </div>
        <form method="GET" action="{{ route('admin.compliance.category-reviews') }}" class="table-toolbar admin-category-review-search" data-category-review-filter-form>
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
            <select class="select-field" name="marketplace_category_id" aria-label="Filter by marketplace category">
                <option value="">All marketplace categories</option>
                @foreach ($marketplaceCategories as $category)
                    <option value="{{ $category->id }}" @selected($selectedMarketplaceCategoryId === (int) $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
            <button class="button button-primary button-small" type="submit">
                <i data-lucide="search" aria-hidden="true"></i> Search
            </button>
            @if (filled(request('search')) || $selectedMarketplaceCategoryId !== null)
                <a class="button button-ghost button-small" href="{{ route('admin.compliance.category-reviews') }}">Reset all</a>
            @endif
        </form>
    </div>

    <div class="table-wrap admin-category-review-table-wrap">
        <table
            class="admin-table admin-category-review-table"
            data-image-advisory-poll
            data-image-advisories-url="{{ route('admin.compliance.image-advisories') }}"
        >
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Seller</th>
                    <th>Approved business category</th>
                    <th>Listed business category</th>
                    <th>Marketplace category</th>
                    <th>Latest scan</th>
                    <th>Image checks</th>
                    <th>Status</th>
                    <th>Review actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $product)
                    <tr data-image-advisory-product-id="{{ $product['id'] }}">
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
                            <div class="admin-category-review-cell admin-category-review-image-check">
                                <span class="status-badge {{ $product['image_category_class'] }}" data-image-advisory-status data-image-advisory-code="{{ $product['image_category_code'] }}">{{ $product['image_category_status'] }}</span>
                                <small data-image-advisory-description @if (! $product['image_category_description']) hidden @endif>{{ $product['image_category_description'] }}</small>
                                <small data-image-advisory-reason @if (! $product['image_category_reason']) hidden @endif>{{ $product['image_category_reason'] }}</small>
                                <small data-image-advisory-helper @if (! $product['image_category_helper']) hidden @endif>{{ $product['image_category_helper'] }}</small>
                                <small data-image-advisory-checked-at @if (! $product['image_category_checked_at']) hidden @endif>@if ($product['image_category_checked_at'])Checked {{ $product['image_category_checked_at'] }}@endif</small>
                                <details class="admin-category-review-image-list">
                                    <summary>Review all images ({{ count($product['image_reviews']) }})</summary>
                                    <div class="admin-category-review-image-list-items">
                                        @forelse ($product['image_reviews'] as $imageReview)
                                            @php
                                                $categoryStatus = $imageReview['category_status'];
                                                $safetyStatus = $imageReview['safety_status'];
                                                $categoryBadge = match ($categoryStatus) {
                                                    'match' => 'badge-success',
                                                    'possible_mismatch' => 'badge-warning',
                                                    'pending' => 'badge-info',
                                                    default => 'badge-neutral',
                                                };
                                                $safetyBadge = match ($safetyStatus) {
                                                    'clear' => 'badge-success',
                                                    'prohibited' => 'badge-danger',
                                                    'possible_violation' => 'badge-warning',
                                                    'pending' => 'badge-info',
                                                    default => 'badge-neutral',
                                                };
                                            @endphp
                                            <article class="admin-category-review-image-item" data-image-review-image-id="{{ $imageReview['product_image_id'] }}">
                                                @if ($imageReview['image_url'])
                                                    <img src="{{ $imageReview['image_url'] }}" alt="{{ $imageReview['image_alt'] }}" loading="lazy">
                                                @else
                                                    <span class="admin-category-review-no-image" aria-label="Product image unavailable"><i data-lucide="image-off" aria-hidden="true"></i></span>
                                                @endif
                                                <div>
                                                    <strong>{{ str($imageReview['purpose'])->replace('_', ' ')->title() }} image{{ $imageReview['variant_id'] ? ' · Variant #'.$imageReview['variant_id'] : '' }}</strong>
                                                    <small>Category: <span class="status-badge {{ $categoryBadge }}" data-image-review-category-status>{{ str($categoryStatus)->replace('_', ' ')->title() }}</span></small>
                                                    <small>Safety: <span class="status-badge {{ $safetyBadge }}" data-image-review-safety-status>{{ str($safetyStatus)->replace('_', ' ')->title() }}</span></small>
                                                    @if ($imageReview['visible_item_description'])
                                                        <small data-image-review-description>{{ $imageReview['visible_item_description'] }}</small>
                                                    @else
                                                        <small data-image-review-description hidden></small>
                                                    @endif
                                                    @if ($imageReview['category_reason'] || $imageReview['safety_reason'])
                                                        <small data-image-review-reason>{{ $imageReview['category_reason'] ?: $imageReview['safety_reason'] }}</small>
                                                    @else
                                                        <small data-image-review-reason hidden></small>
                                                    @endif
                                                </div>
                                            </article>
                                        @empty
                                            <p class="admin-category-review-image-list-empty">No product images are available for review.</p>
                                        @endforelse
                                    </div>
                                </details>
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
                                @if ($product['awaiting_manual_image_review'] && $product['can_approve_image_review'])
                                    <details class="admin-category-review-action">
                                        <summary class="admin-category-review-summary">
                                            <i data-lucide="shield-check" aria-hidden="true"></i>
                                            Approve after image review
                                        </summary>
                                        <form method="POST" action="{{ route('admin.compliance.category-reviews.image-approved', $product['id']) }}">
                                            @csrf
                                            <label for="image-review-reason-{{ $product['id'] }}">
                                                Review reason <span>Required; all current images must be inspected</span>
                                            </label>
                                            <textarea id="image-review-reason-{{ $product['id'] }}" name="reason" maxlength="2000" required></textarea>
                                            <button class="button button-primary button-small" type="submit">Approve listing</button>
                                        </form>
                                    </details>
                                @elseif (! $product['awaiting_manual_image_review'])
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
                                @endif
                                <details class="admin-category-review-action is-correction">
                                    <summary class="admin-category-review-summary">
                                        <i data-lucide="flag" aria-hidden="true"></i>
                                        {{ $product['awaiting_manual_image_review'] ? 'Keep hidden and flag' : 'Flag for correction' }}
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
                        <td colspan="9">
                            <div class="admin-category-review-empty">
                                <i data-lucide="shield-check" aria-hidden="true"></i>
                                <strong>No listings need manual category review</strong>
                                            <span>Listings with unresolved Product Violations are handled in that queue. Uncertain or unavailable image checks stay hidden here until an Admin records a decision.</span>
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
