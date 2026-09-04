@extends('layouts.seller')

@section('title', 'Products')
@section('page-title', 'Products')

@section('content')
<section class="page-heading products-heading">
    <div>
        <h2>Product Management</h2>
        <p>Manage your catalog, pricing, product status, and inventory.</p>
    </div>
    @if ($products->isNotEmpty())
        <a class="seller-primary-button page-add-button" href="{{ route('seller.products.create') }}">
            <i data-lucide="plus"></i>Add Product
        </a>
    @endif
</section>

@if ($products->isEmpty())
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

    <section class="product-filter-bar" data-product-filters aria-label="Product filters">
        <label class="product-search">
            <i data-lucide="search"></i>
            <input type="search" placeholder="Search by product name or SKU" data-product-search>
        </label>
        <select aria-label="Filter by category" data-product-category>
            <option value="">All Categories</option>
            @foreach ($categories as $category)<option>{{ $category }}</option>@endforeach
        </select>
        <select aria-label="Filter by status" data-product-status>
            <option value="">All Statuses</option>
            @foreach ($statuses as $status)<option>{{ $status }}</option>@endforeach
        </select>
    </section>

    <section class="seller-panel product-table-card">
        <div class="product-table-wrap">
            <table class="product-management-table">
                <thead>
                    <tr><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    @foreach ($products as $product)
                        @php
                            $discount = (int) $product['discount_percent'];
                            $salePrice = (float) $product['price'] * (1 - ($discount / 100));
                            $isLowStock = $product['status'] === 'Active'
                                && (int) $product['stock'] <= (int) $product['low_stock_threshold'];
                        @endphp
                        <tr
                            data-product-row
                            data-name="{{ strtolower($product['name'].' '.$product['sku']) }}"
                            data-category="{{ $product['category'] }}"
                            data-status="{{ $product['status'] }}"
                        >
                            <td>
                                <div class="product-name-cell">
                                    <span class="product-image">
                                        @if ($product['image'])
                                            <img src="{{ asset('storage/'.$product['image']) }}" alt="{{ $product['name'] }}">
                                        @else
                                            <i data-lucide="package"></i>
                                        @endif
                                    </span>
                                    <span class="product-name-copy">
                                        <strong>{{ $product['name'] }}</strong>
                                        <small>{{ $product['sku'] ?: 'No SKU assigned' }}</small>
                                    </span>
                                </div>
                            </td>
                            <td>{{ $product['category'] }}</td>
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
                                    <form method="POST" action="{{ route('seller.products.archive', $product['id']) }}">
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
                </tbody>
            </table>
        </div>
        <div class="no-filter-results" data-no-results hidden>
            <i data-lucide="search-x"></i>
            <strong>No matching products</strong>
            <span>Try a different search term or filter.</span>
        </div>
    </section>
@endif
@endsection
