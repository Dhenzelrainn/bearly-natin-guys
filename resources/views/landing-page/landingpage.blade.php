<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Bearly - Shopping made Bearly a hassle.">
    <title>Bearly | Shopping made Bearly a hassle</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,300..500,0..1,0&display=swap" rel="stylesheet">
    @vite(['resources/css/landing.css', 'resources/js/landing.js'])
</head>
<body>
<a class="skip-link" href="#featured-products">Skip to products</a>
@php
    $categories = [
        ['name' => "Women's Apparel", 'slug' => 'womens-apparel', 'image' => 'womens-apparel.jpg', 'subs' => ['Dresses & Skirts','Tops & Blouses','Activewear & Yoga Pants','Lingerie & Sleepwear','Jackets & Coats','Shoes & Accessories']],
        ['name' => "Men's Apparel", 'slug' => 'mens-apparel', 'image' => 'mens-apparel.jpg', 'subs' => ['Suits & Blazers','Casual Shirts & Pants','Outerwear & Jackets','Activewear & Fitness Gear','Shoes & Accessories','Grooming Products']],
        ['name' => 'Electronics & Gadgets', 'slug' => 'electronics-gadgets', 'image' => 'electronics-gadgets.jpg', 'subs' => ['Mobile Phones & Accessories','Laptops, Desktops & Monitors','Audio & Video Equipment','Smart Home Devices','Cameras & Photography','Wearable Technology']],
        ['name' => 'Health & Beauty', 'slug' => 'health-beauty', 'image' => 'health-beauty.jpg', 'subs' => ['Skincare Products','Haircare Solutions','Makeup & Cosmetics','Personal Care Appliances',"Men's Grooming",'Health Supplements']],
        ['name' => 'Home & Garden', 'slug' => 'home-garden', 'image' => 'home-garden.jpg', 'subs' => ['Kitchen Appliances','Furniture & Decor','Gardening Tools','Outdoor Living','Home Improvement Tools','Bedding & Bath']],
        ['name' => 'Books & Media', 'slug' => 'books-media', 'image' => 'books-media.jpg', 'subs' => ['Fiction & Non-Fiction Books','Magazines & Periodicals','Music CDs & Vinyl Records','Movie DVDs & Blu-ray','Video Games & Consoles','Educational DVDs']],
        ['name' => 'Kids & Baby', 'slug' => 'kids-baby', 'image' => 'kids-baby.jpg', 'subs' => ['Baby Clothes & Accessories','Toys & Games','Educational Materials','Strollers & Gear','Nursery Furniture','Safety & Health']],
        ['name' => 'Pet Supplies', 'slug' => 'pet-supplies', 'image' => 'pet-supplies.jpg', 'subs' => ['Dog Food & Treats','Cat Litter & Accessories','Aquariums & Fish Supplies','Bird Feeders & Food','Pet Grooming Products','Pet Health & Wellness']],
        ['name' => 'Sports & Outdoors', 'slug' => 'sports-outdoors', 'image' => 'sports-outdoors.jpg', 'subs' => ['Fitness Equipment','Camping & Hiking Gear','Sports Apparel','Cycling & Bikes','Water Sports','Team Sports Equipment']],
        ['name' => 'Food & Gourmet', 'slug' => 'food-gourmet', 'image' => 'food-gourmet.jpg', 'subs' => ['Baking Supplies & Ingredients','Coffee, Tea & Beverages','Snacks & Candy','Specialty Foods & International Cuisine','Organic & Health Foods','Meal Kits & Prepped Foods']],
        ['name' => 'Furniture & Office', 'slug' => 'furniture-office', 'image' => 'furniture-office.jpg', 'subs' => ['Office Desks & Chairs','Storage Cabinets & Shelving','Conference & Meeting Furniture','Computer Tables & Workstations','Ergonomic Accessories','Office Lighting & Fixtures']],
        ['name' => 'Jewelry & Watches', 'slug' => 'jewelry-watches', 'image' => 'jewelry-watches.jpg', 'subs' => ['Necklaces & Pendants','Rings & Earrings','Bracelets & Bangles','Watches for Men & Women','Fashion Jewelry','Jewelry Storage & Care']],
    ];

    $extraCategories = [
        ['name' => 'Toys & Hobbies', 'icon' => 'toys', 'subs' => ['Building Sets & Construction','RC Vehicles & Drones','Action Figures & Collectibles','Arts & Crafts','Puzzles & Board Games','Outdoor Play Equipment']],
        ['name' => 'Stationery & Crafts', 'icon' => 'edit_note', 'subs' => ['Notebooks & Journals','Pens, Pencils & Markers','Art Supplies','Craft Kits & Materials','Office Supplies','Gift Wrapping & Packaging']],
        ['name' => 'Travel & Luggage', 'icon' => 'luggage', 'subs' => ['Suitcases & Luggage','Backpacks & Daypacks','Travel Accessories','Packing Organizers','Travel Pillows & Comfort','Passport Holders & Wallets']],
        ['name' => 'Video Games', 'icon' => 'sports_esports', 'subs' => ['PlayStation','Xbox','Nintendo Switch','PC Games','Gaming Accessories','Virtual Reality']],
        ['name' => 'Tools & Hardware', 'icon' => 'construction', 'subs' => ['Power Tools','Hand Tools','Hardware & Fasteners','Safety & Workwear','Home Improvement Supplies','Automotive Tools & Accessories']],
        ['name' => 'Party & Occasions', 'icon' => 'celebration', 'subs' => ['Party Supplies','Balloons & Decorations','Tableware & Serveware','Gifts & Gift Bags','Seasonal Decorations','Weddings & Special Events']],
    ];

    $products = [
        ['name' => 'Cotton T-Shirt', 'category' => 'Fashion', 'image' => 'cotton-tshirt.jpg', 'price' => '₱599', 'badge' => 'New'],
        ['name' => 'Aula F2058 Gaming Keyboard', 'category' => 'Tech', 'image' => 'aula-keyboard.jpg', 'price' => '₱1,899', 'badge' => 'Popular'],
        ['name' => 'Face Serum', 'category' => 'Beauty', 'image' => 'face-serum.jpg', 'price' => '₱499', 'badge' => 'New'],
        ['name' => 'Canvas Tote Bag', 'category' => 'Accessories', 'image' => 'canvas-tote.jpg', 'price' => '₱799', 'badge' => 'Bestseller'],
        ['name' => 'Prolink Wireless Mouse', 'category' => 'Tech', 'image' => 'wireless-mouse.jpg', 'price' => '₱649', 'badge' => null],
        ['name' => 'Everyday Sneakers', 'category' => 'Fashion', 'image' => 'everyday-sneakers.jpg', 'price' => '₱1,299', 'badge' => 'Bestseller'],
        ['name' => 'Celeste Floral Pillow Sham', 'category' => 'Home', 'image' => 'floral-pillow-sham.jpg', 'price' => '₱1,590', 'badge' => null],
        ['name' => 'Reading Journal', 'category' => 'Books', 'image' => 'reading-journal.jpg', 'price' => '₱349', 'badge' => 'New'],
        ['name' => 'Classic Watch', 'category' => 'Accessories', 'image' => 'classic-watch.jpg', 'price' => '₱1,499', 'badge' => 'Popular'],
        ['name' => 'Baseball Cap', 'category' => 'Fashion', 'image' => 'baseball-cap.jpg', 'price' => '₱459', 'badge' => null],
        ['name' => 'Art Dining Chair', 'category' => 'Home', 'image' => 'dining-chair.jpg', 'price' => '₱2,795', 'badge' => 'Bestseller'],
        ['name' => 'Travel Tumbler', 'category' => 'Outdoor', 'image' => 'travel-tumbler.jpg', 'price' => '₱699', 'badge' => 'New'],
    ];

    $collections = [
        ['eyebrow' => 'Tech edit', 'title' => 'For your everyday.', 'copy' => 'Useful tech for study, work, and everything between.', 'tone' => 'cool', 'background' => 'collection-everyday.jpg', 'images' => ['aula-keyboard.jpg','wireless-mouse.jpg','travel-tumbler.jpg']],
        ['eyebrow' => 'Style edit', 'title' => 'A fresh little upgrade.', 'copy' => 'Easy pieces that make everyday outfits feel more put together.', 'tone' => 'warm', 'background' => 'collection-style.jpg', 'images' => ['cotton-tshirt.jpg','baseball-cap.jpg','canvas-tote.jpg']],
        ['eyebrow' => 'Home edit', 'title' => 'Refresh your space.', 'copy' => 'Simple home pieces with a clean, calm look.', 'tone' => 'sand', 'background' => 'collection-home.jpg', 'images' => ['dining-chair.jpg','floral-pillow-sham.jpg','reading-journal.jpg']],
        ['eyebrow' => 'Self-care edit', 'title' => 'Your daily reset.', 'copy' => 'Small routines, thoughtful essentials, better days.', 'tone' => 'rose', 'background' => 'collection-self-care.jpg', 'images' => ['face-serum.jpg','travel-tumbler.jpg','floral-pillow-sham.jpg']],
        ['eyebrow' => 'Weekend edit', 'title' => 'Ready for the weekend.', 'copy' => 'Grab-and-go finds for errands, trips, and days outside.', 'tone' => 'sage', 'background' => 'collection-weekend.jpg', 'images' => ['canvas-tote.jpg','everyday-sneakers.jpg','travel-tumbler.jpg']],
        ['eyebrow' => 'Giftable edit', 'title' => 'Good food, good mood.', 'copy' => 'Easy-to-gift favorites for people you actually like.', 'tone' => 'cream', 'background' => 'collection-food.jpg', 'images' => ['classic-watch.jpg','reading-journal.jpg','face-serum.jpg']],
    ];
