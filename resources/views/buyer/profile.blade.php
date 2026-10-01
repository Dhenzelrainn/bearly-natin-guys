<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile | Bearly</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
    @vite(['resources/css/buyer.css','resources/css/profile.css','resources/js/profile.js'])
</head>
<body class="bh profile-page">
<header class="profile-topbar">
    <a class="profile-brand" href="{{ url('/home') }}">
        <span class="brand-mark">🧸</span><span>bearly</span>
    </a>
    <div class="profile-top-actions">
        <a href="{{ url('/wishlist') }}"><span class="material-symbols-outlined">favorite</span><small>Wishlist</small></a>
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
            <a class="active" href="{{ url('/profile') }}"><span class="material-symbols-outlined">person</span>Profile</a>
            <a href="{{ url('/addresses') }}"><span class="material-symbols-outlined">location_on</span>Addresses</a>
        </nav>
    </aside>

    <section class="profile-card">
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
</main>
<div id="profile-toast" class="profile-toast">Profile saved.</div>
</body>
</html>
