import './notification-center.js';
import {
    acknowledgeIncoming,
    attachmentPreviewHtml,
    receiptMarkup,
    updateReceiptElements,
} from './chat-receipts.js';

const bootSeller = () => {
    window.lucide?.createIcons();

    const productFilterForm = document.querySelector('[data-product-filter-form]');
    productFilterForm?.querySelectorAll('[data-product-filter-auto-submit]').forEach((control) => {
        control.addEventListener('change', () => productFilterForm.requestSubmit());
    });

    const sellerWalletLive = document.querySelector('[data-seller-wallet-live]');
    if (sellerWalletLive) {
        const endpoint = sellerWalletLive.dataset.codCashStatusVersionUrl;
        const withdrawalModal = document.querySelector('[data-modal="wallet-withdrawal"]');
        let knownVersion = String(sellerWalletLive.dataset.codCashStatusVersion || '');
        let requestInFlight = false;
        let requestQueued = false;
        let reloadPending = false;

        const reloadWhenSafe = () => {
            if (withdrawalModal && !withdrawalModal.hidden) {
                reloadPending = true;
                return;
            }

            window.location.reload();
        };

        const refreshWalletStatus = async () => {
            if (!endpoint || document.hidden) return;
            if (requestInFlight) {
                requestQueued = true;
                return;
            }

            requestQueued = false;
            requestInFlight = true;
            try {
                const response = await fetch(endpoint, {
                    cache: 'no-store',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const result = await response.json().catch(() => ({}));
                const version = String(result?.data?.version || '');
                if (response.ok && version && version !== knownVersion) reloadPending = true;

                if (reloadPending && (!withdrawalModal || withdrawalModal.hidden)) {
                    window.location.reload();
                }
            } catch {
                // Retry on the next poll if the realtime connection or request is unavailable.
            } finally {
                requestInFlight = false;
                if (requestQueued && !document.hidden) {
                    requestQueued = false;
                    refreshWalletStatus();
                }
            }
        };

        window.addEventListener('bearly:notification-created', (event) => {
            const eventKey = String(event.detail?.event || '');
            if (eventKey === 'seller_cod_cash_reconciled') {
                refreshWalletStatus();
            } else if (eventKey === 'seller_withdrawal_status_changed') {
                reloadWhenSafe();
            }
        });
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) refreshWalletStatus();
        });
        window.setInterval(refreshWalletStatus, 15000);
    }

    const shell = document.querySelector('[data-seller-shell]');
    const menuButton = document.querySelector('[data-seller-menu]');
    const mobileMenuButton = document.querySelector('[data-seller-mobile-menu]');
    const mobileSidebar = window.matchMedia('(max-width: 820px)');
    const setSidebarCollapsed = (collapsed, remember = true) => {
        if (!shell || mobileSidebar.matches) return;
        shell.classList.toggle('sidebar-collapsed', collapsed);
        menuButton?.setAttribute('aria-expanded', String(!collapsed));
        menuButton?.setAttribute('aria-label', collapsed ? 'Expand seller navigation' : 'Collapse seller navigation');
        if (remember) {
            try { window.localStorage.setItem('bearlySellerSidebarCollapsed', String(collapsed)); } catch (_) {}
        }
    };
    if (!mobileSidebar.matches) {
        try { setSidebarCollapsed(window.localStorage.getItem('bearlySellerSidebarCollapsed') === 'true', false); } catch (_) {}
    } else {
        mobileMenuButton?.setAttribute('aria-expanded', 'false');
    }
    menuButton?.addEventListener('click', () => {
        if (mobileSidebar.matches) shell?.classList.remove('menu-open');
        else setSidebarCollapsed(!shell?.classList.contains('sidebar-collapsed'));
    });
    mobileMenuButton?.addEventListener('click', () => {
        const opened = shell?.classList.toggle('menu-open') ?? false;
        mobileMenuButton.setAttribute('aria-expanded', String(opened));
        mobileMenuButton.setAttribute('aria-label', opened ? 'Close seller navigation' : 'Open seller navigation');
    });
    document.querySelector('[data-seller-overlay]')?.addEventListener('click', () => {
        shell?.classList.remove('menu-open');
        mobileMenuButton?.setAttribute('aria-expanded', 'false');
        mobileMenuButton?.setAttribute('aria-label', 'Open seller navigation');
    });
    mobileSidebar.addEventListener('change', (event) => {
        shell?.classList.remove('menu-open');
        if (event.matches) {
            shell?.classList.remove('sidebar-collapsed');
            mobileMenuButton?.setAttribute('aria-expanded', 'false');
            mobileMenuButton?.setAttribute('aria-label', 'Open seller navigation');
        } else {
            try { setSidebarCollapsed(window.localStorage.getItem('bearlySellerSidebarCollapsed') === 'true', false); } catch (_) { setSidebarCollapsed(false, false); }
        }
    });

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

    const walletForm = document.querySelector('[data-wallet-withdrawal-form]');
    if (walletForm) {
        const amountInput = walletForm.querySelector('[data-wallet-amount]');
        const accountSelect = walletForm.querySelector('[data-wallet-bank-account]');
        const availableMinor = Number(walletForm.dataset.availableMinor || 0);
        const formatMoney = (minor) => `₱${(minor / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        const parseMinor = (value) => {
            const match = String(value || '').match(/^(\d{1,8})(?:\.(\d{1,2}))?$/);
            if (!match) return 0;
            return (Number(match[1]) * 100) + Number((match[2] || '').padEnd(2, '0'));
        };
        const updateAmountSummary = () => {
            const requested = Math.max(0, Math.min(availableMinor, parseMinor(amountInput?.value)));
            const amountSummary = walletForm.querySelector('[data-wallet-amount-summary]');
            const remainingSummary = walletForm.querySelector('[data-wallet-remaining-summary]');
            if (amountSummary) amountSummary.textContent = formatMoney(requested);
            if (remainingSummary) remainingSummary.textContent = formatMoney(availableMinor - requested);
        };
        const updateAccountSummary = () => {
            const option = accountSelect?.selectedOptions?.[0];
            walletForm.querySelector('[data-wallet-account-name]')?.replaceChildren(document.createTextNode(option?.dataset.accountName || 'Choose an account'));
            walletForm.querySelector('[data-wallet-bank-name]')?.replaceChildren(document.createTextNode(option?.dataset.bankName || '—'));
            walletForm.querySelector('[data-wallet-account-number]')?.replaceChildren(document.createTextNode(`•••• ${option?.dataset.last4 || '—'}`));
        };
        amountInput?.addEventListener('input', updateAmountSummary);
        accountSelect?.addEventListener('change', updateAccountSummary);
        walletForm.querySelector('[data-wallet-withdraw-all]')?.addEventListener('click', () => {
            if (!amountInput) return;
            amountInput.value = (availableMinor / 100).toFixed(2);
            amountInput.dispatchEvent(new Event('input', { bubbles: true }));
            amountInput.focus();
        });
        updateAmountSummary();
        updateAccountSummary();
    }

    const pickupModalWithErrors = document.querySelector('[data-pickup-reopen]');
    if (pickupModalWithErrors) {
        pickupModalWithErrors.hidden = false;
        document.body.style.overflow = 'hidden';
        pickupModalWithErrors.querySelector('[name="order_ids[]"]')?.focus();
    }

    const description = document.querySelector('[data-description]');
    const descriptionCount = document.querySelector('[data-description-count]');
    description?.addEventListener('input', () => {
        if (descriptionCount) descriptionCount.textContent = description.value.length;
    });

    const productDescription = document.querySelector('[data-product-description]');
    const productDescriptionCount = document.querySelector('[data-product-description-count]');
    productDescription?.addEventListener('input', () => {
        if (productDescriptionCount) productDescriptionCount.textContent = productDescription.value.length;
    });

    const productPrice = document.querySelector('[data-product-price]');
    const productEditor = document.querySelector('[data-product-editor]');
    const productDiscount = Math.min(90, Math.max(0, Number(productEditor?.dataset.productDiscountPercent || 0)));
    const productSalePrice = document.querySelector('[data-sale-price]');
    const updateSalePrice = () => {
        if (!productSalePrice) return;
        const price = Math.max(0, Number(productPrice?.value || 0));
        const salePrice = price * (1 - (productDiscount / 100));
        productSalePrice.textContent = `₱${salePrice.toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })}`;
    };
    productPrice?.addEventListener('input', updateSalePrice);

    const galleryInput = document.querySelector('input[name="gallery_images[]"]');
    galleryInput?.addEventListener('change', () => {
        const exceedsLimit = (galleryInput.files?.length ?? 0) > 4;
        galleryInput.setCustomValidity(exceedsLimit ? 'Choose up to four gallery images only.' : '');
        if (exceedsLimit) galleryInput.reportValidity();
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

    /* Orders page: frontend-only filtering, selection, and preview actions. */
    const orderWorkspace = document.querySelector('[data-orders-workspace]');

    if (orderWorkspace) {
        const defaultStatusNode = document.querySelector('[data-default-order-status]');
        let activeOrderStatus = defaultStatusNode
            ? JSON.parse(defaultStatusNode.textContent)
            : 'all';
        const orderRows = [...orderWorkspace.querySelectorAll('[data-order-row]')];
        const orderSearch = orderWorkspace.querySelector('[data-order-search]');
        const orderDate = orderWorkspace.querySelector('[data-order-date]');
        const orderPayment = orderWorkspace.querySelector('[data-order-payment]');
        const visibleCount = orderWorkspace.querySelector('[data-order-visible-count]');
        const noResults = orderWorkspace.querySelector('[data-orders-no-results]');

        const refreshOrderStatusCounts = () => {
            orderWorkspace.querySelectorAll('[data-order-tab]').forEach((tab) => {
                const count = tab.dataset.orderTab === 'all'
                    ? orderRows.length
                    : orderRows.filter((row) => row.dataset.status === tab.dataset.orderTab).length;
                const countNode = tab.querySelector('span');
                if (countNode) countNode.textContent = String(count);
            });

            orderWorkspace.querySelectorAll('[data-order-queue-card]').forEach((card) => {
                if (card.dataset.orderQueueCard === 'waybill') return;
                const countNode = card.querySelector('strong');
                if (!countNode) return;
                countNode.textContent = String(orderRows.filter((row) => row.dataset.status === card.dataset.orderQueueCard).length);
            });
        };

        const applyOrderFilters = () => {
            const search = orderSearch?.value.trim().toLowerCase() ?? '';
            const date = orderDate?.value ?? '';
            const payment = orderPayment?.value ?? '';
            let visible = 0;

            orderRows.forEach((row) => {
                const statusMatches = activeOrderStatus === 'all'
                    || row.dataset.status === activeOrderStatus
                    || (activeOrderStatus === 'history' && row.dataset.history === 'true');
                const matches = statusMatches
                    && (!search || row.dataset.search.includes(search))
                    && (!date || row.dataset.date === date)
                    && (!payment || row.dataset.payment === payment);

                row.hidden = !matches;
                if (matches) visible += 1;
            });

            if (visibleCount) visibleCount.textContent = visible;
            if (noResults) noResults.hidden = visible > 0;
        };

        orderWorkspace.addEventListener('seller:order-tracking-updated', () => {
            refreshOrderStatusCounts();
            applyOrderFilters();
        });

        orderWorkspace.querySelectorAll('[data-order-tab]').forEach((tab) => {
            tab.addEventListener('click', () => {
                activeOrderStatus = tab.dataset.orderTab;
                orderWorkspace.querySelectorAll('[data-order-tab]').forEach((item) => {
                    const active = item === tab;
                    item.classList.toggle('is-active', active);
                    item.setAttribute('aria-selected', String(active));
                });
                applyOrderFilters();
            });
        });

        const requestedOrderStatus = new URLSearchParams(window.location.search).get('status') || activeOrderStatus;
        const requestedOrderTab = requestedOrderStatus
            ? orderWorkspace.querySelector(`[data-order-tab="${CSS.escape(requestedOrderStatus)}"]`)
            : null;

        if (requestedOrderTab) {
            activeOrderStatus = requestedOrderStatus;
            orderWorkspace.querySelectorAll('[data-order-tab]').forEach((tab) => {
                const active = tab === requestedOrderTab;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', String(active));
            });
            applyOrderFilters();
        }

        orderSearch?.addEventListener('input', applyOrderFilters);
        orderDate?.addEventListener('change', applyOrderFilters);
        orderPayment?.addEventListener('change', applyOrderFilters);

        orderWorkspace.querySelector('[data-order-reset]')?.addEventListener('click', () => {
            if (orderSearch) orderSearch.value = '';
            if (orderDate) orderDate.value = '';
            if (orderPayment) orderPayment.value = '';
            activeOrderStatus = 'all';
            orderWorkspace.querySelectorAll('[data-order-tab]').forEach((tab) => {
                const active = tab.dataset.orderTab === 'all';
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', String(active));
            });
            applyOrderFilters();
        });

        applyOrderFilters();
    }

    document.querySelectorAll('[data-order-details]').forEach((button) => {
        button.addEventListener('click', () => {
            const modal = document.querySelector('[data-modal="order-details"]');
            if (!modal) return;
            const orderId = modal.querySelector('[data-order-detail-id]');
            if (orderId) orderId.textContent = button.dataset.orderDetails;
            modal.hidden = false;
            document.body.style.overflow = 'hidden';
        });
    });


    /* Seller inventory filters and persisted adjustment forms. */
    const inventoryWorkspace = document.querySelector('[data-inventory-workspace]');

    if (inventoryWorkspace) {
        let activeInventoryStatus = inventoryWorkspace.dataset.inventoryInitialStatus || 'all';
        const inventoryRows = [...inventoryWorkspace.querySelectorAll('[data-inventory-row]')];
        const inventorySearch = inventoryWorkspace.querySelector('[data-inventory-search]');
        const inventoryStoreSection = inventoryWorkspace.querySelector('[data-inventory-store-section]');
        const inventoryMarketplaceSubcategory = inventoryWorkspace.querySelector('[data-inventory-marketplace-subcategory]');
        const inventoryFilterForm = inventoryWorkspace.querySelector('[data-inventory-filter-form]');
        const inventorySearchState = inventoryWorkspace.querySelector('[data-inventory-search-state]');
        const inventoryStatusState = inventoryWorkspace.querySelector('[data-inventory-status-state]');
        const inventoryVariationsState = inventoryWorkspace.querySelector('[data-inventory-variations-state]');
        const showVariations = inventoryWorkspace.querySelector('[data-inventory-variations]');
        const checkAll = inventoryWorkspace.querySelector('[data-inventory-check-all]');
        const selectedCount = inventoryWorkspace.querySelector('[data-inventory-selected-count]');
        const visibleCount = inventoryWorkspace.querySelector('[data-inventory-visible-count]');
        const noResults = inventoryWorkspace.querySelector('[data-inventory-no-results]');
        const bulkButtons = [...inventoryWorkspace.querySelectorAll('[data-inventory-bulk]')];
        const adjustModal = document.querySelector('[data-modal="inventory-adjust"]');
        const thresholdModal = document.querySelector('[data-modal="inventory-threshold"]');
        const adjustForm = adjustModal?.querySelector('[data-inventory-adjust-form]');
        const thresholdForm = thresholdModal?.querySelector('[data-inventory-threshold-form]');
        const adjustMode = adjustForm?.querySelector('[data-adjust-mode]');
        const adjustQuantity = adjustForm?.querySelector('[data-adjust-quantity]');
        const adjustCurrent = adjustForm?.querySelector('[data-adjust-current]');

        const submitInventoryFilterForm = () => {
            if (!inventoryFilterForm) return;

            if (inventorySearchState) inventorySearchState.value = inventorySearch?.value.trim() ?? '';
            if (inventoryStatusState) inventoryStatusState.value = activeInventoryStatus;
            if (inventoryVariationsState) inventoryVariationsState.value = showVariations?.checked ? '1' : '0';

            inventoryFilterForm.requestSubmit();
        };

        if ((adjustModal && !adjustModal.hidden) || (thresholdModal && !thresholdModal.hidden)) {
            document.body.style.overflow = 'hidden';
        }

        const fillVariantIds = (container, ids) => {
            if (!container) return;
            container.replaceChildren();
            ids.forEach((id) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'variant_ids[]';
                input.value = String(id);
                container.append(input);
            });
        };

        const openInventoryModal = (modal) => {
            if (!modal) return;
            modal.hidden = false;
            document.body.style.overflow = 'hidden';
            modal.querySelector('input:not([type="hidden"]), select')?.focus();
        };

        const selectedVariantIds = () => inventoryRows
            .filter((row) => row.querySelector('[data-inventory-check]')?.checked)
            .map((row) => row.dataset.id);

        const openAdjustment = (ids, label) => {
            if (!adjustModal || !adjustForm || ids.length === 0) return;
            adjustForm.reset();
            fillVariantIds(adjustForm.querySelector('[data-adjust-variant-ids]'), ids);
            const itemLabel = adjustForm.querySelector('[data-adjust-item-label]');
            if (itemLabel) itemLabel.textContent = label;
            const selectedRows = ids
                .map((id) => inventoryRows.find((row) => row.dataset.id === String(id)))
                .filter(Boolean);
            if (adjustCurrent) {
                adjustCurrent.textContent = selectedRows.length === 1
                    ? selectedRows[0].dataset.onHand
                    : 'Varies';
            }
            if (adjustMode) adjustMode.value = 'add';
            if (adjustQuantity) {
                adjustQuantity.value = '1';
                adjustQuantity.min = '1';
                adjustQuantity.max = '2147483647';
            }
            openInventoryModal(adjustModal);
        };

        const openThreshold = (ids, target = 'selected') => {
            if (!thresholdModal || !thresholdForm || ids.length === 0) return;
            thresholdForm.reset();
            fillVariantIds(thresholdForm.querySelector('[data-threshold-variant-ids]'), ids);
            const label = thresholdForm.querySelector('[data-threshold-selected-label]');
            if (label) {
                label.textContent = target === 'visible'
                    ? `No rows selected. This will apply to all ${ids.length} SKU${ids.length === 1 ? '' : 's'} currently shown by your filters.`
                    : `This will apply to ${ids.length} selected SKU${ids.length === 1 ? '' : 's'}.`;
            }
            openInventoryModal(thresholdModal);
        };

        const updateInventorySelection = () => {
            const visibleChecks = inventoryRows
                .filter((row) => !row.hidden)
                .map((row) => row.querySelector('[data-inventory-check]'));
            const checked = inventoryRows.filter((row) => row.querySelector('[data-inventory-check]')?.checked).length;

            if (selectedCount) selectedCount.textContent = checked;
            bulkButtons.forEach((button) => {
                button.disabled = button.dataset.inventoryBulk === 'set-threshold'
                    ? visibleChecks.length === 0
                    : checked === 0;
            });

            if (checkAll) {
                checkAll.checked = visibleChecks.length > 0 && visibleChecks.every((checkbox) => checkbox?.checked);
                checkAll.indeterminate = visibleChecks.some((checkbox) => checkbox?.checked) && !checkAll.checked;
            }
        };

        const clearInventorySelection = () => {
            inventoryRows.forEach((row) => {
                const checkbox = row.querySelector('[data-inventory-check]');
                if (checkbox) checkbox.checked = false;
            });

            if (checkAll) {
                checkAll.checked = false;
                checkAll.indeterminate = false;
            }
        };

        const applyInventoryFilters = () => {
            const search = inventorySearch?.value.trim().toLowerCase() ?? '';
            const storeSection = inventoryStoreSection?.value ?? '';
            const marketplaceSubcategory = inventoryMarketplaceSubcategory?.value ?? '';
            const variationsVisible = showVariations?.checked ?? true;
            let visible = 0;

            inventoryRows.forEach((row) => {
                const matchesStatus = activeInventoryStatus === 'all'
                    ? row.dataset.status !== 'archived'
                    : activeInventoryStatus === 'needs-restocking'
                    ? ['low-stock', 'out-of-stock'].includes(row.dataset.status)
                    : activeInventoryStatus === 'needs-review'
                        ? ['held-for-review', 'pending-review'].includes(row.dataset.status)
                        : row.dataset.status === activeInventoryStatus;
                const matches = matchesStatus
                    && (!search || row.dataset.search.includes(search))
                    && (!storeSection || row.dataset.storeSection === storeSection)
                    && (!marketplaceSubcategory || row.dataset.marketplaceSubcategory === marketplaceSubcategory)
                    && (variationsVisible || row.dataset.variationRow !== 'true');

                row.hidden = !matches;
                if (!matches) {
                    const checkbox = row.querySelector('[data-inventory-check]');
                    if (checkbox) checkbox.checked = false;
                }
                if (matches) visible += 1;
            });

            if (visibleCount) visibleCount.textContent = visible;
            if (noResults) noResults.hidden = visible > 0;
            updateInventorySelection();
        };

        inventoryWorkspace.querySelectorAll('[data-inventory-tab]').forEach((tab) => {
            tab.addEventListener('click', () => {
                activeInventoryStatus = tab.dataset.inventoryTab;
                inventoryWorkspace.querySelectorAll('[data-inventory-tab]').forEach((item) => {
                    const active = item === tab;
                    item.classList.toggle('is-active', active);
                    item.setAttribute('aria-selected', String(active));
                });
                applyInventoryFilters();
            });
        });

        inventorySearch?.addEventListener('input', applyInventoryFilters);
        inventoryStoreSection?.addEventListener('change', submitInventoryFilterForm);
        inventoryMarketplaceSubcategory?.addEventListener('change', submitInventoryFilterForm);
        showVariations?.addEventListener('change', applyInventoryFilters);

        inventoryWorkspace.querySelector('[data-inventory-reset]')?.addEventListener('click', () => {
            const shouldReload = Boolean(inventoryStoreSection?.value || inventoryMarketplaceSubcategory?.value);
            if (inventorySearch) inventorySearch.value = '';
            if (inventoryStoreSection) inventoryStoreSection.value = '';
            if (inventoryMarketplaceSubcategory) inventoryMarketplaceSubcategory.value = '';
            if (showVariations) showVariations.checked = true;
            activeInventoryStatus = 'all';
            clearInventorySelection();

            inventoryWorkspace.querySelectorAll('[data-inventory-tab]').forEach((tab) => {
                const active = tab.dataset.inventoryTab === 'all';
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', String(active));
            });

            if (shouldReload) {
                submitInventoryFilterForm();
                return;
            }

            applyInventoryFilters();
        });

        document.querySelector('[data-inventory-alerts]')?.addEventListener('click', () => {
            const shouldReload = Boolean(inventoryStoreSection?.value || inventoryMarketplaceSubcategory?.value);
            if (inventorySearch) inventorySearch.value = '';
            if (inventoryStoreSection) inventoryStoreSection.value = '';
            if (inventoryMarketplaceSubcategory) inventoryMarketplaceSubcategory.value = '';
            if (showVariations) showVariations.checked = true;
            clearInventorySelection();

            inventoryWorkspace.querySelector('[data-inventory-tab="needs-restocking"]')?.click();
            if (shouldReload) submitInventoryFilterForm();
            inventoryWorkspace.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        checkAll?.addEventListener('change', () => {
            inventoryRows.filter((row) => !row.hidden).forEach((row) => {
                const checkbox = row.querySelector('[data-inventory-check]');
                if (checkbox) checkbox.checked = checkAll.checked;
            });
            updateInventorySelection();
        });

        inventoryRows.forEach((row) => row.querySelector('[data-inventory-check]')?.addEventListener('change', updateInventorySelection));

        bulkButtons.forEach((button) => button.addEventListener('click', () => {
            const ids = selectedVariantIds();
            if (button.dataset.inventoryBulk === 'adjust-stock') {
                openAdjustment(ids, `${ids.length} selected SKU${ids.length === 1 ? '' : 's'}`);
            }
            if (button.dataset.inventoryBulk === 'set-threshold') {
                const targets = ids.length > 0
                    ? ids
                    : inventoryRows.filter((row) => !row.hidden).map((row) => row.dataset.id);
                openThreshold(targets, ids.length > 0 ? 'selected' : 'visible');
            }
        }));

        adjustMode?.addEventListener('change', () => {
            if (adjustQuantity) {
                const exactBalance = adjustMode.value === 'set';
                adjustQuantity.min = exactBalance ? '0' : '1';
                adjustQuantity.max = exactBalance ? '4294967295' : '2147483647';
            }
        });

        document.querySelectorAll('[data-adjust-stock]').forEach((button) => {
            button.addEventListener('click', () => {
                openAdjustment([button.dataset.itemId], button.dataset.itemLabel || 'Selected inventory item');
            });
        });

        applyInventoryFilters();
    }

    /* Returns & refunds: Shopee-style case filtering and ERP resolution preview. */
    const returnsWorkspace = document.querySelector('[data-returns-workspace]');
    if (returnsWorkspace) {
        let activeReturnStatus = 'all';
        const rows = [...returnsWorkspace.querySelectorAll('[data-return-row]')];
        const search = returnsWorkspace.querySelector('[data-return-search]');
        const type = returnsWorkspace.querySelector('[data-return-type]');
        const count = returnsWorkspace.querySelector('[data-return-count]');
        const empty = returnsWorkspace.querySelector('[data-returns-empty]');

        const applyReturnFilters = () => {
            const query = search?.value.trim().toLowerCase() ?? '';
            const requestType = type?.value ?? '';
            let visible = 0;
            rows.forEach((row) => {
                const matches = (activeReturnStatus === 'all' || row.dataset.status === activeReturnStatus)
                    && (!requestType || row.dataset.type === requestType)
                    && (!query || row.dataset.search.includes(query));
                row.hidden = !matches;
                if (matches) visible += 1;
            });
            if (count) count.textContent = visible;
            if (empty) empty.hidden = visible > 0;
        };

        returnsWorkspace.querySelectorAll('[data-return-tab]').forEach((tab) => {
            tab.addEventListener('click', () => {
                activeReturnStatus = tab.dataset.returnTab;
                returnsWorkspace.querySelectorAll('[data-return-tab]').forEach((item) => {
                    const active = item === tab;
                    item.classList.toggle('is-active', active);
                    item.setAttribute('aria-selected', String(active));
                });
                applyReturnFilters();
            });
        });
        search?.addEventListener('input', applyReturnFilters);
        type?.addEventListener('change', applyReturnFilters);
        returnsWorkspace.querySelector('[data-return-reset]')?.addEventListener('click', () => {
            if (search) search.value = '';
            if (type) type.value = '';
            activeReturnStatus = 'all';
            returnsWorkspace.querySelectorAll('[data-return-tab]').forEach((tab) => {
                const active = tab.dataset.returnTab === 'all';
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', String(active));
            });
            applyReturnFilters();
        });
        returnsWorkspace.querySelector('[data-return-export]')?.addEventListener('click', () => {
            const visibleRows = rows.filter((row) => !row.hidden);
            const headings = [...returnsWorkspace.querySelectorAll('.returns-table thead th')]
                .slice(0, -1)
                .map((cell) => cell.textContent.trim());
            const records = visibleRows.map((row) => [...row.querySelectorAll('td')]
                .slice(0, -1)
                .map((cell) => cell.innerText.replace(/\s+/g, ' ').trim()));
            const escapeCsv = (value) => `"${String(value).replaceAll('"', '""')}"`;
            const csv = [headings, ...records].map((record) => record.map(escapeCsv).join(',')).join('\n');
            const url = URL.createObjectURL(new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' }));
            const download = document.createElement('a');
            download.href = url;
            download.download = `returns-refunds-${new Date().toISOString().slice(0, 10)}.csv`;
            download.click();
            URL.revokeObjectURL(url);
            if (toast) {
                toast.textContent = `${visibleRows.length} return and refund case${visibleRows.length === 1 ? '' : 's'} exported.`;
                toast.classList.add('is-visible');
                window.clearTimeout(window.sellerToastTimer);
                window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3200);
            }
        });
        applyReturnFilters();
    }

    document.querySelectorAll('[data-case-preview]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!toast) return;
            toast.textContent = `${button.dataset.casePreview} opened. Frontend preview only.`;
            toast.classList.add('is-visible');
            window.clearTimeout(window.sellerToastTimer);
            window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3200);
        });
    });

    const caseResponse = document.querySelector('[data-case-response]');
    const caseCharacterCount = document.querySelector('[data-case-character-count]');
    caseResponse?.addEventListener('input', () => {
        if (caseCharacterCount) caseCharacterCount.textContent = caseResponse.value.length;
    });

    const caseImageDialog = document.querySelector('[data-case-image-dialog]');
    const caseImage = caseImageDialog?.querySelector('[data-case-image-full]');
    const caseImageTitle = caseImageDialog?.querySelector('[data-case-image-title]');
    let lastCaseImageTrigger = null;
    document.querySelectorAll('[data-case-image-preview]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!caseImageDialog || !caseImage || !button.dataset.previewUrl || typeof caseImageDialog.showModal !== 'function') return;
            lastCaseImageTrigger = button;
            const name = button.dataset.previewName || 'Evidence image';
            if (caseImageTitle) caseImageTitle.textContent = name;
            caseImage.src = button.dataset.previewUrl;
            caseImage.alt = name;
            caseImageDialog.showModal();
        });
    });
    caseImageDialog?.querySelector('[data-case-image-close]')?.addEventListener('click', () => caseImageDialog.close());
    caseImageDialog?.addEventListener('click', (event) => {
        if (event.target !== caseImageDialog) return;
        const bounds = caseImageDialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) {
            caseImageDialog.close();
        }
    });
    caseImageDialog?.addEventListener('close', () => {
        caseImage?.removeAttribute('src');
        caseImage?.removeAttribute('alt');
        lastCaseImageTrigger?.focus();
    });

    document.querySelector('[data-case-evidence-input]')?.addEventListener('change', (event) => {
        const files = [...event.target.files];
        const label = document.querySelector('[data-case-evidence-name]');
        if (!label) return;
        label.textContent = files.length ? `${files.length} file${files.length > 1 ? 's' : ''} selected` : 'Images, video, or PDF · Maximum 10 MB per file';
    });

    const caseConfirmModal = document.querySelector('[data-modal="case-confirm"]');
    let pendingCaseAction = null;
    document.querySelectorAll('[data-case-action]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!caseConfirmModal) return;
            const responseText = caseResponse?.value.trim() ?? '';
            if (!responseText) {
                caseResponse?.focus();
                if (toast) {
                    toast.textContent = 'Add a clear seller response before submitting your decision.';
                    toast.classList.add('is-visible');
                    window.clearTimeout(window.sellerToastTimer);
                    window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3200);
                }
                return;
            }

            pendingCaseAction = button.dataset.caseAction;
            const accept = pendingCaseAction === 'accept';
            const title = caseConfirmModal.querySelector('[data-case-confirm-title]');
            const message = caseConfirmModal.querySelector('[data-case-confirm-message]');
            const confirm = caseConfirmModal.querySelector('[data-case-confirm]');
            if (title) title.textContent = accept ? 'Accept buyer request?' : 'Submit case dispute?';
            if (message) message.textContent = accept
                ? 'This sends the request for return or refund processing. Review the amount and response before confirming.'
                : 'This sends your response and evidence to Bearly for platform review. Clearly explain why you dispute the request.';
            if (confirm) {
                confirm.textContent = accept ? 'Accept request' : 'Submit dispute';
                confirm.classList.toggle('is-danger', !accept);
            }
            caseConfirmModal.hidden = false;
            document.body.style.overflow = 'hidden';
        });
    });

    caseConfirmModal?.querySelector('[data-case-confirm]')?.addEventListener('click', () => {
        caseConfirmModal.hidden = true;
        document.body.style.overflow = '';
        if (!toast) return;
        toast.textContent = pendingCaseAction === 'accept'
            ? 'Request accepted and queued for platform processing. Frontend preview only.'
            : 'Dispute submitted for platform review. Frontend preview only.';
        toast.classList.add('is-visible');
        window.clearTimeout(window.sellerToastTimer);
        window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3600);
    });

    const sellerWorkspace = document.querySelector('[data-seller-workspace]');
    const workspaceSearch = sellerWorkspace?.querySelector('[data-workspace-search]');
    workspaceSearch?.addEventListener('input', () => {
        const query = workspaceSearch.value.trim().toLowerCase();
        let visible = 0;
        sellerWorkspace.querySelectorAll('[data-workspace-row]').forEach((row) => {
            const matches = !query || row.dataset.search.includes(query);
            row.hidden = !matches;
            if (matches) visible += 1;
        });
        const count = sellerWorkspace.querySelector('[data-workspace-count]');
        const empty = sellerWorkspace.querySelector('[data-workspace-empty]');
        if (count) count.textContent = visible;
        if (empty) empty.hidden = visible > 0;
    });

    document.querySelectorAll('[data-workspace-demo]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!toast) return;
            toast.textContent = `${button.dataset.workspaceDemo} Frontend preview only.`;
            toast.classList.add('is-visible');
            window.clearTimeout(window.sellerToastTimer);
            window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3000);
        });
    });

    document.querySelector('[data-save-notifications]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const preferences = {};

        document.querySelectorAll('[data-notification-preference]').forEach((row) => {
            const key = row.dataset.notificationPreference;
            if (!key) return;

            preferences[key] = {
                in_app: row.querySelector('[data-preference-channel="in_app"]')?.checked === true,
                email: row.querySelector('[data-preference-channel="email"]')?.checked === true,
            };
        });

        button.disabled = true;

        try {
            const response = await fetch('/notifications/preferences', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ preferences }),
            });

            if (!response.ok) throw new Error(`Save failed with status ${response.status}`);

            if (toast) {
                toast.textContent = 'Notification preferences saved.';
                toast.classList.add('is-visible');
                window.clearTimeout(window.sellerToastTimer);
                window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3000);
            }
        } catch (error) {
            if (toast) {
                toast.textContent = 'Could not save notification preferences. Please try again.';
                toast.classList.add('is-visible');
                window.clearTimeout(window.sellerToastTimer);
                window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3400);
            }
        } finally {
            button.disabled = false;
        }
    });

    /* Store appearance — persisted image, description, and buyer preview. */
    const appearance = document.querySelector('[data-storefront-appearance]');
    if (appearance) {
        const appearanceForm = appearance.closest('form');
        const saveState = appearance.querySelector('[data-storefront-save-state]');
        let hasUnsavedChanges = false;

        const markAppearanceDirty = () => {
            hasUnsavedChanges = true;
            if (saveState) saveState.textContent = 'Unsaved changes';
        };

        appearanceForm?.addEventListener('input', markAppearanceDirty);
        appearanceForm?.addEventListener('change', markAppearanceDirty);
        appearanceForm?.addEventListener('submit', () => {
            hasUnsavedChanges = false;
            if (saveState) saveState.textContent = 'Saving changes…';
        });
        window.addEventListener('beforeunload', (event) => {
            if (!hasUnsavedChanges) return;
            event.preventDefault();
            event.returnValue = '';
        });

        const description = appearance.querySelector('[data-storefront-description]');
        const count = appearance.querySelector('[data-storefront-description-count]');
        const previewDescription = appearance.querySelector('[data-storefront-preview-description]');
        const announcement = appearance.querySelector('[data-storefront-announcement]');
        const previewAnnouncement = appearance.querySelector('[data-storefront-preview-announcement]');
        const hours = appearance.querySelector('[data-storefront-hours]');
        const previewHours = appearance.querySelector('[data-storefront-preview-hours]');
        const promoTitle = appearance.querySelector('[name="promo_banner_titles[]"]');
        const previewPromoTitle = appearance.querySelector('[data-storefront-preview-promo-title]');

        description?.addEventListener('input', () => {
            if (count) count.textContent = description.value.length;
            if (previewDescription) previewDescription.textContent = description.value.trim() || 'Your store description will appear here.';
        });

        const syncStoreDetail = (input, preview) => {
            if (!input || !preview) return;
            const value = input.value.trim();
            preview.hidden = !value;
            preview.querySelector('span').textContent = value;
        };

        announcement?.addEventListener('input', () => syncStoreDetail(announcement, previewAnnouncement));
        hours?.addEventListener('input', () => syncStoreDetail(hours, previewHours));
        appearance.querySelectorAll('[data-storefront-policy]').forEach((input) => {
            input.addEventListener('input', () => {
                const preview = appearance.querySelector(`[data-preview-policy="${input.dataset.storefrontPolicy}"]`);
                if (preview) preview.textContent = input.value.trim() || `No ${input.dataset.storefrontPolicy} policy added yet.`;
            });
        });

        const previewTabs = appearance.querySelectorAll('[data-preview-tab]');
        const previewPanels = appearance.querySelectorAll('[data-preview-panel]');
        previewTabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                const target = tab.dataset.previewTab;
                previewTabs.forEach((item) => {
                    const active = item === tab;
                    item.classList.toggle('is-active', active);
                    item.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                previewPanels.forEach((panel) => { panel.hidden = panel.dataset.previewPanel !== target; });
            });
        });
        promoTitle?.addEventListener('input', () => {
            if (!previewPromoTitle) return;
            previewPromoTitle.textContent = promoTitle.value.trim();
            previewPromoTitle.hidden = !promoTitle.value.trim();
        });

        const updatePromoUploadPreview = (input, src) => {
            const drop = input.closest('[data-promo-banner-item]')?.querySelector('[data-promo-drop]');
            if (!drop || !src) return;

            let image = drop.querySelector('[data-promo-image]');
            if (!image) {
                image = document.createElement('img');
                image.dataset.promoImage = '';
                drop.querySelector('[data-lucide]')?.remove();
                const label = drop.querySelector('strong');
                if (label) label.textContent = 'Replace banner';
                drop.prepend(image);
            }

            image.src = src;
            image.alt = 'Selected promotional banner';
        };

        appearance.querySelectorAll('[data-storefront-image]').forEach((input) => {
            input.addEventListener('change', () => {
                const file = input.files?.[0];
                if (!file) return;

                if (input.dataset.storefrontImage === 'profile' || input.dataset.storefrontImage === 'cover') {
                    const removalName = input.dataset.storefrontImage === 'profile' ? 'remove_profile_photo' : 'remove_cover_photo';
                    const removal = appearance.querySelector(`[name="${removalName}"]`);
                    if (removal) removal.checked = false;
                }

                const maximum = ['cover', 'promo'].includes(input.dataset.storefrontImage) ? 20 : 10;
                if (file.size > maximum * 1024 * 1024) {
                    input.value = '';
                    if (toast) {
                        const imageLabel = input.dataset.storefrontImage === 'promo' ? 'Promotional banner' : input.dataset.storefrontImage === 'cover' ? 'Cover' : 'Profile';
                        toast.textContent = `${imageLabel} image must be ${maximum}MB or smaller.`;
                        toast.classList.add('is-visible');
                    }
                    return;
                }

                const reader = new FileReader();
                reader.addEventListener('load', () => {
                    const createPreview = () => {
                        const image = document.createElement('img');
                        image.alt = `${input.dataset.storefrontImage} preview`;
                        image.src = reader.result;
                        return image;
                    };
                    if (input.dataset.storefrontImage === 'profile') {
                        appearance.querySelector('[data-storefront-profile-placeholder]')?.replaceChildren(createPreview());
                        appearance.querySelector('[data-storefront-preview-profile]')?.replaceChildren(createPreview());
                    } else if (input.dataset.storefrontImage === 'cover') {
                        appearance.querySelector('[data-storefront-cover-placeholder]')?.replaceChildren(createPreview());
                        appearance.querySelector('[data-storefront-preview-cover]')?.replaceChildren(createPreview());
                    } else if (input.dataset.storefrontImage === 'promo') {
                        updatePromoUploadPreview(input, reader.result);
                        const preview = appearance.querySelector('[data-storefront-preview-promo]');
                        const slide = preview?.querySelector('[data-storefront-promo-slide]');
                        if (slide) {
                            const title = slide.querySelector('[data-storefront-preview-promo-title]');
                            slide.replaceChildren(createPreview(), title || document.createElement('span'));
                            if (title) {
                                title.className = 'buyer-store-promo-overlay';
                                title.hidden = !promoTitle?.value.trim();
                                title.textContent = promoTitle?.value.trim() || '';
                                title.dataset.storefrontPreviewPromoTitle = '';
                            }
                        }
                        if (preview) preview.hidden = false;
                    }
                });
                reader.readAsDataURL(file);
            });
        });

        const promoPreview = appearance.querySelector('[data-storefront-preview-promo]');
        const promoList = appearance.querySelector('[data-promo-banner-item]')?.parentElement;

        const fileDataUrl = (file) => new Promise((resolve) => {
            if (!file) return resolve(null);
            const reader = new FileReader();
            reader.addEventListener('load', () => resolve(reader.result));
            reader.readAsDataURL(file);
        });

        const renderPromoCarousel = async () => {
            if (!promoPreview || !promoList) return;

            const items = Array.from(promoList.querySelectorAll('[data-promo-banner-item]'));
            const slides = await Promise.all(items.map(async (item, index) => {
                const input = item.querySelector('[data-storefront-image="promo"]');
                const image = item.querySelector('[data-promo-image]');
                const src = await fileDataUrl(input?.files?.[0]) || image?.src;
                if (!src) return null;

                const slide = document.createElement('div');
                slide.className = `buyer-store-promo-slide${index === 0 ? ' is-active' : ''}`;
                slide.hidden = index !== 0;
                const previewImage = document.createElement('img');
                previewImage.src = src;
                previewImage.alt = item.querySelector('input[name="promo_banner_titles[]"]')?.value.trim() || 'Promotional banner preview';
                const title = document.createElement('span');
                title.className = 'buyer-store-promo-overlay';
                title.textContent = item.querySelector('input[name="promo_banner_titles[]"]')?.value.trim() || '';
                title.hidden = !title.textContent;
                slide.append(previewImage, title);
                return slide;
            }));

            const validSlides = slides.filter(Boolean);
            const track = promoPreview.querySelector('.buyer-store-promo-track');
            const dots = promoPreview.querySelector('[data-promo-preview-dots]');
            const previous = promoPreview.querySelector('[data-promo-preview-prev]');
            const next = promoPreview.querySelector('[data-promo-preview-next]');
            if (!track || !dots || !previous || !next) return;

            track.replaceChildren(...validSlides);
            dots.replaceChildren(...validSlides.map((_, index) => {
                const dot = document.createElement('button');
                dot.type = 'button';
                dot.className = index === 0 ? 'is-active' : '';
                dot.ariaLabel = `Show promotional banner ${index + 1}`;
                dot.dataset.slideIndex = String(index);
                return dot;
            }));
            promoPreview.hidden = validSlides.length === 0;
            previous.disabled = validSlides.length < 2;
            next.disabled = validSlides.length < 2;

            let activeIndex = 0;
            const showSlide = (index) => {
                activeIndex = (index + validSlides.length) % validSlides.length;
                validSlides.forEach((slide, slideIndex) => {
                    slide.classList.toggle('is-active', slideIndex === activeIndex);
                    slide.hidden = slideIndex !== activeIndex;
                });
                dots.querySelectorAll('button').forEach((dot, dotIndex) => dot.classList.toggle('is-active', dotIndex === activeIndex));
            };

            previous.onclick = () => showSlide(activeIndex - 1);
            next.onclick = () => showSlide(activeIndex + 1);
            dots.querySelectorAll('button').forEach((dot) => dot.onclick = () => showSlide(Number(dot.dataset.slideIndex)));
        };

        appearance.addEventListener('change', (event) => {
            if (!event.target.matches('[data-storefront-image="promo"]')) return;

            const file = event.target.files?.[0];
            if (file) {
                const reader = new FileReader();
                reader.addEventListener('load', () => updatePromoUploadPreview(event.target, reader.result));
                reader.readAsDataURL(file);
            }

            renderPromoCarousel();
        });
        appearance.addEventListener('input', (event) => {
            if (event.target.matches('input[name="promo_banner_titles[]"]')) renderPromoCarousel();
        });
        appearance.addEventListener('click', (event) => {
            const addButton = event.target.closest('[data-add-promo-banner]');
            const removeButton = event.target.closest('[data-remove-promo-banner]');

            if (addButton && promoList) {
                const template = promoList.querySelector('[data-promo-banner-item]');
                if (!template) return;
                const item = template.cloneNode(true);
                item.querySelectorAll('input[type="hidden"]').forEach((field) => field.remove());
                item.querySelectorAll('input[type="file"]').forEach((field) => { field.value = ''; });
                item.querySelectorAll('input[type="text"], input[type="url"]').forEach((field) => { field.value = ''; });
                const drop = item.querySelector('[data-promo-drop]');
                if (drop) drop.innerHTML = '<i data-lucide="image-plus"></i><strong>Choose promotional banner</strong><small>JPG, PNG, or WebP · Up to 20MB</small>';
                promoList.append(item);
                window.lucide?.createIcons();
            }

            if (removeButton) {
                const items = promoList?.querySelectorAll('[data-promo-banner-item]') || [];
                const item = removeButton.closest('[data-promo-banner-item]');
                if (item && items.length > 1) item.remove();
                else if (item) {
                    item.querySelectorAll('input[type="hidden"]').forEach((field) => field.remove());
                    item.querySelectorAll('input[type="file"], input[type="text"], input[type="url"]').forEach((field) => { field.value = ''; });
                    const drop = item.querySelector('[data-promo-drop]');
                    if (drop) drop.innerHTML = '<i data-lucide="image-plus"></i><strong>Choose promotional banner</strong><small>JPG, PNG, or WebP · Up to 20MB</small>';
                    window.lucide?.createIcons();
                }
                renderPromoCarousel();
            }
        });
        renderPromoCarousel();
    }

    document.querySelector('[data-storefront-preview-toggle]')?.addEventListener('click', () => {
        document.querySelector('.storefront-preview-card')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    /* Publication settings — persist buyer visibility through the Seller store contract. */
    document.querySelector('[data-publication-toggle]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const nextPublished = button.dataset.published !== 'true';
        const endpoint = button.dataset.publicationEndpoint || '/seller/store/publication';
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const originalLabel = button.querySelector('span')?.textContent || '';

        button.disabled = true;

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ published: nextPublished }),
            });
            const result = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(result.message || Object.values(result.errors || {}).flat()[0] || 'Unable to update store visibility.');
            }

            const published = Boolean(result.data?.published);
            button.dataset.published = String(published);
            button.querySelector('span').textContent = published ? 'Unpublish Store' : 'Publish Store';

            const title = document.querySelector('[data-publication-title]');
            const copy = document.querySelector('[data-publication-copy]');
            const badge = document.querySelector('[data-publication-badge]');
            const visibility = document.querySelector('[data-publication-visibility]');
            if (title) title.textContent = published ? 'Store Published' : 'Store Not Published';
            if (copy) copy.textContent = published ? 'Your storefront is currently visible to buyers.' : 'Your storefront is hidden from buyers.';
            if (badge) {
                badge.classList.toggle('is-live', published);
                badge.lastChild.textContent = published ? 'Published' : 'Draft';
            }
            if (visibility) visibility.textContent = published ? 'Visible to buyers' : 'Hidden from buyers';
            if (toast) {
                toast.textContent = result.message || `${published ? 'Publish' : 'Unpublish'} action saved.`;
                toast.classList.add('is-visible');
                window.clearTimeout(window.sellerToastTimer);
                window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3400);
            }
        } catch (error) {
            if (toast) {
                toast.textContent = error.message || 'Unable to update store visibility.';
                toast.classList.add('is-visible');
            }
            button.querySelector('span').textContent = originalLabel;
        } finally {
            button.disabled = false;
        }
    });


    /* Fulfillment workspaces. */
    const setupFulfillmentFilter = (workspaceSelector, rowSelector, tabSelector, searchSelector, countSelector, emptySelector, resetSelector = null, statusSelector = null) => {
        const workspace = document.querySelector(workspaceSelector);
        if (!workspace) return;
        const rows = [...workspace.querySelectorAll(rowSelector)];
        const search = workspace.querySelector(searchSelector);
        const count = workspace.querySelector(countSelector);
        const empty = workspace.querySelector(emptySelector);
        const status = statusSelector ? workspace.querySelector(statusSelector) : null;
        const pickupDate = workspace.querySelector('[data-pickup-date]');
        let active = 'all';
        const apply = () => {
            const query = search?.value.trim().toLowerCase() ?? '';
            const selected = status?.value ?? '';
            let visible = 0;
            rows.forEach((row) => {
                const match = (active === 'all' || row.dataset.status === active) && (!selected || row.dataset.status === selected) && (!query || row.dataset.search.includes(query)) && (!pickupDate?.value || row.dataset.date === pickupDate.value);
                row.hidden = !match;
                if (match) visible += 1;
            });
            if (count) count.textContent = visible;
            if (empty) empty.hidden = visible > 0;
        };
        workspace.querySelectorAll(tabSelector).forEach((tab) => tab.addEventListener('click', () => {
            active = tab.dataset.waybillTab || tab.dataset.pickupTab || tab.dataset.trackingTab || 'all';
            workspace.querySelectorAll(tabSelector).forEach((item) => {
                item.classList.toggle('is-active', item === tab);
                if (item.hasAttribute('aria-pressed')) item.setAttribute('aria-pressed', String(item === tab));
            });
            apply();
        }));
        search?.addEventListener('input', apply);
        status?.addEventListener('change', apply);
        pickupDate?.addEventListener('change', apply);
        if (resetSelector) workspace.querySelector(resetSelector)?.addEventListener('click', () => {
            if (search) search.value = '';
            if (status) status.value = '';
            active = 'all';
            workspace.querySelectorAll(tabSelector).forEach((tab, index) => {
                tab.classList.toggle('is-active', index === 0);
                if (tab.hasAttribute('aria-pressed')) tab.setAttribute('aria-pressed', String(index === 0));
            });
            apply();
        });
        apply();
    };
    setupFulfillmentFilter('[data-waybill-workspace]', '[data-waybill-row]', '[data-waybill-tab]', '[data-waybill-search]', '[data-waybill-count]', '[data-waybill-empty]');
    setupFulfillmentFilter('[data-pickup-workspace]', '[data-pickup-row]', '[data-pickup-tab]', '[data-pickup-search]', '[data-pickup-count]', '[data-pickup-empty]');
    setupFulfillmentFilter('[data-tracking-workspace]', '[data-tracking-row]', '[data-tracking-tab]', '[data-tracking-search]', '[data-tracking-count]', '[data-tracking-empty]', '[data-tracking-reset]', '[data-tracking-status]');

    const waybillChecks = [...document.querySelectorAll('[data-waybill-check]:not(:disabled)')];
    const waybillBulk = document.querySelector('[data-waybill-bulk]');
    const waybillPdfForm = document.querySelector('#waybill-pdf-form');
    const updateWaybillBulk = () => {
        const selected = waybillChecks.filter((check) => check.checked).length;
        if (waybillBulk) { waybillBulk.disabled = selected === 0; waybillBulk.innerHTML = `<i data-lucide="file-down" aria-hidden="true"></i>Download Selected PDFs${selected ? ` (${selected})` : ''}`; window.lucide?.createIcons(); }
    };
    waybillChecks.forEach((check) => check.addEventListener('change', updateWaybillBulk));
    document.querySelector('[data-waybill-check-all]')?.addEventListener('change', (event) => { waybillChecks.forEach((check) => { check.checked = event.currentTarget.checked; }); updateWaybillBulk(); });

    const downloadWaybillPdfs = async (ids) => {
        if (!waybillPdfForm || ids.length === 0) return;

        waybillPdfForm.querySelectorAll('input[name="waybill_ids[]"]').forEach((input) => input.remove());
        ids.forEach((id) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'waybill_ids[]';
            input.value = id;
            waybillPdfForm.append(input);
        });

        const submitButton = document.activeElement instanceof HTMLButtonElement ? document.activeElement : null;
        if (submitButton) submitButton.disabled = true;

        try {
            const response = await fetch(waybillPdfForm.action, {
                method: 'POST',
                body: new FormData(waybillPdfForm),
                credentials: 'same-origin',
                headers: { Accept: 'application/pdf' },
            });

            if (!response.ok || !response.headers.get('content-type')?.includes('application/pdf')) {
                throw new Error(response.status === 404
                    ? 'One or more waybills are no longer available for this Store.'
                    : response.status === 409
                        ? 'A selected waybill is voided and cannot be downloaded.'
                        : 'The PDF could not be generated. Please try again.');
            }

            const blob = await response.blob();
            const disposition = response.headers.get('content-disposition') ?? '';
            const filename = disposition.match(/filename="?([^";]+)"?/i)?.[1] ?? 'Bearly-Waybills.pdf';
            const objectUrl = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = objectUrl;
            link.download = filename;
            link.hidden = true;
            document.body.append(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);

            ids.forEach((id) => {
                const row = [...document.querySelectorAll('[data-waybill-row]')]
                    .find((candidate) => candidate.querySelector('[data-waybill-check]')?.value === String(id));
                const printButton = row?.querySelector('[data-waybill-print-confirm]');
                if (printButton) {
                    printButton.disabled = false;
                    printButton.removeAttribute('title');
                }
            });

            if (toast) {
                toast.textContent = `${ids.length} waybill ${ids.length === 1 ? 'PDF is' : 'PDFs are'} ready to print.`;
                toast.classList.add('is-visible');
            }
        } catch (error) {
            if (toast) {
                toast.textContent = error instanceof Error ? error.message : 'The PDF could not be generated. Please try again.';
                toast.classList.add('is-visible');
            }
        } finally {
            if (submitButton) submitButton.disabled = false;
            waybillPdfForm.querySelectorAll('input[name="waybill_ids[]"]').forEach((input) => input.remove());
            updateWaybillBulk();
        }
    };

    waybillBulk?.addEventListener('click', () => downloadWaybillPdfs(waybillChecks.filter((check) => check.checked).map((check) => check.value)));
    document.querySelectorAll('[data-waybill-download]').forEach((button) => button.addEventListener('click', () => downloadWaybillPdfs([button.dataset.waybillDownload])));
    document.querySelector('[data-waybill-history]')?.addEventListener('click', () => { const modal = document.querySelector('[data-modal="waybill-history"]'); if (modal) { modal.hidden = false; document.body.style.overflow = 'hidden'; } });

    document.querySelector('[data-pickup-form]')?.addEventListener('submit', (event) => {
        const selectedOrders = event.currentTarget.querySelectorAll('[data-pickup-order]:checked');
        if (selectedOrders.length === 0) {
            event.preventDefault();
            if (toast) {
                toast.textContent = 'Select at least one ready order.';
                toast.classList.add('is-visible');
            }
            return;
        }

        const submit = event.currentTarget.querySelector('[type="submit"]');
        if (submit) {
            submit.disabled = true;
            submit.textContent = 'Submitting…';
        }
    });
    document.querySelectorAll('[data-pickup-view]').forEach((button) => button.addEventListener('click', () => {
        const item = JSON.parse(button.dataset.pickup); const modal = document.querySelector('[data-modal="pickup-details"]'); if (!modal) return;
        const set = (selector, value) => { const node = modal.querySelector(selector); if (node) node.textContent = value; };
        set('[data-pickup-id]', item.id); set('[data-pickup-status]', item.status); set('[data-pickup-orders]', `${item.orders} (${item.order_numbers || 'Order details unavailable'}) · ${item.packages} ${item.packages === 1 ? 'parcel' : 'parcels'}`); set('[data-pickup-provider]', item.provider); set('[data-pickup-schedule]', item.schedule); set('[data-pickup-rider]', item.rider); set('[data-pickup-rider-location]', item.rider_location || 'Awaiting assignment'); set('[data-pickup-address]', item.address); set('[data-pickup-instructions]', item.instructions);
        modal.hidden = false; document.body.style.overflow = 'hidden';
    }));

    document.querySelector('[data-tracking-attention]')?.addEventListener('click', () => {
        const search = document.querySelector('[data-tracking-search]');
        if (search) { search.value = ''; search.dispatchEvent(new Event('input')); }
        const tab = document.querySelector('[data-tracking-tab="failed"]');
        tab?.click();
        tab?.focus();
    });

    let activeTrackingShipment = null;

    document.querySelectorAll('[data-tracking-view]').forEach((button) => button.addEventListener('click', () => {
        const item = JSON.parse(button.dataset.shipment); const modal = document.querySelector('[data-modal="tracking-details"]'); if (!modal) return;
        activeTrackingShipment = item;
        const set = (selector, value) => { const node = modal.querySelector(selector); if (node) node.textContent = value; };
        set('[data-tracking-number]', item.tracking); set('[data-tracking-order]', item.order); set('[data-tracking-customer]', item.customer); set('[data-tracking-destination]', item.destination); set('[data-tracking-eta]', item.eta);
        const timeline = modal.querySelector('[data-tracking-timeline]');
        if (timeline) {
            timeline.replaceChildren();
            const events = Array.isArray(item.events) ? item.events : [];
            if (events.length === 0) {
                const empty = document.createElement('li');
                const marker = document.createElement('span');
                const copy = document.createElement('div');
                const heading = document.createElement('strong');
                heading.textContent = 'No tracking scans recorded yet';
                const note = document.createElement('small');
                note.textContent = 'Scans appear here once Bearly Logistics records them.';
                copy.append(heading, note); empty.append(marker, copy); timeline.append(empty);
            } else {
                events.forEach((event, index) => {
                    const row = document.createElement('li');
                    row.className = index === 0 ? 'is-current' : 'is-done';
                    const marker = document.createElement('span');
                    marker.innerHTML = `<i data-lucide="${index === 0 ? 'package' : 'check'}" aria-hidden="true"></i>`;
                    const copy = document.createElement('div');
                    const heading = document.createElement('strong');
                    heading.textContent = event.label || 'Tracking update';
                    const note = document.createElement('small');
                    note.textContent = [event.time, event.note].filter(Boolean).join(' · ');
                    copy.append(heading, note); row.append(marker, copy); timeline.append(row);
                });
            }
            window.lucide?.createIcons();
        }
        const proof = modal.querySelector('[data-delivery-proof]'); if (proof) proof.hidden = item.status_key !== 'delivered';
        modal.hidden = false; document.body.style.overflow = 'hidden';
    }));
    document.querySelector('[data-report-shipment]')?.addEventListener('click', () => {
        const details = document.querySelector('[data-modal="tracking-details"]');
        const report = document.querySelector('[data-modal="tracking-report"]');
        const form = report?.querySelector('[data-shipment-report-form]');
        if (!report || !form) return;

        const reference = form.querySelector('[data-shipment-report-reference]');
        const subject = form.querySelector('[data-shipment-report-subject]');
        const context = form.querySelector('[data-shipment-report-context]');
        const trackingNumber = String(activeTrackingShipment?.tracking || '');

        if (reference) reference.value = trackingNumber;
        if (subject) subject.value = trackingNumber
            ? `Shipment issue: ${trackingNumber}`
            : 'Shipment tracking issue';
        if (context) {
            context.textContent = trackingNumber
                ? `Support ticket reference: ${trackingNumber}`
                : 'The shipment tracking number will be attached to your support ticket.';
        }

        if (details) details.hidden = true;
        report.hidden = false;
        form.querySelector('[data-shipment-report-issue]')?.focus();
    });

    document.querySelector('[data-shipment-report-form]')?.addEventListener('submit', async (event) => {
        event.preventDefault();

        const form = event.currentTarget;
        const submitButton = form.querySelector('[data-shipment-report-submit]');
        const errorNode = form.querySelector('[data-shipment-report-error]');
        const issue = form.querySelector('[data-shipment-report-issue]')?.value || '';
        const description = form.querySelector('[data-shipment-report-description]');

        if (errorNode) {
            errorNode.hidden = true;
            errorNode.textContent = '';
        }

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Submitting…';
        }

        try {
            const formData = new FormData(form);
            formData.set('message', `Issue type: ${issue}\n\n${description?.value.trim() || ''}`);

            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
            });
            const result = await response.json().catch(() => ({}));

            if (!response.ok) {
                const validationMessage = Object.values(result.errors || {}).flat()[0];
                throw new Error(validationMessage || result.message || 'The support ticket could not be submitted.');
            }

            form.reset();
            form.closest('[data-modal]').hidden = true;
            document.body.style.overflow = '';

            if (toast) {
                toast.textContent = result.message || 'Your shipment issue was saved as a support ticket.';
                toast.classList.add('is-visible');
                window.clearTimeout(window.sellerToastTimer);
                window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 4200);
            }
        } catch (error) {
            if (errorNode) {
                errorNode.textContent = error instanceof Error
                    ? error.message
                    : 'The support ticket could not be submitted.';
                errorNode.hidden = false;
            }
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = 'Submit Report';
            }
        }
    });

    document.querySelector('[data-tracking-export]')?.addEventListener('click', () => {
        const workspace = document.querySelector('[data-tracking-workspace]');
        const rows = [...(workspace?.querySelectorAll('[data-tracking-row]') || [])]
            .filter((row) => !row.hidden);

        if (!rows.length) {
            if (toast) {
                toast.textContent = 'There are no shipments matching the current filters to export.';
                toast.classList.add('is-visible');
                window.clearTimeout(window.sellerToastTimer);
                window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3200);
            }
            return;
        }

        const headings = [...(workspace?.querySelectorAll('.tracking-table thead th') || [])]
            .slice(0, -1)
            .map((cell) => cell.textContent.trim());
        const records = rows.map((row) => [...row.querySelectorAll('td')]
            .slice(0, -1)
            .map((cell) => cell.innerText.replace(/\s+/g, ' ').trim()));
        const escapeCsv = (value) => `"${String(value).replaceAll('"', '""')}"`;
        const csv = [headings, ...records]
            .map((record) => record.map(escapeCsv).join(','))
            .join('\n');
        const url = URL.createObjectURL(new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' }));
        const download = document.createElement('a');
        download.href = url;
        download.download = `shipments-${new Date().toISOString().slice(0, 10)}.csv`;
        download.hidden = true;
        document.body.append(download);
        download.click();
        download.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);

        if (toast) {
            toast.textContent = `${rows.length} shipment${rows.length === 1 ? '' : 's'} exported from the current view.`;
            toast.classList.add('is-visible');
            window.clearTimeout(window.sellerToastTimer);
            window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3200);
        }
    });
    document.querySelectorAll('[data-fulfillment-demo]').forEach((button) => button.addEventListener('click', () => { if (toast) { toast.textContent = `${button.dataset.fulfillmentDemo} Frontend preview only.`; toast.classList.add('is-visible'); } }));

    /* Product pricing and promotion workspace. */
    const pricingWorkspace = document.querySelector('[data-pricing-workspace]');
    const formatPricingMoney = (minor) => `₱${(Number(minor) / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

    if (pricingWorkspace) {
        const discountRows = [...pricingWorkspace.querySelectorAll('[data-product-discount-row]')];
        const discountBody = discountRows[0]?.closest('tbody');
        const discountSearch = pricingWorkspace.querySelector('[data-discount-search]');
        const discountCategory = pricingWorkspace.querySelector('[data-discount-category]');
        const discountStatus = pricingWorkspace.querySelector('[data-discount-status]');
        const discountRange = pricingWorkspace.querySelector('[data-discount-range]');
        const discountEmpty = pricingWorkspace.querySelector('[data-discount-empty]');
        const discountPagination = pricingWorkspace.querySelector('[data-discount-pagination]');
        const discountSortButtons = [...pricingWorkspace.querySelectorAll('[data-discount-sort]')];
        const initialOrder = new Map(discountRows.map((row, index) => [row, index]));
        const perPage = 10;
        let currentPage = 1;
        let sortKey = '';
        let sortDirection = 'asc';

        const filteredDiscountRows = () => {
            const query = discountSearch?.value.trim().toLowerCase() ?? '';
            const category = discountCategory?.value ?? '';
            const status = discountStatus?.value ?? '';
            const rows = discountRows.filter((row) => {
                const matchesSearch = !query || row.dataset.search.includes(query);
                const matchesCategory = !category || row.dataset.category === category;
                const matchesStatus = !status || row.dataset.status === status;

                return matchesSearch && matchesCategory && matchesStatus;
            });

            return rows.sort((first, second) => {
                if (!sortKey) return initialOrder.get(first) - initialOrder.get(second);
                const direction = sortDirection === 'asc' ? 1 : -1;
                if (sortKey === 'price') {
                    return (Number(first.dataset.price) - Number(second.dataset.price)) * direction;
                }

                return first.dataset.name.localeCompare(second.dataset.name) * direction;
            });
        };

        const renderPagination = (totalRows) => {
            if (!discountPagination) return;
            const totalPages = Math.max(1, Math.ceil(totalRows / perPage));
            discountPagination.replaceChildren();

            const addButton = (label, page, options = {}) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'pricing-page-button';
                button.textContent = label;
                button.dataset.discountPage = String(page);
                if (options.label) button.setAttribute('aria-label', options.label);
                if (options.current) {
                    button.classList.add('is-current');
                    button.setAttribute('aria-current', 'page');
                }
                if (options.disabled) button.disabled = true;
                discountPagination.appendChild(button);
            };

            addButton('‹', currentPage - 1, { label: 'Previous page', disabled: currentPage <= 1 });

            const windowSize = 5;
            let windowStart = Math.max(1, currentPage - 2);
            let windowEnd = Math.min(totalPages, windowStart + windowSize - 1);
            windowStart = Math.max(1, windowEnd - windowSize + 1);

            if (windowStart > 1) {
                addButton('1', 1);
                if (windowStart > 2) {
                    const gap = document.createElement('span');
                    gap.className = 'pricing-page-gap';
                    gap.textContent = '…';
                    discountPagination.appendChild(gap);
                }
            }

            for (let page = windowStart; page <= windowEnd; page += 1) {
                addButton(String(page), page, { current: page === currentPage });
            }

            if (windowEnd < totalPages) {
                if (windowEnd < totalPages - 1) {
                    const gap = document.createElement('span');
                    gap.className = 'pricing-page-gap';
                    gap.textContent = '…';
                    discountPagination.appendChild(gap);
                }
                addButton(String(totalPages), totalPages);
            }

            addButton('›', currentPage + 1, { label: 'Next page', disabled: currentPage >= totalPages });
        };

        const renderDiscountRows = () => {
            const rows = filteredDiscountRows();
            const totalPages = Math.max(1, Math.ceil(rows.length / perPage));
            currentPage = Math.min(Math.max(1, currentPage), totalPages);

            const pageStart = (currentPage - 1) * perPage;
            const visibleRows = new Set(rows.slice(pageStart, pageStart + perPage));

            rows.forEach((row) => discountBody?.insertBefore(row, discountEmpty));
            discountRows.forEach((row) => {
                row.hidden = !visibleRows.has(row);
            });

            if (discountEmpty) {
                discountEmpty.hidden = discountRows.length === 0 || rows.length > 0;
            }

            if (discountRange) {
                const first = rows.length === 0 ? 0 : pageStart + 1;
                const last = Math.min(pageStart + perPage, rows.length);
                discountRange.textContent = rows.length === 0
                    ? 'Showing 0 of 0 products'
                    : `Showing ${first} to ${last} of ${rows.length} products`;
            }

            renderPagination(rows.length);
        };

        discountSearch?.addEventListener('input', () => {
            currentPage = 1;
            renderDiscountRows();
        });
        discountCategory?.addEventListener('change', () => {
            currentPage = 1;
            renderDiscountRows();
        });
        discountStatus?.addEventListener('change', () => {
            currentPage = 1;
            renderDiscountRows();
        });

        discountPagination?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-discount-page]');
            if (!button || button.disabled) return;
            const page = Number(button.dataset.discountPage);
            if (!Number.isFinite(page)) return;
            currentPage = page;
            renderDiscountRows();
        });

        discountSortButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const key = button.dataset.discountSort;
                if (sortKey === key) {
                    sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
                } else {
                    sortKey = key;
                    sortDirection = 'asc';
                }
                currentPage = 1;
                renderDiscountRows();

                discountSortButtons.forEach((item) => {
                    const heading = item.closest('th');
                    if (!heading) return;
                    if (item.dataset.discountSort === sortKey) {
                        heading.setAttribute('aria-sort', sortDirection === 'asc' ? 'ascending' : 'descending');
                    } else {
                        heading.setAttribute('aria-sort', 'none');
                    }
                });
            });
        });

        pricingWorkspace.querySelectorAll('[data-pricing-menu]').forEach((menu) => {
            menu.addEventListener('toggle', () => {
                if (!menu.open) return;
                pricingWorkspace.querySelectorAll('[data-pricing-menu][open]').forEach((other) => {
                    if (other !== menu) other.open = false;
                });
            });
        });

        renderDiscountRows();

        pricingWorkspace.querySelectorAll('[data-pricing-tab]').forEach((tab) => tab.addEventListener('click', () => {
            pricingWorkspace.querySelectorAll('[data-pricing-tab]').forEach((item) => {
                const active = item === tab;
                item.classList.toggle('is-active', active);
                item.setAttribute('aria-selected', String(active));
            });
            pricingWorkspace.querySelectorAll('[data-pricing-panel]').forEach((panel) => {
                panel.hidden = panel.dataset.pricingPanel !== tab.dataset.pricingTab;
            });
            pricingWorkspace.querySelectorAll('[data-pricing-menu][open]').forEach((menu) => {
                menu.open = false;
            });
        }));
    }

    document.addEventListener('click', (event) => {
        if (!pricingWorkspace || event.target.closest('[data-pricing-menu]')) return;
        pricingWorkspace.querySelectorAll('[data-pricing-menu][open]').forEach((menu) => {
            menu.open = false;
        });
    });

    const promotionForm = document.querySelector('[data-promotion-form]');
    if (promotionForm) {
        const value = promotionForm.querySelector('[data-promotion-value]');
        const percent = promotionForm.querySelector('[data-promotion-percent]');
        const discountType = promotionForm.querySelector('[data-promotion-discount-type]');
        const fixedField = promotionForm.querySelector('[data-promotion-fixed-field]');
        const percentField = promotionForm.querySelector('[data-promotion-percent-field]');
        const name = promotionForm.querySelector('[data-promotion-name]');
        const start = promotionForm.querySelector('[data-promotion-start]');
        const end = promotionForm.querySelector('[data-promotion-end]');
        const startTime = promotionForm.querySelector('[data-promotion-start-time]');
        const endTime = promotionForm.querySelector('[data-promotion-end-time]');
        const checks = [...promotionForm.querySelectorAll('[data-promotion-product]')];
        const eligibleChecks = checks.filter((check) => !check.disabled);
        const selectAll = promotionForm.querySelector('[data-promotion-select-all]');

        const syncPromotionTypeFields = () => {
            const isPercent = discountType?.value === 'percent';
            if (fixedField) fixedField.hidden = isPercent;
            if (percentField) percentField.hidden = !isPercent;
        };

        const syncPromotionSummary = () => {
            const currentValue = Math.max(0, Number(value?.value || 0));
            const currentPercent = Math.min(100, Math.max(0, Number(percent?.value || 0)));
            const selected = eligibleChecks.filter((check) => check.checked).length;
            const summaryName = promotionForm.querySelector('[data-promotion-summary-name]');
            const summaryValue = promotionForm.querySelector('[data-promotion-summary-value]');
            const summaryProducts = promotionForm.querySelector('[data-promotion-summary-products]');
            const summarySchedule = promotionForm.querySelector('[data-promotion-summary-schedule]');

            if (summaryName) summaryName.textContent = name?.value.trim() || 'Offer-based voucher';
            if (summaryValue) {
                summaryValue.textContent = discountType?.value === 'percent'
                    ? (currentPercent > 0 ? currentPercent + '% off voucher' : 'Enter discount percentage')
                    : (currentValue > 0
                        ? '₱' + currentValue.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' voucher'
                        : 'Enter voucher amount');
            }
            if (summaryProducts) summaryProducts.textContent = selected + ' selected';
            if (summarySchedule) {
                const startsAt = start?.value && startTime?.value ? `${start.value} ${startTime.value}` : '';
                const endsAt = end?.value && endTime?.value ? `${end.value} ${endTime.value}` : '';
                summarySchedule.textContent = startsAt && endsAt ? `${startsAt} to ${endsAt} PHT` : 'Not set';
            }

            if (selectAll) {
                selectAll.disabled = eligibleChecks.length === 0;
                selectAll.checked = eligibleChecks.length > 0 && selected === eligibleChecks.length;
                selectAll.indeterminate = selected > 0 && selected < eligibleChecks.length;
            }
        };

        [value, percent, name, start, end, startTime, endTime].forEach((field) => {
            field?.addEventListener('input', syncPromotionSummary);
            field?.addEventListener('change', syncPromotionSummary);
        });
        discountType?.addEventListener('change', () => {
            syncPromotionTypeFields();
            syncPromotionSummary();
        });
        checks.forEach((check) => check.addEventListener('change', syncPromotionSummary));
        selectAll?.addEventListener('change', () => {
            eligibleChecks.forEach((check) => { check.checked = selectAll.checked; });
            syncPromotionSummary();
        });

        promotionForm.addEventListener('submit', (event) => {
            const showToast = (message, focus) => {
                event.preventDefault();
                if (toast) {
                    toast.textContent = message;
                    toast.classList.add('is-visible');
                    window.clearTimeout(window.sellerToastTimer);
                    window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3000);
                }
                focus?.focus();
            };

            if (!eligibleChecks.some((check) => check.checked)) {
                showToast('Select at least one product enabled for Store Vouchers in Product Management.', eligibleChecks[0] || checks[0]);
                return;
            }

            if (start?.value && end?.value && startTime?.value && endTime?.value) {
                const startsAt = `${start.value}T${startTime.value}`;
                const endsAt = `${end.value}T${endTime.value}`;
                if (endsAt < startsAt) {
                    showToast('The end date and time must be after the start date and time.', endTime);
                    return;
                }
            }

            if (discountType?.value === 'percent') {
                const percentValue = Number(percent?.value || 0);
                if (percentValue < 1 || percentValue > 100) {
                    showToast('Enter a discount percentage between 1 and 100.', percent);
                    return;
                }
            } else if (Number(value?.value || 0) <= 0) {
                showToast('Enter a voucher amount greater than zero.', value);
                return;
            }

            const submitter = event.submitter;
            if (submitter) {
                submitter.disabled = true;
                submitter.setAttribute('aria-busy', 'true');
            }
        });

        syncPromotionTypeFields();
        syncPromotionSummary();
    }

    /* Customer Service — persisted buyer conversations (live thread + inbox). */
    const messageWorkspace = document.querySelector('[data-message-workspace]');
    if (messageWorkspace) {
        const list = messageWorkspace.querySelector('[data-conversation-list]');
        const search = messageWorkspace.querySelector('[data-message-search]');
        const empty = messageWorkspace.querySelector('[data-conversation-empty]');
        const thread = messageWorkspace.querySelector('[data-message-thread]');
        const input = messageWorkspace.querySelector('[data-message-input]');
        const fileInput = messageWorkspace.querySelector('[data-message-file]');
        const fileChip = messageWorkspace.querySelector('[data-message-file-chip]');
        const messageForm = messageWorkspace.querySelector('[data-message-form]');
        const inboxUrl = messageWorkspace.dataset.inboxUrl || '';
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const POLL_MS = 5000;
        const INBOX_EVERY_TICKS = 3;
        let filter = 'all';
        let conversations = [];
        let conversationRows = [];
        let activeRow = null;
        let activePayload = {};
        let lastMessageId = 0;
        let lastDayLabel = null;
        let renderedMessageIds = new Set();
        let lastInboxSnapshot = null;
        let sending = false;
        let pollTimer = null;
        let pollTick = 0;
        let visibilityHandler = null;

        const esc = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
        const rowPayload = (row) => {
            try { return JSON.parse(row?.dataset.payload || '{}'); } catch { return {}; }
        };
        const setText = (selector, value) => {
            const node = messageWorkspace.querySelector(selector);
            if (node) node.textContent = value;
        };
        const refreshUnreadTotals = () => {
            const unread = conversations.reduce((total, item) => total + Number(item.unread || 0), 0);
            setText('[data-inbox-unread-count]', String(unread));
        };
        const requestJson = async (url, options = {}) => {
            if (!url) throw new Error('This conversation is missing its endpoint. Reload the page.');
            const response = await fetch(url, {
                ...options,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(options.method && options.method !== 'GET' ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                    ...(options.headers || {}),
                },
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                const validationMessage = Object.values(result.errors || {}).flat()[0];
                throw new Error(validationMessage || result.message || 'Something went wrong. Try again.');
            }
            return result;
        };
        const seedState = () => {
            conversationRows = [...messageWorkspace.querySelectorAll('[data-conversation]')];
            conversations = conversationRows.map((row) => rowPayload(row));
            activeRow = conversationRows.find((row) => row.classList.contains('is-active')) ?? conversationRows[0] ?? null;
            activePayload = activeRow ? rowPayload(activeRow) : {};
            lastInboxSnapshot = JSON.stringify(conversations);
        };
        const rowNode = (conversation) => {
            const unread = Number(conversation.unread || 0);
            const row = document.createElement('button');
            row.type = 'button';
            row.className = `conversation-row${Number(conversation.id) === Number(activePayload.id) ? ' is-active' : ''}`;
            row.setAttribute('data-conversation', '');
            row.dataset.search = `${conversation.buyer} ${conversation.context} ${conversation.preview}`.toLowerCase();
            row.dataset.type = conversation.type;
            row.dataset.unread = String(unread);
            row.dataset.payload = JSON.stringify(conversation);
            const disputeBadge = conversation.dispute
                ? '<span class="conversation-dispute-badge"><i data-lucide="gavel"></i>Complaint &amp; Dispute</span>'
                : '';
            const presenceUsers = Array.isArray(conversation.presence_user_ids)
                ? conversation.presence_user_ids.join(',')
                : '';
            row.innerHTML = `<span class="conversation-avatar chat-presence-target" data-presence-conversation="${esc(conversation.id)}" data-presence-users="${esc(presenceUsers)}">${esc(conversation.initials)}</span><span class="conversation-copy"><span><strong>${esc(conversation.buyer)}</strong><time>${esc(conversation.time_label)}</time></span><em>${esc(conversation.preview)}</em>${disputeBadge}<span class="conversation-chip"><i data-lucide="${conversation.order ? 'receipt' : 'package'}"></i>${esc(conversation.order ? `Order #${conversation.order}` : conversation.context)}</span></span>${unread ? `<b class="conversation-unread">${unread}</b>` : ''}`;
            return row;
        };
        const renderConversationRows = () => {
            if (!list) return;
            conversationRows.forEach((row) => row.remove());
            const anchor = empty && empty.parentElement === list ? empty : null;
            conversationRows = conversations.map((conversation) => {
                const row = rowNode(conversation);
                if (anchor) list.insertBefore(row, anchor); else list.append(row);
                return row;
            });
            const activeId = Number(activePayload.id || 0);
            activeRow = conversationRows.find((row) => Number(rowPayload(row).id) === activeId) ?? activeRow;
            applyConversationFilter();
            window.lucide?.createIcons();
            window.BearlyPresence?.refresh?.();
        };
        const refreshInbox = async () => {
            if (!inboxUrl || sending) return;
            try {
                const result = await requestJson(inboxUrl);
                const incoming = result.data?.conversations;
                if (!Array.isArray(incoming)) return;
                const snapshot = JSON.stringify(incoming);
                if (snapshot === lastInboxSnapshot) return;
                lastInboxSnapshot = snapshot;
                conversations = incoming;
                renderConversationRows();
                refreshUnreadTotals();
            } catch { /* keep the current list when a background refresh fails */ }
        };
        const markRowRead = async (row, force = false) => {
            if (!row) return;
            const payload = rowPayload(row);
            if (!payload.read_url) return;
            if (!force && Number(row.dataset.unread || 0) === 0) return;
            try {
                await requestJson(payload.read_url, { method: 'POST' });
                row.dataset.unread = '0';
                row.querySelector('.conversation-unread')?.remove();
                const entry = conversations.find((item) => Number(item.id) === Number(payload.id));
                if (entry) entry.unread = 0;
                refreshUnreadTotals();
            } catch { /* keep the unread badge if the read marker fails */ }
        };
        const updateRowPreview = (id, message) => {
            const preview = message.text || (message.attachments || []).map((attachment) => attachment.name).join(', ') || 'Sent an attachment.';
            const entry = conversations.find((item) => Number(item.id) === Number(id));
            if (entry) {
                entry.preview = preview;
                entry.time_label = message.time_label || entry.time_label;
            }
            const row = conversationRows.find((item) => Number(rowPayload(item).id) === Number(id));
            const copy = row?.querySelector('.conversation-copy');
            if (!copy) return;
            const previewNode = copy.querySelector('em');
            const timeNode = copy.querySelector('time');
            if (previewNode) previewNode.textContent = preview;
            if (timeNode) timeNode.textContent = message.time_label ?? '';
        };
        const threadNote = (text) => {
            const note = document.createElement('p');
            note.className = 'message-thread-empty';
            note.textContent = text;
            return note;
        };
        const bubble = (message, initials) => {
            const row = document.createElement('article');
            row.className = `chat-bubble-row is-${message.from === 'me' ? 'seller' : 'buyer'}`;
            row.dataset.messageId = String(message.id ?? '');
            if (message.from === 'them') {
                const avatar = document.createElement('span');
                avatar.className = 'conversation-avatar';
                avatar.textContent = initials;
                row.append(avatar);
            }
            const body = document.createElement('div');
            if (message.text) {
                const copy = document.createElement('p');
                copy.textContent = message.text;
                body.append(copy);
            }
            (message.attachments || []).forEach((attachment) => {
                const wrapper = document.createElement('span');
                wrapper.innerHTML = attachmentPreviewHtml(attachment, {
                    linkClass: 'chat-attachment',
                    mediaClass: 'chat-attachment-preview',
                });
                body.append(...wrapper.childNodes);
            });
            const time = document.createElement('time');
            time.textContent = message.time_label;
            const meta = document.createElement('span');
            meta.className = 'chat-bubble-meta';
            meta.setAttribute('data-chat-meta', '');
            meta.append(time);
            if (message.from === 'me' && message.receipt) {
                meta.insertAdjacentHTML('beforeend', receiptMarkup(message.receipt));
            }
            body.append(meta);
            row.append(body);
            return row;
        };
        const appendDay = (label) => {
            if (!thread) return;
            const day = document.createElement('div');
            day.className = 'message-day';
            const span = document.createElement('span');
            span.textContent = label;
            day.append(span);
            thread.append(day);
        };
        const appendMessage = (message, initials) => {
            if (!thread) return;

            const messageId = Number(message.id || 0);

            if (messageId > 0 && renderedMessageIds.has(messageId)) return;
            if (messageId > 0) renderedMessageIds.add(messageId);

            if (message.day_label && message.day_label !== lastDayLabel) {
                lastDayLabel = message.day_label;
                appendDay(lastDayLabel);
            }
            thread.querySelector('.message-thread-empty')?.remove();
            thread.append(bubble(message, initials));
            lastMessageId = Math.max(lastMessageId, messageId);
        };
        const renderThread = (messages, initials) => {
            if (!thread) return;
            thread.replaceChildren();
            lastMessageId = 0;
            lastDayLabel = null;
            renderedMessageIds = new Set();
            (messages || []).forEach((message) => appendMessage(message, initials));
            if (!messages || messages.length === 0) thread.append(threadNote('No messages yet. Say hello to start the conversation.'));
            window.lucide?.createIcons();
            thread.scrollTop = thread.scrollHeight;
        };
        const syncRenderedThreadState = () => {
            if (!thread) return;
            const bubbles = thread.querySelectorAll('[data-message-id]');
            const lastBubble = bubbles[bubbles.length - 1];
            lastMessageId = lastBubble ? Number(lastBubble.dataset.messageId || 0) : 0;
            renderedMessageIds = new Set(
                [...bubbles]
                    .map((bubble) => Number(bubble.dataset.messageId || 0))
                    .filter((id) => id > 0),
            );
            const days = thread.querySelectorAll('.message-day span');
            lastDayLabel = days.length ? days[days.length - 1].textContent : null;
        };
        const renderThreadHeader = (payload, conversation = null) => {
            setText('[data-chat-initials]', payload.initials ?? 'B');
            setText('[data-chat-buyer]', payload.buyer ?? 'Buyer');
            const since = conversation?.counterpart?.since_label ?? payload.member ?? null;
            setText('[data-chat-member]', since ? 'Member since '.concat(since) : '');
            const orderNumber = conversation?.order?.number ?? payload.order ?? null;
            const dispute = conversation?.dispute ?? payload.dispute ?? null;
            setText('[data-chat-order]', dispute ? `Complaint & Dispute · ${dispute.number}` : (orderNumber ? `Order #${orderNumber}` : 'No active order'));
            messageWorkspace.querySelector('[data-chat-order]')?.classList.toggle('is-dispute', Boolean(dispute));
            const productName = conversation?.product?.name ?? payload.product ?? null;
            setText('[data-chat-context-product]', productName ?? 'this store');
            setText('[data-chat-context-order]', orderNumber ? `Order #${orderNumber}` : 'No active order yet');
            const strip = messageWorkspace.querySelector('[data-message-context]');
            if (strip) {
                strip.hidden = (payload?.counterpart_role ?? 'buyer') === 'admin';
                const url = conversation?.product?.url ?? payload.product_url ?? '';
                if (url) strip.setAttribute('href', url); else strip.removeAttribute('href');
                strip.classList.toggle('is-static', !url);
            }
            const avatar = messageWorkspace.querySelector('[data-chat-initials]');
            if (avatar) {
                const presenceUsers = Array.isArray(conversation?.presence_user_ids)
                    ? conversation.presence_user_ids
                    : (Array.isArray(payload?.presence_user_ids) ? payload.presence_user_ids : []);
                avatar.dataset.presenceConversation = String(payload?.id ?? conversation?.id ?? '');
                avatar.dataset.presenceUsers = presenceUsers.join(',');
            }
            window.BearlyPresence?.refresh?.();
        };
        const renderDetails = (payload, conversation = null) => {
            setText('[data-detail-initials]', payload.initials ?? 'B');
            setText('[data-detail-buyer]', payload.buyer ?? 'Buyer');
            const since = conversation?.counterpart?.since_label ?? payload.member ?? null;
            setText('[data-detail-member]', since ?? '—');
            setText('[data-detail-previous]', String(payload.previous ?? 0));

            const orderBox = messageWorkspace.querySelector('[data-detail-order-card]');
            if (orderBox) {
                const order = conversation?.order ?? null;
                if (order) {
                    orderBox.innerHTML = `<div class="detail-order-heading"><strong>Order #${esc(order.number)}</strong>${order.status_label ? `<span>${esc(order.status_label)}</span>` : ''}</div>${order.url ? `<a class="detail-order-button" href="${esc(order.url)}">Manage orders</a>` : ''}`;
                } else if (payload.order) {
                    orderBox.innerHTML = `<div class="detail-order-heading"><strong>Order #${esc(payload.order)}</strong>${payload.order_status ? `<span>${esc(payload.order_status)}</span>` : ''}</div>${payload.order_url ? `<a class="detail-order-button" href="${esc(payload.order_url)}">Manage orders</a>` : ''}`;
                } else {
                    orderBox.innerHTML = '<div class="detail-empty-card"><i data-lucide="package-x"></i><span>No active order for this conversation.</span></div>';
                }
            }

            const disputeSection = messageWorkspace.querySelector('[data-detail-dispute]');
            const disputeBox = messageWorkspace.querySelector('[data-detail-dispute-card]');
            const dispute = conversation?.dispute ?? payload.dispute ?? null;
            if (disputeSection && disputeBox) {
                disputeSection.hidden = !dispute;
                disputeBox.innerHTML = dispute ? `
                    <div class="detail-dispute-heading"><strong>${esc(dispute.number)}</strong><span>${esc(dispute.status_label || dispute.status || 'Open')}</span></div>
                    <strong class="detail-dispute-subject">${esc(dispute.subject)}</strong>
                    <p class="detail-dispute-description">${esc(dispute.description)}</p>
                    <dl class="detail-dispute-meta">
                        <div><dt>Seller Order</dt><dd>${esc(dispute.seller_order_no || '—')}</dd></div>
                        <div><dt>Shipment</dt><dd>${esc(dispute.shipment_no || 'Not linked')}</dd></div>
                        <div><dt>Order value</dt><dd>${esc(dispute.amount_label || '—')}</dd></div>
                    </dl>
                ` : '';
            }

            const productCard = messageWorkspace.querySelector('[data-detail-product-card]');
            if (productCard) {
                const product = conversation?.product ?? null;
                const url = product?.url ?? payload.product_url ?? '';
                const name = product?.name ?? payload.product ?? 'Direct message';
                const image = product?.image_url
                    ? `<img src="${esc(product.image_url)}" alt="">`
                    : `<i data-lucide="${product ? 'shirt' : 'messages-square'}"></i>`;
                const price = product?.price_label ? `<small>${esc(product.price_label)}</small>` : '';
                const hint = product ? '' : '<small>No product linked to this conversation.</small>';
                productCard.classList.toggle('is-static', !url);
                if (url) productCard.setAttribute('href', url); else productCard.removeAttribute('href');
                productCard.innerHTML = `<span class="detail-product-thumb">${image}</span><span class="detail-product-copy"><strong>${esc(name)}</strong>${price}${hint}</span>${url ? '<i data-lucide="chevron-right"></i>' : ''}`;
            }

            const actions = messageWorkspace.querySelector('[data-detail-actions]');
            if (actions) {
                const productUrl = conversation?.product?.url ?? payload.product_url ?? '';
                const orderUrl = conversation?.order?.url ?? payload.order_url ?? '';
                const body = actions.querySelector('[data-detail-actions-body]');
                if (body) {
                    body.innerHTML = [
                        productUrl ? `<a class="meta-row" href="${esc(productUrl)}"><i data-lucide="external-link"></i><span>Manage product</span><i data-lucide="chevron-right"></i></a>` : '',
                        orderUrl ? `<a class="meta-row" href="${esc(orderUrl)}"><i data-lucide="receipt"></i><span>Manage orders</span><i data-lucide="chevron-right"></i></a>` : '',
                    ].join('');
                }
                actions.hidden = !productUrl && !orderUrl;
            }

            window.lucide?.createIcons();
        };
        const openConversation = async (row) => {
            if (!row) return;
            activeRow = row;
            activePayload = rowPayload(row);
            conversationRows.forEach((item) => item.classList.toggle('is-active', item === row));
            renderThreadHeader(activePayload);
            renderDetails(activePayload);
            if (thread) {
                thread.replaceChildren();
                thread.append(threadNote('Loading messages…'));
            }
            try {
                const result = await requestJson(activePayload.thread_url);
                const conversation = result.data?.conversation ?? null;
                const messages = result.data?.messages ?? [];
                renderThread(messages, activePayload.initials ?? 'B');
                renderThreadHeader(activePayload, conversation);
                renderDetails(activePayload, conversation);

                acknowledgeIncoming(conversation || activePayload, messages, csrfToken).catch(() => {});
            } catch (error) {
                if (thread) {
                    thread.replaceChildren();
                    thread.append(threadNote(error.message || 'Unable to load this conversation.'));
                }
            }
            if (!document.hidden) await markRowRead(row, true);
        };
        const applyConversationFilter = () => {
            const query = search?.value.trim().toLowerCase() ?? '';
            let visible = 0;
            conversationRows.forEach((row) => {
                const matchesFilter = filter === 'all' || (filter === 'unread' && Number(row.dataset.unread) > 0) || row.dataset.type === filter;
                const matchesSearch = !query || row.dataset.search.includes(query);
                row.hidden = !(matchesFilter && matchesSearch);
                if (!row.hidden) visible += 1;
            });
            if (empty) empty.hidden = visible > 0;
        };
        list?.addEventListener('click', (event) => {
            const row = event.target.closest('[data-conversation]');
            if (!row) return;
            if (!messageWorkspace.querySelector('[data-message-thread]')) {
                /* Workspace loaded without an active conversation; open the chosen thread via a scoped load. */
                const id = rowPayload(row).id;
                if (id) {
                    const target = new URL(window.location.href);
                    target.searchParams.set('conversation', String(id));
                    window.location.assign(target.toString());
                }
                return;
            }
            openConversation(row);
        });
        messageWorkspace.querySelectorAll('[data-message-filter]').forEach((button) => button.addEventListener('click', () => {
            filter = button.dataset.messageFilter;
            messageWorkspace.querySelectorAll('[data-message-filter]').forEach((item) => item.classList.toggle('is-active', item === button));
            applyConversationFilter();
        }));
        search?.addEventListener('input', applyConversationFilter);
        messageWorkspace.querySelectorAll('[data-quick-reply]').forEach((button) => button.addEventListener('click', () => {
            if (input) { input.value = button.dataset.quickReply; input.focus(); }
        }));
        messageWorkspace.querySelector('[data-message-emoji]')?.addEventListener('click', () => {
            if (!input || input.disabled) return;
            const start = input.selectionStart ?? input.value.length;
            const end = input.selectionEnd ?? input.value.length;
            input.setRangeText('🙂', start, end, 'end');
            input.focus();
        });
        const pollThread = async () => {
            if (!activeRow || !activePayload.thread_url) return;
            try {
                const target = new URL(activePayload.thread_url, window.location.origin);
                if (lastMessageId > 0) target.searchParams.set('after', String(lastMessageId));
                const result = await requestJson(target.toString());
                const conversation = result.data?.conversation ?? null;
                if (conversation && Number(conversation.id) !== Number(activePayload.id)) return;
                renderThreadHeader(activePayload, conversation);
                renderDetails(activePayload, conversation);

                const receiptUpdates = Array.isArray(result.data?.receipt_updates)
                    ? result.data.receipt_updates
                    : [];
                if (receiptUpdates.length) {
                    updateReceiptElements(receiptUpdates, thread || messageWorkspace);
                }

                const incoming = Array.isArray(result.data?.messages) ? result.data.messages : [];
                if (incoming.length === 0) return;
                const nearBottom = thread
                    ? (thread.scrollHeight - thread.scrollTop - thread.clientHeight) < 80
                    : true;
                const initials = activePayload.initials ?? 'B';
                incoming.forEach((message) => appendMessage(message, initials));
                window.lucide?.createIcons();
                if (thread && nearBottom) thread.scrollTop = thread.scrollHeight;
                updateRowPreview(activePayload.id, incoming[incoming.length - 1]);
                acknowledgeIncoming(conversation || activePayload, incoming, csrfToken).catch(() => {});
                if (!document.hidden && incoming.some((message) => message.from === 'them')) await markRowRead(activeRow, true);
            } catch { /* transient polling failures are ignored; the next tick retries */ }
        };
        const startPolling = () => {
            if (pollTimer) return;
            pollTimer = window.setInterval(() => {
                if (document.hidden || sending) return;
                pollTick += 1;
                pollThread();
                if (pollTick % INBOX_EVERY_TICKS === 0) refreshInbox();
            }, POLL_MS);

            visibilityHandler = () => {
                if (document.hidden || sending) return;
                pollThread();
                refreshInbox();
                markRowRead(activeRow, true);
            };

            document.addEventListener('visibilitychange', visibilityHandler);
        };
        const clearAttachment = () => {
            if (fileInput) fileInput.value = '';
            if (fileChip) { fileChip.hidden = true; fileChip.replaceChildren(); }
        };
        const showAttachmentChip = () => {
            if (!fileChip) return;
            fileChip.replaceChildren();
            const file = fileInput?.files?.[0];
            if (!file) { fileChip.hidden = true; return; }
            const label = document.createElement('span');
            label.textContent = `Attach: ${file.name}`;
            const clear = document.createElement('button');
            clear.type = 'button';
            clear.setAttribute('aria-label', 'Remove attachment');
            const icon = document.createElement('i');
            icon.setAttribute('data-lucide', 'x');
            clear.append(icon);
            clear.addEventListener('click', clearAttachment);
            fileChip.append(label, clear);
            fileChip.hidden = false;
            window.lucide?.createIcons();
        };
        messageWorkspace.querySelector('[data-message-attach]')?.addEventListener('click', () => fileInput?.click());
        fileInput?.addEventListener('change', showAttachmentChip);
        messageForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (sending || !activeRow) return;
            const message = input?.value.trim() ?? '';
            const file = fileInput?.files?.[0] ?? null;
            if (!message && !file) { input?.focus(); return; }
            sending = true;
            const submit = messageForm.querySelector('[type="submit"]');
            if (submit) submit.disabled = true;
            try {
                const formData = new FormData();
                if (message) formData.append('message', message);
                if (file) formData.append('attachment', file);
                const result = await requestJson(activePayload.send_url, { method: 'POST', body: formData });
                const sent = result.data?.message;
                if (input) input.value = '';
                clearAttachment();
                if (thread && sent) {
                    appendMessage(sent, activePayload.initials ?? 'B');
                    window.lucide?.createIcons();
                    thread.scrollTop = thread.scrollHeight;
                }
                if (sent) updateRowPreview(activePayload.id, sent);
            } catch (error) {
                if (toast) { toast.textContent = error.message || 'Unable to send this message. Try again.'; toast.classList.add('is-visible'); }
            } finally {
                sending = false;
                if (submit) submit.disabled = false;
            }
        });
        input?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); messageForm?.requestSubmit(); }
        });
        seedState();
        syncRenderedThreadState();
        refreshUnreadTotals();
        if (!document.hidden) markRowRead(activeRow, true);
        startPolling();

        window.addEventListener('bearly:message-created', (event) => {
            const conversationId = Number(event.detail?.conversation_id || 0);
            if (Number(activePayload.id) === conversationId) {
                pollThread().catch(() => {});
            } else {
                refreshInbox().catch(() => {});
            }
        });

        window.addEventListener('bearly:message-receipt', (event) => {
            const receipts = Array.isArray(event.detail?.receipts) ? event.detail.receipts : [];
            if (receipts.length) updateReceiptElements(receipts, thread || messageWorkspace);
        });
    }

    /* Customer feedback — frontend-only filters and replies. */
    const feedbackWorkspace = document.querySelector('[data-feedback-workspace]');
    if (feedbackWorkspace) {
        const reviews = [...feedbackWorkspace.querySelectorAll('[data-feedback-review]')];
        const search = feedbackWorkspace.querySelector('[data-feedback-search]');
        const empty = feedbackWorkspace.querySelector('[data-feedback-empty]');
        let activeFilter = 'all';
        const applyFeedbackFilter = () => {
            const query = search?.value.trim().toLowerCase() ?? '';
            let visible = 0;
            reviews.forEach((review) => {
                const matches = (activeFilter === 'all' || review.dataset.status === activeFilter) && (!query || review.dataset.search.includes(query));
                review.hidden = !matches;
                if (matches) visible += 1;
            });
            if (empty) empty.hidden = visible > 0;
        };
        feedbackWorkspace.querySelectorAll('[data-feedback-filter]').forEach((button) => button.addEventListener('click', () => {
            activeFilter = button.dataset.feedbackFilter;
            feedbackWorkspace.querySelectorAll('[data-feedback-filter]').forEach((item) => item.classList.toggle('is-active', item === button));
            applyFeedbackFilter();
        }));
        search?.addEventListener('input', applyFeedbackFilter);
        feedbackWorkspace.querySelectorAll('[data-feedback-reply]').forEach((button) => button.addEventListener('click', () => {
            button.hidden = true;
            const form = button.parentElement.querySelector('[data-feedback-form]');
            if (form) { form.hidden = false; form.querySelector('textarea')?.focus(); }
        }));
        feedbackWorkspace.querySelectorAll('[data-feedback-cancel]').forEach((button) => button.addEventListener('click', () => {
            const area = button.closest('[data-feedback-reply-area]');
            area.querySelector('[data-feedback-form]').hidden = true;
            area.querySelector('[data-feedback-reply]').hidden = false;
        }));
        feedbackWorkspace.querySelectorAll('[data-feedback-form]').forEach((form) => form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const textarea = form.querySelector('textarea');
            if (!textarea?.value.trim()) { textarea?.focus(); return; }
            const endpoint = form.dataset.feedbackUrl || '';
            if (!endpoint) return;
            const submit = form.querySelector('[type="submit"]');
            if (submit) submit.disabled = true;
            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(form),
                });
                const result = await response.json().catch(() => ({}));
                if (!response.ok) {
                    const validationMessage = Object.values(result.errors || {}).flat()[0];
                    throw new Error(validationMessage || result.message || 'Unable to post this reply. Try again.');
                }
                const review = form.closest('[data-feedback-review]');
                const replyBlock = document.createElement('div');
                replyBlock.className = 'seller-feedback-response';
                const replyLabel = document.createElement('span');
                const replyCopy = document.createElement('p');
                replyLabel.textContent = 'Seller response';
                replyCopy.textContent = result.data?.response || textarea.value.trim();
                replyBlock.append(replyLabel, replyCopy);
                form.closest('[data-feedback-reply-area]').replaceWith(replyBlock);
                review.dataset.status = 'replied';
                const status = review.querySelector('.feedback-review-status');
                if (status) { status.textContent = 'Replied'; status.classList.add('is-replied'); }
                if (toast) { toast.textContent = 'Reply posted.'; toast.classList.add('is-visible'); }
            } catch (error) {
                if (toast) { toast.textContent = error.message || 'Unable to post this reply. Try again.'; toast.classList.add('is-visible'); }
                if (submit) submit.disabled = false;
            }
        }));
        applyFeedbackFilter();
    }

    /* Reports — client-side search over server-rendered report rows. */
    document.querySelectorAll('[data-report-page]').forEach((page) => {
        const search = page.querySelector('[data-report-search]');
        const rows = [...page.querySelectorAll('[data-report-row]')];
        const count = page.querySelector('[data-report-count]');
        const empty = page.querySelector('[data-report-empty]');

        const filterRows = () => {
            const query = search?.value.trim().toLowerCase() ?? '';
            let visible = 0;
            rows.forEach((row) => {
                const matches = !query || row.dataset.search.includes(query);
                row.hidden = !matches;
                if (matches) visible += 1;
            });
            if (count) count.textContent = visible;
            if (empty) empty.hidden = visible > 0;
        };

        search?.addEventListener('input', filterRows);
    });

    /* Period/interval selects submit their report filter form for server-side navigation. */
    document.querySelectorAll('[data-period-nav]').forEach((control) => {
        control.addEventListener('change', () => {
            const form = control.closest('form');
            if (!form) return;
            if (typeof form.requestSubmit === 'function') form.requestSubmit();
            else form.submit();
        });
    });

    const documentPreviewModal = document.querySelector('[data-document-preview-modal]');
    const documentPreviewFrame = documentPreviewModal?.querySelector('[data-document-preview-frame]');
    const documentPreviewTitle = documentPreviewModal?.querySelector('[data-document-preview-title]');
    const closeDocumentPreview = () => {
        if (!documentPreviewModal) return;
        documentPreviewModal.hidden = true;
        if (documentPreviewFrame) documentPreviewFrame.src = 'about:blank';
        document.body.style.overflow = '';
    };

    document.querySelectorAll('[data-document-preview]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            if (!documentPreviewModal || !documentPreviewFrame) return;
            if (documentPreviewTitle) documentPreviewTitle.textContent = link.dataset.documentPreview || 'Document Preview';
            documentPreviewFrame.src = link.href;
            documentPreviewModal.hidden = false;
            document.body.style.overflow = 'hidden';
            documentPreviewModal.querySelector('[data-document-preview-close]')?.focus();
        });
    });

    documentPreviewModal?.querySelectorAll('[data-document-preview-close]').forEach((button) => {
        button.addEventListener('click', closeDocumentPreview);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && documentPreviewModal && !documentPreviewModal.hidden) closeDocumentPreview();
    });

    /* Product discount form preview. */
    const productDiscountForm = document.querySelector('[data-product-discount-form]');
    if (productDiscountForm) {
        const percentInput = productDiscountForm.querySelector('[data-discount-percent]');
        const productRows = [...productDiscountForm.querySelectorAll('[data-discount-product]')];
        const selectedCount = productDiscountForm.querySelector('[data-selected-product-count]');
        const previewDiscount = () => {
            const percent = Math.min(90, Math.max(0, Number(percentInput?.value || 0)));
            productRows.forEach((row) => {
                const regular = Number(row.dataset.price || 0);
                const preview = row.querySelector('[data-discount-preview]');
                if (preview) preview.textContent = `Promo ${formatPricingMoney(Math.round(regular * (1 - percent / 100) * 100))}`;
            });
            if (selectedCount) {
                selectedCount.textContent = productRows.filter((row) => row.querySelector('input[type="checkbox"]')?.checked).length;
            }
        };
        percentInput?.addEventListener('input', previewDiscount);
        productRows.forEach((row) => row.querySelector('input[type="checkbox"]')?.addEventListener('change', previewDiscount));
        previewDiscount();
    }

    /* Bearly campaign product selection and net preview. */
    const campaignJoinForm = document.querySelector('[data-campaign-join-form]');
    if (campaignJoinForm) {
        const funding = campaignJoinForm.dataset.funding;
        const minimumDiscount = Math.min(90, Math.max(1, Number(campaignJoinForm.dataset.minimumDiscount || 1)));
        const rows = [...campaignJoinForm.querySelectorAll('[data-campaign-product]')];
        const renumberRows = () => {
            let index = 0;
            rows.forEach((row) => {
                const checkbox = row.querySelector('[data-campaign-product-checkbox]');
                const discount = row.querySelector('[data-campaign-discount]');
                const stock = row.querySelector('[data-campaign-stock]');
                const buyerLimit = row.querySelector('[data-campaign-buyer-limit]');
                const error = row.querySelector('[data-campaign-discount-error]');
                const checked = Boolean(checkbox?.checked);

                [discount, stock, buyerLimit].forEach((input) => { if (input) input.disabled = !checked; });
                if (checkbox) {
                    checkbox.name = checked ? `items[${index}][product_id]` : '';
                    checkbox.disabled = false;
                }
                if (checked && discount) {
                    discount.name = `items[${index}][discount_percent]`;
                    discount.required = true;
                    if (!discount.value) discount.value = String(minimumDiscount);
                } else if (discount) {
                    discount.name = '';
                    discount.required = false;
                }
                if (stock) stock.name = checked ? `items[${index}][stock_limit]` : '';
                if (buyerLimit) buyerLimit.name = checked ? `items[${index}][per_buyer_limit]` : '';

                const percent = discount?.value === '' ? minimumDiscount : Number(discount?.value);
                const validDiscount = Number.isInteger(percent) && percent >= minimumDiscount && percent <= 90;
                if (discount) {
                    discount.min = String(minimumDiscount);
                    discount.max = '90';
                    discount.setCustomValidity(checked && !validDiscount
                        ? `Enter a whole discount from ${minimumDiscount}% to 90%.`
                        : '');
                }
                if (error) error.hidden = !checked || validDiscount;

                row.querySelectorAll('[data-campaign-variant]').forEach((variant) => {
                    const baseline = Number(variant.dataset.baselineMinor || 0);
                    const campaignPrice = validDiscount
                        ? Math.max(1, Math.floor((baseline * (100 - percent) + 50) / 100))
                        : null;
                    const pricePreview = variant.querySelector('[data-campaign-price-preview]');
                    const netPreview = variant.querySelector('[data-campaign-net]');
                    if (pricePreview) {
                        pricePreview.textContent = campaignPrice === null ? '—' : formatPricingMoney(campaignPrice);
                    }
                    if (netPreview) {
                        const sellerNet = campaignPrice === null
                            ? null
                            : (funding === 'platform' ? baseline : campaignPrice);
                        netPreview.textContent = sellerNet === null ? '—' : formatPricingMoney(sellerNet);
                    }
                });

                if (checked) index += 1;
            });
        };

        rows.forEach((row) => {
            row.querySelector('[data-campaign-product-checkbox]')?.addEventListener('change', renumberRows);
            row.querySelector('[data-campaign-discount]')?.addEventListener('input', renumberRows);
        });
        campaignJoinForm.addEventListener('submit', (event) => {
            const checked = rows.filter((row) => row.querySelector('[data-campaign-product-checkbox]')?.checked);
            if (checked.length === 0) {
                event.preventDefault();
                window.alert('Select at least one product for this campaign.');
                rows[0]?.querySelector('[data-campaign-product-checkbox]')?.focus();
                return;
            }
            const invalid = checked.find((row) => {
                const discount = row.querySelector('[data-campaign-discount]');
                const percent = Number(discount?.value);
                return !Number.isInteger(percent) || percent < minimumDiscount || percent > 90;
            });
            if (invalid) {
                event.preventDefault();
                const discount = invalid.querySelector('[data-campaign-discount]');
                discount?.focus();
                discount?.reportValidity();
            }
        });
        renumberRows();
    }

    /* Campaign list filter. */
    const campaignFilter = document.querySelector('[data-campaign-filter]');
    if (campaignFilter) {
        const cards = [...document.querySelectorAll('[data-campaign-card]')];
        const empty = document.querySelector('[data-campaign-empty]');
        const applyCampaignFilter = () => {
            const value = campaignFilter.value;
            let visible = 0;
            cards.forEach((card) => {
                const matches = !value
                    || (value === 'joined' ? card.dataset.joined === 'true' : card.dataset.status === value);
                card.hidden = !matches;
                if (matches) visible += 1;
            });
            if (empty) empty.hidden = cards.length === 0 || visible > 0;
        };
        campaignFilter.addEventListener('change', applyCampaignFilter);
        applyCampaignFilter();
    }

    const serverToast = document.querySelector('[data-server-toast]');
    if (serverToast) window.setTimeout(() => serverToast.classList.remove('is-visible'), 3200);
};

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootSeller);
else bootSeller();

