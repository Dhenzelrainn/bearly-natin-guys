<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Seller Dashboard') | Bearly</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/seller.css', 'resources/js/seller.js'])
</head>
<body class="seller-body">
@php
    $sellerNav = [
        ['label' => 'Dashboard', 'icon' => 'house', 'route' => 'seller.dashboard'],
        ['label' => 'My Store', 'icon' => 'store', 'route' => 'seller.store'],
        ['label' => 'Products', 'icon' => 'package', 'route' => 'seller.products'],
        ['label' => 'Orders', 'icon' => 'shopping-bag'],
        ['label' => 'Inventory', 'icon' => 'package'],
        ['label' => 'Deliveries', 'icon' => 'truck'],
        ['label' => 'Reports', 'icon' => 'chart-no-axes-combined'],
        ['label' => 'Messages', 'icon' => 'message-circle-more'],
        ['label' => 'Account', 'icon' => 'circle-user-round'],
    ];
@endphp
<div class="seller-shell" data-seller-shell>
    <aside class="seller-sidebar" data-seller-sidebar>
    <a
    href="{{ route('seller.dashboard') }}"
    class="seller-brand"
    aria-label="Bearly Seller Center"
>
    <img
        src="{{ asset('images/bearly-logo.png') }}"
        alt="Bearly"
        class="seller-brand-logo"
    >

    <span class="seller-brand-subtitle">
        Seller Center
    </span>
</a>
        <nav class="seller-nav" aria-label="Seller navigation">
            @foreach ($sellerNav as $item)
                @php($isActive = isset($item['route']) && request()->routeIs($item['route']))
                <a href="{{ isset($item['route']) ? route($item['route']) : '#' }}"
                   class="seller-nav-link {{ $isActive ? 'is-active' : '' }}"
                   @unless(isset($item['route'])) data-preview-link="{{ $item['label'] }}" @endunless>
                    <i data-lucide="{{ $item['icon'] }}"></i>
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>

        <div class="seller-sidebar-footer">
            <a href="{{ route('login') }}" class="seller-nav-link seller-logout">
                <i data-lucide="log-out"></i><span>Logout</span>
            </a>
        </div>
    </aside>

    <button class="seller-overlay" type="button" data-seller-overlay aria-label="Close menu"></button>

    <div class="seller-main">
        <header class="seller-topbar">
            <div class="seller-topbar-left">
                <button class="seller-icon-button seller-menu-button" type="button" data-seller-menu aria-label="Open seller navigation">
                    <i data-lucide="menu"></i>
                </button>
                <h1>@yield('page-title', 'Seller Dashboard')</h1>
            </div>
            <div class="seller-topbar-actions">
                <div class="seller-popover-wrap">
                    <button class="seller-icon-button notification-trigger" type="button" data-seller-popover-toggle="notifications" aria-label="Open notifications">
                        <i data-lucide="bell"></i><span></span>
                    </button>
                    <div class="seller-popover notification-popover" data-seller-popover="notifications" hidden>
                        <div class="seller-popover-heading"><strong>Notifications</strong><small>{{ count($notifications ?? []) }} new</small></div>
                        @foreach (($notifications ?? []) as $notification)
                            <button type="button" class="seller-notification-row">
                                <span class="notice-dot dot-{{ $notification['type'] }}"></span>
                                <span><strong>{{ $notification['title'] }}</strong><small>{{ $notification['time'] }}</small></span>
                            </button>
                        @endforeach
                    </div>
                </div>
                <span class="topbar-divider"></span>
                <div class="seller-popover-wrap">
                    <button class="seller-profile-button" type="button" data-seller-popover-toggle="profile">
                        <span class="seller-avatar">{{ $seller['initials'] ?? 'BR' }}</span>
                        <span class="seller-profile-name">{{ $seller['name'] ?? 'Bearly Seller' }}</span>
                        <i data-lucide="chevron-down"></i>
                    </button>
                    <div class="seller-popover profile-popover" data-seller-popover="profile" hidden>
                        <strong>{{ $seller['name'] ?? 'Bearly Seller' }}</strong>
                        <small>{{ $seller['email'] ?? 'seller@bearly.test' }}</small>
                        <a href="#" data-preview-link="Account"><i data-lucide="user-round-cog"></i> Account settings</a>
                        <a href="{{ route('login') }}"><i data-lucide="log-out"></i> Logout</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="seller-content">@yield('content')</main>
    </div>
</div>

<div class="seller-toast" data-seller-toast role="status" aria-live="polite"></div>
@if (session('success'))
    <div class="seller-toast is-visible" data-server-toast role="status">{{ session('success') }}</div>
@endif
<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
</body>
</html>
