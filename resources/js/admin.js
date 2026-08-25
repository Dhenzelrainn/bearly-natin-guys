const ready = (callback) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback, { once: true });
    } else {
        callback();
    }
};

ready(() => {
    const body = document.body;

    const refreshIcons = () => {
        if (window.lucide?.createIcons) window.lucide.createIcons();
    };
    refreshIcons();
    setTimeout(refreshIcons, 150);

    // Sidebar state
    const savedSidebar = localStorage.getItem('bearly-admin-sidebar');
    if (savedSidebar === 'collapsed' && window.innerWidth > 1024) body.classList.add('sidebar-collapsed');

    document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            body.classList.toggle('sidebar-collapsed');
            localStorage.setItem('bearly-admin-sidebar', body.classList.contains('sidebar-collapsed') ? 'collapsed' : 'expanded');
        });
    });

    document.querySelectorAll('[data-mobile-sidebar]').forEach((button) => {
        button.addEventListener('click', () => body.classList.add('mobile-sidebar-open'));
    });
    document.querySelectorAll('[data-sidebar-overlay]').forEach((overlay) => {
        overlay.addEventListener('click', () => body.classList.remove('mobile-sidebar-open'));
    });

    // Toasts / mock feedback
    const toastStack = document.querySelector('[data-toast-stack]');
    const showToast = (message, title = 'Preview updated') => {
        if (!toastStack) return;
        const toast = document.createElement('div');
        toast.className = 'admin-toast';
        toast.innerHTML = `
            <span><i data-lucide="circle-check"></i></span>
            <div><strong>${title}</strong><small>${message}</small></div>
            <button type="button" aria-label="Dismiss"><i data-lucide="x"></i></button>
        `;
        toastStack.appendChild(toast);
        refreshIcons();
        const remove = () => toast.remove();
        toast.querySelector('button')?.addEventListener('click', remove);
        setTimeout(remove, 3400);
    };

    document.querySelectorAll('[data-mock-action]').forEach((element) => {
        element.addEventListener('click', (event) => {
            if (element.tagName === 'A') event.preventDefault();
            showToast(element.dataset.mockAction);
        });
    });

    document.querySelectorAll('[data-dismiss-flash]').forEach((button) => {
        button.addEventListener('click', () => button.closest('.flash-message')?.remove());
    });

    // Popovers
    const closePopovers = (except = null) => {
        document.querySelectorAll('[data-popover]').forEach((popover) => {
            if (popover !== except) popover.hidden = true;
        });
    };
    document.querySelectorAll('[data-popover-toggle]').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            const popover = document.querySelector(`[data-popover="${button.dataset.popoverToggle}"]`);
            if (!popover) return;
            const opening = popover.hidden;
            closePopovers(popover);
            popover.hidden = !opening;
        });
    });
    document.addEventListener('click', (event) => {
        if (!event.target.closest('.topbar-popover-wrap')) closePopovers();
    });

    // Command palette / simulated global search
    const command = document.querySelector('[data-command-palette]');
    const commandInput = document.querySelector('[data-command-input]');
    const commandItems = [...document.querySelectorAll('[data-command-item]')];
    const openCommand = () => {
        if (!command) return;
        command.hidden = false;
        setTimeout(() => commandInput?.focus(), 20);
    };
    const closeCommand = () => {
        if (!command) return;
        command.hidden = true;
        if (commandInput) commandInput.value = '';
        commandItems.forEach((item) => item.hidden = false);
    };
    document.querySelectorAll('[data-command-close]').forEach((button) => button.addEventListener('click', closeCommand));
    document.querySelectorAll('[data-global-search]').forEach((input) => {
        input.addEventListener('focus', () => { input.blur(); openCommand(); });
    });
    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault(); openCommand();
        }
        if (event.key === 'Escape') {
            closeCommand(); closePopovers(); body.classList.remove('mobile-sidebar-open');
            document.querySelectorAll('[data-modal]').forEach((modal) => modal.hidden = true);
            document.querySelectorAll('[data-drawer]').forEach((drawer) => drawer.hidden = true);
        }
    });
    commandInput?.addEventListener('input', () => {
        const query = commandInput.value.trim().toLowerCase();
        commandItems.forEach((item) => item.hidden = !item.dataset.searchText.includes(query));
    });

    // Generic modal system
    const closeModal = (modal) => { if (modal) modal.hidden = true; };
    document.querySelectorAll('[data-close-modal]').forEach((button) => {
        button.addEventListener('click', () => closeModal(button.closest('[data-modal]')));
    });
    document.querySelectorAll('[data-open-modal]').forEach((button) => {
        button.addEventListener('click', () => {
            const modal = document.querySelector(`[data-modal="${button.dataset.openModal}"]`);
            if (!modal) return;

            if (button.dataset.applicant) {
                const recipient = modal.querySelector('[data-decision-recipient]');
                const status = modal.querySelector('[data-decision-status]');
                const message = modal.querySelector('[data-decision-message]');
                if (recipient) recipient.value = button.dataset.applicant;
                if (status) status.value = button.dataset.decision || 'Reviewed';
                if (message) {
                    const decision = (button.dataset.decision || 'reviewed').toLowerCase();
                    message.value = `Hello ${button.dataset.applicant},\n\nThank you for submitting your Bearly registration. Your application has been ${decision}. This is a front-end preview of the email notification workflow.`;
                }
            }

            modal.hidden = false;
            refreshIcons();
        });
    });

    // Drawer system + compliance data injection
    document.querySelectorAll('[data-open-drawer]').forEach((button) => {
        button.addEventListener('click', () => {
            const drawer = document.querySelector(`[data-drawer="${button.dataset.openDrawer}"]`);
            if (!drawer) return;
            const setText = (selector, value) => { const el = drawer.querySelector(selector); if (el) el.textContent = value; };
            setText('[data-drawer-product]', button.dataset.flagProduct || 'Flagged product');
            setText('[data-drawer-seller]', button.dataset.flagSeller || 'Seller');
            setText('[data-drawer-reason]', button.dataset.flagReason || 'Review required.');
            setText('[data-drawer-risk]', button.dataset.flagRisk || 'Medium');
            setText('[data-drawer-warnings]', button.dataset.flagWarnings || '0');
            drawer.hidden = false;
            refreshIcons();
        });
    });
    document.querySelectorAll('[data-close-drawer]').forEach((button) => {
        button.addEventListener('click', () => { const drawer = button.closest('[data-drawer]'); if (drawer) drawer.hidden = true; });
    });

    // Reusable table filtering
    const runTableFilter = (tableId) => {
        const table = document.getElementById(tableId);
        if (!table) return;
        const search = document.querySelector(`[data-table-search="${tableId}"]`)?.value.trim().toLowerCase() || '';
        const filters = [...document.querySelectorAll(`[data-table-filter="${tableId}"]`)].map((select) => ({
            key: select.dataset.filterKey,
            value: select.value.toLowerCase(),
        }));
        let visible = 0;
        table.querySelectorAll('[data-table-row]').forEach((row) => {
            const matchesSearch = !search || (row.dataset.search || row.textContent.toLowerCase()).includes(search);
            const matchesFilters = filters.every((filter) => !filter.value || (row.dataset[filter.key] || '').toLowerCase() === filter.value);
            row.hidden = !(matchesSearch && matchesFilters);
            if (!row.hidden) visible += 1;
        });
        const empty = document.querySelector(`[data-table-empty="${tableId}"]`);
        if (empty) empty.hidden = visible !== 0;
    };
    document.querySelectorAll('[data-table-search]').forEach((input) => input.addEventListener('input', () => runTableFilter(input.dataset.tableSearch)));
    document.querySelectorAll('[data-table-filter]').forEach((select) => select.addEventListener('change', () => runTableFilter(select.dataset.tableFilter)));

    // User status mock actions
    document.querySelectorAll('[data-user-status]').forEach((button) => {
        button.addEventListener('click', () => {
            const row = button.closest('tr');
            const badge = row?.querySelector('.js-status-badge');
            if (!row || !badge) return;
            const status = button.dataset.userStatus;
            row.dataset.status = status;
            badge.textContent = status;
            badge.className = 'status-badge js-status-badge ' + (status === 'Active' ? 'badge-success' : status === 'Suspended' ? 'badge-danger' : 'badge-neutral');
            showToast(`Account status changed to ${status}.`, 'Mock account update');
        });
    });

    // Commission calculator
    const commissionInput = document.querySelector('[data-commission-input]');
    const commissionOutput = document.querySelector('[data-commission-output]');
    const sellerNet = document.querySelector('[data-seller-net]');
    const formatPeso = (number) => `₱${Number(number).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const calculateCommission = () => {
        const gross = Math.max(0, Number(commissionInput?.value || 0));
        if (commissionOutput) commissionOutput.textContent = formatPeso(gross * .10);
        if (sellerNet) sellerNet.textContent = formatPeso(gross * .90);
    };
    commissionInput?.addEventListener('input', calculateCommission);

    // Settings live preview
    const announcementTitle = document.querySelector('[data-announcement-title]');
    const announcementAudience = document.querySelector('[data-announcement-audience]');
    const announcementBody = document.querySelector('[data-announcement-body]');
    const updateAnnouncement = () => {
        const title = document.querySelector('[data-preview-title]');
        const audience = document.querySelector('[data-preview-audience]');
        const bodyPreview = document.querySelector('[data-preview-body]');
        if (title && announcementTitle) title.textContent = announcementTitle.value || 'Untitled announcement';
        if (audience && announcementAudience) audience.textContent = announcementAudience.value;
        if (bodyPreview && announcementBody) bodyPreview.textContent = announcementBody.value || 'Announcement message preview.';
    };
    [announcementTitle, announcementAudience, announcementBody].forEach((field) => field?.addEventListener('input', updateAnnouncement));
    announcementAudience?.addEventListener('change', updateAnnouncement);

    const policyEditor = document.querySelector('[data-policy-editor]');
    const policyPreview = document.querySelector('[data-policy-preview]');
    policyEditor?.addEventListener('input', () => { if (policyPreview) policyPreview.textContent = policyEditor.value; });

    // Dispute queue search
    const disputeSearch = document.querySelector('[data-dispute-search]');
    disputeSearch?.addEventListener('input', () => {
        const query = disputeSearch.value.trim().toLowerCase();
        document.querySelectorAll('[data-case-card]').forEach((card) => {
            card.hidden = !!query && !card.dataset.caseSearch.includes(query);
        });
    });
    document.querySelectorAll('[data-case-card]').forEach((card) => {
        card.addEventListener('click', () => {
            document.querySelectorAll('[data-case-card]').forEach((item) => item.classList.remove('is-active'));
            card.classList.add('is-active');
            showToast('Case selected. Detail content remains static for the preview.', 'Dispute workspace');
        });
    });

    // Chat filtering / header selection
    const conversationSearch = document.querySelector('[data-conversation-search]');
    conversationSearch?.addEventListener('input', () => {
        const query = conversationSearch.value.trim().toLowerCase();
        document.querySelectorAll('[data-conversation-item]').forEach((item) => {
            item.hidden = !!query && !item.dataset.search.includes(query);
        });
    });
    document.querySelectorAll('[data-conversation-item]').forEach((item) => {
        item.addEventListener('click', () => {
            document.querySelectorAll('[data-conversation-item]').forEach((other) => other.classList.remove('is-active'));
            item.classList.add('is-active');
            const name = document.querySelector('[data-chat-name]');
            const role = document.querySelector('[data-chat-role]');
            const avatar = document.querySelector('[data-chat-avatar]');
            if (name) name.textContent = item.dataset.name;
            if (role) role.textContent = item.dataset.role;
            if (avatar) avatar.textContent = item.dataset.initials;
        });
    });

    const messageInput = document.querySelector('[data-message-input]');
    const sendMessage = () => {
        const text = messageInput?.value.trim();
        const thread = document.querySelector('[data-message-thread]');
        if (!text || !thread) return;
        const row = document.createElement('div');
        row.className = 'message-row message-me';
        const time = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        row.innerHTML = `<div class="message-bubble"><p></p><span>${time}</span></div>`;
        row.querySelector('p').textContent = text;
        thread.appendChild(row);
        messageInput.value = '';
        thread.scrollTop = thread.scrollHeight;
        showToast('Message added to the static conversation preview.', 'Message sent');
    };
    document.querySelector('[data-send-message]')?.addEventListener('click', sendMessage);
    messageInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); sendMessage(); }
    });
});
