@extends('layouts.app')

@section('title', 'Wishlist | Bearly')

@section('content')

<div class="wishlist-page">

    <div class="page-header">
        <h1>♥ My Wishlist</h1>
        <a href="{{ route('home') }}">Continue Shopping</a>
    </div>

    @if($wishlistItems->count() > 0)

        <div class="wishlist-container">

            <div class="wishlist-info">
                You have {{ $wishlistItems->count() }} item(s) in your wishlist
            </div>

            <div class="products-grid">

                @foreach($wishlistItems as $item)

                    <article class="product-card wishlist-item" data-product-id="{{ $item->product->id }}">

                        <div class="product-image-wrapper">

                            <img
                                src="https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=700&q=80"
                                alt="{{ $item->product->name }}"
                                class="product-image"
                            >

                            @if($item->product->badge)

                                <span class="product-badge">
                                    {{ $item->product->badge }}
                                </span>

                            @endif

                            @if($item->product->discount_percentage)

                                <span class="discount-badge">
                                    -{{ $item->product->discount_percentage }}%
                                </span>

                            @endif

                            <button
                                class="wishlist-product wishlist-remove"
                                type="button"
                                data-product-id="{{ $item->product->id }}"
                            >
                                ♥
                            </button>

                        </div>

                        <div class="product-info">

                            <h3 class="product-name">
                                {{ $item->product->name }}
                            </h3>

                            <div class="price-row">

                                <span class="current-price">
                                    RM {{ number_format($item->product->price, 2) }}
                                </span>

                                @if($item->product->original_price)

                                    <span class="old-price">
                                        RM {{ number_format($item->product->original_price, 2) }}
                                    </span>

                                @endif

                            </div>

                            <div class="product-meta">

                                <span class="rating">
                                    ★ {{ $item->product->rating }}
                                    <span>
                                        ({{ number_format($item->product->sold_count) }} sold)
                                    </span>
                                </span>

                                @if($item->product->free_shipping)

                                    <span class="shipping-badge">
                                        FREE SHIP
                                    </span>

                                @endif

                            </div>

                            <div class="product-location">
                                📍 {{ $item->product->location }}
                            </div>

                            <button
                                class="add-to-cart-btn"
                                data-product-id="{{ $item->product->id }}"
                                data-product-name="{{ $item->product->name }}"
                                data-product-price="{{ $item->product->price }}"
                            >
                                + Add to Cart
                            </button>

                        </div>

                    </article>

                @endforeach

            </div>

        </div>

    @else

        <div class="empty-wishlist">

            <div class="empty-wishlist-icon">
                ♡
            </div>

            <h2>Your wishlist is empty</h2>

            <p>Start adding your favorite products to your wishlist!</p>

            <a href="{{ route('products.index') }}" class="primary-button">
                Browse Products
            </a>

        </div>

    @endif

</div>

<style>
    .wishlist-page {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 2px solid #f0f0f0;
    }

    .page-header h1 {
        font-size: 32px;
        margin: 0;
    }

    .page-header a {
        color: #d4a574;
        text-decoration: none;
        font-weight: 600;
    }

    .wishlist-info {
        background: #fff8f0;
        padding: 15px 20px;
        border-radius: 4px;
        color: #666;
        margin-bottom: 20px;
        font-weight: 600;
    }

    .products-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 20px;
    }

    .product-card {
        background: white;
        border-radius: 8px;
        padding: 15px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        transition: transform 0.2s;
    }

    .product-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .product-image-wrapper {
        position: relative;
        width: 100%;
        padding-bottom: 100%;
        margin-bottom: 10px;
        border-radius: 4px;
        overflow: hidden;
        background: #f0f0f0;
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
    }

    .product-badge {
        left: 10px;
        background: #ff6b6b;
        color: white;
    }

    .discount-badge {
        right: 10px;
        background: #ff6b6b;
        color: white;
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
        color: #ff4757;
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

    .rating {
        display: block;
        margin-bottom: 4px;
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

    .empty-wishlist {
        text-align: center;
        padding: 60px 20px;
    }

    .empty-wishlist-icon {
        font-size: 80px;
        margin-bottom: 20px;
        opacity: 0.5;
    }

    .empty-wishlist h2 {
        font-size: 28px;
        margin: 0 0 10px 0;
    }

    .empty-wishlist p {
        color: #999;
        margin: 0 0 30px 0;
    }

    @media (max-width: 768px) {
        .products-grid {
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
        }
    }
</style>

<script>
    // Wishlist remove
    document.querySelectorAll('.wishlist-remove').forEach(btn => {
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
                    location.reload();
                }
            });
        });
    });

    // Add to cart from wishlist
    document.querySelectorAll('.add-to-cart-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const productId = this.dataset.productId;
            const productName = this.dataset.productName;

            fetch('{{ route("cart.add") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    product_id: productId,
                    quantity: 1
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✓ ' + productName + ' added to cart!');
                }
            });
        });
    });
</script>

@endsection
