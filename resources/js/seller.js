const bootSeller = () => {
    window.lucide?.createIcons();

    const shell = document.querySelector('[data-seller-shell]');
    document.querySelector('[data-seller-menu]')?.addEventListener('click', () => shell?.classList.add('menu-open'));
    document.querySelector('[data-seller-overlay]')?.addEventListener('click', () => shell?.classList.remove('menu-open'));

    document.querySelectorAll('[data-seller-nav-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const group = button.closest('[data-seller-nav-group]');
            const children = group?.querySelector('.seller-nav-children');
            if (!group || !children) return;

            const willOpen = !group.classList.contains('is-open');
            document.querySelectorAll('[data-seller-nav-group]').forEach((item) => {
                item.classList.remove('is-open');
                item.querySelector('[data-seller-nav-toggle]')?.setAttribute('aria-expanded', 'false');
                const itemChildren = item.querySelector('.seller-nav-children');
                if (itemChildren) itemChildren.hidden = true;
            });

            group.classList.toggle('is-open', willOpen);
            button.setAttribute('aria-expanded', String(willOpen));
            children.hidden = !willOpen;
        });
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


    const serverToast = document.querySelector('[data-server-toast]');
    if (serverToast) window.setTimeout(() => serverToast.classList.remove('is-visible'), 3200);
};

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootSeller);
else bootSeller();
