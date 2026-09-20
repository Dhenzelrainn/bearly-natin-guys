const STORAGE_KEY = 'bearlyLogisticsStateV2';

const readState = () => {
    try { return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {}; } catch { return {}; }
};
const writeState = (next) => localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
const updateState = (section, key, value) => {
    const state = readState();
    state[section] = state[section] || {};
    state[section][key] = value;
    writeState(state);
};
const normalize = (value) => String(value || '').trim().toLowerCase().replaceAll('_', ' ');
const prettyStatus = (value) => String(value || '').replaceAll('_', ' ').toLowerCase().replace(/\b\w/g, (letter) => letter.toUpperCase());
const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;' })[character]);
const refreshIcons = () => window.lucide?.createIcons();

function toast(message) {
    const stack = document.querySelector('[data-toast-stack]');
    if (!stack) return;
    const item = document.createElement('div');
    item.className = 'toast';
    item.innerHTML = `<i data-lucide="circle-check"></i><span>${message}</span>`;
    stack.append(item);
    refreshIcons();
    setTimeout(() => item.remove(), 3200);
}

function setupShell() {
    const body = document.body;
    if (localStorage.getItem('bearlyLogisticsSidebar') === 'collapsed') body.classList.add('sidebar-collapsed');
    document.querySelector('[data-sidebar-collapse]')?.addEventListener('click', () => {
        body.classList.toggle('sidebar-collapsed');
        localStorage.setItem('bearlyLogisticsSidebar', body.classList.contains('sidebar-collapsed') ? 'collapsed' : 'open');
    });
    const closeMobile = () => body.classList.remove('sidebar-open');
    document.querySelector('[data-mobile-menu]')?.addEventListener('click', () => body.classList.add('sidebar-open'));
    document.querySelector('[data-overlay]')?.addEventListener('click', closeMobile);
    document.querySelectorAll('[data-dismiss]').forEach((button) => button.addEventListener('click', () => button.closest('.flash-banner')?.remove()));
    document.querySelectorAll('[data-preview-action]').forEach((button) => button.addEventListener('click', () => {
        toast(button.dataset.success || 'Action completed in this front-end preview.');
    }));

    document.querySelectorAll('[data-popover-toggle]').forEach((button) => button.addEventListener('click', (event) => {
        event.stopPropagation();
        const name = button.dataset.popoverToggle;
        document.querySelectorAll('[data-popover]').forEach((popover) => {
            popover.hidden = popover.dataset.popover !== name || !popover.hidden;
        });
    }));
    document.addEventListener('click', (event) => {
        if (!event.target.closest('.popover-wrap')) document.querySelectorAll('[data-popover]').forEach((popover) => { popover.hidden = true; });
    });
    document.querySelector('[data-mark-notifications]')?.addEventListener('click', () => {
        document.querySelectorAll('.notification-row').forEach((row) => row.remove());
        document.querySelector('.notification-dot')?.remove();
        toast('Notifications marked as read.');
    });
}

function setupModals() {
    const backdrop = document.querySelector('[data-modal-backdrop]');
    const closeAll = () => {
        document.querySelectorAll('.modal').forEach((modal) => { modal.hidden = true; });
        if (backdrop) backdrop.hidden = true;
        document.body.style.overflow = '';
    };
    document.querySelectorAll('[data-modal-open]').forEach((button) => button.addEventListener('click', () => {
        const modal = document.querySelector(`[data-modal="${button.dataset.modalOpen}"]`);
        if (!modal) return;
        modal.hidden = false;
        if (backdrop) backdrop.hidden = false;
        document.body.style.overflow = 'hidden';
        modal.querySelector('input, select, textarea')?.focus();
    }));
    document.querySelectorAll('[data-modal-close]').forEach((button) => button.addEventListener('click', closeAll));
    backdrop?.addEventListener('click', closeAll);
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeAll(); });
    window.closeLogisticsModals = closeAll;
}

