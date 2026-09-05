@php
    /*
    |--------------------------------------------------------------------------
    | FRONT-END SAMPLE DATA ONLY
    |--------------------------------------------------------------------------
    | No database changes are required for this page.
    | Replace these sample listings later when the backend product fields
    | for size, color, condition, etc. are ready.
    */
    $mensProducts = [
        [
            'id' => 1,
            'name' => 'Everyday Cotton Tee',
            'price' => 349,
            'condition' => 'New with tags',
            'condition_slug' => 'new-with-tags',
            'subcategory' => 'Casual Shirts & Pants',
            'subcategory_slug' => 'casual-shirts-pants',
            'sizes' => ['S', 'M', 'L', 'XL'],
            'colors' => ['blue'],
            'free_shipping' => true,
            'image' => 'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?auto=format&fit=crop&w=700&q=85',
        ],
        [
            'id' => 2,
            'name' => 'Oxford Long-Sleeve Shirt',
            'price' => 799,
            'condition' => 'New with tags',
            'condition_slug' => 'new-with-tags',
            'subcategory' => 'Casual Shirts & Pants',
            'subcategory_slug' => 'casual-shirts-pants',
            'sizes' => ['M', 'L', 'XL', 'XXL'],
            'colors' => ['light-blue', 'white'],
            'free_shipping' => false,
            'image' => 'https://images.unsplash.com/photo-1602810318383-e386cc2a3ccf?auto=format&fit=crop&w=700&q=85',
        ],
        [
            'id' => 3,
            'name' => 'Relaxed Denim Jacket',
            'price' => 1290,
            'condition' => 'Pre-owned',
            'condition_slug' => 'pre-owned',
            'subcategory' => 'Outerwear & Jackets',
            'subcategory_slug' => 'outerwear-jackets',
            'sizes' => ['M', 'L', 'XL'],
            'colors' => ['blue'],
            'free_shipping' => true,
            'image' => 'https://images.unsplash.com/photo-1551537482-f2075a1d41f2?auto=format&fit=crop&w=700&q=85',
        ],
        [
            'id' => 4,
            'name' => 'Essential Pullover Hoodie',
            'price' => 899,
            'condition' => 'New with tags',
            'condition_slug' => 'new-with-tags',
            'subcategory' => 'Outerwear & Jackets',
            'subcategory_slug' => 'outerwear-jackets',
            'sizes' => ['S', 'M', 'L', 'XL', 'XXL'],
            'colors' => ['blue', 'black'],
            'free_shipping' => true,
            'image' => 'https://images.unsplash.com/photo-1556821840-3a63f95609a7?auto=format&fit=crop&w=700&q=85',
        ],
        [
            'id' => 5,
            'name' => 'Straight-Leg Denim Jeans',
            'price' => 999,
            'condition' => 'New with tags',
            'condition_slug' => 'new-with-tags',
            'subcategory' => 'Casual Shirts & Pants',
            'subcategory_slug' => 'casual-shirts-pants',
            'sizes' => ['S', 'M', 'L', 'XL'],
            'colors' => ['blue'],
            'free_shipping' => false,
            'image' => 'https://images.unsplash.com/photo-1542272604-787c3835535d?auto=format&fit=crop&w=700&q=85',
        ],
        [
            'id' => 6,
            'name' => 'Classic Piqué Polo',
            'price' => 549,
            'condition' => 'New with tags',
            'condition_slug' => 'new-with-tags',
            'subcategory' => 'Casual Shirts & Pants',
            'subcategory_slug' => 'casual-shirts-pants',
            'sizes' => ['S', 'M', 'L', 'XL'],
            'colors' => ['blue', 'black'],
            'free_shipping' => true,
            'image' => 'https://images.unsplash.com/photo-1625910513413-5fc45b8572b7?auto=format&fit=crop&w=700&q=85',
        ],
        [
            'id' => 7,
            'name' => 'Quick-Dry Training Tee',
            'price' => 449,
            'condition' => 'New with tags',
            'condition_slug' => 'new-with-tags',
            'subcategory' => 'Activewear & Fitness Gear',
            'subcategory_slug' => 'activewear-fitness',
            'sizes' => ['S', 'M', 'L', 'XL'],
            'colors' => ['blue', 'black'],
            'free_shipping' => true,
            'image' => 'https://images.unsplash.com/photo-1583743814966-8936f37f4678?auto=format&fit=crop&w=700&q=85',
        ],
        [
            'id' => 8,
            'name' => 'Everyday Chino Shorts',
            'price' => 599,
            'condition' => 'New with tags',
            'condition_slug' => 'new-with-tags',
            'subcategory' => 'Casual Shirts & Pants',
            'subcategory_slug' => 'casual-shirts-pants',
            'sizes' => ['S', 'M', 'L', 'XL', 'XXL'],
            'colors' => ['navy', 'beige'],
            'free_shipping' => false,
            'image' => 'https://images.unsplash.com/photo-1591195853828-11db59a44f6b?auto=format&fit=crop&w=700&q=85',
        ],
        [
            'id' => 9,
            'name' => 'Navy Tailored Blazer',
            'price' => 1899,
            'condition' => 'Pre-owned',
            'condition_slug' => 'pre-owned',
            'subcategory' => 'Suits & Blazers',
            'subcategory_slug' => 'suits-blazers',
            'sizes' => ['M', 'L', 'XL'],
            'colors' => ['navy'],
            'free_shipping' => true,
            'image' => 'https://images.unsplash.com/photo-1507679799987-c73779587ccf?auto=format&fit=crop&w=700&q=85',
        ],
        [
            'id' => 10,
            'name' => 'Linen Button-Down Shirt',
            'price' => 749,
            'condition' => 'New with tags',
            'condition_slug' => 'new-with-tags',
            'subcategory' => 'Casual Shirts & Pants',
            'subcategory_slug' => 'casual-shirts-pants',
            'sizes' => ['S', 'M', 'L', 'XL'],
            'colors' => ['light-blue', 'white'],
            'free_shipping' => true,
            'image' => 'https://images.unsplash.com/photo-1598033129183-c4f50c736f10?auto=format&fit=crop&w=700&q=85',
        ],
        [
            'id' => 11,
            'name' => 'Lightweight Windbreaker',
            'price' => 1099,
            'condition' => 'New with tags',
            'condition_slug' => 'new-with-tags',
            'subcategory' => 'Outerwear & Jackets',
            'subcategory_slug' => 'outerwear-jackets',
            'sizes' => ['M', 'L', 'XL'],
            'colors' => ['blue'],
            'free_shipping' => true,
            'image' => 'https://images.unsplash.com/photo-1551488831-00ddcb6c6bd3?auto=format&fit=crop&w=700&q=85',
        ],
        [
            'id' => 12,
            'name' => 'Tapered Jogger Pants',
            'price' => 699,
            'condition' => 'New with tags',
            'condition_slug' => 'new-with-tags',
            'subcategory' => 'Activewear & Fitness Gear',
            'subcategory_slug' => 'activewear-fitness',
            'sizes' => ['S', 'M', 'L', 'XL'],
            'colors' => ['navy', 'black'],
            'free_shipping' => false,
            'image' => 'https://images.unsplash.com/photo-1552902865-b72c031ac5ea?auto=format&fit=crop&w=700&q=85',
        ],
        [
            'id' => 13,
            'name' => 'Casual Canvas Sneakers',
            'price' => 899,
            'condition' => 'New with tags',
            'condition_slug' => 'new-with-tags',
            'subcategory' => 'Shoes & Accessories',
            'subcategory_slug' => 'shoes-accessories',
            'sizes' => ['S', 'M', 'L'],
            'colors' => ['blue', 'white'],
            'free_shipping' => true,
            'image' => 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=700&q=85',
        ],
        [
            'id' => 14,
            'name' => 'Everyday Baseball Cap',
            'price' => 299,
            'condition' => 'New with tags',
            'condition_slug' => 'new-with-tags',
            'subcategory' => 'Shoes & Accessories',
            'subcategory_slug' => 'shoes-accessories',
            'sizes' => ['M', 'L'],
            'colors' => ['blue', 'black'],
            'free_shipping' => false,
            'image' => 'https://images.unsplash.com/photo-1588850561407-ed78c282e89b?auto=format&fit=crop&w=700&q=85',
        ],
        [
            'id' => 15,
            'name' => 'Athletic Training Shorts',
            'price' => 449,
            'condition' => 'Pre-owned',
            'condition_slug' => 'pre-owned',
            'subcategory' => 'Activewear & Fitness Gear',
            'subcategory_slug' => 'activewear-fitness',
            'sizes' => ['S', 'M', 'L', 'XL'],
            'colors' => ['blue', 'black'],
            'free_shipping' => true,
            'image' => 'https://images.unsplash.com/photo-1594633312681-425c7b97ccd1?auto=format&fit=crop&w=700&q=85',
        ],
        [
            'id' => 16,
            'name' => 'Zip-Up Track Jacket',
            'price' => 849,
            'condition' => 'New with tags',
            'condition_slug' => 'new-with-tags',
            'subcategory' => 'Activewear & Fitness Gear',
            'subcategory_slug' => 'activewear-fitness',
            'sizes' => ['S', 'M', 'L', 'XL'],
            'colors' => ['blue'],
            'free_shipping' => true,
            'image' => 'https://images.unsplash.com/photo-1591047139829-d91aecb6caea?auto=format&fit=crop&w=700&q=85',
        ],
    ];
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Browse Men's Apparel on Bearly.">
    <title>Men's Apparel | Bearly</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">

    @vite(['resources/css/buyer.css', 'resources/js/buyer.js'])
