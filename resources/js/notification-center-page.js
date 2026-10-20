/*
 * Bearly full-page Notification Center.
 *
 * One controller per role page. Category filters, search, unread state, and
 * pagination are executed by the authenticated /notifications endpoint; this
 * module only renders what the backend returns for the signed-in user.
 */
import {
    escapeHtml,
    loadNotificationHistory,
    markAllNotificationsRead,
    markNotificationRead,
    opsIcon,
    opsType,
    syncNotificationBadges,
} from './notification-center.js';

const BUYER_EVENT_ICONS = {
    buyer_order_updates: 'receipt_long',
    buyer_shipment_updates: 'local_shipping',
    buyer_return_updates: 'assignment_return',
    buyer_message_received: 'chat',
    announcement_published: 'campaign',
    account_status_changed: 'verified_user',
};

const OPS_EVENT_ICONS = {
    seller_order_updates: 'clipboard-list',
    seller_order_cancelled: 'circle-slash-2',
    seller_return_updates: 'package-x',
    seller_pickup_updates: 'package-plus',
    seller_shipment_updates: 'truck',
    seller_buyer_message: 'message-circle-more',
    seller_withdrawal_status_changed: 'coins',
    logistics_pickup_requested: 'package-plus',
    logistics_handoff_sent: 'arrow-left-right',
    logistics_handoff_accepted: 'package-check',
    logistics_cod_collected: 'banknote',
    logistics_refund_cash_issued: 'hand-coins',
    logistics_rider_application_submitted: 'user-check',
    logistics_message_received: 'message-circle-more',
    rider_pickup_assigned: 'package-plus',
    rider_dispatch_assigned: 'bike',
    rider_refund_cash_assigned: 'hand-coins',
    announcement_published: 'megaphone',
    account_status_changed: 'shield-alert',
};

const iconFor = (skin, item) => {
    if (skin === 'buyer') {
        const icon = BUYER_EVENT_ICONS[item.event] || 'notifications';

        return `<span class="nc-row-icon material-symbols-outlined" aria-hidden="true">${escapeHtml(icon)}</span>`;
    }

    const type = opsType(item.type);
    const icon = OPS_EVENT_ICONS[item.event] || opsIcon(item.type);

    return `<span class="nc-row-icon nc-icon-${type}"><i data-lucide="${escapeHtml(icon)}" aria-hidden="true"></i></span>`;
};

const rowFor = (skin, item) => {
    const unread = !item.read;

    return `<article class="nc-row${unread ? ' is-unread' : ''}" data-nc-id="${escapeHtml(item.id)}" data-nc-url="${escapeHtml(item.url || '')}" tabindex="0" role="button" aria-label="${escapeHtml(item.title)}${unread ? ' (unread)' : ''}">
        <span class="nc-row-dot" aria-hidden="true"></span>
        ${iconFor(skin, item)}
        <div class="nc-row-copy">
            <div class="nc-row-head">
                <strong>${escapeHtml(item.title)}</strong>
                <time datetime="${escapeHtml(item.created_at || '')}">${escapeHtml(item.created_at_human || '')}</time>
            </div>
            ${item.body ? `<p>${escapeHtml(item.body)}</p>` : ''}
        </div>
        ${item.url ? '<span class="nc-row-arrow" aria-hidden="true">&rsaquo;</span>' : ''}
    </article>`;
};

const skeletonFor = () => Array.from({ length: 4 }, () => `
    <div class="nc-skeleton" aria-hidden="true">
        <span class="nc-skeleton-dot"></span>
        <span class="nc-skeleton-lines"><i></i><i></i></span>
    </div>`).join('');

