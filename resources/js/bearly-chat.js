/* Bearly buyer chat: persisted conversations for the /chat page and the compact drawer. */
(() => {
    const rootStates = new WeakMap();

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const safeHref = (value) => {
        const url = String(value ?? '').trim();
        return /^(\/|https?:\/\/)/i.test(url) ? url : '#';
    };

    const initials = (name) => String(name ?? '')
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0))
        .join('')
        .toUpperCase() || 'B';

    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const requestHeaders = (json = false) => ({
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken(),
        ...(json ? { 'Content-Type': 'application/json' } : {}),
    });

    function rootState(root) {
        if (!rootStates.has(root)) {
            rootStates.set(root, {
                loaded: false,
                loading: false,
                loadError: null,
                conversations: [],
                activeId: null,
                thread: null,
                threadLoading: false,
                threadError: null,
                filter: 'all',
                sending: false,
                sendError: null,
                file: null,
                lastMessageId: 0,
                inboxSnapshot: null,
                pollTimer: null,
                pollTick: 0,
                visibilityHandler: null,
            });
        }
        return rootStates.get(root);
    }

    const lastMessageIdOf = (thread) => {
        const messages = Array.isArray(thread?.messages) ? thread.messages : [];
        const last = messages[messages.length - 1];
        return last ? Number(last.id || 0) : 0;
    };

    /*
     * Realtime refetches and polling can overlap: both may request messages
     * after the same cursor and receive the same rows. Keep the first row per
     * message id so a thread never renders a duplicate bubble.
     */
    const unseenMessages = (existing, incoming) => {
        const seen = new Set(
            (Array.isArray(existing) ? existing : [])
                .map((message) => Number(message.id || 0))
                .filter((id) => id > 0),
        );

        return (Array.isArray(incoming) ? incoming : []).filter((message) => {
            const id = Number(message.id || 0);

            if (id <= 0) return true;
            if (seen.has(id)) return false;

            seen.add(id);

            return true;
        });
    };

    function bootstrapRoot(root) {
        const node = root.querySelector('script[data-chat-bootstrap]');
        if (!node) return false;

        try {
            const payload = JSON.parse(node.textContent || '{}');
            const state = rootState(root);
            state.conversations = Array.isArray(payload.conversations) ? payload.conversations : [];
            state.activeId = payload.active_id ? Number(payload.active_id) : (state.conversations[0]?.id ?? null);
            state.thread = payload.thread || null;
            state.lastMessageId = lastMessageIdOf(state.thread);
            state.inboxSnapshot = JSON.stringify(state.conversations);
            state.loaded = true;
            return true;
        } catch (error) {
            return false;
        }
    }

    function conversationOf(root, id) {
        const state = rootState(root);
        return state.conversations.find((conversation) => Number(conversation.id) === Number(id)) || null;
    }

    async function loadInbox(root) {
        const state = rootState(root);
        if (state.loaded || state.loading) return;

        state.loading = true;
        state.loadError = null;
        renderRoot(root);

        try {
            const response = await fetch(root.dataset.chatInboxUrl, {
                headers: requestHeaders(),
            });
            const result = await response.json().catch(() => ({}));

            if (!response.ok) throw new Error(result.message || 'Unable to load conversations.');

            state.conversations = result.data?.conversations ?? [];
            const requestedId = Number(new URLSearchParams(window.location.search).get('conversation') || 0);
            const requested = requestedId
                ? state.conversations.find((item) => Number(item.id) === requestedId)
                : null;
            state.activeId = requested?.id ?? state.conversations[0]?.id ?? null;
            state.inboxSnapshot = JSON.stringify(state.conversations);
            state.loaded = true;
        } catch (error) {
            state.loadError = error.message || 'Unable to load conversations.';
            state.loaded = true;
        } finally {
            state.loading = false;
            renderRoot(root);
        }

        if (state.activeId) await selectConversation(root, state.activeId);
    }

    async function markRead(root, conversation, force = false) {
        if (!conversation?.read_url) return;
        if (!force && Number(conversation.unread || 0) === 0) return;

        try {
            const response = await fetch(conversation.read_url, {
                method: 'POST',
                headers: requestHeaders(),
            });
            if (!response.ok) return;
            conversation.unread = 0;
            renderList(root);
        } catch (error) {
            /* Keep the unread badge when the read marker fails. */
        }
    }

    async function refreshInbox(root) {
        const state = rootState(root);
        if (!state.loaded || state.loading || state.sending || !root.dataset.chatInboxUrl) return;

        try {
            const response = await fetch(root.dataset.chatInboxUrl, {
                headers: requestHeaders(),
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) return;

            const conversations = result.data?.conversations;
            if (!Array.isArray(conversations)) return;

            const snapshot = JSON.stringify(conversations);
            if (snapshot === state.inboxSnapshot) return;
            state.inboxSnapshot = snapshot;
            state.conversations = conversations;

            if (!state.activeId || !conversationOf(root, state.activeId)) {
                const first = conversations[0]?.id ?? null;
                if (first && !state.loadError) {
                    await selectConversation(root, first);
                    return;
                }
            }

            renderList(root);
        } catch (error) {
            /* Transient refresh failures keep the current inbox. */
        }
    }

    async function pollThread(root) {
        const state = rootState(root);
        const conversation = conversationOf(root, state.activeId);
        if (!conversation?.thread_url || !state.thread) return;

        const url = new URL(conversation.thread_url, window.location.origin);
        if (state.lastMessageId > 0) url.searchParams.set('after', String(state.lastMessageId));

        try {
            const response = await fetch(url, { headers: requestHeaders() });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) return;

            const payload = result.data;
            const active = state.thread?.conversation;
            if (!payload?.conversation || !active || Number(active.id) !== Number(payload.conversation.id)) return;

            const previousMeta = JSON.stringify(state.thread.conversation);
            state.thread.conversation = payload.conversation;

            const incoming = unseenMessages(state.thread.messages, payload.messages);
            if (incoming.length) {
                const container = root.querySelector('[data-chat-messages]');
                const nearBottom = container
                    ? (container.scrollHeight - container.scrollTop - container.clientHeight) < 90
                    : true;

                state.thread.messages.push(...incoming);
                state.lastMessageId = Math.max(state.lastMessageId, ...incoming.map((message) => Number(message.id || 0)));

                const entry = conversationOf(root, payload.conversation.id);
                const last = incoming[incoming.length - 1];
                if (entry && last) {
                    entry.preview = last.text || 'Sent an attachment.';
                    entry.time_label = last.time_label || entry.time_label;
                }

                renderMessages(root);
                if (container && nearBottom) container.scrollTop = container.scrollHeight;

                if (entry && incoming.some((message) => message.from === 'them')) {
                    markRead(root, entry, true);
                }
            } else if (previousMeta !== JSON.stringify(payload.conversation)) {
                renderThreadHead(root);
                renderDetails(root);
            }
        } catch (error) {
            /* Transient polling failures are ignored; the next tick retries. */
        }
    }

    function startPolling(root) {
        const state = rootState(root);
        if (state.pollTimer || !root.dataset.chatMode) return;

        state.pollTimer = window.setInterval(() => {
            if (document.hidden || state.sending) return;
            state.pollTick += 1;
            if (state.pollTick % 3 === 0) refreshInbox(root);
            pollThread(root);
        }, 5000);

        state.visibilityHandler = () => {
            if (document.hidden || state.sending) return;
            pollThread(root);
            refreshInbox(root);
        };

        document.addEventListener('visibilitychange', state.visibilityHandler);
    }

    function stopPolling(root) {
        const state = rootState(root);
        if (!state.pollTimer) return;
        window.clearInterval(state.pollTimer);
        state.pollTimer = null;
        if (state.visibilityHandler) {
            document.removeEventListener('visibilitychange', state.visibilityHandler);
            state.visibilityHandler = null;
        }
    }

    async function selectConversation(root, id) {
        const state = rootState(root);
        const conversation = conversationOf(root, id);
        if (!conversation) return;

        state.activeId = Number(id);
        state.sendError = null;
        state.lastMessageId = 0;
        markRead(root, conversation);

        state.threadLoading = true;
        state.threadError = null;
        state.thread = null;
        renderRoot(root);

        try {
            const response = await fetch(conversation.thread_url, {
                headers: requestHeaders(),
            });
            const result = await response.json().catch(() => ({}));

            if (!response.ok) throw new Error(result.message || 'Unable to load this conversation.');

            state.thread = result.data || null;
            state.lastMessageId = lastMessageIdOf(state.thread);
        } catch (error) {
            state.threadError = error.message || 'Unable to load this conversation.';
        } finally {
            state.threadLoading = false;
            renderRoot(root);
        }
    }

    function renderList(root) {
        const state = rootState(root);
        const list = root.querySelector('[data-chat-list]');
        if (!list) return;

        const search = (root.querySelector('[data-chat-search]')?.value || '').trim().toLowerCase();
        const visible = state.conversations.filter((conversation) => {
            const haystack = `${conversation.title} ${conversation.context} ${conversation.preview}`.toLowerCase();
            const matchesSearch = !search || haystack.includes(search);
            const matchesFilter = state.filter === 'all'
                || (state.filter === 'unread' && Number(conversation.unread || 0) > 0);

            return matchesSearch && matchesFilter;
        });

        list.innerHTML = visible.map((conversation) => {
            const unread = Number(conversation.unread || 0);
            const active = Number(conversation.id) === Number(state.activeId);
            const dispute = conversation.dispute;
            const avatar = conversation.image_url
                ? `<img src="${escapeHtml(safeHref(conversation.image_url))}" alt="">`
                : escapeHtml(conversation.avatar || initials(conversation.title));
            const disputeBadge = dispute
                ? `<span class="bearly-chat-item-dispute"><span class="material-symbols-outlined" aria-hidden="true">gavel</span>Complaint &amp; Dispute</span>`
                : '';

            return `
                <button type="button" class="bearly-chat-item ${active ? 'is-active' : ''}" data-chat-id="${escapeHtml(conversation.id)}" aria-pressed="${active}">
                    <span class="bearly-chat-avatar" aria-hidden="true">${avatar}</span>
                    <span class="bearly-chat-item-copy">
                        <span class="bearly-chat-item-meta">
                            <strong>${escapeHtml(conversation.title)}</strong>
                            <time>${escapeHtml(conversation.time_label)}</time>
                        </span>
                         <small>${escapeHtml(conversation.preview)}</small>
                         ${disputeBadge}
                    </span>
                    ${unread ? `<b class="bearly-chat-unread-count" aria-label="${unread} unread messages">${unread}</b>` : ''}
                </button>
            `;
        }).join('');

        const empty = root.querySelector('[data-chat-list-empty]');
        if (empty) {
            const zero = state.conversations.length === 0;
            empty.hidden = visible.length > 0;
            const title = empty.querySelector('[data-chat-empty-title]');
            const copy = empty.querySelector('[data-chat-empty-copy]');
            if (title) title.textContent = zero ? 'No conversations yet' : 'No conversations found';
            if (copy) copy.textContent = zero
                ? 'Open a product or store page and use “Chat with seller” to start one.'
                : 'Try another search or filter.';
        } else if (!visible.length) {
            list.innerHTML = `<p class="bearly-chat-list-note">${
                state.loading ? 'Loading conversations…'
                    : state.loadError ? escapeHtml(state.loadError)
                        : state.conversations.length ? 'No conversations match your search.' : 'No conversations yet.'
            }</p>`;
        }

        const unreadLabel = root.querySelector('[data-chat-unread-count]');
        if (unreadLabel) unreadLabel.textContent = String(state.conversations.filter((conversation) => Number(conversation.unread || 0) > 0).length);

        root.querySelectorAll('[data-chat-filter]').forEach((button) => {
            const active = (button.dataset.chatFilter || 'all') === state.filter;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
    }

    function renderThreadHead(root) {
        const state = rootState(root);
        const head = root.querySelector('[data-chat-thread-head]');
        if (!head) return;

        const conversation = state.thread?.conversation;
        if (!conversation) {
            head.innerHTML = '';
            return;
        }

        const entry = conversationOf(root, conversation.id);
        const isPage = root.dataset.chatMode === 'page';
        const dispute = conversation.dispute;
        const image = conversation.counterpart?.image_url || entry?.image_url;
        const avatar = image
            ? `<img src="${escapeHtml(safeHref(image))}" alt="">`
            : escapeHtml(entry?.avatar || conversation.initials || initials(conversation.title));

        if (!isPage) {
            head.innerHTML = `
                <span class="bearly-chat-avatar">${avatar}</span>
                <div>
                    <strong>${escapeHtml(conversation.title)}</strong>
                    <small>${escapeHtml(dispute ? `Complaint & Dispute · ${dispute.number}` : conversation.context)}</small>
                </div>
            `;
            return;
        }

        const since = conversation.counterpart?.since_label;
        const caption = conversation.counterpart?.since_caption;
        const subtitle = dispute
            ? `Complaint & Dispute · ${dispute.number}`
            : (since && caption ? `${caption} ${since}` : conversation.context);
        const order = conversation.order;

        head.innerHTML = `
            <div class="bearly-chat-thread-person">
                <span class="bearly-chat-avatar" aria-hidden="true">${avatar}</span>
                <div>
                    <strong>${escapeHtml(conversation.title)}</strong>
                    <small>${escapeHtml(subtitle)}</small>
                </div>
            </div>
            <span class="bearly-chat-thread-order${dispute ? ' is-dispute' : ''}">${escapeHtml(dispute ? 'Complaint & Dispute' : (order ? `Order #${order.number}` : 'No active order'))}</span>
        `;
    }

    function renderDetails(root) {
        const details = root.querySelector('[data-chat-details]');
        if (!details) return;

        const state = rootState(root);
        const conversation = state.thread?.conversation;
        const entry = conversationOf(root, state.activeId);

        if (!conversation) {
            details.innerHTML = '<h3>Conversation details</h3><p class="bearly-chat-details-note">Select a conversation to see product and order details.</p>';
            return;
        }

        const image = conversation.counterpart?.image_url || entry?.image_url;
        const avatar = image
            ? `<img src="${escapeHtml(safeHref(image))}" alt="">`
            : escapeHtml(entry?.avatar || conversation.initials || initials(conversation.title));
        const since = conversation.counterpart?.since_label;
        const caption = conversation.counterpart?.since_caption || 'Seller on Bearly since';
        const dispute = conversation.dispute;
        const personMeta = dispute
            ? `Complaint & Dispute · ${dispute.number}`
            : (since ? `${caption} ${since}` : conversation.context);

        const order = conversation.order;
        const orderBox = order
            ? `<div class="bearly-chat-details-order"><strong>Order #${escapeHtml(order.number)}</strong><span>${escapeHtml(order.status_label || 'Processing')}</span></div>`
            : '<div class="bearly-chat-details-empty"><span class="material-symbols-outlined" aria-hidden="true">inventory_2</span><span>No active order for this conversation.</span></div>';

        const product = conversation.product;
        const productCard = product
            ? `
                <a class="bearly-chat-details-product" href="${escapeHtml(safeHref(product.url))}">
                    <span class="bearly-chat-details-thumb" aria-hidden="true">${product.image_url ? `<img src="${escapeHtml(safeHref(product.image_url))}" alt="">` : '<span class="material-symbols-outlined">image</span>'}</span>
                    <span class="bearly-chat-details-product-copy">
                        <strong>${escapeHtml(product.name)}</strong>
                        ${product.price_label ? `<small>${escapeHtml(product.price_label)}</small>` : ''}
                    </span>
                    <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
                </a>`
            : '<div class="bearly-chat-details-empty"><span class="material-symbols-outlined" aria-hidden="true">forum</span><span>No product linked to this conversation.</span></div>';

        const disputeBox = dispute
            ? `
                <section class="bearly-chat-details-dispute">
                    <span class="bearly-chat-details-label">Complaint &amp; Dispute</span>
                    <div class="bearly-chat-dispute-card">
                        <div class="bearly-chat-dispute-card-head"><strong>${escapeHtml(dispute.number)}</strong><span>${escapeHtml(dispute.status_label || dispute.status || 'Open')}</span></div>
                        <strong class="bearly-chat-dispute-subject">${escapeHtml(dispute.subject)}</strong>
                        <p>${escapeHtml(dispute.description)}</p>
                        <dl>
                            <div><dt>Seller Order</dt><dd>${escapeHtml(dispute.seller_order_no || '—')}</dd></div>
                            <div><dt>Shipment</dt><dd>${escapeHtml(dispute.shipment_no || 'Not linked')}</dd></div>
                            <div><dt>Order value</dt><dd>${escapeHtml(dispute.amount_label || '—')}</dd></div>
                        </dl>
                    </div>
                </section>
            `
            : '';

        const actions = [];
        if (conversation.store_url) {
            actions.push(`<a class="bearly-chat-details-action" href="${escapeHtml(safeHref(conversation.store_url))}"><span class="material-symbols-outlined" aria-hidden="true">storefront</span><span>View store</span></a>`);
        }
        if (conversation.product_url) {
            actions.push(`<a class="bearly-chat-details-action" href="${escapeHtml(safeHref(conversation.product_url))}"><span class="material-symbols-outlined" aria-hidden="true">shopping_bag</span><span>View product</span></a>`);
        }

        details.innerHTML = `
            <h3>Conversation details</h3>
            <div class="bearly-chat-details-person">
                <span class="bearly-chat-avatar" aria-hidden="true">${avatar}</span>
                <div>
                    <strong>${escapeHtml(conversation.title)}</strong>
                    <small>${escapeHtml(personMeta)}</small>
                </div>
            </div>
                <section>
                    <span class="bearly-chat-details-label">Order information</span>
                    ${orderBox}
                    ${productCard}
                </section>
                ${disputeBox}
                ${actions.length ? `<section><span class="bearly-chat-details-label">Quick actions</span>${actions.join('')}</section>` : ''}
        `;
    }

    function renderMessages(root) {
        const state = rootState(root);
        const container = root.querySelector('[data-chat-messages]');
        if (!container) return;

        const isPage = root.dataset.chatMode === 'page';

        if (state.threadLoading) {
            container.innerHTML = '<p class="bearly-chat-thread-empty">Loading messages…</p>';
            return;
        }

        if (state.threadError) {
            container.innerHTML = `<p class="bearly-chat-thread-empty">${escapeHtml(state.threadError)}</p>`;
            return;
        }

        const thread = state.thread;
        if (!thread) {
            container.innerHTML = `<p class="bearly-chat-thread-empty">${
                state.loadError ? escapeHtml(state.loadError)
                    : state.conversations.length ? 'Select a conversation to read it.'
                        : 'No conversations yet. Open a product and use “Chat with seller” to start one.'
            }</p>`;
            return;
        }

        const entry = conversationOf(root, thread.conversation?.id);
        const avatar = escapeHtml(entry?.avatar || initials(thread.conversation?.title));
        const messages = Array.isArray(thread.messages) ? thread.messages : [];

        const rows = messages.map((message, index) => {
            const previous = messages[index - 1];
            const dayDivider = message.day_label && (!previous || previous.day_label !== message.day_label)
                ? `<div class="bearly-chat-day-divider"><span>${escapeHtml(message.day_label)}</span></div>`
                : '';
            const attachments = (message.attachments || []).map((attachment) => `
                <a class="bearly-chat-attachment" href="${escapeHtml(safeHref(attachment.download_url))}">
                    <span class="material-symbols-outlined" aria-hidden="true">${attachment.is_image ? 'image' : 'attach_file'}</span>
                    <span>${escapeHtml(attachment.name)}</span>
                </a>`).join('');

            if (!isPage) {
                return dayDivider + `
                    <div class="bearly-message ${message.from}">
                        ${message.text ? escapeHtml(message.text) : ''}
                        ${attachments}
                        <span class="bearly-message-time">${escapeHtml(message.time_label)}</span>
                    </div>
                `;
            }

            const bubbleAvatar = message.from === 'them'
                ? `<span class="bearly-chat-avatar" aria-hidden="true">${avatar}</span>`
                : '';

            return dayDivider + `
                <div class="bearly-message-row ${message.from}">
                    ${bubbleAvatar}
                    <div class="bearly-message-stack">
                        ${message.text ? `<div class="bearly-message ${message.from}">${escapeHtml(message.text)}</div>` : ''}
                        ${attachments}
                        <span class="bearly-message-time">${escapeHtml(message.time_label)}</span>
                    </div>
                </div>
            `;
        }).join('');

        const notes = [];
        if (thread.conversation && thread.conversation.status !== 'open') {
            notes.push('This conversation is closed. You can still read the history.');
        }
        if (state.sendError) notes.push(state.sendError);
        const noteHtml = notes.map((note) => `<p class="bearly-chat-system-note">${escapeHtml(note)}</p>`).join('');

        container.innerHTML = (rows || '<p class="bearly-chat-thread-empty">No messages yet. Say hello to your seller.</p>') + noteHtml;
        container.scrollTop = container.scrollHeight;
    }

    function renderComposerState(root) {
        const state = rootState(root);
        const form = root.querySelector('[data-chat-form]');
        if (!form) return;

        const closed = Boolean(state.thread?.conversation && state.thread.conversation.status !== 'open');
        const enabled = Boolean(state.thread) && !closed;

        ['[data-chat-input]', '[data-chat-attachment-button]', 'button[type="submit"]'].forEach((selector) => {
            const node = form.querySelector(selector);
            if (node) node.disabled = !enabled || state.sending;
        });
    }

    function renderRoot(root) {
        renderList(root);
        renderThreadHead(root);
        renderMessages(root);
        renderComposerState(root);
        renderDetails(root);
    }

    function fileChip(root) {
        let chip = root.querySelector('[data-chat-file-chip]');
        if (chip) return chip;

        const form = root.querySelector('[data-chat-form]');
        if (!form || !form.parentElement) return null;

        chip = document.createElement('div');
        chip.className = 'bearly-chat-file-chip';
        chip.setAttribute('data-chat-file-chip', '');
        chip.hidden = true;
        form.parentElement.insertBefore(chip, form);
        return chip;
    }

    function renderFileChip(root) {
        const state = rootState(root);
        const chip = fileChip(root);
        if (!chip) return;

        chip.replaceChildren();
        if (!state.file) {
            chip.hidden = true;
            return;
        }

        const label = document.createElement('span');
        label.textContent = `Attach: ${state.file.name}`;
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.setAttribute('data-chat-file-remove', '');
        remove.setAttribute('aria-label', 'Remove attachment');
        remove.textContent = '×';
        chip.append(label, remove);
        chip.hidden = false;
    }

    function clearFile(root) {
        const state = rootState(root);
        state.file = null;
        const input = root.querySelector('[data-chat-file]');
        if (input) input.value = '';
        renderFileChip(root);
    }

    async function sendMessage(root) {
        const state = rootState(root);
        const form = root.querySelector('[data-chat-form]');
        const input = form?.querySelector('[data-chat-input]');
        if (!form || !input || state.sending) return;

        const text = input.value.trim();
        const file = state.file;
        if (!text && !file) {
            input.focus();
            return;
        }

        const conversation = conversationOf(root, state.activeId);
        if (!conversation?.send_url) return;

        state.sending = true;
        state.sendError = null;
        renderComposerState(root);
        renderMessages(root);

        try {
            const body = new FormData();
            if (text) body.append('message', text);
            if (file) body.append('attachment', file);

            const response = await fetch(conversation.send_url, {
                method: 'POST',
                headers: requestHeaders(),
                body,
            });
            const result = await response.json().catch(() => ({}));

            if (!response.ok) {
                const validation = Object.values(result.errors || {}).flat()[0];
                throw new Error(validation || result.message || 'Unable to send this message. Try again.');
            }

            const payload = result.data?.message;
            input.value = '';
            clearFile(root);

            if (payload) {
                conversation.preview = payload.text || 'Sent an attachment.';
                conversation.time_label = payload.time_label || conversation.time_label;
                conversation.unread = 0;

                if (state.thread && Number(state.thread.conversation?.id) === Number(conversation.id)) {
                    state.thread.messages.push(...unseenMessages(state.thread.messages, [payload]));
                    state.lastMessageId = Math.max(state.lastMessageId, Number(payload.id || 0));
                }
            }

            input.focus();
        } catch (error) {
            state.sendError = error.message || 'Unable to send this message. Try again.';
        } finally {
            state.sending = false;
            renderRoot(root);
        }
    }

    function insertEmoji(button) {
        const input = button.closest('form')?.querySelector('[data-chat-input]');
        if (!input || input.disabled) return;

        const start = input.selectionStart ?? input.value.length;
        const end = input.selectionEnd ?? input.value.length;
        input.setRangeText('🙂', start, end, 'end');
        input.focus();
    }

    function openDrawer() {
        const drawer = document.querySelector('[data-bearly-chat-drawer]');
        const backdrop = document.querySelector('.bearly-chat-backdrop');
        if (!drawer) return;

        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        if (backdrop) backdrop.hidden = false;

        const root = drawer.querySelector('[data-bearly-chat-root]');
        if (!root) return;

        bootstrapRoot(root);
        loadInbox(root);
        renderRoot(root);
        startPolling(root);
        refreshInbox(root);
    }

    function closeDrawer() {
        const drawer = document.querySelector('[data-bearly-chat-drawer]');
        const backdrop = document.querySelector('.bearly-chat-backdrop');
        if (!drawer) return;

        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        if (backdrop) backdrop.hidden = true;

        const root = drawer.querySelector('[data-bearly-chat-root]');
        if (root) stopPolling(root);
    }

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-bearly-chat-launcher]')) {
            event.preventDefault();
            openDrawer();
            return;
        }

        if (event.target.closest('[data-bearly-chat-close]')) {
            closeDrawer();
            return;
        }

        const filter = event.target.closest('[data-chat-filter]');
        const filterRoot = filter?.closest('[data-bearly-chat-root]');
        if (filter && filterRoot) {
            rootState(filterRoot).filter = filter.dataset.chatFilter || 'all';
            renderRoot(filterRoot);
            return;
        }

        const item = event.target.closest('[data-chat-id]');
        const itemRoot = item?.closest('[data-bearly-chat-root]');
        if (item && itemRoot) {
            selectConversation(itemRoot, Number(item.dataset.chatId));
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
            return;
        }

        const removeFile = event.target.closest('[data-chat-file-remove]');
        const removeRoot = removeFile?.closest('[data-bearly-chat-root]');
        if (removeFile && removeRoot) clearFile(removeRoot);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeDrawer();
    });

    document.addEventListener('input', (event) => {
        if (!event.target.matches('[data-chat-search]')) return;
        renderRoot(event.target.closest('[data-bearly-chat-root]'));
    });

    document.addEventListener('change', (event) => {
        if (!event.target.matches('[data-chat-file]')) return;
        const root = event.target.closest('[data-bearly-chat-root]');
        if (!root) return;
        rootState(root).file = event.target.files?.[0] || null;
        renderFileChip(root);
    });

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-chat-form]');
        if (!form) return;
        event.preventDefault();
        const root = form.closest('[data-bearly-chat-root]');
        if (root) sendMessage(root);
    });

    document.querySelectorAll('[data-bearly-chat-root]').forEach((root) => {
        const state = rootState(root);
        state.filter = root.dataset.chatFilter || 'all';
        bootstrapRoot(root);

        if (root.dataset.chatMode === 'page') {
            if (state.activeId) markRead(root, conversationOf(root, state.activeId));
            startPolling(root);
        }

        renderRoot(root);
    });

    window.addEventListener('bearly:message-created', (event) => {
        const conversationId = Number(event.detail?.conversation_id || 0);

        document.querySelectorAll('[data-bearly-chat-root]').forEach((root) => {
            const state = rootState(root);
            if (!state.loaded) return;

            if (Number(state.activeId) === conversationId) {
                pollThread(root).catch(() => {});
            } else {
                refreshInbox(root).catch(() => {});
            }
        });
    });

    window.BearlyChat = {
        open: openDrawer,
    };
})();

/* Bearly global navbar state: keeps notification/cart badges in sync across buyer pages. */
(function initBearlyGlobalNavbarState() {
    if (window.BearlyNavbarState) return;

    /* Notification badges mirror the real unread count synced by notification-center.js. */

    function cartCount() {
        return Math.max(0, Number(window.bearlyBuyerProfile?.cartCount || 0));
    }

    function notificationCount() {
        const existing = document.querySelector('[data-notification-badge]');
        return Math.max(0, Number(existing?.textContent || 0) || 0);
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
