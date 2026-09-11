/* Bearly Seller Polish — supplemental frontend behavior. */
document.addEventListener('DOMContentLoaded', () => {
    /*
     * Stable Seller sidebar navigation.
     * Laravel page navigation reloads the document, which normally resets the
     * sidebar/nav scroll position. Save the nav's scrollTop in sessionStorage
     * and restore it on the next Seller page.
     */
    const sellerSidebar = document.querySelector('[data-seller-sidebar]');
    const sellerNav = sellerSidebar?.querySelector('.seller-nav');
    const sellerNavScrollKey = 'bearlySellerNavScrollTop';

    if (sellerNav) {
        const saveSellerNavScroll = () => {
            try {
                window.sessionStorage.setItem(sellerNavScrollKey, String(sellerNav.scrollTop));
            } catch (_) {}
        };

        let restored = false;
        try {
            const saved = window.sessionStorage.getItem(sellerNavScrollKey);
            if (saved !== null) {
                const scrollTop = Number(saved);
                if (Number.isFinite(scrollTop)) {
                    window.requestAnimationFrame(() => {
                        sellerNav.scrollTop = scrollTop;
                    });
                    restored = true;
                }
            }
        } catch (_) {}

        /*
         * First visit only: keep the active page visible.
         * On later navigation, preserving the user's exact sidebar position
         * takes priority so the menu does not jump.
         */
        if (!restored) {
            const activeSellerNavItem = sellerNav.querySelector('.seller-nav-child.is-active, .seller-nav-link.is-active');
            if (activeSellerNavItem) {
                window.requestAnimationFrame(() => {
                    activeSellerNavItem.scrollIntoView({
                        block: 'nearest',
                        inline: 'nearest',
                    });
                });
            }
        }

        sellerNav.addEventListener('scroll', saveSellerNavScroll, { passive: true });

        sellerNav.querySelectorAll('a[href]').forEach((link) => {
            link.addEventListener('click', saveSellerNavScroll);
        });

        window.addEventListener('pagehide', saveSellerNavScroll);
    }

    const formatPeso = (value) => `₱${Math.max(0, Number(value || 0)).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;

    /* Product commission preview. Existing seller.js still owns buyer-price preview. */
    const productEditor = document.querySelector('[data-product-editor]');
    if (productEditor) {
        const priceInput = productEditor.querySelector('[data-product-price]');
        const discountInput = productEditor.querySelector('[data-product-discount]');
        const commissionableNode = productEditor.querySelector('[data-product-commissionable]');
        const commissionNode = productEditor.querySelector('[data-product-commission]');
        const netNode = productEditor.querySelector('[data-product-net]');
        const rate = Number(productEditor.dataset.platformCommissionRate || 0.10);

        const updateCommissionPreview = () => {
            const price = Math.max(0, Number(priceInput?.value || 0));
            const discount = Math.min(90, Math.max(0, Number(discountInput?.value || 0)));
            const commissionable = price * (1 - (discount / 100));
            const commission = commissionable * rate;
            const net = commissionable - commission;
            if (commissionableNode) commissionableNode.textContent = formatPeso(commissionable);
            if (commissionNode) commissionNode.textContent = `−${formatPeso(commission)}`;
            if (netNode) netNode.textContent = formatPeso(net);
        };

        priceInput?.addEventListener('input', updateCommissionPreview);
        discountInput?.addEventListener('input', updateCommissionPreview);
        updateCommissionPreview();
    }

    /* Rich order modal: use the row as the source of preview details. */
    const orderModal = document.querySelector('[data-modal="order-details"]');
    const fillOrderModal = (trigger) => {
        if (!orderModal) return;
        const row = trigger.closest('[data-order-row]');
        if (!row) return;

        const assign = (selector, value) => {
            const node = orderModal.querySelector(selector);
            if (node) node.textContent = value || '—';
        };

        assign('[data-order-detail-id]', trigger.dataset.orderDetails || row.querySelector('[data-order-details]')?.dataset.orderDetails);
        assign('[data-order-detail-customer]', row.dataset.orderCustomer);
        assign('[data-order-detail-items]', row.dataset.orderItems);
        assign('[data-order-detail-payment]', row.dataset.orderPaymentCopy);
        assign('[data-order-detail-total]', row.dataset.orderTotal);
        assign('[data-order-detail-canonical]', row.dataset.orderCanonical);
        assign('[data-order-detail-owner]', row.dataset.orderOwner);
        assign('[data-order-detail-next]', row.dataset.orderNext);

        const action = orderModal.querySelector('[data-order-modal-action]');
        const rowAction = row.querySelector('.order-row-action');
        if (action && rowAction) action.textContent = rowAction.textContent.trim();
    };

    document.querySelectorAll('[data-order-details]').forEach((trigger) => {
        trigger.addEventListener('click', () => fillOrderModal(trigger));
    });

    /* Finance transaction ledger filters. */
    const financeLedger = document.querySelector('[data-finance-ledger]');
    if (financeLedger) {
        const rows = [...financeLedger.querySelectorAll('[data-finance-row]')];
        const search = financeLedger.querySelector('[data-finance-search]');
        const status = financeLedger.querySelector('[data-finance-status]');
        const count = financeLedger.querySelector('[data-finance-count]');
        const empty = financeLedger.querySelector('[data-finance-empty]');

        const applyFinanceFilters = () => {
            const q = search?.value.trim().toLowerCase() || '';
            const selected = status?.value || '';
            let visible = 0;
            rows.forEach((row) => {
                const matches = (!q || row.dataset.search.includes(q)) && (!selected || row.dataset.status === selected);
                row.hidden = !matches;
                if (matches) visible += 1;
            });
            if (count) count.textContent = visible;
            if (empty) empty.hidden = visible > 0;
        };

        search?.addEventListener('input', applyFinanceFilters);
        status?.addEventListener('change', applyFinanceFilters);
        financeLedger.querySelector('[data-finance-reset]')?.addEventListener('click', () => {
            if (search) search.value = '';
            if (status) status.value = '';
            applyFinanceFilters();
        });
        applyFinanceFilters();
    }

    /* Re-run icons after supplemental markup has loaded. */
    if (window.lucide?.createIcons) window.lucide.createIcons();
});
