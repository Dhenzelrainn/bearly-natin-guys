@php
    $buyer = auth()->user();
    $buyerName = trim($buyer?->name ?: trim(($buyer?->first_name ?? '') . ' ' . ($buyer?->last_name ?? '')));
    $buyerName = $buyerName !== '' ? $buyerName : 'Buyer';
    $buyerEmail = (string) ($buyer?->email ?? '');
    $buyerPhone = (string) ($buyer?->phone ?: $buyer?->contact_number ?: '');
    $buyerUsername = (string) ($buyer?->username ?? (str_contains($buyerEmail, '@') ? strstr($buyerEmail, '@', true) : strtolower(preg_replace('/\s+/', '', $buyerName))));
    $buyerBirthday = $buyer?->birthday ?? $buyer?->birth_date;
    $buyerBirthdayValue = $buyerBirthday?->format('Y-m-d') ?? '';
    $buyerSex = (string) ($buyer?->sex ?? '');
    $buyerProfilePayload = json_encode(['username' => $buyerUsername, 'fullName' => $buyerName, 'email' => $buyerEmail, 'phone' => $buyerPhone, 'gender' => $buyerSex, 'birthday' => $buyerBirthdayValue], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chat | Bearly</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Material+Symbols+Outlined:wght@400&display=swap" rel="stylesheet">
    @include('buyer.partials.account-context-script')
    @vite(['resources/css/buyer.css','resources/css/bearly-chat.css','resources/js/bearly-chat.js'])
    @include('partials.session-safety')
</head>
<body class="bh bearly-chat-page">
<header class="header buyer-standard-header">
    <a class="brand" href="{{ url('/home') }}" aria-label="Bearly home"><img src="{{ asset('images/bearly-logo-2.png') }}" alt="Bearly"></a>
    <div class="buyer-header-spacer"></div>
    <nav class="header-actions" aria-label="Account">
        <a href="{{ url('/profile#notifications') }}"><span class="material-symbols-outlined">notifications</span><span>Notifications</span></a>
        <a href="{{ url('/profile#tracking') }}"><span class="material-symbols-outlined">receipt_long</span><span>Orders</span></a>
        <a class="active" href="{{ url('/chat') }}"><span class="material-symbols-outlined">chat_bubble</span><span>Chat</span></a>
        <a href="{{ url('/cart/preview') }}"><span class="material-symbols-outlined">shopping_cart</span><span>Cart preview</span></a>
        <a href="{{ url('/profile') }}"><span class="material-symbols-outlined">person</span><span>{{ $buyerName }}</span></a>
    </nav>
</header>
<main class="bearly-chat-page-shell" data-bearly-chat-root data-chat-mode="page">
    <aside class="bearly-chat-page-sidebar">
        <div class="bearly-chat-page-title"><div><span>Messages</span><h1>Chat</h1><small class="buyer-preview-note">Frontend conversation preview</small></div><span class="material-symbols-outlined">forum</span></div>
        <label class="bearly-chat-search"><span class="material-symbols-outlined">search</span><input type="search" data-chat-search placeholder="Search conversations..."></label>
        <div class="bearly-chat-list" data-chat-list></div>
    </aside>
    <section class="bearly-chat-thread bearly-chat-page-thread">
        <div class="bearly-chat-thread-head" data-chat-thread-head></div>
        <div class="bearly-chat-messages" data-chat-messages></div>
        <div class="bearly-chat-quick" data-chat-quick></div>
        <form class="bearly-chat-composer" data-chat-form><input type="text" data-chat-input placeholder="Write a message..." autocomplete="off" maxlength="240"><button type="submit" aria-label="Send message"><span class="material-symbols-outlined">send</span></button></form>
    </section>
</main>
</body>
</html>
