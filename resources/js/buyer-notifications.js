import './notification-center.js';

/*
 * Buyer notification popover behaviour: open, close, and positioning only.
 * Unread counts, history, mark-as-read, and mark-all come from
 * notification-center.js and the real authenticated /notifications endpoints.
 */
(() => {
    if (window.BearlyNotificationPopover) return;

    const init = menu => {
        const trigger = menu.querySelector('[data-notification-trigger]');
        const popover = menu.querySelector('[data-notification-popover]');
        if (!trigger || !popover) return null;

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

        const open = () => {
            popover.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            menu.classList.add('is-open');
            setPosition();
            requestAnimationFrame(() => menu.querySelector('[data-notification-popover-close]')?.focus());
        };

        trigger.addEventListener('click', event => {
            event.preventDefault();
            if (popover.hidden) open();
            else close({ restoreFocus: true });
        });

        menu.querySelector('[data-notification-popover-close]')?.addEventListener('click', () => close({ restoreFocus: true }));
        menu.querySelector('[data-notification-view-all]')?.addEventListener('click', () => close());

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

        return { open, close };
    };

    const instances = [...document.querySelectorAll('[data-notification-menu]')]
        .map(init)
        .filter(Boolean);

    window.BearlyNotificationPopover = {
        open: () => instances[0]?.open(),
        close: () => instances.forEach(instance => instance.close()),
    };
})();
