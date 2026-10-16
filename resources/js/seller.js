const bootSeller = () => {
    window.lucide?.createIcons();

    const productFilterForm = document.querySelector('[data-product-filter-form]');
    productFilterForm?.querySelectorAll('[data-product-filter-auto-submit]').forEach((control) => {
        control.addEventListener('change', () => productFilterForm.requestSubmit());
    });

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
        const orderCheckAll = orderWorkspace.querySelector('[data-order-check-all]');
        const selectedCount = orderWorkspace.querySelector('[data-order-selected-count]');
        const visibleCount = orderWorkspace.querySelector('[data-order-visible-count]');
        const noResults = orderWorkspace.querySelector('[data-orders-no-results]');
        const bulkButtons = [...orderWorkspace.querySelectorAll('[data-order-bulk-action]')];

        const refreshOrderStatusCounts = () => {
            orderWorkspace.querySelectorAll('[data-order-tab]').forEach((tab) => {
                const count = tab.dataset.orderTab === 'all'
                    ? orderRows.length
                    : orderRows.filter((row) => row.dataset.status === tab.dataset.orderTab).length;
                const countNode = tab.querySelector('span');
                if (countNode) countNode.textContent = String(count);
            });

            orderWorkspace.querySelectorAll('[data-order-queue-card]').forEach((card) => {
                const countNode = card.querySelector('strong');
                if (!countNode) return;
                countNode.textContent = String(orderRows.filter((row) => row.dataset.status === card.dataset.orderQueueCard).length);
            });
        };

        const updateOrderSelection = () => {
            const visibleChecks = orderRows
                .filter((row) => !row.hidden)
                .map((row) => row.querySelector('[data-order-check]'));
            const checked = orderRows.filter((row) => row.querySelector('[data-order-check]')?.checked).length;

            if (selectedCount) selectedCount.textContent = checked;
            bulkButtons.forEach((button) => { button.disabled = checked === 0; });

            if (orderCheckAll) {
                orderCheckAll.checked = visibleChecks.length > 0 && visibleChecks.every((check) => check?.checked);
                orderCheckAll.indeterminate = visibleChecks.some((check) => check?.checked) && !orderCheckAll.checked;
            }
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
            updateOrderSelection();
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

        orderCheckAll?.addEventListener('change', () => {
            orderRows.filter((row) => !row.hidden).forEach((row) => {
                const checkbox = row.querySelector('[data-order-check]');
                if (checkbox) checkbox.checked = orderCheckAll.checked;
            });
            updateOrderSelection();
        });

        orderRows.forEach((row) => row.querySelector('[data-order-check]')?.addEventListener('change', updateOrderSelection));

        bulkButtons.forEach((button) => button.addEventListener('click', () => {
            const count = Number(selectedCount?.textContent ?? 0);
            if (!toast || count === 0) return;
            toast.textContent = `${button.dataset.orderBulkAction} is ready for ${count} selected order${count === 1 ? '' : 's'} (preview only).`;
            toast.classList.add('is-visible');
            window.clearTimeout(window.sellerToastTimer);
            window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3000);
        }));

        applyOrderFilters();
    }

    document.querySelectorAll('[data-order-demo]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!toast) return;
            toast.textContent = `${button.dataset.orderDemo} Frontend preview only.`;
            toast.classList.add('is-visible');
            window.clearTimeout(window.sellerToastTimer);
            window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3000);
        });
    });

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
        let activeInventoryStatus = 'all';
        const inventoryRows = [...inventoryWorkspace.querySelectorAll('[data-inventory-row]')];
        const inventorySearch = inventoryWorkspace.querySelector('[data-inventory-search]');
        const inventorySubcategory = inventoryWorkspace.querySelector('[data-inventory-subcategory]');
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
            const subcategory = inventorySubcategory?.value ?? '';
            const variationsVisible = showVariations?.checked ?? true;
            let visible = 0;

            inventoryRows.forEach((row) => {
                const matchesStatus = activeInventoryStatus === 'all'
                    ? row.dataset.status !== 'archived'
                    : activeInventoryStatus === 'needs-restocking'
                        ? ['low-stock', 'out-of-stock'].includes(row.dataset.status)
                        : row.dataset.status === activeInventoryStatus;
                const matches = matchesStatus
                    && (!search || row.dataset.search.includes(search))
                    && (!subcategory || row.dataset.subcategory === subcategory)
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
        inventorySubcategory?.addEventListener('change', applyInventoryFilters);
        showVariations?.addEventListener('change', applyInventoryFilters);

        inventoryWorkspace.querySelector('[data-inventory-reset]')?.addEventListener('click', () => {
            if (inventorySearch) inventorySearch.value = '';
            if (inventorySubcategory) inventorySubcategory.value = '';
            if (showVariations) showVariations.checked = true;
            activeInventoryStatus = 'all';
            clearInventorySelection();

            inventoryWorkspace.querySelectorAll('[data-inventory-tab]').forEach((tab) => {
                const active = tab.dataset.inventoryTab === 'all';
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', String(active));
            });

            applyInventoryFilters();
        });

        document.querySelector('[data-inventory-alerts]')?.addEventListener('click', () => {
            if (inventorySearch) inventorySearch.value = '';
            if (inventorySubcategory) inventorySubcategory.value = '';
            if (showVariations) showVariations.checked = true;
            clearInventorySelection();

            inventoryWorkspace.querySelector('[data-inventory-tab="needs-restocking"]')?.click();
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

    /* Seller account page — frontend-only edit and photo preview. */
    const accountForm = document.querySelector('[data-account-form]');
    const accountEdit = document.querySelector('[data-account-edit]');
    const accountCancel = document.querySelector('[data-account-cancel]');
    const accountActions = document.querySelector('[data-account-actions]');
    const accountEditState = document.querySelector('[data-account-edit-state]');
    const accountEditableFields = [...document.querySelectorAll('[data-account-editable]')];
    const originalAccountValues = new Map(accountEditableFields.map((field) => [field, field.value]));

    const setAccountEditing = (editing) => {
        accountEditableFields.forEach((field) => { field.disabled = !editing; });
        if (accountActions) accountActions.hidden = !editing;
        if (accountEdit) {
            accountEdit.disabled = editing;
            accountEdit.querySelector('span').textContent = editing ? 'Editing profile' : 'Edit profile';
        }
        if (accountEditState) accountEditState.textContent = editing ? 'Editing' : 'View only';
        if (editing) accountEditableFields[0]?.focus();
    };

    accountEdit?.addEventListener('click', () => setAccountEditing(true));
    accountCancel?.addEventListener('click', () => {
        originalAccountValues.forEach((value, field) => { field.value = value; });
        setAccountEditing(false);
    });
    accountForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        accountEditableFields.forEach((field) => originalAccountValues.set(field, field.value));
        setAccountEditing(false);
        if (!toast) return;
        toast.textContent = 'Profile preview updated. Changes will reset after refreshing the page.';
        toast.classList.add('is-visible');
        window.clearTimeout(window.sellerToastTimer);
        window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3400);
    });

    document.querySelector('[data-account-photo]')?.addEventListener('change', (event) => {
        const file = event.currentTarget.files?.[0];
        const avatar = document.querySelector('[data-account-avatar]');
        if (!file || !avatar) return;
        const image = document.createElement('img');
        image.alt = 'Seller profile preview';
        image.src = URL.createObjectURL(file);
        image.addEventListener('load', () => URL.revokeObjectURL(image.src), { once: true });
        avatar.replaceChildren(image);
    });

    document.querySelector('[data-password-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const password = form.querySelector('[data-new-password]');
        const confirmation = form.querySelector('[data-confirm-password]');
        if (password?.value !== confirmation?.value) {
            confirmation?.setCustomValidity('Passwords do not match.');
            confirmation?.reportValidity();
            return;
        }
        confirmation?.setCustomValidity('');
        form.reset();
        if (!toast) return;
        toast.textContent = 'Password update validated. Backend saving will be connected later.';
        toast.classList.add('is-visible');
        window.clearTimeout(window.sellerToastTimer);
        window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3400);
    });

    document.querySelector('[data-confirm-password]')?.addEventListener('input', (event) => event.currentTarget.setCustomValidity(''));
    document.querySelector('[data-two-factor-toggle]')?.addEventListener('change', (event) => {
        const label = document.querySelector('[data-two-factor-label]');
        if (label) label.textContent = event.currentTarget.checked ? 'Enabled (preview)' : 'Not enabled';
    });
    document.querySelectorAll('[data-security-demo]').forEach((button) => button.addEventListener('click', () => {
        if (!toast) return;
        toast.textContent = `${button.dataset.securityDemo} Frontend preview only.`;
        toast.classList.add('is-visible');
        window.clearTimeout(window.sellerToastTimer);
        window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3000);
    }));

    document.querySelector('[data-save-notifications]')?.addEventListener('click', () => {
        if (!toast) return;
        toast.textContent = 'Notification preferences updated for this preview. They will reset after refresh.';
        toast.classList.add('is-visible');
        window.clearTimeout(window.sellerToastTimer);
        window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3400);
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
        set('[data-pickup-id]', item.id); set('[data-pickup-status]', item.status); set('[data-pickup-orders]', `${item.orders} (${item.order_numbers || 'Order details unavailable'}) · ${item.packages} ${item.packages === 1 ? 'parcel' : 'parcels'}`); set('[data-pickup-provider]', item.provider); set('[data-pickup-schedule]', item.schedule); set('[data-pickup-rider]', item.rider); set('[data-pickup-address]', item.address); set('[data-pickup-instructions]', item.instructions);
        modal.hidden = false; document.body.style.overflow = 'hidden';
    }));

    document.querySelector('[data-tracking-attention]')?.addEventListener('click', () => {
        const search = document.querySelector('[data-tracking-search]');
        if (search) { search.value = ''; search.dispatchEvent(new Event('input')); }
        const tab = document.querySelector('[data-tracking-tab="failed"]');
        tab?.click();
        tab?.focus();
    });

    document.querySelectorAll('[data-tracking-view]').forEach((button) => button.addEventListener('click', () => {
        const item = JSON.parse(button.dataset.shipment); const modal = document.querySelector('[data-modal="tracking-details"]'); if (!modal) return;
        const set = (selector, value) => { const node = modal.querySelector(selector); if (node) node.textContent = value; };
        set('[data-tracking-number]', item.tracking); set('[data-tracking-order]', item.order); set('[data-tracking-customer]', item.customer); set('[data-tracking-destination]', item.destination); set('[data-tracking-eta]', item.eta); set('[data-tracking-latest]', item.latest); set('[data-tracking-updated]', item.updated);
        const proof = modal.querySelector('[data-delivery-proof]'); if (proof) proof.hidden = item.status_key !== 'delivered';
        modal.hidden = false; document.body.style.overflow = 'hidden';
    }));
    document.querySelector('[data-report-shipment]')?.addEventListener('click', () => { const details = document.querySelector('[data-modal="tracking-details"]'); const report = document.querySelector('[data-modal="tracking-report"]'); if (details) details.hidden = true; if (report) report.hidden = false; });
    document.querySelector('[data-shipment-report-form]')?.addEventListener('submit', (event) => { event.preventDefault(); event.currentTarget.closest('[data-modal]').hidden = true; document.body.style.overflow = ''; if (toast) { toast.textContent = 'Shipment issue submitted to logistics support.'; toast.classList.add('is-visible'); } });
    document.querySelector('[data-tracking-export]')?.addEventListener('click', () => { if (toast) { toast.textContent = 'Shipment report prepared for export.'; toast.classList.add('is-visible'); } });
    document.querySelectorAll('[data-fulfillment-demo]').forEach((button) => button.addEventListener('click', () => { if (toast) { toast.textContent = `${button.dataset.fulfillmentDemo} Frontend preview only.`; toast.classList.add('is-visible'); } }));

    /* Product pricing and promotion workspace. */
    const pricingWorkspace = document.querySelector('[data-pricing-workspace]');
    const showPricingToast = (message) => {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('is-visible');
        window.clearTimeout(window.sellerToastTimer);
        window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3400);
    };
    const formatPricingMoney = (minor) => `₱${(Number(minor) / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    let applyPricingFilters = () => {};
    const updatePricingSummary = () => {
        if (!pricingWorkspace) return;
        const summaryDiscounted = pricingWorkspace.querySelector('[data-pricing-summary="discounted"]');
        const summaryVoucherEligible = pricingWorkspace.querySelector('[data-pricing-summary="voucher-eligible"]');
        if (summaryDiscounted) {
            summaryDiscounted.textContent = pricingWorkspace.querySelectorAll('[data-pricing-row][data-state="discounted"]').length;
        }
        if (summaryVoucherEligible) {
            summaryVoucherEligible.textContent = pricingWorkspace.querySelectorAll('[data-voucher-toggle]:checked').length;
        }
    };
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const sendPricingMutation = async (endpoint, body) => {
        const response = await fetch(endpoint, {
            method: 'PATCH',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify(body),
        });
        const result = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(result.message && result.message !== 'The given data was invalid.'
                ? result.message
                : Object.values(result.errors || {}).flat()[0] || 'Unable to save pricing changes.');
        }

        return result;
    };

    if (pricingWorkspace) {
        const pricingRows = [...pricingWorkspace.querySelectorAll('[data-pricing-row]')];
        const pricingSearch = pricingWorkspace.querySelector('[data-pricing-search]');
        const pricingStatus = pricingWorkspace.querySelector('[data-pricing-status]');
        const pricingCount = pricingWorkspace.querySelector('[data-pricing-count]');
        const pricingEmpty = pricingWorkspace.querySelector('[data-pricing-empty]');
        applyPricingFilters = () => {
            const query = pricingSearch?.value.trim().toLowerCase() ?? '';
            const status = pricingStatus?.value ?? '';
            let visible = 0;
            pricingRows.forEach((row) => {
                const match = (!query || row.dataset.search.includes(query)) && (!status || row.dataset.state === status);
                row.hidden = !match;
                if (match) visible += 1;
            });
            if (pricingCount) pricingCount.textContent = visible;
            if (pricingEmpty) pricingEmpty.hidden = pricingRows.length === 0 || visible > 0;
        };
        pricingSearch?.addEventListener('input', applyPricingFilters);
        pricingStatus?.addEventListener('change', applyPricingFilters);
        pricingWorkspace.querySelector('[data-pricing-reset]')?.addEventListener('click', () => {
            if (pricingSearch) pricingSearch.value = '';
            if (pricingStatus) pricingStatus.value = '';
            applyPricingFilters();
        });
        pricingWorkspace.querySelectorAll('[data-pricing-tab]').forEach((tab) => tab.addEventListener('click', () => {
            pricingWorkspace.querySelectorAll('[data-pricing-tab]').forEach((item) => {
                const active = item === tab;
                item.classList.toggle('is-active', active);
                item.setAttribute('aria-selected', String(active));
            });
            pricingWorkspace.querySelectorAll('[data-pricing-panel]').forEach((panel) => {
                panel.hidden = panel.dataset.pricingPanel !== tab.dataset.pricingTab;
            });
        }));
    }

    const pricingParams = new URLSearchParams(window.location.search);
    const requestedPricingTab = pricingParams.get('tab');
    if (pricingWorkspace && requestedPricingTab) {
        pricingWorkspace.querySelector('[data-pricing-tab="' + requestedPricingTab + '"]')?.click();
    }
    const priceModal = document.querySelector('[data-modal="pricing-price"]');
    const campaignModal = document.querySelector('[data-modal="pricing-campaign"]');
    let activePricingButton = null;
    let activePricingProduct = null;
    const calculatePrice = () => {
        if (!priceModal) return;
        const regular = Math.max(0, Number(priceModal.querySelector('[data-price-regular]')?.value || 0));
        const discount = Math.min(90, Math.max(0, Number(priceModal.querySelector('[data-price-discount]')?.value || 0)));
        const sale = regular * (1 - discount / 100);
        const fee = sale * .10;
        const format = (value) => `₱${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        priceModal.querySelector('[data-price-sale]').textContent = format(sale);
        priceModal.querySelector('[data-price-fee]').textContent = `−${format(fee)}`;
        priceModal.querySelector('[data-price-net]').textContent = format(sale - fee);
    };
    document.querySelectorAll('[data-pricing-open]').forEach((button) => button.addEventListener('click', () => {
        const isPrice = button.dataset.pricingOpen === 'price';
        const modal = isPrice ? priceModal : campaignModal;
        if (!modal) return;
        if (isPrice) {
            const product = JSON.parse(button.dataset.product);
            activePricingButton = button;
            activePricingProduct = product;
            modal.querySelector('[data-price-product]').textContent = product.name;
            modal.querySelector('[data-price-regular]').value = product.price;
            const discountInput = modal.querySelector('[data-price-discount]');
            const currentDiscount = Number(product.discount_percent || 0);
            discountInput.value = currentDiscount > 0 ? String(currentDiscount) : '';
            modal.querySelector('[data-price-variant-count]').textContent = product.variants?.length || 0;
            calculatePrice();
        }
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
    }));
    priceModal?.querySelectorAll('[data-price-regular],[data-price-discount]').forEach((input) => input.addEventListener('input', calculatePrice));
    priceModal?.querySelector('[data-price-save]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const regularInput = priceModal.querySelector('[data-price-regular]');
        const discountInput = priceModal.querySelector('[data-price-discount]');

        if (!regularInput?.reportValidity() || (discountInput && !discountInput.reportValidity()) || !activePricingButton) return;

        const discountPercent = Number(discountInput?.value || 0);

        const endpoint = activePricingButton.dataset.pricingUrl;
        const originalLabel = button.textContent;
        button.disabled = true;
        button.textContent = 'Saving…';

        try {
            const result = await sendPricingMutation(endpoint, {
                regular_price: regularInput.value,
                discount_percent: discountPercent,
            });
            const data = result.data || {};
            const productId = String(data.product_id);
            const pricingRow = [...pricingWorkspace?.querySelectorAll('[data-pricing-row]') || []]
                .find((row) => row.dataset.productId === productId);
            const voucherRow = [...pricingWorkspace?.querySelectorAll('[data-voucher-row]') || []]
                .find((row) => row.dataset.productId === productId);

            if (pricingRow) {
                const saleDisplay = pricingRow.querySelector('[data-price-sale-display]');
                const regularDisplay = pricingRow.querySelector('[data-price-regular-display]');
                const discountDisplay = pricingRow.querySelector('[data-price-discount-display]');
                const hasDiscount = Number(data.discount_percent) > 0;

                if (regularDisplay) regularDisplay.textContent = formatPricingMoney(data.regular_price_minor);
                if (saleDisplay) saleDisplay.textContent = formatPricingMoney(data.sale_price_minor);
                if (discountDisplay) {
                    discountDisplay.textContent = hasDiscount ? `${data.discount_percent}% off` : '—';
                    discountDisplay.classList.toggle('pricing-discount-text', hasDiscount);
                    discountDisplay.classList.toggle('pricing-muted', !hasDiscount);
                }
                pricingRow.dataset.state = hasDiscount ? 'discounted' : 'regular';
                activePricingProduct.price = Number(data.regular_price_minor) / 100;
                activePricingProduct.discount_percent = Number(data.discount_percent);
                activePricingButton.dataset.product = JSON.stringify(activePricingProduct);
                applyPricingFilters();
            }

            const voucherRegularDisplay = voucherRow?.querySelector('[data-voucher-regular-display]');
            if (voucherRegularDisplay) voucherRegularDisplay.textContent = formatPricingMoney(data.regular_price_minor);

            updatePricingSummary();

            priceModal.hidden = true;
            document.body.style.overflow = '';
            showPricingToast(result.message || 'Product pricing saved.');
        } catch (error) {
            showPricingToast(error.message || 'Unable to save product pricing.');
        } finally {
            button.disabled = false;
            button.textContent = originalLabel;
        }
    });
    campaignModal?.querySelector('[data-campaign-save]')?.addEventListener('click', () => {
        const name = campaignModal.querySelector('[data-campaign-name]');
        if (!name?.value.trim()) { name?.focus(); name?.reportValidity(); return; }
        campaignModal.hidden = true;
        document.body.style.overflow = '';
        if (toast) {
            toast.textContent = `${name.value.trim()} preview is ready; it was not saved.`;
            toast.classList.add('is-visible');
            window.clearTimeout(window.sellerToastTimer);
            window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3200);
        }
    });
    document.querySelectorAll('[data-voucher-toggle]').forEach((toggle) => toggle.addEventListener('change', async () => {
        const previousValue = !toggle.checked;
        const wasDisabled = toggle.disabled;
        const label = toggle.closest('.pricing-switch')?.querySelector('span');
        toggle.disabled = true;

        try {
            const result = await sendPricingMutation(toggle.dataset.voucherUrl, {
                voucher_eligible: toggle.checked,
            });
            toggle.checked = Boolean(result.data?.voucher_eligible);
            if (label) label.textContent = toggle.checked ? 'Eligible' : 'Not eligible';
            const productId = toggle.closest('[data-voucher-row]')?.dataset.productId;
            const pricingRow = [...pricingWorkspace?.querySelectorAll('[data-pricing-row]') || []]
                .find((row) => row.dataset.productId === productId);
            const voucherState = pricingRow?.querySelector('.pricing-voucher-state');
            if (voucherState) {
                voucherState.classList.toggle('is-eligible', toggle.checked);
                voucherState.classList.toggle('is-ineligible', !toggle.checked);
                const voucherLabel = voucherState.querySelector('span');
                if (voucherLabel) voucherLabel.textContent = toggle.checked ? 'Eligible' : 'Not eligible';
            }
            updatePricingSummary();
            showPricingToast(result.message || 'Voucher eligibility saved.');
        } catch (error) {
            toggle.checked = previousValue;
            if (label) label.textContent = previousValue ? 'Eligible' : 'Not eligible';
            updatePricingSummary();
            showPricingToast(error.message || 'Unable to save voucher eligibility.');
        } finally {
            toggle.disabled = wasDisabled;
        }
    }));
    const promotionForm = document.querySelector('[data-promotion-form]');
    if (promotionForm) {
        const value = promotionForm.querySelector('[data-promotion-value]');
        const name = promotionForm.querySelector('[data-promotion-name]');
        const start = promotionForm.querySelector('[data-promotion-start]');
        const end = promotionForm.querySelector('[data-promotion-end]');
        const checks = [...promotionForm.querySelectorAll('[data-promotion-product]')];
        const selectAll = promotionForm.querySelector('[data-promotion-select-all]');

        const syncPromotionSummary = () => {
            const currentValue = Math.max(0, Number(value?.value || 0));
            const selected = checks.filter((check) => check.checked).length;
            const summaryName = promotionForm.querySelector('[data-promotion-summary-name]');
            const summaryValue = promotionForm.querySelector('[data-promotion-summary-value]');
            const summaryProducts = promotionForm.querySelector('[data-promotion-summary-products]');
            const summarySchedule = promotionForm.querySelector('[data-promotion-summary-schedule]');

            if (summaryName) summaryName.textContent = name?.value.trim() || 'Untitled promotion';
            if (summaryValue) summaryValue.textContent = currentValue > 0
                ? '₱' + currentValue.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' voucher'
                : 'Enter voucher amount';
            if (summaryProducts) summaryProducts.textContent = selected + ' selected';
            if (summarySchedule) summarySchedule.textContent = start?.value && end?.value ? start.value + ' to ' + end.value : 'Not set';

            if (selectAll) {
                selectAll.checked = checks.length > 0 && selected === checks.length;
                selectAll.indeterminate = selected > 0 && selected < checks.length;
            }
        };

        [value, name, start, end].forEach((field) => {
            field?.addEventListener('input', syncPromotionSummary);
            field?.addEventListener('change', syncPromotionSummary);
        });
        checks.forEach((check) => check.addEventListener('change', syncPromotionSummary));
        selectAll?.addEventListener('change', () => {
            checks.forEach((check) => { check.checked = selectAll.checked; });
            syncPromotionSummary();
        });

        promotionForm.addEventListener('submit', (event) => {
            if (!checks.some((check) => check.checked)) {
                event.preventDefault();
                if (toast) {
                    toast.textContent = 'Select at least one eligible product.';
                    toast.classList.add('is-visible');
                    window.clearTimeout(window.sellerToastTimer);
                    window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3000);
                }
                checks[0]?.focus();
                return;
            }

            const submitter = event.submitter;
            if (submitter) {
                submitter.disabled = true;
                submitter.setAttribute('aria-busy', 'true');
            }
        });

        syncPromotionSummary();
    }

    /* Customer Service — frontend-only seller inbox. */
    const messageWorkspace = document.querySelector('[data-message-workspace]');
    if (messageWorkspace) {
        const conversationRows = [...messageWorkspace.querySelectorAll('[data-conversation]')];
        const search = messageWorkspace.querySelector('[data-message-search]');
        const empty = messageWorkspace.querySelector('[data-conversation-empty]');
        const thread = messageWorkspace.querySelector('[data-message-thread]');
        const input = messageWorkspace.querySelector('[data-message-input]');
        let filter = 'all';
        let activeConversation = JSON.parse(conversationRows[0]?.dataset.payload || '{}');

        const setText = (selector, value) => {
            const node = messageWorkspace.querySelector(selector);
            if (node) node.textContent = value;
        };
        const renderThread = (conversation) => {
            if (!thread) return;
            thread.replaceChildren();
            const day = document.createElement('div');
            day.className = 'message-day';
            day.innerHTML = '<span>Today</span>';
            thread.append(day);
            conversation.messages.forEach((message) => {
                const row = document.createElement('article');
                row.className = `chat-bubble-row is-${message.from}`;
                if (message.from === 'buyer') {
                    const avatar = document.createElement('span');
                    avatar.className = 'conversation-avatar';
                    avatar.textContent = conversation.initials;
                    row.append(avatar);
                }
                const bubble = document.createElement('div');
                const copy = document.createElement('p');
                const time = document.createElement('time');
                copy.textContent = message.text;
                time.textContent = message.time;
                if (message.from === 'seller') time.insertAdjacentHTML('beforeend', '<i data-lucide="check-check"></i>');
                bubble.append(copy, time);
                row.append(bubble);
                thread.append(row);
            });
            window.lucide?.createIcons();
            thread.scrollTop = thread.scrollHeight;
        };
        const openConversation = (row) => {
            activeConversation = JSON.parse(row.dataset.payload);
            conversationRows.forEach((item) => item.classList.toggle('is-active', item === row));
            row.dataset.unread = '0';
            row.querySelector('.conversation-unread')?.remove();
            setText('[data-chat-initials]', activeConversation.initials);
            setText('[data-chat-buyer]', activeConversation.buyer);
            setText('[data-chat-active]', activeConversation.active);
            setText('[data-chat-order]', activeConversation.order);
            setText('[data-chat-context-product]', activeConversation.product);
            setText('[data-chat-context-order]', activeConversation.order);
            setText('[data-detail-initials]', activeConversation.initials);
            setText('[data-detail-buyer]', activeConversation.buyer);
            setText('[data-detail-member]', activeConversation.member);
            setText('[data-detail-order]', activeConversation.order);
            setText('[data-detail-status]', activeConversation.status);
            setText('[data-detail-product]', activeConversation.product);
            setText('[data-detail-variant]', activeConversation.variant);
            setText('[data-detail-price]', activeConversation.price);
            setText('[data-detail-previous]', activeConversation.previous);
            renderThread(activeConversation);
            const unread = conversationRows.reduce((total, item) => total + Number(item.dataset.unread || 0), 0);
            setText('[data-total-unread]', unread);
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
        conversationRows.forEach((row) => row.addEventListener('click', () => openConversation(row)));
        messageWorkspace.querySelectorAll('[data-message-filter]').forEach((button) => button.addEventListener('click', () => {
            filter = button.dataset.messageFilter;
            messageWorkspace.querySelectorAll('[data-message-filter]').forEach((item) => item.classList.toggle('is-active', item === button));
            applyConversationFilter();
        }));
        search?.addEventListener('input', applyConversationFilter);
        messageWorkspace.querySelectorAll('[data-quick-reply]').forEach((button) => button.addEventListener('click', () => {
            if (input) { input.value = button.dataset.quickReply; input.focus(); }
        }));
        const messageForm = messageWorkspace.querySelector('[data-message-form]');
        messageForm?.addEventListener('submit', (event) => {
            event.preventDefault();
            const message = input?.value.trim();
            if (!message || !thread) { input?.focus(); return; }
            activeConversation.messages.push({ from: 'seller', text: message, time: 'Just now' });
            renderThread(activeConversation);
            input.value = '';
        });
        input?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); messageForm?.requestSubmit(); }
        });
        messageWorkspace.querySelector('[data-message-attach]')?.addEventListener('click', () => {
            if (toast) { toast.textContent = 'Attachment picker will be connected with the backend later.'; toast.classList.add('is-visible'); }
        });
        messageWorkspace.querySelector('[data-seller-availability]')?.addEventListener('change', (event) => {
            if (toast) { toast.textContent = `Status changed to ${event.currentTarget.value} for this preview.`; toast.classList.add('is-visible'); }
        });
        messageWorkspace.querySelector('[data-report-conversation]')?.addEventListener('click', () => {
            if (toast) { toast.textContent = 'Conversation report form will be connected during backend integration.'; toast.classList.add('is-visible'); }
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
        row.dataset.orderStatusHistory = JSON.stringify(snapshot.timeline_history || []);
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