@endphp

<div class="announcement-bar">
    <div class="site-shell announcement-inner">
        <span><span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 4h12v13H2z M14 9h4l4 5v3h-8"/><circle cx="6" cy="18" r="2"/><circle cx="18" cy="18" r="2"/></svg></span> Big brands & independent sellers</span>
        <span class="announcement-middle">Thoughtful products. Independent sellers. One marketplace.</span>
        <span>A kinder way to shop. <span aria-hidden="true">♡</span></span>
    </div>
</div>

<header class="site-header" id="siteHeader">
    <div class="site-shell header-inner">
        <a class="brand" href="{{ url('/') }}" aria-label="Bearly home">
            <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly">
        </a>

        <nav class="main-nav" aria-label="Main navigation">
            <a class="active" href="#home">Home</a>
            <a href="#featured-products">Shop</a>
            <a href="#curated-collections">Collections</a>
            <a href="{{ url('/about') }}">About</a>
            <a href="{{ url('/contact') }}">Contact</a>
        </nav>

        <div class="header-actions">
            <label class="header-search" aria-label="Search products">
                <span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg></span>
                <input type="search" id="headerProductSearch" placeholder="Search for products, brands or stores...">
            </label>
            <a href="{{ url('/login') }}" class="icon-button" aria-label="Account"><span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="7" r="3.5"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg></span></a>
            <button class="icon-button" type="button" aria-label="Wishlist"><span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 4.8a5 5 0 0 0-7.1 0L12 6.2l-1.4-1.4a5 5 0 0 0-7.1 7.1L12 21l8.5-9.1a5 5 0 0 0 0-7.1Z"/></svg></span></button>
            <button class="icon-button cart-button" type="button" aria-label="Cart">
                <span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14l2 14H3L5 7Z M8 9V6a4 4 0 0 1 8 0v3"/></svg></span><span class="cart-count">0</span>
            </button>
            <button class="menu-button" id="menuButton" type="button" aria-label="Open menu"><span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg></span></button>
        </div>
    </div>
