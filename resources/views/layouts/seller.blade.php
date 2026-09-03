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
    $sellerNavGroups = [
        ['key' => 'orders', 'label' => 'Orders', 'icon' => 'clipboard-list', 'active' => request()->routeIs('seller.orders*'), 'children' => [
            ['label' => 'New Orders', 'route' => 'seller.orders.new'],
            ['label' => 'To Prepare', 'route' => 'seller.orders.prepare'],
            ['label' => 'Ready for Pickup', 'route' => 'seller.orders.ready'],
            ['label' => 'Order History', 'route' => 'seller.orders.history'],
        ]],
        ['key' => 'fulfillment', 'label' => 'Fulfillment', 'icon' => 'truck', 'active' => request()->routeIs('seller.fulfillment*'), 'children' => [
            ['label' => 'Waybills', 'route' => 'seller.fulfillment.waybills'],
            ['label' => 'Pickup Requests', 'route' => 'seller.fulfillment.pickups'],
            ['label' => 'Shipment Tracking', 'route' => 'seller.fulfillment.tracking'],
        ]],
        ['key' => 'products', 'label' => 'Products', 'icon' => 'package', 'active' => request()->routeIs('seller.products*', 'seller.inventory'), 'children' => [
            ['label' => 'Product Management', 'route' => 'seller.products'],
            ['label' => 'Inventory', 'route' => 'seller.inventory'],
            ['label' => 'Pricing & Promotions', 'route' => 'seller.products.pricing'],
        ]],
        ['key' => 'store', 'label' => 'Store', 'icon' => 'store', 'active' => request()->routeIs('seller.store*'), 'children' => [
            ['label' => 'Store Profile', 'route' => 'seller.store'],
            ['label' => 'Store Appearance', 'route' => 'seller.store.appearance'],
            ['label' => 'Publication Settings', 'route' => 'seller.store.publication'],
        ]],
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'chart-no-axes-combined', 'active' => request()->routeIs('seller.reports*'), 'children' => [
            ['label' => 'Sales Report', 'route' => 'seller.reports.sales'],
            ['label' => 'Financial Report', 'route' => 'seller.reports.financial'],
        ]],
        ['key' => 'customer-service', 'label' => 'Customer Service', 'icon' => 'message-circle-more', 'active' => request()->routeIs('seller.support*'), 'children' => [
            ['label' => 'Messages', 'route' => 'seller.support.messages'],
            ['label' => 'Customer Feedback', 'route' => 'seller.support.feedback'],
        ]],
        ['key' => 'settings', 'label' => 'Settings', 'icon' => 'user-round-cog', 'active' => request()->routeIs('seller.settings*'), 'children' => [
            ['label' => 'Account', 'route' => 'seller.settings.account'],
            ['label' => 'Security', 'route' => 'seller.settings.security'],
            ['label' => 'Notifications', 'route' => 'seller.settings.notifications'],
        ]],
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
            <a href="{{ route('seller.dashboard') }}" class="seller-nav-link {{ request()->routeIs('seller.dashboard') ? 'is-active' : '' }}">
                <i class="seller-ui-icon" data-lucide="house" aria-hidden="true"></i><span>Dashboard</span>
            </a>
            @foreach ($sellerNavGroups as $group)
                @php($isOpen = $group['active'] ?? false)
                <section class="seller-nav-group {{ $isOpen ? 'is-open' : '' }}" data-seller-nav-group="{{ $group['key'] }}">
                    <button class="seller-nav-link seller-nav-toggle" type="button" data-seller-nav-toggle aria-expanded="{{ $isOpen ? 'true' : 'false' }}">
                        <i class="seller-ui-icon" data-lucide="{{ $group['icon'] }}" aria-hidden="true"></i>
                        <span>{{ $group['label'] }}</span>
                        <i class="seller-nav-chevron" data-lucide="chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="seller-nav-children" {{ !$isOpen ? 'hidden' : '' }}>
                        @foreach ($group['children'] as $child)
                            @php($childHref = isset($child['route']) ? route($child['route'], $child['query'] ?? []) : '#')
                            @php($childStatus = $child['query']['status'] ?? null)
                            @php($childActive = isset($child['route']) && request()->routeIs($child['route']) && ($childStatus ? request()->query('status') === $childStatus : true))
                            @php($previewAttr = !isset($child['route']) ? ' data-preview-link="' . e($child['label']) . '"' : '')
                            <a href="{{ $childHref }}" class="seller-nav-child {{ $childActive ? 'is-active' : '' }}" {!! $previewAttr !!}>{{ $child['label'] }}</a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </nav>

        <div class="seller-sidebar-footer">
            <a href="{{ route('login') }}" class="seller-nav-link seller-logout">
                <i class="seller-ui-icon" data-lucide="log-out" aria-hidden="true"></i><span>Logout</span>
            </a>
        </div>
    </aside>

    <button class="seller-overlay" type="button" data-seller-overlay aria-label="Close menu"></button>

    <div class="seller-main">
        <header class="seller-topbar">
            <div class="seller-topbar-left">
                <button class="seller-icon-button seller-menu-button" type="button" data-seller-menu aria-label="Open seller navigation">
                    <i class="seller-ui-icon" data-lucide="menu" aria-hidden="true"></i>
                </button>
                <h1>@yield('page-title', 'Seller Dashboard')</h1>
            </div>
            <div class="seller-topbar-actions">
                <div class="seller-popover-wrap">
                    <button class="seller-icon-button notification-trigger" type="button" data-seller-popover-toggle="notifications" aria-label="Open notifications">
                        <i class="seller-ui-icon" data-lucide="bell" aria-hidden="true"></i><span></span>
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
                        <i class="seller-ui-icon" data-lucide="chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="seller-popover profile-popover" data-seller-popover="profile" hidden>
                        <strong>{{ $seller['name'] ?? 'Bearly Seller' }}</strong>
                        <small>{{ $seller['email'] ?? 'seller@bearly.test' }}</small>
                        <a href="#" data-preview-link="Account"><i class="seller-ui-icon" data-lucide="user-round-cog" aria-hidden="true"></i> Account settings</a>
                        <a href="{{ route('login') }}"><i class="seller-ui-icon" data-lucide="log-out" aria-hidden="true"></i> Logout</a>
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
