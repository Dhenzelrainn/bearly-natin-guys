(() => {
    const PREVIEW_KEY =
        window.bearlyStorageKey?.('preview-cart') ||
        'bearly-preview-cart-v1';
    const LIKES_KEY =
        window.bearlyStorageKey?.('preview-wishlist') ||
        'bearly-preview-wishlist-v1';

    const likeCategoryAliases = {
        'electronics-gadgets': 'electronics-and-gadgets',
        'womens-apparel': 'women-s-apparel',
        'mens-apparel': 'men-s-apparel',
        'kids-baby': 'kids-and-baby',
        'home-garden': 'home-and-garden',
        'sports-outdoors': 'sports-and-outdoors',
        'health-beauty': 'health-and-beauty',
        'books-media': 'books-and-media',
        'jewelry-watches': 'jewelry-and-watches',
        'foods-gourmet': 'food-and-gourmet',
        'furniture-office-equipment': 'furniture-and-office-equipment',
    };

    const $ = id => document.getElementById(id);

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

    function readLikes() {
        try {
            const value = JSON.parse(localStorage.getItem(LIKES_KEY) || '[]');

            return Array.isArray(value) ? value.map(String) : [];
        } catch {
            return [];
        }
    }

    function writeLikes(values) {
        try {
            localStorage.setItem(LIKES_KEY, JSON.stringify([...new Set(values)]));
            window.dispatchEvent(new CustomEvent('bearly:likes-changed'));
        } catch {
            // Likes are a browser-scoped preview feature.
        }
    }

    function categorySlug(value) {
        const normalized = String(value || '')
            .trim()
            .toLowerCase()
            .replace(/&/g, 'and')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-|-$/g, '');

        return likeCategoryAliases[normalized] || normalized;
    }

    function likeKeyForItem(item) {
        const candidates = [item.like_key, item.product_id, item.key]
            .map(value => String(value || '').replace(/^preview:/, '').replace(/^wishlist-/, ''))
            .filter(Boolean);

        for (const candidate of candidates) {
            const legacy = candidate.match(/^(?:home|flash|sales)-(.+)-(\d+)$/);

            if (legacy) return `${legacy[1]}:${legacy[2]}`;
            if (/^[^:]+:\d+$/.test(candidate)) return candidate;

            if (/^\d+$/.test(candidate) && item.category) {
                return `${categorySlug(item.category)}:${candidate}`;
            }
        }

        return candidates[0] || '';
    }

    function likeButtonMarkup(item) {
        const key = likeKeyForItem(item);
        const isLiked = key ? readLikes().includes(key) : false;

        return `
            <button type="button" class="cart-like${isLiked ? ' is-added' : ''}" data-add-like aria-pressed="${isLiked ? 'true' : 'false'}" aria-label="${isLiked ? 'Already added to' : 'Add'} ${escapeHtml(item.name)} ${isLiked ? 'in' : 'to'} My Likes">
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

    let previewItems = readPreview();
    let items = [];
    let selected = new Set();
    let selectionInitialized = false;

    function readPreview() {
        try {
            const value = JSON.parse(
                localStorage.getItem(PREVIEW_KEY) || '[]'
            );

            return Array.isArray(value)
                ? value.map(item => ({
                    ...item,
                    source: 'preview',
                    key: `preview:${item.key || item.product_id}`,
                }))
                : [];
        } catch {
            return [];
        }
    }

    function writePreview() {
        try {
            localStorage.setItem(
                PREVIEW_KEY,
                JSON.stringify(
                    previewItems.map(({ source, key, ...item }) => ({
                        ...item,
                        key: String(
                            key || item.product_id || ''
                        ).replace(/^preview:/, ''),
                    }))
                )
            );
        } catch {
            // Local storage is optional for preview items.
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

    function setText(id, value) {
        const element = $(id);
        if (element) element.textContent = value;
    }

    function syncItems() {
        items = [...liveItems, ...previewItems].filter(
            item =>
                item &&
                item.name &&
                Number(item.quantity) > 0
        );

        const keys = new Set(items.map(itemKey));
        selected = new Set(
            [...selected].filter(key => keys.has(key))
        );

        if (!selectionInitialized) {
            selected = new Set(items.map(itemKey));
            selectionInitialized = true;
        }
    }

    function syncChecks() {
        const all =
            items.length > 0 &&
            items.every(item => selected.has(itemKey(item)));
        const top = $('select-all-top');

        if (top) top.checked = all;
    }

    function totals() {
        const chosen = items.filter(item =>
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
            <div class="cart-row" data-key="${escapeHtml(key)}">
                <label class="check-wrap" aria-label="Select ${escapeHtml(item.name)}">
                    <input class="item-check" type="checkbox" ${selected.has(key) ? 'checked' : ''}>
                    <span></span>
                </label>
                <div class="product-cell">
                    <div class="product-img">
                        <img src="${escapeHtml(imageUrl(item.image || item.photo))}" alt="${escapeHtml(item.name)}" loading="lazy" onerror="this.onerror=null;this.src='/images/bearly-logo.png'">
                    </div>
                    <div class="product-info">
                        <div class="product-name">${escapeHtml(item.name)}</div>
                        <div class="product-meta">${escapeHtml(meta || (item.source === 'preview' ? 'Catalog preview item' : 'Standard item'))}</div>
                    </div>
                </div>
                <div class="unit-price"><span class="cart-field-label">Unit Price</span><strong>${money(item.price)}</strong></div>
                <div class="qty-control" aria-label="Quantity for ${escapeHtml(item.name)}">
                    <button type="button" data-minus aria-label="Decrease quantity">−</button>
                    <span>${quantity}</span>
                    <button type="button" data-plus aria-label="Increase quantity">+</button>
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

        const quantity = items.reduce(
            (sum, item) => sum + Number(item.quantity || 1),
            0
        );
        const list = $('cart-list');

        setText(
            'cart-count',
            `${quantity} ${quantity === 1 ? 'item' : 'items'}`
        );
        const headerCount = $('cart-header-count');
        if (headerCount) {
            setText('cart-header-count', quantity);
            headerCount.hidden = quantity === 0;
            headerCount.setAttribute(
                'aria-label',
                `${quantity} cart ${quantity === 1 ? 'item' : 'items'}`
            );
        }
        setText('select-all-label', `Select All (${items.length})`);

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
        if (item.source === 'preview') {
            item.quantity = quantity;
            writePreview();
            render();
            return;
        }

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
        if (item.source === 'preview') {
            previewItems = previewItems.filter(
                value => itemKey(value) !== itemKey(item)
            );
            writePreview();
            render();
            toast('Item removed');
            return;
        }

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
            `${liked ? 'Already added to' : 'Add'} ${item.name} ${liked ? 'in' : 'to'} My Likes`
        );

        const icon = button.querySelector('.material-symbols-outlined');
        const label = button.querySelector('.cart-like-label');

        if (icon) icon.textContent = liked ? 'favorite' : 'favorite_border';
        if (label) label.textContent = liked ? 'In My Likes' : 'Add to Likes';
    }

    function addToLikes(item, button) {
        const key = likeKeyForItem(item);

        if (!key) {
            toast('This item cannot be added to My Likes.');
            return;
        }

        const saved = readLikes();

        if (saved.includes(key)) {
            updateLikeButton(button, item, true);
            toast('This item is already in My Likes.');
            return;
        }

        writeLikes([...saved, key]);
        updateLikeButton(button, item, true);
        toast('Added to My Likes.');
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
        selected = new Set(on ? items.map(itemKey) : []);
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
        if (event.key === PREVIEW_KEY) {
            previewItems = readPreview();
            render();
        }
    });
})();