const initCenter = root => {
    if (!root || root.dataset.ncReady === 'true') return;

    const skin = root.dataset.skin || 'buyer';
    const perPage = Math.max(1, Math.min(Number(root.dataset.perPage || 20) || 20, 50));
    const list = root.querySelector('[data-nc-list]');
    const empty = root.querySelector('[data-nc-empty]');
    const error = root.querySelector('[data-nc-error]');
    const status = root.querySelector('[data-nc-status]');
    const pager = root.querySelector('[data-nc-pager]');
    const pageInfo = root.querySelector('[data-nc-page-info]');
    const prev = root.querySelector('[data-nc-prev]');
    const next = root.querySelector('[data-nc-next]');
    const markAll = root.querySelector('[data-nc-mark-all]');
    const searchInput = root.querySelector('[data-nc-search]');
    const tabs = [...root.querySelectorAll('[data-nc-category]')];

    if (!list) return;

    root.dataset.ncReady = 'true';

    const activeTab = () => tabs.find(tab => tab.getAttribute('aria-selected') === 'true') || tabs[0];

    const state = {
        category: activeTab()?.dataset.ncCategory || 'all',
        search: '',
        page: 1,
        lastPage: 1,
        total: 0,
        items: [],
        unread: 0,
        loading: false,
    };

    let refreshTimer = null;
    let refreshPending = false;

    const scheduleRefresh = () => {
        window.clearTimeout(refreshTimer);

        refreshTimer = window.setTimeout(() => {
            refreshTimer = null;

            if (state.loading) {
                refreshPending = true;
                return;
            }

            fetchPage(state.page);
        }, 250);
    };

    const emptyCopy = () => {
        const filtered = state.search !== '' || state.category !== 'all';

        return filtered
            ? [root.dataset.emptyFilteredTitle || 'No notifications match', root.dataset.emptyFilteredBody || 'Try another filter or search term.']
            : [root.dataset.emptyTitle || 'No notifications yet', root.dataset.emptyBody || 'New Bearly updates will appear here.'];
    };

    const paint = () => {
        list.innerHTML = state.items.map(item => rowFor(skin, item)).join('');

        const hasItems = state.items.length > 0;
        list.hidden = !hasItems;

        if (empty) {
            const [title, body] = emptyCopy();
            const heading = empty.querySelector('[data-nc-empty-title]');
            const copy = empty.querySelector('[data-nc-empty-body]');

            if (heading) heading.textContent = title;
            if (copy) copy.textContent = body;

            empty.hidden = hasItems;
        }

        if (markAll) {
            markAll.hidden = state.unread === 0;
        }

        if (pager) {
            pager.hidden = state.lastPage <= 1;
        }

        if (pageInfo) {
            pageInfo.textContent = state.total > 0
                ? `Page ${state.page} of ${state.lastPage} \u00b7 ${state.total} notification${state.total === 1 ? '' : 's'}`
                : '';
        }

        if (prev) prev.disabled = state.page <= 1;
        if (next) next.disabled = state.page >= state.lastPage;

        if (status) {
            status.textContent = hasItems ? `Showing ${state.items.length} of ${state.total} notifications.` : '';
        }

        window.lucide?.createIcons?.();
    };

    const showError = () => {
        list.innerHTML = '';
        list.hidden = true;
        if (empty) empty.hidden = true;
        if (error) error.hidden = false;
        if (pager) pager.hidden = true;
        if (status) status.textContent = '';
    };

    const fetchPage = async page => {
        if (state.loading) return;

        state.loading = true;
        root.classList.add('is-loading');
        list.setAttribute('aria-busy', 'true');
        if (error) error.hidden = true;

        if (state.items.length === 0) {
            list.hidden = false;
            list.innerHTML = skeletonFor();
        }

        try {
            const data = await loadNotificationHistory({
                perPage,
                page,
                category: state.category,
                search: state.search,
            });

            state.items = Array.isArray(data.items) ? data.items : [];
            state.unread = Number(data.unread_count || 0);
            state.page = Math.max(1, Number(data.current_page) || page);
            state.lastPage = Math.max(1, Number(data.last_page) || 1);
            state.total = Number(data.total ?? state.items.length) || 0;

            paint();
            syncNotificationBadges(state.unread);
        } catch (loadError) {
            showError();
        } finally {
            state.loading = false;
            root.classList.remove('is-loading');
            list.setAttribute('aria-busy', 'false');

            if (refreshPending) {
                refreshPending = false;
                fetchPage(state.page);
            }
        }
    };

    const activateTab = (tab, { fetch = true } = {}) => {
        if (!tab) return;

        tabs.forEach(candidate => {
            const active = candidate === tab;

            candidate.classList.toggle('is-active', active);
            candidate.setAttribute('aria-selected', active ? 'true' : 'false');
            candidate.tabIndex = active ? 0 : -1;
        });

        state.category = tab.dataset.ncCategory || 'all';
        state.page = 1;

        if (fetch) fetchPage(1);
    };

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activateTab(tab));

        tab.addEventListener('keydown', event => {
            const keys = ['ArrowRight', 'ArrowLeft', 'Home', 'End'];
            if (!keys.includes(event.key)) return;

            event.preventDefault();

            let target = index;

            if (event.key === 'ArrowRight') target = (index + 1) % tabs.length;
            if (event.key === 'ArrowLeft') target = (index - 1 + tabs.length) % tabs.length;
            if (event.key === 'Home') target = 0;
            if (event.key === 'End') target = tabs.length - 1;

            activateTab(tabs[target]);
            tabs[target].focus();
        });
    });

    list.addEventListener('click', event => {
        const row = event.target.closest('[data-nc-id]');
        if (row) openRow(row);
    });

    list.addEventListener('keydown', event => {
        if (event.key !== 'Enter' && event.key !== ' ') return;

        const row = event.target.closest('[data-nc-id]');
        if (!row) return;

        event.preventDefault();
        openRow(row);
    });

    const openRow = async row => {
        const id = row.dataset.ncId;
        const url = row.dataset.ncUrl;
        const item = state.items.find(candidate => candidate.id === id);

        if (item && !item.read) {
            try {
                state.unread = await markNotificationRead(id);
                state.items = state.items.map(candidate => (candidate.id === id ? { ...candidate, read: true } : candidate));
                row.classList.remove('is-unread');
                row.setAttribute('aria-label', row.getAttribute('aria-label').replace(' (unread)', ''));
                syncNotificationBadges(state.unread);
                if (markAll) markAll.hidden = state.unread === 0;
            } catch (readError) {
                /* Navigation still proceeds; the row stays unread for the next load. */
            }
        }

        if (url) window.location.href = url;
    };

    if (markAll) {
        markAll.addEventListener('click', async () => {
            try {
                state.unread = await markAllNotificationsRead();
            } catch (markError) {
                return;
            }

            state.items = state.items.map(item => ({ ...item, read: true }));
            syncNotificationBadges(state.unread);

            if (state.category === 'unread') {
                await fetchPage(1);
                return;
            }

            paint();
        });
    }

    if (searchInput) {
        let debounce = null;

        const applySearch = () => {
            const value = searchInput.value.trim();

            if (value === state.search) return;

            state.search = value;
            state.page = 1;
            fetchPage(1);
        };

        searchInput.addEventListener('input', () => {
            window.clearTimeout(debounce);
            debounce = window.setTimeout(applySearch, 300);
        });

        searchInput.addEventListener('search', () => {
            window.clearTimeout(debounce);
            applySearch();
        });
    }

    if (prev) {
        prev.addEventListener('click', () => {
            if (state.page <= 1) return;
            root.scrollIntoView({ behavior: 'smooth', block: 'start' });
            fetchPage(state.page - 1);
        });
    }

    if (next) {
        next.addEventListener('click', () => {
            if (state.page >= state.lastPage) return;
            root.scrollIntoView({ behavior: 'smooth', block: 'start' });
            fetchPage(state.page + 1);
        });
    }

    root.querySelector('[data-nc-retry]')?.addEventListener('click', () => fetchPage(state.page));

    window.BearlyNotificationCenterPage = {
        refresh: () => fetchPage(state.page),
    };

    window.addEventListener('bearly:notification-created', scheduleRefresh);

    fetchPage(1);
};

const boot = () => {
    document.querySelectorAll('[data-notification-center-page]').forEach(initCenter);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
