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
    @vite([
        'resources/css/seller.css',
        'resources/css/messages-standard.css',
        'resources/css/maps.css',
        'resources/css/seller-polish.css',
        'resources/css/seller-legibility.css',
        'resources/js/seller.js',
        'resources/js/realtime.js',
        'resources/js/seller-store-map.js',
        'resources/js/seller-polish.js'
    ])
    @include('partials.session-safety')
    @stack('styles')
</head>
<body class="seller-body" data-realtime-user-id="{{ auth()->id() }}">
@php
    $sellerNavGroups = [
        ['key' => 'orders', 'label' => 'Orders', 'icon' => 'clipboard-list', 'active' => request()->routeIs('seller.orders*'), 'children' => [
            ['label' => 'Manage Orders', 'route' => 'seller.orders'],
            ['label' => 'Returns & Refunds', 'route' => 'seller.orders.returns'],
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
        ['key' => 'finance', 'label' => 'Finance', 'icon' => 'wallet-cards', 'active' => request()->routeIs('seller.finance*'), 'children' => [
            ['label' => 'Wallet', 'route' => 'seller.finance.wallet'],
            ['label' => 'My Earnings', 'route' => 'seller.finance.earnings'],
            ['label' => 'Transaction History', 'route' => 'seller.finance.transactions'],
            ['label' => 'Bank Accounts', 'route' => 'seller.finance.bank-accounts'],
        ]],
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'chart-no-axes-combined', 'active' => request()->routeIs('seller.reports*'), 'children' => [
            ['label' => 'Reports Overview', 'route' => 'seller.reports'],
            ['label' => 'Sales & Performance', 'route' => 'seller.reports.sales'],
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
        <div class="seller-sidebar-header">
            <button class="seller-sidebar-menu-button" type="button" data-seller-menu aria-label="Collapse seller navigation" aria-expanded="true" title="Toggle sidebar">
                <i class="seller-ui-icon" data-lucide="menu" aria-hidden="true"></i>
            </button>
            <a href="{{ route('seller.dashboard') }}" class="seller-brand" aria-label="Bearly Seller Center">
                <img src="{{ asset('images/bearly-logo-2.png') }}" alt="Bearly" class="seller-brand-logo">
                <span class="seller-brand-subtitle">Seller Center</span>
            </a>
        </div>
        <nav class="seller-nav" aria-label="Seller navigation">
            <a href="{{ route('seller.dashboard') }}" class="seller-nav-link {{ request()->routeIs('seller.dashboard') ? 'is-active' : '' }}" title="Dashboard">
                <i class="seller-ui-icon" data-lucide="house" aria-hidden="true"></i><span>Dashboard</span>
            </a>
            <a href="{{ route('notifications.center') }}" class="seller-nav-link {{ request()->routeIs('notifications.center') ? 'is-active' : '' }}" title="Notifications">
                <i class="seller-ui-icon" data-lucide="bell" aria-hidden="true"></i><span>Notifications</span>
            </a>
            @foreach ($sellerNavGroups as $group)
                <section class="seller-nav-group {{ ($group['active'] ?? false) ? 'is-active' : '' }}" data-seller-nav-group="{{ $group['key'] }}">
                    <div class="seller-nav-link seller-nav-heading" title="{{ $group['label'] }}">
                        <i class="seller-ui-icon" data-lucide="{{ $group['icon'] }}" aria-hidden="true"></i>
                        <span>{{ $group['label'] }}</span>
                    </div>
                    <div class="seller-nav-children">
                        @foreach ($group['children'] as $child)
                            @php($childHref = isset($child['route']) ? route($child['route'], $child['query'] ?? []) : '#')
                            @php($childStatus = $child['query']['status'] ?? null)
                            @php($childActive = isset($child['route']) && (request()->routeIs($child['route']) || ($child['route'] === 'seller.orders.returns' && request()->routeIs('seller.orders.returns.*')) || ($child['route'] === 'seller.products.pricing' && request()->routeIs('seller.products.pricing.*'))) && ($childStatus ? request()->query('status') === $childStatus : true))
                            @php($previewAttr = !isset($child['route']) ? ' data-preview-link="' . e($child['label']) . '"' : '')
                            <a href="{{ $childHref }}" class="seller-nav-child {{ $childActive ? 'is-active' : '' }}" {!! $previewAttr !!}>{{ $child['label'] }}</a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </nav>
        <div class="seller-sidebar-footer">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button
                    type="submit"
                    class="seller-nav-link seller-logout"
                    title="Logout"
                    style="width:100%; border:0; background:transparent; text-align:left; cursor:pointer;"
                >
                    <i class="seller-ui-icon" data-lucide="log-out" aria-hidden="true"></i>
                    <span>Logout</span>
                </button>
            </form>
        </div>
    </aside>
    <button class="seller-overlay" type="button" data-seller-overlay aria-label="Close menu"></button>
    <div class="seller-main">
        <header class="seller-topbar">
            <div class="seller-topbar-left">
                <button class="seller-icon-button seller-mobile-menu-button" type="button" data-seller-mobile-menu aria-label="Open seller navigation" aria-expanded="false" title="Open sidebar">
                    <i class="seller-ui-icon" data-lucide="menu" aria-hidden="true"></i>
                </button>
                <div class="seller-topbar-title">
                    <h1>@yield('page-title', 'Seller Dashboard')</h1>
                    @hasSection('topbar-subtitle')
                        <p class="seller-topbar-subtitle">@yield('topbar-subtitle')</p>
                    @endif
                </div>
            </div>
            <div class="seller-topbar-actions">
                @hasSection('topbar-controls')
                    <div class="seller-topbar-controls">@yield('topbar-controls')</div>
                    <span class="topbar-divider seller-topbar-controls-divider"></span>
                @endif
                <div class="seller-popover-wrap" data-notification-center data-notification-skin="seller">
                    <button class="seller-icon-button notification-trigger" type="button" data-seller-popover-toggle="notifications" aria-label="Open notifications">
                        <i class="seller-ui-icon" data-lucide="bell" aria-hidden="true"></i><span data-notification-badge="dot" hidden></span>
                    </button>
                    <div class="seller-popover notification-popover" data-seller-popover="notifications" hidden>
                        <div class="seller-popover-heading">
                            <div><strong>Notifications</strong><small data-notification-summary>{{ count($notifications ?? []) }} new</small></div>
                        </div>
                        <div data-notification-list></div>
                        <p class="seller-notification-empty" data-notification-empty hidden>No new notifications.</p>
                        <div class="popover-footer">
                            <button type="button" class="notification-mark-all" data-notification-mark-all hidden>
                                <i class="seller-ui-icon" data-lucide="mail-check" aria-hidden="true"></i>
                                Mark all as read
                            </button>
                            <a class="seller-notification-view-all" href="{{ route('notifications.center') }}">
                                View all notifications
                                <i class="seller-ui-icon" data-lucide="arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
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
                        <a href="{{ route('seller.settings.account') }}"><i class="seller-ui-icon" data-lucide="user-round-cog" aria-hidden="true"></i> Account settings</a>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button
                                type="submit"
                                style="width:100%; border:0; background:transparent; cursor:pointer;"
                            >
                                <i class="seller-ui-icon" data-lucide="log-out" aria-hidden="true"></i>
                                Logout
                            </button>
                        </form>
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
@if ($errors->any())
    <div class="seller-toast is-visible" data-server-error role="alert">{{ $errors->first() }}</div>
@endif
<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
@stack('scripts')
</body>
</html>
