@extends('layouts.seller')

@section('title', 'Products')
@section('page-title', 'Products')

@section('content')
<section class="page-heading products-heading">
    <div>
        <h2>Product Management</h2>
        <p>Manage your catalog, pricing, product status, and inventory.</p>
    </div>
    @if ($counts['all'] > 0)
        <a class="seller-primary-button page-add-button" href="{{ route('seller.products.create') }}">
            <i data-lucide="plus"></i>Add Product
        </a>
    @endif
</section>

@if (session('compliance_warning'))
    <div class="product-compliance-feedback" role="alert">
        <i data-lucide="triangle-alert" aria-hidden="true"></i>
        <span>{{ session('compliance_warning') }}</span>
    </div>
@endif

@if ($counts['all'] === 0)
    <section class="seller-panel product-onboarding-card" aria-labelledby="empty-products-title">
        <span class="product-empty-icon" aria-hidden="true">
            <i data-lucide="package-open"></i>
        </span>
        <div class="product-onboarding-copy">
            <span class="section-kicker">Product catalog</span>
            <h3 id="empty-products-title">Start building your catalog</h3>
            <p>Add your first product with its category, pricing, stock level, images, and optional variations.</p>
            <a class="seller-primary-button" href="{{ route('seller.products.create') }}">
                <i data-lucide="plus"></i>Add Your First Product
            </a>
        </div>
        <div class="product-onboarding-checklist" aria-label="Product setup includes">
            <span><i data-lucide="image"></i><strong>Product media</strong><small>Primary and gallery images</small></span>
            <span><i data-lucide="badge-percent"></i><strong>Pricing tools</strong><small>Discount and voucher settings</small></span>
            <span><i data-lucide="boxes"></i><strong>Inventory controls</strong><small>Stock threshold and variations</small></span>
        </div>
    </section>
