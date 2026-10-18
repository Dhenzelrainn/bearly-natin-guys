import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

function coordinatePair(latitude, longitude) {
    const parse = (value, minimum, maximum) => {
        if (value === null || value === undefined || String(value).trim() === '') return null;
        const number = Number(value);
        return Number.isFinite(number) && number >= minimum && number <= maximum ? number : null;
    };

    const lat = parse(latitude, -90, 90);
    const lng = parse(longitude, -180, 180);

    return lat === null || lng === null ? null : [lat, lng];
}

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
    const initialCoordinates = coordinatePair(latitude, longitude);
    const map = L.map(element, { scrollWheelZoom: false }).setView(
        initialCoordinates || defaultCenter,
        initialCoordinates ? 16 : 6,
    );

    const setStatus = (message) => {
        if (status) status.textContent = message;
    };

    const tileUrl = element.dataset.mapTileUrl?.trim();
    if (tileUrl) {
        const tileLayer = L.tileLayer(tileUrl, {
            attribution: element.dataset.mapAttribution || '&copy; OpenStreetMap contributors',
            maxZoom: 19,
        });
        tileLayer.on('tileerror', () => {
            setStatus('Map tiles could not load. Check MAPTILER_API_KEY, the allowed origin, and your internet connection.');
        });
        tileLayer.addTo(map);
    } else {
        setStatus('Map tiles are not configured. Set MAPS_TILE_URL in your .env file.');
    }

    let marker = null;

    if (!initialCoordinates) clearButton?.setAttribute('hidden', 'hidden');

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

    if (initialCoordinates) {
        setPin({ lat: initialCoordinates[0], lng: initialCoordinates[1] }, false);
    }

    map.on('click', event => setPin(event.latlng));
    clearButton?.addEventListener('click', clearPin);

    return {
        map,
        reset: (lat = null, lng = null) => {
            clearPin();
            const coordinates = coordinatePair(lat, lng);
            if (coordinates) {
                map.setView(coordinates, 16);
                setPin({ lat: coordinates[0], lng: coordinates[1] }, false);
            } else {
                map.setView(defaultCenter, 6);
            }
            window.setTimeout(() => map.invalidateSize(), 0);
        },
        coordinates: () => ({
            latitude: latitudeInput.value || null,
            longitude: longitudeInput.value || null,
        }),
    };
}
