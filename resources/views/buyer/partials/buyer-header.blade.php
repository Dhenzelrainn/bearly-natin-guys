@php
    $headerSearchMode = $headerSearchMode ?? 'plain';
    $headerSearchFormId = $headerSearchFormId ?? 'buyer-header-search-form';
    $headerSearchInputId = $headerSearchInputId ?? 'buyer-header-search';
    $headerSearchInputName = $headerSearchInputName ?? 'search';
    $headerSearchAction = $headerSearchAction ?? url('/home');
    $headerSearchPlaceholder = $headerSearchPlaceholder ?? 'Search for products, brands or sellers...';
    $headerSearchLabel = $headerSearchLabel ?? 'Search Bearly';
    $headerSearchCategories = $headerSearchCategories ?? [];
    $headerShowCategorySelect = $headerShowCategorySelect ?? true;
    $headerCategoryLabel = $headerCategoryLabel ?? '';
    $headerCategoryId = $headerCategoryId ?? 'search-category';
    $headerActive = $headerActive ?? '';
    $headerAvatarId = $headerAvatarId ?? '';
    $headerCartBadgeId = $headerCartBadgeId ?? '';
    $headerClass = $headerClass ?? '';
    $headerMobileMenu = $headerMobileMenu ?? false;
    $headerMobileMenuId = $headerMobileMenuId ?? 'menu-toggle';
    $headerMobileMenuLabel = $headerMobileMenuLabel ?? 'Open categories';
    $headerMobileMenuControls = $headerMobileMenuControls ?? 'sidebar';
@endphp

<header class="header buyer-global-header{{ $headerMobileMenu ? ' has-mobile-menu' : '' }} {{ $headerClass }}">
    @if ($headerMobileMenu)
        <button class="icon-button mobile-menu" id="{{ $headerMobileMenuId }}" type="button" aria-label="{{ $headerMobileMenuLabel }}" aria-expanded="false" aria-controls="{{ $headerMobileMenuControls }}">
            <span class="material-symbols-outlined" aria-hidden="true">menu</span>
        </button>
    @endif

    <a class="brand" href="{{ url('/home') }}" aria-label="Bearly home">
        <img src="{{ asset('images/bearly-logo-2.png') }}" alt="Bearly">
    </a>

    @if ($headerSearchMode !== 'none')
        <form
            id="{{ $headerSearchFormId }}"
            class="search buyer-global-search"
            role="search"
            @if ($headerSearchMode !== 'category') action="{{ $headerSearchAction }}" method="get" @endif
        >
            @if ($headerSearchMode === 'category')
                <span>{{ $headerCategoryLabel }}</span>
                <label class="sr-only" for="{{ $headerSearchInputId }}">{{ $headerSearchLabel }}</label>
                <input id="{{ $headerSearchInputId }}" type="search" placeholder="{{ $headerSearchPlaceholder }}" maxlength="120" autocomplete="off">
            @else
                <label class="sr-only" for="{{ $headerSearchInputId }}">{{ $headerSearchLabel }}</label>
                @if ($headerShowCategorySelect)
                    <select id="{{ $headerCategoryId }}" name="category">
                        <option value="">All categories</option>
                        @foreach ($headerSearchCategories as $slug => $category)
                            <option value="{{ $slug }}">{{ is_array($category) ? ($category['name'] ?? $slug) : $category }}</option>
                        @endforeach
                    </select>
                @endif
                <input id="{{ $headerSearchInputId }}" name="{{ $headerSearchInputName }}" type="search" placeholder="{{ $headerSearchPlaceholder }}" maxlength="120" autocomplete="off">
            @endif
            <button type="submit" aria-label="Search"><span class="material-symbols-outlined" aria-hidden="true">search</span></button>
        </form>
    @endif

    <nav class="header-actions" aria-label="Account">
        @include('buyer.partials.buyer-notification-popover')
        <a href="{{ url('/profile#purchases') }}" class="{{ $headerActive === 'orders' ? 'active' : '' }}">
            <span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>
            <span>Orders</span>
        </a>
        <a href="{{ url('/chat') }}" class="{{ $headerActive === 'chat' ? 'active' : '' }}">
            <span class="material-symbols-outlined" aria-hidden="true">chat_bubble</span>
            <span>Chat</span>
        </a>
        <a href="{{ url('/cart') }}" class="{{ $headerActive === 'cart' ? 'active cart-active' : '' }}">
            <span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>
            <span>Cart</span>
            @if ($headerCartBadgeId)
                <b id="{{ $headerCartBadgeId }}" class="cart-badge" hidden aria-label="Cart item count">0</b>
            @endif
        </a>
        <a class="account-action {{ $headerActive === 'profile' ? 'active' : '' }}" href="{{ url('/profile') }}" aria-label="Open {{ $buyerName }} profile">
            <span @if ($headerAvatarId) id="{{ $headerAvatarId }}" @endif class="account-avatar{{ $headerAvatarId ? ' navbar-profile-avatar' : '' }}" aria-hidden="true">
                <span class="material-symbols-outlined">person</span>
            </span>
            <span class="account-name">{{ $buyerName }}</span>
            <span class="material-symbols-outlined account-chevron" aria-hidden="true">expand_more</span>
        </a>
    </nav>
</header>

@vite('resources/js/buyer-notifications.js')
