import { createAddressMapPicker } from './address-map-picker';

const map = document.querySelector('[data-seller-pickup-map]');
if (map) {
    const picker = createAddressMapPicker({
        element: map,
        latitudeInput: document.querySelector('[name="latitude"]'),
        longitudeInput: document.querySelector('[name="longitude"]'),
        clearButton: document.querySelector('[data-clear-pickup-pin]'),
        status: document.querySelector('[data-pickup-map-status]'),
        latitude: map.dataset.latitude || null,
        longitude: map.dataset.longitude || null,
    });

    const container = document.querySelector('[data-seller-pickup-map-form]');
    const saveButton = container?.querySelector('[data-save-pickup-pin]');
    const status = document.querySelector('[data-pickup-map-status]');
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
            if (status) status.textContent = 'Pickup map pin saved.';
        } catch (error) {
            if (status) status.textContent = error.message;
        } finally {
            saveButton.disabled = false;
        }
    });
}
