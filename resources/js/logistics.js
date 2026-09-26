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

function setupRegistrationEmail(form) {
    const email = form.elements.email;
    const send = form.querySelector('[data-email-send]');
    const check = form.querySelector('[data-email-check]');
    const code = form.querySelector('[data-email-code]');
    const entry = form.querySelector('[data-email-code-entry]');
    const status = form.querySelector('[data-email-status]');

    if (!email || !send || !check || !code || !entry || !status) {
        return { validate: () => true };
    }

    let verifiedEmail = '';
    let verifiedUntil = 0;
    let sentEmail = '';
    let busy = false;
    let cooldownUntil = 0;
    let generation = 0;

    const normalizedEmail = () =>
        String(email.value || '').trim().toLowerCase();

    const setStatus = (message, state = 'info') => {
        status.textContent = message;
        status.dataset.state = state;
    };

    const resetVerification = () => {
        generation++;
        verifiedEmail = '';
        verifiedUntil = 0;
        sentEmail = '';
        entry.hidden = true;
        code.value = '';
        setStatus('Verify your email address to continue.');
    };

    const validEmail = () => {
        email.value = normalizedEmail();
        email.setCustomValidity('');

        if (!email.value || !email.checkValidity()) {
            email.reportValidity();
            email.focus();
            return false;
        }

        return true;
    };

    async function post(url, extra = {}) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': form.elements._token.value,
            },
            body: JSON.stringify({
                email: normalizedEmail(),
                ...extra,
            }),
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            if (response.status === 429) {
                cooldownUntil =
                    Date.now() +
                    Number(response.headers.get('Retry-After') || 60) * 1000;
            }

            throw new Error(
                response.status === 419
                    ? 'Your session expired. Refresh the page and try again.'
                    : Object.values(data.errors || {}).flat()[0] ||
                      (
                          response.status === 429
                              ? 'Too many requests. Please wait before trying again.'
                              : 'Email verification is unavailable. Please try again.'
                      )
            );
        }

        return data;
    }

    const refresh = () => {
        const secondsLeft = Math.max(
            0,
            Math.ceil((cooldownUntil - Date.now()) / 1000)
        );

        const isVerified =
            verifiedEmail === normalizedEmail() &&
            Date.now() < verifiedUntil;

        send.disabled = busy || secondsLeft > 0 || isVerified;
        check.disabled = busy;

        send.textContent = busy
            ? 'Please wait…'
            : isVerified
                ? 'Verified'
                : secondsLeft
                    ? `Resend in ${secondsLeft}s`
                    : sentEmail === normalizedEmail()
                        ? 'Resend code'
                        : 'Send code';

        if (verifiedEmail && Date.now() >= verifiedUntil) {
            resetVerification();
            setStatus(
                'Verification expired. Please verify your email again.',
                'error'
            );
        }
    };

    email.addEventListener('input', () => {
        resetVerification();
        refresh();
    });

    code.addEventListener('input', () => {
        code.value = code.value
            .replace(/[^0-9]/g, '')
            .slice(0, 6);
    });

    send.addEventListener('click', async () => {
        if (busy || Date.now() < cooldownUntil || !validEmail()) return;

        busy = true;
        resetVerification();

        const requestGeneration = generation;
        const requestedEmail = normalizedEmail();

        refresh();
        setStatus('Sending verification code…');

        try {
            const data = await post(form.dataset.emailSend);

            cooldownUntil =
                Date.now() + Number(data.retry_after || 60) * 1000;

            if (requestGeneration !== generation) return;

            sentEmail = requestedEmail;
            entry.hidden = false;
            setStatus(
                data.message ||
                'Verification code sent. Check your inbox.'
            );
            code.focus();
        } catch (error) {
            if (requestGeneration === generation) {
                setStatus(error.message, 'error');
            }
        } finally {
            busy = false;
            refresh();
        }
    });

    check.addEventListener('click', async () => {
        if (busy || !validEmail()) return;

        if (normalizedEmail() !== sentEmail) {
            resetVerification();
            setStatus(
                'The email address changed. Request a new code.',
                'error'
            );
            return;
        }

        if (!/^[0-9]{6}$/.test(code.value)) {
            setStatus(
                'Enter the 6-digit code from your email.',
                'error'
            );
            code.focus();
            return;
        }

        busy = true;

        const requestGeneration = generation;
        const requestedEmail = normalizedEmail();

        refresh();

        try {
            const data = await post(
                form.dataset.emailCheck,
                { code: code.value }
            );

            if (requestGeneration !== generation) return;

            verifiedEmail = requestedEmail;
            verifiedUntil =
                Date.now() + Number(data.expires_in || 900) * 1000;

            entry.hidden = true;
            code.value = '';
            setStatus(
                data.message || 'Email address verified.',
                'verified'
            );
        } catch (error) {
            if (requestGeneration === generation) {
                setStatus(error.message, 'error');
            }
        } finally {
            busy = false;
            refresh();
        }
    });

    resetVerification();
    refresh();
    window.setInterval(refresh, 1000);

    return {
        validate: () => {
            if (!validEmail()) return false;

            if (
                verifiedEmail !== normalizedEmail() ||
                Date.now() >= verifiedUntil
            ) {
                setStatus(
                    'Verify your email address before continuing.',
                    'error'
                );
                send.focus();
                return false;
            }

            return true;
        },
    };
}

