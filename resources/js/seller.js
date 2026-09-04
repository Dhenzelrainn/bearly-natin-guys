const bootSeller = () => {
    window.lucide?.createIcons();

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
    const productDiscount = document.querySelector('[data-product-discount]');
    const productSalePrice = document.querySelector('[data-sale-price]');
    const updateSalePrice = () => {
        if (!productSalePrice) return;
        const price = Math.max(0, Number(productPrice?.value || 0));
        const discount = Math.min(90, Math.max(0, Number(productDiscount?.value || 0)));
        const salePrice = price * (1 - (discount / 100));
        productSalePrice.textContent = `₱${salePrice.toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })}`;
    };
    productPrice?.addEventListener('input', updateSalePrice);
    productDiscount?.addEventListener('input', updateSalePrice);

    const productImageInput = document.querySelector('[data-product-image-input]');
    productImageInput?.addEventListener('change', () => {
        const file = productImageInput.files?.[0];
        const preview = document.querySelector('[data-product-image-preview]');
        if (!file || !preview) return;
        const image = document.createElement('img');
        image.alt = 'Selected product preview';
        image.src = URL.createObjectURL(file);
        image.addEventListener('load', () => URL.revokeObjectURL(image.src), { once: true });
        preview.replaceChildren(image);
    });

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
    document.querySelector('[data-product-search]')?.addEventListener('input', applyProductFilters);
    document.querySelectorAll('[data-product-category],[data-product-status]').forEach((select) => select.addEventListener('change', applyProductFilters));

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


    /* Inventory page — frontend-only controls and non-persistent stock preview. */
    const inventoryWorkspace = document.querySelector('[data-inventory-workspace]');

    if (inventoryWorkspace) {
        let activeInventoryStatus = 'all';
        const inventoryRows = [...inventoryWorkspace.querySelectorAll('[data-inventory-row]')];
        const inventorySearch = inventoryWorkspace.querySelector('[data-inventory-search]');
        const inventoryCategory = inventoryWorkspace.querySelector('[data-inventory-category]');
        const inventoryStatus = inventoryWorkspace.querySelector('[data-inventory-status]');
        const showVariations = inventoryWorkspace.querySelector('[data-inventory-variations]');
        const checkAll = inventoryWorkspace.querySelector('[data-inventory-check-all]');
        const selectedCount = inventoryWorkspace.querySelector('[data-inventory-selected-count]');
        const visibleCount = inventoryWorkspace.querySelector('[data-inventory-visible-count]');
        const noResults = inventoryWorkspace.querySelector('[data-inventory-no-results]');
        const bulkButtons = [...inventoryWorkspace.querySelectorAll('[data-inventory-bulk]')];

        const updateInventorySelection = () => {
            const visibleChecks = inventoryRows
                .filter((row) => !row.hidden)
                .map((row) => row.querySelector('[data-inventory-check]'));
            const checked = inventoryRows.filter((row) => row.querySelector('[data-inventory-check]')?.checked).length;

            if (selectedCount) selectedCount.textContent = checked;
            bulkButtons.forEach((button) => { button.disabled = checked === 0; });

            if (checkAll) {
                checkAll.checked = visibleChecks.length > 0 && visibleChecks.every((checkbox) => checkbox?.checked);
                checkAll.indeterminate = visibleChecks.some((checkbox) => checkbox?.checked) && !checkAll.checked;
            }
        };

        const applyInventoryFilters = () => {
            const search = inventorySearch?.value.trim().toLowerCase() ?? '';
            const category = inventoryCategory?.value ?? '';
            const selectedStatus = inventoryStatus?.value ?? '';
            const variationsVisible = showVariations?.checked ?? true;
            let visible = 0;

            inventoryRows.forEach((row) => {
                const matches = (activeInventoryStatus === 'all' || row.dataset.status === activeInventoryStatus)
                    && (!selectedStatus || row.dataset.status === selectedStatus)
                    && (!search || row.dataset.search.includes(search))
                    && (!category || row.dataset.category === category)
                    && (variationsVisible || row.dataset.variationRow !== 'true');

                row.hidden = !matches;
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
        inventoryCategory?.addEventListener('change', applyInventoryFilters);
        inventoryStatus?.addEventListener('change', applyInventoryFilters);
        showVariations?.addEventListener('change', applyInventoryFilters);

        inventoryWorkspace.querySelector('[data-inventory-reset]')?.addEventListener('click', () => {
            if (inventorySearch) inventorySearch.value = '';
            if (inventoryCategory) inventoryCategory.value = '';
            if (inventoryStatus) inventoryStatus.value = '';
            if (showVariations) showVariations.checked = true;
            activeInventoryStatus = 'all';

            inventoryWorkspace.querySelectorAll('[data-inventory-tab]').forEach((tab) => {
                const active = tab.dataset.inventoryTab === 'all';
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', String(active));
            });

            applyInventoryFilters();
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
            const count = Number(selectedCount?.textContent ?? 0);
            if (!toast || count === 0) return;
            toast.textContent = `${button.dataset.inventoryBulk} selected for ${count} inventory item${count === 1 ? '' : 's'} (preview only).`;
            toast.classList.add('is-visible');
            window.clearTimeout(window.sellerToastTimer);
            window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3000);
        }));

        applyInventoryFilters();
    }

    const adjustModal = document.querySelector('[data-modal="inventory-adjust"]');
    const adjustItemId = adjustModal?.querySelector('[data-adjust-item-id]');
    const adjustItemLabel = adjustModal?.querySelector('[data-adjust-item-label]');
    const adjustCurrent = adjustModal?.querySelector('[data-adjust-current]');
    const adjustMode = adjustModal?.querySelector('[data-adjust-mode]');
    const adjustQuantity = adjustModal?.querySelector('[data-adjust-quantity]');

    document.querySelectorAll('[data-adjust-stock]').forEach((button) => {
        button.addEventListener('click', () => {
            const row = button.closest('[data-inventory-row]');
            if (!adjustModal || !row) return;
            if (adjustItemId) adjustItemId.value = button.dataset.itemId;
            if (adjustItemLabel) adjustItemLabel.textContent = button.dataset.itemLabel;
            if (adjustCurrent) adjustCurrent.textContent = row.dataset.onHand;
            if (adjustMode) adjustMode.value = 'add';
            if (adjustQuantity) adjustQuantity.value = '1';
            adjustModal.hidden = false;
            document.body.style.overflow = 'hidden';
            adjustQuantity?.focus();
        });
    });

    adjustModal?.querySelector('[data-apply-stock-adjustment]')?.addEventListener('click', () => {
        const row = document.querySelector(`[data-inventory-row][data-id="${adjustItemId?.value}"]`);
        if (!row) return;

        const current = Math.max(0, Number(row.dataset.onHand || 0));
        const reserved = Math.max(0, Number(row.dataset.reserved || 0));
        const threshold = Math.max(0, Number(row.dataset.threshold || 0));
        const quantity = Math.max(0, Math.floor(Number(adjustQuantity?.value || 0)));
        let next = current;

        if (adjustMode?.value === 'add') next = current + quantity;
        if (adjustMode?.value === 'remove') next = Math.max(0, current - quantity);
        if (adjustMode?.value === 'set') next = quantity;

        const available = Math.max(0, next - reserved);
        const statusKey = available === 0 ? 'out-of-stock' : available <= threshold ? 'low-stock' : 'in-stock';
        const statusLabel = statusKey === 'out-of-stock' ? 'Out of Stock' : statusKey === 'low-stock' ? 'Low Stock' : 'In Stock';
        const status = row.querySelector('[data-stock-status]');
        const availableCell = row.querySelector('[data-stock-available]');

        row.dataset.onHand = String(next);
        row.dataset.status = statusKey;
        if (row.querySelector('[data-stock-on-hand]')) row.querySelector('[data-stock-on-hand]').textContent = next;
        if (availableCell) {
            availableCell.textContent = available;
            availableCell.classList.toggle('is-empty', available === 0);
        }
        if (status) {
            status.textContent = statusLabel;
            status.className = `inventory-stock-badge stock-${statusKey}`;
        }

        const action = row.querySelector('[data-adjust-stock]');
        if (action) action.textContent = available === 0 ? 'Restock' : 'Adjust Stock';
        if (adjustModal) adjustModal.hidden = true;
        document.body.style.overflow = '';

        if (toast) {
            toast.textContent = `Stock preview updated to ${next}. Refreshing the page will reset this change.`;
            toast.classList.add('is-visible');
            window.clearTimeout(window.sellerToastTimer);
            window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3400);
        }

        inventoryWorkspace?.querySelector('[data-inventory-search]')?.dispatchEvent(new Event('input'));
    });

    document.querySelector('[data-inventory-alerts]')?.addEventListener('click', () => {
        document.querySelector('[data-inventory-tab="low-stock"]')?.click();
        document.querySelector('[data-inventory-workspace]')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    document.querySelectorAll('[data-inventory-demo]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!toast) return;
            toast.textContent = `${button.dataset.inventoryDemo} Frontend preview only.`;
            toast.classList.add('is-visible');
            window.clearTimeout(window.sellerToastTimer);
            window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3000);
        });
    });

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

    /* Store appearance — frontend-only image, description, and buyer preview. */
    const appearance = document.querySelector('[data-storefront-appearance]');
    if (appearance) {
        const description = appearance.querySelector('[data-storefront-description]');
        const count = appearance.querySelector('[data-storefront-description-count]');
        const previewDescription = appearance.querySelector('[data-storefront-preview-description]');

        description?.addEventListener('input', () => {
            if (count) count.textContent = description.value.length;
            if (previewDescription) previewDescription.textContent = description.value.trim() || 'Your store description will appear here.';
        });

        appearance.querySelectorAll('[data-storefront-image]').forEach((input) => {
            input.addEventListener('change', () => {
                const file = input.files?.[0];
                if (!file) return;

                const maximum = input.dataset.storefrontImage === 'cover' ? 10 : 5;
                if (file.size > maximum * 1024 * 1024) {
                    input.value = '';
                    if (toast) {
                        toast.textContent = `${input.dataset.storefrontImage === 'cover' ? 'Cover' : 'Profile'} image must be ${maximum}MB or smaller.`;
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
                    } else {
                        appearance.querySelector('[data-storefront-cover-placeholder]')?.replaceChildren(createPreview());
                        appearance.querySelector('[data-storefront-preview-cover]')?.replaceChildren(createPreview());
                    }
                });
                reader.readAsDataURL(file);
            });
        });
    }

    document.querySelector('[data-storefront-preview-toggle]')?.addEventListener('click', () => {
        document.querySelector('.storefront-preview-card')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    document.querySelector('[data-save-appearance]')?.addEventListener('click', () => {
        if (!toast) return;
        toast.textContent = 'Store appearance saved for this preview. It will reset after refresh.';
        toast.classList.add('is-visible');
        window.clearTimeout(window.sellerToastTimer);
        window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3400);
    });

    /* Publication settings — frontend-only visibility preview. */
    document.querySelector('[data-publication-toggle]')?.addEventListener('click', (event) => {
        const button = event.currentTarget;
        const nextPublished = button.dataset.published !== 'true';
        button.dataset.published = String(nextPublished);
        button.querySelector('span').textContent = nextPublished ? 'Unpublish Store' : 'Publish Store';

        const title = document.querySelector('[data-publication-title]');
        const copy = document.querySelector('[data-publication-copy]');
        const badge = document.querySelector('[data-publication-badge]');
        const visibility = document.querySelector('[data-publication-visibility]');
        if (title) title.textContent = nextPublished ? 'Store Published' : 'Store Not Published';
        if (copy) copy.textContent = nextPublished ? 'Your storefront is currently visible to buyers.' : 'Your storefront is hidden from buyers.';
        if (badge) {
            badge.classList.toggle('is-live', nextPublished);
            badge.lastChild.textContent = nextPublished ? 'Published' : 'Draft';
        }
        if (visibility) visibility.textContent = nextPublished ? 'Visible to buyers' : 'Hidden from buyers';
        if (!toast) return;
        toast.textContent = `${nextPublished ? 'Publish' : 'Unpublish'} action previewed. Backend confirmation will be connected later.`;
        toast.classList.add('is-visible');
        window.clearTimeout(window.sellerToastTimer);
        window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3400);
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
        let active = 'all';
        const apply = () => {
            const query = search?.value.trim().toLowerCase() ?? '';
            const selected = status?.value ?? '';
            let visible = 0;
            rows.forEach((row) => {
                const match = (active === 'all' || row.dataset.status === active) && (!selected || row.dataset.status === selected) && (!query || row.dataset.search.includes(query));
                row.hidden = !match;
                if (match) visible += 1;
            });
            if (count) count.textContent = visible;
            if (empty) empty.hidden = visible > 0;
        };
        workspace.querySelectorAll(tabSelector).forEach((tab) => tab.addEventListener('click', () => {
            active = tab.dataset.waybillTab || tab.dataset.pickupTab || tab.dataset.trackingTab || 'all';
            workspace.querySelectorAll(tabSelector).forEach((item) => item.classList.toggle('is-active', item === tab));
            apply();
        }));
        search?.addEventListener('input', apply);
        status?.addEventListener('change', apply);
        if (resetSelector) workspace.querySelector(resetSelector)?.addEventListener('click', () => {
            if (search) search.value = '';
            if (status) status.value = '';
            active = 'all';
            workspace.querySelectorAll(tabSelector).forEach((tab, index) => tab.classList.toggle('is-active', index === 0));
            apply();
        });
        apply();
    };
    setupFulfillmentFilter('[data-waybill-workspace]', '[data-waybill-row]', '[data-waybill-tab]', '[data-waybill-search]', '[data-waybill-count]', '[data-waybill-empty]');
    setupFulfillmentFilter('[data-pickup-workspace]', '[data-pickup-row]', '[data-pickup-tab]', '[data-pickup-search]', '[data-pickup-count]', '[data-pickup-empty]');
    setupFulfillmentFilter('[data-tracking-workspace]', '[data-tracking-row]', '[data-tracking-tab]', '[data-tracking-search]', '[data-tracking-count]', '[data-tracking-empty]', '[data-tracking-reset]', '[data-tracking-status]');

    const waybillChecks = [...document.querySelectorAll('[data-waybill-check]:not(:disabled)')];
    const waybillBulk = document.querySelector('[data-waybill-bulk]');
    const updateWaybillBulk = () => {
        const selected = waybillChecks.filter((check) => check.checked).length;
        if (waybillBulk) { waybillBulk.disabled = selected === 0; waybillBulk.innerHTML = `<i data-lucide="printer"></i>Print Selected${selected ? ` (${selected})` : ''}`; window.lucide?.createIcons(); }
    };
    waybillChecks.forEach((check) => check.addEventListener('change', updateWaybillBulk));
    document.querySelector('[data-waybill-check-all]')?.addEventListener('change', (event) => { waybillChecks.forEach((check) => { check.checked = event.currentTarget.checked; }); updateWaybillBulk(); });
    waybillBulk?.addEventListener('click', () => { if (toast) { toast.textContent = `${waybillChecks.filter((check) => check.checked).length} waybills prepared for printing.`; toast.classList.add('is-visible'); } });
    document.querySelector('[data-waybill-history]')?.addEventListener('click', () => { const modal = document.querySelector('[data-modal="waybill-history"]'); if (modal) { modal.hidden = false; document.body.style.overflow = 'hidden'; } });
    document.querySelectorAll('[data-waybill-action]').forEach((button) => button.addEventListener('click', () => {
        const item = JSON.parse(button.dataset.waybill); const modal = document.querySelector('[data-modal="waybill-preview"]'); if (!modal) return;
        const set = (selector, value) => { const node = modal.querySelector(selector); if (node) node.textContent = value; };
        set('[data-waybill-modal-order]', `${item.action} · ${item.order}`); set('[data-waybill-modal-tracking]', item.tracking); set('[data-waybill-modal-customer]', item.customer); set('[data-waybill-modal-destination]', item.destination); set('[data-waybill-modal-courier]', item.courier); set('[data-waybill-modal-parcel]', `${item.packages} package(s) · ${item.weight} · ${item.size}`);
        modal.hidden = false; document.body.style.overflow = 'hidden';
    }));
    document.querySelector('[data-waybill-print]')?.addEventListener('click', () => { if (toast) { toast.textContent = 'Waybill sent to the print dialog.'; toast.classList.add('is-visible'); } window.print(); });

    document.querySelector('[data-pickup-form]')?.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!event.currentTarget.querySelector('[data-pickup-order]:checked')) { if (toast) { toast.textContent = 'Select at least one labeled parcel.'; toast.classList.add('is-visible'); } return; }
        event.currentTarget.closest('[data-modal]').hidden = true; document.body.style.overflow = '';
        if (toast) { toast.textContent = 'Pickup request submitted to logistics for approval.'; toast.classList.add('is-visible'); }
    });
    document.querySelectorAll('[data-pickup-view]').forEach((button) => button.addEventListener('click', () => {
        const item = JSON.parse(button.dataset.pickup); const modal = document.querySelector('[data-modal="pickup-details"]'); if (!modal) return;
        const set = (selector, value) => { const node = modal.querySelector(selector); if (node) node.textContent = value; };
        set('[data-pickup-id]', item.id); set('[data-pickup-status]', item.status); set('[data-pickup-orders]', `${item.orders} · ${item.packages} parcels`); set('[data-pickup-schedule]', item.schedule); set('[data-pickup-rider]', item.rider); set('[data-pickup-address]', item.address);
        const actions = modal.querySelector('[data-pickup-actions]'); if (actions) actions.hidden = !['pending', 'assigned'].includes(item.status_key);
        const cancel = modal.querySelector('[data-pickup-cancel]'); if (cancel) cancel.hidden = item.status_key !== 'pending';
        const confirm = modal.querySelector('[data-pickup-confirm]'); if (confirm) confirm.hidden = item.status_key !== 'assigned';
        modal.hidden = false; document.body.style.overflow = 'hidden';
    }));
    document.querySelectorAll('[data-pickup-cancel],[data-pickup-confirm]').forEach((button) => button.addEventListener('click', () => { button.closest('[data-modal]').hidden = true; document.body.style.overflow = ''; if (toast) { toast.textContent = button.hasAttribute('data-pickup-confirm') ? 'Parcel handover confirmed and orders moved to In Transit.' : 'Pending pickup request cancelled.'; toast.classList.add('is-visible'); } }));

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
    if (pricingWorkspace) {
        const pricingRows = [...pricingWorkspace.querySelectorAll('[data-pricing-row]')];
        const pricingSearch = pricingWorkspace.querySelector('[data-pricing-search]');
        const pricingStatus = pricingWorkspace.querySelector('[data-pricing-status]');
        const pricingCount = pricingWorkspace.querySelector('[data-pricing-count]');
        const pricingEmpty = pricingWorkspace.querySelector('[data-pricing-empty]');
        const applyPricingFilters = () => {
            const query = pricingSearch?.value.trim().toLowerCase() ?? '';
            const status = pricingStatus?.value ?? '';
            let visible = 0;
            pricingRows.forEach((row) => {
                const match = (!query || row.dataset.search.includes(query)) && (!status || row.dataset.state === status);
                row.hidden = !match;
                if (match) visible += 1;
            });
            if (pricingCount) pricingCount.textContent = visible;
            if (pricingEmpty) pricingEmpty.hidden = visible > 0;
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

    const priceModal = document.querySelector('[data-modal="pricing-price"]');
    const campaignModal = document.querySelector('[data-modal="pricing-campaign"]');
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
            modal.querySelector('[data-price-product]').textContent = product.name;
            modal.querySelector('[data-price-regular]').value = product.price;
            modal.querySelector('[data-price-discount]').value = product.discount_percent;
            calculatePrice();
        }
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
    }));
    priceModal?.querySelectorAll('[data-price-regular],[data-price-discount]').forEach((input) => input.addEventListener('input', calculatePrice));
    priceModal?.querySelector('[data-price-save]')?.addEventListener('click', () => {
        priceModal.hidden = true;
        document.body.style.overflow = '';
        if (toast) {
            toast.textContent = 'Product price updated for this frontend preview.';
            toast.classList.add('is-visible');
            window.clearTimeout(window.sellerToastTimer);
            window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3200);
        }
    });
    campaignModal?.querySelector('[data-campaign-save]')?.addEventListener('click', () => {
        const name = campaignModal.querySelector('[data-campaign-name]');
        if (!name?.value.trim()) { name?.focus(); name?.reportValidity(); return; }
        campaignModal.hidden = true;
        document.body.style.overflow = '';
        if (toast) {
            toast.textContent = `${name.value.trim()} created as a frontend preview.`;
            toast.classList.add('is-visible');
            window.clearTimeout(window.sellerToastTimer);
            window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3200);
        }
    });
    document.querySelectorAll('[data-voucher-toggle]').forEach((toggle) => toggle.addEventListener('change', () => {
        const label = toggle.closest('.pricing-switch')?.querySelector('span');
        if (label) label.textContent = toggle.checked ? 'Eligible' : 'Not eligible';
    }));
    document.querySelectorAll('[data-pricing-demo]').forEach((button) => button.addEventListener('click', () => {
        if (!toast) return;
        toast.textContent = `${button.dataset.pricingDemo} Frontend preview only.`;
        toast.classList.add('is-visible');
        window.clearTimeout(window.sellerToastTimer);
        window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3000);
    }));

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
        feedbackWorkspace.querySelectorAll('[data-feedback-form]').forEach((form) => form.addEventListener('submit', (event) => {
            event.preventDefault();
            const textarea = form.querySelector('textarea');
            if (!textarea?.value.trim()) { textarea?.focus(); return; }
            const review = form.closest('[data-feedback-review]');
            const response = document.createElement('div');
            response.className = 'seller-feedback-response';
            const label = document.createElement('span');
            const copy = document.createElement('p');
            label.textContent = 'Seller response';
            copy.textContent = textarea.value.trim();
            response.append(label, copy);
            form.closest('[data-feedback-reply-area]').replaceWith(response);
            review.dataset.status = 'replied';
            const status = review.querySelector('.feedback-review-status');
            if (status) { status.textContent = 'Replied'; status.classList.add('is-replied'); }
            if (toast) { toast.textContent = 'Reply posted for this frontend preview.'; toast.classList.add('is-visible'); }
        }));
    }

    /* Reports — frontend-only filters and export feedback. */
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
        page.querySelector('[data-report-period]')?.addEventListener('change', (event) => {
            if (!toast) return;
            toast.textContent = `${event.currentTarget.value} selected. Report data is a frontend preview.`;
            toast.classList.add('is-visible');
            window.clearTimeout(window.sellerToastTimer);
            window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3200);
        });
        page.querySelector('[data-report-export]')?.addEventListener('click', () => {
            if (!toast) return;
            toast.textContent = 'Report export previewed. File generation will be connected with the backend.';
            toast.classList.add('is-visible');
            window.clearTimeout(window.sellerToastTimer);
            window.sellerToastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3400);
        });
    });

    const serverToast = document.querySelector('[data-server-toast]');
    if (serverToast) window.setTimeout(() => serverToast.classList.remove('is-visible'), 3200);
};

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootSeller);
else bootSeller();
