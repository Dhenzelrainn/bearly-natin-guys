<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <meta
        name="description"
        content="Contact Bearly for shopping, order, delivery, seller, account, registration, complaint, dispute, and general marketplace support."
    >

    <title>Contact Bearly — We’re here to help</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@300&display=block"
        rel="stylesheet"
    >

    @vite([
        'resources/css/landing.css',
        'resources/css/contact.css',
        'resources/js/contact.js',
    ])
</head>

<body class="bl contact-page">
<a class="skip-link" href="#contact-main">Skip to content</a>

<header class="bl-header contact-header">
    <a class="bl-brand" href="{{ route('shop.home') }}" aria-label="Bearly home">
        <img
            src="{{ asset('images/bearly-logo.png') }}"
            alt="Bearly"
        >
    </a>

    <nav class="bl-nav" aria-label="Main navigation">
        <a href="{{ route('shop.home') }}">Home</a>
        <a href="{{ route('home') }}">Shop</a>
        <a href="{{ route('about') }}">About</a>
        <a href="{{ route('shop.home') }}#bl-delivery">Delivery</a>
        <a href="{{ route('contact') }}" aria-current="page">Contact</a>
    </nav>

    <div class="bl-actions">
        <a href="{{ route('login') }}" aria-label="Sign in">
            <span class="material-symbols-outlined" aria-hidden="true">person</span>
        </a>

        <a href="{{ route('wishlist.index') }}" aria-label="Saved products">
            <span class="material-symbols-outlined" aria-hidden="true">favorite</span>
        </a>

        <a href="{{ route('cart.view') }}" aria-label="Shopping cart">
            <span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>
        </a>
    </div>

    <form class="bl-search" action="{{ route('products.index') }}" method="GET" role="search">
        <button type="submit" aria-label="Search">
            <span class="material-symbols-outlined" aria-hidden="true">search</span>
        </button>

        <label class="sr-only" for="contact-site-search">Search products</label>

        <input
            id="contact-site-search"
            name="search"
            type="search"
            maxlength="120"
            placeholder="Search for products, brands, or independent stores..."
        >
    </form>
</header>

