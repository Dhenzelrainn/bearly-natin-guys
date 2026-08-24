<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') | Bearly</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    @vite([
        'resources/css/admin.css',
        'resources/js/admin.js',
    ])

    @stack('styles')
</head>
<body class="admin-body">
@php
    $navItems = [
        ['route' => 'admin.dashboard', 'label' => 'Dashboard Overview', 'icon' => 'layout-dashboard'],
        ['route' => 'admin.registrations', 'label' => 'Account Registrations', 'icon' => 'clipboard-check'],
        ['route' => 'admin.users', 'label' => 'User Accounts', 'icon' => 'users'],
        ['route' => 'admin.compliance', 'label' => 'Seller Compliance', 'icon' => 'shield-check'],
        ['route' => 'admin.disputes', 'label' => 'Complaints & Disputes', 'icon' => 'message-square-warning'],
        ['route' => 'admin.commissions', 'label' => 'Commission (10%)', 'icon' => 'percent'],
        ['route' => 'admin.reports', 'label' => 'Generate Reports', 'icon' => 'chart-no-axes-combined'],
        ['route' => 'admin.settings', 'label' => 'Platform Settings', 'icon' => 'settings-2'],
        ['route' => 'admin.messages', 'label' => 'Chat / Messaging', 'icon' => 'messages-square'],
        ['route' => 'admin.account', 'label' => 'Account Management', 'icon' => 'circle-user-round'],
    ];
@endphp

<div class="admin-shell" data-admin-shell>
    <aside class="admin-sidebar" data-sidebar>
        <div class="sidebar-brand-row">
            <a href="{{ route('admin.dashboard') }}" class="admin-brand" aria-label="Bearly Admin home">
                <img src="{{ asset('images/bearly-logo.png') }}" alt="Bearly">
                <span class="admin-pill">Admin</span>
            </a>
            <button class="icon-button sidebar-collapse-button" type="button" data-sidebar-toggle aria-label="Collapse sidebar">
                <i data-lucide="panel-left-close"></i>
            </button>
        </div>

        <div class="sidebar-section-label">Main menu</div>
        <nav class="sidebar-nav" aria-label="Admin navigation">
            @foreach ($navItems as $index => $item)
                <a
                    href="{{ route($item['route']) }}"
                    class="sidebar-link {{ request()->routeIs($item['route']) ? 'is-active' : '' }}"
                    title="{{ $item['label'] }}"
                >
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
                <span class="sidebar-help-icon"><i data-lucide="sparkles"></i></span>
                <div>
                    <strong>Front-end preview</strong>
                    <p>Static mock data only. Backend integration comes later.</p>
                </div>
            </div>
            <a href="{{ route('login') }}" class="sidebar-logout" data-mock-action="Logged out of the admin preview.">
                <i data-lucide="log-out"></i>
                <span>Logout</span>
            </a>
        </div>
    </aside>

    <div class="sidebar-overlay" data-sidebar-overlay></div>

    <div class="admin-main">
        <header class="admin-topbar">
            <div class="topbar-left">
                <button class="icon-button mobile-menu-button" type="button" data-mobile-sidebar aria-label="Open navigation">
                    <i data-lucide="menu"></i>
                </button>
                <div class="page-heading-compact">
                    <span class="eyebrow">Bearly Control Center</span>
                    <strong>@yield('page-title', 'Admin')</strong>
                </div>
            </div>

            <div class="topbar-actions">
                <label class="admin-search">
                    <i data-lucide="search"></i>
                    <input type="search" placeholder="Search admin tools..." data-global-search aria-label="Search admin tools">
                    <kbd>⌘ K</kbd>
                </label>

                <div class="topbar-popover-wrap">
                    <button class="icon-button notification-button" type="button" data-popover-toggle="notifications" aria-label="Notifications">
                        <i data-lucide="bell"></i>
                        <span class="notification-dot"></span>
                    </button>
                    <div class="topbar-popover notification-popover" data-popover="notifications" hidden>
                        <div class="popover-heading">
                            <div>
                                <strong>Notifications</strong>
                                <span>Latest platform activity</span>
                            </div>
                            <span class="status-badge badge-info">{{ count($topNotifications ?? []) }} new</span>
                        </div>
                        <div class="notification-list">
                            @forelse(($topNotifications ?? []) as $notice)
                                <button type="button" class="notification-row" data-mock-action="Notification opened.">
                                    <span class="activity-dot dot-{{ $notice['type'] }}"></span>
                                    <span>
                                        <strong>{{ $notice['title'] }}</strong>
                                        <small>{{ $notice['time'] }}</small>
                                    </span>
                                </button>
                            @empty
                                <div class="empty-state-small">No new notifications.</div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <div class="topbar-popover-wrap profile-wrap">
                    <button class="profile-button" type="button" data-popover-toggle="profile">
                        <span class="avatar avatar-warm">{{ $admin['initials'] ?? 'BA' }}</span>
                        <span class="profile-copy">
                            <strong>{{ $admin['name'] ?? 'Bearly Admin' }}</strong>
                            <small>{{ $admin['role'] ?? 'Administrator' }}</small>
                        </span>
                        <i data-lucide="chevron-down"></i>
                    </button>
                    <div class="topbar-popover profile-popover" data-popover="profile" hidden>
                        <div class="profile-popover-head">
                            <span class="avatar avatar-warm">{{ $admin['initials'] ?? 'BA' }}</span>
                            <div>
                                <strong>{{ $admin['name'] ?? 'Bearly Admin' }}</strong>
                                <small>{{ $admin['email'] ?? 'admin@bearly.test' }}</small>
                            </div>
                        </div>
                        <a href="{{ route('admin.account') }}"><i data-lucide="user-round-cog"></i> Account settings</a>
                        <a href="{{ route('login') }}"><i data-lucide="log-out"></i> Logout preview</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="admin-content">
            <div class="flash-stack" data-flash-stack>
                @if (session('success'))
                    <div class="flash-message flash-success">
                        <i data-lucide="circle-check"></i>
                        <span>{{ session('success') }}</span>
                        <button type="button" data-dismiss-flash aria-label="Dismiss"><i data-lucide="x"></i></button>
                    </div>
                @endif
                @if (session('error'))
                    <div class="flash-message flash-error">
                        <i data-lucide="circle-alert"></i>
                        <span>{{ session('error') }}</span>
                        <button type="button" data-dismiss-flash aria-label="Dismiss"><i data-lucide="x"></i></button>
                    </div>
                @endif
            </div>

            @yield('content')
        </main>
    </div>
</div>

<div class="command-palette" data-command-palette hidden>
    <button class="command-backdrop" type="button" data-command-close aria-label="Close search"></button>
    <div class="command-card">
        <div class="command-input-wrap">
            <i data-lucide="search"></i>
            <input type="search" placeholder="Jump to an admin module..." data-command-input autofocus>
            <button type="button" data-command-close>Esc</button>
        </div>
        <div class="command-results" data-command-results>
            @foreach ($navItems as $item)
                <a href="{{ route($item['route']) }}" data-command-item data-search-text="{{ strtolower($item['label']) }}">
                    <span><i data-lucide="{{ $item['icon'] }}"></i></span>
                    <strong>{{ $item['label'] }}</strong>
                    <i data-lucide="arrow-up-right"></i>
                </a>
            @endforeach
        </div>
    </div>
</div>

<div class="toast-stack" data-toast-stack aria-live="polite" aria-atomic="true"></div>

<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
@stack('scripts')
</body>
</html>
