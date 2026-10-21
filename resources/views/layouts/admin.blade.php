<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>
        @yield('title', 'Admin Center') | Bearly
    </title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    @vite([
        'resources/css/admin.css',
        'resources/css/messages-standard.css',
        'resources/js/messages-standard.js',
        'resources/js/realtime.js',
        'resources/js/admin.js'
    ])
    @include('partials.session-safety')
    @stack('styles')
</head>

<body class="admin-body" data-realtime-user-id="{{ auth()->id() }}">
@include('partials.realtime-identity')

@php

$adminNavGroups = [

    [
        'label' => 'Registration Management',
        'items' => [
            [
                'label' => 'All Applications',
                'route' => 'admin.registrations',
                'icon' => 'clipboard-check',
            ],
            [
                'label' => 'Buyer Applications',
                'route' => 'admin.registrations.buyers',
                'icon' => 'user-plus',
            ],
            [
                'label' => 'Seller Applications',
                'route' => 'admin.registrations.sellers',
                'icon' => 'store',
            ],
            [
                'label' => 'Logistics Applications',
                'route' => 'admin.registrations.logistics',
                'icon' => 'truck',
            ],
        ],
    ],

    [
        'label' => 'User Management',
        'items' => [
            [
                'label' => 'All Users',
                'route' => 'admin.users',
                'icon' => 'users',
            ],
            [
                'label' => 'Buyers',
                'route' => 'admin.users.buyers',
                'icon' => 'user',
            ],
            [
                'label' => 'Sellers',
                'route' => 'admin.users.sellers',
                'icon' => 'store',
            ],
            [
                'label' => 'Logistics Centers',
                'route' => 'admin.users.logistics',
                'icon' => 'warehouse',
            ],
            [
                'label' => 'Riders',
                'route' => 'admin.users.riders',
                'icon' => 'bike',
            ],
        ],
    ],

    [
        'label' => 'Compliance & Disputes',
        'items' => [
            [
                'label' => 'Seller Compliance',
                'route' => 'admin.compliance',
                'icon' => 'shield-check',
            ],
            [
                'label' => 'Complaints & Disputes',
                'route' => 'admin.disputes',
                'icon' => 'message-square-warning',
            ],
            [
                'label' => 'Product Violations',
                'route' => 'admin.compliance.violations',
                'icon' => 'shield-alert',
            ],
            [
                'label' => 'Returns & Refunds',
                'route' => 'admin.compliance.returns-refunds',
                'icon' => 'rotate-ccw',
            ],
        ],
    ],

    [
        'label' => 'Finance & Reports',
        'items' => [
            [
                'label' => 'Commission Management',
                'route' => 'admin.commissions',
                'icon' => 'percent',
            ],
            [
                'label' => 'Transactions',
                'route' => 'admin.transactions',
                'icon' => 'receipt',
            ],
            [
                'label' => 'Seller Payments',
                'route' => 'admin.payments',
                'icon' => 'credit-card',
            ],
            [
                'label' => 'Seller Payouts',
                'route' => 'admin.seller-payouts.index',
                'icon' => 'wallet',
            ],
            [
                'label' => 'Financial Reports',
                'route' => 'admin.reports',
                'icon' => 'chart-column',
            ],
        ],
    ],

    [
        'label' => 'Marketing',
        'items' => [
            [
                'label' => 'Platform Vouchers & Offers',
                'route' => 'admin.promotions',
                'icon' => 'badge-percent',
            ],
            [
                'label' => 'Bearly Campaigns',
                'route' => 'admin.campaigns',
                'icon' => 'calendar-range',
            ],
        ],
    ],

    [
        'label' => 'Communication',
        'items' => [
            [
                'label' => 'Messages',
                'route' => 'admin.messages',
                'icon' => 'message-circle',
            ],
            [
                'label' => 'Announcements',
                'route' => 'admin.announcements',
                'icon' => 'megaphone',
            ],
            [
                'label' => 'Support Tickets',
                'route' => 'admin.support-tickets',
                'icon' => 'life-buoy',
            ],
        ],
    ],

    [
        'label' => 'System Management',
        'items' => [
            [
                'label' => 'Platform Settings',
                'route' => 'admin.settings',
                'icon' => 'settings',
            ],
            [
                'label' => 'Shipping Fees',
                'route' => 'admin.shipping-rates',
                'icon' => 'map-pinned',
            ],
            [
                'label' => 'Account',
                'route' => 'admin.account',
                'icon' => 'user-round-cog',
            ],
            [
                'label' => 'Platform Policies',
                'route' => 'admin.policies',
                'icon' => 'file-text',
            ],
            [
                'label' => 'Audit Logs',
                'route' => 'admin.audit-logs',
                'icon' => 'scroll-text',
            ],
        ],
    ],

];