function setupRegistrationPassword(form) {
    const password = form.querySelector('[data-registration-password]');
    const confirmation = form.querySelector(
        '[data-registration-password-confirmation]'
    );

    if (!password || !confirmation) {
        return {
            prepareField: () => {},
            validate: () => true,
        };
    }

    const meter = form.querySelector('.registration-password-meter');
    const strengthLabel = form.querySelector(
        '[data-password-strength-label]'
    );
    const status = form.querySelector('[data-password-status]');
    const matchStatus = form.querySelector('[data-password-match]');

    const ruleElements = {
        length: form.querySelector('[data-password-rule="length"]'),
        uppercase: form.querySelector('[data-password-rule="uppercase"]'),
        lowercase: form.querySelector('[data-password-rule="lowercase"]'),
        number: form.querySelector('[data-password-rule="number"]'),
    };

    const evaluate = (value) => ({
        length: value.length >= 8,
        uppercase: /[A-Z]/.test(value),
        lowercase: /[a-z]/.test(value),
        number: /[0-9]/.test(value),
    });

    const passwordMessage = (value) => {
        const rules = evaluate(value);

        if (!rules.length) {
            return 'Password must be at least 8 characters long.';
        }
        if (!rules.uppercase) {
            return 'Password must contain at least one uppercase letter.';
        }
        if (!rules.lowercase) {
            return 'Password must contain at least one lowercase letter.';
        }
        if (!rules.number) {
            return 'Password must contain at least one number.';
        }

        return '';
    };

    const update = () => {
        const rules = evaluate(password.value);
        const score = Object.values(rules).filter(Boolean).length;

        Object.entries(rules).forEach(([name, met]) => {
            ruleElements[name]?.classList.toggle('is-met', met);
        });

        meter?.style.setProperty('--strength', `${score * 25}%`);

        if (strengthLabel) {
            const labels = ['Not set', 'Weak', 'Fair', 'Good', 'Strong'];
            strengthLabel.textContent =
                password.value ? labels[score] : labels[0];
            strengthLabel.dataset.strength = String(score);
        }

        if (status) {
            if (!password.value) {
                status.textContent =
                    'Meet all four requirements to continue.';
                status.dataset.state = 'neutral';
            } else if (score === 4) {
                status.textContent =
                    'Password meets all requirements.';
                status.dataset.state = 'success';
            } else {
                status.textContent = passwordMessage(password.value);
                status.dataset.state = 'error';
            }
        }

        if (matchStatus) {
            if (!confirmation.value) {
                matchStatus.textContent = '';
                matchStatus.dataset.state = 'neutral';
            } else if (confirmation.value === password.value) {
                matchStatus.textContent = 'Passwords match.';
                matchStatus.dataset.state = 'success';
            } else {
                matchStatus.textContent = 'Passwords do not match.';
                matchStatus.dataset.state = 'error';
            }
        }
    };

    const prepareField = (field) => {
        if (field === password) {
            field.setCustomValidity(passwordMessage(field.value));
        }

        if (field === confirmation) {
            field.setCustomValidity(
                field.value === password.value
                    ? ''
                    : 'Passwords do not match.'
            );
        }
    };

    form
        .querySelectorAll('[data-toggle-password]')
        .forEach((button) => {
            button.addEventListener('click', () => {
                const input = document.getElementById(
                    button.dataset.togglePassword
                );

                if (!input) return;

                input.type =
                    input.type === 'password'
                        ? 'text'
                        : 'password';

                const isVisible = input.type === 'text';

                button.setAttribute(
                    'aria-label',
                    isVisible ? 'Hide password' : 'Show password'
                );
                button.setAttribute(
                    'aria-pressed',
                    String(isVisible)
                );
            });
        });

    password.addEventListener('input', () => {
        password.setCustomValidity('');
        confirmation.setCustomValidity('');
        update();
    });

    confirmation.addEventListener('input', () => {
        confirmation.setCustomValidity('');
        update();
    });

    update();

    return {
        prepareField,
        validate: () => {
            prepareField(password);

            if (!password.checkValidity()) {
                password.reportValidity();
                password.focus();
                return false;
            }

            prepareField(confirmation);

            if (!confirmation.checkValidity()) {
                confirmation.reportValidity();
                confirmation.focus();
                return false;
            }

            return true;
        },
    };
}

