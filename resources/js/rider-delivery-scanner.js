import {
    createBarcodeReadState,
    observeBarcodeRead,
    REQUIRED_STABLE_BARCODE_READS,
} from './waybill-scanner-utils.js';

document.querySelectorAll('[data-rider-delivery-scan-form]').forEach((form) => {
    const input = form.querySelector('[data-delivery-barcode-input]');
    const verifyButton = form.querySelector('[data-delivery-verify-code]');
    const openCameraButton = form.querySelector('[data-delivery-camera-open]');
    const openCameraLabel = openCameraButton?.querySelector('[data-delivery-camera-label]');
    const closeCameraButton = form.querySelector('[data-delivery-camera-close]');
    const cameraPanel = form.querySelector('[data-delivery-camera-panel]');
    const video = form.querySelector('[data-delivery-camera-video]');
    const status = form.querySelector('[data-delivery-scan-status]');
    const progress = form.querySelector('[data-delivery-scan-progress]');
    const scanList = form.querySelector('[data-delivery-scan-list]');
    const verifyUrl = form.dataset.deliveryVerifyUrl || '';
    const progressUrl = form.dataset.deliveryProgressUrl || '';

    if (!input || !verifyButton || !openCameraButton || !cameraPanel || !video || !status || !progress || !scanList || !verifyUrl || !progressUrl) return;

    const parcelItems = [...scanList.querySelectorAll('[data-delivery-expected-parcel]')];
    const expectedCodes = parcelItems.map((item) => item.dataset.deliveryExpectedParcel || '');
    const expectedCodesAreUsable = expectedCodes.length > 0
        && expectedCodes.every(Boolean)
        && new Set(expectedCodes).size === expectedCodes.length;
    const parcelItemsByCode = new Map(expectedCodes.map((code, index) => [code, parcelItems[index]]));
    const verifiedCodes = new Set(parcelItems
        .filter((item) => item.dataset.deliveryVerified === 'true')
        .map((item) => item.dataset.deliveryExpectedParcel || '')
        .filter(Boolean));
    const pendingCodes = new Set();
    let stream = null;
    let detector = null;
    let animation = null;
    let scanning = false;
    let progressPoll = null;
    let progressRequestInFlight = false;
    let candidateState = createBarcodeReadState();
    let lastAttemptedValue = '';
    let lastDetectionAt = 0;

    const setStatus = (message, state = '') => {
        status.textContent = message;
        status.dataset.state = state;
    };

    const allParcelsVerified = () => expectedCodesAreUsable
        && verifiedCodes.size === expectedCodes.length;

    const completionMessage = () => allParcelsVerified()
        ? 'All assigned Parcels are verified and saved. Delivery remains Out for Delivery until you separately confirm the handoff.'
        : `Parcel verification saved (${verifiedCodes.size} of ${expectedCodes.length}). Scan the next assigned Parcel.`;

    const updateProgress = () => {
        progress.textContent = `${verifiedCodes.size} of ${expectedCodes.length} parcels verified`;

        parcelItems.forEach((item) => {
            const barcode = item.dataset.deliveryExpectedParcel || '';
            const isVerified = verifiedCodes.has(barcode);
            item.dataset.deliveryVerified = isVerified ? 'true' : 'false';
            item.dataset.state = isVerified ? 'verified' : 'pending';
            const state = item.querySelector('[data-delivery-parcel-state]');
            if (state) state.textContent = isVerified ? 'Verified · saved' : 'Awaiting scan';
        });
    };

    const applySavedProgress = (data) => {
        const savedCodes = Array.isArray(data?.verified_parcel_nos)
            ? data.verified_parcel_nos
            : [];
        let changed = false;

        savedCodes.forEach((barcode) => {
            if (typeof barcode === 'string' && parcelItemsByCode.has(barcode) && !verifiedCodes.has(barcode)) {
                verifiedCodes.add(barcode);
                changed = true;
            }
        });

        updateProgress();

        return changed;
    };

    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    const stopCamera = () => {
        scanning = false;
        if (animation !== null) window.cancelAnimationFrame(animation);
        animation = null;
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
        detector = null;
        video.srcObject = null;
        cameraPanel.hidden = true;
        if (openCameraLabel) openCameraLabel.textContent = 'Scan with camera';
        openCameraButton.setAttribute('aria-pressed', 'false');
        candidateState = createBarcodeReadState();
        lastAttemptedValue = '';
        lastDetectionAt = 0;
    };

    const verifyBarcode = async (rawValue, scanMethod = 'manual') => {
        const barcode = typeof rawValue === 'string' ? rawValue.trim() : '';

        if (!barcode) {
            setStatus('Scan or enter a Parcel barcode first.', 'error');
            return false;
        }

        if (!expectedCodesAreUsable) {
            setStatus('The assigned Parcel barcode list is unavailable. Contact Logistics before completing this delivery.', 'error');
            return false;
        }

        if (!parcelItemsByCode.has(barcode)) {
            setStatus('That exact Parcel barcode is not assigned to this delivery. Nothing was verified.', 'error');
            return false;
        }

        if (verifiedCodes.has(barcode)) {
            setStatus('This assigned Parcel is already verified and saved. Scan the next unverified Parcel.', 'success');
            return true;
        }

        if (pendingCodes.has(barcode)) return false;

        pendingCodes.add(barcode);
        verifyButton.disabled = true;
        setStatus('Saving this Parcel verification…');

        try {
            const response = await fetch(verifyUrl, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ barcode, scan_method: scanMethod }),
            });
            const payload = await response.json().catch(() => null);

            if (!response.ok) {
                const validationMessage = payload?.errors?.barcode?.[0];
                setStatus(validationMessage || payload?.message || 'This Parcel scan could not be saved. Check your connection and try again.', 'error');
                return false;
            }

            applySavedProgress(payload?.data);

            if (!verifiedCodes.has(barcode)) {
                setStatus('The server did not confirm this Parcel scan. Please scan it again.', 'error');
                return false;
            }

            setStatus(completionMessage(), 'success');

            return true;
        } catch {
            setStatus('This Parcel scan could not be saved. Check your connection and try again.', 'error');

            return false;
        } finally {
            pendingCodes.delete(barcode);
            verifyButton.disabled = false;
        }
    };

    const verifyInput = async () => {
        const verified = await verifyBarcode(input.value, 'manual');
        if (verified) {
            input.value = '';
            input.focus();
        }
    };

    const refreshSavedProgress = async () => {
        if (document.hidden || progressRequestInFlight) return;

        progressRequestInFlight = true;

        try {
            const response = await fetch(progressUrl, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                if ([401, 403, 404, 409].includes(response.status)) {
                    setStatus('This delivery assignment changed. Refresh the page to see its current status.', 'error');
                    if (progressPoll !== null) window.clearInterval(progressPoll);
                    progressPoll = null;
                }

                return;
            }

            const payload = await response.json();
            if (applySavedProgress(payload?.data)) {
                setStatus(completionMessage(), 'success');
            }
        } catch {
            // Retain the last server-confirmed progress and retry on the next poll.
        } finally {
            progressRequestInFlight = false;
        }
    };

    const scanFrame = async (frameTime = performance.now()) => {
        if (!scanning || !detector) return;

        if (frameTime - lastDetectionAt < 120) {
            animation = window.requestAnimationFrame(scanFrame);
            return;
        }
        lastDetectionAt = frameTime;

        try {
            const results = await detector.detect(video);
            if (!scanning) return;

            const rawValue = results?.find((result) => result.rawValue)?.rawValue || '';
            const observed = observeBarcodeRead(candidateState, rawValue, performance.now());

            if (observed.invalid) {
                lastAttemptedValue = '';
                setStatus('Unclear barcode read. Keep the complete label in frame, hold steady, and use good lighting.', 'error');
            } else if (observed.value && !observed.stable) {
                setStatus(`Barcode detected (${observed.reads}/${REQUIRED_STABLE_BARCODE_READS}). Hold still…`);
            } else if (observed.value && observed.stable && observed.value !== lastAttemptedValue) {
                lastAttemptedValue = observed.value;
                await verifyBarcode(observed.value, 'camera');

                if (allParcelsVerified()) {
                    stopCamera();
                    return;
                }
            } else if (!candidateState.value) {
                lastAttemptedValue = '';
            }
        } catch {
            if (!scanning) return;
            setStatus('The camera could not read this barcode. Keep it centered or use a USB scanner/manual entry.', 'error');
        }

        if (scanning) animation = window.requestAnimationFrame(scanFrame);
    };

    const startCamera = async () => {
        if (scanning) {
            stopCamera();
            setStatus('Camera stopped. Saved verification progress is retained.');
            return;
        }

        if (!expectedCodesAreUsable) {
            setStatus('The assigned Parcel barcode list is unavailable. Contact Logistics before completing this delivery.', 'error');
            return;
        }

        if (!('BarcodeDetector' in window)) {
            setStatus('Camera barcode scanning is not supported here. Use a USB/Bluetooth scanner or enter the exact printed code.', 'error');
            return;
        }

        if (!navigator.mediaDevices?.getUserMedia) {
            setStatus('Camera access is unavailable. Use a USB/Bluetooth scanner or enter the exact printed code.', 'error');
            return;
        }

        openCameraButton.disabled = true;
        setStatus('Opening the rear camera…');

        try {
            candidateState = createBarcodeReadState();
            lastAttemptedValue = '';
            lastDetectionAt = 0;
            detector = new window.BarcodeDetector({ formats: ['code_128'] });
            stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: { ideal: 'environment' },
                    width: { ideal: 1920 },
                    height: { ideal: 1080 },
                },
                audio: false,
            });

            const track = stream.getVideoTracks()[0];
            try {
                const focusModes = track?.getCapabilities?.()?.focusMode;
                if (typeof track?.applyConstraints === 'function'
                    && Array.isArray(focusModes)
                    && focusModes.includes('continuous')) {
                    await track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] });
                }
            } catch {
                // Autofocus is optional; retain the camera's native fallback.
            }

            video.muted = true;
            video.playsInline = true;
            video.autoplay = true;
            video.srcObject = stream;
            cameraPanel.hidden = false;
            scanning = true;
            if (openCameraLabel) openCameraLabel.textContent = 'Stop camera';
            openCameraButton.setAttribute('aria-pressed', 'true');
            setStatus(`Point the rear camera at a Code 128 Parcel barcode and hold steady for ${REQUIRED_STABLE_BARCODE_READS} matching reads.`);
            await video.play();
            animation = window.requestAnimationFrame(scanFrame);
        } catch {
            stopCamera();
            setStatus('Camera permission was denied or the camera is unavailable. Use a scanner or enter the exact printed code.', 'error');
        } finally {
            openCameraButton.disabled = false;
        }
    };

    updateProgress();

    if (!expectedCodesAreUsable) {
        setStatus('The assigned Parcel barcode list is unavailable. Contact Logistics before completing this delivery.', 'error');
    }

    verifyButton.addEventListener('click', verifyInput);
    input.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        verifyInput();
    });
    openCameraButton.addEventListener('click', startCamera);
    closeCameraButton?.addEventListener('click', () => {
        stopCamera();
        setStatus('Camera stopped. Saved verification progress is retained.');
    });

    form.addEventListener('submit', (event) => {
        if (!allParcelsVerified()) {
            event.preventDefault();
            setStatus(expectedCodesAreUsable
                ? `Verify all ${expectedCodes.length} assigned Parcel barcodes before confirming delivery.`
                : 'The assigned Parcel barcode list is unavailable. Contact Logistics before completing this delivery.', 'error');
            input.focus();
            return;
        }

        stopCamera();
    });

    document.addEventListener('visibilitychange', () => {
        if (document.hidden && scanning) {
            stopCamera();
            setStatus('Camera paused because this page is hidden. Saved verification progress is retained.');
        } else if (!document.hidden) {
            refreshSavedProgress();
        }
    });

    progressPoll = window.setInterval(refreshSavedProgress, 5000);
    window.addEventListener('pagehide', () => {
        stopCamera();
        if (progressPoll !== null) window.clearInterval(progressPoll);
    });
});
