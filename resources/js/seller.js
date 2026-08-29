const bootSeller = () => {
    window.lucide?.createIcons();

    const shell = document.querySelector('[data-seller-shell]');
    document.querySelector('[data-seller-menu]')?.addEventListener('click', () => shell?.classList.add('menu-open'));
    document.querySelector('[data-seller-overlay]')?.addEventListener('click', () => shell?.classList.remove('menu-open'));

    const closePopovers = (except = null) => {
        document.querySelectorAll('[data-seller-popover]').forEach((popover) => {
            if (popover !== except) popover.hidden = true;
        });
    };

    document.querySelectorAll('[data-seller-popover-toggle]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            const popover = document.querySelector(`[data-seller-popover="${button.dataset.sellerPopoverToggle}"]`);
            if (!popover) return;
            const shouldOpen = popover.hidden;
            closePopovers(popover);
            popover.hidden = !shouldOpen;
        });
    });

    document.addEventListener('click', () => closePopovers());
    document.querySelectorAll('[data-seller-popover]').forEach((popover) => popover.addEventListener('click', (event) => event.stopPropagation()));

    const toast = document.querySelector('[data-seller-toast]');
    document.querySelectorAll('[data-preview-link]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            if (!toast) return;
            toast.textContent = `${link.dataset.previewLink} page will be built after the dashboard.`;
            toast.classList.add('is-visible');
            window.clearTimeout(window.sellerToastTimer);
            window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 2600);
        });
    });
};

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootSeller);
else bootSeller();