@endphp


<div class="admin-shell">


    <aside class="admin-sidebar" data-admin-sidebar>


        <div class="admin-sidebar-header">


            <button
                class="admin-sidebar-menu-button"
                type="button"
                data-admin-menu
                aria-label="Toggle sidebar"
            >
                <i class="admin-ui-icon" data-lucide="menu"></i>
            </button>


            <a href="{{ route('admin.dashboard') }}" class="admin-brand">

                <img
                    src="{{ asset('images/bearly-logo-2.png') }}"
                    alt="Bearly"
                    class="admin-brand-logo"
                >

                <span class="admin-brand-subtitle">
                    Admin Center
                </span>

            </a>


        </div>


        <nav class="admin-nav">


            <a
                href="{{ route('admin.dashboard') }}"
                class="admin-nav-link {{ request()->routeIs('admin.dashboard') ? 'is-active' : '' }}"
                title="Dashboard"
            >

                <i class="admin-ui-icon" data-lucide="house"></i>

                <span>
                    Dashboard
                </span>

            </a>


            <a
                href="{{ route('notifications.center') }}"
                class="admin-nav-link {{ request()->routeIs('notifications.center') ? 'is-active' : '' }}"
                title="Notifications"
            >

                <i class="admin-ui-icon" data-lucide="bell"></i>

                <span>
                    Notifications
                </span>

            </a>


            @foreach($adminNavGroups as $group)


                <section class="admin-nav-group">
                    <div class="admin-nav-label">
                        <span>{{ strtoupper($group['label']) }}</span>
                    </div>


                    <div class="admin-nav-children">


                        @foreach($group['items'] as $item)

                            @php
                                $hasRoute = !empty($item['route']) && Route::has($item['route']);
                                $isActive = $hasRoute && request()->routeIs($item['route']);
                            @endphp

                            @if($hasRoute)

                                <a
                                    href="{{ route($item['route']) }}"
                                    class="admin-nav-link {{ $isActive ? 'is-active' : '' }}"
                                    title="{{ $item['label'] }}"
                                >
                                    <i
                                        class="admin-ui-icon"
                                        data-lucide="{{ $item['icon'] }}"
                                    ></i>

                                    <span>{{ $item['label'] }}</span>
                                </a>

                            @else

                                <span
                                    class="admin-nav-link admin-nav-link-disabled"
                                    title="Coming soon"
                                    aria-disabled="true"
                                >
                                    <i
                                        class="admin-ui-icon"
                                        data-lucide="{{ $item['icon'] }}"
                                    ></i>

                                    <span>{{ $item['label'] }}</span>
                                </span>

                            @endif

                        @endforeach


                    </div>

                </section>

            @endforeach


        </nav>


        <div class="admin-sidebar-footer">


            <form method="POST" action="{{ route('logout') }}" style="display: contents">
                @csrf

                <button
                    type="submit"
                    class="admin-nav-link admin-logout"
                >

                <i
                    class="admin-ui-icon"
                    data-lucide="log-out"
                ></i>


                <span>
                    Logout
                </span>


                </button>
            </form>


        </div>


    </aside>


    <button
        class="admin-overlay"
        data-admin-overlay
    ></button>


    <div class="admin-main">


        <header class="admin-topbar">

    @php
        $adminTopbarTitle = match (true) {
            request()->routeIs('admin.dashboard') => 'Dashboard Overview',
            request()->routeIs('admin.registrations*') => 'Registration Management',
            request()->routeIs('admin.users*') => 'User Management',
            request()->routeIs('admin.compliance*', 'admin.disputes*', 'admin.violations*') => 'Compliance & Disputes',
            request()->routeIs('admin.commissions', 'admin.transactions', 'admin.payments', 'admin.seller-payouts.*', 'admin.reports', 'admin.orders', 'admin.fulfillment') => 'Finance & Reports',
            request()->routeIs('admin.messages', 'admin.announcements', 'admin.support-tickets') => 'Communication',
            request()->routeIs('admin.settings', 'admin.account', 'admin.policies', 'admin.audit-logs') => 'System Management',
            default => trim(View::yieldContent('page-title', 'Admin Center')) ?: 'Admin Center',
        };
    @endphp

    <div class="admin-topbar-left">

        <button
            class="admin-icon-button admin-mobile-menu"
            type="button"
            data-admin-mobile-menu
            aria-label="Open admin navigation"
            aria-expanded="false"
        >
            <i
                class="admin-ui-icon"
                data-lucide="menu"
                aria-hidden="true"
            ></i>
        </button>

        <h1>
            {{ $adminTopbarTitle }}
        </h1>

    </div>


    <div class="admin-topbar-actions">

    {{-- ================================
         Notifications
         ================================ --}}
    <div class="admin-popover-wrap topbar-popover-wrap" data-notification-center data-notification-skin="admin">

        <button
            class="admin-icon-button admin-notification-trigger"
            type="button"
            data-popover-toggle="notifications"
            aria-label="Open notifications"
        >
            <i
                class="admin-ui-icon"
                data-lucide="bell"
                aria-hidden="true"
            ></i>

            <span class="admin-notification-dot" data-notification-badge="dot" hidden></span>
        </button>


        <div
            class="topbar-popover admin-notification-popover"
            data-popover="notifications"
            hidden
        >

            <div class="popover-heading">

                <div>
                    <strong>Notifications</strong>

                    <span data-notification-summary>Recent updates</span>
                </div>

            </div>


            <div class="notification-list" data-notification-list></div>

            <div class="admin-empty-notifications" data-notification-empty hidden>
                No new notifications.
            </div>

            <div class="popover-footer">

                <button type="button" class="notification-mark-all" data-notification-mark-all hidden>
                    <i class="admin-ui-icon" data-lucide="mail-check" aria-hidden="true"></i>
                    <span>Mark all as read</span>
                </button>

                <a class="admin-notification-view-all" href="{{ route('notifications.center') }}">
                    <span>View all notifications</span>
                    <i class="admin-ui-icon" data-lucide="arrow-right" aria-hidden="true"></i>
                </a>

            </div>

        </div>

    </div>


    <span class="admin-topbar-divider"></span>


        {{-- ================================
            Admin Profile
            ================================ --}}
        <div class="admin-popover-wrap topbar-popover-wrap">

            <button
                class="admin-profile-button"
                type="button"
                data-popover-toggle="profile"
                aria-label="Open profile menu"
            >

                <span class="admin-avatar">
                    {{ $admin['initials'] ?? 'AR' }}
                </span>

                <span class="admin-profile-name">
                    {{ $admin['name'] ?? 'Alex Rivera' }}
                </span>

                <i
                    class="admin-ui-icon"
                    data-lucide="chevron-down"
                    aria-hidden="true"
                ></i>

            </button>


            <div
                class="topbar-popover profile-popover admin-profile-popover"
                data-popover="profile"
                hidden
            >

                <div class="profile-popover-head">

                    <span class="admin-avatar admin-avatar-small">
                        {{ $admin['initials'] ?? 'AR' }}
                    </span>

                    <div>

                        <strong>
                            {{ $admin['name'] ?? 'Alex Rivera' }}
                        </strong>

                        <small>
                            {{ $admin['email'] ?? 'admin@bearly.test' }}
                        </small>

                        <small>
                            {{ $admin['role'] ?? 'Super Admin' }}
                        </small>

                    </div>

                </div>


                <a href="{{ route('admin.account') }}">

                    <i
                        class="admin-ui-icon"
                        data-lucide="user-round-cog"
                    ></i>

                    <span>Account settings</span>

                </a>


                <a href="{{ route('admin.settings') }}">

                    <i
                        class="admin-ui-icon"
                        data-lucide="settings"
                    ></i>

                    <span>Platform settings</span>

                </a>


                <form method="POST" action="{{ route('logout') }}" style="display: contents">
                    @csrf

                    <button
                        type="submit"
                        class="admin-profile-logout"
                    >

                    <i
                        class="admin-ui-icon"
                        data-lucide="log-out"
                    ></i>

                    <span>Logout</span>

                    </button>
                </form>

            </div>

        </div>

    </div>

</header>


        <main class="admin-content">

            @if (session('warning'))
                <div class="info-box" role="status">
                    <strong>Heads up.</strong> {{ session('warning') }}
                </div>
            @endif

            @yield('content')

        </main>


    </div>


</div>


{{-- Global feedback container used by admin.js mock/preview actions. --}}
<div
    class="toast-stack"
    data-toast-stack
    aria-live="polite"
    aria-atomic="true"
></div>


<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>

@stack('scripts')

</body>
</html>
