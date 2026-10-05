<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile | Bearly</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
    @include('buyer.partials.account-context-script')
    @vite(['resources/css/buyer.css','resources/css/profile.css','resources/css/addresses.css','resources/css/bearly-chat.css','resources/js/profile.js','resources/js/account-addresses.js','resources/js/my-likes.js','resources/js/my-purchases.js','resources/js/reviews-ratings.js','resources/js/bearly-chat.js'])
</head>
<body class="bh profile-page">
@include('buyer.partials.buyer-header', [
    'headerSearchFormId' => 'profile-search-form',
    'headerSearchInputId' => 'profile-search-input',
    'headerSearchLabel' => 'Search Bearly',
    'headerActive' => 'profile',
    'headerAvatarId' => 'navbar-avatar',
])

<main class="account-layout">
    <aside class="account-sidebar">
        <div class="account-user">
            <div id="sidebar-avatar" class="sidebar-avatar"><span class="material-symbols-outlined">person</span></div>
            <div>
                <strong id="sidebar-name">{{ $buyerName }}</strong>
                <span>Buyer account</span>
            </div>
            <span class="material-symbols-outlined account-user-chevron" aria-hidden="true">chevron_right</span>
        </div>
        <div class="sidebar-divider"></div>
        <div class="sidebar-title"><span class="material-symbols-outlined">account_circle</span> My Account</div>
        <nav class="account-nav">
            <button class="account-tab active" type="button" data-account-tab="profile"><span class="material-symbols-outlined">person</span>Profile</button>
            <button class="account-tab" type="button" data-account-tab="addresses"><span class="material-symbols-outlined">location_on</span>Addresses</button>
            <button class="account-tab" type="button" data-account-tab="likes"><span class="material-symbols-outlined">favorite</span>My Likes <span id="likes-nav-count" class="likes-nav-count">0</span></button>
            <div class="nav-section-gap"></div>
            <button class="account-tab nav-parent" type="button" data-account-tab="purchases"><span class="material-symbols-outlined">receipt_long</span>My Purchases</button>
            <button class="account-tab nav-parent" type="button" data-account-tab="reviews"><span class="material-symbols-outlined">star</span>Reviews & Ratings</button>
            <button class="account-tab nav-parent" type="button" data-account-tab="notifications"><span class="material-symbols-outlined">notifications</span>Notifications <span id="notifications-nav-count" class="notifications-nav-count">0</span></button>
            <button class="account-tab nav-parent" type="button" data-account-tab="vouchers"><span class="material-symbols-outlined">confirmation_number</span>My Vouchers <span id="vouchers-nav-count" class="vouchers-nav-count" hidden>0</span></button>
            <button class="account-tab nav-parent" type="button" data-account-tab="help"><span class="material-symbols-outlined">help</span>Help Center</button>
        </nav>
        <form class="account-logout-form" method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit"><span class="material-symbols-outlined" aria-hidden="true">logout</span>Log out</button>
        </form>
    </aside>

    <section class="profile-overview account-panel" id="profile-panel" data-account-panel="profile">
        <div class="profile-page-heading">
            <h1>My Profile</h1>
            <p>Manage your Bearly buyer account information.</p>
        </div>

        <div class="profile-card profile-form-card">
            <div class="profile-section-heading">
                <h2>Personal Information</h2>
                <p>Keep your information up to date for a smoother shopping experience.</p>
            </div>

            <form id="profile-form" class="profile-form">
                <div class="profile-fields">
                <label class="field-row">
                    <span>Username</span>
                    <input id="username" type="text" value="{{ $buyerUsername }}" maxlength="30" readonly>
                </label>
                <label class="field-row">
                    <span>Full Name</span>
                    <input id="full-name" type="text" value="{{ $buyerName }}" required>
                </label>
                <label class="field-row">
                    <span>Email</span>
                    <input id="email" type="email" value="{{ $buyerEmail }}" readonly>
                </label>
                <label class="field-row">
                    <span>Phone Number</span>
                    <input id="phone" type="tel" inputmode="numeric" autocomplete="tel" placeholder="+63 9123456789 or 09123456789">
                </label>

                <div class="field-row">
                    <span>Gender</span>
                    <div class="radio-row">
                        <label><input type="radio" name="gender" value="Male"> Male</label>
                        <label><input type="radio" name="gender" value="Female"> Female</label>
                        <label><input type="radio" name="gender" value="Other"> Other</label>
                    </div>
                </div>

                <div class="field-row">
                    <span>Birthday</span>
                    <div class="birthday-row">
                        <select id="birth-month"><option value="">Month</option></select>
                        <select id="birth-day"><option value="">Day</option></select>
                        <select id="birth-year"><option value="">Year</option></select>
                    </div>
                </div>
                </div>

                <div class="photo-panel">
                    <h2 class="photo-title">Profile Photo</h2>
                    <p class="photo-description">Add a profile photo to personalize your account.</p>
                    <button id="profile-avatar" class="profile-avatar profile-avatar-button" type="button" aria-label="Change profile photo"><span class="material-symbols-outlined">person</span></button>
                    <input id="photo-input" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" hidden>
                    <div class="photo-actions">
                        <button id="select-photo" type="button" class="photo-btn">Change Photo</button>
                        <button id="remove-photo" type="button" class="photo-remove-btn">Remove</button>
                    </div>
                    <p class="photo-help">Maximum 5 MB<br>JPG, JPEG, PNG or WEBP</p>
                </div>

                <div class="profile-actions">
                    <button id="edit-profile" class="secondary-btn" type="button">Edit Profile</button>
                    <button id="save-profile" class="primary-btn" type="submit">Save Changes</button>
                </div>
            </form>
        </div>
    </section>


    <section class="profile-card account-panel" id="addresses-panel" data-account-panel="addresses" hidden>
        <div class="address-panel-head">
            <div><h1>My Addresses</h1><p>Manage your delivery addresses without leaving your Buyer Account.</p></div>
            <button id="add-address" class="add-address-btn" type="button"><span class="material-symbols-outlined">add</span>Add Address</button>
        </div>
        <div id="address-list" class="address-list"></div>
        <div id="address-empty" class="address-empty">
            <span class="material-symbols-outlined">location_on</span><h2>No addresses yet</h2><p>Add a delivery address for checkout.</p>
            <button id="empty-add-address" type="button">Add Address</button>
        </div>
    </section>

    <section class="profile-card account-panel likes-panel" id="likes-panel" data-account-panel="likes" hidden>
        <div class="likes-panel-head">
            <div class="likes-heading-copy">
                <div class="likes-title-row">
                    <h1>My Likes</h1>
                    <span id="likes-count" class="likes-count-badge">0 liked items</span>
                </div>
                <p>Saved products you want to come back to.</p>
            </div>
            <a class="likes-continue" href="{{ url('/home') }}"><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>Continue Shopping</a>
        </div>

        <div class="likes-toolbar">
            <div id="likes-category-tabs" class="likes-category-tabs" role="tablist" aria-label="Filter liked products"></div>
            <label class="likes-sort" for="likes-sort">
                <span class="material-symbols-outlined" aria-hidden="true">sort</span>
                <span>Sort:</span>
                <select id="likes-sort">
                    <option value="recent">Recently liked</option>
                    <option value="price-low">Price: low to high</option>
                    <option value="price-high">Price: high to low</option>
                    <option value="name">Name: A to Z</option>
                    <option value="rating">Top rated</option>
                </select>
                <span class="material-symbols-outlined likes-sort-chevron" aria-hidden="true">expand_more</span>
            </label>
        </div>

        <div id="likes-grid" class="likes-grid"></div>
        <div id="likes-empty" class="likes-empty">
            <span class="material-symbols-outlined" aria-hidden="true">favorite_border</span>
            <h2 id="likes-empty-title">No liked products yet</h2>
            <p id="likes-empty-copy">Tap the heart on a product and it will appear here automatically.</p>
            <a class="likes-empty-action" href="{{ url('/home') }}">Browse Products</a>
        </div>

        <section id="likes-recommendations" class="likes-recommendations" aria-labelledby="likes-recommendations-title">
            <div class="likes-recommendations-head">
                <div><h2 id="likes-recommendations-title">You may also like</h2><p>More great finds based on your likes.</p></div>
                <a href="{{ url('/home#results') }}">See more <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span></a>
            </div>
            <div id="likes-recommendation-grid" class="likes-recommendation-grid"></div>
        </section>
    </section>

    <section class="profile-card account-panel" id="purchases-panel" data-account-panel="purchases" hidden>
        <div class="purchases-head">
            <div class="purchases-heading-copy">
                <div class="purchases-title-row"><h1>My Purchases</h1><span id="purchase-count" class="purchase-count">0 orders</span></div>
            </div>
        </div>
        <div class="purchase-toolbar">
            <div class="purchase-tabs" role="tablist" aria-label="Purchase status filters">
                <button class="purchase-filter active" type="button" data-order-filter="all" aria-selected="true"><span>All</span> <span class="purchase-filter-count" data-filter-count="all">(0)</span></button>
                <button class="purchase-filter" type="button" data-order-filter="to-pay" aria-selected="false"><span>To Pay</span> <span class="purchase-filter-count" data-filter-count="to-pay">(0)</span></button>
                <button class="purchase-filter" type="button" data-order-filter="to-ship" aria-selected="false"><span>To Ship</span> <span class="purchase-filter-count" data-filter-count="to-ship">(0)</span></button>
                <button class="purchase-filter" type="button" data-order-filter="to-receive" aria-selected="false"><span>To Receive</span> <span class="purchase-filter-count" data-filter-count="to-receive">(0)</span></button>
                <button class="purchase-filter" type="button" data-order-filter="completed" aria-selected="false"><span>Completed</span> <span class="purchase-filter-count" data-filter-count="completed">(0)</span></button>
                <button class="purchase-filter" type="button" data-order-filter="cancelled" aria-selected="false"><span>Cancelled</span> <span class="purchase-filter-count" data-filter-count="cancelled">(0)</span></button>
            </div>
            <label class="purchase-sort" for="purchase-sort">
                <span class="sr-only">Sort purchases</span>
                <select id="purchase-sort">
                    <option value="recent">Most Recent</option>
                    <option value="oldest">Oldest</option>
                    <option value="high">Amount: High to Low</option>
                    <option value="low">Amount: Low to High</option>
                </select>
                <span class="material-symbols-outlined" aria-hidden="true">expand_more</span>
            </label>
        </div>
        <div id="purchase-list" class="purchase-list"></div>
        <div id="purchases-empty" class="purchases-empty" hidden>
            <span class="material-symbols-outlined">shopping_bag</span><h2>No orders here yet</h2><p>Your purchases under this status will appear here.</p>
            <a href="{{ url('/home') }}">Start Shopping</a>
        </div>
    </section>

    <section class="profile-card account-panel" id="tracking-panel" data-account-panel="tracking" hidden>
        <div class="tracking-head">
            <div><button id="tracking-back" class="tracking-back" type="button"><span class="material-symbols-outlined">arrow_back</span> My Purchases</button><h1>Order Tracking</h1><p>Follow your order from confirmation until delivery.</p></div>
            <span id="tracking-order-id" class="tracking-order-id"></span>
        </div>
        <div id="tracking-content"></div>
    </section>

    <section class="profile-card account-panel" id="notifications-panel" data-account-panel="notifications" hidden>
        <div class="notifications-head">
            <div><h1>Notifications</h1><p>Stay updated with your orders, payments, promotions, and more.</p></div>
            <button id="mark-all-notifications" type="button" class="notification-mark-all"><span class="material-symbols-outlined" aria-hidden="true">mark_email_read</span><span>Mark all as read</span></button>
        </div>
        <div class="notification-filters" role="tablist" aria-label="Notification filters">
            <button class="notification-filter active" type="button" data-notification-filter="all" aria-selected="true">All</button>
            <button class="notification-filter" type="button" data-notification-filter="orders" aria-selected="false">Order Updates</button>
            <button class="notification-filter" type="button" data-notification-filter="payment" aria-selected="false">Payment</button>
            <button class="notification-filter" type="button" data-notification-filter="promos" aria-selected="false">Promos</button>
        </div>
        <div id="notification-list" class="notification-list"></div>
        <div id="notifications-empty" class="notifications-empty" hidden>
            <span class="material-symbols-outlined">notifications_off</span><h2>No notifications here</h2><p>New Bearly updates will appear here.</p>
        </div>
    </section>

    <section class="profile-card account-panel" id="vouchers-panel" data-account-panel="vouchers" hidden>
        <div class="vouchers-head">
            <div class="vouchers-heading-copy">
                <div class="vouchers-title-row">
                    <img class="vouchers-title-icon" src="{{ asset('images/vouchers/Bearly-voucher-icon.png') }}" alt="">
                    <div>
                        <h1>My Vouchers</h1>
                        <p>Collect platform and shop vouchers and use them on eligible purchases.</p>
                    </div>
                </div>
            </div>
            <span id="voucher-claimed-count" class="voucher-claimed-count">0 claimed</span>
        </div>
        <div class="voucher-banner" aria-label="Bearly voucher promotion">
            <div class="voucher-banner-art">
                <img src="{{ asset('images/vouchers/Bearly-voucher-banner.png') }}" alt="Bearly voucher tickets">
            </div>
            <div class="voucher-banner-copy">
                <h2>Save more with Bearly Vouchers</h2>
                <p>Claim platform or shop discounts now and choose an eligible voucher when you check out.</p>
            </div>
        </div>
        <div class="voucher-content-stack">
        <nav class="voucher-tabs" role="tablist" aria-label="Voucher filters">
            <button class="voucher-filter active" type="button" data-voucher-filter="all" aria-pressed="true">All Vouchers</button>
            <button class="voucher-filter" type="button" data-voucher-filter="shipping" aria-pressed="false">Shipping Vouchers</button>
            <button class="voucher-filter" type="button" data-voucher-filter="discount" aria-pressed="false">Discount Vouchers</button>
            <button class="voucher-filter" type="button" data-voucher-filter="category" aria-pressed="false">Category Vouchers</button>
            <button class="voucher-filter" type="button" data-voucher-filter="shop" aria-pressed="false">Shop Vouchers</button>
            <button class="voucher-filter" type="button" data-voucher-filter="new" aria-pressed="false">New Vouchers</button>
            <button class="voucher-filter" type="button" data-voucher-filter="claimed" aria-pressed="false">Claimed</button>
        </nav>
        <div id="voucher-list" class="voucher-sections"></div>
        <div id="vouchers-empty" class="vouchers-empty" hidden><span class="material-symbols-outlined">confirmation_number</span><h2>No vouchers here</h2><p>New Bearly rewards will appear here.</p></div>
        </div>
    </section>

    <dialog class="voucher-dialog profile-voucher-dialog" id="voucher-terms-dialog" aria-labelledby="voucher-dialog-title">
        <div class="voucher-dialog-head">
            <div>
                <small id="voucher-dialog-type">Bearly Voucher</small>
                <h2 id="voucher-dialog-title">Voucher terms</h2>
            </div>
            <button type="button" class="voucher-dialog-close" data-voucher-dialog-close aria-label="Close voucher terms">
                <span class="material-symbols-outlined" aria-hidden="true">close</span>
            </button>
        </div>
        <p id="voucher-dialog-copy"></p>
        <button type="button" class="voucher-dialog-done" data-voucher-dialog-close>Got it</button>
    </dialog>

    <div id="voucher-toast" class="voucher-toast" role="status" aria-live="polite"></div>

    <section class="profile-card account-panel" id="help-panel" data-account-panel="help" hidden>
        <div class="help-head">
            <div><h1>Help Center</h1><p>Find quick answers about shopping with Bearly.</p></div>
            <a class="help-chat-bearly" href="{{ url('/chat?conversation=bearly') }}" title="Chat with Bearly Assistant"><span class="material-symbols-outlined help-head-icon">support_agent</span><span>Chat with Bearly</span></a>
        </div>
        <label class="help-search"><span class="material-symbols-outlined">search</span><input id="help-search" type="search" placeholder="Search orders, shipping, vouchers, account..." autocomplete="off"></label>
        <div class="help-categories" aria-label="Help categories">
            <button class="help-category active" type="button" data-help-category="all">All</button>
            <button class="help-category" type="button" data-help-category="orders">Orders</button>
            <button class="help-category" type="button" data-help-category="shipping">Shipping</button>
            <button class="help-category" type="button" data-help-category="payments">Payments</button>
            <button class="help-category" type="button" data-help-category="returns">Returns & Refunds</button>
            <button class="help-category" type="button" data-help-category="account">Account</button>
            <button class="help-category" type="button" data-help-category="vouchers">Vouchers</button>
        </div>
        <div id="help-list" class="help-list"></div>
        <div id="help-empty" class="help-empty" hidden><span class="material-symbols-outlined">search_off</span><h2>No matching help topic</h2><p>Try another keyword or category.</p></div>
    </section>

    <section class="profile-card account-panel" id="reviews-panel" data-account-panel="reviews" hidden>
        <div class="reviews-head">
            <div><h1>Reviews &amp; Ratings</h1><p>Share your experience with products you've purchased.</p></div>
        </div>
        <div class="review-tabs" role="tablist" aria-label="Review status">
            <button class="review-tab active" type="button" data-review-filter="to-review" aria-selected="true">To Review <span id="reviews-to-review-count">(2)</span></button>
            <button class="review-tab" type="button" data-review-filter="reviewed" aria-selected="false">Reviewed <span id="reviews-reviewed-count">(3)</span></button>
        </div>
        <div class="review-sections">
            <section class="review-section" data-review-section="to-review" aria-labelledby="products-to-review-title">
                <div class="review-section-heading">
                    <h2 id="products-to-review-title">Products to Review</h2>
                    <p>Write a review for the products you've received.</p>
                </div>
                <div id="review-list" class="review-list"></div>
            </section>
            <section class="review-section" data-review-section="reviewed" aria-labelledby="your-reviews-title">
                <div class="review-section-heading">
                    <h2 id="your-reviews-title">Your Reviews</h2>
                    <p>Products you've already reviewed.</p>
                </div>
                <div id="reviewed-list" class="review-list"></div>
            </section>
        </div>
        <div id="reviews-empty" class="reviews-empty" hidden>
            <span class="material-symbols-outlined">reviews</span><h2>No reviews yet</h2><p>Completed purchases that you can rate will appear here.</p>
            <button id="reviews-go-purchases" type="button">View My Purchases</button>
        </div>
    </section>


