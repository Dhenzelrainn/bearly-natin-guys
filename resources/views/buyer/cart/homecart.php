@extends('layouts.buyer')

@section('title', 'Shopping Cart | Bearly')

@section('content')

<header class="site-header">
    <div class="header-main">
        <a href="{{ route('home') }}" class="logo">
            <div class="logo-bear">🐻</div>
            <span>bearly</span>
        </a>

        <div class="search-container">
            <input type="text" class="search-input" placeholder="Search products, shops, brands...">
            <button type="button" class="search-button">🔍</button>
        </div>

        <div class="header-actions">
            <button class="header-action" type="button">
                <span class="action-icon">🔔<span class="notification-badge">3</span></span>
                <span class="action-label">Alerts</span>
            </button>
            <button class="header-action" type="button">
                <span class="action-icon">💬</span>
                <span class="action-label">Chat</span>
            </button>
            <button class="header-action" type="button">
                <span class="action-icon">♡<span class="notification-badge wishlist-badge">0</span></span>
                <span class="action-label">Wishlist</span>
            </button>
            <button class="header-action" type="button">
                <span class="action-icon">📄</span>
                <span class="action-label">Orders</span>
            </button>
            <button class="header-action" type="button">
                <span class="action-icon">♙</span>
                <span class="action-label">Account</span>
            </button>
            <button class="header-action cart-action" type="button">
                <span class="action-icon">🛒<span class="cart-count">0</span></span>
                <span class="action-label">Cart</span>
            </button>
        </div>
    </div>

    <nav class="main-navigation">
        <a href="{{ route('home') }}" class="nav-link active">All</a>
        @foreach(['Electronics', 'Fashion', 'Home & Living', 'Beauty', 'Sports', 'Books', 'Automotive'] as $cat)
            <a href="{{ route('home') }}?category={{ $cat }}" class="nav-link">{{ $cat }}</a>
        @endforeach
    </nav>
</header>

<div class="cart-page">

    <div class="page-header">
        <h1>🛒 Shopping Cart</h1>
        <a href="{{ route('home') }}">Continue Shopping</a>
    </div>

    @if($cartItems->count() > 0)

        <div class="cart-container">

            <!-- Cart Items -->
            <div class="cart-items-section">

                <div class="cart-items-header">
                    <h2>Items in Cart ({{ $cartItems->count() }})</h2>
                </div>

                <div class="cart-items-list">

                    @foreach($cartItems as $item)

                        <div class="cart-item" data-item-id="{{ $item->id }}">

                            <div class="item-image">
                                <img src="https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=150&q=80" alt="{{ $item->product->name }}">
                            </div>

                            <div class="item-details">

                                <h3>{{ $item->product->name }}</h3>

                                <p class="item-shop">
                                    From: <strong>{{ $item->product->shop->name }}</strong>
                                </p>

                                <p class="item-price">
                                    RM {{ number_format($item->price, 2) }} each
                                </p>

                            </div>

                            <div class="item-quantity">

                                <button class="qty-btn minus" data-item-id="{{ $item->id }}">-</button>

                                <input 
                                    type="number" 
                                    class="qty-input" 
                                    value="{{ $item->quantity }}"
                                    min="1"
                                    data-item-id="{{ $item->id }}"
                                >

                                <button class="qty-btn plus" data-item-id="{{ $item->id }}">+</button>

                            </div>

                            <div class="item-total">
                                RM {{ number_format($item->quantity * $item->price, 2) }}
                            </div>

                            <button class="remove-btn" data-item-id="{{ $item->id }}">
                                🗑️ Remove
                            </button>

                        </div>

                    @endforeach

                </div>

            </div>


            <!-- Order Summary -->
            <div class="order-summary">

                <h2>Order Summary</h2>

                <div class="summary-row">
                    <span>Subtotal:</span>
                    <span>RM {{ number_format($total, 2) }}</span>
                </div>

                <div class="summary-row">
                    <span>Shipping:</span>
                    <span>RM 10.00</span>
                </div>

                <div class="summary-row">
                    <span>Tax:</span>
                    <span>RM {{ number_format($total * 0.06, 2) }}</span>
                </div>

                <div class="summary-divider"></div>

                <div class="summary-row total">
                    <span>Total:</span>
                    <span>RM {{ number_format($total + 10 + ($total * 0.06), 2) }}</span>
                </div>

                <button class="checkout-btn" onclick="window.location.href='{{ route('home') }}/checkout'">
                    Proceed to Checkout
                </button>

                <button class="continue-shopping-btn" onclick="window.location.href='{{ route('products.index') }}'">
                    Continue Shopping
                </button>

            </div>

        </div>

    @else

        <div class="empty-cart">

            <div class="empty-cart-icon">
                🐻
            </div>

            <h2>Your cart is empty</h2>

            <p>Looks like you haven't added anything yet.</p>

            <a href="{{ route('products.index') }}" class="empty-cart-button">
                Start Shopping
            </a>

        </div>

    @endif

