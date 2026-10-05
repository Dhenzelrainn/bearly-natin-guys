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
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Top Products | Bearly</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
@vite(['resources/css/buyer.css','resources/js/buyer.js'])
@include('buyer.partials.account-context-script')</head>
<body class="bh top-products-page"><header class="header">
    <a class="brand" href="{{ url('/home') }}" aria-label="Bearly home"><img src="{{ asset('images/bearly-logo-2.png') }}" alt="Bearly" width="192" height="64"></a>

    <form class="search" id="search-form" role="search" action="{{ url('/home') }}" method="get">
        <label class="sr-only" for="search-category">Search category</label>
         <select id="search-category" name="category"><option value="">All categories</option>@foreach($categoryProductSources as $slug => $source)<option value="{{ $slug }}">{{ $source['name'] }}</option>@endforeach</select>
        <label class="sr-only" for="search-input">Search products</label>
         <input id="search-input" name="search" type="search" placeholder="Search for anything on Bearly" maxlength="120" autocomplete="off">
        <button type="submit" aria-label="Search"><span class="material-symbols-outlined" aria-hidden="true">search</span></button>
    </form>

    <nav class="header-actions" aria-label="Account">
        <a href="{{ url('/profile#notifications') }}" class="notification-header-link"><span class="material-symbols-outlined" aria-hidden="true">notifications</span><span>Notifications</span><span class="notification-badge" data-notification-badge>0</span></a>
        <a href="{{ url('/profile#tracking') }}"><span class="material-symbols-outlined" aria-hidden="true">receipt_long</span><span>Orders</span></a>
        <a href="{{ url('/chat') }}"><span class="material-symbols-outlined" aria-hidden="true">chat_bubble</span><span>Chat</span></a>
        <a href="{{ url('/cart') }}"><span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span><span>Cart</span></a>
        <a class="account-action" href="{{ url('/profile') }}" aria-label="Open {{ $buyerName }} profile"><span class="material-symbols-outlined" aria-hidden="true">person</span><span>{{ $buyerName }}</span></a>
    </nav>
</header>
<main class="top-products-main">
<a class="flash-back" href="{{ url('/home') }}"><span class="material-symbols-outlined">arrow_back</span> Back to Home</a>
<section class="top-products-hero"><div><p class="top-products-kicker"><span class="material-symbols-outlined">workspace_premium</span> Bearly favorites</p><h1>Top Products</h1><p>Popular picks from across Bearly's existing catalog, ranked using demo ratings and buyer activity for this frontend prototype.</p></div><div class="top-products-trophy"><span class="material-symbols-outlined">emoji_events</span><strong>18</strong><small>popular picks</small></div></section>
<section class="top-products-toolbar"><div><h2>Popular across categories</h2><p>Browse the current demo ranking.</p></div><div class="top-filter-row" aria-label="Top product filters"><button class="is-active" data-top-filter="All">All</button><button data-top-filter="Fashion">Fashion</button><button data-top-filter="Electronics & Gadgets">Electronics</button><button data-top-filter="Health & Beauty">Beauty</button><button data-top-filter="Home & Garden">Home</button></div></section>
<section class="top-products-grid" data-top-grid><article class="top-product-card top-rank-podium" data-top-product data-key="top-electronics-and-gadgets-5" data-product-id="electronics-and-gadgets:5" data-name="Mouse" data-price="1499" data-image="/images/products/electronics/electronics-05-01.jpg" data-top-category="Electronics & Gadgets">
    <div class="top-product-image"><img src="{{ asset('images/products/electronics/electronics-05-01.jpg') }}" alt="Mouse"><span class="top-rank">#1</span><button type="button" class="top-like" data-top-like aria-label="Save Mouse"><span class="material-symbols-outlined">favorite</span></button></div>
    <div class="top-product-copy"><small>Electronics & Gadgets</small><h3>Mouse</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>2.8k sold</span></div><div class="top-product-bottom"><strong>₱1,499</strong></div></div>