function setupTabs() {
    document.querySelectorAll('[data-tabs]').forEach((tabs) => {
        tabs.querySelectorAll('[data-tab]').forEach((button) => button.addEventListener('click', () => {
            tabs.querySelectorAll('[data-tab]').forEach((item) => item.classList.toggle('is-active', item === button));
            const scope = tabs.closest('[data-tab-scope]') || document;
            scope.querySelectorAll('[data-tab-panel]').forEach((panel) => { panel.hidden = panel.dataset.tabPanel !== button.dataset.tab; });
        }));
    });
}

function setupTables() {
    document.querySelectorAll('[data-table-scope]').forEach((scope) => {
        const rows = [...scope.querySelectorAll('tbody tr[data-row]')];
        const empty = scope.querySelector('[data-empty-row]');
        const apply = () => {
            const query = normalize(scope.querySelector('[data-table-search]')?.value);
            const status = normalize(scope.querySelector('[data-filter-status]')?.value);
            const zone = normalize(scope.querySelector('[data-filter-zone]')?.value);
            let shown = 0;
            rows.forEach((row) => {
                const matches = (!query || normalize(row.textContent).includes(query)) && (!status || normalize(row.dataset.status) === status) && (!zone || normalize(row.dataset.zone) === zone);
                row.classList.toggle('is-filtered-out', !matches);
                if (matches) shown++;
            });
            if (empty) empty.style.display = shown ? 'none' : 'table-cell';
        };
        scope.querySelectorAll('[data-table-search], [data-filter-status], [data-filter-zone]').forEach((field) => field.addEventListener(field.tagName === 'INPUT' ? 'input' : 'change', apply));
        scope.querySelectorAll('th[data-sort]').forEach((header) => header.addEventListener('click', () => {
            const index = [...header.parentElement.children].indexOf(header);
            const direction = header.dataset.direction === 'asc' ? 'desc' : 'asc';
            header.dataset.direction = direction;
            rows.sort((a, b) => a.children[index].innerText.localeCompare(b.children[index].innerText, undefined, { numeric: true }) * (direction === 'asc' ? 1 : -1));
            rows.forEach((row) => row.parentElement.append(row));
        }));
    });
}

function setRowStatus(row, status) {
    row.dataset.status = status;
    const badge = row.querySelector('[data-status-badge]');
    if (badge) { badge.dataset.status = status; badge.textContent = prettyStatus(status); }
}

function setupStatusActions() {
    const state = readState();
    document.querySelectorAll('[data-record-row]').forEach((row) => {
        const saved = state[row.dataset.recordType]?.[row.dataset.recordId];
        if (typeof saved === 'string') setRowStatus(row, saved);
    });
    document.querySelectorAll('[data-set-status]').forEach((button) => button.addEventListener('click', () => {
        let row = button.closest('[data-record-row]');
        if (!row) {
            const modalName = button.closest('[data-modal]')?.dataset.modal || '';
            const recordId = modalName.replace(/^(review|pickup|delivery)-/, '');
            row = [...document.querySelectorAll('[data-record-row]')].find((item) => item.dataset.recordId === recordId);
        }
        if (!row) return;
        const status = button.dataset.setStatus;
        setRowStatus(row, status);
        updateState(row.dataset.recordType, row.dataset.recordId, status);
        if (button.dataset.removeOnAction === 'true') row.classList.add('is-filtered-out');
        window.closeLogisticsModals?.();
        toast(button.dataset.success || `Status updated to ${prettyStatus(status)}.`);
    }));
    document.querySelectorAll('[data-toggle-record]').forEach((button) => button.addEventListener('click', () => {
        const row = button.closest('[data-record-row]');
        const next = normalize(row?.dataset.status) === 'active' ? 'Inactive' : 'Active';
        if (!row) return;
        setRowStatus(row, next);
        updateState(row.dataset.recordType, row.dataset.recordId, next);
        button.textContent = next === 'Active' ? 'Deactivate' : 'Activate';
        toast(`${row.dataset.recordId} is now ${next.toLowerCase()}.`);
    }));
}

