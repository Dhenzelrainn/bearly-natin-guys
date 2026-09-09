@php
    $homeCategories = json_decode(file_get_contents(resource_path('data/categories.json')), true, 512, JSON_THROW_ON_ERROR);
    $sprite = fn ($index) => '--sprite-position:'.(($index % 4) * 100 / 3).'% '.(intdiv($index, 4) * 100 / 3).'%';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Discover unique finds from independent stores. Shopping made Bearly a hassle.">
    <title>Bearly — Shopping made Bearly a hassle.</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@300&display=block" rel="stylesheet">
    @vite([
        'resources/css/landing.css',
        'resources/css/bearly-category-explorer.css',
        'resources/js/landing.js',
        'resources/js/bearly-category-explorer.js',
    ])
</head>
<body
    class="bl"
    style="--bl-catalog: url('{{ asset('images/catalog-placeholders.png') }}');"
>
<a class="bl-skip" href="#main-content">Skip to content</a>

<header class="bl-header">
    <a class="bl-logo" href="{{ url('/') }}" aria-label="Bearly home">
        <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly">
    </a>
    <button class="bl-menu" type="button" aria-label="Open menu" aria-controls="primary-navigation" aria-expanded="false" data-menu-button>
        <span class="material-symbols-outlined" aria-hidden="true">menu</span>
    </button>
    <nav class="bl-nav" id="primary-navigation" aria-label="Main navigation" data-navigation>
        <a href="{{ url('/') }}" aria-current="page">Home</a>
        <a href="{{ url('/home') }}">Discover</a>
        <a href="{{ route('about') }}">About</a>
        <a href="{{ route('contact') }}">Contact</a>
    </nav>
    <div class="bl-actions">
        <button type="button" aria-label="Search" data-search-toggle><span class="material-symbols-outlined" aria-hidden="true">search</span></button>
        <a href="{{ url('/login') }}" aria-label="Sign in"><span class="material-symbols-outlined" aria-hidden="true">person</span></a>
        <a href="{{ url('/wishlist') }}" aria-label="Wishlist"><span class="material-symbols-outlined" aria-hidden="true">favorite</span></a>
        <a href="{{ url('/cart') }}" aria-label="Shopping cart"><span class="material-symbols-outlined" aria-hidden="true">shopping_bag</span></a>
    </div>
    <form class="bl-search" action="{{ url('/home') }}" role="search" hidden data-search-form>
        <label class="bl-sr-only" for="landing-search">Search products</label>
        <span class="material-symbols-outlined" aria-hidden="true">search</span>
        <input id="landing-search" name="search" type="search" maxlength="120" placeholder="Search products, brands, or stores...">
        <button type="button" aria-label="Close search" data-search-close><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
    </form>
</header>

