// Standalone landing interactions. Frontend preview only.
const escapeHtml = value => String(value).replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
})[char]);
const peso = value => new Intl.NumberFormat('en-PH', {
    style: 'currency', currency: 'PHP', maximumFractionDigits: 0,
}).format(value);

if (typeof document !== 'undefined' && document.body.classList.contains('bl')) {
    initializeBearlyLanding();
}

function initializeBearlyLanding() {
    const products = JSON.parse(document.getElementById('bl-featured-data').textContent);
    const dialog = document.getElementById('bl-dialog');
    const title = document.getElementById('bl-dialog-title');
    const content = document.getElementById('bl-dialog-content');
    const storageKey = 'bearly-homepage-saved-v1';
    let saved = [];
    let previousFocus;
    let toastTimer;
    try {
        const value = JSON.parse(localStorage.getItem(storageKey) || '[]');
        if (Array.isArray(value)) saved = value.filter(id => Number.isInteger(id) && products[id]);
    } catch { /* Saving still works for this page session if storage is blocked. */ }
    function notify(message) {
        const toast = document.getElementById('bl-toast');
        toast.textContent = message;
        toast.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { toast.hidden = true; }, 3000);
    }
    function syncSaved() {
        document.querySelectorAll('[data-bl-save]').forEach(button => {
            const id = Number(button.dataset.blSave);
            const selected = saved.includes(id);
            button.setAttribute('aria-pressed', String(selected));
            button.setAttribute('aria-label', `${selected ? 'Unsave' : 'Save'} ${products[id].name}`);
        });
    }
    function open(titleText, html) {
        if (!dialog.open) previousFocus = document.activeElement;
        title.textContent = titleText;
        content.innerHTML = html;
        if (!dialog.open) dialog.showModal();
    }
    function showProduct(id) {
        const product = products[id];
        if (!product) return;
        const source = document.querySelector(`[data-bl-product="${id}"] .bl-catalog-image`);
        const shopUrl = new URL(document.querySelector('.bl-search').action);
        shopUrl.searchParams.set('category', product.category);
        open(product.name, `<div class="bl-detail-photo"></div>
            <p class="bl-price">${peso(product.price)}</p>
            <p>${escapeHtml(product.shop)} · ★ ${escapeHtml(product.rating)}</p>
            <p>Sample product preview. Final specifications, availability, delivery estimates, and seller details will come from the seller’s listing.</p>
            <button class="bl-button" data-bl-save="${id}">${saved.includes(id) ? 'Remove from saved' : 'Save this product'}</button>
            <a class="bl-button bl-outline" href="${escapeHtml(shopUrl.href)}">Browse category</a>`);
        content.querySelector('.bl-detail-photo').append(source.cloneNode(true));
        syncSaved();
    }
    document.addEventListener('click', event => {
        const saveButton = event.target.closest('[data-bl-save]');
        if (saveButton) {
            const id = Number(saveButton.dataset.blSave);
            if (!products[id]) return;
            const wasSaved = saved.includes(id);
            saved = wasSaved ? saved.filter(value => value !== id) : [...saved, id];
            try { localStorage.setItem(storageKey, JSON.stringify(saved)); } catch { /* Session fallback. */ }
            syncSaved();
            if (saveButton.classList.contains('bl-button')) saveButton.textContent = wasSaved ? 'Save this product' : 'Remove from saved';
            notify(wasSaved ? 'Removed from saved products' : 'Saved on this device');
            return;
        }
        const productButton = event.target.closest('[data-bl-product]');
        if (productButton) { showProduct(Number(productButton.dataset.blProduct)); return; }
        if (event.target.closest('[data-bl-wishlist]')) {
            open('Saved products', saved.length
                ? `<div class="bl-saved-list">${saved.map(id => `<button data-bl-product="${id}">${escapeHtml(products[id].name)} — ${peso(products[id].price)}</button>`).join('')}</div><p>Saved on this device.</p>`
                : '<p>No saved products yet. Tap a heart on a product to save it here.</p>');
            return;
        }
        const infoButton = event.target.closest('[data-bl-info]');
        if (infoButton) {
            const messages = {
                cart: ['Your cart', 'Your cart is empty. Shopping and checkout will be available when the marketplace launches. For now, you can browse and save products.'],
                privacy: ['Privacy Policy', 'The marketplace privacy policy will be published before launch. This homepage preview saves only your selected products in this browser; the newsletter form does not send or store your email.'],
                terms: ['Terms of Service', 'The marketplace terms of service will be published before launch. Products, ratings, prices, and delivery estimates shown here are sample content.'],
            };
            const message = messages[infoButton.dataset.blInfo];
            if (message) open(message[0], `<p>${escapeHtml(message[1])}</p>`);
        }
    });
    dialog.querySelector('.bl-dialog-close').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => {
        if (event.target !== dialog) return;
        const rect = dialog.getBoundingClientRect();
        if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
    });
    dialog.addEventListener('close', () => previousFocus?.focus());
    document.getElementById('bl-newsletter').addEventListener('submit', event => {
        event.preventDefault();
        document.getElementById('bl-newsletter-status').textContent = 'Newsletter sign-ups are coming soon. Your email has not been submitted.';
    });
    syncSaved();
}