function setupIncoming() {
    const form = document.querySelector('[data-incoming-form]');
    const tbody = document.querySelector('[data-incoming-body]');
    if (!form || !tbody) return;
    const addRow = (data, received = 'Just now') => {
        if ([...tbody.querySelectorAll('[data-record-id]')].some((item) => item.dataset.recordId === data.waybill)) return;
        const row = document.createElement('tr');
        row.dataset.row = '';
        row.dataset.recordRow = '';
        row.dataset.recordType = 'incoming';
        row.dataset.recordId = data.waybill;
        row.dataset.status = 'AT_SORTING_CENTER';
        row.dataset.zone = data.destination;
        row.innerHTML = `<td><span class="cell-title"><strong>${escapeHtml(data.waybill)}</strong><small>${escapeHtml(data.order)}</small></span></td><td>${escapeHtml(data.seller)}</td><td>${escapeHtml(data.rider)}</td><td>${escapeHtml(received)}</td><td>${escapeHtml(data.pieces)}</td><td>${escapeHtml(data.weight)} kg</td><td>${escapeHtml(data.destination)}</td><td><span class="status-badge is-info" data-status-badge data-status="AT_SORTING_CENTER">At Sorting Center</span></td><td><button class="button button-small" type="button" data-send-sorting>Send to sorting</button></td>`;
        tbody.prepend(row);
    };
    [...(readState().incomingRows || [])].reverse().forEach((item) => addRow(item, 'Saved preview'));
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        const data = Object.fromEntries(new FormData(form));
        addRow(data);
        const state = readState();
        state.incomingRows = [data, ...(state.incomingRows || [])].slice(0, 25);
        writeState(state);
        form.reset();
        window.closeLogisticsModals?.();
        toast(`${data.waybill} logged at the sorting center.`);
        refreshIcons();
    });
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-send-sorting]');
        if (!button) return;
        const row = button.closest('tr');
        setRowStatus(row, 'Ready for Sorting');
        updateState('incoming', row.dataset.recordId, 'Ready for Sorting');
        button.disabled = true;
        button.textContent = 'Queued';
        toast('Parcel added to the sorting queue.');
    });
}

function setupSorting() {
    document.querySelectorAll('[data-sort-parcel]').forEach((button) => button.addEventListener('click', () => {
        const row = button.closest('[data-record-row]');
        const select = row?.querySelector('[data-zone-select]');
        if (!row || !select?.value) { toast('Select a destination zone first.'); return; }
        row.dataset.zone = select.value;
        setRowStatus(row, 'Sorted');
        updateState('sorting', row.dataset.recordId, { status: 'Sorted', zone: select.value });
        toast(`${row.dataset.recordId} sorted to ${select.value}.`);
    }));
    const state = readState();
    document.querySelectorAll('[data-record-type="sorting"]').forEach((row) => {
        const saved = state.sorting?.[row.dataset.recordId];
        if (saved && typeof saved === 'object') {
            setRowStatus(row, saved.status);
            const select = row.querySelector('[data-zone-select]');
            if (select) select.value = saved.zone;
        }
    });
}

function setupDispatch() {
    const state = readState();
    document.querySelectorAll('[data-dispatch-zone]').forEach((row) => {
        const saved = state.dispatch?.[row.dataset.dispatchZone];
        if (saved) { row.querySelector('[data-rider-select]').value = saved.rider; row.querySelector('[data-dispatch-status]').textContent = `Assigned to ${saved.rider}`; }
    });
    document.querySelectorAll('[data-dispatch-action]').forEach((button) => button.addEventListener('click', () => {
        const row = button.closest('[data-dispatch-zone]');
        const rider = row.querySelector('[data-rider-select]').value;
        if (!rider) { toast('Choose an active rider before dispatching.'); return; }
        updateState('dispatch', row.dataset.dispatchZone, { rider, status: 'ASSIGNED_TO_RIDER', updatedAt: new Date().toISOString() });
        row.querySelector('[data-dispatch-status]').textContent = `Assigned to ${rider}`;
        button.textContent = 'Reassign';
        toast(`${row.dataset.dispatchZone} assigned to ${rider}.`);
    }));
}

