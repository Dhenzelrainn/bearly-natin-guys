<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile | Bearly</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
    @vite(['resources/css/buyer.css','resources/css/profile.css','resources/css/addresses.css','resources/css/bearly-chat.css','resources/js/profile.js','resources/js/account-addresses.js','resources/js/my-likes.js','resources/js/my-purchases.js','resources/js/reviews-ratings.js','resources/js/bearly-chat.js'])
</head>
<body class="bh profile-page">
<header class="profile-topbar">
    <a class="profile-brand" href="{{ url('/home') }}">
        <span class="brand-mark">🧸</span><span>bearly</span>
    </a>
    <div class="profile-top-actions">
        <a href="{{ url('/profile#notifications') }}" class="notification-header-link"><span class="material-symbols-outlined">notifications</span><small>Notifications</small><span class="notification-badge" data-notification-badge>3</span></a>
        <a href="{{ url('/cart') }}"><span class="material-symbols-outlined">shopping_cart</span><small>Cart</small></a>
        <a class="active" href="{{ url('/profile') }}"><span class="material-symbols-outlined">person</span><small>Mia Santos</small></a>
    </div>
</header>

<main class="account-layout">
    <aside class="account-sidebar">
        <div class="account-user">
            <div id="sidebar-avatar" class="sidebar-avatar"><span class="material-symbols-outlined">person</span></div>
            <div><strong id="sidebar-name">Mia Santos</strong><span>Demo Buyer</span></div>
        </div>
        <div class="sidebar-divider"></div>
        <div class="sidebar-title"><span class="material-symbols-outlined">account_circle</span> My Account</div>
        <nav class="account-nav">
            <button class="account-tab active" type="button" data-account-tab="profile"><span class="material-symbols-outlined">person</span>Profile</button>
            <button class="account-tab" type="button" data-account-tab="addresses"><span class="material-symbols-outlined">location_on</span>Addresses</button>
            <button class="account-tab" type="button" data-account-tab="likes"><span class="material-symbols-outlined">favorite</span>My Likes <span id="likes-nav-count" class="likes-nav-count">0</span></button>
            <div class="nav-section-gap"></div>
            <button class="account-tab nav-parent" type="button" data-account-tab="purchases"><span class="material-symbols-outlined">receipt_long</span>My Purchases</button>
            <button class="account-tab nav-parent" type="button" data-account-tab="history"><span class="material-symbols-outlined">history</span>Order History</button>
            <button class="account-tab nav-parent" type="button" data-account-tab="reviews"><span class="material-symbols-outlined">star</span>Reviews & Ratings</button>
            <button class="account-tab nav-parent" type="button" data-account-tab="notifications"><span class="material-symbols-outlined">notifications</span>Notifications <span id="notifications-nav-count" class="notifications-nav-count">3</span></button>
            <button class="account-tab nav-parent" type="button" data-account-tab="vouchers"><span class="material-symbols-outlined">confirmation_number</span>My Vouchers <span id="vouchers-nav-count" class="vouchers-nav-count" hidden>0</span></button>
            <button class="account-tab nav-parent" type="button" data-account-tab="help"><span class="material-symbols-outlined">help</span>Help Center</button>
        </nav>
    </aside>

    <section class="profile-card account-panel" id="profile-panel" data-account-panel="profile">
        <div class="profile-heading">
            <h1>My Profile</h1>
            <p>Manage your Bearly buyer account information.</p>
        </div>

        <form id="profile-form" class="profile-form">
            <div class="profile-fields">
                <label class="field-row">
                    <span>Username</span>
                    <input id="username" type="text" value="miasantos" maxlength="30">
                </label>
                <label class="field-row">
                    <span>Full Name</span>
                    <input id="full-name" type="text" value="Mia Santos" required>
                </label>
                <label class="field-row">
                    <span>Email</span>
                    <input id="email" type="email" value="mia.santos@example.com" required>
                </label>
                <label class="field-row">
                    <span>Phone Number</span>
                    <input id="phone" type="tel" placeholder="+63 900 000 0000">
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

                <div class="profile-buttons">
                    <button id="edit-profile" class="secondary-btn" type="button">Edit Profile</button>
                    <button id="save-profile" class="primary-btn" type="submit">Save Changes</button>
                </div>
            </div>

            <div class="photo-panel">
                <div id="profile-avatar" class="profile-avatar"><span class="material-symbols-outlined">person</span></div>
                <input id="photo-input" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" hidden>
                <button id="select-photo" type="button" class="photo-btn">Select Image</button>
                <p>File size: maximum 1 MB<br>File extension: .JPEG, .PNG</p>
            </div>
        </form>
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

    <section class="profile-card account-panel" id="likes-panel" data-account-panel="likes" hidden>
        <div class="likes-panel-head">
            <div><h1>My Likes</h1><p>Products you liked across Bearly. This uses the same hearts as your Wishlist.</p></div>
            <span id="likes-count" class="likes-count">0 liked items</span>
        </div>
        <div id="likes-grid" class="likes-grid"></div>
        <div id="likes-empty" class="likes-empty">
            <span class="material-symbols-outlined">favorite</span><h2>No liked products yet</h2><p>Tap the heart on a product and it will appear here automatically.</p>
            <a href="{{ url('/home') }}">Browse Products</a>
        </div>
    </section>

    <section class="profile-card account-panel" id="purchases-panel" data-account-panel="purchases" hidden>
        <div class="purchases-head">
            <div><h1>My Purchases</h1><p>View your orders and follow each purchase from payment to completion.</p></div>
            <span id="purchase-count" class="purchase-count">0 orders</span>
        </div>
        <div class="purchase-tabs" role="tablist">
            <button class="purchase-filter active" type="button" data-order-filter="all">All</button>
            <button class="purchase-filter" type="button" data-order-filter="to-pay">To Pay</button>
            <button class="purchase-filter" type="button" data-order-filter="to-ship">To Ship</button>
            <button class="purchase-filter" type="button" data-order-filter="to-receive">To Receive</button>
            <button class="purchase-filter" type="button" data-order-filter="completed">Completed</button>
            <button class="purchase-filter" type="button" data-order-filter="cancelled">Cancelled</button>
        </div>
        <div id="purchase-list" class="purchase-list"></div>
        <div id="purchases-empty" class="purchases-empty" hidden>
            <span class="material-symbols-outlined">shopping_bag</span><h2>No orders here yet</h2><p>Your purchases under this status will appear here.</p>
            <a href="{{ url('/home') }}">Start Shopping</a>
        </div>
    </section>

    <section class="profile-card account-panel" id="history-panel" data-account-panel="history" hidden>
        <div class="history-head">
            <div><h1>Order History</h1><p>Review your completed and cancelled Bearly orders.</p></div>
            <span id="history-count" class="purchase-count">0 orders</span>
        </div>
        <div class="history-tools">
            <label class="history-search"><span class="material-symbols-outlined">search</span><input id="history-search" type="search" placeholder="Search order ID or product" autocomplete="off"></label>
            <div class="history-filters" role="tablist" aria-label="Order history filters">
                <button class="history-filter active" type="button" data-history-filter="all">All</button>
                <button class="history-filter" type="button" data-history-filter="completed">Completed</button>
                <button class="history-filter" type="button" data-history-filter="cancelled">Cancelled</button>
            </div>
        </div>
        <div id="history-list" class="purchase-list"></div>
        <div id="history-empty" class="purchases-empty" hidden>
            <span class="material-symbols-outlined">history</span><h2>No order history found</h2><p>Completed and cancelled orders will appear here.</p>
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
            <div><h1>Notifications</h1><p>Order, payment, and Bearly account updates in one place.</p></div>
            <button id="mark-all-notifications" type="button" class="notification-mark-all">Mark all as read</button>
        </div>
        <div class="notification-filters" role="tablist" aria-label="Notification filters">
            <button class="notification-filter active" type="button" data-notification-filter="all">All</button>
            <button class="notification-filter" type="button" data-notification-filter="orders">Order Updates</button>
            <button class="notification-filter" type="button" data-notification-filter="payment">Payment</button>
            <button class="notification-filter" type="button" data-notification-filter="promos">Promos</button>
        </div>
        <div id="notification-list" class="notification-list"></div>
        <div id="notifications-empty" class="notifications-empty" hidden>
            <span class="material-symbols-outlined">notifications_off</span><h2>No notifications here</h2><p>New Bearly updates will appear here.</p>
        </div>
    </section>

    <section class="profile-card account-panel" id="vouchers-panel" data-account-panel="vouchers" hidden>
        <div class="vouchers-head">
            <div><h1>My Vouchers</h1><p>Claim Bearly rewards and use them on eligible orders.</p></div>
            <span id="voucher-claimed-count" class="voucher-claimed-count">0 claimed</span>
        </div>
        <div class="voucher-tabs" role="tablist" aria-label="Voucher filters">
            <button class="voucher-filter active" type="button" data-voucher-filter="available">Available</button>
            <button class="voucher-filter" type="button" data-voucher-filter="claimed">Claimed</button>
        </div>
        <div id="voucher-list" class="voucher-list"></div>
        <div id="vouchers-empty" class="vouchers-empty" hidden><span class="material-symbols-outlined">confirmation_number</span><h2>No vouchers here</h2><p>New Bearly rewards will appear here.</p></div>
    </section>

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
            <div><h1>Reviews & Ratings</h1><p>Rate completed purchases and manage reviews you already submitted.</p></div>
            <span id="reviews-count" class="reviews-count">0 reviews</span>
        </div>
        <div id="review-list" class="review-list"></div>
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
   <div class="form-grid two"><label><span>Full Name</span><input id="address-full-name" required placeholder="Mia Santos"></label><label><span>Phone Number</span><input id="address-phone-number" required placeholder="+63 900 000 0000"></label></div>
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