@else
    <section class="product-stat-grid" aria-label="Product catalog summary">
        @foreach ([
            ['All Products', $counts['all'], 'package', 'brown'],
            ['Active', $counts['active'], 'circle-check-big', 'olive'],
            ['Low Stock', $counts['low'], 'triangle-alert', 'gold'],
            ['Archived', $counts['archived'], 'archive', 'gray']
        ] as [$label, $value, $icon, $tone])
            <article class="product-stat-card">
                <span class="product-stat-icon tone-{{ $tone }}"><i data-lucide="{{ $icon }}"></i></span>
                <div><p>{{ $label }}</p><strong>{{ $value }}</strong></div>
            </article>
        @endforeach
    </section>

    <form class="product-filter-bar" method="GET" action="{{ route('seller.products') }}" aria-label="Product filters" data-product-filter-form>
        <label class="product-search">
            <i data-lucide="search" aria-hidden="true"></i>
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Search by product name or variant SKU" aria-label="Search by product name or active variant SKU">
        </label>
        <label class="product-filter-select">
            <span>Subcategory</span>
            <select name="subcategory" aria-label="Filter by subcategory" data-product-filter-auto-submit>
                <option value="">All Subcategories</option>
                <option value="none" @selected(request('subcategory') === 'none')>No subcategory</option>
                @foreach ($subcategories as $subcategory)
                    <option value="{{ $subcategory->id }}" @selected((string) request('subcategory') === (string) $subcategory->id)>{{ $subcategory->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="product-filter-select">
            <span>Status</span>
            <select name="status" aria-label="Filter by status" data-product-filter-auto-submit>
                <option value="">All Statuses</option>
                @foreach ($statuses as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>@endforeach
            </select>
        </label>
        <div class="product-filter-actions">
            <button class="seller-secondary-button" type="submit"><i data-lucide="filter" aria-hidden="true"></i>Apply</button>
            @if (request()->hasAny(['search', 'subcategory', 'status']))
                <a class="product-filter-reset" href="{{ route('seller.products') }}">Clear</a>
            @endif
        </div>
    </form>

    <section class="seller-panel product-table-card">
        <div class="product-table-wrap">
            <table class="product-management-table">
                <thead>
                    <tr><th>Product</th><th>Subcategory</th><th>Price</th><th>Stock</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    @foreach ($products as $product)
                        @php
                            $discount = (int) $product['discount_percent'];
                            $salePrice = (float) $product['price'] * (1 - ($discount / 100));
                            $variantLabels = collect($product['variants'] ?? [])
                                ->pluck('label')
                                ->filter()
                                ->values();
                            $variantPreview = $variantLabels->take(2)->implode(' · ');
                            $remainingVariants = max(0, $variantLabels->count() - 2);
                            $isLowStock = $product['status'] === 'Active'
                                && (int) $product['stock'] <= (int) $product['low_stock_threshold'];
                        @endphp
                        <tr
                            data-product-row
                            data-name="{{ strtolower($product['name'].' '.collect($product['variants'] ?? [])->pluck('sku')->implode(' ')) }}"
                            data-subcategory="{{ $product['subcategory'] }}"
                            data-status="{{ $product['status'] }}"
                        >
                            <td>
                                <div class="product-name-cell">
                                    <span class="product-image">
                                        @if ($product['image_url'])
                                            <img src="{{ $product['image_url'] }}" alt="{{ $product['name'] }}" loading="lazy">
                                        @else
                                            <i data-lucide="package"></i>
                                        @endif
                                    </span>
                                    <span class="product-name-copy">
                                        <strong>{{ $product['name'] }}</strong>
                                        <small>{{ $variantLabels->count() > 1 ? 'Variant SKUs managed per combination' : ($product['sku'] ?: 'No SKU assigned') }}</small>
                                        @if ($variantLabels->isNotEmpty())
                                            <small class="product-variant-summary">
                                                {{ $variantLabels->count() }} {{ $variantLabels->count() === 1 ? 'variant' : 'variants' }} · {{ $variantPreview }}@if ($remainingVariants > 0) · +{{ $remainingVariants }} more @endif
                                            </small>
                                        @endif
                                        @foreach ($product['compliance_warnings'] as $warning)
                                            <span class="product-compliance-warning">
                                                <strong>{{ $warning['level'] }} compliance warning</strong>
                                                <small>{{ $warning['reason'] }}@if ($warning['detected_excerpt']) Detected text: “{{ $warning['detected_excerpt'] }}”.@endif</small>
                                            </span>
                                        @endforeach
                                    </span>
                                </div>
                            </td>
                            <td>
                                <span class="product-category-cell">
                                    <strong>{{ $product['subcategory'] ?: 'No subcategory' }}</strong>
                                    <small>{{ $product['category'] }}</small>
                                </span>
                            </td>
                            <td>
                                <span class="product-price-cell">
                                    <strong>₱{{ number_format($discount > 0 ? $salePrice : $product['price'], 2) }}</strong>
                                    @if ($discount > 0)
                                        <small><del>₱{{ number_format($product['price'], 2) }}</del> · {{ $discount }}% off</small>
                                    @endif
                                </span>
                            </td>
                            <td>
                                <span class="stock-value {{ $isLowStock ? 'is-low' : '' }}">{{ $product['stock'] }}</span>
                                @if ($isLowStock)<small class="stock-helper">Low stock</small>@endif
                            </td>
                            <td><span class="product-status status-{{ strtolower($product['status']) }}">{{ $product['status'] }}</span></td>
                            <td>
                                <div class="product-row-actions">
                                    <a class="row-action" href="{{ route('seller.products.edit', $product['id']) }}" title="Edit product" aria-label="Edit {{ $product['name'] }}">
                                        <i data-lucide="pencil"></i>
                                    </a>
                                    <form method="POST" action="{{ route('seller.products.archive', array_merge(['product' => $product['id']], request()->only(['search', 'subcategory', 'status']))) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button class="row-action" type="submit" title="{{ $product['status'] === 'Archived' ? 'Restore' : 'Archive' }} product" aria-label="{{ $product['status'] === 'Archived' ? 'Restore' : 'Archive' }} {{ $product['name'] }}">
                                            <i data-lucide="{{ $product['status'] === 'Archived' ? 'archive-restore' : 'archive' }}"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    @if ($products->isEmpty())
                        <tr><td colspan="6">
                            <div class="no-filter-results">
                                <i data-lucide="search-x" aria-hidden="true"></i>
                                <strong>No matching products</strong>
                                <span>Try another name, active variant SKU, subcategory, or status.</span>
                            </div>
                        </td></tr>
                    @endif
                </tbody>
            </table>
        </div>
        <div class="product-pagination">
            <span>
                @if ($products->total() > 0)
                    Showing {{ $products->firstItem() }}–{{ $products->lastItem() }} of {{ $products->total() }} products
                @else
                    No products match these filters
                @endif
            </span>
            @if ($products->hasPages())
                <nav class="product-pagination-controls" aria-label="Product pages">
                    @if ($products->onFirstPage())
                        <span class="is-disabled" aria-disabled="true">Previous</span>
                    @else
                        <a href="{{ $products->previousPageUrl() }}" rel="prev">Previous</a>
                    @endif
                    @foreach ($products->getUrlRange(max(1, $products->currentPage() - 2), min($products->lastPage(), $products->currentPage() + 2)) as $page => $url)
                        <a href="{{ $url }}" @if ($page === $products->currentPage()) aria-current="page" class="is-current" @endif>{{ $page }}</a>
                    @endforeach
                    @if ($products->hasMorePages())
                        <a href="{{ $products->nextPageUrl() }}" rel="next">Next</a>
                    @else
                        <span class="is-disabled" aria-disabled="true">Next</span>
                    @endif
                </nav>
            @endif
        </div>
    </section>
@endif
@endsection
