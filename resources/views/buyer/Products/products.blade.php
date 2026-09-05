@php
    $catalog = json_decode(file_get_contents(resource_path('data/buyer-mens-products.json')), true, 512, JSON_THROW_ON_ERROR);
    $taxonomy = json_decode(file_get_contents(resource_path('data/buyer-categories.json')), true, 512, JSON_THROW_ON_ERROR);
    $mensCategory = collect($taxonomy)->firstWhere('slug', 'men-s-apparel');
    $subcategories = $mensCategory['subcategories'];
@endphp
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>
            Men's Apparel | Bearly
        </title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
        @vite(['resources/css/buyer.css', 'resources/js/buyer.js'])
    </head>
    <body class="bc" style="--catalog-image:url('{{ asset('images/mens-catalog-atlas.png') }}')">
        <a href="#bc-main" class="skip">
            Skip to products
        </a>
        <header class="bc-header">
            <a href="{{ route('home') }}" class="brand">
                <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly home" width="185" height="62">
            </a>
            <form id="bc-search-form" class="search" role="search">
                <span>
                    Men's Apparel
                </span>
                <label class="sr" for="bc-search">
                    Search Men's Apparel
                </label>
                <input id="bc-search" type="search" placeholder="Search Men's Apparel" maxlength="120">
                <button aria-label="Search">
                    <i class="mi" aria-hidden="true">
                        search
                    </i>
                </button>
            </form>
            <nav aria-label="Account">
                <button data-info="orders">
                    <i class="mi" aria-hidden="true">
                        receipt_long
                    </i>
                    Orders
                </button>
                <button data-info="chat">
                    <i class="mi" aria-hidden="true">
                        chat_bubble
                    </i>
                    Chat
                </button>
                <a href="{{ url('/cart') }}">
                    <i class="mi" aria-hidden="true">
                        shopping_cart
                    </i>
                    Cart
                </a>
                <a href="{{ url('/login') }}">
                    <i class="mi" aria-hidden="true">
                        person
                    </i>
                    Sign in
                </a>
            </nav>
        </header>
        <div class="bc-shell">
            <aside class="bc-sidebar" id="bc-sidebar" aria-label="Categories and filters">
                @include('buyer.Products.components.category-sidebar') @include('buyer.Products.components.product-filter')
            </aside>
            <main id="bc-main" tabindex="-1">
                <nav class="breadcrumb" aria-label="Breadcrumb">
                    <a href="{{ route('home') }}">
                        Home
                    </a>
                    <span>
                        /
                    </span>
                    <strong>
                        Men's Apparel
                    </strong>
                </nav>
                <h1>
                    Men’s Apparel
                </h1>
                <p class="subtitle">
                    Everyday staples. Your own style.
                </p>
                <p class="preview">
                    Concept catalog · Sample products, prices and ratings
                </p>
                @include('buyer.Products.components.product-toolbar')
                <div class="shortcuts" aria-label="Explore subcategories">
                    @foreach($subcategories as $i => $sub)
                    <button data-sub="{{ $sub }}">
                        <span class="photo" style="--x:{{ ([5,1,2,15,4,19][$i] % 5)*25 }}%;--y:{{ floor([5,1,2,15,4,19][$i]/5)*100/3 }}%" aria-hidden="true">
                        </span>
                        <span>
                            {{ $sub }}
                            <b aria-hidden="true">
                                ›
                            </b>
                        </span>
                    </button>
                    @endforeach
                </div>
                <div id="bc-chips" class="chips">
                </div>
                @include('buyer.Products.components.product-grid')
                <section id="bc-empty" class="empty" hidden>
                    <i class="mi" aria-hidden="true">
                        search_off
                    </i>
                    <h2>
                        No matching finds
                    </h2>
                    <p>
                        Try removing a filter or searching for something else.
                    </p>
                    <button class="button" data-reset>
                        Clear all filters
                    </button>
                </section>
                <div class="load">
                    <p id="bc-status" role="status" aria-live="polite">
                    </p>
                    <button class="button outline" id="bc-more">
                        Load more products
                    </button>
                </div>
            </main>
        </div>
        <footer>
            <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly" width="110" height="37">
            <span>
                Good finds. Happy spaces.
            </span>
            <nav>
                <button data-info="help">
                    Help centre
                </button>
                <button data-info="about">
                    About Bearly
                </button>
            </nav>
            <small>
                Frontend preview
            </small>
        </footer>
        <button class="chat" data-info="chat">
            <i class="mi" aria-hidden="true">
                chat_bubble
            </i>
            Chat
        </button>
        <dialog id="bc-mobile-dialog" aria-labelledby="bc-mobile-title">
            <div class="mobile-heading">
                <div>
                    <h2 id="bc-mobile-title">
                        Filters
                    </h2>
                    <p>
                        Men's Apparel
                    </p>
                </div>
                <button data-close aria-label="Close filters">
                    <i class="mi" aria-hidden="true">
                        close
                    </i>
                </button>
            </div>
            <div id="bc-mobile-content">
            </div>
            <div class="mobile-actions">
                <button class="button outline" data-reset>
                    Reset
                </button>
                <button class="button gold" id="bc-show-results">
                    Show results
                </button>
            </div>
        </dialog>
        <dialog id="bc-product-dialog" aria-labelledby="bc-product-title">
            <button class="close" data-close aria-label="Close product">
                <i class="mi" aria-hidden="true">
                    close
                </i>
            </button>
            <div id="bc-product-detail">
            </div>
        </dialog>
        <dialog id="bc-info-dialog" aria-labelledby="bc-info-title">
            <button class="close" data-close aria-label="Close">
                <i class="mi" aria-hidden="true">
                    close
                </i>
            </button>
            <h2 id="bc-info-title">
            </h2>
            <p id="bc-info-copy">
            </p>
            <a class="button gold" href="{{ url('/login') }}">
                Sign in
            </a>
        </dialog>
        @include('buyer.Products.components.product-card')
        <script type="application/json" id="bc-data">
            {!! json_encode($catalog, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}
        </script>
    </body>
</html>
