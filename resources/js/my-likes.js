import { addBuyerProductToCart, toggleBuyerWishlist } from './buyer-wishlist';

const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
}[character]));

const money = minor => '₱' + (Number(minor || 0) / 100).toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

let liveLikes = Array.isArray(window.bearlyLiveWishlist) ? [...window.bearlyLiveWishlist] : [];
let activeCategory = 'all';
let sortMode = 'recent';

function categoryKey(item) {
    return String(item.category_slug || item.category || 'Other');
}

function categoryLabel(item) {
    return String(item.category || item.category_slug || 'Other');
}

function renderTabs() {
    const tabs = document.getElementById('likes-category-tabs');
    if (!tabs) return;

    const categories = [...new Map(liveLikes.map(item => [categoryKey(item), categoryLabel(item)])).entries()];
    const options = [['all', 'All liked items'], ...categories];
    if (!options.some(([key]) => key === activeCategory)) activeCategory = 'all';

    tabs.innerHTML = options.map(([key, label]) => `
        <button
            type="button"
            class="likes-category-tab${activeCategory === key ? ' is-active' : ''}${key === 'all' ? ' likes-all-tab' : ''}"
            data-likes-category="${escapeHtml(key)}"
            role="tab"
            aria-selected="${activeCategory === key}"
        >${escapeHtml(label)}</button>
    `).join('');
}

function sortedItems(items) {
    const rows = [...items];
    if (sortMode === 'price-low') rows.sort((a, b) => Number(a.price_minor || 0) - Number(b.price_minor || 0));
    if (sortMode === 'price-high') rows.sort((a, b) => Number(b.price_minor || 0) - Number(a.price_minor || 0));
    if (sortMode === 'name') rows.sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
    // The server supplies wishlist rows newest-first for the default order.
    return rows;
}

function renderCard(item) {
    const productId = Number(item.product_id || 0);
    const variantId = Number(item.product_variant_id || 0);
    const hasStock = Number(item.stock || 0) > 0;
    const canPurchase = item.orderable === true && hasStock && variantId > 0;
    const image = String(item.image || '/images/bearly-logo.png');
    const name = String(item.name || 'Product');
    const status = item.orderable !== true
        ? 'Saved · unavailable with Cash on Delivery checkout'
        : !hasStock || !variantId
            ? 'Saved · currently out of stock'
            : 'Saved to your account';
    const nameMarkup = canPurchase
        ? `<a class="like-product-link" href="/products/${productId}"><h3 title="${escapeHtml(name)}">${escapeHtml(name)}</h3></a>`
        : `<h3 title="${escapeHtml(name)}">${escapeHtml(name)}</h3>`;

    return `
        <article class="like-card" data-live-like-card data-product-id="${productId}">
            <div class="like-image">
                <img src="${escapeHtml(image)}" alt="${escapeHtml(name)}" loading="lazy">
                <button type="button" class="like-remove" data-like-remove aria-label="Remove ${escapeHtml(name)} from My Likes">
                    <span class="material-symbols-outlined" aria-hidden="true">favorite</span>
                </button>
            </div>
            <div class="like-copy">
                <span class="like-category">${escapeHtml(categoryLabel(item))}</span>
                ${nameMarkup}
                <strong class="like-price">${money(item.price_minor)}</strong>
                <p class="like-orderability">${escapeHtml(status)}</p>
                <button
                    type="button"
                    class="like-cart"
                    data-like-cart
                    ${canPurchase ? '' : 'disabled'}
                    aria-label="${canPurchase ? `Add ${escapeHtml(name)} to cart` : escapeHtml(status)}"
                >${canPurchase ? 'Add to Cart' : item.orderable === true ? 'Out of stock' : 'Unavailable'}</button>
            </div>
        </article>
    `;
}

function setStatus(message) {
    const status = document.getElementById('likes-status');
    if (status) {
        status.textContent = message;
        status.hidden = false;
    }
}

function renderMyLikes() {
    const grid = document.getElementById('likes-grid');
    const empty = document.getElementById('likes-empty');
    const count = document.getElementById('likes-count');
    const navCount = document.getElementById('likes-nav-count');
    if (!grid || !empty) return;

    const filtered = liveLikes.filter(item => activeCategory === 'all' || categoryKey(item) === activeCategory);
    const items = sortedItems(filtered);
    renderTabs();

    if (count) count.textContent = `${liveLikes.length} liked item${liveLikes.length === 1 ? '' : 's'}`;
    if (navCount) {
        navCount.textContent = String(liveLikes.length);
        navCount.hidden = liveLikes.length === 0;
    }

    grid.innerHTML = items.map(renderCard).join('');
    grid.hidden = items.length === 0;
    empty.hidden = items.length > 0;

    const title = document.getElementById('likes-empty-title');
    const copy = document.getElementById('likes-empty-copy');
    if (title) title.textContent = activeCategory === 'all' ? 'No liked products yet' : 'No liked products in this category';
    if (copy) copy.textContent = activeCategory === 'all'
        ? 'Save a live product from the Bearly catalog and it will appear here.'
        : 'Try another category or save a live product in this category.';
}

function initMyLikes() {
    const tabs = document.getElementById('likes-category-tabs');
    const grid = document.getElementById('likes-grid');
    const sort = document.getElementById('likes-sort');
    if (!tabs || !grid || window.bearlyLikesControlsInitialized) return;
    window.bearlyLikesControlsInitialized = true;

    tabs.addEventListener('click', event => {
        const tab = event.target.closest('[data-likes-category]');
        if (!tab) return;
        activeCategory = tab.dataset.likesCategory || 'all';
        renderMyLikes();
    });

    sort?.addEventListener('change', () => {
        sortMode = sort.value || 'recent';
        renderMyLikes();
    });

    grid.addEventListener('click', async event => {
        const card = event.target.closest('[data-live-like-card]');
        if (!card) return;
        const productId = Number(card.dataset.productId || 0);
        const item = liveLikes.find(like => Number(like.product_id) === productId);
        if (!item) return;

        const removeButton = event.target.closest('[data-like-remove]');
        if (removeButton) {
            removeButton.disabled = true;
            try {
                const result = await toggleBuyerWishlist(productId);
                if (!result.is_wishlisted) {
                    liveLikes = liveLikes.filter(like => Number(like.product_id) !== productId);
                    setStatus(`${item.name} removed from My Likes.`);
                    renderMyLikes();
                }
            } catch (error) {
                removeButton.disabled = false;
                setStatus(error.message || 'Unable to remove this product from My Likes.');
            }
            return;
        }

        const cartButton = event.target.closest('[data-like-cart]');
        if (!cartButton || cartButton.disabled) return;
        cartButton.disabled = true;
        try {
            const result = await addBuyerProductToCart(item.product_variant_id, 1);
            window.bearlyBuyerProfile = {
                ...(window.bearlyBuyerProfile || {}),
                cartCount: Number(result?.cart_count ?? window.bearlyBuyerProfile?.cartCount ?? 0),
            };
            window.BearlyNavbarState?.sync?.();
            setStatus(`${item.name} added to your cart.`);
            cartButton.textContent = 'Added to Cart';
            window.setTimeout(() => {
                if (cartButton.isConnected) cartButton.textContent = 'Add to Cart';
            }, 1200);
        } catch (error) {
            setStatus(error.message || 'Unable to add this product to your cart.');
        } finally {
            cartButton.disabled = false;
        }
    });

    window.renderMyLikes = renderMyLikes;
    renderMyLikes();
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initMyLikes);
else initMyLikes();
