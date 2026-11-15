<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="A Bearly product preview. Sign in as a Buyer to see the full product information.">
    <meta name="robots" content="noindex,follow">
    <meta name="theme-color" content="#442214">
    <title>{{ $product['name'] }} | Bearly</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@400&display=block" rel="stylesheet">
    @include('partials.theme-preload')
    @vite('resources/css/landing.css')
</head>
<body class="bl bearly-landing lp-preview-page">
    <a class="lp-skip" href="#lp-preview-main">Skip to product preview</a>
    <header class="lp-header lp-preview-header">
        <a class="lp-logo" href="{{ route('shop.home') }}" aria-label="Bearly home">
            @include('partials.theme-aware-logo', ['themeLogoAlt' => 'Bearly', 'themeLogoWidth' => 205, 'themeLogoHeight' => 64])
        </a>
        <nav class="lp-nav" aria-label="Main navigation">
            <a href="{{ route('shop.home') }}">Home</a>
            <a href="{{ url('/products') }}">Shop</a>
            <a href="{{ route('about') }}">About</a>
            <a href="{{ route('contact') }}">Contact</a>
        </nav>
        <div class="lp-actions">
            @include('partials.theme-toggle')
        </div>
    </header>

    <main class="lp-preview-main" id="lp-preview-main">
        <a class="lp-preview-back" href="{{ route('shop.home') }}#bl-featured">
            <span aria-hidden="true">←</span> Back to featured products
        </a>

        <article class="lp-preview-product" aria-labelledby="lp-preview-title">
            <div class="lp-preview-image">
                @if (filled($product['image']))
                    <img src="{{ $product['image'] }}" alt="{{ $product['name'] }}" fetchpriority="high" width="720" height="720">
                @else
                    <p class="lp-preview-image-empty">Product image unavailable</p>
                @endif
            </div>

            <div class="lp-preview-copy">
                <p class="lp-eyebrow">PRODUCT PREVIEW</p>
                <h1 id="lp-preview-title">{{ $product['name'] }}</h1>
                <p class="lp-preview-price">₱{{ number_format($product['price_minor'] / 100, 2) }}</p>
                <p class="lp-preview-note">Sign in with a Buyer account to view the description, options, availability, and seller information.</p>

                @guest
                    <a class="lp-button" href="{{ route('products.show', ['product' => $product['id']]) }}">
                        Sign in to view full details <span aria-hidden="true">↗</span>
                    </a>
                @else
                    <p class="lp-preview-account-note">Full product details are available to active Buyer accounts.</p>
                @endguest

                <a class="lp-text-link" href="{{ route('shop.home') }}#bl-featured">Explore more products</a>
            </div>
        </article>
    </main>

    <footer class="lp-preview-footer">
        <a href="{{ route('privacy') }}">Privacy Policy</a>
        <a href="{{ route('terms') }}">Terms of Service</a>
        <span>© {{ date('Y') }} Bearly</span>
    </footer>

</body>
</html>
