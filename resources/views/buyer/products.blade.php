@extends('layouts.app')

@section('title', 'Products | Bearly')

@section('content')

<div class="products-page">

    <div class="products-header">

        <h1>🛍️ All Products</h1>

        <div class="filters-bar">

            <div class="category-filter">

                <label>Category:</label>

                <select id="categorySelect" onchange="filterProducts()">

                    @foreach($categories as $cat)

                        <option value="{{ $cat }}" {{ request('category') == $cat ? 'selected' : '' }}>

                            {{ $cat }}

                        </option>

                    @endforeach

                </select>

            </div>


            <div class="search-filter">

                <input 
                    type="text" 
                    id="searchInput"
                    placeholder="Search products..."
                    value="{{ request('search') }}"
                >

                <button onclick="searchProducts()">🔍 Search</button>

            </div>


            <div class="sort-filter">

                <label>Sort by:</label>

                <select id="sortSelect" onchange="sortProducts()">

                    <option value="featured" {{ request('sort') == 'featured' ? 'selected' : '' }}>Featured</option>

                    <option value="newest" {{ request('sort') == 'newest' ? 'selected' : '' }}>Newest</option>

                    <option value="popular" {{ request('sort') == 'popular' ? 'selected' : '' }}>Most Popular</option>

                    <option value="price_low" {{ request('sort') == 'price_low' ? 'selected' : '' }}>Price: Low to High</option>

                    <option value="price_high" {{ request('sort') == 'price_high' ? 'selected' : '' }}>Price: High to Low</option>

                </select>

            </div>

        </div>

    </div>


    @if($products->count() > 0)

        <div class="products-grid">

            @foreach($products as $product)

                <article class="product-card" data-product-id="{{ $product->id }}">

                    <div class="product-image-wrapper">

                        <img
                            src="https://images.unsplash.com/photo-{{ rand(1000000000, 9999999999) }}?auto=format&fit=crop&w=700&q=80"
                            alt="{{ $product->name }}"
                            class="product-image"
                        >

                        @if($product->badge)

                            <span class="product-badge">
                                {{ $product->badge }}
                            </span>

                        @endif

                        @if($product->discount_percentage)

                            <span class="discount-badge">
                                -{{ $product->discount_percentage }}%
                            </span>

                        @endif

                        <button
                            class="wishlist-product wishlist-toggle"
                            type="button"
                            data-product-id="{{ $product->id }}"
                        >
                            ♡
                        </button>

                    </div>

                    <div class="product-info">

                        <h3 class="product-name">
                            {{ $product->name }}
                        </h3>

                        <div class="price-row">

                            <span class="current-price">
                                RM {{ number_format($product->price, 2) }}
                            </span>

                            @if($product->original_price)

                                <span class="old-price">
                                    RM {{ number_format($product->original_price, 2) }}
                                </span>

                            @endif

                        </div>

                        <div class="product-meta">

                            <span class="rating">
                                ★ {{ $product->rating }}
                                <span>
                                    ({{ number_format($product->sold_count) }} sold)
                                </span>
                            </span>

                            @if($product->free_shipping)

                                <span class="shipping-badge">
                                    FREE SHIP
                                </span>

                            @endif

                        </div>

                        <div class="product-location">
                            📍 {{ $product->location }}
                        </div>

                        <div class="product-shop">
                            Seller: <strong>{{ $product->shop->name }}</strong>
                        </div>

                        <button
                            class="add-to-cart-btn"
                            type="button"
                            data-server-cart="true"
                            data-product-id="{{ $product->id }}"
                            data-product-name="{{ $product->name }}"
                            data-product-price="{{ $product->price }}"
                        >
                            + Add to Cart
                        </button>

                    </div>

                </article>

            @endforeach

        </div>


        <!-- Pagination -->
        <div class="pagination">

            {{ $products->links() }}

        </div>

    @else

        <div class="no-products">

            <p>No products found. Try adjusting your filters.</p>

            <a href="{{ route('products.index') }}">Clear Filters</a>

        </div>

    @endif

</div>