</main>

<div id="review-modal" class="review-modal" hidden>
 <div class="review-dialog">
  <div class="review-dialog-head"><div><h2 id="review-modal-title">Rate Product</h2><p id="review-product-name"></p></div><button id="close-review-modal" type="button" aria-label="Close">×</button></div>
  <form id="review-form">
   <input id="review-order-id" type="hidden"><input id="review-item-index" type="hidden">
   <div class="rating-picker"><span>Your Rating</span><div id="review-stars" class="review-stars" aria-label="Choose rating">
    <button type="button" data-rating="1">★</button><button type="button" data-rating="2">★</button><button type="button" data-rating="3">★</button><button type="button" data-rating="4">★</button><button type="button" data-rating="5">★</button>
   </div><strong id="rating-label">Select a rating</strong></div>
   <label class="review-comment-label"><span>Your Review</span><textarea id="review-comment" maxlength="500" placeholder="Share your experience with this product..." required></textarea><small><span id="review-char-count">0</span>/500</small></label>
   <label class="review-photo-label"><span>Add Product Photo <small>(optional)</small></span><input id="review-photo" type="file" accept="image/png,image/jpeg" hidden><button id="select-review-photo" type="button"><span class="material-symbols-outlined">add_photo_alternate</span>Choose Photo</button><div id="review-photo-preview" class="review-photo-preview" hidden></div></label>
   <div class="review-actions"><button id="cancel-review" class="order-btn" type="button">Cancel</button><button class="order-btn primary" type="submit">Submit Review</button></div>
  </form>
 </div>
