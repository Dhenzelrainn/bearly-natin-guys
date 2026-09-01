@extends('layouts.app')

@section('title', 'Bearly | Everything You Need')

@section('content')

<!-- =========================================================
     HEADER
========================================================= -->

<header class="site-header">

    <!-- Top Header -->
    <div class="header-main">

        <!-- Logo -->
        <a href="{{ route('home') }}" class="logo">

            <div class="logo-bear">
                🐻
            </div>

            <span>bearly</span>

        </a>


        <!-- Search -->
        <div class="search-container">

            <input
                type="text"
                id="searchInput"
                class="search-input"
                placeholder="Search products, shops, brands..."
            >

            <button
                type="button"
                class="search-button"
                id="searchButton"
            >
                🔍
            </button>

        </div>


        <!-- Header Actions -->
        <div class="header-actions">

            <!-- Alerts -->
            <button class="header-action" id="alertsBtn" type="button">

                <span class="action-icon">
                    🔔

                    <span class="notification-badge">
                        3
                    </span>
                </span>

                <span class="action-label">
                    Alerts
                </span>

            </button>


            <!-- Chat -->
            <button class="header-action" id="chatBtn" type="button">

                <span class="action-icon">
                    💬
                </span>

                <span class="action-label">
                    Chat
                </span>

            </button>


            <!-- Wishlist -->
            <button class="header-action" id="wishlistBtn">

                <span class="action-icon">

                    ♡

                    <span class="notification-badge wishlist-badge" id="wishlistCount">
                        0
                    </span>

                </span>

                <span class="action-label">
                    Wishlist
                </span>

            </button>


            <!-- Orders -->
            <button class="header-action">

                <span class="action-icon">
                    📄
                </span>

                <span class="action-label">
                    Orders
                </span>

            </button>


            <!-- Account -->
            <button class="header-action">

                <span class="action-icon">
                    ♙
                </span>

                <span class="action-label">
                    Account
                </span>

            </button>


            <!-- Cart -->
            <button class="header-action cart-action" id="cartBtn">

                <span class="action-icon">

                    🛒

                    <span
                        class="cart-count"
                        id="cartCount"
                    >
                        0
                    </span>

                </span>

                <span class="action-label">
                    Cart
                </span>

            </button>

        </div>

    </div>


    <!-- Navigation -->
    <nav class="main-navigation">

        <a href="/" class="nav-link active">
            All
        </a>

        @foreach(['Electronics', 'Fashion', 'Home & Living', 'Beauty', 'Sports', 'Books', 'Automotive'] as $cat)
            <a href="/?category={{ $cat }}" class="nav-link">
                {{ $cat }}
            </a>
        @endforeach

    </nav>

</header>


<!-- =========================================================
     NOTIFICATIONS DRAWER
========================================================= -->

<div class="notification-overlay" id="notificationOverlay" hidden></div>

<aside class="notification-drawer" id="notificationDrawer" aria-label="Notifications" aria-hidden="true">
    <div class="notification-drawer-header">
        <div>
            <h2>Notifications</h2>
            <span id="unreadNotificationLabel">3 unread</span>
        </div>

        <div class="notification-header-actions">
            <button class="mark-read-btn" id="markAllReadBtn" type="button">Mark all read</button>
            <button class="close-notifications-btn" id="closeNotificationsBtn" type="button" aria-label="Close notifications">×</button>
        </div>
    </div>

    <div class="notification-list" id="notificationList">
        <article class="notification-item unread">
            <span class="notification-icon">🚚</span>
            <div class="notification-copy">
                <strong>Your order is out for delivery!</strong>
                <p>ORD-2026-08-14291 · Expected today by 6PM</p>
                <time>5 min ago</time>
            </div>
            <span class="unread-dot" aria-label="Unread"></span>
        </article>

        <article class="notification-item unread">
            <span class="notification-icon">⭐</span>
            <div class="notification-copy">
                <strong>Rate your recent purchase</strong>
                <p>Sony WH-1000XM5 from TechHub Official</p>
                <time>2h ago</time>
            </div>
            <span class="unread-dot" aria-label="Unread"></span>
        </article>

        <article class="notification-item unread">
            <span class="notification-icon">🎟️</span>
            <div class="notification-copy">
                <strong>New voucher available for you</strong>
                <p>RM25 off on orders above RM150 — expires Aug 20</p>
                <time>5h ago</time>
            </div>
            <span class="unread-dot" aria-label="Unread"></span>
        </article>

        <article class="notification-item">
            <span class="notification-icon">✅</span>
            <div class="notification-copy">
                <strong>Payment confirmed</strong>
                <p>RM 899 payment for ORD-2026-08-14291 received.</p>
                <time>1d ago</time>
            </div>
        </article>

        <article class="notification-item">
            <span class="notification-icon">💬</span>
            <div class="notification-copy">
                <strong>New message from TechHub Official</strong>
                <p>"Your order is being packed and will ship tomorrow..."</p>
                <time>1d ago</time>
            </div>
        </article>

        <article class="notification-item">
            <span class="notification-icon">📦</span>
            <div class="notification-copy">
                <strong>Order ready to ship</strong>
                <p>ORD-2026-08-14291 is packed and handed to courier.</p>
                <time>1d ago</time>
            </div>
        </article>
    </div>
