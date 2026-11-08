import QRCode from 'qrcode';

function setupAttendanceQrStation() {
    const roster = document.querySelector('[data-attendance-roster]');

    if (roster) {
        const cutoffAt = Date.parse(roster.dataset.cutoffAt || '');
        const syncAbsenceActions = () => {
            const phtDate = new Intl.DateTimeFormat('en-CA', {
                timeZone: 'Asia/Manila',
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
            }).format(new Date());
            const canMarkAbsent = phtDate === roster.dataset.workDate
                && Number.isFinite(cutoffAt)
                && Date.now() >= cutoffAt;

            roster.querySelectorAll('[data-attendance-absence-action]').forEach((form) => {
                form.hidden = !canMarkAbsent;
            });
            roster.querySelectorAll('[data-attendance-cutoff-hint]').forEach((hint) => {
                hint.hidden = canMarkAbsent;
            });
        };

        syncAbsenceActions();
        window.setInterval(syncAbsenceActions, 15000);
    }

    const station = document.querySelector('[data-logistics-attendance-qr]');
    if (!station) return;

    const canvas = station.querySelector('[data-attendance-qr-canvas]');
    const refreshButton = station.querySelector('[data-attendance-qr-refresh]');
    const countdown = station.querySelector('[data-attendance-qr-countdown]');
    const expiry = station.querySelector('[data-attendance-qr-expiry]');
    const message = station.querySelector('[data-attendance-qr-message]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let expiresAt = 0;
    let issuing = false;
    let stopped = false;

    const setMessage = (text, isError = false) => {
        message.textContent = text;
        message.classList.toggle('is-error', isError);
    };

    const issue = async () => {
        if (issuing || stopped) return;
        issuing = true;
        refreshButton.disabled = true;
        countdown.textContent = 'Generating a fresh QR…';

        try {
            const response = await fetch(station.dataset.challengeUrl, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: '{}',
            });
            const result = await response.json().catch(() => ({}));

            if (!response.ok || typeof result.data?.challenge !== 'string') {
                throw new Error(result.message || 'The attendance QR could not be refreshed. Try again.');
            }

            await QRCode.toCanvas(canvas, result.data.challenge, {
                width: 280,
                margin: 2,
                errorCorrectionLevel: 'M',
                color: { dark: '#2B1D16', light: '#FFFFFF' },
            });
            expiresAt = Date.parse(result.data.expires_at);
            setMessage('The current code is ready for Rider check-in or check-out.');
            expiry.textContent = `Challenge ${result.data.generation} · code is not saved in the browser after refresh.`;
        } catch (error) {
            expiresAt = 0;
            canvas.getContext('2d')?.clearRect(0, 0, canvas.width, canvas.height);
            countdown.textContent = 'QR unavailable';
            setMessage(error?.message || 'The attendance QR could not be refreshed. Try again.', true);
        } finally {
            issuing = false;
            refreshButton.disabled = false;
        }
    };

    refreshButton.addEventListener('click', issue);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && expiresAt - Date.now() < 12000) issue();
    });

    window.setInterval(() => {
        if (!expiresAt) return;
        const remaining = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
        countdown.textContent = remaining > 0 ? `Refreshes in ${remaining} seconds` : 'Refreshing expired QR…';

        if (remaining === 0) issue();
    }, 1000);

    issue();
}

document.addEventListener('DOMContentLoaded', setupAttendanceQrStation);
