<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Learn more about Bearly, a marketplace built to make product discovery feel simpler and more human.">
    <meta name="theme-color" content="#432214">
    <title>About Bearly</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@300&display=block" rel="stylesheet">

    @vite(['resources/css/about.css'])
</head>
<body class="bearly-public about-page">
<a class="public-skip" href="#about-main">Skip to content</a>

<header class="public-header">
    <a class="public-logo" href="{{ url('/') }}" aria-label="Bearly home">
        <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly">
    </a>

    <nav class="public-nav" aria-label="Main navigation">
        <a href="{{ url('/') }}">Home</a>
        <a href="{{ url('/products') }}">Shop</a>
        <a href="{{ route('about') }}" aria-current="page">About</a>
        <a href="{{ route('contact') }}">Contact</a>
    </nav>

    <form class="public-search" action="{{ url('/products') }}" method="GET" role="search">
        <label class="public-sr" for="about-search">Search products and stores</label>
        <input id="about-search" name="search" type="search" maxlength="120" placeholder="Search products and stores">
        <button type="submit" aria-label="Search">
            <span class="material-symbols-outlined" aria-hidden="true">search</span>
        </button>
    </form>

    <div class="public-actions">
        <a href="{{ url('/login') }}" aria-label="Log in">
            <span class="material-symbols-outlined" aria-hidden="true">person</span>
        </a>
        <a href="{{ url('/cart') }}" aria-label="Shopping cart">
            <span class="material-symbols-outlined" aria-hidden="true">shopping_bag</span>
        </a>
    </div>
</header>

<main id="about-main">
    <section class="about-hero">
        <div class="about-shell about-hero-grid">
            <div class="about-hero-copy">
                <p class="about-eyebrow">ABOUT BEARLY</p>
                <h1>Shopping should feel <span>more human.</span></h1>
                <p class="about-lead">
                    Bearly brings independent stores and thoughtful shoppers together
                    in one marketplace built around easier discovery and less friction.
                </p>

                <div class="about-hero-actions">
                    <a class="about-primary" href="{{ url('/products') }}">Explore the shop ↗</a>
                    <a class="about-text-link" href="{{ route('contact') }}">Contact Bearly</a>
                </div>
            </div>

            <aside class="about-manifesto" aria-label="Bearly principles">
                <p class="about-manifesto-kicker">BEARLY IN THREE LINES</p>
                <strong>Find more.<br>Live better.</strong>

                <div class="about-manifesto-list">
                    <div><span>01</span><p>Independent stores deserve room to be discovered.</p></div>
                    <div><span>02</span><p>Useful shopping should not feel overwhelming.</p></div>
                    <div><span>03</span><p>Good products should be easier to find and understand.</p></div>
                </div>
            </aside>
        </div>
    </section>

    <section class="about-section">
        <div class="about-shell about-story-grid">
            <div>
                <p class="about-label">OUR STORY</p>
                <h2>A marketplace built around better everyday choices.</h2>
            </div>

            <div class="about-story-copy">
                <p>
                    Bearly was created for people who want to discover useful,
                    distinctive products without getting lost in endless noise.
                </p>
                <p>
                    The marketplace gives independent sellers space to present what
                    they offer clearly while giving shoppers a simpler way to browse.
                </p>
            </div>
        </div>
    </section>

    <section class="about-name">
        <div class="about-shell about-name-grid">
            <div>
                <p class="about-label">WHY “BEARLY”</p>
                <h2>Because shopping should be barely a hassle.</h2>
            </div>
            <p>
                The name is a reminder of the product goal: remove unnecessary
                friction between people and the things they are trying to find.
            </p>
        </div>
    </section>

    <section class="about-section about-expect">
        <div class="about-shell">
            <div class="about-section-heading">
                <div>
                    <p class="about-label">WHAT BEARLY OFFERS</p>
                    <h2>Built for discovery, not clutter.</h2>
                </div>
                <p>
                    A marketplace can have variety without making every screen feel busy.
                </p>
            </div>

            <div class="about-offer-list">
                <article>
                    <span class="material-symbols-outlined" aria-hidden="true">storefront</span>
                    <div>
                        <h3>Independent and growing stores</h3>
                        <p>Give smaller businesses a clear place to present products beside larger sellers.</p>
                    </div>
                </article>

                <article>
                    <span class="material-symbols-outlined" aria-hidden="true">search</span>
                    <div>
                        <h3>Thoughtful product discovery</h3>
                        <p>Browse useful categories and move from interest to product without unnecessary steps.</p>
                    </div>
                </article>

                <article>
                    <span class="material-symbols-outlined" aria-hidden="true">verified_user</span>
                    <div>
                        <h3>Clear shopping information</h3>
                        <p>Keep product, seller, and order information understandable as the platform grows.</p>
                    </div>
                </article>

                <article>
                    <span class="material-symbols-outlined" aria-hidden="true">handshake</span>
                    <div>
                        <h3>Support for small businesses</h3>
                        <p>Help independent sellers reach more shoppers without losing their own identity.</p>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <section class="about-small-business">
        <div class="about-shell about-small-business-grid">
            <p class="about-label">WHY IT MATTERS</p>
            <h2>More room for small businesses to be seen.</h2>
            <p>
                Bearly is designed as an open marketplace where independent shops and
                larger stores can coexist. Discovery should not depend only on who is
                already the biggest.
            </p>
        </div>
    </section>

    <section class="about-cta">
        <div class="about-shell about-cta-inner">
            <div>
                <p class="about-label">START EXPLORING</p>
                <h2>Good things are waiting.</h2>
                <p>Browse the guest catalogue or create an account when you are ready.</p>
            </div>

            <div class="about-cta-actions">
                <a class="about-primary" href="{{ url('/products') }}">Shop now ↗</a>
                <a class="about-secondary" href="{{ url('/register') }}">Create an account</a>
            </div>
        </div>
    </section>
</main>

<footer class="public-footer">
    <div class="public-footer-grid">
        <div>
            <a class="public-footer-logo" href="{{ url('/') }}" aria-label="Bearly home">
                <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly">
            </a>
            <p>Shopping should be easy.<br>Bearly stressful.</p>
        </div>

        <nav aria-label="Shop links">
            <h2>Shop</h2>
            <a href="{{ url('/products') }}">All Products</a>
            <a href="{{ url('/') }}#bl-categories">Categories</a>
        </nav>

        <nav aria-label="About links">
            <h2>About</h2>
            <a href="{{ route('about') }}">Our Story</a>
            <a href="{{ url('/register') }}">Sell on Bearly</a>
        </nav>

        <nav aria-label="Support links">
            <h2>Support</h2>
            <a href="{{ route('contact') }}">Contact Us</a>
            <a href="{{ url('/login') }}">Log in</a>
        </nav>
    </div>

    <div class="public-footer-bottom">
        <span>© {{ date('Y') }} Bearly. All rights reserved.</span>
    </div>
</footer>
</body>
</html>
