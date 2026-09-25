<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Contact Bearly for shopping, seller, account, delivery, complaint, and general marketplace support.">
    <meta name="theme-color" content="#432214">
    <title>Contact Bearly — We’re here to help</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@300&display=block" rel="stylesheet">

    @vite(['resources/css/contact.css', 'resources/js/contact.js'])
</head>
<body class="bearly-public contact-page">
<a class="public-skip" href="#contact-main">Skip to content</a>

<header class="public-header">
    <a class="public-logo" href="{{ url('/') }}" aria-label="Bearly home">
        <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly">
    </a>

    <nav class="public-nav" aria-label="Main navigation">
        <a href="{{ url('/') }}">Home</a>
        <a href="{{ url('/products') }}">Shop</a>
        <a href="{{ route('about') }}">About</a>
        <a href="{{ route('contact') }}" aria-current="page">Contact</a>
    </nav>

    <form class="public-search" action="{{ url('/products') }}" method="GET" role="search">
        <label class="public-sr" for="contact-site-search">Search products and stores</label>
        <input id="contact-site-search" name="search" type="search" maxlength="120" placeholder="Search products and stores">
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

<main id="contact-main">
    <section class="contact-hero">
        <div class="contact-shell contact-hero-grid">
            <div>
                <p class="contact-eyebrow">CONTACT BEARLY</p>
                <h1>We’re here to <span>help.</span></h1>
                <p class="contact-lead">
                    Start with a support topic or send us the details directly.
                    We’ll keep the path simple.
                </p>
            </div>

            <div class="contact-hero-note">
                <span>Guest support</span>
                <p>
                    You can send a message without signing in. For account or order-specific
                    actions, Bearly may ask you to log in.
                </p>
                <a href="#contact-form">Send us a message ↓</a>
            </div>
        </div>
    </section>

    <section class="contact-section contact-topics-section" aria-labelledby="help-topics-title">
        <div class="contact-shell">
            <div class="contact-section-heading">
                <div>
                    <p class="contact-label">SUPPORT TOPICS</p>
                    <h2 id="help-topics-title">What do you need help with?</h2>
                </div>
                <p>Choose a topic and we’ll pre-select it in the message form.</p>
            </div>

            <div class="contact-topic-list">
                <button type="button" data-contact-topic="Shopping & Orders">
                    <span class="material-symbols-outlined" aria-hidden="true">shopping_bag</span>
                    <span><strong>Shopping &amp; Orders</strong><small>Products, checkout, payments, returns, or order questions.</small></span>
                    <span aria-hidden="true">↗</span>
                </button>

                <button type="button" data-contact-topic="Delivery & Tracking">
                    <span class="material-symbols-outlined" aria-hidden="true">local_shipping</span>
                    <span><strong>Delivery &amp; Tracking</strong><small>Pickup, sorting, rider assignment, or delivery status.</small></span>
                    <span aria-hidden="true">↗</span>
                </button>

                <button type="button" data-contact-topic="Seller Support">
                    <span class="material-symbols-outlined" aria-hidden="true">storefront</span>
                    <span><strong>Seller Support</strong><small>Stores, products, inventory, orders, and fulfillment.</small></span>
                    <span aria-hidden="true">↗</span>
                </button>

                <button type="button" data-contact-topic="Account & Registration">
                    <span class="material-symbols-outlined" aria-hidden="true">person</span>
                    <span><strong>Account &amp; Registration</strong><small>Registration, approval status, login, or account access.</small></span>
                    <span aria-hidden="true">↗</span>
                </button>

                <button type="button" data-contact-topic="Complaints & Disputes">
                    <span class="material-symbols-outlined" aria-hidden="true">verified_user</span>
                    <span><strong>Complaints &amp; Disputes</strong><small>Report a buyer, seller, courier, delivery, or order concern.</small></span>
                    <span aria-hidden="true">↗</span>
                </button>

                <button type="button" data-contact-topic="General Inquiry">
                    <span class="material-symbols-outlined" aria-hidden="true">chat_bubble</span>
                    <span><strong>General Inquiry</strong><small>Feedback, partnerships, questions, or anything else about Bearly.</small></span>
                    <span aria-hidden="true">↗</span>
                </button>
            </div>
        </div>
    </section>

    <section class="contact-form-section">
        <div class="contact-shell contact-form-grid">
            <div class="contact-form-column">
                <div class="contact-form-heading">
                    <p class="contact-label">CONTACT SUPPORT</p>
                    <h2>Send us a message</h2>
                    <p>Tell us what happened and include the details that will help us understand your concern.</p>
                </div>

                <form class="contact-form" id="contact-form" novalidate data-contact-form>
                    <div class="contact-field-row">
                        <div class="contact-field">
                            <label for="contact-name">Full name <span aria-hidden="true">*</span></label>
                            <input id="contact-name" name="name" type="text" maxlength="100" autocomplete="name" placeholder="Enter your full name" required>
                            <small class="contact-error" data-error-for="contact-name"></small>
                        </div>

                        <div class="contact-field">
                            <label for="contact-email">Email address <span aria-hidden="true">*</span></label>
                            <input id="contact-email" name="email" type="email" maxlength="254" autocomplete="email" placeholder="Enter your email address" required>
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
                            <label for="contact-topic">What can we help you with? <span aria-hidden="true">*</span></label>
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
                            <label for="contact-reference">Order / Reference number <span class="contact-optional">(optional)</span></label>
                            <input id="contact-reference" name="reference" type="text" maxlength="50" placeholder="e.g. BRLY123456">
                        </div>

                        <div class="contact-field">
                            <label for="contact-subject">Subject <span aria-hidden="true">*</span></label>
                            <input id="contact-subject" name="subject" type="text" maxlength="120" placeholder="Enter a subject" required>
                            <small class="contact-error" data-error-for="contact-subject"></small>
                        </div>
                    </div>

                    <div class="contact-field">
                        <div class="contact-message-label">
                            <label for="contact-message">Message <span aria-hidden="true">*</span></label>
                            <span data-contact-count>0/1000</span>
                        </div>

                        <textarea id="contact-message" name="message" rows="7" maxlength="1000" placeholder="Tell us more about your inquiry..." required></textarea>
                        <small class="contact-error" data-error-for="contact-message"></small>
                    </div>

                    <div class="contact-form-actions">
                        <button class="contact-primary-button" type="submit">Send message ↗</button>
                        <p>
                            Message delivery will be connected when the backend support module is implemented.
                        </p>
                    </div>
                </form>
            </div>

            <aside class="contact-reach" aria-labelledby="reach-title">
                <p class="contact-label">CONTACT DETAILS</p>
                <h2 id="reach-title">Other ways to reach us</h2>
                <p class="contact-reach-intro">
                    Official support details are still being finalized. For now,
                    use the message form so your concern has the right context.
                </p>

                <div class="contact-reach-list">
                    <div>
                        <span class="material-symbols-outlined" aria-hidden="true">mail</span>
                        <p><strong>Email</strong><small>To be announced</small></p>
                    </div>

                    <div>
                        <span class="material-symbols-outlined" aria-hidden="true">call</span>
                        <p><strong>Phone</strong><small>To be announced</small></p>
                    </div>

                    <div>
                        <span class="material-symbols-outlined" aria-hidden="true">schedule</span>
                        <p><strong>Support hours</strong><small>To be announced</small></p>
                    </div>

                    <div>
                        <span class="material-symbols-outlined" aria-hidden="true">location_on</span>
                        <p><strong>Location</strong><small>To be announced</small></p>
                    </div>
                </div>

                <div class="contact-tip">
                    <span class="material-symbols-outlined" aria-hidden="true">info</span>
                    <p>For order concerns, include the order or reference number when available.</p>
                </div>

                <a class="contact-login-link" href="{{ url('/login') }}">Log in for account-specific support ↗</a>
            </aside>
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

<p class="contact-toast" role="status" data-contact-toast hidden></p>
</body>
</html>
