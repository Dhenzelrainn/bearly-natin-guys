<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title','Rider Center') | Bearly Marketplace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/rider.css','resources/css/messages-standard.css','resources/css/maps.css','resources/css/waybill-scanner.css','resources/js/messages-standard.js','resources/js/realtime.js','resources/js/waybill-scanner.js','resources/js/destination-map.js','resources/js/rider.js'])
    @include('partials.session-safety')
    @stack('styles')
</head>
<body class="ops-body" data-module="rider" data-realtime-user-id="{{ auth()->id() }}">
<div class="ops-shell">
    <aside class="ops-sidebar" aria-label="Rider navigation">
        <div class="ops-sidebar-header">
            <button class="ops-menu-button ops-collapse" type="button" data-sidebar-collapse aria-label="Toggle sidebar"><i data-lucide="menu"></i></button>
            <a href="{{ route('rider.dashboard.deliveries') }}" class="ops-brand"><img src="{{ asset('images/bearly-logo-2.png') }}" alt="Bearly"><span class="ops-brand-subtitle">Rider Center</span></a>
        </div>
        <nav class="ops-nav">
            <p class="ops-nav-label">Workspace</p>
            <a href="{{ route('rider.dashboard.pickups') }}" class="ops-nav-link {{ request()->routeIs('rider.dashboard.pickups','rider.orders.pickup*') ? 'is-active' : '' }}"><i data-lucide="package-plus"></i><span>Items for Pickup</span><span class="nav-count">3</span></a>
            <a href="{{ route('rider.dashboard.deliveries') }}" class="ops-nav-link {{ request()->routeIs('rider.dashboard.deliveries','rider.orders.delivery*') ? 'is-active' : '' }}"><i data-lucide="bike"></i><span>Items for Delivery</span><span class="nav-count">3</span></a>
            <a href="{{ route('rider.refund-cash.index') }}" class="ops-nav-link {{ request()->routeIs('rider.refund-cash.*') ? 'is-active' : '' }}"><i data-lucide="hand-coins"></i><span>Cash custody</span></a>
            <p class="ops-nav-label">Finance & records</p>
            <a href="{{ route('rider.earnings.index') }}" class="ops-nav-link {{ request()->routeIs('rider.earnings.*') ? 'is-active' : '' }}"><i data-lucide="wallet-cards"></i><span>Earnings</span></a>
            <a href="{{ route('rider.history.index') }}" class="ops-nav-link {{ request()->routeIs('rider.history.*') ? 'is-active' : '' }}"><i data-lucide="history"></i><span>Delivery History</span></a>
            <p class="ops-nav-label">Support</p>
            <a href="{{ route('rider.messages.index') }}" class="ops-nav-link {{ request()->routeIs('rider.messages.*') ? 'is-active' : '' }}"><i data-lucide="message-circle"></i><span>Messages</span><span class="nav-count">2</span></a>
            <a href="{{ route('notifications.center') }}" class="ops-nav-link {{ request()->routeIs('notifications.center') ? 'is-active' : '' }}"><i data-lucide="bell"></i><span>Notifications</span></a>
            <a href="{{ route('rider.profile.index') }}" class="ops-nav-link {{ request()->routeIs('rider.profile.*') ? 'is-active' : '' }}"><i data-lucide="user-round-cog"></i><span>Account</span></a>
        </nav>
        <div class="ops-sidebar-footer">
            <div class="facility-chip"><i data-lucide="warehouse"></i><span><small>Assigned logistics</small><strong>Laguna Central</strong></span></div>
            <form method="POST" action="{{ route('logout') }}" style="display: contents">@csrf<button type="submit" class="ops-nav-link ops-logout"><i data-lucide="log-out"></i><span>Logout</span></button></form>
        </div>
    </aside>
    <button class="ops-overlay" type="button" data-overlay aria-label="Close navigation"></button>
    <div class="ops-main">
        <header class="ops-topbar">
            <div class="ops-topbar-left"><button class="icon-button mobile-menu" type="button" data-mobile-menu aria-label="Open navigation"><i data-lucide="menu"></i></button><h1>@yield('page-title','Rider Center')</h1></div>
            <div class="ops-topbar-actions">
                <div class="popover-wrap" data-notification-center data-notification-skin="rider">
                    <button class="icon-button notification-trigger" type="button" data-popover-toggle="notifications" aria-label="Open notifications"><i data-lucide="bell"></i><span class="notification-dot" data-notification-badge="dot" hidden></span></button>
                    <div class="topbar-popover notification-popover" data-popover="notifications" hidden>
                        <div class="popover-heading"><div><strong>Notifications</strong><span data-notification-summary>Recent updates</span></div></div>
                        <div data-notification-list></div>
                        <p class="empty-copy" data-notification-empty hidden>No new notifications.</p>
                        <div class="popover-footer">
                            <button type="button" class="notification-mark-all" data-notification-mark-all hidden><i data-lucide="mail-check"></i>Mark all as read</button>
                            <a class="notification-view-all" href="{{ route('notifications.center') }}">View all notifications<i data-lucide="arrow-right"></i></a>
                        </div>
                    </div>
                </div>
                <span class="topbar-divider"></span>
                <div class="popover-wrap"><button class="profile-button" type="button" data-popover-toggle="profile"><span class="avatar">{{ $rider['initials'] ?? 'BR' }}</span><span class="profile-copy"><strong>{{ $rider['name'] ?? 'Bearly Rider' }}</strong><small>{{ $rider['role'] ?? 'Rider' }}</small></span><i data-lucide="chevron-down"></i></button><div class="topbar-popover profile-popover" data-popover="profile" hidden><a href="{{ route('rider.profile.index') }}"><i data-lucide="user-round-cog"></i>Account settings</a><form method="POST" action="{{ route('logout') }}" style="display: contents">@csrf<button type="submit"><i data-lucide="log-out"></i>Logout</button></form></div></div>
            </div>
        </header>
        <main class="ops-content">
            @if(session('success') || session('job_status'))<div class="flash-banner is-success"><i data-lucide="circle-check"></i><span>{{ session('success') ?? session('job_status') }}</span><button type="button" data-dismiss><i data-lucide="x"></i></button></div>@endif
            @yield('content')
        </main>
        <footer class="ops-footer"><span>© {{ date('Y') }} Bearly Marketplace</span><span>Rider operations preview</span></footer>
    </div>
</div>
<div class="modal-backdrop" data-modal-backdrop hidden></div><div class="toast-stack" data-toast-stack aria-live="polite"></div>
<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
@stack('scripts')
</body></html>