</article>
<article class="top-product-card top-rank-podium" data-top-product data-key="top-mens-apparel-4" data-product-id="mens-apparel:4" data-name="Classic Piqué Polo" data-price="549" data-image="/images/products/men/mens-04-01.jpg" data-top-category="Men's Apparel">
    <div class="top-product-image"><img src="{{ asset('images/products/men/mens-04-01.jpg') }}" alt="Classic Piqué Polo"><span class="top-rank">#2</span><button type="button" class="top-like" data-top-like aria-label="Save Classic Piqué Polo"><span class="material-symbols-outlined">favorite</span></button></div>
    <div class="top-product-copy"><small>Men's Apparel</small><h3>Classic Piqué Polo</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>2.4k sold</span></div><div class="top-product-bottom"><strong>₱549</strong></div></div>
</article>
<article class="top-product-card top-rank-podium" data-top-product data-key="top-health-and-beauty-5" data-product-id="health-and-beauty:5" data-name="Sunscreen SPF 50+" data-price="499" data-image="/images/products/health-beauty/health-beauty-05-01.jpg" data-top-category="Health & Beauty">
    <div class="top-product-image"><img src="{{ asset('images/products/health-beauty/health-beauty-05-01.jpg') }}" alt="Sunscreen SPF 50+"><span class="top-rank">#3</span><button type="button" class="top-like" data-top-like aria-label="Save Sunscreen SPF 50+"><span class="material-symbols-outlined">favorite</span></button></div>
    <div class="top-product-copy"><small>Health & Beauty</small><h3>Sunscreen SPF 50+</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>2.1k sold</span></div><div class="top-product-bottom"><strong>₱499</strong></div></div>
</article>
<article class="top-product-card" data-top-product data-key="top-home-and-garden-1" data-product-id="home-and-garden:1" data-name="Decorative Indoor Plant" data-price="599" data-image="/images/products/home-garden/home-garden-01-01.jpg" data-top-category="Home & Garden">
    <div class="top-product-image"><img src="{{ asset('images/products/home-garden/home-garden-01-01.jpg') }}" alt="Decorative Indoor Plant"><span class="top-rank">#4</span><button type="button" class="top-like" data-top-like aria-label="Save Decorative Indoor Plant"><span class="material-symbols-outlined">favorite</span></button></div>
    <div class="top-product-copy"><small>Home & Garden</small><h3>Decorative Indoor Plant</h3><div class="top-product-meta"><span><b>★ 4.8</b></span><span>1.9k sold</span></div><div class="top-product-bottom"><strong>₱599</strong></div></div>
</article>
<article class="top-product-card" data-top-product data-key="top-womens-apparel-6" data-product-id="womens-apparel:6" data-name="Classic White Blouse" data-price="499" data-image="/images/products/women/womens-06-01.jpg" data-top-category="Women's Apparel">
    <div class="top-product-image"><img src="{{ asset('images/products/women/womens-06-01.jpg') }}" alt="Classic White Blouse"><span class="top-rank">#5</span><button type="button" class="top-like" data-top-like aria-label="Save Classic White Blouse"><span class="material-symbols-outlined">favorite</span></button></div>
    <div class="top-product-copy"><small>Women's Apparel</small><h3>Classic White Blouse</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>1.8k sold</span></div><div class="top-product-bottom"><strong>₱499</strong></div></div>
</article>
<article class="top-product-card" data-top-product data-key="top-sports-and-outdoors-11" data-product-id="sports-and-outdoors:11" data-name="Resistance Band Set" data-price="499" data-image="/images/products/sports-outdoors/sports-outdoors-11-01.jpg" data-top-category="Sports & Outdoors">
    <div class="top-product-image"><img src="{{ asset('images/products/sports-outdoors/sports-outdoors-11-01.jpg') }}" alt="Resistance Band Set"><span class="top-rank">#6</span><button type="button" class="top-like" data-top-like aria-label="Save Resistance Band Set"><span class="material-symbols-outlined">favorite</span></button></div>
    <div class="top-product-copy"><small>Sports & Outdoors</small><h3>Resistance Band Set</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>1.7k sold</span></div><div class="top-product-bottom"><strong>₱499</strong></div></div>
