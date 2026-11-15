const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

export function storeVoucherClaimPayload(storePromotionId) {
    const id = Number(storePromotionId);
    if (!Number.isInteger(id) || id < 1) {
        throw new Error('Choose a valid store voucher.');
    }

    return { store_promotion_id: id };
}

export async function claimStoreVoucher(storePromotionId, endpoint) {
    const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify(storeVoucherClaimPayload(storePromotionId)),
    });
    const result = await response.json().catch(() => ({}));

    if (!response.ok) {
        const validationMessage = Object.values(result.errors || {}).flat().find(Boolean);
        throw new Error(validationMessage || result.message || 'Unable to claim this voucher.');
    }

    return result;
}

export function markStoreVoucherClaimed(card, message = '') {
    if (!card) return;

    card.dataset.claimed = 'true';
    const button = card.querySelector('[data-store-voucher-claim]');
    const action = button?.parentElement;

    if (button && action) {
        const state = document.createElement('span');
        state.className = 'store-voucher-state';
        state.textContent = 'Claimed';
        button.replaceWith(state);
    }

    const feedback = card.querySelector('[data-store-voucher-feedback]');
    if (feedback) {
        feedback.textContent = message || 'Voucher added to My Vouchers.';
        feedback.dataset.tone = 'success';
    }
}

export function initStoreVouchers(root = document) {
    const container = root.querySelector('[data-store-vouchers]');
    if (!container) return;

    const endpoint = container.dataset.storeVoucherClaimUrl;
    if (!endpoint) return;

    container.addEventListener('click', async event => {
        const button = event.target.closest?.('[data-store-voucher-claim]');
        if (!button || button.disabled) return;

        const card = button.closest('[data-store-voucher]');
        const id = Number(button.dataset.storePromotionId || card?.dataset.storeVoucherId || 0);
        if (!id) return;

        const feedback = card?.querySelector('[data-store-voucher-feedback]');
        const originalLabel = button.textContent;

        button.disabled = true;
        button.textContent = 'Claiming…';
        if (feedback) {
            feedback.textContent = '';
            feedback.dataset.tone = '';
        }

        try {
            const result = await claimStoreVoucher(id, endpoint);
            markStoreVoucherClaimed(card, result.message || '');
            window.dispatchEvent(new CustomEvent('bearly:vouchers-changed'));
        } catch (error) {
            button.disabled = false;
            button.textContent = originalLabel;
            if (feedback) {
                feedback.textContent = error.message || 'Unable to claim this voucher.';
                feedback.dataset.tone = 'error';
            }
        }
    });
}
