import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import './leaflet-default-icon.js';
import {
    directionsUrl,
    normalizeRiderFix,
    shouldAppendTrailPoint,
    validCoordinatePair,
} from './rider-delivery-map-state.js';

const DEFAULT_CENTER = [12.8797, 121.774];
const RIDER_FIX_FRESHNESS_MS = 180_000;
const MAX_TRAIL_POINTS = 120;
const MAX_TRAIL_ACCURACY_METERS = 250;

const BUYER_PIN_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 9-7 9 7M5 9v12h14V9M9 21v-8h6v8"/></svg>';
const RIDER_PIN_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><circle cx="15" cy="5" r="1"/><path d="m12 17.5 3-6-4-3-3 8m-2.5 1h5l3.5-6h-5"/></svg>';

function deliveryPinIcon(kind, svg) {
    return L.divIcon({
        className: `rider-delivery-map-marker rider-delivery-map-marker--${kind}`,
        html: `<span>${svg}</span>`,
        iconSize: [42, 48],
        iconAnchor: [21, 44],
        tooltipAnchor: [0, -40],
    });
}

function initializeDeliveryMap(element) {
    const status = document.querySelector('[data-rider-delivery-map-status]');
    const centerButton = document.querySelector('[data-rider-delivery-center]');
    const directionsLink = document.querySelector('[data-rider-delivery-directions]');
    const address = element.dataset.destinationAddress?.trim() || '';
    const destination = validCoordinatePair(element.dataset.latitude, element.dataset.longitude);
    const destinationSource = element.dataset.destinationSource || 'order';
    const deliveryActive = element.dataset.deliveryActive === 'true';
    const destinationNote = destination
        ? `The destination marker uses the location saved with this ${destinationSource}.`
        : `No destination pin was saved with this ${destinationSource}; use the delivery address above.`;
    const trackingNote = deliveryActive
        ? ''
        : 'The movement trail and destination guide appear after delivery starts.';
    const tileUrl = element.dataset.mapTileUrl?.trim();
    let map = null;
    let riderMarker = null;
    let accuracyCircle = null;
    let staleTimer = null;
    let lastRiderFix = null;
    let lastFixReceivedAt = 0;
    let hasFittedLiveFix = false;
    let tileError = false;
    const riderTrail = [];
    let riderTrailLayer = null;
    let destinationGuideLayer = null;

    const setStatus = (message) => {
        if (!status) return;

        status.textContent = [message, destinationNote, trackingNote, tileError ? 'Map tiles could not load; Open directions remains available.' : '']
            .filter(Boolean)
            .join(' ');
    };

    if (directionsLink) {
        const url = directionsUrl(destination, address);
        directionsLink.hidden = !url;

        if (url) directionsLink.href = url;
    }

    const clearRiderFix = (message) => {
        if (staleTimer !== null) window.clearTimeout(staleTimer);
        staleTimer = null;
        lastRiderFix = null;
        lastFixReceivedAt = 0;

        if (riderMarker && map) map.removeLayer(riderMarker);
        if (accuracyCircle && map) map.removeLayer(accuracyCircle);
        riderMarker = null;
        accuracyCircle = null;
        riderTrail.length = 0;
        riderTrailLayer?.setLatLngs([]);
        destinationGuideLayer?.setLatLngs([]);
        if (centerButton) centerButton.hidden = true;
        setStatus(message);
    };

    if (!tileUrl) {
        element.hidden = true;
        setStatus('Map tiles are not configured.');
        return;
    }

    map = L.map(element, {
        scrollWheelZoom: false,
        doubleClickZoom: false,
    }).setView(destination || DEFAULT_CENTER, destination ? 15 : 6);

    const tiles = L.tileLayer(tileUrl, {
        attribution: element.dataset.mapAttribution || '&copy; OpenStreetMap contributors',
        maxZoom: 19,
    }).addTo(map);

    tiles.on('tileerror', () => {
        tileError = true;
        setStatus(lastRiderFix
            ? 'Your Rider GPS is live.'
            : 'Waiting for your shared Rider GPS.');
    });

    if (destination) {
        L.marker(destination, {
            icon: deliveryPinIcon('buyer', BUYER_PIN_SVG),
            title: element.dataset.destinationLabel || 'Saved destination',
            alt: element.dataset.destinationLabel || 'Saved destination',
            keyboard: false,
        })
            .bindTooltip(element.dataset.destinationLabel || 'Saved destination', { direction: 'top' })
            .addTo(map);
    }

    riderTrailLayer = L.polyline([], {
        color: '#187b72',
        weight: 4,
        opacity: 0.8,
        lineCap: 'round',
        lineJoin: 'round',
    }).addTo(map);
    destinationGuideLayer = L.polyline([], {
        color: '#d88b12',
        weight: 4,
        opacity: 0.92,
        dashArray: '7 8',
        lineCap: 'round',
        lineJoin: 'round',
    }).addTo(map);

    const showRiderFix = (fix) => {
        if (!map) return;

        lastRiderFix = fix;
        lastFixReceivedAt = Date.now();
        if (staleTimer !== null) window.clearTimeout(staleTimer);

        if (riderMarker) map.removeLayer(riderMarker);
        if (accuracyCircle) map.removeLayer(accuracyCircle);

        if (fix.accuracyMeters !== null && fix.accuracyMeters <= 5_000) {
            accuracyCircle = L.circle(fix.coordinates, {
                radius: Math.max(8, fix.accuracyMeters),
                color: '#187b72',
                weight: 1,
                fillColor: '#187b72',
                fillOpacity: 0.1,
            }).addTo(map);
        }

        riderMarker = L.marker(fix.coordinates, {
            icon: deliveryPinIcon('rider', RIDER_PIN_SVG),
            title: 'Your live Rider location',
            alt: 'Your live Rider location',
            keyboard: false,
        })
            .bindTooltip('Your live Rider location', { direction: 'top' })
            .addTo(map);

        if (deliveryActive && destination) destinationGuideLayer.setLatLngs([fix.coordinates, destination]);

        if (deliveryActive
            && fix.accuracyMeters !== null
            && fix.accuracyMeters <= MAX_TRAIL_ACCURACY_METERS
            && shouldAppendTrailPoint(riderTrail.at(-1), fix.coordinates)) {
            riderTrail.push(fix.coordinates);
            if (riderTrail.length > MAX_TRAIL_POINTS) riderTrail.shift();
            riderTrailLayer.setLatLngs(riderTrail.length > 1 ? riderTrail : []);
        }

        if (!deliveryActive) {
            riderTrail.length = 0;
            riderTrailLayer.setLatLngs([]);
        }

        if (centerButton) centerButton.hidden = false;

        if (!hasFittedLiveFix) {
            const points = destination ? [destination, fix.coordinates] : [fix.coordinates];
            map.fitBounds(L.latLngBounds(points), { padding: [28, 28], maxZoom: 15 });
            hasFittedLiveFix = true;
        }

        map.panInside(fix.coordinates, {
            padding: [54, 54],
            animate: true,
            duration: 0.35,
        });

        const accuracyMessage = fix.accuracyMeters === null
            ? 'Your live Rider pin is shown, but GPS accuracy is unavailable; treat it as approximate.'
            : fix.accuracyUsable
                ? `Your live Rider pin is updating (±${Math.round(fix.accuracyMeters)} m).`
                : `Your live Rider pin is updating but approximate (±${Math.round(fix.accuracyMeters)} m).`;
        setStatus(accuracyMessage);
        staleTimer = window.setTimeout(() => {
            clearRiderFix('Your Rider GPS has not updated recently. Keep location sharing on to show your position.');
        }, RIDER_FIX_FRESHNESS_MS);
    };

    window.addEventListener('bearly:rider-location-shared', (event) => {
        const fix = normalizeRiderFix(event.detail);

        if (!fix) {
            clearRiderFix('A valid Rider GPS coordinate is not available yet. Keep location sharing on while the device gets a fix.');
            return;
        }

        showRiderFix(fix);
    });

    window.addEventListener('bearly:rider-location-sharing-updated', (event) => {
        const detail = event.detail || {};

        if (detail.sharing !== true) {
            const message = detail.state === 'error'
                ? 'Rider GPS is unavailable or permission was denied. Allow location in your browser and device settings, then try again.'
                : document.body.dataset.riderAvailabilityStatus === 'available'
                    ? 'Turn on Share live location in the header to show your Rider position.'
                    : 'Set your Rider availability to Available before sharing live location.';

            clearRiderFix(message);
            return;
        }

        if (detail.accuracyUsable !== true && detail.state === 'pending') {
            setStatus('Waiting for a precise Rider GPS fix. Keep this page open while location sharing starts.');
        }
    });

    centerButton?.addEventListener('click', () => {
        if (lastRiderFix && map) map.setView(lastRiderFix.coordinates, 16);
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible' || !lastRiderFix) return;

        if (Date.now() - lastFixReceivedAt >= RIDER_FIX_FRESHNESS_MS) {
            clearRiderFix('Your Rider GPS has not updated recently. Keep location sharing on to show your position.');
        }
    });

    window.requestAnimationFrame(() => map?.invalidateSize());
    setStatus(document.body.dataset.riderAvailabilityStatus === 'available'
        ? 'Turn on Share live location in the header to show your Rider position.'
        : 'Set your Rider availability to Available before sharing live location.');
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-rider-delivery-map]').forEach(initializeDeliveryMap);
});