</aside>

<aside class="chat-drawer" id="chatDrawer" aria-label="Messages" aria-hidden="true">
    <section class="chat-conversations">
        <div class="chat-list-header">
            <h2>Messages</h2>
        </div>

        <button class="conversation-item selected" type="button" data-shop="TechHub Official">
            <span class="conversation-avatar techhub-avatar">🔧</span>
            <span class="conversation-copy">
                <strong>TechHub Official</strong>
                <span>Absolutely! It's the full...</span>
                <time>10:36 AM</time>
            </span>
            <span class="conversation-unread">1</span>
        </button>

        <button class="conversation-item" type="button" data-shop="SneakerVault">
            <span class="conversation-avatar sneaker-avatar">👟</span>
            <span class="conversation-copy">
                <strong>SneakerVault</strong>
                <span>Your order has been shipp...</span>
                <time>Yesterday</time>
            </span>
        </button>

        <button class="conversation-item" type="button" data-shop="HomeNest Co.">
            <span class="conversation-avatar home-avatar">🏠</span>
            <span class="conversation-copy">
                <strong>HomeNest Co.</strong>
                <span>Thank you for your purcha...</span>
                <time>2d ago</time>
            </span>
        </button>
    </section>

    <section class="chat-thread">
        <header class="chat-thread-header">
            <span class="conversation-avatar techhub-avatar">🔧</span>
            <div>
                <h2>TechHub Official</h2>
                <span class="online-status">● Online</span>
            </div>
            <button class="close-chat-btn" id="closeChatBtn" type="button" aria-label="Close messages">×</button>
        </header>

        <div class="chat-messages" id="chatMessages">
            <div class="message-row received">
                <div class="message-bubble">Hi! Welcome to TechHub Official. How can I<br>help you today? 😊</div>
                <time>10:30 AM</time>
            </div>
            <div class="message-row sent">
                <div class="message-bubble">Hi, I'm interested in the Sony WH-1000XM5.<br>Is the black variant in stock?</div>
                <time>10:32 AM</time>
            </div>
            <div class="message-row received">
                <div class="message-bubble">Yes! We have the Midnight Black variant in<br>stock. It comes with a 1-year local warranty.<br>Would you like to proceed with the order?</div>
                <time>10:33 AM</time>
            </div>
            <div class="message-row sent">
                <div class="message-bubble">Great! Does it come with the original carry<br>case?</div>
                <time>10:35 AM</time>
            </div>
            <div class="message-row received">
                <div class="message-bubble">Absolutely! It's the full retail box — includes<br>the carry case, USB-C cable, and 3.5mm<br>audio cable. 📦</div>
                <time>10:36 AM</time>
            </div>
        </div>

        <form class="chat-composer" id="chatForm">
            <input id="chatInput" type="text" placeholder="Type a message..." autocomplete="off">
            <button type="submit" aria-label="Send message">➤</button>
        </form>
    </section>
</aside>



<!-- =========================================================
     FREE SHIPPING BAR
========================================================= -->

<div class="shipping-bar">

    🐻

    <strong>
        Free shipping on orders above RM100
    </strong>

    <span>
        ·
    </span>

    Use code

    <strong>
        BEARLY10
    </strong>

    for 10% off your first order

</div>



<!-- =========================================================
     HERO
