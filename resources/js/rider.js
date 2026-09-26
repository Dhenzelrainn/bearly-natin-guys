const RIDER_STATE_KEY = 'bearlyRiderStateV1';
const readState = () => { try { return JSON.parse(localStorage.getItem(RIDER_STATE_KEY)) || {}; } catch { return {}; } };
const writeState = (state) => localStorage.setItem(RIDER_STATE_KEY, JSON.stringify(state));
const saveValue = (section, key, value) => { const state = readState(); state[section] ||= {}; state[section][key] = value; writeState(state); };
const normalize = (value) => String(value || '').trim().toLowerCase().replaceAll('_',' ');
const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'})[character]);
const refreshIcons = () => window.lucide?.createIcons();

function toast(message) {
    const stack = document.querySelector('[data-toast-stack]'); if (!stack) return;
    const item = document.createElement('div'); item.className = 'toast'; item.innerHTML = `<i data-lucide="circle-check"></i><span>${escapeHtml(message)}</span>`; stack.append(item); refreshIcons(); setTimeout(() => item.remove(), 3200);
}

function setupShell() {
    const body = document.body;
    if (localStorage.getItem('bearlyRiderSidebar') === 'collapsed') body.classList.add('sidebar-collapsed');
    document.querySelector('[data-sidebar-collapse]')?.addEventListener('click', () => { body.classList.toggle('sidebar-collapsed'); localStorage.setItem('bearlyRiderSidebar', body.classList.contains('sidebar-collapsed') ? 'collapsed' : 'open'); });
    document.querySelector('[data-mobile-menu]')?.addEventListener('click', () => body.classList.add('sidebar-open'));
    document.querySelector('[data-overlay]')?.addEventListener('click', () => body.classList.remove('sidebar-open'));
    document.querySelectorAll('[data-dismiss]').forEach((button) => button.addEventListener('click', () => button.closest('.flash-banner')?.remove()));
    document.querySelectorAll('[data-popover-toggle]').forEach((button) => button.addEventListener('click', (event) => { event.stopPropagation(); const name = button.dataset.popoverToggle; document.querySelectorAll('[data-popover]').forEach((popover) => { popover.hidden = popover.dataset.popover !== name || !popover.hidden; }); }));
    document.addEventListener('click', (event) => { if (!event.target.closest('.popover-wrap')) document.querySelectorAll('[data-popover]').forEach((popover) => { popover.hidden = true; }); });
    document.querySelector('[data-mark-notifications]')?.addEventListener('click', () => { document.querySelectorAll('.notification-row').forEach((row) => row.remove()); document.querySelector('.notification-dot')?.remove(); toast('Notifications marked as read.'); });
}

function setupModals() {
    const backdrop = document.querySelector('[data-modal-backdrop]');
    const close = () => { document.querySelectorAll('.modal').forEach((modal) => { modal.hidden = true; }); if (backdrop) backdrop.hidden = true; document.body.style.overflow = ''; };
    const open = (name) => { const modal = document.querySelector(`[data-modal="${name}"]`); if (!modal) return; modal.hidden = false; if (backdrop) backdrop.hidden = false; document.body.style.overflow = 'hidden'; modal.querySelector('input,select,textarea')?.focus(); };
    document.querySelectorAll('[data-modal-open]').forEach((button) => button.addEventListener('click', () => open(button.dataset.modalOpen)));
    document.querySelectorAll('[data-modal-close]').forEach((button) => button.addEventListener('click', close)); backdrop?.addEventListener('click', close); document.addEventListener('keydown', (event) => { if (event.key === 'Escape') close(); });
    window.openRiderModal = open; window.closeRiderModals = close;
}

function setupTabs() {
    document.querySelectorAll('[data-tabs]').forEach((tabs) => tabs.querySelectorAll('[data-tab]').forEach((button) => button.addEventListener('click', () => { tabs.querySelectorAll('[data-tab]').forEach((item) => item.classList.toggle('is-active', item === button)); const scope = tabs.closest('[data-tab-scope]') || document; scope.querySelectorAll('[data-tab-panel]').forEach((panel) => { panel.hidden = panel.dataset.tabPanel !== button.dataset.tab; }); })));
}

