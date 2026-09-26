export function setupEmail(form, role) {
    const stored = form.elements.email;
    const panel = form.querySelector('[data-email-verification]');
    const send = form.querySelector('[data-email-send]');
    const check = form.querySelector('[data-email-check]');
    const code = form.querySelector('[data-email-code]');
    const entry = form.querySelector('[data-email-code-entry]');
    const status = form.querySelector('[data-email-status]');
    let verifiedEmail = '';
    let verifiedUntil = 0;
    let sentEmail = '';
    let busy = false;
    let cooldownUntil = 0;
    let generation = 0;
    function message(text, error = false) {
        status.textContent = text;
        status.dataset.state = error ? 'error' : 'info';
    }
    function reset() {
        generation++;
        verifiedEmail = '';
        verifiedUntil = 0;
        sentEmail = '';
        entry.hidden = true;
        code.value = '';
        message('Verify your email address to continue.');
        status.dataset.state = 'info';
    }
    stored.addEventListener('input', () => { reset(); refresh(); });
    code.addEventListener('input', () => {
        code.value = code.value.replace(/[^0-9]/g, '').slice(0, 6);
    });

    function validEmail() {
        stored.value = stored.value.trim().toLowerCase();
        if (!stored.value || !stored.checkValidity()) { stored.reportValidity(); stored.focus(); return false; }
        return true;
    }
    async function post(url, extra) {
        const response = await fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': form.elements._token.value },
            body: JSON.stringify({ email: stored.value, ...extra }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            if (response.status === 429) cooldownUntil = Date.now() + Number(response.headers.get('Retry-After') || 60) * 1000;
            throw new Error(response.status === 419 ? 'Your session expired. Refresh the page and try again.' :
                Object.values(data.errors || {}).flat()[0] || (response.status === 429 ? 'Too many requests. Please wait before trying again.' : 'Verification is unavailable. Please try again.'));
        }
        return data;
    }
    send.addEventListener('click', async () => {
        if (busy || Date.now() < cooldownUntil || !validEmail()) return;
        busy = true;
        reset();
        const requestGeneration = generation;
        const email = stored.value;
        refresh();
        message('Sending verification code…');
        try {
            const data = await post(form.dataset.emailSend, {});
            cooldownUntil = Date.now() + data.retry_after * 1000;
            if (requestGeneration !== generation) return;
            sentEmail = email;
            entry.hidden = false;
            message(data.message);
            code.focus();
        } catch (error) { if (requestGeneration === generation) message(error.message, true); }
        finally { busy = false; refresh(); }
    });
    check.addEventListener('click', async () => {
        if (busy || !validEmail()) return;
        if (stored.value !== sentEmail) { reset(); return; }
        if (!/^[0-9]{6}$/.test(code.value)) { message('Enter the six-digit code from your email.', true); code.focus(); return; }
        busy = true;
        const requestGeneration = generation;
        const email = stored.value;
        refresh();
        try {
            const data = await post(form.dataset.emailCheck, { code: code.value });
            if (requestGeneration !== generation) return;
            verifiedEmail = email;
            verifiedUntil = Date.now() + data.expires_in * 1000;
            message(data.message);
            status.dataset.state = 'verified';
            entry.hidden = true;
            code.value = '';
        } catch (error) { if (requestGeneration === generation) message(error.message, true); }
        finally { busy = false; refresh(); }
    });
    function refresh() {
        panel.hidden = role() !== 'buyer';
        const left = Math.max(0, Math.ceil((cooldownUntil - Date.now()) / 1000));
        send.disabled = busy || left > 0 || Boolean(verifiedEmail);
        check.disabled = busy;
        send.textContent = busy ? 'Please wait…' : verifiedEmail ? 'Verified' : left ? `Resend in ${left}s` : sentEmail ? 'Resend code' : 'Send code';
        if (verifiedEmail && Date.now() >= verifiedUntil) { reset(); message('Verification expired. Please verify your email address again.', true); }
    }
    reset();
    refresh();
    window.setInterval(refresh, 1000);
    return {
        updateRole: refresh,
        validate: () => {
            if (!validEmail()) return false;
            if (role() === 'buyer' && (verifiedEmail !== stored.value || Date.now() >= verifiedUntil)) {
                message('Verify your email address before continuing.', true); send.focus(); return false;
            }
            return true;
        },
    };
}
