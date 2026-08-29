@extends('layouts.seller')

@section('title', 'Products')
@section('page-title', 'Products')

@section('content')
<section class="page-heading products-heading">
    <div><h2>Product Management</h2><p>Add, update, archive, and manage your products.</p></div>
    <button class="seller-primary-button page-add-button" type="button" data-modal-open="product-modal"><i data-lucide="plus"></i>Add Product</button>
</section>

@if ($errors->any())
    <div class="form-alert products-alert"><i data-lucide="circle-alert"></i><span>{{ $errors->first() }}</span></div>
@endif

<section class="product-stat-grid">
    @foreach ([
        ['All Products', $counts['all'], 'package', 'brown'],
        ['Active', $counts['active'], 'circle-check-big', 'olive'],
        ['Low Stock', $counts['low'], 'triangle-alert', 'gold'],
        ['Archived', $counts['archived'], 'archive', 'gray']
    ] as [$label, $value, $icon, $tone])
        <article class="product-stat-card"><span class="product-stat-icon tone-{{ $tone }}"><i data-lucide="{{ $icon }}"></i></span><div><p>{{ $label }}</p><strong>{{ $value }}</strong></div></article>
    @endforeach
</section>

<section class="product-filter-bar" data-product-filters>
    <label class="product-search"><i data-lucide="search"></i><input type="search" placeholder="Search products" data-product-search></label>
    <select aria-label="Filter by category" data-product-category><option value="">All Categories</option>@foreach($categories as $category)<option>{{ $category }}</option>@endforeach</select>
    <select aria-label="Filter by status" data-product-status><option value="">All Status</option><option>Active</option><option>Archived</option></select>
    <button type="button" class="filter-button" data-apply-filter><i data-lucide="list-filter"></i>Filter</button>
</section>

<section class="seller-panel product-table-card">
    <div class="product-table-wrap">
        <table class="product-management-table">
            <thead><tr><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th><th>Actions</th></tr></thead>
            @if ($products->isNotEmpty())
                <tbody>
                    @foreach ($products as $product)
                        <tr data-product-row data-name="{{ strtolower($product['name']) }}" data-category="{{ $product['category'] }}" data-status="{{ $product['status'] }}">
                            <td><div class="product-name-cell"><span class="product-image">@if($product['image'])<img src="{{ asset('storage/'.$product['image']) }}" alt="">@else<i data-lucide="package"></i>@endif</span><strong>{{ $product['name'] }}</strong></div></td>
                            <td>{{ $product['category'] }}</td><td>₱{{ number_format($product['price'], 2) }}</td>
                            <td><span class="stock-value {{ $product['stock'] <= 5 ? 'is-low' : '' }}">{{ $product['stock'] }}</span></td>
                            <td><span class="product-status status-{{ strtolower($product['status']) }}">{{ $product['status'] }}</span></td>
                            <td><form method="POST" action="{{ route('seller.products.archive', $product['id']) }}">@csrf @method('PATCH')<button class="row-action" type="submit" title="{{ $product['status'] === 'Archived' ? 'Restore' : 'Archive' }}"><i data-lucide="{{ $product['status'] === 'Archived' ? 'archive-restore' : 'archive' }}"></i></button></form></td>
                        </tr>
                    @endforeach
                </tbody>
            @endif
        </table>
    </div>

    @if ($products->isEmpty())
        <div class="product-empty-state">
            <img src="{{ asset('images/seller-product-empty-v2.png') }}" alt="Bearly bear holding an empty product box">
            <div class="empty-state-copy"><h3>No products yet</h3><p>Add your first product to start selling on Bearly.</p><button class="seller-primary-button" type="button" data-modal-open="product-modal"><i data-lucide="plus"></i>Add Your First Product</button><div class="empty-tip"><i data-lucide="lightbulb"></i><span>You can add product images, prices, stock,<br>and variations such as size or color.</span></div></div>
        </div>
    @else
        <div class="no-filter-results" data-no-results hidden><i data-lucide="search-x"></i><strong>No matching products</strong><span>Try a different search or filter.</span></div>
    @endif
</section>

<div class="seller-modal" data-modal="product-modal" hidden>
    <button class="modal-backdrop" type="button" data-modal-close aria-label="Close product form"></button>
    <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="product-modal-title">
        <div class="modal-heading"><div><span class="section-kicker">Product catalog</span><h3 id="product-modal-title">Add New Product</h3></div><button type="button" data-modal-close><i data-lucide="x"></i></button></div>
        <form method="POST" action="{{ route('seller.products.add') }}" enctype="multipart/form-data" class="product-form">@csrf
            <label class="seller-field"><span>Product Name</span><input name="name" value="{{ old('name') }}" placeholder="e.g. Classic Linen Shirt" required></label>
            <div class="product-form-grid"><label class="seller-field"><span>Category</span><input name="category" value="{{ old('category') }}" placeholder="e.g. Fashion and Apparel" required></label><label class="seller-field"><span>Price (₱)</span><input type="number" name="price" value="{{ old('price') }}" min="0" step="0.01" placeholder="0.00" required></label></div>
            <div class="product-form-grid"><label class="seller-field"><span>Stock</span><input type="number" name="stock" value="{{ old('stock', 0) }}" min="0" required></label><label class="seller-field"><span>Product Image</span><input type="file" name="image" accept="image/png,image/jpeg,image/webp"></label></div>
            <div class="modal-actions"><button type="button" class="draft-button" data-modal-close>Cancel</button><button class="seller-primary-button" type="submit"><i data-lucide="plus"></i>Add Product</button></div>
        </form>
    </section>
</div>
@endsection