function setupTables() {
    document.querySelectorAll('[data-table-scope]').forEach((scope) => {
        const rows = [...scope.querySelectorAll('[data-row]')]; const empty = scope.querySelector('[data-empty-row], [data-empty-cards]');
        const apply = () => { const query = normalize(scope.querySelector('[data-table-search]')?.value); const status = normalize(scope.querySelector('[data-filter-status]')?.value); let shown = 0; rows.forEach((row) => { const match = (!query || normalize(row.textContent).includes(query)) && (!status || normalize(row.dataset.status) === status); row.classList.toggle('is-filtered-out', !match); if (match) shown++; }); if (empty) empty.hidden = shown > 0; };
        scope.querySelectorAll('[data-table-search],[data-filter-status]').forEach((field) => field.addEventListener(field.tagName === 'INPUT' ? 'input' : 'change', apply));
        scope.querySelectorAll('th[data-sort]').forEach((header) => header.addEventListener('click', () => { const tbody = header.closest('table')?.tBodies[0]; if (!tbody) return; const index = [...header.parentElement.children].indexOf(header); const direction = header.dataset.direction === 'asc' ? 'desc' : 'asc'; header.dataset.direction = direction; rows.filter((row) => row.tagName === 'TR').sort((a,b) => a.children[index].innerText.localeCompare(b.children[index].innerText,undefined,{numeric:true}) * (direction === 'asc' ? 1 : -1)).forEach((row) => tbody.append(row)); }));
    });
}

function setupPickupJobs() {
    const state = readState();
    document.querySelectorAll('[data-record-type="pickupJobs"]').forEach((card) => { const saved = state.pickupJobs?.[card.dataset.recordId]; if (!saved) return; card.dataset.status = saved; const badge = card.querySelector('[data-status-badge]'); if (badge) { badge.dataset.status = saved; badge.textContent = saved; } const button = card.querySelector('[data-accept-pickup]'); if (button && saved === 'Accepted') { button.textContent = 'Accepted'; button.disabled = true; } });
    document.querySelectorAll('[data-accept-pickup]').forEach((button) => button.addEventListener('click', () => { const card = button.closest('[data-record-row]'); card.dataset.status = 'Accepted'; const badge = card.querySelector('[data-status-badge]'); badge.dataset.status = 'Accepted'; badge.textContent = 'Accepted'; button.textContent = 'Accepted'; button.disabled = true; saveValue('pickupJobs', card.dataset.recordId, 'Accepted'); toast(`${card.dataset.recordId} accepted and added to your pickup route.`); }));
    document.querySelectorAll('[data-preview-detail]').forEach((button) => button.addEventListener('click', () => toast('Pickup details are visible in the job card.')));
    document.querySelector('[data-refresh-preview]')?.addEventListener('click', () => toast('Pickup queue refreshed with current preview data.'));
    document.querySelector('[data-optimize-route]')?.addEventListener('click', () => toast('Delivery stops reordered by distance and payment priority.'));
}

function setupWorkflow() {
    document.querySelectorAll('[data-check-all]').forEach((button) => button.addEventListener('click', () => { document.querySelectorAll(`[data-check-group="${button.dataset.checkAll}"] input[type="checkbox"]`).forEach((checkbox) => { checkbox.checked = true; }); document.querySelector('[data-workflow-step="manifest"]')?.classList.replace('is-current','is-complete'); document.querySelector('[data-workflow-step="confirm"]')?.classList.add('is-current'); toast('Every manifest item is checked.'); }));
    document.querySelector('[data-confirm-pickup]')?.addEventListener('click', () => { const checks = [...document.querySelectorAll('[data-check-group] input[type="checkbox"]')]; if (checks.some((item) => !item.checked)) { toast('Check every manifest item before confirming pickup.'); return; } window.openRiderModal?.('confirm-pickup'); });
    document.querySelector('[data-confirm-delivery]')?.addEventListener('click', () => { const checks = [...document.querySelectorAll('[data-delivery-check]')]; if (checks.some((item) => !item.checked)) { toast('Verify the package and payment before adding proof of delivery.'); return; } window.openRiderModal?.('proof-delivery'); });
    document.querySelectorAll('[data-call-contact]').forEach((button) => button.addEventListener('click', () => toast(`Call preview opened for ${button.dataset.callContact}.`)));
    document.querySelectorAll('[data-navigation-preview]').forEach((button) => button.addEventListener('click', () => toast('Route navigation preview opened.')));
    document.querySelectorAll('[data-job-complete]').forEach((form) => form.addEventListener('submit', () => saveValue('completedJobs', form.dataset.jobComplete, new Date().toISOString())));
    const proof = document.querySelector('[data-proof-file]'); proof?.addEventListener('change', () => { const name = document.querySelector('[data-proof-name]'); if (name) name.textContent = proof.files?.[0]?.name || 'JPG or PNG preview file'; });
}