/* =========================================================
   ORDER WORKFLOW MODAL
   ========================================================= */

function initializeOrderWorkflowModal() {
    const workspace =
        document.querySelector('[data-orders-workspace]');

    const modal =
        document.querySelector('[data-order-workflow-modal]');

    if (
        !workspace ||
        !modal ||
        modal.dataset.initialized === 'true'
    ) {
        return;
    }

    modal.dataset.initialized = 'true';


    const title =
        modal.querySelector('[data-order-workflow-title]');

    const subtitle =
        modal.querySelector('[data-order-workflow-subtitle]');

    const iconWrap =
        modal.querySelector('[data-order-workflow-icon-wrap]');

    const status =
        modal.querySelector('[data-order-workflow-status]');

    const deadline =
        modal.querySelector('[data-order-workflow-deadline]');

    const orderId =
        modal.querySelector('[data-order-workflow-id]');

    const customer =
        modal.querySelector('[data-order-workflow-customer]');

    const payment =
        modal.querySelector('[data-order-workflow-payment]');

    const total =
        modal.querySelector('[data-order-workflow-total]');

    const itemCount =
        modal.querySelector('[data-order-workflow-item-count]');

    const itemsList =
        modal.querySelector(
            '[data-order-workflow-items-list]'
        );

    const guidance =
        modal.querySelector(
            '[data-order-workflow-guidance]'
        );

    const guidanceIcon =
        modal.querySelector(
            '[data-order-workflow-guidance-icon]'
        );

    const guidanceText =
        modal.querySelector(
            '[data-order-workflow-guidance-text]'
        );

    const checklist =
        modal.querySelector(
            '[data-order-workflow-checklist]'
        );

    const timelinePanel =
        modal.querySelector(
            '[data-order-workflow-timeline-panel]'
        );

    const timeline =
        modal.querySelector(
            '[data-order-workflow-timeline]'
        );

    const primary =
        modal.querySelector(
            '[data-order-workflow-primary]'
        );

    const closeButton =
        modal.querySelector('.order-workflow-x');


    let lastFocus = null;
    let currentRow = null;
    let currentConfig = null;


    /*
     * One modal, different content depending
     * on the current seller action.
     */
    const configs = {

        review: {
            title: 'Review and Confirm Order',

            subtitle:
                'Check the order details and confirm if you can fulfill this order.',

            icon: 'clipboard-check',

            primary: 'Confirm Order',

            guidance:
                'By confirming, you accept this order and will prepare it for pickup.',

            checks: [],
        },


        prepare: {
            title: 'Start Preparing Order',

            subtitle:
                'Prepare the correct items and organize them for packing.',

            icon: 'package-check',

            primary: 'Start Preparing',

            guidance:
                'Before packing, verify the item, variation, and quantity against the order.',

            checks: [],
        },


        packing: {
            title: 'Continue Packing',

            subtitle:
                'Finish packing and get the parcel ready for its shipping label.',

            icon: 'package',

            primary: 'Continue Packing',

            guidance: '',

            checks: [
                'Correct item and variation are packed',
                'Order quantity is complete',
                'Parcel is secure and in good condition',
            ],
        },


        waybill: {
            title: 'Print Waybill / Label',

            subtitle:
                'Prepare the shipping label for this packed order.',

            icon: 'printer',

            primary: 'Print Waybill',

            guidance:
                'Print the waybill and attach it securely to the parcel before marking the order ready for pickup.',

            checks: [],
        },


        ready: {
            title: 'Mark as Ready for Pickup',

            subtitle:
                'Confirm that the packed and labeled parcel is ready for rider pickup.',

            icon: 'truck',

            primary: 'Mark as Ready',

            guidance:
                'Once marked as ready, the parcel can move to Pickup Requests for logistics handover.',

            checks: [],
        },


        details: {
            title: 'View Order Details',

            subtitle:
                'View the order information and current delivery status.',

            icon: 'circle-info',

            primary: '',

            guidance: '',

            checks: [],

            readOnly: true,
        },

    };


    const readableStatus = value => ({
        PLACED: 'Placed',
        CONFIRMED: 'Confirmed',
        PREPARING: 'Preparing',
        PACKED: 'Packed',
        READY_FOR_PICKUP: 'Ready for Pickup',
        PICKED_UP: 'Picked Up',
        AT_SORTING_CENTER: 'At Sorting Center',
        SORTED: 'Sorted',
        ASSIGNED_TO_RIDER: 'Rider Assigned',
        OUT_FOR_DELIVERY: 'Out for Delivery',
        DELIVERED: 'Delivered',
        COMPLETED: 'Completed',
        DELIVERY_FAILED: 'Delivery Failed',
        RETURNED: 'Returned',
        CANCELLED: 'Cancelled',
    }[value] || value.replaceAll('_', ' '));


    /*
     * Determine which modal should be shown
     * from the actual action label / ERP status.
     */
    function configFor(row, mode) {

        if (mode === 'details') {
            return configs.details;
        }


        const action =
            (row.dataset.orderAction || '')
                .trim()
                .toLowerCase();


        const canonical =
            (row.dataset.orderCanonical || '')
                .trim()
                .toUpperCase();


        if (
            action.includes('review') ||
            action.includes('confirm')
        ) {
            return configs.review;
        }


        if (
            action.includes('start preparing')
        ) {
            return configs.prepare;
        }


        if (
            action.includes('continue packing') ||
            action.includes('packing')
        ) {
            return configs.packing;
        }


        if (
            action.includes('print waybill') ||
            action.includes('print label')
        ) {
            return configs.waybill;
        }


        if (
            action.includes('mark as ready')
        ) {
            return configs.ready;
        }


        if (
            action.includes('view') ||
            action.includes('track')
        ) {
            return configs.details;
        }


        /*
         * Status fallback.
         */
        if (canonical === 'PLACED') {
            return configs.review;
        }

        if (canonical === 'CONFIRMED') {
            return configs.prepare;
        }

        if (canonical === 'PREPARING') {
            return configs.packing;
        }

        if (canonical === 'PACKED') {
            return configs.waybill;
        }


        return configs.details;
    }


    /*
     * Product / item rows
     */
    function renderItems(row) {
        itemsList.replaceChildren();


        const raw =
            (row.dataset.orderItems || '').trim();


        const parts = raw
            ? raw
                .split(/\s+\+\s+/)
                .map(part => part.trim())
                .filter(Boolean)

            : ['Item details unavailable'];


        parts.forEach(part => {

            const article =
                document.createElement('article');

            article.className =
                'order-workflow-item';


            const iconBox =
                document.createElement('span');

            iconBox.className =
                'order-workflow-item-icon';

            iconBox.innerHTML = `
                <i
                    data-lucide="package"
                    aria-hidden="true"
                ></i>
            `;


            const copy =
                document.createElement('div');


            const strong =
                document.createElement('strong');

            strong.textContent = part;


            const small =
                document.createElement('small');

            small.textContent =
                'Order item';


            copy.append(
                strong,
                small
            );


            article.append(
                iconBox,
                copy
            );


            itemsList.append(article);

        });
    }


    /*
     * Action-specific guidance / checklist
     */
    function renderGuidance(config) {
        checklist.replaceChildren();


        if (config.checks.length) {

            guidance.hidden = true;
            checklist.hidden = false;


            config.checks.forEach(text => {

                const li =
                    document.createElement('li');


                li.innerHTML = `
                    <span>
                        <i
                            data-lucide="check"
                            aria-hidden="true"
                        ></i>
                    </span>
                `;


                const copy =
                    document.createElement('strong');

                copy.textContent = text;


                li.append(copy);

                checklist.append(li);

            });


            return;
        }


        checklist.hidden = true;

        guidance.hidden =
            !config.guidance;


        guidanceText.textContent =
            config.guidance || '';


        guidanceIcon.innerHTML = `
            <i
                data-lucide="info"
                aria-hidden="true"
            ></i>
        `;
    }


    /*
     * Read-only order status timeline.
     * Visible names follow the ERP flow,
     * not database-style underscore names.
     */
    function renderTimeline(canonical) {
        timeline.replaceChildren();


        if (canonical === 'CANCELLED') {

            appendTimeline(
                [
                    ['PLACED', 'Placed'],
                    ['CANCELLED', 'Cancelled'],
                ],
                canonical
            );

            return;
        }


        if (canonical === 'RETURNED') {

            appendTimeline(
                [
                    ['PLACED', 'Placed'],
                    ['CONFIRMED', 'Confirmed'],
                    ['PREPARING', 'Preparing'],
                    [
                        'READY_FOR_PICKUP',
                        'Ready for Pickup'
                    ],
                    ['PICKED_UP', 'Picked Up'],
                    [
                        'AT_SORTING_CENTER',
                        'At Sorting Center'
                    ],
                    ['SORTED', 'Sorted'],
                    [
                        'ASSIGNED_TO_RIDER',
                        'Rider Assigned'
                    ],
                    [
                        'OUT_FOR_DELIVERY',
                        'Out for Delivery'
                    ],
                    ['RETURNED', 'Returned'],
                ],
                canonical
            );

            return;
        }


        if (canonical === 'DELIVERY_FAILED') {

            appendTimeline(
                [
                    ['PLACED', 'Placed'],
                    ['CONFIRMED', 'Confirmed'],
                    ['PREPARING', 'Preparing'],
                    [
                        'READY_FOR_PICKUP',
                        'Ready for Pickup'
                    ],
                    ['PICKED_UP', 'Picked Up'],
                    [
                        'AT_SORTING_CENTER',
                        'At Sorting Center'
                    ],
                    ['SORTED', 'Sorted'],
                    [
                        'ASSIGNED_TO_RIDER',
                        'Rider Assigned'
                    ],
                    [
                        'OUT_FOR_DELIVERY',
                        'Out for Delivery'
                    ],
                    [
                        'DELIVERY_FAILED',
                        'Delivery Failed'
                    ],
                ],
                canonical
            );

            return;
        }


        /*
         * PACKED is part of the seller's
         * preparation process.
         */
        const normalized =
            canonical === 'PACKED'
                ? 'PREPARING'
                : canonical;


        appendTimeline(
            [
                ['PLACED', 'Placed'],
                ['CONFIRMED', 'Confirmed'],
                ['PREPARING', 'Preparing'],
                [
                    'READY_FOR_PICKUP',
                    'Ready for Pickup'
                ],
                ['PICKED_UP', 'Picked Up'],
                [
                    'AT_SORTING_CENTER',
                    'At Sorting Center'
                ],
                ['SORTED', 'Sorted'],
                [
                    'ASSIGNED_TO_RIDER',
                    'Rider Assigned'
                ],
                [
                    'OUT_FOR_DELIVERY',
                    'Out for Delivery'
                ],
                ['DELIVERED', 'Delivered'],
                ['COMPLETED', 'Completed'],
            ],
            normalized
        );
    }


    function appendTimeline(
        stages,
        currentStatus
    ) {

        const currentIndex =
            Math.max(
                0,
                stages.findIndex(
                    ([key]) =>
                        key === currentStatus
                )
            );


        stages.forEach(
            ([key, label], index) => {

                const li =
                    document.createElement('li');


                li.className =
                    index < currentIndex
                        ? 'is-done'
                        : index === currentIndex
                            ? 'is-current'
                            : 'is-upcoming';


                const marker =
                    document.createElement('span');


                marker.innerHTML =
                    index <= currentIndex
                        ? `
                            <i
                                data-lucide="check"
                                aria-hidden="true"
                            ></i>
                        `
                        : '';


                const copy =
                    document.createElement('div');


                const strong =
                    document.createElement('strong');

                strong.textContent =
                    label;


                copy.append(strong);


                if (index === currentIndex) {

                    const small =
                        document.createElement(
                            'small'
                        );

                    small.textContent =
                        'Current status';

                    copy.append(small);

                }


                li.append(
                    marker,
                    copy
                );


                timeline.append(li);

            }
        );
    }


    function openModal(
        row,
        mode
    ) {

        const config =
            configFor(row, mode);


        const canonical =
            (
                row.dataset.orderCanonical ||
                ''
            )
                .trim()
                .toUpperCase();


        currentRow = row;
        currentConfig = config;

        lastFocus =
            document.activeElement;


        title.textContent =
            config.title;


        subtitle.textContent =
            config.subtitle;


        iconWrap.innerHTML = `
            <i
                data-lucide="${config.icon}"
                aria-hidden="true"
            ></i>
        `;


        status.textContent =
            row.dataset.orderStatusCopy ||
            readableStatus(canonical);


        if (
            row.dataset.orderDeadline
        ) {

            deadline.textContent =
                `Deadline / Update · ${row.dataset.orderDeadline}`;

            deadline.hidden = false;

        } else {

            deadline.textContent = '';

            deadline.hidden = true;

        }


        orderId.textContent =
            row.dataset.orderId || '—';


        customer.textContent =
            row.dataset.orderCustomer || '—';


        payment.textContent =
            row.dataset.orderPaymentCopy || '—';


        total.textContent =
            row.dataset.orderTotal || '—';


        itemCount.textContent =
            row.dataset.orderItemCount ||
            'Items';


        renderItems(row);

        renderGuidance(config);


        timelinePanel.hidden =
            !config.readOnly;


        if (config.readOnly) {
            renderTimeline(canonical);
        }


        primary.hidden =
            !config.primary;


        primary.textContent =
            config.primary || '';


        modal.hidden = false;

        document.body.classList.add(
            'modal-open'
        );


        /*
         * Re-render Lucide icons inserted
         * dynamically in this modal.
         */
        window.lucide?.createIcons();


        if (config.primary) {
            primary.focus();
        } else {
            closeButton?.focus();
        }
    }


    function closeModal() {

        modal.hidden = true;

        document.body.classList.remove(
            'modal-open'
        );


        currentRow = null;
        currentConfig = null;


        lastFocus?.focus?.();
    }


    function showToast(message) {

        const toast =
            document.querySelector(
                '[data-seller-toast]'
            );


        if (!toast) {
            return;
        }


        toast.textContent = message;

        toast.classList.add(
            'is-visible'
        );


        clearTimeout(
            window.orderWorkflowToastTimer
        );


        window.orderWorkflowToastTimer =
            setTimeout(() => {

                toast.classList.remove(
                    'is-visible'
                );

            }, 3500);
    }


    /*
     * Open modal from:
     * - Order ID = read-only details
     * - Action column = action-specific modal
     */
    workspace.addEventListener(
        'click',
        event => {

            const trigger =
                event.target.closest(
                    '[data-order-workflow-open]'
                );


            if (!trigger) {
                return;
            }


            const row =
                trigger.closest(
                    '[data-order-row]'
                );


            if (!row) {
                return;
            }


            event.preventDefault();


            openModal(
                row,
                trigger.dataset.workflowMode ||
                'action'
            );
        }
    );


    /*
     * Close controls
     */
    modal
        .querySelectorAll(
            '[data-order-workflow-close]'
        )
        .forEach(button => {

            button.addEventListener(
                'click',
                closeModal
            );

        });


    /*
     * Front-end-only action.
     *
     * Later this is where backend order
     * status updates can be connected.
     */
    primary.addEventListener(
        'click',
        () => {

            if (
                !currentRow ||
                !currentConfig ||
                !currentConfig.primary
            ) {
                return;
            }


            showToast(
                `${currentConfig.primary} is a front-end preview. No order status was changed.`
            );


            closeModal();
        }
    );


    /*
     * ESC key
     */
    document.addEventListener(
        'keydown',
        event => {

            if (
                event.key === 'Escape' &&
                !modal.hidden
            ) {
                closeModal();
            }

        }
    );
}