</head>

<body class="bh buyer-products-body">
    <header class="header bp-header">
        <a class="brand" href="{{ route('home') }}" aria-label="Bearly home">
            <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly" width="192" height="64">
        </a>

        <form class="search bp-global-search" id="bp-global-search" role="search">
            <label class="sr-only" for="bp-search-category">Search category</label>
            <select id="bp-search-category">
                <option>All categories</option>
                <option selected>Men's Apparel</option>
            </select>

            <label class="sr-only" for="bp-global-search-input">Search products</label>
            <input id="bp-global-search-input" type="search" placeholder="Search for anything on Bearly" autocomplete="off">

            <button type="submit" aria-label="Search">
                <span class="material-symbols-outlined" aria-hidden="true">search</span>
            </button>
        </form>

        <nav class="header-actions" aria-label="Account">
            <button type="button"><span class="material-symbols-outlined">receipt_long</span><span>Orders</span></button>
            <button type="button"><span class="material-symbols-outlined">chat_bubble</span><span>Chat</span></button>
            <a href="{{ route('cart.view') }}"><span class="material-symbols-outlined">shopping_cart</span><span>Cart</span></a>
            <a href="{{ route('login') }}"><span class="material-symbols-outlined">person</span><span>Sign in</span></a>
        </nav>

        <button class="bp-mobile-filter-button" id="bp-mobile-filter-button" type="button" aria-label="Open filters">
            <span class="material-symbols-outlined">tune</span>
        </button>
    </header>

    <div class="bp-layout buyer-products-page" id="buyer-products-page">
        <aside class="bp-sidebar" id="bp-sidebar">
            <button class="bp-sidebar-close" id="bp-sidebar-close" type="button" aria-label="Close filters">
                <span class="material-symbols-outlined">close</span>
            </button>

            @include('buyer.Products.components.category-sidebar')
            @include('buyer.Products.components.product-filter')
        </aside>

        <button class="bp-sidebar-backdrop" id="bp-sidebar-backdrop" type="button" aria-label="Close filters" hidden></button>

        <main class="bp-main">
            <nav class="bp-breadcrumbs" aria-label="Breadcrumb">
                <a href="{{ route('home') }}">Home</a>
                <span>/</span>
                <strong>Men's Apparel</strong>
            </nav>

            <div class="bp-heading">
                <div>
                    <h1>Men's Apparel</h1>
                    <p>Find your everyday fit.</p>
                    <span id="bp-heading-count">16 sample results</span>
                </div>
            </div>

            @include('buyer.Products.components.product-toolbar')

            @include('buyer.Products.components.product-grid', ['mensProducts' => $mensProducts])

            <div class="bp-empty-state" id="bp-empty-state" hidden>
                <span class="material-symbols-outlined">search_off</span>
                <h2>No matching products</h2>
                <p>Try changing or clearing your filters.</p>
                <button type="button" class="bp-outline-button" data-bp-clear-all>Clear all filters</button>
            </div>

            <div class="bp-load-area">
                <button class="bp-load-more" id="bp-load-more" type="button">Load more products</button>
                <p id="bp-result-status" aria-live="polite">Showing 16 sample listings</p>
            </div>
        </main>
    </div>

    <footer class="footer bp-footer">
        <a href="{{ route('home') }}">
            <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly home" width="110" height="37">
        </a>
        <p>Good finds. Happy spaces.</p>
        <nav aria-label="Footer">
            <button type="button">About Bearly</button>
            <button type="button">Help centre</button>
            <button type="button">Privacy</button>
        </nav>
        <span>Visual concept · Sample products, prices and filters.</span>
    </footer>

    <button class="chat-button" type="button">
        <span class="material-symbols-outlined">chat_bubble</span>
        Chat
    </button>
</body>
</html>
