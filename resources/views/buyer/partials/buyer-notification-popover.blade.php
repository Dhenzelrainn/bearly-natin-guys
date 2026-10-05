@php
    $notificationHeaderActive = ($headerActive ?? '') === 'notifications';
@endphp

<div class="buyer-notification-menu" data-notification-menu>
    <button
        type="button"
        class="notification-header-trigger{{ $notificationHeaderActive ? ' active' : '' }}"
        data-notification-trigger
        aria-label="Open notifications"
        aria-haspopup="dialog"
        aria-expanded="false"
        aria-controls="buyer-notification-popover"
    >
        <span class="material-symbols-outlined" aria-hidden="true">notifications</span>
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

        <div class="buyer-notification-popover-list" data-notification-popover-list role="list"></div>
        <div class="buyer-notification-popover-empty" data-notification-popover-empty hidden>
            <span class="material-symbols-outlined" aria-hidden="true">notifications_off</span>
            <strong>You're all caught up</strong>
            <span>New Bearly updates will appear here.</span>
        </div>

        <div class="buyer-notification-popover-actions">
            <button type="button" class="buyer-notification-mark-all" data-notification-popover-mark-all>
                <span class="material-symbols-outlined" aria-hidden="true">mark_email_read</span>
                Mark all as read
            </button>
            <a href="{{ url('/profile#notifications') }}" data-notification-view-all>
                View all notifications
                <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
            </a>
        </div>
    </div>
</div>
