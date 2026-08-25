<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Courier') | Bearly</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    @vite([
        'resources/css/courier.css',
        'resources/js/courier.js',
    ])
    @stack('styles')
</head>
@php
    $isPublicCourierPage = request()->routeIs('courier.register', 'courier.pending');
    $navItems = [
        ['route' => 'courier.dashboard', 'label' => 'Delivery Dashboard', 'icon' => 'layout-dashboard'],
        ['route' => 'courier.requests', 'label' => 'Delivery Requests', 'icon' => 'inbox'],
        ['route' => 'courier.pickup', 'label' => 'Pick Up Order', 'icon' => 'package-check'],
        ['route' => 'courier.transit', 'label' => 'Deliver Order', 'icon' => 'navigation'],
        ['route' => 'courier.complete', 'label' => 'Complete Delivery', 'icon' => 'badge-check'],
        ['route' => 'courier.earnings', 'label' => 'Profit Dashboard', 'icon' => 'wallet-cards'],
        ['route' => 'courier.history', 'label' => 'Delivery History', 'icon' => 'history'],
        ['route' => 'courier.messages', 'label' => 'Chat / Messaging', 'icon' => 'messages-square'],
        ['route' => 'courier.account', 'label' => 'Account Management', 'icon' => 'circle-user-round'],
    ];
@endphp
<body class="courier-body {{ $isPublicCourierPage ? 'courier-public' : '' }}">
@if($isPublicCourierPage)
    <header class="public-courier-header">
        <a href="{{ route('courier.register') }}" aria-label="Bearly Courier registration">
            <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly">
        </a>
        <div class="public-header-copy">
            <strong>Courier Partner Portal</strong>
            <span>Front-end preview</span>
        </div>
    </header>
    <main class="public-courier-main">
        @yield('content')
    </main>
