@php
    $notificationHeaderActive = ($headerActive ?? '') === 'notifications';
@endphp

<div class="buyer-notification-menu" data-notification-menu data-notification-center data-notification-skin="buyer">
    <button
        type="button"
        class="notification-header-trigger{{ $notificationHeaderActive ? ' active' : '' }}"
        data-notification-trigger
        aria-label="Open notifications"
        aria-haspopup="dialog"
        aria-expanded="false"
        aria-controls="buyer-notification-popover"
    >
        <svg class="notification-bell-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
            <path d="M10.268 21a2 2 0 0 0 3.464 0"/>
            <path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>
        </svg>
        <span>Notifications</span>
        <span class="notification-badge" data-notification-badge hidden aria-label="Unread notifications">0</span>
    </button>

    <div
        id="buyer-notification-popover"
        class="buyer-notification-popover"
        data-notification-popover
        role="dialog"
        aria-label="Recent notifications"
        hidden
    >
        <div class="buyer-notification-popover-head">
            <div>
                <strong>Notifications</strong>
                <span data-notification-summary>Recent updates from Bearly</span>
            </div>
            <button type="button" class="buyer-notification-popover-close" data-notification-popover-close aria-label="Close notifications">
                <span class="material-symbols-outlined" aria-hidden="true">close</span>
            </button>
        </div>

        <div class="buyer-notification-popover-list" data-notification-popover-list data-notification-list role="list"></div>
        <div class="buyer-notification-popover-empty" data-notification-popover-empty data-notification-empty hidden>
            <span class="material-symbols-outlined" aria-hidden="true">notifications_off</span>
            <strong>You're all caught up</strong>
            <span>New Bearly updates will appear here.</span>
        </div>

        <div class="buyer-notification-popover-actions">
            <button type="button" class="buyer-notification-mark-all" data-notification-mark-all>
                <span class="material-symbols-outlined" aria-hidden="true">mark_email_read</span>
                Mark all as read
            </button>
            <a href="{{ route('notifications.center') }}" data-notification-view-all>
                View all notifications
                <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
            </a>
        </div>
    </div>
</div>