<main id="main-content">
    <section class="bl-hero" aria-labelledby="hero-title">
        <img class="bl-hero-image" src="{{ asset('images/landing-hero-bg (2).png') }}" alt="" width="1870" height="841" fetchpriority="high">
        <div class="bl-hero-content">
            <h1 id="hero-title">BEARLY</h1>
            <a class="bl-button bl-button-solid" href="{{ url('/home') }}">Shop now</a>
        </div>
    </section>

    <section class="bl-statement" aria-labelledby="statement-title">
        <p class="bl-eyebrow">BEARLY MARKETPLACE</p>
        <h2 id="statement-title">Shopping made <span>Bearly</span> a hassle.</h2>
        <p>Shopping should be easy. Bearly stressful. Discover unique finds from independent stores all in one place—curated pieces, great deals, and effortless discovery.</p>
    </section>

    <section class="bl-editorial" aria-label="Featured collections">
        <article class="bl-collection bl-collection-fashion">
            <img class="bl-collection-image" src="{{ asset('images/collection-fashion.png') }}" alt="A coordinated flat lay of a hoodie, striped shirt, jeans, cap, and sneakers" width="769" height="511" loading="lazy">
            <div class="bl-collection-copy">
                <h2>Wear It<br>Your Way</h2>
                <a class="bl-button bl-button-outline" href="{{ url('/home') }}?category=men-s-apparel">Shop now</a>
            </div>
        </article>
        <article class="bl-collection bl-collection-tech">
            <img class="bl-collection-image" src="{{ asset('images/collection-tech.png') }}" alt="Everyday technology including a laptop, phone, headphones, speaker, and charger" width="769" height="511" loading="lazy">
            <div class="bl-collection-copy bl-copy-dark">
                <h2>Everyday<br>Essentials</h2>
                <a class="bl-button bl-button-outline bl-outline-dark" href="{{ url('/home') }}?category=electronics-and-gadgets">Shop now</a>
            </div>
        </article>
        <article class="bl-collection bl-collection-details">
            <img class="bl-collection-image" src="{{ asset('images/collection-accessories.png') }}" alt="Fragrances, watches, eyewear, and a cap arranged on a warm stone surface" width="1538" height="511" loading="lazy">
            <div class="bl-collection-copy bl-copy-dark">
                <h2>Details That<br>Define You</h2>
                <a class="bl-button bl-button-outline bl-outline-dark" href="{{ url('/home') }}?category=jewelry-and-watches">Shop now</a>
            </div>
        </article>
    </section>

    <section class="bl-categories" id="categories" aria-labelledby="category-title">
        <div class="bl-section-heading">
            <div>
                <p class="bl-eyebrow">EXPLORE BEARLY</p>
                <h2 id="category-title">Shop by Categories</h2>
            </div>
            <button
                type="button"
                class="bl-view-all-categories"
                data-bl-open-categories
                aria-haspopup="dialog"
                aria-controls="bl-category-explorer"
                aria-expanded="false"
            >
                View all categories
                <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
            </button>
        </div>
        <div class="bl-category-grid">
            @foreach ($homeCategories as $index => $category)
                <a class="bl-category-card" href="{{ $category['slug'] === 'men-s-apparel' ? url('/products') : url('/home').'?category='.$category['slug'] }}">
                    <span class="bl-category-image" style="{{ $sprite($index) }}" role="img" aria-label="{{ $category['name'] }}"></span>
                    <span>{{ str_replace(' and ', ' & ', $category['name']) }}</span>
                </a>
            @endforeach
        </div>
    </section>

    <section class="bl-signup" aria-labelledby="signup-title">
        <img src="{{ asset('images/signup.png') }}" alt="Camera, notebook, sunglasses, phone, and everyday accessories" loading="lazy">
        <div class="bl-signup-copy">
            <p class="bl-eyebrow">YOUR NEXT FIND STARTS HERE</p>
            <h2 id="signup-title">Make shopping<br>Bearly a hassle.</h2>
            <p>Create an account and start discovering great finds from independent stores.</p>
            <a class="bl-button bl-button-solid" href="{{ url('/register') }}">Create an account</a>
        </div>
    </section>

    <section class="bl-trust" aria-label="Why Bearly">
        <div><span class="material-symbols-outlined" aria-hidden="true">sell</span><span>Curated Finds</span></div>
        <div><span class="material-symbols-outlined" aria-hidden="true">verified_user</span><span>Trusted Sellers</span></div>
        <div><span class="material-symbols-outlined" aria-hidden="true">groups</span><span>Made for Everyone</span></div>
    </section>
</main>

