@php
$categoryProductSources = [
    'pet-supplies'=>['file'=>'buyer-pet-supplies-products.json','name'=>'Pet Supplies'],
    'electronics-and-gadgets'=>['file'=>'buyer-electronics-gadgets-products.json','name'=>'Electronics and Gadgets'],
    'women-s-apparel'=>['file'=>'buyer-womens-products.json','name'=>"Women's Apparel"],
    'men-s-apparel'=>['file'=>'buyer-mens-products (1).json','name'=>"Men's Apparel"],
    'kids-and-baby'=>['file'=>'buyer-kids-baby-products.json','name'=>'Kids and Baby'],
    'home-and-garden'=>['file'=>'buyer-home-garden-products.json','name'=>'Home and Garden'],
    'sports-and-outdoors'=>['file'=>'buyer-sports-products.json','name'=>'Sports and Outdoors'],
    'health-and-beauty'=>['file'=>'buyer-health-beauty-products.json','name'=>'Health and Beauty'],
    'books-and-media'=>['file'=>'buyer-books-media-products.json','name'=>'Books and Media'],
    'jewelry-and-watches'=>['file'=>'buyer-jewelry-watches-products.json','name'=>'Jewelry and Watches'],
    'food-and-gourmet'=>['file'=>'buyer-foods-gourmet-products.json','name'=>'Foods and Gourmet'],
    'furniture-and-office-equipment'=>['file'=>'buyer-furniture-office-products.json','name'=>'Furniture and Office Equipment'],
];
$featuredProducts=[];
foreach($categoryProductSources as $slug=>$source){
    $items=json_decode(file_get_contents(resource_path('data/'.$source['file'])),true,512,JSON_THROW_ON_ERROR);
    foreach($items as $product){
        $product['featured_key']=$slug.':'.$product['id'];
        $product['category']=$source['name'];
        $product['category_slug']=$slug;
        $featuredProducts[]=$product;
    }
}
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Flash Deals | Bearly</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
@vite(['resources/css/buyer.css','resources/js/buyer.js'])
</head>
<body class="bh flash-page">
<header class="header">
    <a class="brand" href="{{ url('/home') }}" aria-label="Bearly home"><img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly" width="192" height="64"></a>

    <form class="search" id="search-form" role="search" action="{{ url('/home') }}" method="get">
        <label class="sr-only" for="search-category">Search category</label>
        <select id="search-category" name="category"><option value="">All categories</option></select>
        <label class="sr-only" for="search-input">Search products</label>
        <input id="search-input" name="q" type="search" placeholder="Search for anything on Bearly" maxlength="120" autocomplete="off">
        <button type="submit" aria-label="Search"><span class="material-symbols-outlined" aria-hidden="true">search</span></button>
    </form>

    <nav class="header-actions" aria-label="Account">
        <a href="{{ url('/profile#notifications') }}" class="notification-header-link"><span class="material-symbols-outlined" aria-hidden="true">notifications</span><span>Notifications</span><span class="notification-badge" data-notification-badge>3</span></a>
        <a href="{{ url('/profile#purchases') }}"><span class="material-symbols-outlined" aria-hidden="true">receipt_long</span><span>Orders</span></a>
        <button type="button" data-info="chat"><span class="material-symbols-outlined" aria-hidden="true">chat_bubble</span><span>Chat</span></button>
        <a href="{{ url('/cart') }}"><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span><span>Cart</span></a>
        <a class="account-action" href="{{ url('/profile') }}" aria-label="Open Mia Santos profile"><span class="material-symbols-outlined" aria-hidden="true">person</span><span>Mia Santos</span></a>
    </nav>
</header>
<main class="flash-page-main">
    <a class="flash-back" href="{{ url('/home') }}"><span class="material-symbols-outlined">arrow_back</span> Back to Home</a>
    <section class="flash-hero">
        <div><p class="flash-kicker"><span class="material-symbols-outlined">bolt</span> Bearly Flash Deals</p><h1>Catch it before the clock runs out.</h1><p>Selected Bearly finds at limited-time demo prices. Sale prices stay with the item when you add it to your cart.</p></div>
        <div><small>Deals refresh in</small><div class="flash-countdown flash-countdown-large" data-flash-countdown><span data-hours>00</span><b>:</b><span data-minutes>00</span><b>:</b><span data-seconds>00</span></div></div>
    </section>
    <div class="flash-page-toolbar"><div><h2>Today's deals</h2><p>Demo sale · fixed availability for the buyer prototype</p></div><span class="flash-live"><i></i> LIVE</span></div>
    <section class="flash-deal-grid flash-deal-grid-full" aria-label="Flash deal products">