@else
<div class="courier-shell" data-courier-shell>
    <aside class="courier-sidebar" data-sidebar>
        <div class="sidebar-brand-row">
            <a href="{{ route('courier.dashboard') }}" class="courier-brand" aria-label="Bearly Courier home">
                <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly">
                <span class="courier-pill">Courier</span>
            </a>
            <button class="icon-button sidebar-collapse-button" type="button" data-sidebar-toggle aria-label="Collapse sidebar">
                <i data-lucide="panel-left-close"></i>
            </button>
        </div>

        <div class="sidebar-section-label">Courier workflow</div>
        <nav class="sidebar-nav" aria-label="Courier navigation">
            @foreach ($navItems as $index => $item)
                <a href="{{ route($item['route']) }}" class="sidebar-link {{ request()->routeIs($item['route']) ? 'is-active' : '' }}" title="{{ $item['label'] }}">
                    <span class="sidebar-link-icon"><i data-lucide="{{ $item['icon'] }}"></i></span>
                    <span class="sidebar-link-copy">
                        <span class="sidebar-link-number">{{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}</span>
                        <span>{{ $item['label'] }}</span>
                    </span>
                </a>
            @endforeach
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-help-card">
                <span class="sidebar-help-icon"><i data-lucide="bike"></i></span>
                <div>
                    <strong>Courier preview mode</strong>
                    <p>Delivery actions use mock data and do not write to a database.</p>
                </div>
            </div>
            <a href="{{ route('login') }}" class="sidebar-logout" data-mock-action="Logged out of the courier preview.">
                <i data-lucide="log-out"></i><span>Logout</span>
            </a>
        </div>
    </aside>

    <div class="sidebar-overlay" data-sidebar-overlay></div>

    <div class="courier-main">
        <header class="courier-topbar">
            <div class="topbar-left">
                <button class="icon-button mobile-menu-button" type="button" data-mobile-sidebar aria-label="Open navigation"><i data-lucide="menu"></i></button>
                <div class="page-heading-compact">
                    <span class="eyebrow">Bearly Courier Center</span>
                    <strong>@yield('page-title', 'Courier')</strong>
                </div>
            </div>

            <div class="topbar-actions">
                <label class="courier-search">
                    <i data-lucide="search"></i>
                    <input type="search" placeholder="Search courier tools..." data-global-search aria-label="Search courier tools">
                    <kbd>⌘ K</kbd>
                </label>

                <div class="duty-control" title="Static online/offline preview">
                    <strong>Duty</strong>
                    <button type="button" class="duty-toggle is-online" data-duty-toggle aria-label="Toggle duty status"></button>
                    <span class="duty-state is-online" data-duty-state>Online</span>
                </div>

                <div class="topbar-popover-wrap">
                    <button class="icon-button notification-button" type="button" data-popover-toggle="notifications" aria-label="Delivery notifications">
                        <i data-lucide="bell"></i><span class="notification-dot"></span>
                    </button>
                    <div class="topbar-popover notification-popover" data-popover="notifications" hidden>
                        <div class="popover-heading">
                            <div><strong>Delivery notifications</strong><span>Latest courier activity</span></div>
                            <span class="status-badge badge-info">{{ count($topNotifications ?? []) }} new</span>
                        </div>
                        <div class="notification-list">
                            @forelse(($topNotifications ?? []) as $notice)
                                <button type="button" class="notification-row" data-mock-action="Notification opened.">
                                    <span class="activity-dot dot-{{ $notice['type'] }}"></span>
                                    <span><strong>{{ $notice['title'] }}</strong><small>{{ $notice['time'] }}</small></span>
                                </button>
                            @empty
                                <div class="empty-state-small">No new delivery notifications.</div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <div class="topbar-popover-wrap profile-wrap">
                    <button class="profile-button" type="button" data-popover-toggle="profile">
                        <span class="avatar avatar-warm">{{ $courier['initials'] ?? 'AC' }}</span>
                        <span class="profile-copy"><strong>{{ $courier['name'] ?? 'Adrian Cruz' }}</strong><small>{{ $courier['vehicle'] ?? 'Motorcycle Courier' }}</small></span>
                        <i data-lucide="chevron-down"></i>
                    </button>
                    <div class="topbar-popover profile-popover" data-popover="profile" hidden>
                        <div class="profile-popover-head">
                            <span class="avatar avatar-warm">{{ $courier['initials'] ?? 'AC' }}</span>
                            <div><strong>{{ $courier['name'] ?? 'Adrian Cruz' }}</strong><small>{{ $courier['email'] ?? 'courier@bearly.test' }}</small></div>
                        </div>
                        <a href="{{ route('courier.account') }}"><i data-lucide="user-round-cog"></i> Account settings</a>
                        <a href="{{ route('login') }}"><i data-lucide="log-out"></i> Logout preview</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="courier-content">
            <div class="flash-stack" data-flash-stack>
                @if (session('success'))
                    <div class="flash-message flash-success"><i data-lucide="circle-check"></i><span>{{ session('success') }}</span><button type="button" data-dismiss-flash aria-label="Dismiss"><i data-lucide="x"></i></button></div>
                @endif
                @if (session('error'))
                    <div class="flash-message flash-error"><i data-lucide="circle-alert"></i><span>{{ session('error') }}</span><button type="button" data-dismiss-flash aria-label="Dismiss"><i data-lucide="x"></i></button></div>
                @endif
            </div>
            @yield('content')
        </main>
    </div>
</div>

<div class="command-palette" data-command-palette hidden>
    <button class="command-backdrop" type="button" data-command-close aria-label="Close search"></button>
    <div class="command-card">
        <div class="command-input-wrap"><i data-lucide="search"></i><input type="search" placeholder="Jump to a courier module..." data-command-input autofocus><button type="button" data-command-close>Esc</button></div>
        <div class="command-results" data-command-results>
            @foreach ($navItems as $item)
                <a href="{{ route($item['route']) }}" data-command-item data-search-text="{{ strtolower($item['label']) }}"><span><i data-lucide="{{ $item['icon'] }}"></i></span><strong>{{ $item['label'] }}</strong><i data-lucide="arrow-up-right"></i></a>
            @endforeach
        </div>
    </div>
</div>
<div class="toast-stack" data-toast-stack aria-live="polite" aria-atomic="true"></div>
@endif

<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
@stack('scripts')
</body>
</html>
