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
        const loadButton = form.querySelector('[data-waybill-load]');
        const sizeList = form.querySelector('[data-waybill-parcel-size-list]');
        const sizeStatus = form.querySelector('[data-waybill-parcel-size-status]');
        if (!input || !method || !openButton || !panel || !video) return;

        let stream = null;
        let detector = null;
        let animation = null;
        let scanning = false;
        let loadedIdentifier = '';
        let lookupSequence = 0;
        let activeLookup = null;
        let parcels = [];
        let matchedParcelId = null;
        const submitButton = form.querySelector('[type="submit"]');
        const canUseIntake = Boolean(submitButton && !submitButton.disabled);

        const lookupUrl = form.dataset.waybillLookupUrl || '';
        const requiresIntakeLookup = Boolean(lookupUrl && sizeList);
        const parseJsonNode = (selector, fallback) => {
            try {
                return JSON.parse(form.querySelector(selector)?.textContent || '');
            } catch {
                return fallback;
            }
        };
        const sizeOptions = parseJsonNode('[data-parcel-size-options]', []);
        const oldSizeClasses = parseJsonNode('[data-old-parcel-size-classes]', {});

        const setStatus = (message, state = '') => {
            if (!status) return;
            status.textContent = message;
            status.dataset.state = state;
        };

        const setSizeStatus = (message, state = '') => {
            if (!sizeStatus) return;
            sizeStatus.textContent = message;
            sizeStatus.dataset.state = state;
        };

        const clearParcelRows = () => {
            lookupSequence += 1;
            activeLookup = null;
            loadedIdentifier = '';
            parcels = [];
            matchedParcelId = null;
            sizeList?.replaceChildren();
            if (loadButton) loadButton.disabled = false;
            if (requiresIntakeLookup && submitButton) submitButton.disabled = true;
            setSizeStatus('Load a Waybill reference or scan a unique Parcel barcode.');
        };

        const renderParcelRows = (data) => {
            if (!sizeList) return;
            const fragment = document.createDocumentFragment();
            const items = Array.isArray(data?.parcels) ? data.parcels : [];
            matchedParcelId = data?.matched_parcel_id === null || data?.matched_parcel_id === undefined
                ? null
                : String(data.matched_parcel_id);

            items.forEach((parcel) => {
                const row = document.createElement('div');
                row.className = 'waybill-parcel-size-row';

                const description = document.createElement('span');
                description.className = 'waybill-parcel-size-copy';
                const parcelNumber = document.createElement('strong');
                parcelNumber.textContent = parcel.parcel_no || `Parcel #${parcel.id}`;
                const parcelMeta = document.createElement('small');
                const weight = Number(parcel.weight_kg);
                const dimensions = [parcel.length_cm, parcel.width_cm, parcel.height_cm]
                    .filter((value) => Number.isFinite(Number(value)) && Number(value) > 0)
                    .map((value) => `${Number(value).toFixed(2)} cm`)
                    .join(' × ');
                parcelMeta.textContent = Number.isFinite(weight) && weight > 0
                    ? `Piece ${parcel.piece_sequence || '—'} of ${data.pieces || items.length} · ${weight.toFixed(3)} kg${dimensions ? ` · ${dimensions}` : ''} · ${parcel.status || 'Awaiting intake'}`
                    : (parcel.status || 'Awaiting intake');
                description.append(parcelNumber, parcelMeta);

                const contents = document.createElement('small');
                const contentLines = Array.isArray(parcel.contents) ? parcel.contents.map((item) => {
                    const variant = item.variant && item.variant !== '—' ? ` · ${item.variant}` : '';
                    return `${item.name || 'Order item'}${variant} × ${item.quantity}`;
                }) : [];
                contents.textContent = contentLines.length > 0
                    ? `Contents: ${contentLines.join('; ')}`
                    : 'Contents not recorded for this parcel.';
                description.append(contents);

                const isSelected = matchedParcelId === String(parcel.id);
                const canReceive = ['created', 'picked_up'].includes(String(parcel.status || '').toLowerCase());

                if (isSelected && canReceive) {
                    const label = document.createElement('label');
                    label.className = 'waybill-parcel-size-control';
                    label.textContent = 'Inspect and classify';

                    const select = document.createElement('select');
                    select.name = `parcel_size_classes[${parcel.id}]`;
                    select.required = true;
                    select.setAttribute('aria-label', `Package size for ${parcel.parcel_no || `parcel ${parcel.id}`}`);

                    const emptyOption = document.createElement('option');
                    emptyOption.value = '';
                    emptyOption.textContent = 'Select size';
                    select.append(emptyOption);

                    sizeOptions.forEach((sizeClass) => {
                        const option = document.createElement('option');
                        option.value = sizeClass;
                        option.textContent = sizeClass
                            .replaceAll('_', ' ')
                            .replace(/\b\w/g, (character) => character.toUpperCase());
                        select.append(option);
                    });

                    const savedClass = oldSizeClasses[String(parcel.id)] || parcel.size_class || '';
                    if (sizeOptions.includes(savedClass)) select.value = savedClass;
                    label.append(select);
                    row.append(description, label);
                } else if (!isSelected && canReceive) {
                    const choose = document.createElement('button');
                    choose.type = 'button';
                    choose.className = 'button button-small';
                    choose.textContent = 'Select parcel';
                    choose.setAttribute('aria-label', `Load parcel ${parcel.parcel_no} for intake`);
                    choose.addEventListener('click', () => {
                        input.value = parcel.parcel_no || '';
                        method.value = 'manual';
                        clearParcelRows();
                        loadParcels(input.value);
                    });
                    row.append(description, choose);
                } else {
                    row.classList.add('is-received');
                    const state = document.createElement('span');
                    state.className = 'waybill-parcel-received-state';
                    state.textContent = isSelected ? `Already ${parcel.status}` : String(parcel.status || 'Not awaiting intake');
                    row.append(description, state);
                }
                fragment.append(row);
            });

            sizeList.replaceChildren(fragment);
            if (submitButton) submitButton.disabled = !canUseIntake || matchedParcelId === null
                || !items.some((parcel) => String(parcel.id) === matchedParcelId
                    && ['created', 'picked_up'].includes(String(parcel.status || '').toLowerCase()));
        };

        const loadParcels = async (rawIdentifier) => {
            const identifier = String(rawIdentifier || '').trim();
            if (!identifier || !lookupUrl || !sizeList) {
                setSizeStatus('Enter a Waybill reference or Parcel barcode first.', 'error');
                return false;
            }
            if (loadedIdentifier === identifier) return true;
            if (activeLookup?.identifier === identifier) return activeLookup.promise;

            const sequence = ++lookupSequence;
            loadedIdentifier = '';
            parcels = [];
            sizeList.replaceChildren();
            setSizeStatus('Looking up parcels…', 'loading');
            if (loadButton) loadButton.disabled = true;

            const promise = (async () => {
                try {
                    const url = lookupUrl.replace('__WAYBILL__', encodeURIComponent(identifier));
                    const response = await fetch(url, {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                        cache: 'no-store',
                    });
                    if (!response.ok) throw new Error('lookup_failed');

                    const payload = await response.json();
                    const data = payload?.data || {};
                    const items = Array.isArray(data.parcels) ? data.parcels : [];
                    if (sequence !== lookupSequence) return false;
                    if (items.length === 0) {
                        setSizeStatus('No parcels were found for this Waybill.', 'error');
                        return false;
                    }

                    parcels = items;
                    renderParcelRows(data);
                    loadedIdentifier = identifier;
                    const progress = `${data.received_pieces || 0} of ${data.pieces || items.length} pieces already received.`;
                    setSizeStatus(matchedParcelId
                        ? `Waybill ${data.waybill_no || ''} · ${progress} Review the scanned parcel and select its inspected size.`
                        : `Waybill ${data.waybill_no || ''} · ${progress} Scan a unique Parcel barcode or select one piece below.`);
                    return true;
                } catch {
                    if (sequence === lookupSequence) {
                        setSizeStatus('Parcel or Waybill not found for this Logistics account. Check the code and try again.', 'error');
                    }
                    return false;
                } finally {
                    if (sequence === lookupSequence && loadButton) loadButton.disabled = false;
                    if (activeLookup?.sequence === sequence) activeLookup = null;
                }
            })();

            activeLookup = { identifier, sequence, promise };
            return promise;
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
                    stop();

                    if (requiresIntakeLookup) {
                        clearParcelRows();
                        setStatus(`Scanned ${value}. Loading parcel details…`, 'success');
                        await loadParcels(value);
                    } else {
                        setStatus(`Scanned ${value}. Submit to accept this handoff.`, 'success');
                    }

                    return;
                }
            } catch {
                setStatus(
                    requiresIntakeLookup
                        ? 'The camera could not read this code. Try again or enter the Parcel barcode manually.'
                        : 'The camera could not read this code. Try again or enter the Waybill manually.',
                    'error',
                );
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
                setStatus(requiresIntakeLookup
                    ? 'Point the camera at the unique Code 128 Parcel barcode.'
                    : 'Point the camera at the Code 128 waybill barcode.');
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
            clearParcelRows();
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && input.value.trim() !== '') {
                method.value = 'barcode';

                if (requiresIntakeLookup) {
                    event.preventDefault();
                    loadParcels(input.value);
                }
            }
        });
        loadButton?.addEventListener('click', () => loadParcels(input.value));
        if (requiresIntakeLookup) input.addEventListener('change', () => loadParcels(input.value));
        form.addEventListener('submit', async (event) => {
            if (!requiresIntakeLookup) return;

            if (loadedIdentifier !== input.value.trim()) {
                event.preventDefault();
                await loadParcels(input.value);
                return;
            }
            if (parcels.length === 0 || matchedParcelId === null
                || sizeList?.querySelectorAll('select[required]').length !== 1) {
                event.preventDefault();
                setSizeStatus('Select or scan one unique Parcel barcode before logging its intake.', 'error');
                return;
            }
            if (method.value === '' || method.value === 'camera') return;
            if (method.value !== 'barcode') method.value = 'manual';
        });
        if (requiresIntakeLookup && input.value.trim() !== '') loadParcels(input.value);
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) stop();
        });
        window.addEventListener('pagehide', stop);
    });

    const handoffForm = document.querySelector('[data-handoff-accept-form]');
    const handoffInput = handoffForm?.querySelector('[data-waybill-identifier]');
    const handoffMethod = handoffForm?.querySelector('[data-waybill-scan-method]');

    if (handoffForm && handoffInput && handoffMethod) {
        document.querySelectorAll('[data-handoff-waybill-prefill]').forEach((button) => {
            button.addEventListener('click', () => {
                const waybill = String(button.dataset.handoffWaybillPrefill || '').trim();
                if (!waybill) return;

                handoffInput.value = waybill;
                handoffMethod.value = 'manual';
                handoffInput.dispatchEvent(new Event('input', { bubbles: true }));
                handoffForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
                handoffInput.focus({ preventScroll: true });
            });
        });
    }
})();
