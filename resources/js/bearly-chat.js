(() => {
    const KEY = window.bearlyStorageKey?.('preview-chat') || 'bearly-chat-v1';
    const buyerName = window.bearlyBuyerProfile?.fullName || 'there';

    const conversations = [
        {
            id: 'sundays',
            name: 'Sundays Market',
            subtitle: 'Seller',
            location: 'Laguna',
            avatar: 'SM',
            tone: 'sundays',
            preview: 'Thanks for your order!',
            time: '2:15 PM',
            unread: 2,
            seller: true,
            online: true,
            dayLabel: 'Yesterday 6:42 PM',
            shopUrl: '/home#results',
        },
        {
            id: 'greenline',
            name: 'Greenline Home',
            subtitle: 'Seller',
            location: 'Quezon City',
            avatar: '🌱',
            tone: 'greenline',
            preview: 'Your desk lamp is on the way.',
            time: '11:23 AM',
            unread: 1,
            seller: true,
            online: true,
            dayLabel: 'Today',
            shopUrl: '/home#results',
        },
        {
            id: 'bearly',
            name: 'Bearly Official Store',
            subtitle: 'Official store',
            location: 'Bearly',
            avatar: '🧸',
            tone: 'bearly',
            preview: 'Hi there! How can I help you?',
            time: '10:05 AM',
            unread: 0,
            assistant: true,
            online: true,
            dayLabel: 'Today',
        },
        {
            id: 'techworld',
            name: 'TechWorld PH',
            subtitle: 'Seller',
            location: 'Manila',
            avatar: '◉',
            tone: 'techworld',
            preview: 'Item is available po.',
            time: 'Yesterday',
            unread: 1,
            seller: true,
            online: false,
            dayLabel: 'Yesterday',
            shopUrl: '/home#results',
        },
        {
            id: 'cozy',
            name: 'Cozy Living',
            subtitle: 'Seller',
            location: 'Cebu',
            avatar: '🪑',
            tone: 'cozy',
            preview: 'Thank you!',
            time: 'Yesterday',
            unread: 0,
            seller: true,
            online: false,
            dayLabel: 'Yesterday',
            shopUrl: '/home#results',
        },
        {
            id: 'pet-supplies',
            name: 'Pet Supplies PH',
            subtitle: 'Seller',
            location: 'Pasig',
            avatar: '🐾',
            tone: 'pets',
            preview: 'Yes po, available po.',
            time: 'Jun 10',
            unread: 0,
            seller: true,
            online: false,
            dayLabel: 'Jun 10',
            shopUrl: '/home#results',
        },
    ];

    const quick = [
        ['Track my order', 'track'],
        ['My vouchers', 'vouchers'],
        ['Shipping', 'shipping'],
        ['Payments', 'payments'],
        ['Returns & refunds', 'returns'],
        ['Account help', 'account'],
    ];

    const defaults = {
        active: 'sundays',
        messages: {
            bearly: [
                {
                    from: 'them',
                    text: `Hi ${buyerName}! I’m the Bearly Assistant. I can help with orders, shipping, payments, vouchers, returns, and your account.`,
                    time: 'Now',
                },
            ],
            greenline: [
                { from: 'them', text: 'Your desk lamp has been handed to the courier.', time: '11:20 AM' },
                { from: 'me', text: 'Great, thank you for the update!', time: '11:23 AM' },
            ],
            sundays: [
                { from: 'them', text: 'Thanks for your order!\nLet us know if you need anything.', time: '6:42 PM' },
                { from: 'me', text: 'Hi! Is this still available?', time: '6:45 PM' },
                { from: 'them', text: 'Yes po, available po. You can place your order anytime.', time: '6:46 PM' },
                {
                    from: 'them',
                    text: '',
                    time: '6:46 PM',
                    product: {
                        title: 'Dog Food',
                        price: '₱699',
                        status: 'In Stock',
                        image: '/images/products/foods-gourmet/foods-gourmet-07-01.jpg',
                        href: '/home#results',
                    },
                },
                { from: 'me', text: 'Okay. Thank you!', time: '6:47 PM' },
            ],
            techworld: [
                { from: 'them', text: 'Item is available po.', time: 'Yesterday' },
            ],
            cozy: [
                { from: 'them', text: 'Thank you!', time: 'Yesterday' },
            ],
            'pet-supplies': [
                { from: 'them', text: 'Yes po, available po.', time: 'Jun 10' },
            ],
        },
        unread: {},
    };

    function clone(value) {
        try {
            return structuredClone(value);
        } catch {
            return JSON.parse(JSON.stringify(value));
        }
    }

    function load() {
        try {
            const saved = JSON.parse(localStorage.getItem(KEY) || 'null');
            if (!saved || !saved.messages) return clone(defaults);

            return {
                ...clone(defaults),
                ...saved,
                messages: { ...clone(defaults.messages), ...saved.messages },
                unread: { ...clone(defaults.unread), ...(saved.unread || {}) },
            };
        } catch {
            return clone(defaults);
        }
    }

    let state = load();

    function save() {
        try {
            localStorage.setItem(KEY, JSON.stringify(state));
        } catch {
            // Preview chat state is best-effort when browser storage is unavailable.
        }
    }

    function unreadCount(conversation) {
        return Number(state.unread?.[conversation.id] ?? conversation.unread ?? 0);
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        }[character]));
    }

    function safeImage(source) {
        const value = String(source || '');
        return /^(\/images\/|\/storage\/)/.test(value) ? value : '/images/bearly-logo.png';
    }

    function safeHref(source) {
        const value = String(source || '');
        return /^\/(?!\/)/.test(value) ? value : '/home#results';
    }

    function answer(text) {
        const query = text.toLowerCase();
        if (/track|where.*order|order status/.test(query)) {
            return ['Open My Purchases, choose the relevant order, then select Track Order to view its delivery timeline.', '/profile#purchases', 'View My Purchases'];
        }
        if (/voucher|discount|coupon/.test(query)) {
            return ['Your claimed and available vouchers are in My Vouchers. You can also apply eligible vouchers during checkout.', '/profile#vouchers', 'Open My Vouchers'];
        }
        if (/ship|delivery|courier/.test(query)) {
            return ['Open My Purchases and choose Track Order on the specific purchase you want to follow.', '/profile#purchases', 'View My Purchases'];
        }
        if (/payment|pay|gcash|maya|card|cod/.test(query)) {
            return ['Bearly currently uses Cash on Delivery (COD) for buyer orders. Pay with cash when your order is delivered.', '/checkout', 'Go to Checkout'];
        }
        if (/return|refund|cancel/.test(query)) {
            return ['For returns, refunds, or cancellation questions, check the Help Center first and review the status of the related purchase.', '/profile#help', 'Open Help Center'];
        }
        if (/address|account|profile/.test(query)) {
            return ['You can update your profile and saved delivery addresses from Buyer Account.', '/profile#addresses', 'Manage Addresses'];
        }
        if (/help|support/.test(query)) {
            return ['I can help with orders, shipping, payments, vouchers, returns, and account settings. Pick a quick option below or type your question.', '/profile#help', 'Open Help Center'];
        }
        return ['I can help with Bearly shopping questions. Try asking about your order, shipping, payment, vouchers, returns, or account.', null, null];
    }

    function conversationMatches(conversation, root) {
        const search = (root.querySelector('[data-chat-search]')?.value || '').trim().toLowerCase();
        const filter = root.dataset.chatFilter || 'all';
        const searchable = `${conversation.name} ${conversation.subtitle} ${conversation.preview}`.toLowerCase();
        const matchesSearch = !search || searchable.includes(search);
        const matchesFilter = filter === 'all'
            || (filter === 'unread' && unreadCount(conversation) > 0)
            || (filter === 'sellers' && conversation.seller);

        return matchesSearch && matchesFilter;
    }

    function renderList(root) {
        const list = root.querySelector('[data-chat-list]');
        if (!list) return;

        const visible = conversations.filter(conversation => conversationMatches(conversation, root));
        list.innerHTML = visible.map(conversation => {
            const unread = unreadCount(conversation);

            return `
                <button
                    type="button"
                    class="bearly-chat-item ${conversation.assistant ? 'is-assistant' : ''} ${state.active === conversation.id ? 'is-active' : ''}"
                    data-chat-id="${escapeHtml(conversation.id)}"
                    aria-pressed="${state.active === conversation.id}"
                >
                    <span class="bearly-chat-avatar bearly-chat-avatar-${escapeHtml(conversation.tone)}" aria-hidden="true">${escapeHtml(conversation.avatar)}</span>
                    <span class="bearly-chat-item-copy">
                        <span class="bearly-chat-item-meta">
                            <strong>${escapeHtml(conversation.name)}</strong>
                            <time>${escapeHtml(conversation.time)}</time>
                        </span>
                        <small>${escapeHtml(conversation.preview)}</small>
                    </span>
                    ${unread ? `<b class="bearly-chat-unread-count" aria-label="${unread} unread messages">${unread}</b>` : ''}
                </button>
            `;
        }).join('');

        const empty = root.querySelector('[data-chat-list-empty]');
        if (empty) empty.hidden = visible.length !== 0;

        const unreadCountLabel = root.querySelector('[data-chat-unread-count]');
        if (unreadCountLabel) {
            unreadCountLabel.textContent = conversations.filter(conversation => unreadCount(conversation) > 0).length;
        }

        root.querySelectorAll('[data-chat-filter]').forEach(button => {
            const active = button.dataset.chatFilter === (root.dataset.chatFilter || 'all');
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
    }

    function renderCompactHeader(head, conversation) {
        head.innerHTML = `
            <span class="bearly-chat-avatar bearly-chat-avatar-${escapeHtml(conversation.tone)}">${escapeHtml(conversation.avatar)}</span>
            <div>
                <strong>${escapeHtml(conversation.name)}</strong>
                <small>${escapeHtml(conversation.subtitle)}${conversation.assistant ? ' · Always pinned' : ''}</small>
            </div>
        `;
    }

    function renderPageHeader(head, conversation) {
        const status = conversation.online ? 'Online' : 'Away';
        const shopAction = conversation.seller
            ? `<a class="bearly-chat-shop-link" href="${escapeHtml(safeHref(conversation.shopUrl))}"><span class="material-symbols-outlined" aria-hidden="true">storefront</span><span>View Shop</span></a>`
            : '';

        head.innerHTML = `
            <div class="bearly-chat-thread-person">
                <span class="bearly-chat-avatar bearly-chat-avatar-${escapeHtml(conversation.tone)}">${escapeHtml(conversation.avatar)}</span>
                <div>
                    <strong>${escapeHtml(conversation.name)} <span class="material-symbols-outlined bearly-chat-name-arrow" aria-hidden="true">chevron_right</span></strong>
                    <small><span class="bearly-chat-online-dot ${conversation.online ? '' : 'is-away'}"></span>${status}${conversation.location ? ` <i>·</i> ${escapeHtml(conversation.location)}` : ''}</small>
                </div>
            </div>
            <div class="bearly-chat-thread-actions">
                ${shopAction}
                <button type="button" aria-label="More conversation options" title="More options"><span class="material-symbols-outlined" aria-hidden="true">more_vert</span></button>
            </div>
        `;
    }

    function renderProduct(product) {
        return `
            <article class="bearly-chat-product-card">
                <img src="${escapeHtml(safeImage(product.image))}" alt="${escapeHtml(product.title)}" loading="lazy">
                <div class="bearly-chat-product-copy">
                    <strong>${escapeHtml(product.title)}</strong>
                    <b>${escapeHtml(product.price)}</b>
                    <span>${escapeHtml(product.status || 'Available')}</span>
                </div>
                <a href="${escapeHtml(safeHref(product.href))}">
                    View Product
                    <span class="material-symbols-outlined" aria-hidden="true">open_in_new</span>
                </a>
            </article>
        `;
    }

    function renderPageMessages(messages, conversation) {
        const day = `<div class="bearly-chat-day-divider"><span>${escapeHtml(conversation.dayLabel || 'Today')}</span></div>`;
        const rows = messages.map(message => {
            const avatar = message.from === 'them'
                ? `<span class="bearly-chat-avatar bearly-chat-avatar-${escapeHtml(conversation.tone)}" aria-hidden="true">${escapeHtml(conversation.avatar)}</span>`
                : '';
            const text = message.text
                ? `<div class="bearly-message ${message.from}">${escapeHtml(message.text)}</div>`
                : '';
            const product = message.product ? renderProduct(message.product) : '';
            const checks = message.from === 'me'
                ? '<span class="material-symbols-outlined bearly-chat-read-icon" aria-label="Read">done_all</span>'
                : '';

            return `
                <div class="bearly-message-row ${message.from}">
                    ${avatar}
                    <div class="bearly-message-stack">
                        ${text}
                        ${product}
                        <span class="bearly-message-time">${escapeHtml(message.time || '')} ${checks}</span>
                    </div>
                </div>
            `;
        }).join('');

        return day + rows;
    }

    function renderCompactMessages(messages) {
        return messages.map(message => `
            <div class="bearly-message ${message.from}">
                ${escapeHtml(message.text)}
                ${message.action ? `<div style="margin-top:8px"><a class="bearly-chat-action" href="${escapeHtml(safeHref(message.action.url))}">${escapeHtml(message.action.label)}</a></div>` : ''}
                <span class="bearly-message-time">${escapeHtml(message.time || '')}</span>
            </div>
        `).join('');
    }

    function renderRoot(root) {
        const list = root.querySelector('[data-chat-list]');
        const head = root.querySelector('[data-chat-thread-head]');
        const messages = root.querySelector('[data-chat-messages]');
        const quickEl = root.querySelector('[data-chat-quick]');
        if (!list || !head || !messages) return;

        renderList(root);
        const conversation = conversations.find(item => item.id === state.active) || conversations[0];
        const chatMessages = state.messages[conversation.id] || [];
        const isPage = root.dataset.chatMode === 'page';

        if (isPage) {
            renderPageHeader(head, conversation);
            messages.innerHTML = renderPageMessages(chatMessages, conversation);
        } else {
            renderCompactHeader(head, conversation);
            messages.innerHTML = renderCompactMessages(chatMessages);
        }

        if (quickEl) {
            quickEl.innerHTML = conversation.assistant
                ? quick.map(([label, key]) => `<button type="button" data-chat-quick="${key}">${label}</button>`).join('')
                : '';
        }

        messages.scrollTop = messages.scrollHeight;
    }

    function renderAll() {
        document.querySelectorAll('[data-bearly-chat-root]').forEach(renderRoot);
    }

    function send(text) {
        const value = String(text || '').trim();
        if (!value) return;

        const id = state.active;
        state.messages[id] = state.messages[id] || [];
        state.messages[id].push({ from: 'me', text: value, time: 'Now' });

        if (id === 'bearly') {
            const [reply, url, label] = answer(value);
            state.messages[id].push({
                from: 'them',
                text: reply,
                time: 'Now',
                action: url ? { url, label } : null,
            });
        } else {
            state.messages[id].push({ from: 'them', text: 'Thanks for your message! This seller conversation is a frontend demo for now.', time: 'Now' });
        }

        save();
        renderAll();
    }

    function openDrawer(forceBearly = false) {
        const drawer = document.querySelector('[data-bearly-chat-drawer]');
        const backdrop = document.querySelector('.bearly-chat-backdrop');
        if (!drawer) return;
        if (forceBearly) state.active = 'bearly';
        save();
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        if (backdrop) backdrop.hidden = false;
        renderAll();
    }

    function closeDrawer() {
        const drawer = document.querySelector('[data-bearly-chat-drawer]');
        const backdrop = document.querySelector('.bearly-chat-backdrop');
        if (!drawer) return;
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        if (backdrop) backdrop.hidden = true;
    }

    function insertEmoji(button) {
        const input = button.closest('form')?.querySelector('[data-chat-input]');
        if (!input) return;
        const emoji = '🙂';
        const start = input.selectionStart ?? input.value.length;
        const end = input.selectionEnd ?? input.value.length;
        input.setRangeText(emoji, start, end, 'end');
        input.focus();
    }

    document.addEventListener('click', event => {
        const launcher = event.target.closest('[data-bearly-chat-launcher]');
        if (launcher) {
            event.preventDefault();
            openDrawer(launcher.hasAttribute('data-open-bearly'));
            return;
        }

        if (event.target.closest('[data-bearly-chat-close]')) {
            closeDrawer();
            return;
        }

        const filter = event.target.closest('[data-chat-filter]');
        if (filter) {
            const root = filter.closest('[data-bearly-chat-root]');
            if (root) {
                root.dataset.chatFilter = filter.dataset.chatFilter || 'all';
                renderRoot(root);
            }
            return;
        }

        const item = event.target.closest('[data-chat-id]');
        if (item) {
            state.active = item.dataset.chatId;
            state.unread = { ...(state.unread || {}), [state.active]: 0 };
            save();
            renderAll();
            return;
        }

        const quickButton = event.target.closest('button[data-chat-quick]');
        if (quickButton) {
            const labels = {
                track: 'Where is my order?',
                vouchers: 'Show me my vouchers',
                shipping: 'How does shipping work?',
                payments: 'What payment methods are available?',
                returns: 'How do returns and refunds work?',
                account: 'Help me with my account',
            };
            send(labels[quickButton.dataset.chatQuick] || quickButton.textContent);
            return;
        }

        const emojiButton = event.target.closest('[data-chat-emoji]');
        if (emojiButton) {
            insertEmoji(emojiButton);
            return;
        }

        const attachmentButton = event.target.closest('[data-chat-attachment-button]');
        if (attachmentButton) {
            attachmentButton.closest('form')?.querySelector('[data-chat-file]')?.click();
        }
    });

    document.addEventListener('change', event => {
        if (!event.target.matches('[data-chat-file]')) return;
        const file = event.target.files?.[0];
        const input = event.target.closest('form')?.querySelector('[data-chat-input]');
        if (file && input) {
            input.value = `[Attachment] ${file.name}`;
            input.focus();
        }
    });

    document.addEventListener('submit', event => {
        const form = event.target.closest('[data-chat-form]');
        if (!form) return;
        event.preventDefault();
        const input = form.querySelector('[data-chat-input]');
        send(input?.value || '');
        if (input) {
            input.value = '';
            input.focus();
        }
        const file = form.querySelector('[data-chat-file]');
        if (file) file.value = '';
    });

    document.addEventListener('input', event => {
        if (event.target.matches('[data-chat-search]')) {
            renderRoot(event.target.closest('[data-bearly-chat-root]'));
        }
    });

    const params = new URLSearchParams(location.search);
    if (params.get('conversation') === 'bearly') {
        state.active = 'bearly';
        save();
    }

    document.querySelectorAll('[data-bearly-chat-root]').forEach(root => {
        root.dataset.chatFilter = root.dataset.chatFilter || 'all';
    });
    renderAll();

    window.BearlyChat = {
        open: openDrawer,
        openAssistant: () => openDrawer(true),
    };
})();