function setupMiddleInitial(form) {
    const middle = form.querySelector('[data-middle-initial]');
    if (!middle) return;

    const getLetter = () =>
        middle.value
            .replace(/[^A-Za-z]/g, '')
            .slice(0, 1)
            .toUpperCase();

    middle.addEventListener('focus', () => {
        middle.value = getLetter();
    });

    middle.addEventListener('input', () => {
        middle.value = getLetter();
    });

    middle.addEventListener('blur', () => {
        const letter = getLetter();
        middle.value = letter ? `${letter}.` : '';
    });
}

function setupRegistration() {
    const form = document.querySelector('[data-registration-form]');
    if (!form) return;

    let step = 1;

    const emailVerification = setupRegistrationEmail(form);
    const passwordUx = setupRegistrationPassword(form);

    setupMiddleInitial(form);

    const province = form.querySelector('[data-province]');
    const city = form.querySelector('[data-city]');
    const barangay = form.querySelector('[data-barangay]');

    const postalCode = form.querySelector('[data-postal-code]');
    const postalChoices = form.querySelector('[data-postal-choices]');
    const postalHelp = form.querySelector('[data-postal-help]');
    const cityCodeField = form.elements.city_code;
    const postalManualField = form.elements.postal_manual;

    /*
     * Searchable address dropdowns
     * Same interaction pattern as Buyer/Seller:
     * click the select-looking trigger -> search field appears inside panel.
     */
    const searchableSelects = new Map();

    const normalizeSearchText = (value) =>
        String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLocaleLowerCase('en-PH')
            .trim();

    const closeSearchableSelect = (
        select,
        returnFocus = false
    ) => {
        const widget = searchableSelects.get(select);

        if (!widget || widget.panel.hidden) return;

        widget.panel.hidden = true;
        widget.trigger.setAttribute('aria-expanded', 'false');
        widget.wrapper.classList.remove('is-open');
        widget.search.value = '';
        widget.renderOptions();

        if (returnFocus) {
            widget.trigger.focus();
        }
    };

    const closeOtherSearchableSelects = (currentSelect) => {
        searchableSelects.forEach((widget, select) => {
            if (select !== currentSelect) {
                closeSearchableSelect(select);
            }
        });
    };

    const openSearchableSelect = (select) => {
        const widget = searchableSelects.get(select);

        if (!widget || select.disabled) return;

        closeOtherSearchableSelects(select);

        widget.panel.hidden = false;
        widget.trigger.setAttribute('aria-expanded', 'true');
        widget.wrapper.classList.add('is-open');
        widget.search.value = '';
        widget.renderOptions();

        window.requestAnimationFrame(() => {
            widget.search.focus();
        });
    };

    const syncSearchableSelect = (select) => {
        const widget = searchableSelects.get(select);
        if (!widget) return;

        const selected = select.selectedOptions?.[0];

        widget.value.textContent =
            selected?.textContent || 'Select an option';

        widget.value.classList.toggle(
            'is-placeholder',
            !select.value
        );

        widget.trigger.disabled = select.disabled;
        widget.trigger.setAttribute(
            'aria-disabled',
            String(select.disabled)
        );

        widget.wrapper.classList.toggle(
            'is-disabled',
            select.disabled
        );

        widget.wrapper.classList.remove('is-invalid');

        if (select.disabled) {
            closeSearchableSelect(select);
        }

        widget.renderOptions();
    };

    const createSearchableSelect = (select) => {
        if (!select || searchableSelects.has(select)) return;

        const wrapper = document.createElement('div');
        wrapper.className = 'searchable-select';

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'searchable-select__trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.innerHTML = `
            <span class="searchable-select__value"></span>
            <svg viewBox="0 0 20 20" aria-hidden="true">
                <path d="m5 7.5 5 5 5-5"></path>
            </svg>
        `;

        const panel = document.createElement('div');
        panel.className = 'searchable-select__panel';
        panel.hidden = true;
        panel.innerHTML = `
            <div class="searchable-select__search-wrap">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="11" cy="11" r="7"></circle>
                    <path d="m20 20-4-4"></path>
                </svg>

                <input
                    type="search"
                    class="searchable-select__search"
                    autocomplete="off"
                    spellcheck="false"
                >
            </div>

            <ul
                class="searchable-select__options"
                role="listbox"
            ></ul>

            <p
                class="searchable-select__empty"
                hidden
            >
                No locations found
            </p>

            <p
                class="searchable-select__count"
                aria-live="polite"
            ></p>
        `;

        select.parentNode.insertBefore(wrapper, select);
        wrapper.append(select, trigger, panel);

        select.classList.add('searchable-select__native');

        const value =
            trigger.querySelector('.searchable-select__value');

        const search =
            panel.querySelector('.searchable-select__search');

        const options =
            panel.querySelector('.searchable-select__options');

        const empty =
            panel.querySelector('.searchable-select__empty');

        const count =
            panel.querySelector('.searchable-select__count');

        search.placeholder =
            select.dataset.searchPlaceholder ||
            'Search location';

        search.setAttribute(
            'aria-label',
            search.placeholder
        );

        const widget = {
            wrapper,
            trigger,
            panel,
            value,
            search,
            options,
            empty,
            count,
            activeIndex: -1,
            visibleOptions: [],
            renderOptions: () => {},
        };

        widget.renderOptions = () => {
            const query =
                normalizeSearchText(search.value);

            const availableOptions =
                [...select.options].filter(
                    (option) =>
                        option.value &&
                        !option.disabled
                );

            const matches =
                availableOptions.filter((option) =>
                    normalizeSearchText(
                        option.textContent
                    ).includes(query)
                );

            options.replaceChildren();

            widget.visibleOptions = matches;
            widget.activeIndex = -1;

            matches.forEach((option) => {
                const item =
                    document.createElement('li');

                const button =
                    document.createElement('button');

                const isSelected =
                    option.value === select.value;

                button.type = 'button';
                button.className =
                    'searchable-select__option';

                button.setAttribute(
                    'role',
                    'option'
                );

                button.setAttribute(
                    'aria-selected',
                    String(isSelected)
                );

                button.dataset.value = option.value;

                button.innerHTML = `
                    <span></span>
                    <span
                        class="searchable-select__check"
                        aria-hidden="true"
                    >
                        ${isSelected ? '✓' : ''}
                    </span>
                `;

                button.firstElementChild.textContent =
                    option.textContent;

                button.addEventListener('click', () => {
                    select.value = option.value;
                    select.setCustomValidity('');

                    select.dispatchEvent(
                        new Event(
                            'change',
                            { bubbles: true }
                        )
                    );

                    syncSearchableSelect(select);
                    closeSearchableSelect(
                        select,
                        true
                    );
                });

                item.append(button);
                options.append(item);
            });

            empty.hidden = matches.length !== 0;

            count.textContent =
                `${matches.length} ${
                    matches.length === 1
                        ? 'location'
                        : 'locations'
                } found`;
        };

        searchableSelects.set(select, widget);

        trigger.addEventListener('click', () => {
            if (panel.hidden) {
                openSearchableSelect(select);
            } else {
                closeSearchableSelect(select);
            }
        });

        trigger.addEventListener(
            'keydown',
            (event) => {
                if (
                    [
                        'ArrowDown',
                        'Enter',
                        ' ',
                    ].includes(event.key)
                ) {
                    event.preventDefault();
                    openSearchableSelect(select);
                }
            }
        );

        search.addEventListener(
            'input',
            widget.renderOptions
        );

        search.addEventListener(
            'keydown',
            (event) => {
                const optionButtons = [
                    ...options.querySelectorAll(
                        'button'
                    ),
                ];

                if (event.key === 'Escape') {
                    event.preventDefault();

                    closeSearchableSelect(
                        select,
                        true
                    );

                    return;
                }

                if (
                    ![
                        'ArrowDown',
                        'ArrowUp',
                        'Enter',
                    ].includes(event.key)
                ) {
                    return;
                }

                event.preventDefault();

                if (event.key === 'Enter') {
                    optionButtons[
                        widget.activeIndex
                    ]?.click();

                    return;
                }

                const direction =
                    event.key === 'ArrowDown'
                        ? 1
                        : -1;

                widget.activeIndex = Math.max(
                    0,
                    Math.min(
                        optionButtons.length - 1,
                        widget.activeIndex +
                            direction
                    )
                );

                optionButtons.forEach(
                    (button, index) => {
                        button.classList.toggle(
                            'is-active',
                            index ===
                                widget.activeIndex
                        );
                    }
                );

                optionButtons[
                    widget.activeIndex
                ]?.scrollIntoView({
                    block: 'nearest',
                });
            }
        );

        syncSearchableSelect(select);
    };

    form
        .querySelectorAll('[data-searchable-location]')
        .forEach(createSearchableSelect);

    document.addEventListener(
        'pointerdown',
        (event) => {
            searchableSelects.forEach(
                (widget, select) => {
                    if (
                        !widget.wrapper.contains(
                            event.target
                        )
                    ) {
                        closeSearchableSelect(
                            select
                        );
                    }
                }
            );
        }
    );

    const show = (next) => {
        step = next;

        document
            .querySelectorAll('[data-form-step]')
            .forEach((panel) => {
                panel.hidden =
                    Number(panel.dataset.formStep) !== step;
            });

        document
            .querySelectorAll('[data-step-marker]')
            .forEach((marker) => {
                const markerStep =
                    Number(marker.dataset.stepMarker);

                marker.classList.toggle(
                    'is-active',
                    markerStep === step
                );

                marker.classList.toggle(
                    'is-complete',
                    markerStep < step
                );
            });

        window.scrollTo({
            top: 0,
            behavior: 'smooth',
        });
    };

    const validateStep = () => {
        const panel = form.querySelector(
            `[data-form-step="${step}"]`
        );

        if (!panel) return false;

        const fields = [
            ...panel.querySelectorAll(
                'input, select, textarea'
            ),
        ].filter(
            (field) =>
                !field.disabled &&
                field.type !== 'button' &&
                field.type !== 'submit' &&
                !field.classList.contains(
                    'searchable-select__search'
                )
        );

        for (const field of fields) {
            field.setCustomValidity('');
            passwordUx.prepareField(field);

            if (!field.checkValidity()) {
                const widget =
                    searchableSelects.get(field);

                if (widget) {
                    widget.wrapper.classList.add(
                        'is-invalid'
                    );

                    openSearchableSelect(field);
                    widget.trigger.focus();
                } else {
                    field.reportValidity();
                    field.focus();
                }

                return false;
            }
        }

        if (step === 1) {
            if (!passwordUx.validate()) return false;
            if (!emailVerification.validate()) return false;
        }

        return true;
    };

    document
        .querySelectorAll('[data-step-next]')
        .forEach((button) => {
            button.addEventListener('click', () => {
                if (validateStep()) {
                    show(
                        Math.min(
                            3,
                            step + 1
                        )
                    );
                }
            });
        });

    document
        .querySelectorAll('[data-step-back]')
        .forEach((button) => {
            button.addEventListener('click', () => {
                show(
                    Math.max(
                        1,
                        step - 1
                    )
                );
            });
        });

    const birthday =
        form.querySelector('[name="birthday"]');

    const ageField =
        form.querySelector('[data-age]');

    const updateAge = () => {
        if (!birthday || !ageField) return;

        if (!birthday.value) {
            ageField.value = '';
            return;
        }

        const birth =
            new Date(
                `${birthday.value}T00:00:00`
            );

        const now = new Date();

        let age =
            now.getFullYear() -
            birth.getFullYear();

        if (
            now <
            new Date(
                now.getFullYear(),
                birth.getMonth(),
                birth.getDate()
            )
        ) {
            age--;
        }

        ageField.value =
            Number.isFinite(age) &&
            age >= 0
                ? String(age)
                : '';
    };

    birthday?.addEventListener(
        'change',
        updateAge
    );

    updateAge();

    form
        .querySelectorAll('input[type="file"]')
        .forEach((input) => {
            input.addEventListener(
                'change',
                () => {
                    const label =
                        input
                            .closest(
                                '.upload-field'
                            )
                            ?.querySelector(
                                '[data-file-name]'
                            );

                    if (label) {
                        label.textContent =
                            input.files?.[0]
                                ?.name ||
                            'Choose a JPG, PNG, or PDF file';
                    }
                }
            );
        });

    const fallbackLocations = (url) => {
        if (url.endsWith('/provinces')) {
            return [
                {
                    name: 'Laguna',
                    code: '043400000',
                },
                {
                    name: 'Batangas',
                    code: '041000000',
                },
                {
                    name: 'Cavite',
                    code: '042100000',
                },
                {
                    name: 'Quezon',
                    code: '045600000',
                },
            ];
        }

        if (
            url.includes(
                '/provinces/043400000/'
            )
        ) {
            return [
                {
                    name: 'San Pablo City',
                    code: '043426000',
                },
                {
                    name: 'Santa Cruz',
                    code: '043428000',
                },
                {
                    name: 'Calauan',
                    code: '043405000',
                },
                {
                    name: 'Pila',
                    code: '043419000',
                },
                {
                    name: 'Bay',
                    code: '043403000',
                },
            ];
        }

        if (url.includes('/provinces/')) {
            return [
                {
                    name: 'Provincial Capital',
                    code: '000000001',
                },
                {
                    name: 'Service Municipality',
                    code: '000000002',
                },
            ];
        }

        return [
            {
                name: 'Poblacion',
                code: '000000101',
            },
            {
                name: 'San Antonio',
                code: '000000102',
            },
            {
                name: 'San Rafael',
                code: '000000103',
            },
            {
                name: 'Del Remedio',
                code: '000000104',
            },
        ];
    };

    const fill = (
        select,
        items,
        placeholder
    ) => {
        select.innerHTML =
            `<option value="">${placeholder}</option>` +
            items
                .map(
                    (item) =>
                        `<option value="${escapeHtml(item.name)}" data-code="${escapeHtml(item.code)}">${escapeHtml(item.name)}</option>`
                )
                .join('');

        select.disabled = false;
        syncSearchableSelect(select);
    };

    const load = async (
        url,
        select,
        placeholder
    ) => {
        select.disabled = true;
        select.innerHTML =
            '<option value="">Loading…</option>';

        syncSearchableSelect(select);

        try {
            const response = await fetch(url);

            if (!response.ok) throw new Error();

            const payload =
                await response.json();

            const items =
                Array.isArray(payload)
                    ? payload
                    : payload.data;

            if (!Array.isArray(items)) {
                throw new Error();
            }

            fill(
                select,
                items,
                placeholder
            );
        } catch {
            fill(
                select,
                fallbackLocations(url),
                `${placeholder} (preview list)`
            );

            toast(
                'Using the offline preview location list.'
            );
        }
    };

    const resetPostal = (
        message =
            'Select a municipality to fill this automatically.'
    ) => {
        if (!postalCode) return;

        postalCode.value = '';
        postalCode.readOnly = true;
        postalCode.placeholder =
            'Auto-generated';

        if (postalChoices) {
            postalChoices.hidden = true;
            postalChoices.innerHTML = '';
        }

        if (postalHelp) {
            postalHelp.textContent = message;
            postalHelp.dataset.state = 'info';
        }

        if (cityCodeField) {
            cityCodeField.value = '';
        }

        if (postalManualField) {
            postalManualField.value = '0';
        }
    };

    const postalLabel = (option) => {
        const area =
            option.area ||
            option.label ||
            option.name ||
            option.location ||
            '';

        return area
            ? `${option.code} — ${area}`
            : String(option.code || '');
    };

    const applyPostal = (option) => {
        if (!postalCode || !option?.code) return;

        postalCode.value =
            String(option.code)
                .replace(/\D/g, '')
                .slice(0, 4);

        postalCode.readOnly = true;

        if (postalManualField) {
            postalManualField.value = '0';
        }
    };

    const loadPostalCode = async () => {
        if (
            !postalCode ||
            !city ||
            !province
        ) {
            return;
        }

        const cityOption =
            city.selectedOptions[0];

        const cityCode =
            cityOption?.dataset.code || '';

        resetPostal('Looking up postal code…');

        if (
            !city.value ||
            !province.value
        ) {
            resetPostal();
            return;
        }

        if (cityCodeField) {
            cityCodeField.value = cityCode;
        }

        try {
            const params =
                new URLSearchParams({
                    city_code: cityCode,
                    province: province.value,
                    city: city.value,
                });

            const response =
                await fetch(
                    `${form.dataset.postalUrl}?${params.toString()}`,
                    {
                        headers: {
                            Accept:
                                'application/json',
                        },
                    }
                );

            if (!response.ok) {
                throw new Error();
            }

            const payload =
                await response.json();

            const options =
                Array.isArray(payload.options)
                    ? payload.options.filter(
                        (item) =>
                            item &&
                            /^\d{4}$/.test(
                                String(
                                    item.code ||
                                        ''
                                )
                            )
                    )
                    : [];

            if (options.length === 1) {
                applyPostal(options[0]);

                if (postalHelp) {
                    postalHelp.textContent =
                        `Postal code ${options[0].code} was filled from the selected municipality.`;

                    postalHelp.dataset.state =
                        'success';
                }

                return;
            }

            if (
                options.length > 1 &&
                postalChoices
            ) {
                postalChoices.innerHTML =
                    options
                        .map(
                            (
                                option,
                                index
                            ) =>
                                `<option value="${escapeHtml(option.code)}" data-index="${index}">${escapeHtml(postalLabel(option))}</option>`
                        )
                        .join('');

                postalChoices.hidden = false;

                applyPostal(options[0]);

                postalChoices.onchange = () => {
                    const selected =
                        options[
                            Number(
                                postalChoices
                                    .selectedOptions[0]
                                    ?.dataset
                                    .index || 0
                            )
                        ];

                    applyPostal(selected);
                };

                if (postalHelp) {
                    postalHelp.textContent =
                        'This municipality has more than one postal code. Choose the correct postal area.';

                    postalHelp.dataset.state =
                        'info';
                }

                return;
            }

            postalCode.readOnly = false;
            postalCode.placeholder =
                'Enter 4-digit postal code';

            if (postalManualField) {
                postalManualField.value =
                    '1';
            }

            if (postalHelp) {
                postalHelp.textContent =
                    'No postal code was found for this municipality. Enter the correct 4-digit postal code.';

                postalHelp.dataset.state =
                    'warning';
            }
        } catch {
            postalCode.readOnly = false;
            postalCode.placeholder =
                'Enter 4-digit postal code';

            if (postalManualField) {
                postalManualField.value =
                    '1';
            }

            if (postalHelp) {
                postalHelp.textContent =
                    'Postal lookup is temporarily unavailable. Enter the correct 4-digit postal code.';

                postalHelp.dataset.state =
                    'warning';
            }
        }
    };

    postalCode?.addEventListener(
        'input',
        () => {
            if (!postalCode.readOnly) {
                postalCode.value =
                    postalCode.value
                        .replace(/\D/g, '')
                        .slice(0, 4);
            }
        }
    );

    if (province && city && barangay) {
        load(
            '/api/psgc/provinces',
            province,
            'Select province'
        );

        province.addEventListener(
            'change',
            () => {
                const code =
                    province
                        .selectedOptions[0]
                        ?.dataset.code;

                city.innerHTML =
                    '<option value="">Select province first</option>';

                city.disabled = true;

                barangay.innerHTML =
                    '<option value="">Select city first</option>';

                barangay.disabled = true;

                syncSearchableSelect(city);
                syncSearchableSelect(barangay);

                resetPostal();

                if (code) {
                    load(
                        `/api/psgc/provinces/${code}/cities`,
                        city,
                        'Select municipality / city'
                    );
                }
            }
        );

        city.addEventListener(
            'change',
            () => {
                const code =
                    city
                        .selectedOptions[0]
                        ?.dataset.code;

                barangay.innerHTML =
                    '<option value="">Select city first</option>';

                barangay.disabled = true;

                syncSearchableSelect(barangay);

                resetPostal();

                if (code) {
                    load(
                        `/api/psgc/cities/${code}/barangays`,
                        barangay,
                        'Select barangay'
                    );
                }

                loadPostalCode();
            }
        );

        barangay.addEventListener(
            'change',
            () => {
                syncSearchableSelect(barangay);
            }
        );
    }

    show(1);
}

document.addEventListener('DOMContentLoaded', () => {
    setupShell(); setupModals(); setupTabs(); setupIncoming(); setupTables(); setupStatusActions(); setupSorting(); setupDispatch(); setupReports(); setupChat(); setupAccount(); setupRegistration(); refreshIcons();
});