if (
    document.readyState === 'loading'
) {

    document.addEventListener(
        'DOMContentLoaded',
        initializeOrderWorkflowModal
    );

} else {

    initializeOrderWorkflowModal();

}

/* =========================================================
   MANAGE ORDERS ACTION MODAL
   Moved out of orders.blade.php
   Append to resources/js/seller.js
   ========================================================= */

const setupBearlyOrderActionModal = () => {
    const modal = document.querySelector('[data-bearly-action-modal]');
    if (!modal || modal.dataset.bound === '1') return;

    modal.dataset.bound = '1';

    const q = (selector) => modal.querySelector(selector);
    const title = q('[data-bearly-action-title]');
    const subtitle = q('[data-bearly-action-subtitle]');
    const icon = q('[data-bearly-action-icon]');
    const badge = q('[data-bearly-action-badge]');
    const orderId = q('[data-bearly-order-id]');
    const customer = q('[data-bearly-order-customer]');
    const orderDate = q('[data-bearly-order-date]');
    const payment = q('[data-bearly-order-payment]');
    const total = q('[data-bearly-order-total]');
    const address = q('[data-bearly-order-address]');
    const addressCell = q('[data-bearly-address-cell]');
    const addressLabel = q('[data-bearly-address-label]');
    const itemCount = q('[data-bearly-item-count]');
    const items = q('[data-bearly-items]');
    const context = q('[data-bearly-action-context]');
    const transitionForm = q('[data-bearly-transition-form]');
    const actionField = q('[data-bearly-action-field]');
    const actionError = q('[data-bearly-action-error]');
    const cancelControls = q('[data-bearly-cancel-controls]');
    const cancelReason = q('[data-bearly-cancel-reason]');
    const cancelBack = q('[data-bearly-cancel-back]');
    const primary = q('[data-bearly-action-primary]');
    const footer = q('[data-bearly-action-footer]');

    let lastFocus = null;
    let active = null;
    let trackingTimer = null;
    let trackingController = null;
    let trackingRequestInFlight = false;
    let lastTrackingSignature = '';

    const statusLabels = {
        PLACED: 'Placed',
        CONFIRMED: 'Confirmed',
        PREPARING: 'Preparing',
        READY_FOR_PICKUP: 'Ready for Pickup',
        PICKED_UP: 'Picked Up',
        AT_SORTING_CENTER: 'At Sorting Center',
        SORTED: 'Sorted',
        ASSIGNED_TO_RIDER: 'Rider Assigned',
        OUT_FOR_DELIVERY: 'Out for Delivery',
        DELIVERED: 'Delivered',
        COMPLETED: 'Completed',
        DELIVERY_FAILED: 'Delivery Failed',
        RETURNED: 'Returned',
        CANCELLED: 'Cancelled',
        RECEIVED: 'At Sorting Center',
        FAILED: 'Delivery Failed',
    };

    const flow = [
        ['PLACED', 'Placed'],
        ['CONFIRMED', 'Confirmed'],
        ['PREPARING', 'Preparing'],
        ['READY_FOR_PICKUP', 'Ready for Pickup'],
        ['PICKED_UP', 'Picked Up'],
        ['AT_SORTING_CENTER', 'At Sorting Center'],
        ['SORTED', 'Sorted'],
        ['ASSIGNED_TO_RIDER', 'Rider Assigned'],
        ['OUT_FOR_DELIVERY', 'Out for Delivery'],
        ['DELIVERED', 'Delivered'],
        ['COMPLETED', 'Completed'],
    ];

    const configFor = (row, mode) => {
        const transition = row.dataset.orderTransitionAction || '';

        if (mode === 'details' || !transition) {
            return {
                type: 'details',
                title: 'Order Details',
                subtitle: 'View the complete details and current status of this order.',
                icon: 'info',
                button: null,
            };
        }

        if (transition === 'confirm') {
            return {
                type: 'review',
                title: 'Review and Confirm Order',
                subtitle: 'Check the order details and confirm if you can fulfill this order.',
                icon: 'clipboard-check',
                button: 'Confirm Order',
                action: 'confirm',
            };
        }

        if (transition === 'start_preparing') {
            return {
                type: 'prepare',
                title: 'Start Preparing Order',
                subtitle: 'Prepare the correct items and organize them for packing.',
                icon: 'package-check',
                button: 'Start Preparing',
                action: 'start_preparing',
            };
        }

        if (transition === 'mark_ready') {
            return {
                type: 'packing',
                title: 'Complete Packing',
                subtitle: 'Complete the checks before marking this order ready for pickup.',
                icon: 'package',
                button: 'Mark as Ready',
                action: 'mark_ready',
            };
        }

        return {
            type: 'details',
            title: 'Order Details',
            subtitle: 'View the complete details and current status of this order.',
            icon: 'info',
            button: null,
        };
    };

    const productIcon = (text) => {
        const value = text.toLowerCase();
        if (value.includes('shirt') || value.includes('dress') || value.includes('top')) return 'shirt';
        if (value.includes('bag') || value.includes('tote')) return 'shopping-bag';
        if (value.includes('shoe') || value.includes('sneaker')) return 'footprints';
        if (value.includes('watch')) return 'watch';
        return 'package';
    };

    const paymentText = (row) => {
        const raw = (row.dataset.orderPaymentCopy || '').trim();
        const key = (row.dataset.orderPaymentKey || row.dataset.payment || '').trim().toLowerCase();
        const canonical = (row.dataset.orderCanonical || '').trim().toLowerCase();
        const statusCopy = (row.dataset.orderStatusCopy || '').trim().toLowerCase();

        const badValues = new Set([
            'cancelled',
            'returned',
            'completed',
            'delivered',
            'preparing',
            'confirmed',
            'placed',
            'packed',
            'ready for pickup',
            'delivery failed',
        ]);

        if (
            raw &&
            !badValues.has(raw.toLowerCase()) &&
            raw.toLowerCase() !== canonical &&
            raw.toLowerCase() !== statusCopy
        ) {
            return raw;
        }

        if (key === 'cod') return 'Cash on Delivery';
        if (key === 'paid') return 'Paid';
        return 'Payment details unavailable';
    };

    const formatOrderDate = (rawValue) => {
        const raw = String(rawValue || '').trim();
        if (!raw) return 'Not available';

        const lower = raw.toLowerCase();

        if (lower === 'today') {
            return new Intl.DateTimeFormat('en-PH', {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
            }).format(new Date());
        }

        if (['upcoming', 'previous', 'upcoming / previous'].includes(lower)) {
            return 'Not available';
        }

        const parsed = new Date(raw);

        if (!Number.isNaN(parsed.getTime())) {
            const hasTime = /\d{1,2}:\d{2}|t\d{2}:\d{2}/i.test(raw);
            const options = {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
            };

            if (hasTime) {
                Object.assign(options, {
                    hour: 'numeric',
                    minute: '2-digit',
                });
            }

            return new Intl.DateTimeFormat('en-PH', options).format(parsed);
        }

        return raw;
    };

    const parseItemsJson = (row) => {
        const raw = (row.dataset.orderItemsJson || '').trim();
        if (!raw) return [];

        try {
            const value = JSON.parse(raw);
            return Array.isArray(value) ? value : [];
        } catch (_) {
            return [];
        }
    };

    const parseStatusHistory = (row) => {
        const raw = (row.dataset.orderStatusHistory || '').trim();
        if (!raw) return [];

        try {
            const value = JSON.parse(raw);
            return Array.isArray(value) ? value : [];
        } catch (_) {
            return [];
        }
    };

    const itemImage = (item) =>
        item.image || item.product_image || item.image_url || item.thumbnail || '';

    const itemName = (item) =>
        item.name || item.product_name || item.title || 'Order item';

    const itemVariant = (item) =>
        item.variant || item.variation || item.option || '';

    const itemSku = (item) =>
        item.sku || item.product_sku || '';

    const itemQty = (item) =>
        Number(item.qty ?? item.quantity ?? 1) || 1;

    const itemPriceText = (item) => {
        if (Number.isInteger(item.unit_price_minor)) {
            return formatItemMoney(item.unit_price_minor);
        }
        const value = item.unit_price ?? item.price ?? item.amount ?? null;
        if (value === null || value === '') return '';

        if (typeof value === 'number' || /^\d+(\.\d+)?$/.test(String(value))) {
            return new Intl.NumberFormat('en-PH', {
                style: 'currency',
                currency: 'PHP',
                maximumFractionDigits: 2,
            }).format(Number(value));
        }

        return String(value);
    };

    const formatItemMoney = (minor) => new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        maximumFractionDigits: 2,
    }).format(minor / 100);

    const renderItems = (row) => {
        items.replaceChildren();

        const databaseItems = parseItemsJson(row);

        if (databaseItems.length) {
            databaseItems.forEach((item) => {
                const nameText = itemName(item);
                const article = document.createElement('article');
                article.className = 'bearly-action-item';

                const thumb = document.createElement('span');
                thumb.className = 'bearly-action-thumb';

                const image = itemImage(item);

                if (image) {
                    const img = document.createElement('img');
                    img.src = image;
                    img.alt = nameText;
                    img.loading = 'lazy';

                    img.addEventListener(
                        'error',
                        () => {
                            thumb.replaceChildren();
                            thumb.innerHTML = `<i data-lucide="${productIcon(nameText)}" aria-hidden="true"></i>`;
                            window.lucide?.createIcons();
                        },
                        { once: true },
                    );

                    thumb.append(img);
                } else {
                    thumb.innerHTML = `<i data-lucide="${productIcon(nameText)}" aria-hidden="true"></i>`;
                }

                const copy = document.createElement('div');
                copy.className = 'bearly-action-item-copy';

                const name = document.createElement('strong');
                name.textContent = nameText;

                const meta = document.createElement('small');
                const skuText = itemSku(item);
                meta.textContent = skuText ? `SKU: ${skuText}` : 'Order item';

                copy.append(name, meta);
                const variant = document.createElement('small');
                variant.textContent = `Variant: ${itemVariant(item) || 'Standard'}`;
                const quantity = document.createElement('small');
                quantity.textContent = `Quantity: ${itemQty(item)}`;
                copy.append(variant, quantity);

                const side = document.createElement('span');
                side.className = 'bearly-action-item-side';

                const price = itemPriceText(item);
                if (price) {
                    const unitPrice = document.createElement('small');
                    unitPrice.textContent = `Unit price: ${price}`;
                    side.append(unitPrice);
                }
                const subtotal = document.createElement('strong');
                subtotal.textContent = Number.isInteger(item.subtotal_minor)
                    ? `Subtotal: ${formatItemMoney(item.subtotal_minor)}`
                    : 'Subtotal unavailable';
                side.append(subtotal);

                article.append(thumb, copy, side);
                items.append(article);
            });

            const totalItems = databaseItems.reduce(
                (sum, item) => sum + itemQty(item),
                0,
            );

            itemCount.textContent = `(${totalItems} ${totalItems === 1 ? 'item' : 'items'})`;
            return;
        }

        const raw = (row.dataset.orderItems || '').trim();
        const parts = raw
            ? raw.split(/\s*\+\s*/).filter(Boolean)
            : ['Order item'];

        const sku = (row.dataset.orderSku || '').trim();
        const image = (row.dataset.orderProductImage || '').trim();

        parts.forEach((part, index) => {
            const article = document.createElement('article');
            article.className = 'bearly-action-item';

            const thumb = document.createElement('span');
            thumb.className = 'bearly-action-thumb';

            if (image && index === 0) {
                const img = document.createElement('img');
                img.src = image;
                img.alt = part.trim();
                img.loading = 'lazy';

                img.addEventListener(
                    'error',
                    () => {
                        thumb.replaceChildren();
                        thumb.innerHTML = `<i data-lucide="${productIcon(part)}" aria-hidden="true"></i>`;
                        window.lucide?.createIcons();
                    },
                    { once: true },
                );

                thumb.append(img);
            } else {
                thumb.innerHTML = `<i data-lucide="${productIcon(part)}" aria-hidden="true"></i>`;
            }

            const copy = document.createElement('div');
            copy.className = 'bearly-action-item-copy';

            const name = document.createElement('strong');
            name.textContent = part.trim();

            const meta = document.createElement('small');
            meta.textContent = sku && index === 0 ? `SKU: ${sku}` : 'Order item';

            copy.append(name, meta);

            const side = document.createElement('span');
            side.className = 'bearly-action-item-side';
            side.textContent =
                parts.length === 1
                    ? row.dataset.orderTotal || ''
                    : `Item ${index + 1}`;

            article.append(thumb, copy, side);
            items.append(article);
        });

        itemCount.textContent = `(${row.dataset.orderItemCount || parts.length})`;
    };

    const notice = (iconName, text) =>
        `<section class="bearly-action-notice"><span><i data-lucide="${iconName}"></i></span><p>${text}</p></section>`;

    const renderContext = (row, config) => {
        context.innerHTML = '';

        if (config.type === 'review') {
            context.innerHTML = `${notice(
                'info',
                'Confirm only after checking that your Store can fulfill every item and quantity in this order.',
            )}<button class="bearly-action-cancel-start" type="button" data-bearly-cancel-start>Cancel this order instead</button>`;
        } else if (config.type === 'prepare') {
            context.innerHTML = `
                <section class="bearly-action-next">
                    <span><i data-lucide="package-check"></i></span>
                    <div>
                        <small>Before packing</small>
                        <strong>Verify the item, variation, quantity, and condition against the order.</strong>
                    </div>
                </section>
            `;
        } else if (config.type === 'packing') {
            context.innerHTML = `
                <section class="bearly-action-check-card">
                    <div class="bearly-action-check-head">
                        <strong>Packing Checklist</strong>
                        <span data-bearly-check-count>0/3 Completed</span>
                    </div>
                    <label class="bearly-action-check">
                        <input type="checkbox" data-bearly-pack-check>
                        <span>Correct item and variation are packed</span>
                    </label>
                    <label class="bearly-action-check">
                        <input type="checkbox" data-bearly-pack-check>
                        <span>Order quantity is complete</span>
                    </label>
                    <label class="bearly-action-check">
                        <input type="checkbox" data-bearly-pack-check>
                        <span>Parcel is secure and in good condition</span>
                    </label>
                    <p class="bearly-action-check-note">Waybill creation and logistics pickup are handled separately and are not enabled here.</p>
                </section>
            `;

            const checks = [...context.querySelectorAll('[data-bearly-pack-check]')];
            const counter = context.querySelector('[data-bearly-check-count]');

            primary.disabled = true;

            checks.forEach((check) => {
                check.addEventListener('change', () => {
                    const done = checks.filter((item) => item.checked).length;
                    counter.textContent = `${done}/3 Completed`;
                    primary.disabled = done !== checks.length;
                });
            });
        } else {
            const current = (row.dataset.orderCanonical || '').toUpperCase();

            let timelineFlow = flow;

            if (current === 'CANCELLED') {
                timelineFlow = [
                    ['PLACED', 'Placed'],
                    ['CANCELLED', 'Cancelled'],
                ];
            }

        if (current === 'RETURNED') {
                timelineFlow = [...flow.slice(0, 10), ['RETURNED', 'Returned']];
            }

            if (current === 'DELIVERY_FAILED') {
                timelineFlow = [
                    ...flow.slice(0, 9),
                    ['DELIVERY_FAILED', 'Delivery Failed'],
                ];
            }

            let currentIndex = timelineFlow.findIndex(([key]) => key === current);
            if (currentIndex < 0) currentIndex = 0;

            const wrap = document.createElement('section');
            wrap.className = 'bearly-action-status-layout';

            const left = document.createElement('div');
            const headingRow = document.createElement('div');
            headingRow.className = 'bearly-action-status-heading';

            const heading = document.createElement('h4');
            heading.className = 'bearly-action-status-title';
            heading.textContent = 'Current Order Status';

            const liveStatus = document.createElement('p');
            liveStatus.className = 'bearly-action-live-status';
            liveStatus.dataset.bearlyLiveStatus = '';
            liveStatus.setAttribute('role', 'status');
            liveStatus.setAttribute('aria-live', 'polite');
            liveStatus.textContent = 'Live updates every 15 seconds while this dialog is open.';

            headingRow.append(heading, liveStatus);
            left.append(headingRow);

            const summary = document.createElement('div');
            summary.className = 'bearly-action-next';
            summary.innerHTML =
                '<span><i data-lucide="info"></i></span><div><small>Next update</small><strong></strong></div>';

            summary.querySelector('small').textContent = `Next update · ${row.dataset.orderOwner || 'Seller'}`;

            summary.querySelector('strong').textContent =
                current === 'CANCELLED'
                    ? 'This order is cancelled. No further seller fulfillment action is required.'
                    : row.dataset.orderNext ||
                      'No seller action is required right now.';

            left.append(summary);

            const timeline = document.createElement('ol');
            timeline.className = 'bearly-action-timeline';

            timelineFlow.forEach(([key, label], index) => {
                const item = document.createElement('li');

                item.className =
                    index < currentIndex
                        ? 'is-done'
                        : index === currentIndex
                          ? 'is-current'
                          : '';

                const marker = document.createElement('span');
                marker.className = 'bearly-action-timeline-marker';

                if (index <= currentIndex) {
                    marker.innerHTML = '<i data-lucide="check"></i>';
                }

                const copy = document.createElement('div');
                copy.className = 'bearly-action-timeline-copy';

                const strong = document.createElement('strong');
                strong.textContent = label;
                copy.append(strong);

                if (index === currentIndex) {
                    const small = document.createElement('small');
                    small.textContent = 'Current status';
                    copy.append(small);
                }

                item.append(marker, copy);
                timeline.append(item);
            });

            const history = parseStatusHistory(row);
            const historySection = document.createElement('section');
            historySection.className = 'bearly-action-history';
            const historyHeading = document.createElement('h4');
            historyHeading.textContent = 'Recent Order & Tracking Activity';
            historySection.append(historyHeading);

            if (history.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'bearly-action-history-empty';
                empty.textContent = 'No order or shipment updates have been recorded yet.';
                historySection.append(empty);
            } else {
                const list = document.createElement('ol');
                history.forEach((entry) => {
                    const item = document.createElement('li');
                    const heading = document.createElement('strong');
                    const statusKey = String(entry.status || '').toUpperCase();
                    heading.textContent = entry.label || statusLabels[statusKey] || statusKey.replaceAll('_', ' ') || 'Status updated';
                    const meta = document.createElement('small');
                    const source = entry.source && entry.source !== entry.actor ? entry.source : '';
                    meta.textContent = [entry.actor || source || 'System', source, formatOrderDate(entry.occurred_at)].filter(Boolean).join(' · ');
                    item.append(heading, meta);
                    if (entry.reason) {
                        const reason = document.createElement('p');
                        reason.textContent = entry.reason;
                        item.append(reason);
                    }
                    list.append(item);
                });
                historySection.append(list);
            }

            left.append(historySection);
            wrap.append(left, timeline);
            context.append(wrap);
        }
    };

    const trackingSignature = (snapshot) => JSON.stringify({
        seller_order_status: snapshot.seller_order_status,
        status_label: snapshot.status_label,
        status_detail: snapshot.status_detail,
        canonical_status: snapshot.canonical_status,
        status_key: snapshot.status_key,
        next_step: snapshot.next_step,
        timeline_history: snapshot.timeline_history || [],
    });

    const applyTrackingSnapshot = (row, snapshot) => {
        const previousStatusKey = row.dataset.status;
        const statusLabel = String(snapshot.status_label || 'Order status unavailable');
        const statusDetail = String(snapshot.status_detail || '');

        row.dataset.status = String(snapshot.status_key || 'all');
        row.dataset.orderStatusCopy = statusLabel;
        row.dataset.orderCanonical = String(snapshot.canonical_status || '');
        row.dataset.orderOwner = String(snapshot.responsibility || '');
        row.dataset.orderNext = String(snapshot.next_step || '');
        if (Array.isArray(snapshot.timeline_history)) {
            row.dataset.orderStatusHistory = JSON.stringify(snapshot.timeline_history);
        }
        row.dataset.orderSellerStatus = String(snapshot.seller_order_status || '');
        row.dataset.search = `${row.dataset.orderId || ''} ${row.dataset.orderCustomer || ''} ${snapshot.canonical_status || ''} ${statusLabel}`.toLowerCase();

        const statusBadge = row.querySelector('[data-order-status-badge]');
        if (statusBadge) {
            statusBadge.className = `order-status-badge order-status-${String(snapshot.tone || 'new')}`;
            statusBadge.setAttribute('aria-label', [statusLabel, statusDetail].filter(Boolean).join('. '));

            let labelNode = statusBadge.querySelector('[data-order-status-label]');
            if (!labelNode) {
                labelNode = document.createElement('span');
                labelNode.dataset.orderStatusLabel = '';
                statusBadge.replaceChildren(labelNode);
            }
            labelNode.textContent = statusLabel;

            let detailNode = statusBadge.querySelector('[data-order-status-detail]');
            if (statusDetail && !detailNode) {
                detailNode = document.createElement('small');
                detailNode.dataset.orderStatusDetail = '';
                statusBadge.append(detailNode);
            } else if (!statusDetail && detailNode) {
                detailNode.remove();
            }
            if (detailNode) detailNode.textContent = statusDetail;
        }

        if (previousStatusKey !== row.dataset.status) {
            row.dispatchEvent(new CustomEvent('seller:order-tracking-updated', { bubbles: true }));
        }

        return trackingSignature(snapshot);
    };

    let orderStatusSyncInFlight = false;
    let orderStatusSyncQueued = false;
    const refreshOrderStatuses = async () => {
        const endpoint = workspace.dataset.orderStatusesUrl;
        if (!endpoint || document.hidden) return;
        if (orderStatusSyncInFlight) {
            orderStatusSyncQueued = true;
            return;
        }

        orderStatusSyncQueued = false;
        orderStatusSyncInFlight = true;
        try {
            const response = await fetch(endpoint, {
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const result = await response.json().catch(() => ({}));
            const snapshots = result?.data?.orders;
            if (!response.ok || !Array.isArray(snapshots)) return;

            const rowsById = new Map(
                [...workspace.querySelectorAll('[data-order-row]')]
                    .map((row) => [Number(row.dataset.sellerOrderId), row]),
            );

            snapshots.forEach((snapshot) => {
                const row = rowsById.get(Number(snapshot.seller_order_id));
                if (!row) return;

                applyTrackingSnapshot(row, snapshot);
                if (active?.row === row) refreshActiveTracking();
            });
        } catch {
            // The short polling fallback retries if the realtime connection is unavailable.
        } finally {
            orderStatusSyncInFlight = false;
            if (orderStatusSyncQueued && !document.hidden) {
                orderStatusSyncQueued = false;
                refreshOrderStatuses();
            }
        }
    };

    const sellerOrderNotificationEvents = new Set([
        'seller_order_updates',
        'seller_order_cancelled',
        'seller_pickup_updates',
        'seller_shipment_updates',
    ]);
    const refreshAfterOrderNotification = (event) => {
        const notification = event.detail || {};
        if (!sellerOrderNotificationEvents.has(String(notification.event || ''))) return;

        const sellerOrderId = Number(notification.meta?.seller_order_id || 0);
        if (sellerOrderId > 0 && !workspace.querySelector(`[data-seller-order-id="${sellerOrderId}"]`)) return;

        refreshOrderStatuses();
    };

    window.addEventListener('bearly:notification-created', refreshAfterOrderNotification);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshOrderStatuses();
    });
    refreshOrderStatuses();
    window.setInterval(refreshOrderStatuses, 15000);

    const setLiveTrackingMessage = (message) => {
        const note = context.querySelector('[data-bearly-live-status]');
        if (note) note.textContent = message;
    };

    const refreshActiveTracking = async () => {
        if (!active || modal.hidden || document.hidden || trackingRequestInFlight) return;

        const row = active.row;
        const endpoint = row.dataset.orderTrackingUrl;
        if (!endpoint) return;

        trackingRequestInFlight = true;
        const controller = new AbortController();
        trackingController = controller;

        try {
            const response = await fetch(endpoint, {
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: controller.signal,
            });
            const result = await response.json().catch(() => ({}));

            if (!response.ok || !result.data || active?.row !== row || modal.hidden) {
                if (!response.ok) throw new Error(result.message || 'Tracking refresh unavailable.');
                return;
            }

            const signature = applyTrackingSnapshot(row, result.data);
            if (signature !== lastTrackingSignature) {
                lastTrackingSignature = signature;
                const config = configFor(row, active.mode);
                badge.textContent = result.data.status_label || 'Order';
                // Action modes own interactive state (packing checklist,
                // cancel reason). Re-render only the read-only details view
                // so live updates never wipe in-progress seller input.
                if (config.type === 'details') {
                    renderContext(row, config);
                    window.lucide?.createIcons();
                }
            }

            setLiveTrackingMessage(`Live · checked ${formatOrderDate(new Date())}`);

            if (['completed', 'cancelled'].includes(result.data.seller_order_status)) {
                window.clearInterval(trackingTimer);
                trackingTimer = null;
            }
        } catch (error) {
            if (error.name !== 'AbortError' && active?.row === row && !modal.hidden) {
                setLiveTrackingMessage('Live refresh unavailable · showing the last saved update.');
            }
        } finally {
            if (trackingController === controller) {
                trackingRequestInFlight = false;
                trackingController = null;
            }
        }
    };

    const beginTrackingUpdates = () => {
        window.clearInterval(trackingTimer);
        trackingController?.abort();
        trackingRequestInFlight = false;
        lastTrackingSignature = '';
        if (!active?.row.dataset.orderTrackingUrl) return;
        refreshActiveTracking();
        trackingTimer = window.setInterval(refreshActiveTracking, 15000);
    };

    const stopTrackingUpdates = () => {
        window.clearInterval(trackingTimer);
        trackingTimer = null;
        trackingController?.abort();
        trackingController = null;
        trackingRequestInFlight = false;
        lastTrackingSignature = '';
    };

    const open = (row, mode, trigger) => {
        active = { row, mode, trigger };
        lastFocus = trigger;

        const config = configFor(row, mode);

        transitionForm.setAttribute('action', row.dataset.orderTransitionUrl || '');
        actionField.value = config.action || '';
        actionError.hidden = true;
        actionError.textContent = '';
        cancelControls.hidden = true;
        cancelReason.value = '';
        cancelReason.required = false;
        cancelReason.disabled = true;
        cancelBack.hidden = true;
        primary.classList.remove('is-danger');

        title.textContent = config.title;
        subtitle.textContent = config.subtitle;
        icon.innerHTML = `<i data-lucide="${config.icon}"></i>`;

        const canonical = (row.dataset.orderCanonical || '').toUpperCase();

        badge.textContent =
            row.dataset.orderStatusCopy ||
            statusLabels[canonical] ||
            'Order';

        orderId.textContent =
            row.dataset.orderId ||
            trigger.dataset.orderDetails ||
            '—';

        customer.textContent =
            row.dataset.orderCustomer || '—';

        orderDate.textContent =
            formatOrderDate(row.dataset.orderDateCopy || row.dataset.date);

        payment.textContent = paymentText(row);
        total.textContent = row.dataset.orderTotal || '—';

        const shippingAddress = (row.dataset.orderAddress || '').trim();

        if (['review', 'prepare', 'packing'].includes(config.type)) {
            addressLabel.textContent = 'Order Status';
            address.textContent =
                row.dataset.orderStatusCopy ||
                statusLabels[canonical] ||
                '—';
        } else {
            addressLabel.textContent = 'Shipping Address';
            address.textContent =
                shippingAddress || 'Not available in preview';
        }

        addressCell.hidden = false;

        renderItems(row);

        primary.disabled = false;
        primary.hidden = !config.button;
        primary.textContent = config.button || '';

        footer.classList.toggle('is-read-only', !config.button);

        renderContext(row, config);

        modal.hidden = false;
        document.body.classList.add('modal-open');

        window.lucide?.createIcons();

        (config.button ? primary : q('.bearly-action-x'))?.focus();
        beginTrackingUpdates();
    };

    const close = () => {
        stopTrackingUpdates();
        modal.hidden = true;
        document.body.classList.remove('modal-open');
        lastFocus?.focus?.();
        active = null;
    };

    document.addEventListener(
        'click',
        (event) => {
            const trigger = event.target.closest('[data-bearly-action-open]');
            if (!trigger) return;

            const row = trigger.closest('[data-order-row]');
            if (!row) return;

            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();

            open(
                row,
                trigger.dataset.bearlyActionMode || 'action',
                trigger,
            );
        },
        true,
    );

    modal
        .querySelectorAll('[data-bearly-action-close]')
        .forEach((button) => button.addEventListener('click', close));

    context.addEventListener('click', (event) => {
        const cancelStart = event.target.closest('[data-bearly-cancel-start]');
        if (!cancelStart || !active) return;

        cancelControls.hidden = false;
        cancelReason.disabled = false;
        cancelReason.required = true;
        cancelStart.hidden = true;
        cancelBack.hidden = false;
        actionField.value = 'cancel';
        primary.textContent = 'Cancel Seller Order';
        primary.classList.add('is-danger');
        cancelReason.focus();
    });

    cancelBack.addEventListener('click', () => {
        if (!active) return;

        const config = configFor(active.row, active.mode);
        cancelControls.hidden = true;
        cancelReason.value = '';
        cancelReason.required = false;
        cancelReason.disabled = true;
        const cancelStart = context.querySelector('[data-bearly-cancel-start]');
        if (cancelStart) cancelStart.hidden = false;
        cancelBack.hidden = true;
        actionField.value = config.action || '';
        primary.textContent = config.button || '';
        primary.classList.remove('is-danger');
        actionError.hidden = true;
        actionError.textContent = '';
    });

    transitionForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!active || !transitionForm.reportValidity()) return;

        const submitAction = actionField.value;
        const submitLabel = primary.textContent;
        primary.disabled = true;
        primary.textContent = 'Saving…';
        actionError.hidden = true;
        actionError.textContent = '';

        try {
            const endpoint = transitionForm.getAttribute('action');
            if (!endpoint) throw new Error('The order action endpoint is missing. Refresh and try again.');

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new FormData(transitionForm),
            });
            const result = await response.json().catch(() => ({}));

            if (!response.ok) {
                const validationMessage = Object.values(result.errors || {}).flat()[0];
                throw new Error(validationMessage || result.message || 'Unable to update this order. Refresh and try again.');
            }

            window.location.assign(result.data?.redirect_to || window.location.href);
        } catch (error) {
            actionError.textContent = error.message || 'Unable to update this order. Refresh and try again.';
            actionError.hidden = false;
            primary.disabled = false;
            primary.textContent = submitLabel;
            if (submitAction === 'cancel') cancelReason.focus();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) close();
    });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refreshActiveTracking();
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupBearlyOrderActionModal);
} else {
    setupBearlyOrderActionModal();
}