function setupReports() {
    document.querySelector('[data-report-apply]')?.addEventListener('click', () => toast('Report preview refreshed for the selected range.'));
    document.querySelector('[data-report-export]')?.addEventListener('click', () => {
        const rows = [['Rider','Assigned','Delivered','Failed','Success Rate']];
        document.querySelectorAll('[data-report-row]').forEach((row) => rows.push([...row.children].map((cell) => cell.innerText.trim())));
        const blob = new Blob([rows.map((row) => row.map((value) => `"${value.replaceAll('"', '""')}"`).join(',')).join('\n')], { type: 'text/csv' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'bearly-logistics-report.csv';
        link.click();
        URL.revokeObjectURL(link.href);
        toast('CSV report exported.');
    });
}

function setupChat() {
    const conversations = [...document.querySelectorAll('[data-conversation]')];
    const name = document.querySelector('[data-chat-name]');
    const role = document.querySelector('[data-chat-role]');
    const messages = document.querySelector('[data-chat-messages]');
    const input = document.querySelector('[data-chat-input]');
    if (!conversations.length || !messages) return;
    let active = conversations[0].dataset.conversation;
    const baseThreads = {
        'techvault-ph': [{ mine:false, text:'Good afternoon! Our six-parcel pickup batch is ready at the counter.', time:'2:07 PM' },{ mine:true, text:'Received. The request is verified and a rider will arrive within your selected window.', time:'2:10 PM' }],
        'nico-flores': [{ mine:false, text:'I finished the SP-N1 route. All eight parcels were delivered.', time:'1:51 PM' }],
        'bearly-admin': [{ mine:false, text:'Please review the three pending rider credentials before the 4 PM dispatch.', time:'11:24 AM' }],
    };
    const render = () => {
        const button = conversations.find((item) => item.dataset.conversation === active);
        if (name) name.textContent = button?.dataset.name || 'Conversation';
        if (role) role.textContent = button?.dataset.role || '';
        const saved = readState().messages?.[active] || [];
        messages.innerHTML = [...(baseThreads[active] || []), ...saved].map((item) => `<div class="message-bubble ${item.mine ? 'is-mine' : ''}">${escapeHtml(item.text)}<small>${escapeHtml(item.time)}</small></div>`).join('');
        messages.scrollTop = messages.scrollHeight;
    };
    conversations.forEach((button) => button.addEventListener('click', () => {
        active = button.dataset.conversation;
        conversations.forEach((item) => item.classList.toggle('is-active', item === button));
        render();
    }));
    const send = () => {
        const text = input?.value.trim();
        if (!text) return;
        const state = readState();
        state.messages = state.messages || {};
        state.messages[active] = state.messages[active] || [];
        state.messages[active].push({ mine:true, text, time:new Date().toLocaleTimeString([], { hour:'numeric', minute:'2-digit' }) });
        writeState(state);
        input.value = '';
        render();
    };
    document.querySelector('[data-chat-send]')?.addEventListener('click', send);
    input?.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); send(); } });
    render();
}

function setupAccount() {
    document.querySelectorAll('[data-preview-form]').forEach((form) => form.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        updateState('forms', form.dataset.previewForm, Object.fromEntries(new FormData(form)));
        toast(form.dataset.success || 'Changes saved in this preview.');
    }));
    const passwordForm = document.querySelector('[data-password-form]');
    passwordForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        const next = passwordForm.querySelector('[name="new_password"]').value;
        const confirm = passwordForm.querySelector('[name="new_password_confirmation"]').value;
        if (next.length < 8 || next !== confirm) { toast('Passwords must match and contain at least 8 characters.'); return; }
        passwordForm.reset();
        toast('Password update validated for this preview.');
    });
}

