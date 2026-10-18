(() => {
    const roots = [...document.querySelectorAll('[data-waybill-scan-form]')];

    roots.forEach((form) => {
        const input = form.querySelector('[data-waybill-identifier]');
        const method = form.querySelector('[data-waybill-scan-method]');
        const openButton = form.querySelector('[data-waybill-camera-open]');
        const panel = form.querySelector('[data-waybill-camera-panel]');
        const video = form.querySelector('[data-waybill-camera-video]');
        const status = form.querySelector('[data-waybill-camera-status]');
        const closeButton = form.querySelector('[data-waybill-camera-close]');
        if (!input || !method || !openButton || !panel || !video) return;

        let stream = null;
        let detector = null;
        let animation = null;
        let scanning = false;

        const setStatus = (message, state = '') => {
            if (!status) return;
            status.textContent = message;
            status.dataset.state = state;
        };

        const stop = () => {
            scanning = false;
            if (animation) window.cancelAnimationFrame(animation);
            animation = null;
            if (stream) stream.getTracks().forEach((track) => track.stop());
            stream = null;
            video.srcObject = null;
            panel.hidden = true;
        };

        const scanFrame = async () => {
            if (!scanning || !detector) return;
            try {
                const results = await detector.detect(video);
                const value = results?.find((result) => result.rawValue)?.rawValue?.trim();
                if (value) {
                    input.value = value;
                    method.value = 'camera';
                    setStatus(`Scanned ${value}. Submit to continue.`, 'success');
                    stop();
                    return;
                }
            } catch {
                setStatus('The camera could not read this code. Try again or enter the waybill manually.', 'error');
            }
            animation = window.requestAnimationFrame(scanFrame);
        };

        const start = async () => {
            if (!('BarcodeDetector' in window)) {
                setStatus('Camera barcode scanning is not supported in this browser. Use manual entry or a keyboard-wedge scanner.', 'error');
                return;
            }
            if (!navigator.mediaDevices?.getUserMedia) {
                setStatus('Camera access is unavailable. Use manual entry or a keyboard-wedge scanner.', 'error');
                return;
            }
            try {
                detector = new window.BarcodeDetector({ formats: ['code_128'] });
                stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
                video.srcObject = stream;
                panel.hidden = false;
                scanning = true;
                setStatus('Point the camera at the Code 128 waybill barcode.');
                await video.play();
                animation = window.requestAnimationFrame(scanFrame);
            } catch {
                stop();
                setStatus('Camera permission was denied or the camera is unavailable. Use manual entry instead.', 'error');
            }
        };

        openButton.addEventListener('click', start);
        closeButton?.addEventListener('click', stop);
        form.closest('[data-modal]')?.querySelectorAll('[data-modal-close]').forEach((button) => {
            button.addEventListener('click', stop);
        });
        input.addEventListener('input', () => {
            if (method.value === 'camera') method.value = 'manual';
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && input.value.trim() !== '') method.value = 'barcode';
        });
        form.addEventListener('submit', () => {
            if (method.value === '' || method.value === 'camera') return;
            if (method.value !== 'barcode') method.value = 'manual';
        });
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) stop();
        });
        window.addEventListener('pagehide', stop);
    });
})();