</article>
<article class="top-product-card" data-top-product data-key="top-jewelry-and-watches-5" data-product-id="jewelry-and-watches:5" data-name="Pink Heart Pendant Necklace" data-price="849" data-image="/images/products/jewelry-watches/jewelry-watches-05-01.jpg" data-top-category="Jewelry & Watches">
    <div class="top-product-image"><img src="{{ asset('images/products/jewelry-watches/jewelry-watches-05-01.jpg') }}" alt="Pink Heart Pendant Necklace"><span class="top-rank">#7</span><button type="button" class="top-like" data-top-like aria-label="Save Pink Heart Pendant Necklace"><span class="material-symbols-outlined">favorite</span></button></div>
    <div class="top-product-copy"><small>Jewelry & Watches</small><h3>Pink Heart Pendant Necklace</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>1.6k sold</span></div><div class="top-product-bottom"><strong>₱849</strong></div></div>
</article>
<article class="top-product-card" data-top-product data-key="top-kids-and-baby-2" data-product-id="kids-and-baby:2" data-name="Floral Baby Dress" data-price="499" data-image="/images/products/kids-baby/kids-baby-02-01.jpg" data-top-category="Kids & Baby">
    <div class="top-product-image"><img src="{{ asset('images/products/kids-baby/kids-baby-02-01.jpg') }}" alt="Floral Baby Dress"><span class="top-rank">#8</span><button type="button" class="top-like" data-top-like aria-label="Save Floral Baby Dress"><span class="material-symbols-outlined">favorite</span></button></div>
    <div class="top-product-copy"><small>Kids & Baby</small><h3>Floral Baby Dress</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>1.5k sold</span></div><div class="top-product-bottom"><strong>₱499</strong></div></div>
</article>
<article class="top-product-card" data-top-product data-key="top-pet-supplies-6" data-product-id="pet-supplies:6" data-name="Pet Bed" data-price="449" data-image="/images/products/pet/pet-06-01.jpg" data-top-category="Pet Supplies">
    <div class="top-product-image"><img src="{{ asset('images/products/pet/pet-06-01.jpg') }}" alt="Pet Bed"><span class="top-rank">#9</span><button type="button" class="top-like" data-top-like aria-label="Save Pet Bed"><span class="material-symbols-outlined">favorite</span></button></div>
    <div class="top-product-copy"><small>Pet Supplies</small><h3>Pet Bed</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>1.4k sold</span></div><div class="top-product-bottom"><strong>₱449</strong></div></div>
</article>
<article class="top-product-card" data-top-product data-key="top-books-and-media-5" data-product-id="books-and-media:5" data-name="The Ember Crown — Fantasy Novel" data-price="599" data-image="/images/products/books-media/books-media-05-01.jpg" data-top-category="Books & Media">
    <div class="top-product-image"><img src="{{ asset('images/products/books-media/books-media-05-01.jpg') }}" alt="The Ember Crown — Fantasy Novel"><span class="top-rank">#10</span><button type="button" class="top-like" data-top-like aria-label="Save The Ember Crown — Fantasy Novel"><span class="material-symbols-outlined">favorite</span></button></div>
    <div class="top-product-copy"><small>Books & Media</small><h3>The Ember Crown — Fantasy Novel</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>1.3k sold</span></div><div class="top-product-bottom"><strong>₱599</strong></div></div>
