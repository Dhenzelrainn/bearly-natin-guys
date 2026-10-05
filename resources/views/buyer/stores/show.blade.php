<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $store->name }} | Bearly</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
    @include('buyer.partials.account-context-script')
    @vite(['resources/css/buyer.css', 'resources/js/buyer.js'])
    <style>
        .store-page { min-height:100vh; background:#f7f3ed; }
        .store-shell { width:min(1180px, calc(100% - 32px)); margin:0 auto; padding:32px 0 72px; }
        .store-hero { overflow:hidden; border:1px solid #e4d8ca; border-radius:14px; background:#fffdfa; box-shadow:0 8px 24px rgba(74,48,30,.06); }
        .store-banner { min-height:220px; background:#d9c8b6 center/cover no-repeat; }
        .store-banner.is-empty { display:grid; place-items:center; color:#fff; background:linear-gradient(135deg,#5b3827,#9b7145); }
        .store-identity { display:flex; align-items:center; gap:18px; padding:22px 26px; }
        .store-avatar { display:grid; place-items:center; flex:0 0 78px; width:78px; height:78px; margin-top:-58px; border:5px solid #fffdfa; border-radius:50%; background:#5b3827; color:#fff; font-size:28px; font-weight:800; }
        .store-identity h1 { margin:0; color:#39251b; font-size:clamp(25px,4vw,40px); }
        .store-identity p { margin:6px 0 0; color:#75695d; }
        .store-products { margin-top:38px; }
        .store-products h2 { color:#39251b; }
        .store-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; }
        .store-product { overflow:hidden; border:1px solid #e4d8ca; border-radius:10px; background:#fffdfa; color:inherit; text-decoration:none; }
        .store-product img, .store-product .store-product-image { display:block; width:100%; aspect-ratio:1/1; object-fit:cover; background:#eee5da; }
        .store-product-copy { padding:14px; }
        .store-product-copy strong { display:block; color:#39251b; line-height:1.35; }
        .store-product-copy span { display:block; margin-top:7px; color:#6f4a2e; font-weight:800; }
        .store-empty { padding:38px 20px; border:1px dashed #cdbba9; border-radius:16px; color:#75695d; text-align:center; background:#fffdfa; }
        @media (max-width:800px) { .store-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .store-identity { align-items:flex-start; padding:18px; } }
        @media (max-width:520px) { .store-shell { width:min(100% - 20px,1180px); padding-top:20px; } .store-banner { min-height:150px; } .store-avatar { flex-basis:62px; width:62px; height:62px; margin-top:-44px; font-size:22px; } .store-identity h1 { font-size:25px; } .store-grid { gap:10px; } .store-product-copy { padding:10px; font-size:13px; } }
    </style>
</head>
<body class="bh store-page">
    <header class="header">
        <a class="brand" href="{{ route('home') }}"><img src="{{ asset('images/bearly-logo-2.png') }}" alt="Bearly"></a>
        <div class="header-actions">
            <a href="{{ route('cart.view') }}"><span class="material-symbols-outlined">shopping_cart</span><span>Cart</span></a>
            <a class="account-action" href="{{ route('buyer.profile') }}"><span class="material-symbols-outlined">person</span><span>{{ $buyerName ?? auth()->user()->name }}</span></a>
        </div>
    </header>
    <main class="store-shell">
        <section class="store-hero">
            @if ($store->banner_path)
                <div class="store-banner" style="background-image:url('{{ asset('storage/'.$store->banner_path) }}')"></div>
            @else
                <div class="store-banner is-empty">Bearly seller storefront</div>
            @endif
            <div class="store-identity">
                <span class="store-avatar" aria-hidden="true">{{ strtoupper(substr($store->name, 0, 1)) }}</span>
                <div><h1>{{ $store->name }}</h1><p>{{ $store->description ?: 'Browse published products from this Bearly store.' }}</p></div>
            </div>
        </section>
        <section class="store-products" aria-labelledby="store-products-title">
            <h2 id="store-products-title">Published products</h2>
            @if (count($products))
                <div class="store-grid">
                    @foreach ($products as $product)
                        <a class="store-product" href="{{ route('products.show', $product['id']) }}">
                            @if ($product['image'])<img src="{{ $product['image'] }}" alt="{{ $product['name'] }}">@else<div class="store-product-image"></div>@endif
                            <div class="store-product-copy"><strong>{{ $product['name'] }}</strong><span>₱{{ number_format($product['price'], 2) }}</span></div>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="store-empty">This store has no published products yet.</div>
            @endif
        </section>
    </main>
</body>
</html>
