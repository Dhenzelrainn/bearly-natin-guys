(() => {
    const root = document.querySelector('[data-bearly-assistant]');
    if (!root) return;

    const launcher = root.querySelector('[data-assistant-open]');
    const panel = root.querySelector('[data-assistant-panel]');
    const minimizeButton = root.querySelector('[data-assistant-minimize]');
    const closeButton = root.querySelector('[data-assistant-close]');
    const messages = root.querySelector('[data-assistant-messages]');
    const disclosure = root.querySelector('[data-assistant-disclosure]');
    const quickHelp = root.querySelector('[data-assistant-quick-help]');
    const form = root.querySelector('[data-assistant-form]');
    const input = root.querySelector('[data-assistant-input]');
    const sendButton = root.querySelector('[data-assistant-send]');
    const icon = root.querySelector('[data-assistant-icon]');
    const iconFallback = root.querySelector('[data-assistant-icon-fallback]');

    if (!launcher || !panel || !messages || !form || !input || !sendButton) return;

    let sending = false;

    const safeInternalUrl = (value) => {
        if (typeof value !== 'string' || value.trim() === '') return null;

        try {
            const url = new URL(value, window.location.origin);
            return url.origin === window.location.origin ? url.href : null;
        } catch {
            return null;
        }
    };

    const currency = (minor) => {
        const amount = Number(minor);
        if (!Number.isSafeInteger(amount) || amount < 0) return 'Price shown on listing';

        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
        }).format(amount / 100);
    };

    const scrollToLatest = () => {
        messages.scrollTop = messages.scrollHeight;
    };

    const addMessage = (text, kind = 'bot') => {
        const message = document.createElement('p');
        message.className = `bearly-assistant-message bearly-assistant-message--${kind}`;
        message.textContent = String(text ?? '');
        let entry = message;

        if (kind === 'user') {
            messages.append(message);
        } else {
            const row = document.createElement('div');
            row.className = 'bearly-assistant-message-row';

            const avatar = document.createElement('img');
            avatar.className = 'bearly-assistant-avatar';
            avatar.src = root.dataset.assistantAvatar;
            avatar.alt = 'Bearly assistant';
            avatar.width = 32;
            avatar.height = 32;
            avatar.addEventListener('error', () => avatar.remove(), { once: true });

            row.append(avatar, message);
            messages.append(row);
            entry = row;
        }

        scrollToLatest();
        return entry;
    };

    const addProducts = (products) => {
        if (!Array.isArray(products) || products.length === 0) return;

        const list = document.createElement('div');
        list.className = 'bearly-assistant-products';

        products.slice(0, 5).forEach((product) => {
            if (!product || typeof product !== 'object') return;

            const href = safeInternalUrl(product.url);
            const card = document.createElement(href ? 'a' : 'article');
            card.className = 'bearly-assistant-product';
            if (href) {
                card.href = href;
                card.setAttribute('aria-label', `View ${String(product.name ?? 'product')}`);
            }

            const imageUrl = safeInternalUrl(product.image_url);
            if (imageUrl) {
                const image = document.createElement('img');
                image.className = 'bearly-assistant-product-image';
                image.src = imageUrl;
                image.alt = '';
                image.loading = 'lazy';
                image.width = 54;
                image.height = 54;
                card.append(image);
            }

            const details = document.createElement('span');
            details.className = 'bearly-assistant-product-details';

            const name = document.createElement('strong');
            name.textContent = String(product.name ?? 'Bearly product');
            details.append(name);

            if (product.store) {
                const store = document.createElement('small');
                store.textContent = `Sold by ${String(product.store)}`;
                details.append(store);
            }

            const price = document.createElement('span');
            price.className = 'bearly-assistant-product-price';
            price.textContent = currency(product.price_minor);
            details.append(price);

            if (product.available === false) {
                const stock = document.createElement('small');
                stock.className = 'bearly-assistant-stock-note';
                stock.textContent = 'Currently out of stock';
                details.append(stock);
            }

            card.append(details);
            list.append(card);
        });

        if (list.childElementCount > 0) {
            messages.append(list);
            scrollToLatest();
        }
    };

    const setOpen = (open) => {
        panel.hidden = !open;
        panel.setAttribute('aria-hidden', String(!open));
        launcher.setAttribute('aria-expanded', String(open));
        if (open) input.focus();
        else launcher.focus();
    };

    const submitMessage = async () => {
        const message = input.value.trim();
        if (!message || sending) return;

        if (disclosure) disclosure.hidden = true;
        if (quickHelp) quickHelp.hidden = true;
        addMessage(message, 'user');
        input.value = '';
        sending = true;
        sendButton.disabled = true;
        input.disabled = true;
        messages.setAttribute('aria-busy', 'true');
        const loading = addMessage('Checking Bearly information…', 'loading');

        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
            const response = await fetch(root.dataset.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body: JSON.stringify({ message }),
            });
            const payload = await response.json().catch(() => null);
            loading.remove();

            if (!response.ok || typeof payload?.data?.answer !== 'string') {
                throw new Error('assistant_unavailable');
            }

            addMessage(payload.data.answer, 'bot');
            addProducts(payload.data.products);
        } catch {
            loading.remove();
            addMessage('I couldn’t get a reply just now. Please try again or use Contact Bearly Support below.', 'error');
        } finally {
            sending = false;
            sendButton.disabled = false;
            input.disabled = false;
            messages.removeAttribute('aria-busy');
            if (!panel.hidden) input.focus();
        }
    };

    launcher.addEventListener('click', () => setOpen(panel.hidden));
    minimizeButton?.addEventListener('click', () => setOpen(false));
    closeButton?.addEventListener('click', () => setOpen(false));
    messages.addEventListener('click', (event) => {
        const suggestion = event.target.closest('[data-assistant-suggestion]');
        if (!suggestion || !messages.contains(suggestion)) return;

        input.value = suggestion.dataset.assistantSuggestion ?? '';
        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        submitMessage();
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            form.requestSubmit();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) setOpen(false);
    });
    icon?.addEventListener('error', () => {
        icon.hidden = true;
        if (iconFallback) iconFallback.hidden = false;
    }, { once: true });
})();
