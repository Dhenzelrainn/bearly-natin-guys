import {
    countUnreadPreviewNotifications,
    getPreviewNotifications,
    savePreviewNotifications,
} from './buyer-notification-data.js';

(() => {
    if (window.BearlyNotificationPopover) return;

    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[character]));

    const safeImage = source => {
        const value = String(source || '');
        return /^(\/images\/|\/storage\/)/.test(value)
            ? value
            : '/images/bearly-logo.png';
    };

    const targetHref = item => item.actionHref || ({
        purchases: '/profile#purchases',
        reviews: '/profile#reviews',
        vouchers: '/profile#vouchers',
        home: '/home#results',
    }[item.target] || '');

    const updateBadges = items => {
        const count = countUnreadPreviewNotifications(items);

        document.querySelectorAll('[data-notification-badge]').forEach(badge => {
            badge.textContent = count;
            badge.hidden = count === 0;
            badge.style.display = count === 0 ? 'none' : '';
            badge.setAttribute('aria-label', `${count} unread notification${count === 1 ? '' : 's'}`);
        });

        const profileCount = document.getElementById('notifications-nav-count');
        if (profileCount) {
            profileCount.textContent = count;
            profileCount.hidden = count === 0;
        }
    };

    const init = menu => {
        const trigger = menu.querySelector('[data-notification-trigger]');
        const popover = menu.querySelector('[data-notification-popover]');
        const list = menu.querySelector('[data-notification-popover-list]');
        const empty = menu.querySelector('[data-notification-popover-empty]');
        const summary = menu.querySelector('[data-notification-summary]');
        if (!trigger || !popover || !list) return null;

        const setPosition = () => {
            if (popover.hidden || !window.matchMedia('(max-width: 720px)').matches) return;

            const header = menu.closest('header');
            const headerBottom = header?.getBoundingClientRect().bottom || 0;
            popover.style.setProperty('--buyer-notification-popover-top', `${Math.max(8, headerBottom + 8)}px`);
        };

        const close = ({ restoreFocus = false } = {}) => {
            popover.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            menu.classList.remove('is-open');
            if (restoreFocus) trigger.focus();
        };

        const render = () => {
            const items = getPreviewNotifications();
            const unread = countUnreadPreviewNotifications(items);
            updateBadges(items);

            if (summary) {
                summary.textContent = unread
                    ? `${unread} unread update${unread === 1 ? '' : 's'}`
                    : 'You are all caught up';
            }

            const recentItems = items.slice(0, 5);
            list.innerHTML = recentItems.map(item => `
                <button
                    type="button"
                    class="buyer-notification-item${item.read ? '' : ' unread'}"
                    data-notification-id="${escapeHtml(item.id)}"
                >
                    <span class="buyer-notification-unread-dot" aria-hidden="true"></span>
                    <span class="buyer-notification-icon material-symbols-outlined" aria-hidden="true">${escapeHtml(item.icon)}</span>
                    <span class="buyer-notification-copy">
                        <strong>${escapeHtml(item.title)}</strong>
                        <span>${escapeHtml(item.message)}</span>
                        <small>${escapeHtml(item.time)}</small>
                    </span>
                    <span class="material-symbols-outlined buyer-notification-arrow" aria-hidden="true">chevron_right</span>
                    ${item.image ? `<img class="buyer-notification-image" src="${escapeHtml(safeImage(item.image))}" alt="" loading="lazy">` : ''}
                </button>
            `).join('');

            if (empty) empty.hidden = recentItems.length !== 0;
            setPosition();
        };

        const open = () => {
            render();
            popover.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            menu.classList.add('is-open');
            setPosition();
            requestAnimationFrame(() => menu.querySelector('[data-notification-popover-close]')?.focus());
        };

        const toggle = () => {
            if (popover.hidden) open();
            else close({ restoreFocus: true });
        };

        trigger.addEventListener('click', event => {
            event.preventDefault();
            toggle();
        });

        menu.querySelector('[data-notification-popover-close]')?.addEventListener('click', () => close({ restoreFocus: true }));

        menu.querySelector('[data-notification-popover-mark-all]')?.addEventListener('click', () => {
            savePreviewNotifications(getPreviewNotifications().map(item => ({ ...item, read: true })));
            render();
        });

        menu.querySelector('[data-notification-view-all]')?.addEventListener('click', () => close());

        list.addEventListener('click', event => {
            const itemButton = event.target.closest('[data-notification-id]');
            if (!itemButton) return;

            const items = getPreviewNotifications();
            const item = items.find(candidate => candidate.id === itemButton.dataset.notificationId);
            if (!item) return;

            item.read = true;
            savePreviewNotifications(items);
            close();

            const href = targetHref(item);
            if (href) window.location.href = href;
        });

        document.addEventListener('click', event => {
            if (!popover.hidden && !menu.contains(event.target)) close();
        });

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && !popover.hidden) {
                event.preventDefault();
                close({ restoreFocus: true });
            }
        });

        window.addEventListener('resize', setPosition);
        window.addEventListener('scroll', setPosition, { passive: true });
        window.addEventListener('storage', render);
        window.addEventListener('bearly:notifications-changed', render);

        render();

        return { open, close, render };
    };

    const instances = [...document.querySelectorAll('[data-notification-menu]')]
        .map(init)
        .filter(Boolean);

    window.BearlyNotificationPopover = {
        open: () => instances[0]?.open(),
        close: () => instances.forEach(instance => instance.close()),
        render: () => instances.forEach(instance => instance.render()),
    };
})();
