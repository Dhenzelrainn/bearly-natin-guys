import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import './leaflet-default-icon.js';

const DEFAULT_CENTER = [12.8797, 121.7740];

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

function targetElement(element, datasetKey) {
    const selector = element.dataset[datasetKey]?.trim();
    if (!selector) return null;

    return selector.startsWith('#')
        ? document.querySelector(selector)
        : document.getElementById(selector);
}

function setStatus(element, message) {
    const status = targetElement(element, 'mapStatusTarget');
    if (status) status.textContent = message;
}

function updateOsmLink(element, latitude, longitude) {
    const link = targetElement(element, 'mapOsmLinkTarget');
    if (!link) return;

    link.href = `https://www.openstreetmap.org/?mlat=${encodeURIComponent(latitude)}&mlon=${encodeURIComponent(longitude)}#map=17/${encodeURIComponent(latitude)}/${encodeURIComponent(longitude)}`;
}

async function locatePickupAddress(element, showLocation) {
    const geocodeUrl = element.dataset.mapForwardGeocodeUrl?.trim();
    const street = element.dataset.mapStreet?.trim();
    const barangay = element.dataset.mapBarangay?.trim();
    const city = element.dataset.mapCity?.trim();
    const province = element.dataset.mapProvince?.trim();
    const postal = element.dataset.mapPostal?.trim();

    if (!geocodeUrl || !barangay || !city || !province) {
        setStatus(element, 'The pickup address is incomplete, so the map cannot place a pin.');
        return;
    }

    const parameters = new URLSearchParams({ barangay, city, province });
    if (street) parameters.set('street', street);
    if (postal) parameters.set('postal', postal);

    setStatus(element, 'Locating the pickup address on OpenStreetMap…');

    try {
        const response = await fetch(`${geocodeUrl}?${parameters.toString()}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(payload.message || 'OpenStreetMap could not locate this pickup address.');
        }

        const data = payload.data || {};
        const latitude = Number(data.latitude);
        const longitude = Number(data.longitude);
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
            throw new Error('OpenStreetMap did not return usable pickup coordinates.');
        }

        showLocation([latitude, longitude], data.approximate ? 14 : 16);
        setStatus(
            element,
            data.approximate
                ? 'Approximate pickup area found on OpenStreetMap. Use the map link for exact navigation.'
                : 'Pickup location found on OpenStreetMap. Use the map link for exact navigation.',
        );
    } catch (error) {
        const message = error instanceof Error ? error.message : 'OpenStreetMap could not locate this pickup address.';
        setStatus(element, `${message} You can still open the address in OpenStreetMap.`);
    }
}

document.querySelectorAll('[data-destination-map]').forEach((element) => {
    const coordinates = coordinatePair(element.dataset.latitude, element.dataset.longitude);
    const tileUrl = element.dataset.mapTileUrl?.trim();
    if (!tileUrl) {
        setStatus(element, 'Map tiles are not configured.');
        return;
    }

    const map = L.map(element, { scrollWheelZoom: false, dragging: false }).setView(
        coordinates || DEFAULT_CENTER,
        coordinates ? 16 : 6,
    );

    const tileLayer = L.tileLayer(tileUrl, {
        attribution: element.dataset.mapAttribution || '&copy; OpenStreetMap contributors',
        maxZoom: 19,
    }).addTo(map);

    tileLayer.on('tileerror', () => {
        setStatus(element, 'Map tiles could not load. Check your connection and try again.');
    });

    let marker = null;
    const showLocation = (latlng, zoom = 16) => {
        map.setView(latlng, zoom);
        if (!marker) {
            marker = L.marker(latlng).addTo(map);
        } else {
            marker.setLatLng(latlng);
        }
        updateOsmLink(element, latlng[0], latlng[1]);
    };

    if (coordinates) {
        showLocation(coordinates, 16);
    } else {
        locatePickupAddress(element, showLocation);
    }

    window.setTimeout(() => map.invalidateSize(), 0);
});
