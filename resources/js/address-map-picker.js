import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

export function createAddressMapPicker({
    element,
    latitudeInput,
    longitudeInput,
    clearButton,
    status,
    latitude = null,
    longitude = null,
}) {
    if (!element || !latitudeInput || !longitudeInput) return null;

    const defaultCenter = [12.8797, 121.7740];
    const hasCoordinates = Number.isFinite(Number(latitude)) && Number.isFinite(Number(longitude));
    const map = L.map(element, { scrollWheelZoom: false }).setView(
        hasCoordinates ? [Number(latitude), Number(longitude)] : defaultCenter,
        hasCoordinates ? 16 : 6,
    );

    L.tileLayer(element.dataset.mapTileUrl, {
        attribution: element.dataset.mapAttribution || '&copy; OpenStreetMap contributors',
        maxZoom: 19,
    }).addTo(map);

    let marker = null;

    if (!hasCoordinates) clearButton?.setAttribute('hidden', 'hidden');

    const setStatus = (message) => {
        if (status) status.textContent = message;
    };

    const setPin = (latlng, announce = true) => {
        const lat = Number(latlng.lat).toFixed(7);
        const lng = Number(latlng.lng).toFixed(7);
        latitudeInput.value = lat;
        longitudeInput.value = lng;
        if (!marker) {
            marker = L.marker([Number(lat), Number(lng)], { draggable: true }).addTo(map);
            marker.on('dragend', () => setPin(marker.getLatLng()));
        } else {
            marker.setLatLng([Number(lat), Number(lng)]);
        }
        clearButton?.removeAttribute('hidden');
        if (announce) setStatus(`Pin selected: ${lat}, ${lng}. Click Save Address to persist it.`);
    };

    const clearPin = () => {
        marker?.remove();
        marker = null;
        latitudeInput.value = '';
        longitudeInput.value = '';
        clearButton?.setAttribute('hidden', 'hidden');
        setStatus('No coordinates selected. Your validated address will still be saved.');
    };

    if (hasCoordinates) setPin({ lat: Number(latitude), lng: Number(longitude) }, false);
    map.on('click', event => setPin(event.latlng));
    clearButton?.addEventListener('click', clearPin);

    return {
        map,
        reset: (lat = null, lng = null) => {
            clearPin();
            if (Number.isFinite(Number(lat)) && Number.isFinite(Number(lng))) {
                map.setView([Number(lat), Number(lng)], 16);
                setPin({ lat: Number(lat), lng: Number(lng) }, false);
            } else {
                map.setView(defaultCenter, 6);
            }
            window.setTimeout(() => map.invalidateSize(), 0);
        },
        coordinates: () => ({ latitude: latitudeInput.value || null, longitude: longitudeInput.value || null }),
    };
}