========================================================= -->

<section class="hero-section">

    <div class="hero-content">

        <div class="hero-copy">

            <span class="hero-label">
                Limited Time Offer
            </span>

            <h1>
                Home Refresh
            </h1>

            <p>
                Transform your living space today
            </p>

            <a href="{{ route('products.index', ['category' => 'Home & Living']) }}" class="primary-button">
                Explore →
            </a>

        </div>


        <div class="hero-image-wrapper">

            <img
                src="https://images.unsplash.com/photo-1556228578-8c89e6adf883?auto=format&fit=crop&w=1200&q=80"
                alt="Home refresh"
                class="hero-image"
            >

        </div>

    </div>


    <!-- Hero slider indicators -->
    <div class="hero-dots">

        <span class="hero-dot active"></span>

        <span class="hero-dot"></span>

        <span class="hero-dot"></span>

    </div>

</section>



<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<main class="main-content">


    <!-- =====================================================
         BROWSE CATEGORIES
    ====================================================== -->

    <section class="categories-section">

        <div class="section-heading">

            <h2>
                Browse Categories
            </h2>

            <a href="{{ route('products.index') }}">
                View all →
            </a>

        </div>


        <div class="categories-grid">

            @foreach($categories as $category)

                <a
                    href="/?category={{ $category['name'] }}"
                    class="category-card"
                >

                    <div class="category-icon">
                        {{ $category['icon'] }}
                    </div>

                    <h3>
                        {{ $category['name'] }}
                    </h3>

                    <span>
                        {{ $category['count'] }}
                    </span>

                </a>

            @endforeach

        </div>

    </section>



    <!-- =====================================================
         RECOMMENDED FOR YOU
    ====================================================== -->

    <section class="recommended-section">

        <div class="section-heading">

            <h2>
                Recommended For You
            </h2>

            <a href="/">
                View all →
            </a>

        </div>


        <!-- Products -->
        <div class="products-grid">

            @forelse($recommendedProducts as $product)

                <article class="product-card" data-product-id="{{ $product['id'] }}">

                    <!-- Product Image -->
                    <div class="product-image-wrapper">

                        <img
                            src="https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=700&q=80"
                            alt="{{ $product['name'] }}"
                            class="product-image"
                        >


                        @if($product['badge'])

                            <span class="product-badge">

                                {{ $product['badge'] }}

                            </span>

                        @endif


                        @if($product['discount'])

                            <span class="discount-badge">

                                -{{ $product['discount'] }}%

                            </span>

                        @endif


                        <button
                            class="wishlist-product wishlist-toggle"
                            type="button"
                            data-product-id="{{ $product['id'] }}"
                            data-product-name="{{ $product['name'] }}"
                        >
                            ♡
                        </button>

                    </div>


                    <!-- Product Information -->
                    <div class="product-info">

                        <h3 class="product-name">

                            {{ $product['name'] }}

                        </h3>


                        <div class="price-row">

                            <span class="current-price">

                                RM {{ number_format($product['price'], 2) }}

                            </span>

                            @if($product['old_price'])

                                <span class="old-price">

                                    RM {{ number_format($product['old_price'], 2) }}

                                </span>

                            @endif

                        </div>


                        <div class="product-meta">

                            <span class="rating">

                                ★ {{ $product['rating'] }}

                                <span>
                                    ({{ $product['sold'] }} sold)
                                </span>

                            </span>


                            @if($product['shipping'])

                                <span class="shipping-badge">
                                    FREE SHIP
                                </span>

                            @endif

                        </div>

                        <div class="product-location">
                            📍 {{ $product['location'] }}
                        </div>

                        <button
                            class="add-to-cart-btn"
                            data-product-id="{{ $product['id'] }}"
                            data-product-name="{{ $product['name'] }}"
                            data-product-price="{{ $product['price'] }}"
                        >

                            + Add to Cart

                        </button>

                    </div>

                </article>

            @empty

                <p class="no-products">No products found</p>

            @endforelse

        </div>

    </section>



    <!-- =====================================================
         FEATURED SHOPS
    ====================================================== -->

    <section class="shops-section">

        <div class="section-heading">

            <h2>
                Featured Shops
            </h2>

            <a href="#">
                View all →
            </a>

        </div>


        <div class="shops-grid">

            @foreach($featuredShops as $shop)

                <div class="shop-card">

                    <div class="shop-header">

                        <img
                            src="{{ $shop['image'] }}"
                            alt="{{ $shop['name'] }}"
                            class="shop-logo"
                        >

                        <div class="shop-title">
                            <h3 class="shop-name">{{ $shop['name'] }}</h3>

                            @if($shop['verified'])
                                <span class="verified-badge">✓ VERIFIED</span>
                            @endif
                        </div>

                    </div>


                    <div class="shop-stats">

                        <div class="stat">

                            <strong>
                                ★ {{ number_format($shop['rating'], 1) }}
                            </strong>

                            <span>
                                Rating
                            </span>

                        </div>


                        <div class="stat">

                            <strong>
                                {{ number_format($shop['followers']) }}
                            </strong>

                            <span>
                                Followers
                            </span>

                        </div>


                        <div class="stat">

                            <strong>
                                {{ number_format($shop['products']) }}
                            </strong>

                            <span>
                                Products
                            </span>

                        </div>

                    </div>


                    <button class="visit-shop-btn" type="button">

                        Visit Shop

                    </button>

                </div>

            @endforeach

        </div>

    </section>



    <!-- =====================================================
         BEST SELLERS
    ====================================================== -->

    <section class="best-sellers-section">

        <div class="section-heading">

            <h2>
                Best Sellers
            </h2>

            <a href="{{ route('products.index', ['sort' => 'popular']) }}">
                View all →
            </a>

        </div>


        <!-- Products -->
        <div class="products-grid">

            @forelse($bestSellers as $product)

                <article class="product-card" data-product-id="{{ $product['id'] }}">

                    <!-- Product Image -->
                    <div class="product-image-wrapper">

                        <img
                            src="https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=700&q=80"
                            alt="{{ $product['name'] }}"
                            class="product-image"
                        >


                        @if($product['badge'])

                            <span class="product-badge">

                                {{ $product['badge'] }}

                            </span>

                        @endif


                        @if($product['discount'])

                            <span class="discount-badge">

                                -{{ $product['discount'] }}%

                            </span>

                        @endif


                        <button
                            class="wishlist-product wishlist-toggle"
                            type="button"
                            data-product-id="{{ $product['id'] }}"
                            data-product-name="{{ $product['name'] }}"
                        >
                            ♡
                        </button>

                    </div>


                    <!-- Product Information -->
                    <div class="product-info">

                        <h3 class="product-name">

                            {{ $product['name'] }}

                        </h3>


                        <div class="price-row">

                            <span class="current-price">

                                RM {{ number_format($product['price'], 2) }}

                            </span>

                            @if($product['old_price'])

                                <span class="old-price">

                                    RM {{ number_format($product['old_price'], 2) }}

                                </span>

                            @endif

                        </div>


                        <div class="product-meta">

                            <span class="rating">

                                ★ {{ $product['rating'] }}

                                <span>
                                    ({{ $product['sold'] }} sold)
                                </span>

                            </span>


                            @if($product['shipping'])

                                <span class="shipping-badge">
                                    FREE SHIP
                                </span>

                            @endif

                        </div>

                        <div class="product-location">
                            📍 {{ $product['location'] }}
                        </div>

                        <button
                            class="add-to-cart-btn"
                            data-product-id="{{ $product['id'] }}"
                            data-product-name="{{ $product['name'] }}"
                            data-product-price="{{ $product['price'] }}"
                        >

                            + Add to Cart

                        </button>

                    </div>

                </article>

            @empty

                <p class="no-products">No products found</p>

            @endforelse

        </div>

    </section>





