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

    document.querySelectorAll('[data-modal-open]').forEach((button) => {
        button.addEventListener('click', () => {
            const modal = document.querySelector(`[data-modal="${button.dataset.modalOpen}"]`);
            if (!modal) return;
            modal.hidden = false;
            document.body.style.overflow = 'hidden';
            modal.querySelector('input:not([type="hidden"])')?.focus();
        });
    });

    document.querySelectorAll('[data-modal-close]').forEach((button) => {
        button.addEventListener('click', () => {
            button.closest('[data-modal]').hidden = true;
            document.body.style.overflow = '';
        });
    });

    const description = document.querySelector('[data-description]');
    const descriptionCount = document.querySelector('[data-description-count]');
    description?.addEventListener('input', () => {
        if (descriptionCount) descriptionCount.textContent = description.value.length;
    });

    document.querySelectorAll('[data-photo-input]').forEach((input) => {
        input.addEventListener('change', () => {
            const file = input.files?.[0];
            const box = input.closest('[data-photo-upload]')?.querySelector('.upload-box');
            if (!file || !box) return;
            let preview = box.querySelector('[data-photo-preview]');
            if (!preview) {
                preview = document.createElement('img');
                preview.dataset.photoPreview = '';
                box.prepend(preview);
                box.querySelector('svg')?.remove();
            }
            preview.src = URL.createObjectURL(file);
        });
    });

    const applyProductFilters = () => {
        const search = document.querySelector('[data-product-search]')?.value.trim().toLowerCase() ?? '';
        const category = document.querySelector('[data-product-category]')?.value ?? '';
        const status = document.querySelector('[data-product-status]')?.value ?? '';
        let visible = 0;
        document.querySelectorAll('[data-product-row]').forEach((row) => {
            const matches = (!search || row.dataset.name.includes(search))
                && (!category || row.dataset.category === category)
                && (!status || row.dataset.status === status);
            row.hidden = !matches;
            if (matches) visible += 1;
        });
        const noResults = document.querySelector('[data-no-results]');
        if (noResults) noResults.hidden = visible > 0;
    };
    document.querySelector('[data-apply-filter]')?.addEventListener('click', applyProductFilters);
    document.querySelector('[data-product-search]')?.addEventListener('input', applyProductFilters);
    document.querySelectorAll('[data-product-category],[data-product-status]').forEach((select) => select.addEventListener('change', applyProductFilters));

    const serverToast = document.querySelector('[data-server-toast]');
    if (serverToast) window.setTimeout(() => serverToast.classList.remove('is-visible'), 3200);
};

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootSeller);
else bootSeller();