<main id="contact-main">
    <section class="contact-hero">
        <div class="contact-shell">
            <p class="contact-eyebrow">CONTACT BEARLY</p>

            <h1>
                We’re here to <span>help.</span>
            </h1>

            <p>
                Whether you’re shopping, selling, managing an account, or need
                help with an order, Bearly can point you to the right support.
            </p>
        </div>
    </section>

    <section class="contact-section contact-topics-section" aria-labelledby="help-topics-title">
        <div class="contact-shell">
            <div class="contact-section-heading">
                <div>
                    <p class="contact-label">SUPPORT TOPICS</p>
                    <h2 id="help-topics-title">How can we help you?</h2>
                </div>

                <a class="contact-text-link" href="#contact-form">
                    Send us a message
                    <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                </a>
            </div>

            <div class="contact-topic-grid">
                <button
                    class="contact-topic-card"
                    type="button"
                    data-contact-topic="Shopping & Orders"
                >
                    <span class="material-symbols-outlined contact-topic-icon" aria-hidden="true">
                        shopping_cart
                    </span>

                    <span class="contact-topic-copy">
                        <strong>Shopping &amp; Orders</strong>
                        <small>Questions about products, checkout, payments, returns, or orders.</small>
                    </span>

                    <span class="material-symbols-outlined contact-topic-arrow" aria-hidden="true">
                        arrow_forward
                    </span>
                </button>

                <button
                    class="contact-topic-card"
                    type="button"
                    data-contact-topic="Delivery & Tracking"
                >
                    <span class="material-symbols-outlined contact-topic-icon" aria-hidden="true">
                        local_shipping
                    </span>

                    <span class="contact-topic-copy">
                        <strong>Delivery &amp; Tracking</strong>
                        <small>Get help with pickup, sorting, rider assignment, or delivery status.</small>
                    </span>

                    <span class="material-symbols-outlined contact-topic-arrow" aria-hidden="true">
                        arrow_forward
                    </span>
                </button>

                <button
                    class="contact-topic-card"
                    type="button"
                    data-contact-topic="Seller Support"
                >
                    <span class="material-symbols-outlined contact-topic-icon" aria-hidden="true">
                        storefront
                    </span>

                    <span class="contact-topic-copy">
                        <strong>Seller Support</strong>
                        <small>Support for stores, products, inventory, orders, and fulfillment.</small>
                    </span>

                    <span class="material-symbols-outlined contact-topic-arrow" aria-hidden="true">
                        arrow_forward
                    </span>
                </button>

                <button
                    class="contact-topic-card"
                    type="button"
                    data-contact-topic="Account & Registration"
                >
                    <span class="material-symbols-outlined contact-topic-icon" aria-hidden="true">
                        person
                    </span>

                    <span class="contact-topic-copy">
                        <strong>Account &amp; Registration</strong>
                        <small>Help with registration, approval status, login, or account access.</small>
                    </span>

                    <span class="material-symbols-outlined contact-topic-arrow" aria-hidden="true">
                        arrow_forward
                    </span>
                </button>

                <button
                    class="contact-topic-card"
                    type="button"
                    data-contact-topic="Complaints & Disputes"
                >
                    <span class="material-symbols-outlined contact-topic-icon" aria-hidden="true">
                        verified_user
                    </span>

                    <span class="contact-topic-copy">
                        <strong>Complaints &amp; Disputes</strong>
                        <small>Report an order, seller, buyer, courier, or delivery concern for review.</small>
                    </span>

                    <span class="material-symbols-outlined contact-topic-arrow" aria-hidden="true">
                        arrow_forward
                    </span>
                </button>

                <button
                    class="contact-topic-card"
                    type="button"
                    data-contact-topic="General Inquiry"
                >
                    <span class="material-symbols-outlined contact-topic-icon" aria-hidden="true">
                        chat_bubble
                    </span>

                    <span class="contact-topic-copy">
                        <strong>General Inquiry</strong>
                        <small>Questions, feedback, partnerships, or anything else about Bearly.</small>
                    </span>

                    <span class="material-symbols-outlined contact-topic-arrow" aria-hidden="true">
                        arrow_forward
                    </span>
                </button>
            </div>
        </div>
    </section>

    <section class="contact-section contact-form-section">
        <div class="contact-shell contact-main-grid">
            <div class="contact-form-column">
                <div class="contact-section-heading contact-form-heading">
                    <div>
                        <p class="contact-label">CONTACT SUPPORT</p>
                        <h2>Send us a message</h2>
                        <p>
                            Tell us what you need help with and provide as much
                            detail as you can.
                        </p>
                    </div>
                </div>

                <form
                    class="contact-form"
                    id="contact-form"
                    novalidate
                    data-contact-form
                >
                    <div class="contact-field-row">
                        <div class="contact-field">
                            <label for="contact-name">
                                Full name <span aria-hidden="true">*</span>
                            </label>

                            <input
                                id="contact-name"
                                name="name"
                                type="text"
                                maxlength="100"
                                autocomplete="name"
                                placeholder="Enter your full name"
                                required
                            >

                            <small class="contact-error" data-error-for="contact-name"></small>
                        </div>

                        <div class="contact-field">
                            <label for="contact-email">
                                Email address <span aria-hidden="true">*</span>
                            </label>

                            <input
                                id="contact-email"
                                name="email"
                                type="email"
                                maxlength="254"
                                autocomplete="email"
                                placeholder="Enter your email address"
                                required
                            >

                            <small class="contact-error" data-error-for="contact-email"></small>
                        </div>
                    </div>

                    <div class="contact-field-row">
                        <div class="contact-field">
                            <label for="contact-role">I am a</label>

                            <select id="contact-role" name="role">
                                <option value="">Select an option</option>
                                <option value="Buyer">Buyer</option>
                                <option value="Seller">Seller</option>
                                <option value="Courier">Courier</option>
                                <option value="Logistics / Sorting Center">Logistics / Sorting Center</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="contact-field">
                            <label for="contact-topic">
                                What can we help you with? <span aria-hidden="true">*</span>
                            </label>

                            <select id="contact-topic" name="topic" required>
                                <option value="">Select a topic</option>
                                <option value="Shopping & Orders">Shopping &amp; Orders</option>
                                <option value="Delivery & Tracking">Delivery &amp; Tracking</option>
                                <option value="Seller Support">Seller Support</option>
                                <option value="Account & Registration">Account &amp; Registration</option>
                                <option value="Complaints & Disputes">Complaints &amp; Disputes</option>
                                <option value="Technical Issue">Technical Issue</option>
                                <option value="General Inquiry">General Inquiry</option>
                            </select>

                            <small class="contact-error" data-error-for="contact-topic"></small>
                        </div>
                    </div>

                    <div class="contact-field-row">
                        <div class="contact-field">
                            <label for="contact-reference">
                                Order / Reference number
                                <span class="contact-optional">(optional)</span>
                            </label>

                            <input
                                id="contact-reference"
                                name="reference"
                                type="text"
                                maxlength="50"
                                placeholder="e.g. BRLY123456"
                            >
                        </div>

                        <div class="contact-field">
                            <label for="contact-subject">
                                Subject <span aria-hidden="true">*</span>
                            </label>

                            <input
                                id="contact-subject"
                                name="subject"
                                type="text"
                                maxlength="120"
                                placeholder="Enter a subject"
                                required
                            >

                            <small class="contact-error" data-error-for="contact-subject"></small>
                        </div>
                    </div>

                    <div class="contact-field">
                        <div class="contact-message-label">
                            <label for="contact-message">
                                Message <span aria-hidden="true">*</span>
                            </label>

                            <span data-contact-count>0/1000</span>
                        </div>

                        <textarea
                            id="contact-message"
                            name="message"
                            rows="7"
                            maxlength="1000"
                            placeholder="Tell us more about your inquiry..."
                            required
                        ></textarea>

                        <small class="contact-error" data-error-for="contact-message"></small>
                    </div>

                    <div class="contact-form-actions">
                        <button class="contact-primary-button" type="submit">
                            Send message
                            <span class="material-symbols-outlined" aria-hidden="true">
                                arrow_forward
                            </span>
                        </button>

                        <p class="contact-form-note">
                            This is currently a front-end support form. Message
                            delivery will be connected when the backend support
                            module is implemented.
                        </p>
                    </div>
                </form>
            </div>

            <aside class="contact-reach-column" aria-labelledby="reach-title">
                <p class="contact-label">CONTACT DETAILS</p>
                <h2 id="reach-title">Other ways to reach us</h2>
                <p class="contact-reach-intro">
                    Official Bearly contact details will be published here once
                    they are finalized.
                </p>

                <div class="contact-reach-list">
                    <article>
                        <span class="material-symbols-outlined" aria-hidden="true">mail</span>

                        <div>
                            <strong>Email</strong>
                            <p>To be announced</p>
                        </div>
                    </article>

                    <article>
                        <span class="material-symbols-outlined" aria-hidden="true">call</span>

                        <div>
                            <strong>Phone</strong>
                            <p>To be announced</p>
                        </div>
                    </article>

                    <article>
                        <span class="material-symbols-outlined" aria-hidden="true">schedule</span>

                        <div>
                            <strong>Support hours</strong>
                            <p>To be announced</p>
                        </div>
                    </article>

                    <article>
                        <span class="material-symbols-outlined" aria-hidden="true">location_on</span>

                        <div>
                            <strong>Location</strong>
                            <p>To be announced</p>
                        </div>
                    </article>
                </div>

                <div class="contact-tip">
                    <span class="material-symbols-outlined" aria-hidden="true">info</span>

                    <p>
                        For faster support, include your order or reference
                        number when your concern is related to a transaction.
                    </p>
                </div>
            </aside>
        </div>
    </section>

    <section class="contact-section contact-shortcuts">
        <div class="contact-shell contact-shortcut-grid">
            <article class="contact-shortcut-card">
                <span class="material-symbols-outlined contact-shortcut-icon" aria-hidden="true">
                    package_2
                </span>

                <div class="contact-shortcut-copy">
                    <strong>Need help with an existing order?</strong>
                    <p>
                        Sign in to Bearly to check your buyer area and available
                        order information.
                    </p>
                </div>

                <div class="contact-shortcut-actions">
                    <a class="contact-primary-button contact-small-button" href="{{ route('home') }}">
                        View orders
                    </a>

                    <a class="contact-outline-button contact-small-button" href="{{ route('login') }}">
                        Log in
                    </a>
                </div>
            </article>

            <article class="contact-shortcut-card">
                <span class="material-symbols-outlined contact-shortcut-icon" aria-hidden="true">
                    report
                </span>

                <div class="contact-shortcut-copy">
                    <strong>Have a complaint or dispute?</strong>
                    <p>
                        Tell us what happened and provide the details needed for
                        review.
                    </p>
                </div>

                <div class="contact-shortcut-actions">
                    <button
                        class="contact-outline-button contact-small-button"
                        type="button"
                        data-contact-report
                    >
                        Report a concern
                    </button>
                </div>
            </article>
        </div>
    </section>
