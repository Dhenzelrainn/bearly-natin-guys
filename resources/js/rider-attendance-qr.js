async function setupRiderAttendanceQr() {
    const control = document.querySelector('[data-rider-attendance-scanner]');
    if (!control) return;

    const startButton = control.querySelector('[data-attendance-scan-start]');
    const cameraElement = control.querySelector('[data-attendance-camera]');
    const status = control.querySelector('[data-attendance-scan-status]');
    const scanUrl = control.dataset.scanUrl;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const shiftState = document.querySelector('[data-rider-attendance-state]');
    const shiftDetail = document.querySelector('[data-rider-attendance-detail]');
    let scanner = null;
    let active = false;
    let submitting = false;

    const setStatus = (text, isError = false) => {
        status.textContent = text;
        status.classList.toggle('is-error', isError);
    };

    const formatPhtTime = (value) => {
        const date = new Date(value || '');
        if (Number.isNaN(date.getTime())) return null;

        return new Intl.DateTimeFormat('en-PH', {
            timeZone: 'Asia/Manila',
            dateStyle: 'medium',
            hour: 'numeric',
            minute: '2-digit',
        }).format(date);
    };

    const updateShiftIndicator = (attendance) => {
        if (!shiftState || !shiftDetail) return;

        const time = formatPhtTime(attendance.scanned_at);

        if (attendance.scan_type === 'check_in') {
            shiftState.textContent = 'Checked in';
            shiftState.classList.add('is-checked-in');
            shiftState.classList.remove('is-not-checked-in');
            shiftDetail.textContent = time
                ? `Time in recorded at ${time} PHT. Your attendance is saved.`
                : 'Your attendance is saved. You are checked in.';
            return;
        }

        if (attendance.scan_type === 'check_out') {
            shiftState.textContent = 'Not checked in';
            shiftState.classList.add('is-not-checked-in');
            shiftState.classList.remove('is-checked-in');
            shiftDetail.textContent = time
                ? `Time out recorded at ${time} PHT. Your shift is closed.`
                : 'Your time out is saved. Your shift is closed.';
        }
    };

    const stopScanner = async () => {
        if (!scanner || !active) return;

        try {
            await scanner.stop();
            await scanner.clear();
        } catch {
            // Camera cleanup is best-effort after a completed or failed scan.
        }

        active = false;
        cameraElement.hidden = true;
        startButton.disabled = false;
        startButton.textContent = 'Open camera to scan';
    };

    const submitScan = async (qrPayload) => {
        if (submitting) return;
        submitting = true;
        startButton.disabled = true;
        setStatus('Checking the QR with your Logistics provider…');
        await stopScanner();
        startButton.disabled = true;

        try {
            const response = await fetch(scanUrl, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ qr_payload: qrPayload }),
            });
            const result = await response.json().catch(() => ({}));

            if (!response.ok) {
                const validationMessage = Object.values(result.errors || {}).flat()[0];
                const message = validationMessage || result.message || 'The QR could not be accepted. Scan the current code and try again.';

                if (response.status === 409 && message.includes('already been used for your attendance')) {
                    setStatus('This QR was already used. Refreshing to check your saved attendance status…');
                    window.setTimeout(() => window.location.reload(), 700);
                    return;
                }

                throw new Error(message);
            }

            updateShiftIndicator(result.data || {});
            setStatus(result.message || 'Attendance recorded.');
            window.setTimeout(() => window.location.reload(), 900);
        } catch (error) {
            setStatus(error?.message || 'The QR could not be accepted. Scan the current code and try again.', true);
            submitting = false;
            startButton.disabled = false;
        }
    };

    startButton.addEventListener('click', async () => {
        if (active) {
            await stopScanner();
            startButton.textContent = 'Open camera to scan';
            setStatus('Camera stopped. Scan the current QR to record attendance.');
            return;
        }

        if (submitting) return;

        startButton.disabled = true;
        setStatus('Opening your camera…');

        try {
            const { Html5Qrcode, Html5QrcodeSupportedFormats } = await import('html5-qrcode');
            scanner = new Html5Qrcode(cameraElement.id, {
                formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE],
                verbose: false,
            });
            cameraElement.hidden = false;
            await scanner.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 240, height: 240 }, aspectRatio: 1 },
                (decodedText) => submitScan(decodedText),
                () => {},
            );
            active = true;
            startButton.disabled = false;
            startButton.textContent = 'Stop camera';
            setStatus('Point your camera at the current QR from Logistics.');
        } catch (error) {
            cameraElement.hidden = true;
            startButton.disabled = false;
            startButton.textContent = 'Try camera again';
            setStatus(error?.message || 'Camera access is unavailable. Allow camera permission and try again.', true);
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (document.hidden && active) {
            stopScanner();
            startButton.textContent = 'Open camera to scan';
            setStatus('Camera paused while this page is hidden. Scan the current QR when you return.');
        }
    });
}

document.addEventListener('DOMContentLoaded', setupRiderAttendanceQr);