<style>
    .products-page {
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px;
    }

    .products-header {
        margin-bottom: 30px;
    }

    .products-header h1 {
        font-size: 32px;
        margin: 0 0 20px 0;
    }

    .filters-bar {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
        padding: 20px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .category-filter, .sort-filter {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .category-filter label, .sort-filter label {
        font-weight: 600;
        color: #333;
    }

    .category-filter select, .sort-filter select {
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
        cursor: pointer;
    }

    .search-filter {
        display: flex;
        gap: 10px;
    }

    .search-filter input {
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
        min-width: 200px;
    }

    .search-filter button {
        padding: 8px 16px;
        background: #d4a574;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-weight: 600;
    }

    .search-filter button:hover {
        background: #c19560;
    }

    .products-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .product-card {
        background: white;
        border-radius: 8px;
        padding: 15px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        transition: transform 0.2s;
    }

    .product-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
    }

    .product-image-wrapper {
        position: relative;
        width: 100%;
        padding-bottom: 100%;
        margin-bottom: 10px;
        border-radius: 4px;
        overflow: hidden;
        background: #f5f5f5;
    }

    .product-image {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .product-badge, .discount-badge {
        position: absolute;
        font-size: 11px;
        font-weight: 700;
        padding: 4px 8px;
        border-radius: 2px;
        top: 10px;
        background: #ff6b6b;
        color: white;
    }

    .product-badge {
        left: 10px;
    }

    .discount-badge {
        right: 10px;
    }

    .wishlist-product {
        position: absolute;
        bottom: 10px;
        right: 10px;
        background: white;
        border: none;
        border-radius: 50%;
        width: 36px;
        height: 36px;
        cursor: pointer;
        font-size: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .product-name {
        font-size: 14px;
        font-weight: 600;
        margin: 0 0 8px 0;
        height: 36px;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .price-row {
        display: flex;
        gap: 8px;
        margin-bottom: 8px;
    }

    .current-price {
        font-weight: 700;
        font-size: 16px;
        color: #333;
    }

    .old-price {
        text-decoration: line-through;
        color: #999;
        font-size: 12px;
    }

    .product-meta {
        font-size: 12px;
        color: #999;
        margin-bottom: 8px;
    }

    .shipping-badge {
        display: inline-block;
        background: #ffd55b;
        color: #333;
        padding: 2px 6px;
        border-radius: 2px;
        font-size: 10px;
        font-weight: 600;
    }

    .product-location {
        font-size: 12px;
        color: #666;
        margin: 8px 0;
    }

    .product-shop {
        font-size: 12px;
        color: #666;
        margin: 6px 0;
    }

    .add-to-cart-btn {
        width: 100%;
        padding: 10px;
        background: #d4a574;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-weight: 600;
        font-size: 12px;
        margin-top: 10px;
    }

    .add-to-cart-btn:hover {
        background: #c19560;
    }

    .add-to-cart-btn:disabled {
        cursor: wait;
        opacity: 0.8;
    }

    .add-to-cart-btn.is-loading::before {
        display: inline-block;
        width: 12px;
        height: 12px;
        margin-right: 7px;
        vertical-align: -2px;
        border: 2px solid rgba(255, 255, 255, 0.45);
        border-top-color: white;
        border-radius: 50%;
        content: '';
        animation: cart-spinner 0.7s linear infinite;
    }

    .add-to-cart-btn.is-success {
        background: #3f8f68;
    }

    .add-to-cart-btn.is-error {
        background: #b5483f;
    }

    @keyframes cart-spinner {
        to { transform: rotate(360deg); }
    }

    .pagination {
        text-align: center;
        margin-top: 40px;
    }

    .no-products {
        text-align: center;
        padding: 60px 20px;
        background: white;
        border-radius: 8px;
    }

    .no-products p {
        font-size: 16px;
        color: #999;
        margin-bottom: 20px;
    }

    .no-products a {
        color: #d4a574;
        text-decoration: none;
        font-weight: 600;
    }

    @media (max-width: 768px) {
        .filters-bar {
            flex-direction: column;
        }

        .products-grid {
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
        }

        .search-filter {
            width: 100%;
        }

        .search-filter input {
            flex: 1;
        }
    }
</style>

<script>
    function filterProducts() {
        const category = document.getElementById('categorySelect').value;
        const sort = document.getElementById('sortSelect').value;
        const search = document.getElementById('searchInput').value;

        let url = '{{ route("products.index") }}?';

        if (category && category !== 'All') {
            url += 'category=' + encodeURIComponent(category) + '&';
        }

        if (sort && sort !== 'featured') {
            url += 'sort=' + encodeURIComponent(sort) + '&';
        }

        if (search) {
            url += 'search=' + encodeURIComponent(search) + '&';
        }

        window.location.href = url;
    }

    function sortProducts() {
        filterProducts();
    }

    function searchProducts() {
        filterProducts();
    }

    // Add to Cart functionality
    document.querySelectorAll('.add-to-cart-btn[data-server-cart="true"]').forEach(button => {
        button.addEventListener('click', async function(e) {
            e.preventDefault();
            if (this.disabled) return;

            const originalLabel = this.textContent.trim();
            this.disabled = true;
            this.classList.add('is-loading');
            this.classList.remove('is-success', 'is-error');
            this.textContent = 'Adding...';

            try {
                const response = await fetch('{{ route("cart.add") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        product_id: this.dataset.productId,
                        quantity: 1
                    })
                });

                if (!response.ok) throw new Error('Cart request failed');

                const data = await response.json();
                if (!data.success) throw new Error(data.message || 'Cart request failed');

                const counter = document.getElementById('cartCount');
                if (counter && data.cart_count !== undefined) counter.textContent = data.cart_count;
                this.classList.remove('is-loading');
                this.classList.add('is-success');
                this.textContent = 'Added to Cart';
            } catch (error) {
                this.classList.remove('is-loading');
                this.classList.add('is-error');
                this.textContent = 'Try Again';
            } finally {
                setTimeout(() => {
                    this.disabled = false;
                    this.classList.remove('is-success', 'is-error');
                    this.textContent = originalLabel;
                }, 1800);
            }
        });
    });

    // Wishlist toggle
    document.querySelectorAll('.wishlist-toggle').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const productId = this.dataset.productId;

            fetch('{{ route("wishlist.toggle") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    product_id: productId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.is_wishlisted) {
                        this.style.color = '#ff4757';
                        this.textContent = '♥';
                    } else {
                        this.style.color = '#999';
                        this.textContent = '♡';
                    }
                }
            });
        });
    });
</script>

@endsection