/* Bearly global navbar state: keeps notification/cart badges in sync across buyer pages. */
(function initBearlyGlobalNavbarState() {
    if (window.BearlyNavbarState) return;

    const CART_KEY = window.bearlyStorageKey?.('preview-cart') || 'bearly-preview-cart-v1';
    const NOTIFICATION_KEY = window.bearlyStorageKey?.('preview-notifications') || 'bearly-notifications-v1';

    function cartCount() {
        try {
            const value = JSON.parse(localStorage.getItem(CART_KEY) || '[]');
            const preview = Array.isArray(value)
                ? value.reduce((total, item) => total + Math.max(0, Number(item?.quantity || 0)), 0)
                : 0;
            return preview + Number(window.bearlyBuyerProfile?.cartCount || 0);
        } catch {
            return Number(window.bearlyBuyerProfile?.cartCount || 0);
        }
    }

    function notificationCount() {
        try {
            const raw = localStorage.getItem(NOTIFICATION_KEY);
            if (!raw) {
                const existing = document.querySelector('[data-notification-badge]');
                return Number(existing?.textContent || 3) || 0;
            }
            const value = JSON.parse(raw);
            return Array.isArray(value) ? value.filter(item => !item?.read).length : 0;
        } catch {
            return 0;
        }
    }

    function ensureBadge(link, type) {
        if (!link) return null;
        const selector = type === 'cart' ? '[data-global-cart-badge]' : '[data-notification-badge]';
        let badge = link.querySelector(selector);

        /* Reuse older page-specific cart counters instead of creating a second badge. */
        if (!badge && type === 'cart') badge = link.querySelector('#cart-header-count,.cart-badge');
        if (!badge) {
            badge = document.createElement('span');
            badge.setAttribute(type === 'cart' ? 'data-global-cart-badge' : 'data-notification-badge', '');
            link.appendChild(badge);
        } else if (type === 'cart') {
            badge.setAttribute('data-global-cart-badge', '');
        }

        Object.assign(badge.style, {
            position: 'absolute',
            top: '-7px',
            right: '0',
            minWidth: '17px',
            height: '17px',
            padding: '0 4px',
            borderRadius: '999px',
            background: '#e9a321',
            color: '#432718',
            fontSize: '10px',
            fontWeight: '800',
            lineHeight: '17px',
            textAlign: 'center',
            zIndex: '3',
        });
        link.style.position = 'relative';
        return badge;
    }

    function sync() {
        document.querySelectorAll('a[href$="/cart"],a[href="/cart"]').forEach(link => {
            const badge = ensureBadge(link, 'cart');
            const count = cartCount();
            badge.textContent = count;
            badge.hidden = count === 0;
            badge.style.display = count === 0 ? 'none' : '';
        });

        document.querySelectorAll('a[href*="#notifications"]').forEach(link => {
            const badge = ensureBadge(link, 'notification');
            const count = notificationCount();
            badge.textContent = count;
            badge.hidden = count === 0;
            badge.style.display = count === 0 ? 'none' : '';
        });

        document.querySelectorAll('[data-notification-badge]').forEach(badge => {
            const count = notificationCount();
            badge.textContent = count;
            badge.hidden = count === 0;
            if (badge.style) badge.style.display = count === 0 ? 'none' : '';
        });

        /* Profile header must follow Home: logo left, actions pushed to the far right. */
        document.querySelectorAll('.profile-top-actions').forEach(element => {
            element.style.marginLeft = 'auto';
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', sync);
    else sync();
    window.addEventListener('storage', sync);
    window.addEventListener('focus', sync);
    setInterval(sync, 5000);
    window.BearlyNavbarState = { sync };
})();
