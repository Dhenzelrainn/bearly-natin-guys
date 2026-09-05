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
        'resources/js/admin.js'
    ])
</head>

<body class="admin-body">

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
                'route' => null,
                'icon' => 'user-plus',
            ],
            [
                'label' => 'Seller Applications',
                'route' => null,
                'icon' => 'store',
            ],
            [
                'label' => 'Logistics Applications',
                'route' => null,
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
                'route' => null,
                'icon' => 'user',
            ],
            [
                'label' => 'Sellers',
                'route' => null,
                'icon' => 'store',
            ],
            [
                'label' => 'Logistics Centers',
                'route' => null,
                'icon' => 'warehouse',
            ],
            [
                'label' => 'Riders / Couriers',
                'route' => null,
                'icon' => 'bike',
            ],
        ],
    ],

    [
        'label' => 'Marketplace Management',
        'items' => [
            [
                'label' => 'Products',
                'route' => null,
                'icon' => 'package',
            ],
            [
                'label' => 'Categories',
                'route' => null,
                'icon' => 'layers',
            ],
            [
                'label' => 'Orders',
                'route' => null,
                'icon' => 'shopping-cart',
            ],
            [
                'label' => 'Delivery Monitoring',
                'route' => null,
                'icon' => 'map-pin',
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
                'route' => null,
                'icon' => 'triangle-alert',
            ],
            [
                'label' => 'Returns & Refunds',
                'route' => null,
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
                'label' => 'Reports',
                'route' => 'admin.reports',
                'icon' => 'chart-column',
            ],
            [
                'label' => 'Transactions',
                'route' => null,
                'icon' => 'receipt',
            ],
            [
                'label' => 'Payments',
                'route' => null,
                'icon' => 'credit-card',
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
                'route' => null,
                'icon' => 'megaphone',
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
                'label' => 'Account',
                'route' => 'admin.account',
                'icon' => 'user-round-cog',
            ],
            [
                'label' => 'Platform Policies',
                'route' => null,
                'icon' => 'file-text',
            ],
            [
                'label' => 'Audit Logs',
                'route' => null,
                'icon' => 'history',
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
                    src="{{ asset('images/bearly-logo.png') }}"
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
            >

                <i class="admin-ui-icon" data-lucide="house"></i>

                <span>
                    Dashboard
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


            <a
                href="{{ route('login') }}"
                class="admin-nav-link admin-logout"
            >

                <i
                    class="admin-ui-icon"
                    data-lucide="log-out"
                ></i>


                <span>
                    Logout
                </span>


            </a>


        </div>


    </aside>


    <button
        class="admin-overlay"
        data-admin-overlay
    ></button>


    <div class="admin-main">


        <header class="admin-topbar">

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
            @yield('page-title', 'Admin Center')
        </h1>

    </div>


    <div class="admin-topbar-actions">

    {{-- ================================
         Notifications
         ================================ --}}
    <div class="admin-popover-wrap topbar-popover-wrap">

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

            @if(count($topNotifications ?? []) > 0)
                <span class="admin-notification-dot"></span>
            @endif
        </button>


        <div
            class="topbar-popover admin-notification-popover"
            data-popover="notifications"
            hidden
        >

            <div class="popover-heading">

                <div>
                    <strong>Notifications</strong>

                    <span>
                        {{ count($topNotifications ?? []) }} new
                    </span>
                </div>

            </div>


            <div class="notification-list">

                @forelse(($topNotifications ?? []) as $notification)

                    <button
                        type="button"
                        class="notification-row"
                    >

                        <span
                            class="admin-notice-dot
                            admin-notice-dot-{{ $notification['type'] }}"
                        ></span>

                        <span>

                            <strong>
                                {{ $notification['title'] }}
                            </strong>

                            <small>
                                {{ $notification['time'] }}
                            </small>

                        </span>

                    </button>

                @empty

                    <div class="admin-empty-notifications">
                        No new notifications.
                    </div>

                @endforelse

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


                <a
                    href="{{ route('login') }}"
                    class="admin-profile-logout"
                >

                    <i
                        class="admin-ui-icon"
                        data-lucide="log-out"
                    ></i>

                    <span>Logout</span>

                </a>

            </div>

        </div>

    </div>

</header>


        <main class="admin-content">

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


</body>
</html>