</header>

<main>
    <section class="hero" id="home" aria-label="Bearly highlights">
        <div class="hero-slider" id="heroSlider" aria-roledescription="carousel" aria-label="Bearly collections">
            <article class="hero-slide is-active" data-slide="0" role="group" aria-roledescription="slide" aria-label="1 of 3: Welcome to Bearly">
                <img class="hero-banner-image" src="{{ asset('images/landing-page/hero-banner.jpg') }}" alt="Bearly community member at a desk" fetchpriority="high" width="1024" height="290">
                <div class="site-shell hero-layout">
                    <div class="hero-copy reveal-up">
                        <span class="section-kicker">BEARLY MARKETPLACE</span>
                        <h1>Shopping made<br>Bearly a hassle.</h1>
                        <p>Discover big brands and independent sellers, all in one place. Thoughtful choices for a more comfortable, inspired you.</p>
                        <div class="hero-actions-row">
                            <a class="button button-primary" href="#featured-products">Shop now <span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h18m-6-6 6 6-6 6"/></svg></span></a>
                            <a class="button button-outline" href="#curated-collections">Explore collections</a>
                        </div>
                        <div class="hero-promise">
                            <img src="{{ asset('images/landing-page/bear-mascot.png') }}" alt="" aria-hidden="true">
                            <div><strong>Good Things Find You Here.</strong><span>Curated finds, real categories, easier browsing.</span></div>
                        </div>
                    </div>
                    <div class="hero-media reveal-up">
                        <div class="model-frame model-one">
                            <img src="{{ asset('images/landing-page/model-1.png') }}" alt="Bearly model wearing a white top" loading="eager">
                        </div>
                        <span class="hero-note">Real people.<br>Real finds.<br>Bearly stressful.</span>
                    </div>
                </div>
            </article>

            <article class="hero-slide" data-slide="1" role="group" aria-roledescription="slide" aria-label="2 of 3: Style and everyday">
                <div class="site-shell hero-layout">
                    <div class="hero-copy">
                        <span class="section-kicker">STYLE + EVERYDAY</span>
                        <h2>Find your<br>everyday,<br>your way.</h2>
                        <p>From apparel to accessories, Bearly keeps the browsing simple and the product choices clear.</p>
                        <div class="hero-actions-row">
                            <a class="button button-primary" href="#curated-collections">See collections <span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h18m-6-6 6 6-6 6"/></svg></span></a>
                            <button class="button button-outline" type="button" data-open-categories>Shop categories</button>
                        </div>
                        <div class="hero-promise">
                            <img src="{{ asset('images/landing-page/bear-mascot.png') }}" alt="" aria-hidden="true">
                            <div><strong>Different finds. Same Bearly.</strong><span>One marketplace for different kinds of shoppers.</span></div>
                        </div>
                    </div>
                    <div class="hero-media">
                        <div class="model-frame model-two">
                            <img src="{{ asset('images/landing-page/model-2.png') }}" alt="Bearly model wearing black" loading="lazy">
                        </div>
                        <span class="hero-note">THE EVERYDAY EDIT<br><strong>Wear it your way.</strong></span>
                    </div>
                </div>
            </article>

            <article class="hero-slide" data-slide="2" role="group" aria-roledescription="slide" aria-label="3 of 3: Curated for you">
                <div class="site-shell hero-layout bear-slide-layout">
                    <div class="hero-copy">
                        <span class="section-kicker">CURATED FOR YOU</span>
                        <h2>Small upgrades.<br>Better everyday.</h2>
                        <p>Discover useful tech, thoughtful gifts, and everyday essentials from big brands and independent sellers.</p>
                        <div class="hero-actions-row">
                            <a class="button button-primary" href="#curated-collections">Explore collections <span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h18m-6-6 6 6-6 6"/></svg></span></a>
                            <a class="button button-outline" href="#featured-products">View products</a>
                        </div>
                        <div class="hero-promise"><img src="{{ asset('images/landing-page/bear-mascot.png') }}" alt="" aria-hidden="true"><div><strong>Little finds. Big smiles.</strong><span>Something for every part of your day.</span></div></div>
                    </div>
                    <div class="hero-product-collage" aria-hidden="true">
                        <span class="curated-label">THE BEARLY EDIT</span>
                        <img class="hero-bear" src="{{ asset('images/landing-page/bear-mascot.png') }}" alt="">
                        <img class="collage-product p1" src="{{ asset('images/landing-page/products/aula-keyboard.jpg') }}" alt="">
                        <img class="collage-product p2" src="{{ asset('images/landing-page/products/canvas-tote.jpg') }}" alt="">
                        <img class="collage-product p3" src="{{ asset('images/landing-page/products/classic-watch.jpg') }}" alt="">
                    </div>
                </div>
            </article>

            <button class="hero-arrow hero-prev" type="button" aria-label="Previous slide"><span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg></span></button>
            <button class="hero-arrow hero-next" type="button" aria-label="Next slide"><span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></span></button>

            <div class="hero-dots" role="group" aria-label="Hero slides">
                <button class="is-active" type="button" data-hero-dot="0" aria-label="Go to slide 1"></button>
                <button type="button" data-hero-dot="1" aria-label="Go to slide 2"></button>
                <button type="button" data-hero-dot="2" aria-label="Go to slide 3"></button>
            </div>
        </div>

    </section>

    <section class="section section-categories reveal-section" id="shop-categories">
        <div class="site-shell">
            <div class="section-heading compact-heading">
                <div>
                    <span class="section-kicker">SHOP BY CATEGORIES</span>
                    <h2>Shop by Categories</h2>
                </div>
                <button class="text-link" type="button" data-open-categories>View all categories <span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h18m-6-6 6 6-6 6"/></svg></span></button>
            </div>

            <div class="category-carousel-wrap">
                <button class="carousel-control category-prev" type="button" aria-label="Previous categories"><span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg></span></button>
                <div class="category-carousel" id="categoryCarousel">
                    @foreach($categories as $category)
                        <button class="category-card" type="button" data-open-categories data-category-name="{{ $category['name'] }}">
                            <span class="category-image"><img src="{{ asset('images/landing-page/categories/' . $category['image']) }}" alt="{{ $category['name'] }}"></span>
                            <span>{{ $category['name'] }}</span>
                        </button>
                    @endforeach
                </div>
                <button class="carousel-control category-next" type="button" aria-label="Next categories"><span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></span></button>
            </div>
        </div>
    </section>

    <section class="section section-collections reveal-section" id="curated-collections">
        <div class="site-shell">
            <div class="section-heading">
                <div>
                    <span class="section-kicker">CURATED COLLECTIONS</span>
                    <h2>Curated Collections</h2>
                    <p>Thoughtfully chosen collections for a more comfortable, inspired you.</p>
                </div>
                <div class="heading-actions">
                    <a class="text-link" href="#curated-collections">View all collections <span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h18m-6-6 6 6-6 6"/></svg></span></a>

                </div>
            </div>

            <div class="collection-viewport">
                <div class="collection-track" id="collectionTrack">
                    @foreach($collections as $collection)
                        <article class="collection-card tone-{{ $collection['tone'] }}">
                            <img class="collection-banner-image" src="{{ asset('images/landing-page/' . $collection['background']) }}" alt="" loading="lazy">
                            <div class="collection-copy">
                                <span>{{ $collection['eyebrow'] }}</span>
                                <h3>{{ $collection['title'] }}</h3>
                                <p>{{ $collection['copy'] }}</p>
                                <a href="#featured-products">Shop collection <span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h18m-6-6 6 6-6 6"/></svg></span></a>
                            </div>

                        </article>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section class="model-promo reveal-section" aria-label="Bearly community promotion">
        <img class="community-banner-image" src="{{ asset('images/landing-page/community-banner.jpg') }}" alt="Bearly community members with a teddy bear" loading="lazy" width="1024" height="196">
        <div class="site-shell promo-layout">
            <div class="promo-copy">
                <span class="section-kicker light-kicker">BEARLY COMMUNITY</span>
                <h2>A community that shops brighter together.</h2>
                <p>Real people. Real finds. A kinder, brighter everyday — with you in it.</p>
                <a class="button button-light" href="#featured-products">Shop with purpose <span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h18m-6-6 6 6-6 6"/></svg></span></a>
            </div>
            <div class="promo-models">
                <div class="promo-model promo-model-left"><img src="{{ asset('images/landing-page/model-1.png') }}" alt="Bearly community model"></div>
                <div class="promo-model promo-model-right"><img src="{{ asset('images/landing-page/model-2.png') }}" alt="Bearly community model"></div>
            </div>
            <div class="promo-bear">
                <img src="{{ asset('images/landing-page/bear-mascot.png') }}" alt="Bearly bear mascot">
                <span>Good things<br>find you here.</span>
            </div>
        </div>
    </section>

    <section class="section products-section reveal-section" id="featured-products">
        <div class="site-shell">
            <div class="section-heading products-heading">
                <div>
                    <span class="section-kicker">FEATURED PRODUCTS</span>
                    <h2>Featured Products</h2>
                </div>
                <div class="heading-actions">
                    <button class="text-link" type="button" id="showAllProducts">View all products <span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h18m-6-6 6 6-6 6"/></svg></span></button>

                </div>
            </div>

            <div class="product-tabs" role="group" aria-label="Product categories">
                @foreach(['All','Fashion','Tech','Beauty','Home','Accessories','Outdoor','Books'] as $tab)
                    <button type="button" class="product-tab {{ $tab === 'All' ? 'is-active' : '' }}" data-product-filter="{{ $tab }}">{{ $tab }}</button>
                @endforeach
            </div>

            <p id="noProductResults" hidden>No matching products. Try another search or category.</p>
            <div class="product-viewport">
                <div class="product-track" id="productTrack">
                    @foreach($products as $product)
                        <article class="product-card" data-product-card data-category="{{ $product['category'] }}" data-name="{{ strtolower($product['name']) }}">
                            <div class="product-image-wrap" aria-label="{{ $product['name'] }}">
                                @if($product['badge'])<span class="product-badge">{{ $product['badge'] }}</span>@endif
                                <button class="wishlist-button" type="button" aria-label="Add {{ $product['name'] }} to wishlist"><span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 4.8a5 5 0 0 0-7.1 0L12 6.2l-1.4-1.4a5 5 0 0 0-7.1 7.1L12 21l8.5-9.1a5 5 0 0 0 0-7.1Z"/></svg></span></button>
                                <img src="{{ asset('images/landing-page/products/' . $product['image']) }}" alt="{{ $product['name'] }}" loading="lazy">
                                
                            </div>
                            <div class="product-info">
                                <span class="product-category">{{ $product['category'] }}</span>
                                <h3>{{ $product['name'] }}</h3>
                                <div class="product-meta">
                                    <strong>{{ $product['price'] }}</strong>
                                    <span class="product-rating" aria-label="4.8 out of 5 stars">★★★★★</span>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section class="benefits-section reveal-section">
        <div class="site-shell benefits-grid">
            <div><span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 4h12v13H2z M14 9h4l4 5v3h-8"/><circle cx="6" cy="18" r="2"/><circle cx="18" cy="18" r="2"/></svg></span><strong>Discover more</strong><small>Across everyday categories</small></div>
            <div><span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 2 9 4v7c0 5-9 9-9 9S3 18 3 13V6l9-4Z m-4 10 3 3 5-6"/></svg></span><strong>Shop your way</strong><small>Find your everyday essentials</small></div>
            <div><span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 3C6 2 1 9 6 17S22 17 20 3ZM5 22 17 7"/></svg></span><strong>Thoughtfully curated</strong><small>Products worth discovering</small></div>
            <div><span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="7" r="3"/><path d="M2 21v-3a7 7 0 0 1 14 0v3 M16 4a3 3 0 0 1 0 6m2 4a5 5 0 0 1 4 5v2"/></svg></span><strong>Support</strong><small>For buyers and sellers</small></div>
            <div><span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 4.8a5 5 0 0 0-7.1 0L12 6.2l-1.4-1.4a5 5 0 0 0-7.1 7.1L12 21l8.5-9.1a5 5 0 0 0 0-7.1Z"/></svg></span><strong>A happier experience</strong><small>Bearly stressful shopping</small></div>
        </div>
    </section>

    <section class="account-cta reveal-section">
        <div class="site-shell account-cta-inner">
            <div>
                <span class="section-kicker light-kicker">JOIN BEARLY</span>
                <h2>Make shopping Bearly a hassle.</h2>
                <p>Join for a simpler, kinder way to shop. Get exclusive offers, new arrivals, and more.</p>
            </div>
            <form class="newsletter-form cta-form"><input type="email" required aria-label="Email address" placeholder="Your email address"><button type="submit">Subscribe</button></form>
            <img src="{{ asset('images/landing-page/bear-mascot.png') }}" alt="" aria-hidden="true">
        </div>
    </section>