function downloadCsv(filename, rows) { const csv = rows.map((row) => row.map((value) => `"${String(value).replaceAll('"','""')}"`).join(',')).join('\n'); const link = document.createElement('a'); link.href = URL.createObjectURL(new Blob([csv],{type:'text/csv'})); link.download = filename; link.click(); URL.revokeObjectURL(link.href); }
function setupReports() {
    document.querySelector('[data-apply-earnings]')?.addEventListener('click', () => toast('Earnings preview refreshed for the selected date range.'));
    document.querySelector('[data-export-earnings]')?.addEventListener('click', () => { const rows = [['Date','Job','Type','Amount','Status']]; document.querySelectorAll('[data-earnings-row]').forEach((row) => rows.push([...row.children].map((cell) => cell.innerText.trim()))); downloadCsv('bearly-rider-earnings.csv', rows); toast('Earnings CSV exported.'); });
    document.querySelector('[data-export-history]')?.addEventListener('click', () => { const rows = [['Date','Delivery','Customer','Area','Outcome','Earning']]; document.querySelectorAll('tbody tr[data-row]').forEach((row) => rows.push([...row.children].slice(0,6).map((cell) => cell.innerText.trim()))); downloadCsv('bearly-rider-history.csv', rows); toast('Delivery history CSV exported.'); });
    document.querySelectorAll('[data-history-detail]').forEach((button) => button.addEventListener('click', () => { const title = document.querySelector('[data-history-title]'); if (title) title.textContent = `${button.dataset.historyDetail} timeline`; window.openRiderModal?.('history-detail'); }));
}

function setupChat() {
    const conversations = [...document.querySelectorAll('[data-conversation]')]; const messages = document.querySelector('[data-chat-messages]'); const input = document.querySelector('[data-chat-input]'); if (!conversations.length || !messages) return;
    let active = conversations[0].dataset.conversation; const base = {'sorting-center':[{mine:false,text:'Your next dispatch is ready at Intake Bay 2.',time:'2:10 PM'},{mine:true,text:'Received. I will return after the current pickup.',time:'2:12 PM'}],'karen-yu':[{mine:false,text:'Please call me when you are near the subdivision gate.',time:'1:55 PM'}],'techvault-ph':[{mine:false,text:'The six pickup parcels are ready at our operations counter.',time:'1:31 PM'}]};
    const render = () => { const button = conversations.find((item) => item.dataset.conversation === active); document.querySelector('[data-chat-name]').textContent = button?.dataset.name || 'Conversation'; document.querySelector('[data-chat-role]').textContent = button?.dataset.role || ''; const saved = readState().messages?.[active] || []; messages.innerHTML = [...(base[active] || []),...saved].map((item) => `<div class="message-bubble ${item.mine?'is-mine':''}">${escapeHtml(item.text)}<small>${escapeHtml(item.time)}</small></div>`).join(''); messages.scrollTop = messages.scrollHeight; };
    conversations.forEach((button) => button.addEventListener('click', () => { active = button.dataset.conversation; conversations.forEach((item) => item.classList.toggle('is-active',item===button)); render(); }));
    document.querySelector('[data-chat-search]')?.addEventListener('input', (event) => conversations.forEach((button) => button.hidden = !normalize(button.textContent).includes(normalize(event.target.value))));
    const send = () => { const text = input.value.trim(); if (!text) return; const state = readState(); state.messages ||= {}; state.messages[active] ||= []; state.messages[active].push({mine:true,text,time:new Date().toLocaleTimeString([],{hour:'numeric',minute:'2-digit'})}); writeState(state); input.value=''; render(); };
    document.querySelector('[data-chat-send]')?.addEventListener('click',send); input?.addEventListener('keydown',(event)=>{if(event.key==='Enter'){event.preventDefault();send();}}); render();
}

