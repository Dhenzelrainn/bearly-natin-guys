const clamp = (value, minimum, maximum) => Math.min(maximum, Math.max(minimum, value));

export const getCampaignBannerCropGeometry = (
    sourceWidth,
    sourceHeight,
    frameWidth,
    frameHeight,
    zoom = 1,
    panX = 0.5,
    panY = 0.5,
) => {
    const dimensions = [sourceWidth, sourceHeight, frameWidth, frameHeight, zoom].map(Number);
    if (!dimensions.every(value => Number.isFinite(value) && value > 0)) return null;

    const [width, height, viewportWidth, viewportHeight, scaleZoom] = dimensions;
    const scale = Math.max(viewportWidth / width, viewportHeight / height) * scaleZoom;
    const imageWidth = width * scale;
    const imageHeight = height * scale;
    const overflowX = Math.max(0, imageWidth - viewportWidth);
    const overflowY = Math.max(0, imageHeight - viewportHeight);
    const left = -overflowX * clamp(Number(panX) || 0, 0, 1);
    const top = -overflowY * clamp(Number(panY) || 0, 0, 1);
    const sourceX = clamp(-left / scale, 0, width);
    const sourceY = clamp(-top / scale, 0, height);

    return {
        scale,
        imageWidth,
        imageHeight,
        left,
        top,
        overflowX,
        overflowY,
        sourceX,
        sourceY,
        sourceWidth: Math.min(viewportWidth / scale, width - sourceX),
        sourceHeight: Math.min(viewportHeight / scale, height - sourceY),
    };
};