<p class="interaction-status" id="interactionStatus" role="status" aria-live="polite"></p>
</main>

<footer class="site-footer">
    <div class="site-shell footer-grid">
        <div class="footer-brand">
            <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly">
            <p>Shopping should be easy. Bearly stressful.</p>
            <div class="social-row">
                <a href="#" aria-label="Instagram">IG</a><a href="#" aria-label="Facebook">FB</a><a href="#" aria-label="TikTok">TT</a><a href="#" aria-label="YouTube">YT</a>
            </div>
        </div>
        <div>
            <h3>Shop</h3>
            <a href="#featured-products">All Products</a>
            <button type="button" data-open-categories>Categories</button>
            <a href="#curated-collections">Collections</a>
            <a href="#featured-products">New Arrivals</a>
        </div>
        <div>
            <h3>Help</h3>
            <a href="{{ url('/about') }}">Our Story</a>
            <a href="#">Sell on Bearly</a>
            <a href="#">Shopping & Returns</a>
            <a href="{{ url('/contact') }}">Contact Us</a>
        </div>
        <div><h3>About</h3><a href="{{ url('/about') }}">Our Story</a><a href="{{ url('/about') }}">Independent Sellers</a><a href="{{ url('/contact') }}">Contact Us</a></div>
        <div class="newsletter">
            <h3>Be the first to know.</h3>
            <p>Get product drops, seller finds, and Bearly updates.</p>
            <form class="newsletter-form">
                <input type="email" required placeholder="Your email address" aria-label="Email address">
                <button type="submit">Subscribe</button>
            </form>
        </div>
    </div>
    <div class="site-shell footer-bottom">
        <span>© 2026 Bearly. All rights reserved.</span>
        <div><a href="#">Privacy Policy</a><a href="#">Terms of Service</a><a href="#">Cookies</a></div>
    </div>