</div>

<style>
    .cart-page {
        min-height: calc(100vh - 140px);
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px 40px;
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

    .cart-container {
        display: grid;
        grid-template-columns: 1fr 350px;
        gap: 30px;
    }

    .cart-items-section {
        background: white;
        border-radius: 8px;
        padding: 20px;
    }

    .cart-items-header {
        margin-bottom: 20px;
        border-bottom: 2px solid #f0f0f0;
        padding-bottom: 15px;
    }

    .cart-item {
        display: grid;
        grid-template-columns: 80px 1fr 120px 100px 80px;
        gap: 15px;
        align-items: center;
        padding: 15px 0;
        border-bottom: 1px solid #f0f0f0;
    }

    .item-image {
        width: 80px;
        height: 80px;
        border-radius: 4px;
        overflow: hidden;
    }

    .item-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .item-details h3 {
        margin: 0 0 8px 0;
        font-size: 14px;
        font-weight: 600;
    }

    .item-shop, .item-price {
        margin: 4px 0;
        font-size: 12px;
        color: #999;
    }

    .item-quantity {
        display: flex;
        align-items: center;
        gap: 5px;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 5px;
    }

    .qty-btn {
        background: none;
        border: none;
        cursor: pointer;
        font-size: 16px;
        padding: 2px 8px;
        color: #666;
    }

    .qty-input {
        width: 40px;
        border: none;
        text-align: center;
        font-size: 14px;
    }

    .item-total {
        text-align: right;
        font-weight: 600;
        color: #333;
    }

    .remove-btn {
        background: #fff;
        border: 1px solid #ddd;
        padding: 8px 12px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 12px;
    }

    .remove-btn:hover {
        background: #ffebee;
    }

    .order-summary {
        background: white;
        border-radius: 8px;
        padding: 20px;
        height: fit-content;
        position: sticky;
        top: 20px;
    }

    .order-summary h2 {
        margin: 0 0 20px 0;
        font-size: 18px;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        font-size: 14px;
    }

    .summary-row.total {
        font-size: 16px;
        font-weight: 700;
        color: #333;
    }

    .summary-divider {
        height: 1px;
        background: #f0f0f0;
        margin: 15px 0;
    }

    .checkout-btn, .continue-shopping-btn {
        width: 100%;
        padding: 12px;
        border: none;
        border-radius: 4px;
        font-weight: 600;
        cursor: pointer;
        font-size: 14px;
        margin-top: 10px;
    }

    .checkout-btn {
        background: #d4a574;
        color: white;
    }

    .checkout-btn:hover {
        background: #c19560;
    }

    .continue-shopping-btn {
        background: #f5f5f5;
        color: #333;
    }

    .continue-shopping-btn:hover {
        background: #e8e8e8;
    }

    .empty-cart {
        display: flex;
        min-height: calc(100vh - 230px);
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 70px 20px 100px;
    }

    .empty-cart-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 104px;
        height: 104px;
        margin-bottom: 22px;
        border-radius: 50%;
        background: #f6eadb;
        font-size: 70px;
        line-height: 1;
    }

    .empty-cart h2 {
        margin: 0 0 12px;
        color: #3d2418;
        font-size: 30px;
    }

    .empty-cart p {
        margin: 0 0 30px;
        color: #c27b48;
        font-size: 18px;
    }

    .empty-cart-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 220px;
        min-height: 58px;
        padding: 0 28px;
        border-radius: 17px;
        background: #553022;
        color: white;
        font-size: 17px;
        font-weight: 700;
        text-decoration: none;
        transition: background 0.2s, transform 0.2s;
    }

    .empty-cart-button:hover {
        background: #3d2418;
        transform: translateY(-2px);
    }

    @media (max-width: 768px) {
        .cart-container {
            grid-template-columns: 1fr;
        }

        .cart-item {
            grid-template-columns: 60px 1fr;
        }

        .item-quantity, .item-total, .remove-btn {
            grid-column: 2;
        }

        .order-summary {
            position: relative;
            top: auto;
        }
    }
</style>

<script>
    // Quantity update
    document.querySelectorAll('.qty-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const itemId = this.dataset.itemId;
            const input = document.querySelector(`.qty-input[data-item-id="${itemId}"]`);
            let quantity = parseInt(input.value);

            if (this.classList.contains('plus')) {
                quantity++;
            } else if (this.classList.contains('minus') && quantity > 1) {
                quantity--;
            }

            input.value = quantity;
            updateCartItem(itemId, quantity);
        });
    });

    // Remove from cart
    document.querySelectorAll('.remove-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const itemId = this.dataset.itemId;
            removeFromCart(itemId);
        });
    });

    function updateCartItem(itemId, quantity) {
        fetch(`/cart/${itemId}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ quantity })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            }
        });
    }

    function removeFromCart(itemId) {
        if (confirm('Remove this item from cart?')) {
            fetch(`/cart/${itemId}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }
    }
</script>

@endsection
