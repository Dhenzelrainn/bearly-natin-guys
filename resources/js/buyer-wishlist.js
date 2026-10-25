function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

async function parseResponse(response, fallbackMessage) {
    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
        const validationMessage = Object.values(result.errors || {}).flat().find(Boolean);
        throw new Error(validationMessage || result.message || fallbackMessage);
    }

    return result;
}

export async function toggleBuyerWishlist(productId) {
    const id = Number(productId);
    if (!Number.isInteger(id) || id < 1) throw new Error('Choose a valid product before saving it.');

    const response = await fetch('/wishlist/toggle', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({ product_id: id }),
    });

    const result = await parseResponse(response, 'Unable to update My Likes.');
    return result.data;
}

export async function addBuyerProductToCart(productVariantId, quantity = 1) {
    const variantId = Number(productVariantId);
    if (!Number.isInteger(variantId) || variantId < 1) {
        throw new Error('This product has no currently orderable variation.');
    }

    const response = await fetch('/cart/add', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({ product_variant_id: variantId, quantity }),
    });

    const result = await parseResponse(response, 'Unable to add this product to your cart.');
    return result.data;
}
