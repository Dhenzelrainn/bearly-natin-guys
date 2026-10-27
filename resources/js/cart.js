(() => {
    const PREVIEW_KEY =
        window.bearlyStorageKey?.('preview-cart') ||
        'bearly-preview-cart-v1';

    const $ = id => document.getElementById(id);
    const wishlistProductIds = new Set(
        (Array.isArray(window.bearlyWishlistProductIds) ? window.bearlyWishlistProductIds : [])
            .map(Number)
            .filter(productId => Number.isInteger(productId) && productId > 0)
    );

    const money = value =>
        '₱' + Number(value || 0).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });

    const csrf = () =>
        document.querySelector('meta[name="csrf-token"]')?.content || '';

    const escapeHtml = value =>
        String(value ?? '').replace(
            /[&<>"']/g,
            character =>
                ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;',
                })[character]
        );

    function likeButtonMarkup(item) {
        const productId = Number(item.product_id || 0);
        const isLiked = productId > 0 && wishlistProductIds.has(productId);

        return `
            <button type="button" class="cart-like${isLiked ? ' is-added' : ''}" data-add-like aria-pressed="${isLiked ? 'true' : 'false'}" aria-label="${isLiked ? 'Remove' : 'Add'} ${escapeHtml(item.name)} ${isLiked ? 'from' : 'to'} My Likes">
                <span class="material-symbols-outlined" aria-hidden="true">${isLiked ? 'favorite' : 'favorite_border'}</span>
                <span class="cart-like-label">${isLiked ? 'In My Likes' : 'Add to Likes'}</span>
            </button>
        `;
    }

    const imageUrl = value => {
        const image = String(value || '').trim();

        return !image
            ? '/images/bearly-logo.png'
            : /^(https?:|\/|data:)/.test(image)
                ? image
                : `/${image.replace(/^\.\//, '')}`;
    };

    let liveItems = Array.isArray(window.bearlyCartItems)
        ? window.bearlyCartItems.map(item => ({
            ...item,
            key: `live:${item.id}`,
            source: 'live',
            price: Number(item.price_minor || 0) / 100,
        }))
        : [];

    let items = [];
    let selected = new Set();
    let selectionInitialized = false;

    function hasPreviewItems() {
        try {
            const value = JSON.parse(
                localStorage.getItem(PREVIEW_KEY) || '[]'
            );

            return Array.isArray(value) && value.length > 0;
        } catch {
            return false;
        }
    }

    function toast(message) {
        const element = $('cart-toast');
        if (!element) return;

        element.textContent = message;
        element.classList.add('show');
        clearTimeout(window.__cartToast);
        window.__cartToast = setTimeout(
            () => element.classList.remove('show'),
            1600
        );
    }

    function itemKey(item) {
        return String(item.key);
    }

    function orderableItems() {
        return items.filter(item => item.orderable !== false);
    }

    function setText(id, value) {
        const element = $(id);
        if (element) element.textContent = value;
    }

    function syncItems() {
        items = liveItems.filter(
            item =>
                item &&
                item.name &&
                Number(item.quantity) > 0
        );

        const keys = new Set(items.map(itemKey));
        selected = new Set(
            [...selected].filter(key => keys.has(key) && items.some(item => itemKey(item) === key && item.orderable !== false))
        );

        if (!selectionInitialized) {
            selected = new Set(orderableItems().map(itemKey));
            selectionInitialized = true;
        }
    }

    function syncChecks() {
        const all =
            orderableItems().length > 0 &&
            orderableItems().every(item => selected.has(itemKey(item)));
        const top = $('select-all-top');

        if (top) top.checked = all;
    }

    function totals() {
        const chosen = orderableItems().filter(item =>
            selected.has(itemKey(item))
        );
        const count = chosen.reduce(
            (sum, item) => sum + Number(item.quantity || 1),
            0
        );
        const total = chosen.reduce(
            (sum, item) =>
                sum +
                Number(item.price || 0) *
                Number(item.quantity || 1),
            0
        );

        setText(
            'cart-selected-count',
            `${count} ${count === 1 ? 'item' : 'items'}`
        );
        setText('summary-item-count', count);
        setText('summary-subtotal', money(total));
        setText('summary-shipping', money(0));
        setText('summary-shipping-discount', '− ₱0.00');
        setText('summary-voucher-discount', '− ₱0.00');
        setText('summary-total', money(total));
        setText('checkout-label', `Checkout (${count})`);

        const checkout = $('cart-checkout');
        if (checkout) checkout.disabled = count === 0;
    }

    function itemMeta(item) {
        return [
            item.variant_name,
            item.color ? `Color: ${item.color}` : '',
            item.size ? `Size: ${item.size}` : '',
        ]
            .filter(Boolean)
            .join(' · ');
    }

    function rowMarkup(item) {
        const key = itemKey(item);
        const meta = itemMeta(item);
        const quantity = Number(item.quantity || 1);

        return `
            <div class="cart-row${item.orderable === false ? ' is-unorderable' : ''}" data-key="${escapeHtml(key)}">
                <label class="check-wrap" aria-label="Select ${escapeHtml(item.name)}">
                    <input class="item-check" type="checkbox" ${selected.has(key) ? 'checked' : ''} ${item.orderable === false ? 'disabled' : ''}>
                    <span></span>
                </label>
                <div class="product-cell">
                    <div class="product-img">
                        <img src="${escapeHtml(imageUrl(item.image || item.photo))}" alt="${escapeHtml(item.name)}" loading="lazy" onerror="this.onerror=null;this.src='/images/bearly-logo.png'">
                    </div>
                    <div class="product-info">
                        <div class="product-name">${escapeHtml(item.name)}</div>
                        <div class="product-meta">${escapeHtml(meta || (item.source === 'preview' ? 'Catalog preview item' : 'Standard item'))}</div>
                        ${item.orderable === false ? `<div class="cart-item-unavailable" role="status">${escapeHtml(item.unavailable_reason || 'This item is not available with the currently supported Cash on Delivery checkout. Remove it before checkout.')}</div>` : ''}
                    </div>
                </div>
                <div class="unit-price"><span class="cart-field-label">Unit Price</span><strong>${money(item.price)}</strong></div>
                <div class="qty-control" aria-label="Quantity for ${escapeHtml(item.name)}">
                    <button type="button" data-minus aria-label="Decrease quantity" ${item.orderable === false ? 'disabled' : ''}>−</button>
                    <span>${quantity}</span>
                    <button type="button" data-plus aria-label="Increase quantity" ${item.orderable === false ? 'disabled' : ''}>+</button>
                </div>
                <div class="line-total"><span class="cart-field-label">Total Price</span><strong>${money(Number(item.price || 0) * quantity)}</strong></div>
                <div class="action-cell">
                    ${likeButtonMarkup(item)}
                    <button type="button" class="cart-remove" data-remove>Remove</button>
                </div>
            </div>
        `;
    }

    function groupedItems() {
        const groups = new Map();

        items.forEach(item => {
            const name = String(item.seller_name || 'Bearly Seller');

            if (!groups.has(name)) {
                groups.set(name, {
                    name,
                    items: [],
                });
            }

            groups.get(name).items.push(item);
        });

        return [...groups.values()];
    }

    function render() {
        syncItems();
        const previewNotice = $('cart-preview-notice');
        if (previewNotice) previewNotice.hidden = !hasPreviewItems();

        const quantity = items.reduce(
            (sum, item) => sum + Number(item.quantity || 1),
            0
        );
        const itemCount = items.length;
        const list = $('cart-list');

        setText(
            'cart-count',
            `${quantity} ${quantity === 1 ? 'item' : 'items'}`
        );
        const headerCount = $('cart-header-count');
        if (headerCount) {
            setText('cart-header-count', itemCount);
            headerCount.hidden = itemCount === 0;
            headerCount.setAttribute(
                'aria-label',
                `${itemCount} ${itemCount === 1 ? 'product' : 'products'} in cart`
            );
        }
        setText('select-all-label', `Select All (${orderableItems().length})`);

        $('cart-content').hidden = !items.length;
        $('cart-empty').hidden = Boolean(items.length);

        if (list) {
            list.innerHTML = groupedItems()
                .map(group => `
                    <section class="seller-card">
                        <div class="seller-head">
                            <span class="material-symbols-outlined" aria-hidden="true">storefront</span>
                            <strong>${escapeHtml(group.name)}</strong>
                        </div>
                        ${group.items.map(rowMarkup).join('')}
                    </section>
                `)
                .join('');
        }

        syncChecks();
        totals();
    }

    async function liveRequest(item, method, quantity) {
        const response = await fetch(
            `/cart/${encodeURIComponent(item.id)}`,
            {
                method,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body:
                    method === 'PATCH'
                        ? JSON.stringify({ quantity })
                        : undefined,
            }
        );
        const result = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(
                Object.values(result.errors || {}).flat()[0] ||
                result.message ||
                'Unable to update cart.'
            );
        }

        return result;
    }

    async function updateItem(item, quantity) {
        try {
            await liveRequest(item, 'PATCH', quantity);
            item.quantity = quantity;
            render();
            window.BearlyNavbarState?.sync?.();
        } catch (error) {
            toast(error.message);
        }
    }

    async function removeItem(item) {
        try {
            await liveRequest(item, 'DELETE');
            liveItems = liveItems.filter(
                value => itemKey(value) !== itemKey(item)
            );
            render();
            window.BearlyNavbarState?.sync?.();
            toast('Item removed');
        } catch (error) {
            toast(error.message);
        }
    }

    function updateLikeButton(button, item, liked) {
        if (!button) return;

        button.classList.toggle('is-added', liked);
        button.setAttribute('aria-pressed', String(liked));
        button.setAttribute(
            'aria-label',
            `${liked ? 'Remove' : 'Add'} ${item.name} ${liked ? 'from' : 'to'} My Likes`
        );

        const icon = button.querySelector('.material-symbols-outlined');
        const label = button.querySelector('.cart-like-label');

        if (icon) icon.textContent = liked ? 'favorite' : 'favorite_border';
        if (label) label.textContent = liked ? 'In My Likes' : 'Add to Likes';
    }

    async function addToLikes(item, button) {
        const productId = Number(item.product_id || 0);

        if (productId < 1) {
            toast('This product is not available in your wishlist.');
            return;
        }

        button.disabled = true;

        try {
            const response = await fetch('/wishlist/toggle', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ product_id: productId }),
            });
            const result = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(
                    Object.values(result.errors || {}).flat()[0] ||
                    result.message ||
                    'Unable to update My Likes.'
                );
            }

            const liked = Boolean(result.data?.is_wishlisted);
            if (liked) wishlistProductIds.add(productId);
            else wishlistProductIds.delete(productId);
            updateLikeButton(button, item, liked);
            toast(result.message || (liked ? 'Added to My Likes.' : 'Removed from My Likes.'));
        } catch (error) {
            toast(error.message || 'Unable to update My Likes.');
        } finally {
            button.disabled = false;
        }
    }

    $('cart-list').addEventListener('click', event => {
        const row = event.target.closest('.cart-row');
        if (!row) return;

        const item = items.find(
            value => itemKey(value) === row.dataset.key
        );
        if (!item) return;

        if (event.target.closest('[data-add-like]')) {
            addToLikes(item, event.target.closest('[data-add-like]'));
            return;
        }

        if (event.target.closest('[data-plus]')) {
            return updateItem(
                item,
                Number(item.quantity || 1) + 1
            );
        }

        if (event.target.closest('[data-minus]')) {
            return updateItem(
                item,
                Math.max(1, Number(item.quantity || 1) - 1)
            );
        }

        if (event.target.closest('[data-remove]')) {
            selected.delete(itemKey(item));
            return removeItem(item);
        }
    });

    $('cart-list').addEventListener('change', event => {
        if (!event.target.classList.contains('item-check')) return;

        const row = event.target.closest('.cart-row');
        if (!row) return;

        event.target.checked
            ? selected.add(row.dataset.key)
            : selected.delete(row.dataset.key);

        syncChecks();
        totals();
    });

    function selectAll(on) {
        selected = new Set(on ? orderableItems().map(itemKey) : []);
        selectionInitialized = true;
        render();
    }

    $('select-all-top').addEventListener('change', event =>
        selectAll(event.target.checked)
    );
    $('select-all-label').addEventListener('click', () =>
        selectAll(true)
    );

    $('delete-selected').addEventListener('click', async () => {
        const chosen = items.filter(item =>
            selected.has(itemKey(item))
        );

        if (!chosen.length) {
            toast('Select an item first');
            return;
        }

        await Promise.all(chosen.map(removeItem));
        toast('Selected items deleted');
    });

    const showVoucherMessage = () =>
        toast('Vouchers can be selected at checkout.');

    $('cart-vouchers')?.addEventListener('click', showVoucherMessage);
    $('cart-voucher-summary')?.addEventListener(
        'click',
        showVoucherMessage
    );

    $('cart-checkout').addEventListener('click', () => {
        const keys = items
            .filter(item => selected.has(itemKey(item)))
            .map(itemKey);

        if (!keys.length) {
            toast('Select at least one item');
            return;
        }

        try {
            localStorage.setItem(
                window.bearlyStorageKey?.('checkout-selection') ||
                'bearly-checkout-selection-v1',
                JSON.stringify(keys)
            );
        } catch {
            // Checkout can still open if browser storage is unavailable.
        }

        window.location.href = '/checkout';
    });

    render();

    window.addEventListener('storage', event => {
        if (event.key === PREVIEW_KEY) render();
    });
})();