function setupRegistration() {
    const form = document.querySelector('[data-registration-form]');
    if (!form) return;
    let step = 1;
    const show = (next) => {
        step = next;
        document.querySelectorAll('[data-form-step]').forEach((panel) => { panel.hidden = Number(panel.dataset.formStep) !== step; });
        document.querySelectorAll('[data-step-marker]').forEach((marker) => marker.classList.toggle('is-active', Number(marker.dataset.stepMarker) === step));
    };
    const validateStep = () => [...form.querySelector(`[data-form-step="${step}"]`).querySelectorAll('input,select')].every((field) => field.reportValidity());
    document.querySelectorAll('[data-step-next]').forEach((button) => button.addEventListener('click', () => { if (validateStep()) show(Math.min(3, step + 1)); }));
    document.querySelectorAll('[data-step-back]').forEach((button) => button.addEventListener('click', () => show(Math.max(1, step - 1))));
    const birthday = form.querySelector('[name="birthday"]');
    birthday?.addEventListener('change', () => {
        const birth = new Date(birthday.value); const now = new Date();
        let age = now.getFullYear() - birth.getFullYear();
        if (now < new Date(now.getFullYear(), birth.getMonth(), birth.getDate())) age--;
        const field = form.querySelector('[data-age]'); if (field) field.value = Number.isFinite(age) ? age : '';
    });
    form.querySelectorAll('input[type="file"]').forEach((input) => input.addEventListener('change', () => {
        const label = input.closest('.upload-field')?.querySelector('[data-file-name]');
        if (label) label.textContent = input.files?.[0]?.name || 'Choose a JPG, PNG, or PDF file';
    }));
    const province = form.querySelector('[data-province]');
    const city = form.querySelector('[data-city]');
    const barangay = form.querySelector('[data-barangay]');
    const fill = (select, items, placeholder) => { select.innerHTML = `<option value="">${placeholder}</option>` + items.map((item) => `<option value="${item.name}" data-code="${item.code}">${item.name}</option>`).join(''); select.disabled = false; };
    const fallbackLocations = (url) => {
        if (url.endsWith('/provinces')) return [{ name:'Laguna', code:'043400000' },{ name:'Batangas', code:'041000000' },{ name:'Cavite', code:'042100000' },{ name:'Quezon', code:'045600000' }];
        if (url.includes('/provinces/043400000/')) return [{ name:'San Pablo City', code:'043426000' },{ name:'Santa Cruz', code:'043428000' },{ name:'Calauan', code:'043405000' },{ name:'Pila', code:'043419000' },{ name:'Bay', code:'043403000' }];
        if (url.includes('/provinces/')) return [{ name:'Provincial Capital', code:'000000001' },{ name:'Service Municipality', code:'000000002' }];
        return [{ name:'Poblacion', code:'000000101' },{ name:'San Antonio', code:'000000102' },{ name:'San Rafael', code:'000000103' },{ name:'Del Remedio', code:'000000104' }];
    };
    const load = async (url, select, placeholder) => {
        select.disabled = true; select.innerHTML = '<option>Loading…</option>';
        try { const response = await fetch(url); if (!response.ok) throw new Error(); const payload = await response.json(); const items = Array.isArray(payload) ? payload : payload.data; if (!Array.isArray(items)) throw new Error(); fill(select, items, placeholder); }
        catch { fill(select, fallbackLocations(url), `${placeholder} (preview list)`); toast('Using the offline preview location list.'); }
    };
    if (province && city && barangay) {
        load('/api/psgc/provinces', province, 'Select province');
        province.addEventListener('change', () => { const code = province.selectedOptions[0]?.dataset.code; if (code) load(`/api/psgc/provinces/${code}/cities`, city, 'Select municipality / city'); });
        city.addEventListener('change', () => { const code = city.selectedOptions[0]?.dataset.code; if (code) load(`/api/psgc/cities/${code}/barangays`, barangay, 'Select barangay'); });
    }
    show(1);
}

document.addEventListener('DOMContentLoaded', () => {
    setupShell(); setupModals(); setupTabs(); setupIncoming(); setupTables(); setupStatusActions(); setupSorting(); setupDispatch(); setupReports(); setupChat(); setupAccount(); setupRegistration(); refreshIcons();
});
