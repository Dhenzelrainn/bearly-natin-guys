<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Addresses | Bearly</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
@vite(['resources/css/buyer.css','resources/css/addresses.css','resources/js/addresses.js'])
</head>
<body class="bh address-page">
<header class="header">
<a class="brand" href="{{ url('/home') }}"><img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly"></a>
<div class="address-header-spacer"></div>
<nav class="header-actions">
<a href="{{ url('/wishlist') }}"><span class="material-symbols-outlined">favorite</span><span>Wishlist</span></a>
<a href="{{ url('/cart') }}"><span class="material-symbols-outlined">shopping_cart</span><span>Cart</span></a>
<a class="address-active" href="{{ url('/addresses') }}"><span class="material-symbols-outlined">person</span><span>Mia Santos</span></a>
</nav>
</header>

<main class="address-shell">
<a class="address-back" href="{{ url('/home') }}"><span class="material-symbols-outlined">arrow_back</span> Back to Home</a>

<section class="address-panel">
<div class="address-panel-head">
<div><h1>My Addresses</h1><p>Manage your delivery addresses.</p></div>
<button id="add-address" class="add-address-btn" type="button"><span class="material-symbols-outlined">add</span>Add Address</button>
</div>

<div id="address-list" class="address-list"></div>

<div id="address-empty" class="address-empty">
<span class="material-symbols-outlined">location_on</span>
<h2>No addresses yet</h2>
<p>Add a delivery address for checkout.</p>
<button id="empty-add-address" type="button">Add Address</button>
</div>
</section>
</main>

<div id="address-modal" class="address-modal" hidden>
<div class="address-dialog">
<div class="dialog-head">
<h2 id="address-modal-title">New Address</h2>
<button id="close-address-modal" type="button" aria-label="Close">×</button>
</div>
<form id="address-form">
<input id="editing-id" type="hidden">
<div class="form-grid two">
<label><span>Full Name</span><input id="full-name" required placeholder="Mia Santos"></label>
<label><span>Phone Number</span><input id="phone-number" required placeholder="+63 900 000 0000"></label>
</div>
<div class="form-grid">
<label><span>Province</span><input id="province" required placeholder="Laguna"></label>
</div>
<div class="form-grid two">
<label><span>City / Municipality</span><input id="city" required placeholder="City / Municipality"></label>
<label><span>Barangay</span><input id="barangay" required placeholder="Barangay"></label>
</div>
<div class="form-grid">
<label><span>Postal Code</span><input id="postal-code" required placeholder="Postal Code"></label>
<label><span>Street Name, Building, House No.</span><input id="street" required placeholder="Street Name, Building, House No."></label>
</div>

<div class="label-section">Label As:</div>
<div class="label-buttons">
<button class="label-choice active" data-label="Home" type="button">Home</button>
<button class="label-choice" data-label="Work" type="button">Work</button>
</div>

<label class="default-check">
<input id="set-default" type="checkbox"><span></span>Set as Default Address
</label>

<div class="dialog-actions">
<button id="cancel-address" class="cancel-btn" type="button">Cancel</button>
<button class="save-btn" type="submit">Save Address</button>
</div>
</form>
</div>
</div>

<div id="delete-modal" class="address-modal" hidden>
<div class="confirm-dialog">
<span class="material-symbols-outlined">delete</span>
<h2>Delete Address?</h2>
<p>This address will be removed from your saved addresses.</p>
<div>
<button id="cancel-delete" class="cancel-btn" type="button">Cancel</button>
<button id="confirm-delete" class="delete-confirm" type="button">Delete</button>
</div>
</div>
</div>

<div id="address-toast" class="address-toast" role="status"></div>
</body>
</html>
