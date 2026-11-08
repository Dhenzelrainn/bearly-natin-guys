async function setupRiderAttendanceQr() {
    const control = document.querySelector('[data-rider-attendance-scanner]');
    if (!control) return;

    const startButton = control.querySelector('[data-attendance-scan-start]');
    const cameraElement = control.querySelector('[data-attendance-camera]');
    const status = control.querySelector('[data-attendance-scan-status]');
    const scanUrl = control.dataset.scanUrl;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let scanner = null;
    let active = false;
    let submitting = false;

    const setStatus = (text, isError = false) => {
        status.textContent = text;
        status.classList.toggle('is-error', isError);
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
                throw new Error(validationMessage || result.message || 'The QR could not be accepted. Scan the current code and try again.');
            }

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
