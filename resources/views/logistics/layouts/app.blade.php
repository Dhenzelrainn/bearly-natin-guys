<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Logistics Center') | Bearly Marketplace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/logistics.css', 'resources/js/logistics.js'])
</head>
<body class="ops-body" data-module="logistics">
<div class="ops-shell">
    <aside class="ops-sidebar" aria-label="Logistics navigation">
        <div class="ops-sidebar-header">
            <button class="ops-menu-button ops-collapse" type="button" data-sidebar-collapse aria-label="Toggle sidebar"><i data-lucide="menu"></i></button>
            <a href="{{ route('logistics.dashboard') }}" class="ops-brand"><img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly"><span class="ops-brand-subtitle">Logistics Center</span></a>
        </div>
        <nav class="ops-nav">
            <p class="ops-nav-label">Overview</p>
            <a href="{{ route('logistics.dashboard') }}" class="ops-nav-link {{ request()->routeIs('logistics.dashboard') ? 'is-active' : '' }}"><i data-lucide="house"></i><span>Dashboard</span></a>
            <p class="ops-nav-label">Operations</p>
            <a href="{{ route('logistics.riders.index') }}" class="ops-nav-link {{ request()->routeIs('logistics.riders.*') ? 'is-active' : '' }}"><i data-lucide="users-round"></i><span>Rider Management</span><span class="nav-count">3</span></a>
            <a href="{{ route('logistics.pickups.index') }}" class="ops-nav-link {{ request()->routeIs('logistics.pickups.*') ? 'is-active' : '' }}"><i data-lucide="package-check"></i><span>Pickup Requests</span><span class="nav-count">2</span></a>
            <a href="{{ route('logistics.sorting.incoming') }}" class="ops-nav-link {{ request()->routeIs('logistics.sorting.incoming') ? 'is-active' : '' }}"><i data-lucide="package-open"></i><span>Incoming Parcels</span></a>
            <a href="{{ route('logistics.sorting.center') }}" class="ops-nav-link {{ request()->routeIs('logistics.sorting.center') ? 'is-active' : '' }}"><i data-lucide="warehouse"></i><span>Sorting Center</span></a>
            <a href="{{ route('logistics.dispatch.index') }}" class="ops-nav-link {{ request()->routeIs('logistics.dispatch.index') ? 'is-active' : '' }}"><i data-lucide="route"></i><span>Delivery Assignment</span></a>
            <a href="{{ route('logistics.dispatch.monitoring') }}" class="ops-nav-link {{ request()->routeIs('logistics.dispatch.monitoring') ? 'is-active' : '' }}"><i data-lucide="map-pinned"></i><span>Delivery Monitoring</span></a>
            <p class="ops-nav-label">Insights & support</p>
            <a href="{{ route('logistics.reports.index') }}" class="ops-nav-link {{ request()->routeIs('logistics.reports.*') ? 'is-active' : '' }}"><i data-lucide="chart-column"></i><span>Reports</span></a>
            <a href="{{ route('logistics.messages.index') }}" class="ops-nav-link {{ request()->routeIs('logistics.messages.*') ? 'is-active' : '' }}"><i data-lucide="message-circle"></i><span>Messages</span><span class="nav-count">2</span></a>
            <p class="ops-nav-label">Settings</p>
            <a href="{{ route('logistics.profile.index') }}" class="ops-nav-link {{ request()->routeIs('logistics.profile.*') ? 'is-active' : '' }}"><i data-lucide="user-round-cog"></i><span>Account</span></a>
        </nav>
        <div class="ops-sidebar-footer">
            <div class="facility-chip"><i data-lucide="building-2"></i><span><small>Active facility</small><strong>Laguna Central</strong></span></div>
            <form method="POST" action="{{ route('logout') }}" style="display: contents">@csrf<button type="submit" class="ops-nav-link ops-logout"><i data-lucide="log-out"></i><span>Logout</span></button></form>
        </div>
    </aside>
    <button class="ops-overlay" type="button" data-overlay aria-label="Close navigation"></button>
    <div class="ops-main">
        <header class="ops-topbar">
            <div class="ops-topbar-left"><button class="icon-button mobile-menu" type="button" data-mobile-menu aria-label="Open navigation"><i data-lucide="menu"></i></button><h1>@yield('page-title', 'Logistics Center')</h1></div>
            <div class="ops-topbar-actions">
                <div class="popover-wrap">
                    <button class="icon-button notification-trigger" type="button" data-popover-toggle="notifications" aria-label="Open notifications"><i data-lucide="bell"></i>@if(count($topNotifications ?? []))<span class="notification-dot"></span>@endif</button>
                    <div class="topbar-popover notification-popover" data-popover="notifications" hidden>
                        <div class="popover-heading"><strong>Notifications</strong><button type="button" data-mark-notifications>Mark all read</button></div>
                        @forelse($topNotifications ?? [] as $notification)
                            <button class="notification-row" type="button"><span class="notice-icon is-{{ $notification['type'] }}"><i data-lucide="{{ $notification['type'] === 'success' ? 'circle-check' : ($notification['type'] === 'warning' ? 'triangle-alert' : 'info') }}"></i></span><span><strong>{{ $notification['title'] }}</strong><small>{{ $notification['time'] }}</small></span></button>
                        @empty<p class="empty-copy">No new notifications.</p>@endforelse
                    </div>
                </div>
                <div class="popover-wrap">
                    <button class="profile-button" type="button" data-popover-toggle="profile"><span class="avatar">{{ $operator['initials'] ?? 'BR' }}</span><span class="profile-copy"><strong>{{ $operator['name'] ?? 'Bearly User' }}</strong><small>{{ $operator['role'] ?? 'Logistics Operator' }}</small></span><i data-lucide="chevron-down"></i></button>
                    <div class="topbar-popover profile-popover" data-popover="profile" hidden><a href="{{ route('logistics.profile.index') }}"><i data-lucide="settings"></i>Account settings</a><form method="POST" action="{{ route('logout') }}" style="display: contents">@csrf<button type="submit"><i data-lucide="log-out"></i>Logout</button></form></div>
                </div>
            </div>
        </header>
        <main class="ops-content">
            @if(session('success'))<div class="flash-banner is-success" role="status"><i data-lucide="circle-check"></i><span>{{ session('success') }}</span><button type="button" data-dismiss><i data-lucide="x"></i></button></div>@endif
            @if($errors->any())<div class="flash-banner is-danger" role="alert"><i data-lucide="circle-alert"></i><span>Please check the highlighted information and try again.</span><button type="button" data-dismiss><i data-lucide="x"></i></button></div>@endif
            @yield('content')
        </main>
        <footer class="ops-footer"><span>© {{ date('Y') }} Bearly Marketplace</span><span>Logistics operations preview</span></footer>
    </div>
</div>
<div class="modal-backdrop" data-modal-backdrop hidden></div>
<div class="toast-stack" data-toast-stack aria-live="polite"></div>
<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
</body>
</html>
