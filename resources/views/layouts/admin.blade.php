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

    @include('partials.theme-preload')
    @vite([
        'resources/css/admin.css',
        'resources/css/messages-standard.css',
        'resources/js/messages-standard.js',
        'resources/js/realtime.js',
        'resources/js/admin.js'
    ])
    @include('partials.session-safety')
    @stack('styles')
    <style>
        /* Keep Admin filters consistent when an older CSS bundle is cached. */
        :root[data-theme="dark"] body.admin-body :is(
            .admin-search, .field-with-icon, .registration-search, .table-search
        ) input[type="search"]:focus-visible {
            outline: none !important;
            outline-offset: 0 !important;
            box-shadow: none !important;
        }

        :root[data-theme="dark"] body.admin-body :is(.admin-search, .field-with-icon) input[type="search"] {
            border-color: transparent !important;
            background-color: transparent !important;
            box-shadow: none !important;
        }

        :root[data-theme="dark"] body.admin-body select:not([multiple]):not([size]) {
            appearance: none !important;
            -webkit-appearance: none !important;
            min-width: 0;
            padding-right: 34px;
            border-color: #4a3d34 !important;
            background-color: #1b1714 !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23efbd71' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") !important;
            background-position: right 12px center !important;
            background-repeat: no-repeat !important;
            background-size: 12px !important;
            color: #f1e9e1 !important;
            color-scheme: dark;
        }

        :root[data-theme="dark"] body.admin-body select:not([multiple]):not([size]):focus-visible {
            border-color: #efbd71 !important;
            outline: none !important;
            box-shadow: 0 0 0 2px rgba(239, 189, 113, .22) !important;
        }

        /* Keep dispute participant controls readable when an older Admin bundle is cached. */
        :root[data-theme="dark"] body.admin-body .coordination-panel .coordination-grid {
            border-color: #4a3d34 !important;
        }

        :root[data-theme="dark"] body.admin-body .coordination-panel .party-card {
            border-right-color: #4a3d34 !important;
        }

        :root[data-theme="dark"] body.admin-body .coordination-panel .party-icon {
            background: #45331d !important;
            color: #efbd71 !important;
        }

        :root[data-theme="dark"] body.admin-body .coordination-panel .party-card small,
        :root[data-theme="dark"] body.admin-body .coordination-panel .dispute-visibility-help {
            color: #c0b2a5 !important;
        }

        :root[data-theme="dark"] body.admin-body .coordination-panel .party-card strong,
        :root[data-theme="dark"] body.admin-body .coordination-panel .form-field > span {
            color: #f1e9e1 !important;
        }

        :root[data-theme="dark"] body.admin-body .coordination-panel .form-field textarea {
            border-color: #4a3d34 !important;
            background: #1b1714 !important;
            color: #f1e9e1 !important;
        }

        /* Keep Finance action forms dark when an older Admin bundle is cached. */
        :root[data-theme="dark"] body.admin-body .finance-action-form {
            border-color: #4a3d34 !important;
            background: #211c18 !important;
            color: #f1e9e1 !important;
            box-shadow: none !important;
        }

        :root[data-theme="dark"] body.admin-body .finance-action-form label,
        :root[data-theme="dark"] body.admin-body .finance-action-form .finance-confirm {
            color: #f1e9e1 !important;
        }

        :root[data-theme="dark"] body.admin-body .finance-action-form :is(
            input:not([type="checkbox"]), select, textarea
        ) {
            border-color: #4a3d34 !important;
            background: #1b1714 !important;
            color: #f1e9e1 !important;
            color-scheme: dark;
        }

        :root[data-theme="dark"] body.admin-body .finance-action-form :is(
            small, .table-subline
        ) {
            color: #c0b2a5 !important;
        }

        :root[data-theme="dark"] body.admin-body .finance-action-form .finance-confirm input {
            accent-color: #efbd71;
        }

        /* Keep announcement composer surfaces dark when an older Admin bundle is cached. */
        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-composer-author strong,
        :root[data-theme="dark"] body.admin-body .announcement-composer .form-field > span,
        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-audience-field legend {
            color: #f1e9e1 !important;
        }

        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-composer-author small,
        :root[data-theme="dark"] body.admin-body .announcement-composer .form-field > span small,
        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-audience-field legend small,
        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-media-count,
        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-delivery-note {
            color: #c0b2a5 !important;
        }

        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-retention-note {
            border-color: #72563e !important;
            background: #3b3027 !important;
            color: #d8cabd !important;
        }

        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-retention-note svg,
        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-delivery-note svg {
            color: #efbd71 !important;
        }

        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-audience-field,
        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-existing-media {
            border-color: #594232 !important;
            background: #211c18 !important;
        }

        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-audience-options label {
            border-color: #72563e !important;
            background: #3b3027 !important;
            color: #f1e9e1 !important;
        }

        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-audience-options input,
        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-remove-choice input {
            accent-color: #efbd71;
        }

        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-media-upload {
            border-color: #806334 !important;
            background: #2b241f !important;
            color: #f1e9e1 !important;
        }

        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-media-upload-icon {
            background: #45331d !important;
            color: #efbd71 !important;
        }

        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-media-upload-copy strong {
            color: #f1e9e1 !important;
        }

        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-media-upload-copy small,
        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-media-upload input {
            color: #c0b2a5 !important;
        }

        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-media-upload input[type="file"] {
            border-color: #594232 !important;
            background: #1b1714 !important;
        }

        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-media-upload input[type="file"]::file-selector-button {
            border: 0;
            border-right: 1px solid #594232;
            background: #3b3027;
            color: #f1e9e1;
            cursor: pointer;
        }

        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-upload-preview-item {
            border-color: #594232 !important;
            background: #2b241f !important;
        }

        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-upload-preview-item img,
        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-upload-preview-item video {
            background: #211c18 !important;
        }

        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-upload-preview-item figcaption {
            color: #c0b2a5 !important;
        }

        :root[data-theme="dark"] body.admin-body .announcement-composer .announcement-upload-remove {
            border: 1px solid #72504a !important;
            background: #3b2523 !important;
            color: #ffaaa0 !important;
        }

        /* Keep Platform Settings controls dark when an older Admin bundle is cached. */
        :root[data-theme="dark"] body.admin-body .settings-toggle-card > div {
            background: #45331d !important;
            color: #efbd71 !important;
        }

        :root[data-theme="dark"] body.admin-body .settings-toggle-card:hover {
            border-color: #806334 !important;
            background: #2b241f !important;
        }

        :root[data-theme="dark"] body.admin-body .settings-toggle-row + .settings-toggle-row {
            border-color: #4a3d34 !important;
        }

        :root[data-theme="dark"] body.admin-body .settings-save-state {
            border-color: #72563e !important;
            background: #3b3027 !important;
            color: #f1e9e1 !important;
            box-shadow: none !important;
        }

        :root[data-theme="dark"] body.admin-body .settings-save-state strong {
            color: #f1e9e1 !important;
        }

        :root[data-theme="dark"] body.admin-body .settings-save-state small {
            color: #c0b2a5 !important;
        }

        :root[data-theme="dark"] body.admin-body .settings-save-state-icon {
            background: #1e3828 !important;
            color: #a8d5b0 !important;
        }

        :root[data-theme="dark"] body.admin-body .settings-save-state[data-state="dirty"] {
            border-color: #806334 !important;
            background: #3b3020 !important;
        }

        :root[data-theme="dark"] body.admin-body .settings-save-state[data-state="dirty"] .settings-save-state-icon {
            background: #45331d !important;
            color: #efbd71 !important;
        }

        :root[data-theme="dark"] body.admin-body .settings-save-state[data-state="default"] {
            border-color: #594232 !important;
            background: #2b241f !important;
        }

        :root[data-theme="dark"] body.admin-body .settings-save-state[data-state="default"] .settings-save-state-icon {
            background: #3b3027 !important;
            color: #d8cabd !important;
        }

        :root[data-theme="dark"] body.admin-body .settings-grid input[type="number"],
        :root[data-theme="dark"] body.admin-body .settings-editor-panel input[type="number"] {
            color-scheme: dark;
        }

        :root[data-theme="dark"] body.admin-body .settings-availability-state {
            border-color: #4a3d34 !important;
        }

        /* Keep Shipping Fees values and calculation help readable when an older Admin bundle is cached. */
        :root[data-theme="dark"] body.admin-body :is(
            label[for="shipping-base-fee"],
            label[for="zone-surcharge-step"],
            label[for="volumetric-divisor"],
            label[for="shipping-change-reason"]
        ) {
            color: #f1e9e1 !important;
        }

        :root[data-theme="dark"] body.admin-body :is(
            #shipping-base-fee,
            #zone-surcharge-step,
            #volumetric-divisor,
            #shipping-change-reason
        ) {
            border-color: #4a3d34 !important;
            background: #1b1714 !important;
            color: #f1e9e1 !important;
            color-scheme: dark;
        }

        :root[data-theme="dark"] body.admin-body :is(
            #shipping-base-fee + small,
            #zone-surcharge-step + small,
            #volumetric-divisor + small,
            #shipping-change-reason + small
        ) {
            color: #c0b2a5 !important;
        }
    </style>
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
                'label' => 'Buyer ID uploads',
                'route' => 'admin.registrations.buyer-ids',
                'icon' => 'id-card',
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
        'label' => 'Product Compliance',
        'items' => [
            [
                'label' => 'Compliance Overview',
                'route' => 'admin.compliance',
                'icon' => 'shield-check',
            ],
            [
                'label' => 'Category Reviews',
                'route' => 'admin.compliance.category-reviews',
                'icon' => 'scan-search',
            ],
            [
                'label' => 'Product Violations',
                'route' => 'admin.compliance.violations',
                'icon' => 'shield-alert',
            ],
        ],
    ],

    [
        'label' => 'Customer Cases',
        'items' => [
            [
                'label' => 'Complaints & Disputes',
                'route' => 'admin.disputes',
                'icon' => 'message-square-warning',
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
                'icon' => 'wallet',
            ],
            [
                'label' => 'Shipping Settlements',
                'route' => 'admin.shipping-settlements.index',
                'icon' => 'truck',
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
                'label' => 'Marketplace Taxonomy',
                'route' => 'admin.marketplace-taxonomy.index',
                'icon' => 'tags',
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
                'label' => 'Logistics Policy Approvals',
                'route' => 'admin.shipping-policy-approvals.index',
                'icon' => 'clipboard-check',
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
            request()->routeIs('admin.disputes*', 'admin.compliance.returns-refunds*') => 'Customer Cases',
            request()->routeIs('admin.compliance*') => 'Product Compliance',
            request()->routeIs('admin.commissions', 'admin.transactions', 'admin.payments', 'admin.shipping-settlements.*', 'admin.seller-payouts.*', 'admin.reports', 'admin.orders', 'admin.fulfillment') => 'Finance & Reports',
            request()->routeIs('admin.messages', 'admin.announcements', 'admin.support-tickets') => 'Communication',
            request()->routeIs('admin.settings', 'admin.account', 'admin.policies', 'admin.shipping-policy-approvals.*', 'admin.audit-logs') => 'System Management',
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
            {{ html_entity_decode($adminTopbarTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8') }}
        </h1>

    </div>


    <div class="admin-topbar-actions">

    @include('partials.theme-toggle')

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
>
    @stack('admin-toasts')
</div>


<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>

@stack('scripts')

</body>
</html>
