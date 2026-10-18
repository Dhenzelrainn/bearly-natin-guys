(() => {
    const sourceFor = (role) => {
        if (role === 'logistics') return window.bearlyLogisticsConversations || {};
        if (role === 'rider') return window.bearlyRiderConversations || {};
        if (role === 'admin') return window.bearlyAdminConversations || {};
        return {};
    };

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    document.querySelectorAll('[data-standard-details]').forEach((details) => {
        const page = details.closest('[data-standard-role]');
        const role = page?.dataset.standardRole || '';
        const source = sourceFor(role);
        const body = details.querySelector('[data-standard-details-body]');
        const buttons = [...(page?.querySelectorAll('[data-conversation], [data-conversation-item]') || [])];

        if (!body || !buttons.length) return;

        const dataFor = (button) => {
            const id = String(button.dataset.conversation || button.dataset.conversationId || '');
            return source[id] || source[Number(id)] || {
                name: button.dataset.name || '',
                role: button.dataset.role || '',
                preview: button.dataset.preview || '',
            };
        };

        const render = (button) => {
            const conversation = dataFor(button);
            const dispute = conversation.dispute || null;
            const name = conversation.name || conversation.buyer || conversation.title || 'Conversation';
            const roleLabel = conversation.role || conversation.counterpart_role || 'Participant';
            const context = dispute ? 'Complaint & Dispute' : (conversation.subject || conversation.context || conversation.type || 'Message thread');
            const status = conversation.status || 'open';

            body.innerHTML = `
                <div class="messages-standard-person">
                    <span class="messages-standard-avatar">${escapeHtml(conversation.initials || name.slice(0, 2).toUpperCase())}</span>
                    <div><strong>${escapeHtml(name)}</strong><small>${escapeHtml(roleLabel)}</small></div>
                </div>
                <div class="messages-standard-context-card">
                    <span class="messages-standard-label">${escapeHtml(context)}</span>
                    <strong>${escapeHtml(dispute?.subject || conversation.subject || 'Conversation details')}</strong>
                    <small>${escapeHtml(dispute?.number || conversation.preview || '')}</small>
                    <span class="messages-standard-status">${escapeHtml(dispute?.status_label || status)}</span>
                </div>
            `;
        };

        buttons.forEach((button) => button.addEventListener('click', () => render(button)));

        const requested = new URLSearchParams(window.location.search).get('conversation');
        const initial = buttons.find((button) => String(button.dataset.conversation || button.dataset.conversationId) === String(requested))
            || buttons.find((button) => button.classList.contains('is-active'))
            || buttons[0];

        render(initial);
    });
})();
