<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $campaign['name'] }} — active Bearly campaign preview.">
    <meta name="robots" content="noindex,follow">
    <meta name="theme-color" content="#442214">
    <title>{{ $campaign['name'] }} | Bearly</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@400&display=block" rel="stylesheet">
    @include('partials.theme-preload')
    @vite('resources/css/landing.css')
</head>
<body class="bl bearly-landing lp-preview-page lp-campaign-preview-page">
    <a class="lp-skip" href="#lp-campaign-preview-main">Skip to campaign preview</a>
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

    <main class="lp-preview-main" id="lp-campaign-preview-main">
        <a class="lp-campaign-preview-back-link" href="{{ route('shop.home') }}">
            <span aria-hidden="true">←</span> Back to Bearly
        </a>

        <header class="lp-campaign-preview-hero">
            @if ($campaign['banner_url'])
                <img src="{{ $campaign['banner_url'] }}" alt="{{ $campaign['banner_title'] }}" fetchpriority="high">
            @endif
            <div class="lp-campaign-preview-hero-copy">
                <p class="lp-eyebrow">{{ $campaign['type_label'] }}</p>
                <h1>{{ $campaign['name'] }}</h1>
                <p class="lp-campaign-preview-period">{{ $campaign['period'] }}</p>
                @if ($campaign['description'] !== '')
                    <p class="lp-campaign-preview-description">{{ $campaign['description'] }}</p>
                @endif
            </div>
        </header>

        <section aria-labelledby="lp-campaign-products-title">
            <div class="lp-heading lp-campaign-product-heading">
                <div>
                    <p class="lp-eyebrow">CAMPAIGN PICKS</p>
                    <h2 id="lp-campaign-products-title">Participating products</h2>
                </div>
                <span>{{ count($products) }} {{ \Illuminate\Support\Str::plural('product', count($products)) }}</span>
            </div>

            @if ($products !== [])
                <div class="lp-campaign-product-grid">
                    @foreach ($products as $product)
                        <a class="lp-campaign-product-card" href="{{ $product['preview_url'] }}" aria-label="Preview {{ $product['name'] }}, priced at ₱{{ number_format($product['price_minor'] / 100, 2) }}">
                            <span class="lp-campaign-product-image">
                                @if ($product['image'] !== '')
                                    <img src="{{ $product['image'] }}" alt="{{ $product['name'] }}" loading="lazy" decoding="async">
                                @else
                                    <span class="lp-preview-image-empty">Product image unavailable</span>
                                @endif
                            </span>
                            <span class="lp-campaign-product-name">{{ $product['name'] }}</span>
                            <span class="lp-campaign-product-price">₱{{ number_format($product['price_minor'] / 100, 2) }}</span>
                        </a>
                    @endforeach
                </div>
            @else
                <p class="lp-campaign-preview-empty" role="status">There are no shoppable products in this campaign right now. Please check back soon.</p>
            @endif
        </section>

        <div class="lp-campaign-preview-actions">
            @guest
                <a class="lp-button" href="{{ route('campaigns.show', ['campaign' => $campaign['id']]) }}">Sign in as a Buyer to shop this campaign <span aria-hidden="true">↗</span></a>
            @else
                <p class="lp-campaign-preview-account-note">Shopping this campaign is available to active Buyer accounts.</p>
            @endguest
        </div>
    </main>

    <footer class="lp-preview-footer">
        <a href="{{ route('privacy') }}">Privacy Policy</a>
        <a href="{{ route('terms') }}">Terms of Service</a>
        <span>© {{ date('Y') }} Bearly</span>
    </footer>
</body>
</html>