function setupForms() {
    document.querySelectorAll('[data-preview-form]').forEach((form) => form.addEventListener('submit',(event)=>{event.preventDefault();if(!form.reportValidity())return;saveValue('forms',form.dataset.previewForm,Object.fromEntries(new FormData(form)));window.closeRiderModals?.();toast('Changes saved in this browser preview.');}));
    document.querySelectorAll('[data-address-edit]').forEach((button)=>button.addEventListener('click',()=>toast('Address editor opened in preview mode.')));
    document.querySelector('[data-password-form]')?.addEventListener('submit',(event)=>{event.preventDefault();const form=event.currentTarget,next=form.querySelector('[name="new_password"]').value,confirm=form.querySelector('[name="new_password_confirmation"]').value;if(next.length<8||next!==confirm){toast('Passwords must match and contain at least 8 characters.');return;}form.reset();toast('Password update validated for this preview.');});
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
    };

    const validateStep = () => {
        const panel = form.querySelector(
            `[data-form-step="${step}"]`
        );

        if (!panel) return false;

        const fields = [
            ...panel.querySelectorAll('input, select, textarea'),
        ].filter(
            (field) =>
                !field.disabled &&
                field.type !== 'button' &&
                field.type !== 'submit'
        );

        for (const field of fields) {
            field.setCustomValidity('');
            passwordUx.prepareField(field);

            if (!field.checkValidity()) {
                field.reportValidity();
                field.focus();
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
                    show(Math.min(3, step + 1));
                }
            });
        });

    document
        .querySelectorAll('[data-step-back]')
        .forEach((button) => {
            button.addEventListener('click', () => {
                show(Math.max(1, step - 1));
            });
        });

    const birthday = form.querySelector('[name="birthday"]');
    const ageField = form.querySelector('[data-age]');

    const updateAge = () => {
        if (!birthday || !ageField) return;

        if (!birthday.value) {
            ageField.value = '';
            return;
        }

        const birth = new Date(`${birthday.value}T00:00:00`);
        const now = new Date();

        let age = now.getFullYear() - birth.getFullYear();

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
            Number.isFinite(age) && age >= 0
                ? String(age)
                : '';
    };

    birthday?.addEventListener('change', updateAge);
    updateAge();

    form
        .querySelectorAll('input[type="file"]')
        .forEach((input) => {
            input.addEventListener('change', () => {
                const label =
                    input
                        .closest('.upload-field')
                        ?.querySelector('[data-file-name]');

                if (label) {
                    label.textContent =
                        input.files?.[0]?.name ||
                        'Choose a JPG, PNG, or PDF file';
                }
            });
        });

    const province = form.querySelector('[data-province]');
    const city = form.querySelector('[data-city]');
    const barangay = form.querySelector('[data-barangay]');

    const fallbackLocations = (url) => {
        if (url.endsWith('/provinces')) {
            return [
                { name: 'Laguna', code: '043400000' },
                { name: 'Batangas', code: '041000000' },
                { name: 'Cavite', code: '042100000' },
                { name: 'Quezon', code: '045600000' },
            ];
        }

        if (url.includes('/provinces/043400000/')) {
            return [
                { name: 'San Pablo City', code: '043426000' },
                { name: 'Santa Cruz', code: '043428000' },
                { name: 'Calauan', code: '043405000' },
                { name: 'Pila', code: '043419000' },
                { name: 'Bay', code: '043403000' },
            ];
        }

        if (url.includes('/provinces/')) {
            return [
                { name: 'Provincial Capital', code: '000000001' },
                { name: 'Service Municipality', code: '000000002' },
            ];
        }

        return [
            { name: 'Poblacion', code: '000000101' },
            { name: 'San Antonio', code: '000000102' },
            { name: 'San Rafael', code: '000000103' },
            { name: 'Del Remedio', code: '000000104' },
        ];
    };

    const fill = (select, items, placeholder) => {
        select.innerHTML =
            `<option value="">${placeholder}</option>` +
            items
                .map(
                    (item) =>
                        `<option value="${escapeHtml(item.name)}" data-code="${escapeHtml(item.code)}">${escapeHtml(item.name)}</option>`
                )
                .join('');

        select.disabled = false;
    };

    const load = async (url, select, placeholder) => {
        select.disabled = true;
        select.innerHTML = '<option>Loading…</option>';

        try {
            const response = await fetch(url);

            if (!response.ok) throw new Error();

            const payload = await response.json();
            const items =
                Array.isArray(payload)
                    ? payload
                    : payload.data;

            if (!Array.isArray(items)) throw new Error();

            fill(select, items, placeholder);
        } catch {
            fill(
                select,
                fallbackLocations(url),
                `${placeholder} (preview list)`
            );

            toast('Using the offline preview location list.');
        }
    };

    if (province && city && barangay) {
        load('/api/psgc/provinces', province, 'Select province');

        province.addEventListener('change', () => {
            const code =
                province.selectedOptions[0]?.dataset.code;

            if (code) {
                load(
                    `/api/psgc/provinces/${code}/cities`,
                    city,
                    'Select municipality / city'
                );
            }
        });

        city.addEventListener('change', () => {
            const code =
                city.selectedOptions[0]?.dataset.code;

            if (code) {
                load(
                    `/api/psgc/cities/${code}/barangays`,
                    barangay,
                    'Select barangay'
                );
            }
        });
    }

    show(1);
}

document.addEventListener('DOMContentLoaded',()=>{setupShell();setupModals();setupTabs();setupTables();setupPickupJobs();setupWorkflow();setupReports();setupChat();setupForms();setupRegistration();refreshIcons();});
