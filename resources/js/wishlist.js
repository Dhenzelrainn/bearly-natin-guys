import { addBuyerProductToCart, toggleBuyerWishlist } from './buyer-wishlist';

function announce(message) {
    const status = document.getElementById('wl-status');
    if (status) {
        status.textContent = message;
        status.hidden = false;
    }
}

function updateEmptyState() {
    const grid = document.getElementById('wl-grid');
    const empty = document.getElementById('wl-empty');
    const count = document.getElementById('wl-count');
    const cards = grid ? [...grid.querySelectorAll('[data-wishlist-card]')] : [];

    if (grid) grid.hidden = cards.length === 0;
    if (empty) empty.hidden = cards.length > 0;
    if (count) count.textContent = String(cards.length);
}

function initWishlist() {
    const grid = document.getElementById('wl-grid');
    if (!grid || grid.dataset.initialized === 'true') return;
    grid.dataset.initialized = 'true';

    grid.addEventListener('click', async event => {
        const card = event.target.closest('[data-wishlist-card]');
        if (!card) return;

        const removeButton = event.target.closest('[data-wishlist-remove]');
        if (removeButton) {
            removeButton.disabled = true;
            try {
                const result = await toggleBuyerWishlist(card.dataset.productId);
                if (!result.is_wishlisted) {
                    const name = card.dataset.productName || 'Product';
                    card.remove();
                    updateEmptyState();
                    announce(`${name} removed from your wishlist.`);
                }
            } catch (error) {
                removeButton.disabled = false;
                announce(error.message || 'Unable to remove this product from your wishlist.');
            }
            return;
        }

        const cartButton = event.target.closest('[data-wishlist-add]');
        if (!cartButton || cartButton.disabled) return;
        cartButton.disabled = true;
        try {
            const result = await addBuyerProductToCart(card.dataset.variantId, 1);
            const name = card.dataset.productName || 'Product';
            announce(`${name} added to your cart.`);
            cartButton.textContent = 'Added to Cart';
            window.setTimeout(() => {
                if (cartButton.isConnected) cartButton.textContent = 'Add to Cart';
            }, 1200);
            window.bearlyBuyerProfile = {
                ...(window.bearlyBuyerProfile || {}),
                cartCount: Number(result?.cart_count ?? window.bearlyBuyerProfile?.cartCount ?? 0),
            };
            window.BearlyNavbarState?.sync?.();
        } catch (error) {
            announce(error.message || 'Unable to add this product to your cart.');
        } finally {
            cartButton.disabled = false;
        }
    });

    updateEmptyState();
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initWishlist);
else initWishlist();
