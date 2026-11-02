const EXCLUDED_FIELDS = new Set([
    '_token',
    '_method',
    'age',
    'password',
    'password_confirmation',
    'terms',
]);

function registrationDraftFields(form) {
    const fields = {};

    [...form.elements].forEach((field) => {
        if (!field.name || EXCLUDED_FIELDS.has(field.name) || field.disabled) return;
        if (field.type === 'password' || field.type === 'file' || field.type === 'submit' || field.type === 'button') return;
        if (field.type === 'checkbox' && !field.checked) return;
        if (field.type === 'radio' && !field.checked) return;
        if (field.matches('[data-email-code], [autocomplete="one-time-code"]')) return;
        if (field.name.startsWith('terms')) return;

        fields[field.name] = String(field.value ?? '').slice(0, 300);
    });

    return fields;
}

export function restoreRegistrationDraft(form) {
    if (form.dataset.hasOldInput === 'true') return;

    let fields = {};
    try {
        fields = JSON.parse(form.querySelector('[data-registration-draft]')?.textContent || '{}');
    } catch (_) {}

    Object.entries(fields).forEach(([name, value]) => {
        const controls = [...form.elements].filter((field) => field.name === name);
        if (!controls.length || typeof value !== 'string') return;

        const radioControls = controls.filter((field) => field.type === 'radio');
        if (radioControls.length) {
            radioControls.forEach((field) => {
                field.checked = field.value === value;
            });
            return;
        }

        const control = controls[0];
        if (control.tagName === 'SELECT') {
            control.dataset.oldValue = value;
            if ([...control.options].some((option) => option.value === value)) {
                control.value = value;
            }
            return;
        }

        if (control.type === 'checkbox') {
            control.checked = value === control.value || value === '1' || value === 'true';
            return;
        }

        control.value = value;
    });
}

export function setupRegistrationDraft(form) {
    const url = form.dataset.draftSaveUrl;
    const status = form.querySelector('[data-registration-draft-status]');
    if (!url) return;

    let timer = null;
    let changes = 0;
    let savedChanges = 0;
    let saving = false;

    const payload = () => ({
        fields: registrationDraftFields(form),
        step: Math.max(1, Number(form.dataset.currentStep || form.dataset.draftStep || 1)),
    });

    const save = async (keepalive = false) => {
        if (saving || savedChanges >= changes) return;
        saving = true;
        const csrf = form.querySelector('input[name="_token"]')?.value
            || document.querySelector('meta[name="csrf-token"]')?.content
            || '';

        try {
            while (savedChanges < changes) {
                const currentChanges = changes;
                const response = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    keepalive,
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify(payload()),
                });
                if (!response.ok) throw new Error('Draft save failed');
                savedChanges = currentChanges;
                if (status) status.textContent = 'Saved on this browser for 7 days.';
                if (keepalive) break;
            }
        } catch (_) {
            if (status) status.textContent = 'Draft autosave is temporarily unavailable. Keep this page open while you finish.';
        } finally {
            saving = false;
            if (savedChanges < changes && !keepalive) schedule(false);
        }
    };

    const schedule = (markChanged = true) => {
        if (markChanged) changes += 1;
        window.clearTimeout(timer);
        if (status) status.textContent = 'Saving your progress…';
        timer = window.setTimeout(() => { void save(); }, 650);
    };

    form.addEventListener('input', schedule);
    form.addEventListener('change', schedule);
    form.addEventListener('registration:step', schedule);
    window.addEventListener('pagehide', () => {
        window.clearTimeout(timer);
        if (savedChanges < changes) void save(true);
    });
}
