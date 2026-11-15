import { toggleBuyerWishlist } from './buyer-wishlist';

function announce(message) {
    const status = document.getElementById('flash-likes-status');
    if (status) {
        status.textContent = message;
        status.hidden = false;
    }
}

function initFlashDealLikes() {
    const grid = document.querySelector('[data-flash-deal-listing]');
    if (!grid || grid.dataset.likesInitialized === 'true') return;
    grid.dataset.likesInitialized = 'true';

    grid.addEventListener('click', async event => {
        const button = event.target.closest('[data-flash-wishlist]');
        if (!button) return;

        const productId = Number(button.dataset.productId || 0);
        const productName = button.dataset.productName || 'Product';
        if (!Number.isInteger(productId) || productId < 1) return;

        button.disabled = true;
        try {
            const result = await toggleBuyerWishlist(productId);
            button.classList.toggle('is-liked', result.is_wishlisted);
            button.setAttribute('aria-pressed', String(result.is_wishlisted));
            button.setAttribute('aria-label', `${result.is_wishlisted ? 'Remove' : 'Save'} ${productName} ${result.is_wishlisted ? 'from' : 'to'} My Likes`);
            announce(result.is_wishlisted ? `${productName} saved to My Likes.` : `${productName} removed from My Likes.`);
        } catch (error) {
            announce(error.message || 'Unable to update My Likes.');
        } finally {
            button.disabled = false;
        }
    });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initFlashDealLikes);
else initFlashDealLikes();