/* =========================================================
   ACCESSIBLE CHART TOOLTIPS
   Server-rendered chart points and bars carry their real period
   label and amount in data attributes. Hover, keyboard focus, and
   touch reveal the same tooltip; amounts are never derived from
   pixel positions.
   ========================================================= */

const setupChartTooltips = () => {
    document.querySelectorAll('[data-chart]').forEach((chart) => {
        const points = [...chart.querySelectorAll('[data-chart-point]')];

        if (!points.length) return;

        const tooltip = document.createElement('div');
        tooltip.className = 'chart-tooltip';
        tooltip.hidden = true;
        tooltip.innerHTML = '<strong></strong><span></span>';
        chart.appendChild(tooltip);

        const valueNode = tooltip.querySelector('strong');
        const labelNode = tooltip.querySelector('span');
        const crosshair = chart.querySelector('[data-chart-crosshair]');
        const nearestMode = chart.dataset.chartHover === 'nearest';
        let activePoint = null;
        let shownPoint = null;

        // Active state for the revealed data point and its guide line.
        const activate = (point) => {
            if (activePoint === point) return;

            if (activePoint) activePoint.classList.remove('is-active');

            activePoint = point;

            if (point) point.classList.add('is-active');

            if (crosshair) {
                const x = point ? point.getAttribute('cx') : null;

                if (x) {
                    crosshair.setAttribute('x1', x);
                    crosshair.setAttribute('x2', x);
                    crosshair.classList.add('is-visible');
                } else {
                    crosshair.classList.remove('is-visible');
                }
            }
        };

        const position = (point) => {
            const anchor = (point.querySelector('[data-chart-bar]') || point).getBoundingClientRect();
            const root = chart.getBoundingClientRect();
            const width = tooltip.offsetWidth;
            const height = tooltip.offsetHeight;
            const center = anchor.left - root.left + anchor.width / 2;
            const left = Math.min(Math.max(center - width / 2, 4), Math.max(root.width - width - 4, 4));
            let top = anchor.top - root.top - height - 8;

            if (top < 4) {
                top = Math.min(anchor.bottom - root.top + 8, Math.max(root.height - height - 4, 4));
            }

            tooltip.style.left = `${left}px`;
            tooltip.style.top = `${Math.max(4, top)}px`;
        };

        const show = (point) => {
            if (point === shownPoint) return;

            shownPoint = point;
            activate(point);
            valueNode.textContent = point.dataset.value || '';
            labelNode.textContent = point.dataset.label || '';
            tooltip.hidden = false;
            position(point);
        };

        const hide = () => {
            shownPoint = null;
            activate(null);
            tooltip.hidden = true;
        };

        const focusPoint = (index) => {
            const point = points[(index + points.length) % points.length];
            points.forEach((item) => { item.tabIndex = item === point ? 0 : -1; });
            point.focus();
            show(point);
        };

        const pointFrom = (event) => (event.target instanceof Element ? event.target.closest('[data-chart-point]') : null);

        // Charts flagged with `data-chart-hover="nearest"` use the whole plot
        // as the hover target: the point closest on the x-axis is activated,
        // so small data points stay easy to reach with a mouse.
        const nearestPoint = (clientX) => {
            let best = null;

            points.forEach((point) => {
                const bounds = point.getBoundingClientRect();
                const distance = Math.abs(clientX - (bounds.left + bounds.width / 2));

                if (!best || distance < best.distance) best = { point, distance };
            });

            return best ? best.point : null;
        };

        if (nearestMode) {
            const hoverRoot = chart.querySelector('svg') || chart;

            hoverRoot.addEventListener('pointermove', (event) => {
                const point = nearestPoint(event.clientX);

                if (point) show(point);
            });
            hoverRoot.addEventListener('pointerleave', (event) => {
                if (event.pointerType !== 'touch') hide();
            });
        } else {
            chart.addEventListener('pointerover', (event) => {
                const point = pointFrom(event);

                if (point) show(point);
            });
            chart.addEventListener('pointerout', (event) => {
                const point = pointFrom(event);

                if (!point || event.pointerType === 'touch') return;

                const next = event.relatedTarget;

                if (!(next instanceof Element) || !point.contains(next)) hide();
            });
        }

        chart.addEventListener('focusin', (event) => {
            const point = pointFrom(event);

            if (point) show(point);
        });
        chart.addEventListener('focusout', () => hide());
        chart.addEventListener('pointerdown', (event) => {
            if (event.pointerType !== 'touch') return;

            const point = nearestMode ? nearestPoint(event.clientX) : pointFrom(event);

            if (point) show(point);
        });
        chart.addEventListener('keydown', (event) => {
            const point = pointFrom(event);

            if (!point) return;

            const index = points.indexOf(point);

            if (index < 0) return;

            if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                event.preventDefault();
                focusPoint(index + 1);
            } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                event.preventDefault();
                focusPoint(index - 1);
            } else if (event.key === 'Home') {
                event.preventDefault();
                focusPoint(0);
            } else if (event.key === 'End') {
                event.preventDefault();
                focusPoint(points.length - 1);
            } else if (event.key === 'Escape') {
                hide();
                point.blur();
            }
        });

        // Touch: tapping anywhere outside this chart dismisses the tooltip.
        document.addEventListener('pointerdown', (event) => {
            if (event.pointerType !== 'touch') return;

            const target = event.target instanceof Element ? event.target.closest('[data-chart]') : null;

            if (target !== chart) hide();
        });
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setupChartTooltips);
} else {
    setupChartTooltips();
}