</article>
<article class="top-product-card" data-top-product data-key="top-foods-and-gourmet-5" data-product-id="foods-and-gourmet:5" data-name="Extra Virgin Olive Oil" data-price="599" data-image="/images/products/foods-gourmet/foods-gourmet-05-01.jpg" data-top-category="Foods & Gourmet">
    <div class="top-product-image"><img src="{{ asset('images/products/foods-gourmet/foods-gourmet-05-01.jpg') }}" alt="Extra Virgin Olive Oil"><span class="top-rank">#11</span><button type="button" class="top-like" data-top-like aria-label="Save Extra Virgin Olive Oil"><span class="material-symbols-outlined">favorite</span></button></div>
    <div class="top-product-copy"><small>Foods & Gourmet</small><h3>Extra Virgin Olive Oil</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>1.2k sold</span></div><div class="top-product-bottom"><strong>₱599</strong></div></div>
</article>
<article class="top-product-card" data-top-product data-key="top-furniture-and-office-equipment-4" data-product-id="furniture-and-office-equipment:4" data-name="Industrial Coffee Table" data-price="2499" data-image="/images/products/furniture-office/furniture-office-04-01.jpg" data-top-category="Furniture & Office Equipment">
    <div class="top-product-image"><img src="{{ asset('images/products/furniture-office/furniture-office-04-01.jpg') }}" alt="Industrial Coffee Table"><span class="top-rank">#12</span><button type="button" class="top-like" data-top-like aria-label="Save Industrial Coffee Table"><span class="material-symbols-outlined">favorite</span></button></div>
    <div class="top-product-copy"><small>Furniture & Office Equipment</small><h3>Industrial Coffee Table</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>1.1k sold</span></div><div class="top-product-bottom"><strong>₱2,499</strong></div></div>
</article>
<article class="top-product-card" data-top-product data-key="top-electronics-and-gadgets-7" data-product-id="electronics-and-gadgets:7" data-name="Wireless Earbuds" data-price="799" data-image="/images/products/electronics/electronics-07-01.jpg" data-top-category="Electronics & Gadgets">
    <div class="top-product-image"><img src="{{ asset('images/products/electronics/electronics-07-01.jpg') }}" alt="Wireless Earbuds"><span class="top-rank">#13</span><button type="button" class="top-like" data-top-like aria-label="Save Wireless Earbuds"><span class="material-symbols-outlined">favorite</span></button></div>
    <div class="top-product-copy"><small>Electronics & Gadgets</small><h3>Wireless Earbuds</h3><div class="top-product-meta"><span><b>★ 4.7</b></span><span>980 sold</span></div><div class="top-product-bottom"><strong>₱799</strong></div></div>
</article>
<article class="top-product-card" data-top-product data-key="top-sports-and-outdoors-18" data-product-id="sports-and-outdoors:18" data-name="Waterproof Camping Tent" data-price="2499" data-image="/images/products/sports-outdoors/sports-outdoors-18-01.jpg" data-top-category="Sports & Outdoors">
    <div class="top-product-image"><img src="{{ asset('images/products/sports-outdoors/sports-outdoors-18-01.jpg') }}" alt="Waterproof Camping Tent"><span class="top-rank">#14</span><button type="button" class="top-like" data-top-like aria-label="Save Waterproof Camping Tent"><span class="material-symbols-outlined">favorite</span></button></div>
    <div class="top-product-copy"><small>Sports & Outdoors</small><h3>Waterproof Camping Tent</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>910 sold</span></div><div class="top-product-bottom"><strong>₱2,499</strong></div></div>
</article>
<article class="top-product-card" data-top-product data-key="top-home-and-garden-18" data-product-id="home-and-garden:18" data-name="Soft Cotton Bed Sheet Set" data-price="1299" data-image="/images/products/home-garden/home-garden-18-01.jpg" data-top-category="Home & Garden">
    <div class="top-product-image"><img src="{{ asset('images/products/home-garden/home-garden-18-01.jpg') }}" alt="Soft Cotton Bed Sheet Set"><span class="top-rank">#15</span><button type="button" class="top-like" data-top-like aria-label="Save Soft Cotton Bed Sheet Set"><span class="material-symbols-outlined">favorite</span></button></div>
    <div class="top-product-copy"><small>Home & Garden</small><h3>Soft Cotton Bed Sheet Set</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>860 sold</span></div><div class="top-product-bottom"><strong>₱1,299</strong></div></div>
