/*
 * Shared chat receipt + attachment preview helpers for every Bearly role
 * client. These helpers only render payloads the server already authorized
 * and only post delivery acknowledgements to participant-guarded endpoints.
 * They are presentation/interaction helpers, not a security boundary.
 */

const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');

const safeUrl = (value) => {
    const url = String(value ?? '').trim();
    return /^(\/|https?:\/\/)/i.test(url) ? url : '#';
};

const ICONS = {
    sent: '<path d="M5 12.5l4 4L19 7"/>',
    delivered: '<path d="M2.5 12.5l4 4L15.5 7"/><path d="M9.5 12.5l4 4L22.5 7"/>',
    seen: '<path d="M2.5 12.5l4 4L15.5 7"/><path d="M9.5 12.5l4 4L22.5 7"/>',
};

export const receiptMarkup = (receipt) => {
    if (!receipt || !receipt.status) return '';

    const status = ['sent', 'delivered', 'seen'].includes(receipt.status)
        ? receipt.status
        : 'sent';
    const label = escapeHtml(receipt.label || status);
    const detail = escapeHtml(receipt.detail || label);

    return `<span class="chat-receipt is-${status}${receipt.is_group ? ' is-group' : ''}" data-chat-receipt title="${detail}" aria-label="${detail}">`
        + `<svg class="chat-receipt-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[status]}</svg>`
        + `<span class="chat-receipt-label">${label}</span>`
        + '</span>';
};

/**
 * Replace the receipt element inside every rendered row for the given
 * message ids. Rows opt in with data-message-id; own-message rows include a
 * data-chat-receipt node (or a data-chat-meta holder) to target.
 */
export const updateReceiptElements = (receipts, root = document) => {
    (Array.isArray(receipts) ? receipts : []).forEach((entry) => {
        const messageId = Number(entry?.message_id || entry?.id || 0);
        const receipt = entry?.receipt || entry;

        if (!messageId || !receipt) return;

        root.querySelectorAll(`[data-message-id="${messageId}"]`).forEach((row) => {
            row.querySelectorAll('[data-chat-receipt]').forEach((node) => node.remove());

            const holder = row.querySelector('[data-chat-meta]') || row;
            holder.insertAdjacentHTML('beforeend', receiptMarkup(receipt));
        });
    });
};

/**
 * Merge receipt updates into an in-memory message list so later re-renders
 * keep the latest status even if the DOM was rebuilt.
 */
export const applyReceiptUpdates = (messages, receipts) => {
    const updates = new Map(
        (Array.isArray(receipts) ? receipts : [])
            .map((entry) => [Number(entry?.message_id || 0), entry?.receipt])
            .filter(([id, receipt]) => id > 0 && receipt),
    );

    (Array.isArray(messages) ? messages : []).forEach((message) => {
        const receipt = updates.get(Number(message?.id || 0));

        if (receipt) message.receipt = receipt;
    });

    return messages;
};

export const attachmentPreviewHtml = (attachment, options = {}) => {
    const linkClass = options.linkClass || 'message-attachment';
    const mediaClass = options.mediaClass || 'message-attachment-preview';
    const url = escapeHtml(safeUrl(attachment?.download_url));
    const name = escapeHtml(attachment?.name || 'Attachment');
    const mime = String(attachment?.mime_type || '').toLowerCase();
    const isImage = attachment?.is_image || mime.startsWith('image/');
    const isVideo = attachment?.is_video || mime.startsWith('video/');

    if (isImage) {
        return `<a class="${escapeHtml(linkClass)} is-image" href="${url}" target="_blank" rel="noopener">`
            + `<img class="${escapeHtml(mediaClass)}" src="${url}" alt="${name}" loading="lazy">`
            + '</a>';
    }

    if (isVideo) {
        return `<span class="${escapeHtml(linkClass)} is-video">`
            + `<video class="${escapeHtml(mediaClass)}" src="${url}" controls preload="metadata"></video>`
            + `<a class="chat-attachment-open" href="${url}" target="_blank" rel="noopener">${name}</a>`
            + '</span>';
    }

    return `<a class="${escapeHtml(linkClass)}" href="${url}" download>`
        + '<span aria-hidden="true">📎</span>'
        + `<span>${name}</span>`
        + '</a>';
};

export const acknowledgeDelivery = async (url, messageIds, csrfToken) => {
    if (!url) return 0;

    const ids = (Array.isArray(messageIds) ? messageIds : [])
        .map((id) => Number(id))
        .filter((id) => id > 0);

    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken || '',
        },
        body: JSON.stringify(ids.length ? { message_ids: ids } : {}),
    });

    if (!response.ok) {
        throw new Error('Delivery acknowledgement failed.');
    }

    const payload = await response.json().catch(() => ({}));

    return Number(payload?.data?.delivered || 0);
};

const acknowledged = new Map();

/**
 * Acknowledge only the not-yet-acknowledged inbound messages. Failed
 * requests release their ids so a later poll can retry them.
 */
export const acknowledgeIncoming = async (conversation, messages, csrfToken) => {
    const url = conversation?.delivery_url;

    if (!url) return 0;

    const conversationKey = String(conversation?.id || '');
    const sent = acknowledged.get(conversationKey) || new Set();
    const ids = (Array.isArray(messages) ? messages : [])
        .filter((message) => message
            && message.from !== 'me'
            && message.mine !== true)
        .map((message) => Number(message.id || 0))
        .filter((id) => id > 0 && !sent.has(id));

    if (!ids.length) return 0;

    ids.forEach((id) => sent.add(id));
    acknowledged.set(conversationKey, sent);

    try {
        return await acknowledgeDelivery(url, ids, csrfToken);
    } catch {
        ids.forEach((id) => sent.delete(id));
        acknowledged.set(conversationKey, sent);

        return 0;
    }
};

/**
 * Marks the whole conversation delivered (no id filter) for the initial
 * server-rendered load, so offline messages catch up once the thread opens.
 */
export const acknowledgeConversation = (conversation, csrfToken) => acknowledgeDelivery(
    conversation?.delivery_url,
    [],
    csrfToken,
).catch(() => 0);

export { escapeHtml };