</div>
<div id="review-toast" class="profile-toast">Review saved.</div>

<div id="address-modal" class="address-modal" hidden>
 <div class="address-dialog">
  <div class="dialog-head"><h2 id="address-modal-title">New Address</h2><button id="close-address-modal" type="button" aria-label="Close">×</button></div>
  <form id="address-form">
   <input id="address-editing-id" type="hidden">
   <div class="form-grid two"><label><span>Full Name</span><input id="address-full-name" required placeholder="Recipient name"></label><label><span>Phone Number</span><input id="address-phone-number" required placeholder="+63 900 000 0000"></label></div>
   <div class="form-grid"><label><span>Province</span><input id="address-province" required placeholder="Laguna"></label></div>
   <div class="form-grid two"><label><span>City / Municipality</span><input id="address-city" required placeholder="City / Municipality"></label><label><span>Barangay</span><input id="address-barangay" required placeholder="Barangay"></label></div>
   <div class="form-grid"><label><span>Postal Code</span><input id="address-postal-code" required placeholder="Postal Code"></label><label><span>Street Name, Building, House No.</span><input id="address-street" required placeholder="Street Name, Building, House No."></label></div>
   <div class="label-section">Label As:</div><div class="label-buttons"><button class="label-choice active" data-label="Home" type="button">Home</button><button class="label-choice" data-label="Work" type="button">Work</button></div>
   <label class="default-check"><input id="address-set-default" type="checkbox"><span></span>Set as Default Address</label>
   <div class="dialog-actions"><button id="cancel-address" class="cancel-btn" type="button">Cancel</button><button class="save-btn" type="submit">Save Address</button></div>
  </form>
 </div>
</div>
<div id="delete-modal" class="address-modal" hidden><div class="confirm-dialog"><span class="material-symbols-outlined">delete</span><h2>Delete Address?</h2><p>This address will be removed from your saved addresses.</p><div><button id="cancel-delete" class="cancel-btn" type="button">Cancel</button><button id="confirm-delete" class="delete-confirm" type="button">Delete</button></div></div></div>
<div id="address-toast" class="address-toast" role="status"></div>

<div id="profile-toast" class="profile-toast">Profile saved.</div>
</body>
</html>
