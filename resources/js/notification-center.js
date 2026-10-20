/*
 * Bearly notification center.
 *
 * Single reader for the authenticated /notifications endpoints shared by the
 * Buyer, Seller, Admin, Logistics, and Rider topbar popovers. The backend
 * scopes every request to the signed-in user; this module never sends a user
 * id and only renders what the API returns for that account.
 */

const ENDPOINTS = {
    list: '/notifications',
    readAll: '/notifications/read-all',
    read: id => `/notifications/${encodeURIComponent(id)}/read`,
};

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content
    || document.querySelector('input[name="_token"]')?.value
    || '';

const jsonHeaders = () => ({
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-CSRF-TOKEN': csrfToken(),
});

export const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
}[character]));

async function requestJson(url, options = {}) {
    const response = await fetch(url, { credentials: 'same-origin', ...options });

    if (!response.ok) {
        throw new Error(`Notification request failed with status ${response.status}`);
    }

    return response.json();
}

/** Paginated history for the authenticated user. */
export async function loadNotifications(limit = 5, page = 1) {
    return loadNotificationHistory({ perPage: limit, page });
}

/**
 * Server-side history query for the full-page centers. Category values come
 * from the role-aware backend mapping (event keys), never from title text.
 */
export async function loadNotificationHistory({ perPage = 15, page = 1, category = 'all', search = '' } = {}) {
    const query = new URLSearchParams({ per_page: String(perPage), page: String(page) });

    if (category && category !== 'all') query.set('category', String(category));
    if (search) query.set('search', String(search));

    const payload = await requestJson(`${ENDPOINTS.list}?${query.toString()}`, { headers: jsonHeaders() });

    return payload.data;
}

/** Marks one owned notification as read; resolves to the fresh unread count. */
export async function markNotificationRead(id) {
    const payload = await requestJson(ENDPOINTS.read(id), { method: 'POST', headers: jsonHeaders() });

    return Number(payload.data?.unread_count ?? 0);
}

/** Marks every owned notification as read; resolves to the fresh unread count. */
export async function markAllNotificationsRead() {
    const payload = await requestJson(ENDPOINTS.readAll, { method: 'POST', headers: jsonHeaders() });

    return Number(payload.data?.unread_count ?? 0);
}

/**
 * Mirrors a real unread count onto every badge on the page. Elements marked
 * data-notification-badge="dot" render presence only; anything else shows the
 * number. Works with `hidden` plus an inline display so author CSS such as
 * `display: grid` cannot defeat the hidden state.
 */
export function syncNotificationBadges(count) {
    const unread = Math.max(0, Number(count) || 0);

    document.querySelectorAll('[data-notification-badge]').forEach(element => {
        const dot = element.dataset.notificationBadge === 'dot';

        if (!dot) {
            element.textContent = String(unread);
            element.setAttribute('aria-label', `${unread} unread notification${unread === 1 ? '' : 's'}`);
        }

        element.hidden = unread === 0;
        if (element.style) element.style.display = unread === 0 ? 'none' : '';
    });

    const navCount = document.getElementById('notifications-nav-count');
    if (navCount) {
        navCount.textContent = String(unread);
        navCount.hidden = unread === 0;
    }

    window.dispatchEvent(new CustomEvent('bearly:notifications-changed', { detail: { unread } }));
}

const BUYER_ICONS = {
    buyer_order_updates: 'receipt_long',
    buyer_shipment_updates: 'local_shipping',
    buyer_return_updates: 'assignment_return',
    buyer_message_received: 'chat',
    account_status_changed: 'verified_user',
};

const OPS_TYPES = ['success', 'warning', 'info'];

export const opsType = type => (OPS_TYPES.includes(type) ? type : type === 'danger' ? 'warning' : 'info');

export const opsIcon = type => ({
    success: 'circle-check',
    warning: 'triangle-alert',
    info: 'info',
}[opsType(type)]);

const actionAttributes = item => `data-notification-id="${escapeHtml(item.id)}" data-notification-url="${escapeHtml(item.url || '')}"`;

const rowBuyer = item => `
    <button type="button" class="buyer-notification-item${item.read ? '' : ' unread'}" ${actionAttributes(item)}>
        <span class="buyer-notification-unread-dot" aria-hidden="true"></span>
        <span class="buyer-notification-icon material-symbols-outlined" aria-hidden="true">${BUYER_ICONS[item.event] || 'notifications'}</span>
        <span class="buyer-notification-copy">
            <strong>${escapeHtml(item.title)}</strong>
            <span>${escapeHtml(item.body)}</span>
            <small>${escapeHtml(item.created_at_human || '')}</small>
        </span>
        <span class="material-symbols-outlined buyer-notification-arrow" aria-hidden="true">chevron_right</span>
    </button>`;

/*
 * Standard popover row shared by the Seller, Admin, Logistics, and Rider
 * popovers: unread dot, round icon, copy (title / body / relative time), and
 * a chevron. Each role stylesheet themes the same structure so every popover
 * keeps one visual language.
 */
