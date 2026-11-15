document.querySelectorAll('[data-buyer-account-menu]').forEach((menu) => {
    const trigger = menu.querySelector('[data-buyer-account-trigger]');
    const dropdown = menu.querySelector('[data-buyer-account-dropdown]');
    if (!trigger || !dropdown) return;

    let pointerInside = false;
    let pinnedOpen = false;
    let suppressFocusOpen = false;

    const setOpen = (open) => {
        dropdown.hidden = !open;
        trigger.setAttribute('aria-expanded', String(open));
        menu.dataset.open = String(open);
    };

    const close = ({ returnFocus = false } = {}) => {
        pinnedOpen = false;
        setOpen(false);

        if (returnFocus) {
            suppressFocusOpen = true;
            trigger.focus({ preventScroll: true });
            suppressFocusOpen = false;
        }
    };

    menu.addEventListener('pointerenter', (event) => {
        if (event.pointerType === 'touch') return;

        pointerInside = true;
        setOpen(true);
    });

    menu.addEventListener('pointerleave', (event) => {
        if (event.pointerType === 'touch') return;

        pointerInside = false;
        if (!pinnedOpen && !menu.contains(document.activeElement)) setOpen(false);
    });

    menu.addEventListener('focusin', () => {
        if (!suppressFocusOpen) setOpen(true);
    });

    menu.addEventListener('focusout', (event) => {
        if (menu.contains(event.relatedTarget)) return;
        if (!pinnedOpen && !pointerInside) setOpen(false);
    });

    trigger.addEventListener('click', () => {
        if (pinnedOpen) {
            close();
            return;
        }

        pinnedOpen = true;
        setOpen(true);
    });

    menu.addEventListener('click', (event) => {
        if (event.target.closest('.buyer-account-dropdown a')) close();
    });

    document.addEventListener('pointerdown', (event) => {
        if (menu.contains(event.target)) return;

        pointerInside = false;
        close();
    });

    menu.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || dropdown.hidden) return;

        event.preventDefault();
        close({ returnFocus: true });
    });

    const syncCurrentLink = () => {
        const pathname = window.location.pathname.replace(/\/+$/, '') || '/';
        let currentSection = window.location.hash.slice(1);
        if (!currentSection && pathname.endsWith('/addresses')) currentSection = 'addresses';
        if (!currentSection && pathname.endsWith('/notifications')) currentSection = 'notifications';
        if (!currentSection && pathname.endsWith('/profile')) currentSection = 'profile';
        if (pathname.endsWith('/profile') && window.bearlyCurrentAccountPanel) {
            currentSection = window.bearlyCurrentAccountPanel;
        }
        if (currentSection === 'history' || currentSection === 'tracking') currentSection = 'purchases';

        menu.querySelectorAll('[data-buyer-account-link]').forEach((link) => {
            const isCurrent = link.dataset.buyerAccountLink === currentSection;
            link.classList.toggle('is-current', isCurrent);
            if (isCurrent) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
    };

    syncCurrentLink();
    window.addEventListener('hashchange', syncCurrentLink);
    window.addEventListener('bearly:account-panel-changed', syncCurrentLink);
});