export const initAdminCampaignBannerCrop = (root = document) => {
    const cropField = root.querySelector('[data-campaign-banner-crop]');
    if (!cropField) return;

    const form = cropField.closest('form');
    const input = cropField.querySelector('[data-campaign-banner-input]');
    const editor = cropField.querySelector('[data-campaign-banner-editor]');
    const stage = cropField.querySelector('[data-campaign-banner-stage]');
    const preview = cropField.querySelector('[data-campaign-banner-preview]');
    const zoomInput = cropField.querySelector('[data-campaign-banner-zoom]');
    const zoomOutput = cropField.querySelector('[data-campaign-banner-zoom-output]');
    const resetButton = cropField.querySelector('[data-campaign-banner-reset]');
    const status = cropField.querySelector('[data-campaign-banner-status]');

    if (!form || !input || !editor || !stage || !preview || !zoomInput || !resetButton || !status) return;

    const maxFileSize = 10 * 1024 * 1024;
    const outputWidth = 1800;
    const outputHeight = 600;
    let previewUrl = null;
    let sourceImage = null;
    let previewReady = false;
    let loadVersion = 0;
    let zoom = 1;
    let panX = 0.5;
    let panY = 0.5;
    let drag = null;
    let cropProcessing = false;
    let cropSubmitted = false;

    const setStatus = (message, isError = false) => {
        status.textContent = message;
        status.dataset.state = isError ? 'error' : 'ready';
    };

    const setCropProcessing = (processing) => {
        cropProcessing = processing;
        input.disabled = processing;
        zoomInput.disabled = processing;
        resetButton.disabled = processing;
        stage.setAttribute('aria-disabled', processing ? 'true' : 'false');
    };

    const geometry = () => {
        if (!sourceImage || !stage.clientWidth || !stage.clientHeight) return null;

        return getCampaignBannerCropGeometry(
            sourceImage.naturalWidth,
            sourceImage.naturalHeight,
            stage.clientWidth,
            stage.clientHeight,
            zoom,
            panX,
            panY,
        );
    };

    const renderPreview = () => {
        const layout = geometry();
        if (!layout) return null;

        preview.style.width = `${layout.imageWidth}px`;
        preview.style.height = `${layout.imageHeight}px`;
        preview.style.left = `${layout.left}px`;
        preview.style.top = `${layout.top}px`;
        return layout;
    };

    const updateZoomLabel = () => {
        const percentage = `${Math.round(zoom * 100)}%`;
        if (zoomOutput) zoomOutput.value = percentage;
        if (zoomOutput) zoomOutput.textContent = percentage;
        zoomInput.setAttribute('aria-valuetext', `${Math.round(zoom * 100)} percent`);
    };

    const resetCrop = () => {
        zoom = 1;
        panX = 0.5;
        panY = 0.5;
        zoomInput.value = '1';
        updateZoomLabel();
        renderPreview();
        setStatus('Crop position reset to center.');
    };

    const releasePreviewUrl = () => {
        if (!previewUrl) return;
        URL.revokeObjectURL(previewUrl);
        previewUrl = null;
    };

    input.addEventListener('change', () => {
        const file = input.files?.[0] || null;
        loadVersion += 1;
        const currentVersion = loadVersion;
        sourceImage = null;
        previewReady = false;
        releasePreviewUrl();

        if (!file) {
            editor.hidden = true;
            setStatus('');
            return;
        }

        editor.hidden = false;
        zoom = 1;
        panX = 0.5;
        panY = 0.5;
        zoomInput.value = '1';
        updateZoomLabel();

        if (file.size > maxFileSize) {
            setStatus('The image must be 10 MB or smaller.', true);
            return;
        }

        const supportedType = /^image\/(jpeg|png|webp)$/i.test(file.type)
            || /\.(jpe?g|png|webp)$/i.test(file.name);
        if (!supportedType) {
            setStatus('Choose a JPG, PNG, or WebP image to preview the crop.', true);
            return;
        }

        setStatus('Preparing the crop preview…');
        previewUrl = URL.createObjectURL(file);
        preview.onload = () => {
            if (currentVersion !== loadVersion) return;
            sourceImage = preview;
            previewReady = true;
            renderPreview();
            setStatus('Drag the image to reposition it, or adjust zoom before saving.');
        };
        preview.onerror = () => {
            if (currentVersion !== loadVersion) return;
            setStatus('This image could not be previewed. Try another JPG, PNG, or WebP image.', true);
        };
        preview.src = previewUrl;
    });

    zoomInput.addEventListener('input', () => {
        zoom = clamp(Number(zoomInput.value) || 1, 1, 3);
        updateZoomLabel();
        renderPreview();
    });
    resetButton.addEventListener('click', resetCrop);
    window.addEventListener('resize', renderPreview, { passive: true });

    stage.addEventListener('pointerdown', (event) => {
        if (cropProcessing || !previewReady || (event.button !== undefined && event.button !== 0)) return;

        const layout = renderPreview();
        if (!layout) return;
        drag = { pointerId: event.pointerId, x: event.clientX, y: event.clientY, panX, panY };
        stage.classList.add('is-dragging');
        try {
            stage.setPointerCapture(event.pointerId);
        } catch {
            // Pointer capture is an enhancement; pointer movement still works without it.
        }
        event.preventDefault();
    });

    stage.addEventListener('pointermove', (event) => {
        if (!drag || event.pointerId !== drag.pointerId) return;
        const layout = geometry();
        if (!layout) return;

        panX = layout.overflowX > 0
            ? clamp(drag.panX - (event.clientX - drag.x) / layout.overflowX, 0, 1)
            : 0.5;
        panY = layout.overflowY > 0
            ? clamp(drag.panY - (event.clientY - drag.y) / layout.overflowY, 0, 1)
            : 0.5;
        renderPreview();
    });

    const finishDrag = (event) => {
        if (drag && event.pointerId === drag.pointerId) drag = null;
        stage.classList.remove('is-dragging');
    };
    stage.addEventListener('pointerup', finishDrag);
    stage.addEventListener('pointercancel', finishDrag);
    stage.addEventListener('lostpointercapture', finishDrag);

    stage.addEventListener('keydown', (event) => {
        if (cropProcessing || !previewReady) return;
        const layout = geometry();
        if (!layout) return;

        const step = event.shiftKey ? 48 : 16;
        const panStepX = layout.overflowX > 0 ? step / layout.overflowX : 0;
        const panStepY = layout.overflowY > 0 ? step / layout.overflowY : 0;

        if (event.key === 'ArrowLeft') panX = clamp(panX + panStepX, 0, 1);
        else if (event.key === 'ArrowRight') panX = clamp(panX - panStepX, 0, 1);
        else if (event.key === 'ArrowUp') panY = clamp(panY + panStepY, 0, 1);
        else if (event.key === 'ArrowDown') panY = clamp(panY - panStepY, 0, 1);
        else return;

        event.preventDefault();
        renderPreview();
    });

    form.addEventListener('submit', async (event) => {
        if (cropSubmitted || !input.files?.[0]) return;

        event.preventDefault();
        event.stopImmediatePropagation();

        if (cropProcessing) return;

        if (!previewReady || !sourceImage) {
            setStatus('Wait for the crop preview to finish loading before saving.', true);
            return;
        }

        const layout = renderPreview();
        if (!layout) {
            setStatus('The crop preview is not ready. Select the image again and retry.', true);
            return;
        }

        setCropProcessing(true);
        const canvas = document.createElement('canvas');
        canvas.width = outputWidth;
        canvas.height = outputHeight;

        try {
            const context = canvas.getContext('2d');
            if (!context) throw new Error('Canvas is unavailable.');
            context.drawImage(
                sourceImage,
                layout.sourceX,
                layout.sourceY,
                layout.sourceWidth,
                layout.sourceHeight,
                0,
                0,
                outputWidth,
                outputHeight,
            );

            const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/webp', 0.9));
            if (!blob) throw new Error('The image crop could not be encoded.');
            if (blob.size > maxFileSize) throw new Error('The cropped image exceeds the 10 MB upload limit.');

            const mimeType = blob.type === 'image/webp' ? 'image/webp' : 'image/png';
            const extension = mimeType === 'image/webp' ? 'webp' : 'png';
            const croppedFile = new File([blob], `campaign-banner-crop.${extension}`, {
                type: mimeType,
                lastModified: Date.now(),
            });
            const transfer = new DataTransfer();
            transfer.items.add(croppedFile);
            input.files = transfer.files;
            cropSubmitted = true;
            setStatus('Crop prepared. Saving campaign…');
            setCropProcessing(false);

            if (typeof form.requestSubmit === 'function') {
                if (event.submitter && form.contains(event.submitter)) form.requestSubmit(event.submitter);
                else form.requestSubmit();
            } else {
                form.submit();
            }
        } catch (error) {
            setCropProcessing(false);
            setStatus(error instanceof Error ? error.message : 'The crop could not be prepared. Try another image.', true);
        }
    }, true);

    window.addEventListener('pagehide', releasePreviewUrl, { once: true });
};