</main>

<footer class="bl-footer">
    <div class="bl-footer-grid">
        <div>
            <a class="bl-brand" href="{{ route('shop.home') }}" aria-label="Bearly home">
                <img
                    src="{{ asset('images/bearly-logo.png') }}"
                    alt="Bearly"
                >
            </a>

            <p class="bl-copyright">
                © {{ date('Y') }} Bearly.<br>
                All rights reserved.
            </p>
        </div>

        <nav aria-label="Shop links">
            <h3>Shop</h3>
            <a href="{{ route('home') }}">All Products</a>
            <a href="{{ route('shop.home') }}#bl-featured">Featured Products</a>
            <a href="{{ route('shop.home') }}#bl-categories">Categories</a>
        </nav>

        <nav aria-label="About links">
            <h3>About</h3>
            <a href="{{ route('about') }}">Our Story</a>
            <a href="{{ route('contact') }}">Contact Bearly</a>
        </nav>

        <nav aria-label="Support links">
            <h3>Support</h3>
            <a href="{{ route('shop.home') }}#bl-delivery">Delivery</a>
            <a href="#contact-form">Send a Message</a>
            <a href="{{ route('contact') }}">Contact Us</a>
        </nav>

        <div class="bl-newsletter">
            <h3>Be the first to know</h3>
            <p>Get updates on new launches and special offers.</p>

            <form action="#" onsubmit="return false;">
                <label class="sr-only" for="contact-footer-email">Your email</label>

                <input
                    type="email"
                    id="contact-footer-email"
                    maxlength="254"
                    placeholder="Enter your email"
                >

                <button class="bl-button" type="submit">Subscribe</button>
            </form>
        </div>
    </div>

    <div class="bl-footer-bottom">
        <span>Shopping should be easy. Bearly stressful.</span>

        <div>
            <span>Privacy Policy</span>
            <span>|</span>
            <span>Terms of Service</span>
        </div>
    </div>
</footer>

<div
    class="contact-toast"
    data-contact-toast
    role="status"
    aria-live="polite"
    hidden
></div>
</body>
</html>