</footer>

<div class="category-modal" id="categoryModal" aria-hidden="true">
    <div class="category-backdrop" data-close-categories></div>
    <div class="category-dialog" role="dialog" aria-modal="true" aria-labelledby="categoryModalTitle">
        <div class="category-dialog-head">
            <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly">
            <h2 id="categoryModalTitle">Shop by category</h2>
            <button class="modal-close" type="button" data-close-categories aria-label="Close categories"><span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 5 14 14M5 19 19 5"/></svg></span></button>
        </div>

        <div class="modal-search-wrap">
            <label class="modal-search">
                <span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg></span>
                <input type="search" id="categorySearch" placeholder="What are you looking for?">
            </label>
            <div class="trending-searches"><strong>Trending:</strong><button type="button">headphones</button><button type="button">skincare</button><button type="button">coffee</button><button type="button">backpacks</button><button type="button">dog toys</button><button type="button">home decor</button></div>
        </div>

        <div class="category-dialog-body" id="categoryDialogBody">
            <div class="modal-category-grid" id="modalCategoryGrid">
                @foreach($categories as $category)
                    <button class="modal-category-card" type="button" data-modal-category data-search-text="{{ strtolower($category['name'] . ' ' . implode(' ', $category['subs'])) }}">
                        <span><img src="{{ asset('images/landing-page/categories/' . $category['image']) }}" alt=""></span>
                        <strong>{{ $category['name'] }}</strong>
                    </button>
                @endforeach
                @foreach($extraCategories as $category)
                    <button class="modal-category-card icon-category-card" type="button" data-modal-category data-search-text="{{ strtolower($category['name'] . ' ' . implode(' ', $category['subs'])) }}">
                        <span><span class="material-symbols-rounded">{{ $category['icon'] }}</span></span>
                        <strong>{{ $category['name'] }}</strong>
                    </button>
                @endforeach
            </div>

            <div class="modal-promo-strip">
                <img src="{{ asset('images/landing-page/bear-mascot.png') }}" alt="" aria-hidden="true">
                <div><strong>Good things find you here.</strong><span>Discover something for every part of your day.</span></div>
                <a class="button button-primary small-button" href="#featured-products" data-close-categories>Shop now <span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h18m-6-6 6 6-6 6"/></svg></span></a>
                <div class="modal-promo-points"><span><span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 3C6 2 1 9 6 17S22 17 20 3ZM5 22 17 7"/></svg></span> Better choices</span><span><span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9h18l-2-6H5L3 9Z M4 9v12h16V9 M9 21v-7h6v7"/></svg></span> Independent sellers</span><span><span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 4h12v13H2z M14 9h4l4 5v3h-8"/><circle cx="6" cy="18" r="2"/><circle cx="18" cy="18" r="2"/></svg></span> Easier discovery</span></div>
            </div>

            <div class="all-categories-head"><h3>Explore all categories</h3><span>Find your next favorite</span></div>
            <div class="subcategory-grid" id="subcategoryGrid">
                @foreach($categories as $category)
                    <div class="subcategory-column" data-subcategory-group data-search-text="{{ strtolower($category['name'] . ' ' . implode(' ', $category['subs'])) }}">
                        <h4>{{ $category['name'] }} <span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></span></h4>
                        @foreach($category['subs'] as $sub)<a href="#">{{ $sub }}</a>@endforeach
                    </div>
                @endforeach
                @foreach($extraCategories as $category)
                    <div class="subcategory-column" data-subcategory-group data-search-text="{{ strtolower($category['name'] . ' ' . implode(' ', $category['subs'])) }}">
                        <h4>{{ $category['name'] }} <span class="material-symbols-rounded"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></span></h4>
                        @foreach($category['subs'] as $sub)<a href="#">{{ $sub }}</a>@endforeach
                    </div>
                @endforeach
            </div>
            <p class="no-category-results" id="noCategoryResults" hidden>No matching category found.</p>
        </div>
    </div>
</div>

</body>
</html>
