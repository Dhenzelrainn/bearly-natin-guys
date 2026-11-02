const DRAFT_FIELDS = [
    'name', 'marketplace_subcategory_id', 'subcategory_id', 'new_subcategory_name',
    'description', 'sku', 'price', 'voucher_eligible', 'product_mode', 'stock',
    'low_stock_threshold', 'package_weight_kg', 'package_length_cm', 'package_width_cm',
    'package_height_cm', 'option_one_name', 'option_one_values', 'option_two_name',
    'option_two_values', 'package_shared',
];

const csrfToken = (form) => form.querySelector('input[name="_token"]')?.value
    || document.querySelector('meta[name="csrf-token"]')?.content
    || '';

function fieldValues(form) {
    const fields = {};

    DRAFT_FIELDS.forEach((name) => {
        const controls = [...form.elements].filter((control) => control.name === name);
        if (name === 'voucher_eligible' || name === 'package_shared') {
            const checkbox = controls.find((control) => control.type === 'checkbox');
            fields[name] = checkbox?.checked ? '1' : '0';
            return;
        }

        const control = controls.find((candidate) => candidate.type !== 'radio')
            || controls.find((candidate) => candidate.checked);
        fields[name] = String(control?.value ?? '').slice(0, 1000);
    });

    const sharedPackage = {};
    [...form.elements].forEach((control) => {
        const match = /^shared_package\[([a-z_]+)\]$/.exec(control.name || '');
        if (match) sharedPackage[match[1]] = String(control.value ?? '').slice(0, 30);
    });
    fields.shared_package = sharedPackage;

    return fields;
}

function variantRows(form) {
    return [...form.querySelectorAll('[data-variant-main-row]')].map((row) => {
        const index = row.dataset.variantIndex;
        const packageRow = row.nextElementSibling?.matches('[data-variant-package-row]')
            ? row.nextElementSibling
            : null;
        const value = (field) => row.querySelector(`[name="variants[${index}][${field}]"]`)?.value
            ?? packageRow?.querySelector(`[name="variants[${index}][${field}]"]`)?.value
            ?? '';

        return {
            label: row.querySelector(`[name="variants[${index}][label]"]`)?.value || '',
            sku: value('sku'),
            price: value('price'),
            stock: value('stock'),
            low_stock_threshold: value('low_stock_threshold'),
            package_weight_kg: value('package_weight_kg'),
            package_length_cm: value('package_length_cm'),
            package_width_cm: value('package_width_cm'),
            package_height_cm: value('package_height_cm'),
            package_override: Boolean(packageRow?.querySelector('[data-variant-package-override]')?.checked),
            package_selected: Boolean(row.querySelector('[data-variant-package-copy-target]')?.checked),
            packageDetailsOpen: Boolean(packageRow?.querySelector('[data-variant-package-details]')?.open),
        };
    }).filter((variant) => variant.label.trim() !== '');
}

function variantColors(form) {
    return [...form.querySelectorAll('[name^="variant_image_colors["]')]
        .map((select) => ({
            index: Number(/\[(\d+)\]/.exec(select.name)?.[1] || 0),
            color: String(select.value || '').slice(0, 120),
        }))
        .sort((left, right) => left.index - right.index)
        .map(({ color }) => color);
}

function draftImageColors(form) {
    const colors = {};

    [...form.querySelectorAll('[name^="draft_image_colors["]')].forEach((control) => {
        const match = /^draft_image_colors\[(\d+)\]$/.exec(control.name || '');
        if (!match) return;

        colors[match[1]] = String(control.value || '').slice(0, 120);
    });

    return colors;
}