<!-- =========================================================
     FOOTER
========================================================= -->

<footer class="site-footer">

    <div class="footer-container">


        <!-- Customer Service -->
        <div class="footer-column">

            <h3>
                Customer Service
            </h3>

            <a href="#">
                Help Centre
            </a>

            <a href="#">
                How to Buy
            </a>

            <a href="#">
                Returns & Refunds
            </a>

            <a href="#">
                Contact Us
            </a>

            <a href="#">
                Order Tracking
            </a>

        </div>


        <!-- About -->
        <div class="footer-column">

            <h3>
                About Bearly
            </h3>

            <a href="#">
                About Us
            </a>

            <a href="#">
                Careers
            </a>

            <a href="#">
                Bearly Blog
            </a>

            <a href="#">
                Press Room
            </a>

            <a href="#">
                Bearly Policies
            </a>

        </div>


        <!-- Sell -->
        <div class="footer-column">

            <h3>
                Sell on Bearly
            </h3>

            <a href="#">
                Become a Seller
            </a>

            <a href="#">
                Seller Centre
            </a>

            <a href="#">
                Seller Policies
            </a>

            <a href="#">
                Flash Deals
            </a>

            <a href="#">
                Advertising
            </a>

        </div>


        <!-- Social -->
        <div class="footer-column">

            <h3>
                Follow Us
            </h3>

            <a href="#">
                Facebook
            </a>

            <a href="#">
                Instagram
            </a>

            <a href="#">
                Twitter / X
            </a>

            <a href="#">
                TikTok
            </a>

            <a href="#">
                YouTube
            </a>

        </div>

    </div>


    <div class="footer-bottom">

        © {{ date('Y') }} Bearly.
        Everything you need. Shopping made bearable.

    </div>

