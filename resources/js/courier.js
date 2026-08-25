const refreshIcons = () => {
    if (window.lucide) window.lucide.createIcons();
};

const money = (value) => `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

document.addEventListener('DOMContentLoaded', () => {
    refreshIcons();

    const shell = document.querySelector('[data-courier-shell]');
    const sidebar = document.querySelector('[data-sidebar]');
    const overlay = document.querySelector('[data-sidebar-overlay]');
    const toastStack = document.querySelector('[data-toast-stack]');

    const showToast = (message, title = 'Courier preview') => {
        if (!toastStack) return;
        const toast = document.createElement('div');
        toast.className = 'courier-toast';
        toast.innerHTML = `<span class="notice-marker"><i data-lucide="circle-check"></i></span><div><strong></strong><p></p></div><button type="button" aria-label="Dismiss"><i data-lucide="x"></i></button>`;
        toast.querySelector('strong').textContent = title;
        toast.querySelector('p').textContent = message;
        toast.querySelector('button').addEventListener('click', () => toast.remove());
        toastStack.appendChild(toast);
        refreshIcons();
        window.setTimeout(() => toast.remove(), 4200);
    };

    // Sidebar
    document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', () => shell?.classList.toggle('sidebar-collapsed'));
    document.querySelector('[data-mobile-sidebar]')?.addEventListener('click', () => document.body.classList.add('mobile-sidebar-open'));
    overlay?.addEventListener('click', () => document.body.classList.remove('mobile-sidebar-open'));
    document.querySelectorAll('.sidebar-link').forEach((link) => link.addEventListener('click', () => document.body.classList.remove('mobile-sidebar-open')));

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
            const willOpen = popover.hidden;
            closePopovers(popover);
            popover.hidden = !willOpen;
            refreshIcons();
        });
    });
    document.addEventListener('click', (event) => {
        if (!event.target.closest('.topbar-popover-wrap')) closePopovers();
    });

    // Duty toggle
    const dutyToggle = document.querySelector('[data-duty-toggle]');
    const dutyState = document.querySelector('[data-duty-state]');
    dutyToggle?.addEventListener('click', () => {
        const online = !dutyToggle.classList.contains('is-online');
        dutyToggle.classList.toggle('is-online', online);
        dutyState?.classList.toggle('is-online', online);
        if (dutyState) dutyState.textContent = online ? 'Online' : 'Offline';
        showToast(online ? 'You are visible for new pickup requests.' : 'New pickup requests are paused.', online ? 'You are online' : 'You are offline');
    });

    // Command palette / search
    const commandPalette = document.querySelector('[data-command-palette]');
    const commandInput = document.querySelector('[data-command-input]');
    const commandItems = [...document.querySelectorAll('[data-command-item]')];
    const openCommand = () => {
        if (!commandPalette) return;
        commandPalette.hidden = false;
        window.setTimeout(() => commandInput?.focus(), 10);
    };
    const closeCommand = () => { if (commandPalette) commandPalette.hidden = true; };
    document.querySelector('[data-global-search]')?.addEventListener('focus', (e) => { e.target.blur(); openCommand(); });
    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); openCommand(); }
        if (event.key === 'Escape') { closeCommand(); document.querySelectorAll('[data-modal],[data-drawer]').forEach((el) => el.hidden = true); }
    });
    document.querySelectorAll('[data-command-close]').forEach((button) => button.addEventListener('click', closeCommand));
    commandInput?.addEventListener('input', () => {
        const query = commandInput.value.trim().toLowerCase();
        commandItems.forEach((item) => item.hidden = !item.dataset.searchText.includes(query));
    });

    // Flash / generic mock actions
    document.querySelectorAll('[data-dismiss-flash]').forEach((button) => button.addEventListener('click', () => button.closest('.flash-message')?.remove()));
    document.querySelectorAll('[data-mock-action]').forEach((button) => button.addEventListener('click', (event) => {
        if (button.tagName === 'A' && button.getAttribute('href') && button.getAttribute('href') !== '#') return;
        event.preventDefault();
        showToast(button.dataset.mockAction);
    }));

    // Modal / drawer system
    const closeModal = (modal) => { if (modal) modal.hidden = true; };
    document.querySelectorAll('[data-close-modal]').forEach((button) => button.addEventListener('click', () => closeModal(button.closest('[data-modal]'))));
    document.querySelectorAll('[data-open-modal]').forEach((button) => {
        button.addEventListener('click', () => {
            const modal = document.querySelector(`[data-modal="${button.dataset.openModal}"]`);
            if (!modal) return;

            if (button.dataset.jobId) {
                const fields = {
                    '[data-modal-job-id]': button.dataset.jobId,
                    '[data-modal-seller]': button.dataset.seller,
                    '[data-modal-pickup]': button.dataset.pickup,
                    '[data-modal-dropoff]': button.dataset.dropoff,
                    '[data-modal-payout]': button.dataset.payout,
                    '[data-modal-distance]': button.dataset.distance,
                };
                Object.entries(fields).forEach(([selector, value]) => {
                    const el = modal.querySelector(selector);
                    if (el) el.textContent = value || '—';
                });
            }
            modal.hidden = false;
            refreshIcons();
        });
    });
    document.querySelectorAll('[data-close-drawer]').forEach((button) => button.addEventListener('click', () => { const drawer = button.closest('[data-drawer]'); if (drawer) drawer.hidden = true; }));
    document.querySelectorAll('[data-open-history]').forEach((button) => {
        button.addEventListener('click', () => {
            const drawer = document.querySelector('[data-drawer="history-detail"]');
            if (!drawer) return;
            const map = {
                '[data-history-id]': button.dataset.id,
                '[data-history-date]': button.dataset.date,
                '[data-history-status]': button.dataset.status,
                '[data-history-seller]': button.dataset.seller,
                '[data-history-buyer]': button.dataset.buyer,
                '[data-history-route]': button.dataset.route,
                '[data-history-distance]': button.dataset.distance,
                '[data-history-payout]': button.dataset.payout,
            };
            Object.entries(map).forEach(([selector, value]) => { const el = drawer.querySelector(selector); if (el) el.textContent = value || '—'; });
            drawer.hidden = false;
            refreshIcons();
        });
    });

    // Registration wizard
    const steps = [...document.querySelectorAll('[data-registration-step]')];
    const progress = [...document.querySelectorAll('[data-progress-step]')];
    let currentStep = 0;
    const renderStep = () => {
        steps.forEach((step, index) => step.hidden = index !== currentStep);
        progress.forEach((item, index) => item.classList.toggle('is-active', index <= currentStep));
        document.querySelector('[data-registration-current]')?.replaceChildren(document.createTextNode(String(currentStep + 1)));
        refreshIcons();
    };
    document.querySelectorAll('[data-step-next]').forEach((button) => button.addEventListener('click', () => { if (currentStep < steps.length - 1) { currentStep += 1; renderStep(); window.scrollTo({ top: 0, behavior: 'smooth' }); } }));
    document.querySelectorAll('[data-step-back]').forEach((button) => button.addEventListener('click', () => { if (currentStep > 0) { currentStep -= 1; renderStep(); } }));
    document.querySelector('[data-registration-submit]')?.addEventListener('click', () => { window.location.href = document.querySelector('[data-registration-submit]')?.dataset.pendingUrl || '#'; });
    document.querySelectorAll('.file-drop input,.proof-upload input').forEach((input) => input.addEventListener('change', () => {
        const label = input.closest('.file-drop,.proof-upload')?.querySelector('small');
        if (label && input.files?.length) label.textContent = input.files[0].name;
    }));
    if (steps.length) renderStep();

    // Table filtering
    const runTableFilter = (tableId) => {
        const table = document.getElementById(tableId);
        if (!table) return;
        const search = document.querySelector(`[data-table-search="${tableId}"]`)?.value.trim().toLowerCase() || '';
        const filters = [...document.querySelectorAll(`[data-table-filter="${tableId}"]`)].map((select) => ({ key: select.dataset.filterKey, value: select.value.toLowerCase() }));
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

    // Request feed search + accept simulation
    const requestSearch = document.querySelector('[data-request-search]');
    const requestFilter = document.querySelector('[data-request-filter]');
    const filterRequests = () => {
        const query = requestSearch?.value.trim().toLowerCase() || '';
        const size = requestFilter?.value.toLowerCase() || '';
        document.querySelectorAll('[data-request-card]').forEach((card) => {
            const matchesSearch = !query || card.dataset.search.includes(query);
            const matchesSize = !size || card.dataset.size.toLowerCase() === size;
            card.hidden = !(matchesSearch && matchesSize);
        });
    };
    requestSearch?.addEventListener('input', filterRequests);
    requestFilter?.addEventListener('change', filterRequests);
    document.querySelectorAll('[data-confirm-accept]').forEach((button) => button.addEventListener('click', () => {
        closeModal(button.closest('[data-modal]'));
        showToast('Delivery request claimed. It now appears in the pickup workflow.', 'Order accepted successfully');
    }));

    // Pickup verification checklist
    const pickupChecks = [...document.querySelectorAll('[data-pickup-check]')];
    const pickupConfirm = document.querySelector('[data-confirm-pickup]');
    const updatePickupButton = () => {
        if (!pickupConfirm || !pickupChecks.length) return;
        pickupConfirm.disabled = !pickupChecks.every((check) => check.checked);
        pickupConfirm.style.opacity = pickupConfirm.disabled ? '.55' : '1';
    };
    pickupChecks.forEach((check) => check.addEventListener('change', updatePickupButton));
    updatePickupButton();
    pickupConfirm?.addEventListener('click', () => showToast('Pickup confirmed. Order status is now In Transit in this preview.', 'Item pickup confirmed'));

    // Transit progress
    document.querySelectorAll('[data-transit-action]').forEach((button) => button.addEventListener('click', () => {
        const target = button.dataset.transitAction;
        document.querySelectorAll('[data-transit-step]').forEach((step) => {
            const order = Number(step.dataset.order);
            const targetOrder = target === 'arrived' ? 4 : 3;
            step.classList.toggle('is-done', order < targetOrder);
            step.classList.toggle('is-current', order === targetOrder);
        });
        showToast(target === 'arrived' ? 'Arrival at customer location recorded.' : 'Delivery marked as In Transit.', 'Delivery status updated');
    }));

    // OTP / delivery completion
    const otpInputs = [...document.querySelectorAll('[data-otp]')];
    otpInputs.forEach((input, index) => {
        input.addEventListener('input', () => {
            input.value = input.value.replace(/\D/g, '').slice(0, 1);
            if (input.value && otpInputs[index + 1]) otpInputs[index + 1].focus();
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Backspace' && !input.value && otpInputs[index - 1]) otpInputs[index - 1].focus();
        });
    });
    document.querySelector('[data-complete-delivery]')?.addEventListener('click', () => {
        const receiver = document.querySelector('[data-receiver-name]')?.value.trim();
        const otp = otpInputs.map((input) => input.value).join('');
        if (!receiver || otp.length !== otpInputs.length) {
            showToast('Enter the receiver name and complete the 6-digit OTP first.', 'Verification incomplete');
            return;
        }
        showToast('Delivery marked completed in the static preview.', 'Delivery completed');
    });

    // Earnings period controls
    document.querySelectorAll('[data-earnings-period]').forEach((button) => button.addEventListener('click', () => {
        document.querySelectorAll('[data-earnings-period]').forEach((item) => item.classList.remove('is-active'));
        button.classList.add('is-active');
        showToast(`${button.textContent.trim()} earnings view selected.`, 'Earnings filter');
    }));

    // Chat
    const conversationSearch = document.querySelector('[data-conversation-search]');
    conversationSearch?.addEventListener('input', () => {
        const query = conversationSearch.value.trim().toLowerCase();
        document.querySelectorAll('[data-conversation-item]').forEach((item) => item.hidden = !!query && !item.dataset.search.includes(query));
    });
    document.querySelectorAll('[data-conversation-item]').forEach((item) => item.addEventListener('click', () => {
        document.querySelectorAll('[data-conversation-item]').forEach((other) => other.classList.remove('is-active'));
        item.classList.add('is-active');
        const name = document.querySelector('[data-chat-name]');
        const role = document.querySelector('[data-chat-role]');
        const avatar = document.querySelector('[data-chat-avatar]');
        if (name) name.textContent = item.dataset.name;
        if (role) role.textContent = item.dataset.role;
        if (avatar) avatar.textContent = item.dataset.initials;
    }));
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
        showToast('Message added to the static courier conversation.', 'Message sent');
    };
    document.querySelector('[data-send-message]')?.addEventListener('click', sendMessage);
    messageInput?.addEventListener('keydown', (event) => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); sendMessage(); } });

    // Address mock add
    document.querySelector('[data-add-address]')?.addEventListener('click', () => showToast('Address form would open here during backend integration.', 'Address manager'));
});