<footer class="bl-footer">
    <div class="bl-footer-grid">
        <div class="bl-footer-about">
            <a class="bl-footer-logo" href="{{ url('/') }}"><img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly"></a>
            <p>A modern marketplace for the things you love. Good things find you here.</p>
        </div>
        <nav aria-label="Explore links"><h2>Explore</h2><a href="{{ url('/') }}">Home</a><a href="{{ url('/home') }}">Discover</a><a href="{{ route('about') }}">About</a><a href="{{ route('contact') }}">Contact</a></nav>
        <nav aria-label="Help links"><h2>Help</h2><a href="{{ route('contact') }}">FAQs</a><a href="{{ route('contact') }}">Shipping</a><a href="{{ route('contact') }}">Returns</a><a href="{{ route('contact') }}">Track Order</a></nav>
        <div class="bl-social"><h2>Follow Us</h2><div><a href="#" aria-label="Instagram">IG</a><a href="#" aria-label="Facebook">f</a><a href="#" aria-label="TikTok">♪</a><a href="#" aria-label="YouTube">▶</a></div></div>
    </div>
    <div class="bl-footer-bottom"><span>© {{ date('Y') }} BEARLY. All rights reserved.</span><span>Good People. Better Days.</span></div>
</footer>

<div class="bl-category-explorer" id="bl-category-explorer" hidden aria-hidden="true">
    <div class="bl-category-explorer-backdrop" data-bl-close-categories></div>

    <section
        class="bl-category-explorer-panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="bl-category-explorer-title"
        tabindex="-1"
    >
        <header class="bl-category-explorer-header">
            <a class="bl-category-explorer-brand" href="{{ url('/') }}" aria-label="Bearly home">
                <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly">
            </a>

            <div class="bl-category-explorer-heading">
                <p>BEARLY MARKETPLACE</p>
                <h2 id="bl-category-explorer-title">Shop by category</h2>
            </div>

            <button type="button" class="bl-category-explorer-close" data-bl-close-categories aria-label="Close categories">
                <span class="material-symbols-outlined" aria-hidden="true">close</span>
            </button>
        </header>

        <div class="bl-category-explorer-body">
            <form class="bl-category-explorer-search" role="search" onsubmit="return false">
                <label class="bl-sr-only" for="bl-category-search">Search categories</label>
                <input id="bl-category-search" type="search" placeholder="What are you looking for?" autocomplete="off" data-bl-category-search>
                <span class="material-symbols-outlined" aria-hidden="true">search</span>
            </form>

            <div class="bl-category-explorer-top-grid" aria-label="Main categories">
                @foreach ($homeCategories as $index => $category)
                    <a
                        class="bl-category-explorer-tile"
                        data-bl-category-tile
                        data-search="{{ strtolower($category['name'].' '.implode(' ', $category['subcategories'])) }}"
                        href="{{ $category['slug'] === 'men-s-apparel' ? url('/products') : url('/home').'?category='.$category['slug'] }}"
                    >
                        <span class="bl-category-image bl-category-explorer-image" style="{{ $sprite($index) }}" role="img" aria-label="{{ $category['name'] }}"></span>
                        <span>{{ str_replace(' and ', ' & ', $category['name']) }}</span>
                    </a>
                @endforeach
            </div>

            <div class="bl-category-explorer-divider"></div>

            <div class="bl-category-explorer-subhead">
                <h3>Explore subcategories</h3>
                <p>Find exactly what you need across Bearly's independent stores.</p>
            </div>

            <div class="bl-category-explorer-groups">
                @foreach ($homeCategories as $category)
                    <article
                        class="bl-category-explorer-group"
                        data-bl-category-group
                        data-search="{{ strtolower($category['name'].' '.implode(' ', $category['subcategories'])) }}"
                    >
                        <a class="bl-category-explorer-group-title" href="{{ $category['slug'] === 'men-s-apparel' ? url('/products') : url('/home').'?category='.$category['slug'] }}">
                            {{ $category['name'] }}
                            <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                        </a>

                        <ul>
                            @foreach ($category['subcategories'] as $subcategory)
                                <li data-search="{{ strtolower($category['name'].' '.$subcategory) }}">
                                    <a href="{{ url('/home').'?category='.$category['slug'].'&subcategory='.urlencode($subcategory) }}">{{ $subcategory }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </article>
                @endforeach
            </div>

            <p class="bl-category-explorer-empty" data-bl-category-empty hidden>No categories match your search.</p>
        </div>
    </section>
</div>
</body>
</html>