</article>
<article class="top-product-card" data-top-product data-key="top-womens-apparel-22" data-product-id="womens-apparel:22" data-name="Summer Dress" data-price="699" data-image="/images/products/women/womens-22-01.jpg" data-top-category="Women's Apparel">
    <div class="top-product-image"><img src="{{ asset('images/products/women/womens-22-01.jpg') }}" alt="Summer Dress"><span class="top-rank">#16</span><button type="button" class="top-like" data-top-like aria-label="Save Summer Dress"><span class="material-symbols-outlined">favorite</span></button></div>
    <div class="top-product-copy"><small>Women's Apparel</small><h3>Summer Dress</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>790 sold</span></div><div class="top-product-bottom"><strong>₱699</strong></div></div>
</article>
<article class="top-product-card" data-top-product data-key="top-kids-and-baby-16" data-product-id="kids-and-baby:16" data-name="Silicone Feeding Set" data-price="599" data-image="/images/products/kids-baby/kids-baby-16-01.jpg" data-top-category="Kids & Baby">
    <div class="top-product-image"><img src="{{ asset('images/products/kids-baby/kids-baby-16-01.jpg') }}" alt="Silicone Feeding Set"><span class="top-rank">#17</span><button type="button" class="top-like" data-top-like aria-label="Save Silicone Feeding Set"><span class="material-symbols-outlined">favorite</span></button></div>
    <div class="top-product-copy"><small>Kids & Baby</small><h3>Silicone Feeding Set</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>720 sold</span></div><div class="top-product-bottom"><strong>₱599</strong></div></div>
</article>
<article class="top-product-card" data-top-product data-key="top-jewelry-and-watches-19" data-product-id="jewelry-and-watches:19" data-name="Black Stone Signet Ring" data-price="899" data-image="/images/products/jewelry-watches/jewelry-watches-19-01.jpg" data-top-category="Jewelry & Watches">
    <div class="top-product-image"><img src="{{ asset('images/products/jewelry-watches/jewelry-watches-19-01.jpg') }}" alt="Black Stone Signet Ring"><span class="top-rank">#18</span><button type="button" class="top-like" data-top-like aria-label="Save Black Stone Signet Ring"><span class="material-symbols-outlined">favorite</span></button></div>
    <div class="top-product-copy"><small>Jewelry & Watches</small><h3>Black Stone Signet Ring</h3><div class="top-product-meta"><span><b>★ 4.9</b></span><span>680 sold</span></div><div class="top-product-bottom"><strong>₱899</strong></div></div>
</article></section>
<div class="top-products-empty" data-top-empty hidden><span class="material-symbols-outlined">search_off</span><h3>No products in this filter</h3></div>
</main><footer class="footer"><a href="{{ url('/home') }}"><img src="{{ asset('images/bearly-logo-2.png') }}" alt="Bearly home" width="110" height="37"></a><p>Good finds. Happy spaces.</p><span>Top Products preview</span></footer><div class="flash-toast" data-top-toast role="status" aria-live="polite"></div><dialog id="product-dialog" aria-labelledby="product-title"><button class="dialog-close icon-button" data-close aria-label="Close product details"><span class="material-symbols-outlined">close</span></button><div id="product-detail"></div></dialog><dialog id="info-dialog" aria-labelledby="info-title"><button class="dialog-close icon-button" data-close aria-label="Close"><span class="material-symbols-outlined">close</span></button><h2 id="info-title"></h2><div id="info-copy"></div><div class="info-actions" id="info-actions"></div></dialog><script id="featured-product-data" type="application/json">{!! json_encode($featuredProducts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
</body></html>