</footer>



<!-- =========================================================
     TOAST NOTIFICATION
========================================================= -->

<div
    id="toast"
    class="toast"
>
    Added to cart!
</div>

<script>
    // Initialize cart and wishlist from localStorage
    const cart = JSON.parse(localStorage.getItem('cart')) || [];
    const wishlist = JSON.parse(localStorage.getItem('wishlist')) || [];

    document.addEventListener('DOMContentLoaded', function() {
        updateCartCount();
        updateWishlistCount();

        document.getElementById('alertsBtn').addEventListener('click', openNotifications);
        document.getElementById('closeNotificationsBtn').addEventListener('click', closeNotifications);
        document.getElementById('notificationOverlay').addEventListener('click', closeNotifications);
        document.getElementById('markAllReadBtn').addEventListener('click', markAllNotificationsRead);
        document.getElementById('chatBtn').addEventListener('click', openChat);
        document.getElementById('closeChatBtn').addEventListener('click', closeChat);
        document.getElementById('chatForm').addEventListener('submit', sendMessage);
        document.querySelectorAll('.conversation-item').forEach(item => {
            item.addEventListener('click', function() {
                document.querySelectorAll('.conversation-item').forEach(conversation => conversation.classList.remove('selected'));
                this.classList.add('selected');
            });
        });
        
        // Cart button click
        document.getElementById('cartBtn').addEventListener('click', showCart);
        
        // Wishlist button click
        document.getElementById('wishlistBtn').addEventListener('click', showWishlist);
    });

    function openNotifications() {
        const drawer = document.getElementById('notificationDrawer');
        const overlay = document.getElementById('notificationOverlay');
        drawer.setAttribute('aria-hidden', 'false');
        drawer.classList.add('is-open');
        overlay.hidden = false;
        document.body.classList.add('notifications-open');
    }

    function closeNotifications() {
        const drawer = document.getElementById('notificationDrawer');
        const overlay = document.getElementById('notificationOverlay');
        drawer.setAttribute('aria-hidden', 'true');
        drawer.classList.remove('is-open');
        overlay.hidden = true;
        document.body.classList.remove('notifications-open');
    }

    function markAllNotificationsRead() {
        document.querySelectorAll('.notification-item.unread').forEach(item => {
            item.classList.remove('unread');
            item.querySelector('.unread-dot')?.remove();
        });
        document.getElementById('unreadNotificationLabel').textContent = '0 unread';
        document.querySelector('.notification-badge').textContent = '0';
    }

    function openChat() {
        const drawer = document.getElementById('chatDrawer');
        const overlay = document.getElementById('notificationOverlay');
        drawer.setAttribute('aria-hidden', 'false');
        drawer.classList.add('is-open');
        overlay.hidden = false;
        overlay.onclick = closeChat;
        document.body.classList.add('notifications-open');
    }

    function closeChat() {
        const drawer = document.getElementById('chatDrawer');
        const overlay = document.getElementById('notificationOverlay');
        drawer.setAttribute('aria-hidden', 'true');
        drawer.classList.remove('is-open');
        overlay.hidden = true;
        overlay.onclick = closeNotifications;
        document.body.classList.remove('notifications-open');
    }

    function sendMessage(event) {
        event.preventDefault();
        const input = document.getElementById('chatInput');
        const message = input.value.trim();
        if (!message) return;

        const row = document.createElement('div');
        row.className = 'message-row sent';
        row.innerHTML = `<div class="message-bubble"></div><time>Just now</time>`;
        row.querySelector('.message-bubble').textContent = message;
        document.getElementById('chatMessages').appendChild(row);
        input.value = '';
        document.getElementById('chatMessages').scrollTop = document.getElementById('chatMessages').scrollHeight;
    }

    // Add to Cart
    document.querySelectorAll('.add-to-cart-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const productId = this.dataset.productId;
            const productName = this.dataset.productName;
            const productPrice = parseFloat(this.dataset.productPrice);

            const product = {
                id: productId,
                name: productName,
                price: productPrice,
                quantity: 1
            };

            const existingItem = cart.find(item => item.id === productId);
            if (existingItem) {
                existingItem.quantity++;
            } else {
                cart.push(product);
            }

            localStorage.setItem('cart', JSON.stringify(cart));
            showToast('✓ ' + productName + ' added to cart!');
            updateCartCount();
        });
    });

    // Wishlist Toggle
    document.querySelectorAll('.wishlist-toggle').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const productId = this.dataset.productId;
            const productName = this.dataset.productName;

            const index = wishlist.findIndex(item => item.id === productId);

            if (index > -1) {
                wishlist.splice(index, 1);
                this.style.color = '#999';
                this.textContent = '♡';
                showToast('✗ Removed from wishlist');
            } else {
                wishlist.push({ id: productId, name: productName });
                this.style.color = '#ff4757';
                this.textContent = '♥';
                showToast('♥ Added to wishlist!');
            }

            localStorage.setItem('wishlist', JSON.stringify(wishlist));
            updateWishlistCount();
        });
    });

    // Update cart count
    function updateCartCount() {
        const cart = JSON.parse(localStorage.getItem('cart')) || [];
        document.getElementById('cartCount').textContent = cart.length;
    }

    // Update wishlist count
    function updateWishlistCount() {
        const wishlist = JSON.parse(localStorage.getItem('wishlist')) || [];
        document.getElementById('wishlistCount').textContent = wishlist.length;
    }

    // Show toast
    function showToast(message) {
        const toast = document.getElementById('toast');
        toast.textContent = message;
        toast.style.display = 'block';
        setTimeout(() => {
            toast.style.display = 'none';
        }, 2500);
    }

    // Show cart modal
    function showCart() {
        const cart = JSON.parse(localStorage.getItem('cart')) || [];
        if (cart.length === 0) {
            alert('Your cart is empty!');
            return;
        }
        
        let cartHTML = '🛒 YOUR CART\\n\\n';
        let total = 0;
        cart.forEach((item, index) => {
            const itemTotal = item.price * item.quantity;
            total += itemTotal;
            cartHTML += `${index + 1}. ${item.name}\\n   Qty: ${item.quantity} x RM ${item.price.toFixed(2)} = RM ${itemTotal.toFixed(2)}\\n\\n`;
        });
        cartHTML += `\\nTOTAL: RM ${total.toFixed(2)}\\n\\nProceed to checkout?`;
        alert(cartHTML);
    }

    // Show wishlist modal
    function showWishlist() {
        const wishlist = JSON.parse(localStorage.getItem('wishlist')) || [];
        if (wishlist.length === 0) {
            alert('Your wishlist is empty!');
            return;
        }
        
        let wishlistHTML = '♥ YOUR WISHLIST\\n\\n';
        wishlist.forEach((item, index) => {
            wishlistHTML += `${index + 1}. ${item.name}\\n`;
        });
        alert(wishlistHTML);
    }

    // Search functionality
    document.getElementById('searchButton').addEventListener('click', function() {
        const searchTerm = document.getElementById('searchInput').value.toLowerCase();
        if (searchTerm) {
            const allProducts = document.querySelectorAll('.product-card');
            allProducts.forEach(card => {
                const productName = card.querySelector('.product-name').textContent.toLowerCase();
                if (productName.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
            showToast('✓ Filtered products');
        }
    });

    // Allow Enter key in search
    document.getElementById('searchInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            document.getElementById('searchButton').click();
        }
    });
</script>

@endsection