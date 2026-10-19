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
    locationButton,
    reverseGeocodeUrl,
    forwardGeocodeUrl,
    fields = {},
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

    let lookupSequence = 0;
    const reverseGeocode = async (lat, lng) => {
        if (!reverseGeocodeUrl) return;
        const sequence = ++lookupSequence;
        setStatus('Looking up address details from OpenStreetMap…');
        try {
            const response = await fetch(`${reverseGeocodeUrl}?latitude=${encodeURIComponent(lat)}&longitude=${encodeURIComponent(lng)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || sequence !== lookupSequence) throw new Error(payload.message || 'Address lookup failed.');
            const data = payload.data || {};
            setStatus(`Pin selected near ${data.display_name || 'the selected location'}. Verify your address fields and pin before saving.`);
        } catch (error) {
            if (sequence === lookupSequence) setStatus(`${error.message} You can keep the pin and enter the address manually.`, 'error');
        }
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
        if (announce) reverseGeocode(Number(lat), Number(lng));
    };

    let forwardTimer = null;
    let forwardSequence = 0;
    const scheduleForwardGeocode = () => {
        if (!forwardGeocodeUrl || !fields.province?.value.trim() || !fields.city?.value.trim() || !fields.barangay?.value.trim()) return;
        window.clearTimeout(forwardTimer);
        forwardTimer = window.setTimeout(async () => {
            const sequence = ++forwardSequence;
            const params = new URLSearchParams({
                province: fields.province.value.trim(),
                city: fields.city.value.trim(),
                barangay: fields.barangay.value.trim(),
            });
            if (fields.street?.value.trim()) params.set('street', fields.street.value.trim());
            if (fields.postal?.value.trim()) params.set('postal', fields.postal.value.trim());
            setStatus('Searching the address on the map…');
            try {
                const response = await fetch(`${forwardGeocodeUrl}?${params.toString()}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || sequence !== forwardSequence) throw new Error(payload.message || 'Address search failed.');
                const data = payload.data;
                map.setView([Number(data.latitude), Number(data.longitude)], data.approximate ? 14 : 17);
                setPin({ lat: Number(data.latitude), lng: Number(data.longitude) }, false);
                setStatus(`${data.approximate ? 'Approximate locality match' : 'Address match'} found. Drag the pin to confirm the exact location, then save.`);
            } catch (error) {
                if (sequence === forwardSequence) setStatus(`${error.message} You can place the pin manually.`, 'error');
            }
        }, 1200);
    };

    Object.values(fields).forEach((field) => field?.addEventListener('input', scheduleForwardGeocode));

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
    locationButton?.addEventListener('click', () => {
        if (!navigator.geolocation) {
            setStatus('Browser location is unavailable. Place the pin manually.', 'error');
            return;
        }

        locationButton.disabled = true;
        setStatus('Requesting your location permission…');
        navigator.geolocation.getCurrentPosition(
            (position) => {
                const coords = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude,
                };
                map.setView([coords.lat, coords.lng], 16);
                setPin(coords, true);
                setStatus('Current location selected. Drag the pin to confirm the exact point, then verify the suggested address before saving.');
                locationButton.disabled = false;
            },
            () => {
                setStatus('Location permission was denied or unavailable. Place the pin manually.', 'error');
                locationButton.disabled = false;
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 },
        );
    });

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