@php
$deals = [
 ['flash-earbuds','electronics-and-gadgets:7','Wireless Earbuds',599,799,'electronics/electronics-07-01.jpg','Electronics & Gadgets',25,72],
 ['flash-cleanser','health-and-beauty:1','Gentle Facial Cleanser',349,499,'health-beauty/health-beauty-01-01.jpg','Health & Beauty',30,64],
 ['flash-tee','mens-apparel:1','Everyday Basic Tee',329,449,'men/mens-01-01.jpg',"Men's Apparel",27,81],
 ['flash-watch','jewelry-and-watches:21',"Men's Silver Classic Watch",999,1499,'jewelry-watches/jewelry-watches-21-01.jpg','Jewelry & Watches',33,58],
 ['flash-chair','furniture-and-office-equipment:8','Ergonomic Office Chair',4299,5499,'furniture-office/furniture-office-08-01.jpg','Furniture & Office Equipment',22,47],
 ['flash-lamp','furniture-and-office-equipment:16','Adjustable Desk Lamp',649,899,'furniture-office/furniture-office-16-01.jpg','Furniture & Office Equipment',28,69],
 ['flash-bracelet','jewelry-and-watches:13','Crystal Tennis Bracelet',799,1099,'jewelry-watches/jewelry-watches-13-01.jpg','Jewelry & Watches',27,55],
 ['flash-monitor','furniture-and-office-equipment:25','24-Inch Office Monitor',6999,8999,'furniture-office/furniture-office-25-01.jpg','Furniture & Office Equipment',22,76],
];
@endphp
@foreach($deals as $d)
        <article class="flash-deal-card" data-flash-product data-key="{{ $d[0] }}" data-product-id="{{ $d[1] }}" data-name="{{ $d[2] }}" data-price="{{ $d[3] }}" data-original-price="{{ $d[4] }}" data-image="{{ asset('images/products/'.$d[5]) }}" data-flash-category="{{ $d[6] }}" onclick="if (!event.target.closest('[data-top-like], [data-top-add], [data-flash-add]')) window.bearlyOpenFeaturedCard?.(this, 'flash')">
            <button type="button" class="flash-image" data-feature-open><img src="{{ asset('images/products/'.$d[5]) }}" alt="{{ $d[2] }}"><span>{{ $d[7] }}% OFF</span></button>
            <div class="flash-copy"><small>{{ $d[6] }}</small><h3>{{ $d[2] }}</h3><div class="flash-prices"><strong>₱{{ number_format($d[3]) }}</strong><del>₱{{ number_format($d[4]) }}</del></div><div class="flash-stock"><span style="--sold:{{ $d[8] }}%"></span><small>{{ $d[8] }}% claimed</small></div><button type="button" class="flash-add" data-flash-add>Add to Cart</button></div>
        </article>
@endforeach
    </section>
</main>
<footer class="footer"><a href="{{ url('/home') }}"><img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly home" width="110" height="37"></a><p>Good finds. Happy spaces.</p><span>Flash Deals preview</span></footer>
<div class="flash-toast" data-flash-toast role="status" aria-live="polite"></div>
<dialog id="product-dialog" aria-labelledby="product-title"><button class="dialog-close icon-button" data-close aria-label="Close product details"><span class="material-symbols-outlined">close</span></button><div id="product-detail"></div></dialog><dialog id="info-dialog" aria-labelledby="info-title"><button class="dialog-close icon-button" data-close aria-label="Close"><span class="material-symbols-outlined">close</span></button><h2 id="info-title"></h2><div id="info-copy"></div><div class="info-actions" id="info-actions"></div></dialog><script id="featured-product-data" type="application/json">{!! json_encode($featuredProducts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
</body>
</html>
