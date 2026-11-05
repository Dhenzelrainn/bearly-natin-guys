import { createAddressMapPicker } from './address-map-picker';

const map = document.querySelector('[data-seller-pickup-map]');
if (map) {
    const picker = createAddressMapPicker({
        element: map,
        latitudeInput: document.querySelector('[name="latitude"]'),
        longitudeInput: document.querySelector('[name="longitude"]'),
        clearButton: document.querySelector('[data-clear-pickup-pin]'),
        locationButton: document.querySelector('[data-detect-pickup-location]'),
        manualPlacementButton: document.querySelector('[data-place-pickup-pin-manually]'),
        stableLocation: true,
        saveLabel: 'Save Pickup Pin',
        status: document.querySelector('[data-pickup-map-status]'),
        latitude: map.dataset.latitude || null,
        longitude: map.dataset.longitude || null,
    });

    const container = document.querySelector('[data-seller-pickup-map-form]');
    const saveButton = container?.querySelector('[data-save-pickup-pin]');
    const status = document.querySelector('[data-pickup-map-status]');
    const toast = document.querySelector('[data-seller-toast]');
    const setPickupStatus = (message, state) => {
        if (!status) return;
        status.textContent = message;
        status.dataset.state = state;
    };
    const showToast = (message, state) => {
        if (!toast) return;
        toast.textContent = message;
        toast.dataset.state = state;
        toast.classList.add('is-visible');
        window.clearTimeout(window.sellerToastTimer);
        window.sellerToastTimer = window.setTimeout(() => {
            toast.classList.remove('is-visible');
            delete toast.dataset.state;
        }, 3200);
    };

    saveButton?.addEventListener('click', async () => {
        const coordinates = picker?.coordinates() || {};
        saveButton.disabled = true;
        try {
            const response = await fetch(container.dataset.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: new URLSearchParams({
                    _method: 'PATCH',
                    latitude: coordinates.latitude || '',
                    longitude: coordinates.longitude || '',
                }),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(Object.values(payload.errors || {}).flat()[0] || payload.message || 'Unable to save the pickup pin.');
            const message = 'Pickup pin saved successfully.';
            setPickupStatus(message, 'success');
            showToast(message, 'success');
        } catch (error) {
            setPickupStatus(error.message, 'error');
            showToast(error.message, 'error');
        } finally {
            saveButton.disabled = false;
        }
    });
}