export function setupSellerProductDraft(form) {
    const saveUrl = form.dataset.productDraftSaveUrl;
    const uploadUrl = form.dataset.productDraftImageUploadUrl;
    const clearUrl = form.dataset.productDraftImageClearUrl;
    const deleteUrlTemplate = form.dataset.productDraftImageDeleteUrl;
    const status = document.querySelector('[data-product-draft-status]');
    if (!saveUrl || !uploadUrl || !clearUrl || !deleteUrlTemplate) return;

    let changeVersion = 0;
    let savedVersion = 0;
    let timer = null;
    let saving = null;
    let submitPending = false;
    let resubmitting = false;
    const uploadQueues = new Map();

    const setStatus = (message, failed = false) => {
        if (!status) return;
        status.textContent = message;
        status.dataset.state = failed ? 'error' : 'info';
    };

    const payload = () => ({
        fields: fieldValues(form),
        variants: variantRows(form),
        variant_image_colors: variantColors(form),
        draft_image_colors: draftImageColors(form),
    });

    const saveNow = async (keepalive = false) => {
        if (savedVersion >= changeVersion) return true;
        if (saving) {
            await saving;
            if (savedVersion >= changeVersion) return true;
        }

        saving = (async () => {
            let succeeded = false;
            try {
                while (savedVersion < changeVersion) {
                    const currentVersion = changeVersion;
                    const response = await fetch(saveUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        keepalive,
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken(form),
                        },
                        body: JSON.stringify(payload()),
                    });
                    if (!response.ok) throw new Error('Product draft autosave failed.');
                    savedVersion = currentVersion;
                    succeeded = true;
                    setStatus('Product details saved privately for 7 days.');
                    if (keepalive) break;
                }
                return true;
            } catch (_) {
                setStatus('Autosave is temporarily unavailable. Your current form remains open.', true);
                return false;
            } finally {
                saving = null;
                if (succeeded && savedVersion < changeVersion && !keepalive) schedule(false);
            }
        })();

        return saving;
    };

    const schedule = (markChanged = true) => {
        if (markChanged) changeVersion += 1;
        window.clearTimeout(timer);
        setStatus('Saving privately…');
        timer = window.setTimeout(() => { void saveNow(); }, 700);
    };

    const imageInputs = {
        primary: () => form.querySelector('[data-product-image-input]'),
        gallery: () => form.querySelector('input[name="gallery_images[]"]'),
        variant_color: () => form.querySelector('[data-variant-color-image-input]'),
    };

    const uploadImages = (purpose) => {
        const input = imageInputs[purpose]?.();
        const files = [...(input?.files || [])];
        const colors = purpose === 'variant_color'
            ? [...form.querySelectorAll('[name^="variant_image_colors["]')]
                .sort((left, right) => Number(/\[(\d+)\]/.exec(left.name)?.[1] || 0)
                    - Number(/\[(\d+)\]/.exec(right.name)?.[1] || 0))
                .map((select) => select.value || '')
            : [];

        const prior = uploadQueues.get(purpose) || Promise.resolve();
        const next = prior.catch(() => {}).then(async () => {
            try {
                let response;
                if (files.length) {
                    const body = new FormData();
                    body.append('purpose', purpose);
                    files.forEach((file) => body.append('images[]', file));
                    colors.forEach((color) => body.append('colors[]', color));
                    response = await fetch(uploadUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken(form) },
                        body,
                    });
                } else {
                    response = await fetch(clearUrl, {
                        method: 'DELETE',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken(form),
                        },
                        body: JSON.stringify({ purpose }),
                    });
                }

                if (!response.ok) throw new Error('Photo autosave failed.');
                const result = await response.json();
                form.dispatchEvent(new CustomEvent('seller:draft-images-updated', {
                    bubbles: true,
                    detail: { purpose, images: Array.isArray(result.data) ? result.data : [] },
                }));
                setStatus(files.length ? 'Product photos saved privately for 7 days.' : 'Product photo removal saved.');
                return true;
            } catch (_) {
                setStatus('A photo could not be saved privately. Keep the file selected and retry before leaving.', true);
                return false;
            }
        });

        uploadQueues.set(purpose, next);
        return next;
    };

    const waitForUploads = async () => Promise.all([...uploadQueues.values()]);

    form.addEventListener('input', () => schedule());
    form.addEventListener('change', (event) => {
        if (event.target.matches('input[type="file"]')) {
            const purpose = event.target.matches('[data-product-image-input]')
                ? 'primary'
                : event.target.matches('[name="gallery_images[]"]')
                    ? 'gallery'
                    : event.target.matches('[data-variant-color-image-input]')
                        ? 'variant_color'
                        : null;
            if (purpose) void uploadImages(purpose);
        }
        schedule();
    });

    form.addEventListener('seller:draft-image-files-changed', (event) => {
        const purpose = event.detail?.purpose;
        if (purpose && imageInputs[purpose]) void uploadImages(purpose);
    });

    form.addEventListener('seller:draft-image-removed', (event) => {
        const { imageId, purpose } = event.detail || {};
        if (!imageId || !purpose) return;
        const url = deleteUrlTemplate.replace('__IMAGE__', encodeURIComponent(String(imageId)));
        const queue = (uploadQueues.get(purpose) || Promise.resolve()).catch(() => {}).then(async () => {
            const response = await fetch(url, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(form),
                },
                body: JSON.stringify({}),
            });
            if (!response.ok) throw new Error('Photo removal failed.');
            const result = await response.json();
            form.dispatchEvent(new CustomEvent('seller:draft-images-updated', {
                bubbles: true,
                detail: {
                    purpose,
                    images: Array.isArray(result.data) ? result.data : [],
                },
            }));
            setStatus('Product photo removal saved.');
            return true;
        }).catch(() => {
            setStatus('The photo could not be removed from the saved draft. Try again.', true);
            return false;
        });
        uploadQueues.set(purpose, queue);
    });

    form.addEventListener('submit', async (event) => {
        if (resubmitting) {
            resubmitting = false;
            return;
        }

        event.preventDefault();
        if (submitPending) return;
        submitPending = true;
        const submitter = event.submitter;
        window.clearTimeout(timer);
        changeVersion += 1;
        setStatus('Saving your latest details and photos…');

        await waitForUploads();
        await saveNow();
        submitPending = false;

        // requestSubmit() runs native constraint validation before dispatching
        // submit. If the form is invalid, no submit event is dispatched and a
        // resubmission sentinel would otherwise remain stuck on the next try.
        if (!form.checkValidity()) {
            resubmitting = false;
            return;
        }

        resubmitting = true;
        if (submitter && typeof form.requestSubmit === 'function') form.requestSubmit(submitter);
        else if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
    });

    window.addEventListener('pagehide', () => {
        window.clearTimeout(timer);
        if (savedVersion < changeVersion) void saveNow(true);
    });
}