const rowStandard = item => `
    <button type="button" class="notification-row${item.read ? '' : ' unread'}" ${actionAttributes(item)}>
        <span class="notification-unread-dot" aria-hidden="true"></span>
        <span class="notification-icon" aria-hidden="true"><i data-lucide="${opsIcon(item.type)}"></i></span>
        <span class="notification-copy">
            <strong>${escapeHtml(item.title)}</strong>
            <span>${escapeHtml(item.body)}</span>
            <small>${escapeHtml(item.created_at_human || '')}</small>
        </span>
        <i class="notification-chevron" data-lucide="chevron-right" aria-hidden="true"></i>
    </button>`;

const SKIN_ROWS = {
    buyer: rowBuyer,
    seller: rowStandard,
    admin: rowStandard,
    logistics: rowStandard,
    rider: rowStandard,
};

const summaryFor = unread => (unread
    ? `${unread} unread update${unread === 1 ? '' : 's'}`
    : 'You are all caught up');

function initRoot(root) {
    const list = root.querySelector('[data-notification-list]');
    if (!list) return null;

    const skin = root.dataset.notificationSkin || 'buyer';
    const limit = Math.max(1, Math.min(Number(root.dataset.notificationLimit || 5) || 5, 50));
    const render = SKIN_ROWS[skin] || rowBuyer;
    const state = { items: [], unread: 0 };

    const paint = () => {
        list.innerHTML = state.items.map(render).join('');

        const empty = root.querySelector('[data-notification-empty]');
        if (empty) {
            const hide = state.items.length !== 0;
            empty.hidden = hide;
            if (empty.style) empty.style.display = hide ? 'none' : '';
        }

        root.querySelectorAll('[data-notification-summary]').forEach(summary => {
            summary.textContent = summaryFor(state.unread);
        });

        const markAll = root.querySelector('[data-notification-mark-all]');
        if (markAll) {
            const hide = state.unread === 0;
            markAll.hidden = hide;
            if (markAll.style) markAll.style.display = hide ? 'none' : '';
        }

        window.lucide?.createIcons?.();
    };

    const refresh = async () => {
        try {
            const data = await loadNotifications(limit);
            state.items = Array.isArray(data.items) ? data.items : [];
            state.unread = Number(data.unread_count || 0);
            paint();
            syncNotificationBadges(state.unread);
        } catch (error) {
            /* Unauthenticated or offline pages keep the last rendered state. */
        }
    };

    list.addEventListener('click', async event => {
        const row = event.target.closest('[data-notification-id]');
        if (!row) return;

        const id = row.dataset.notificationId;
        const url = row.dataset.notificationUrl;

        try {
            state.unread = await markNotificationRead(id);
            state.items = state.items.map(item => (item.id === id ? { ...item, read: true } : item));
            paint();
            syncNotificationBadges(state.unread);
        } catch (error) {
            /* Navigation still proceeds; the row simply stays unread for the next load. */
        }

        if (url) window.location.href = url;
    });

    root.querySelector('[data-notification-mark-all]')?.addEventListener('click', async () => {
        try {
            const unread = await markAllNotificationsRead();
            state.unread = unread;
            state.items = state.items.map(item => ({ ...item, read: true }));
            paint();
            syncNotificationBadges(unread);
            window.lucide?.createIcons?.();
        } catch (error) {
            /* Keep the previous visual state on failure. */
        }
    });

    return { refresh };
}

let instances = null;
let refreshing = false;

export async function refreshNotificationCenters() {
    if (instances === null) return;
    if (refreshing) return;

    refreshing = true;

    try {
        await Promise.all(instances.map(instance => instance.refresh()));
    } finally {
        refreshing = false;
    }
}

function boot() {
    if (window.__bearlyNotificationCenterBooted) return;
    window.__bearlyNotificationCenterBooted = true;

    instances = [...document.querySelectorAll('[data-notification-center]')]
        .map(initRoot)
        .filter(Boolean);

    if (!instances.length) return;

    refreshNotificationCenters();

    let clickTimer = null;
    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-popover-toggle="notifications"],[data-seller-popover-toggle="notifications"],[data-notification-trigger]');
        if (!trigger) return;

        window.clearTimeout(clickTimer);
        clickTimer = window.setTimeout(refreshNotificationCenters, 350);
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') refreshNotificationCenters();
    });

    /*
     * realtime.js dispatches this when a private notification event arrives.
     * Debounce so a burst of events causes one refresh; the 60-second
     * interval below remains the fallback when Reverb is unavailable.
     */
    let realtimeRefreshTimer = null;
    window.addEventListener('bearly:notification-created', () => {
        window.clearTimeout(realtimeRefreshTimer);
        realtimeRefreshTimer = window.setTimeout(refreshNotificationCenters, 250);
    });

    window.setInterval(refreshNotificationCenters, 60000);

    window.BearlyNotifications = {
        refresh: refreshNotificationCenters,
        syncBadges: syncNotificationBadges,
    };
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
