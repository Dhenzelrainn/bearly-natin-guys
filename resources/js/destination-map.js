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

    const routeEndpoint = element.dataset.pickupRouteEndpoint?.trim();
    const routeStatusTarget = () => targetElement(element, 'pickupRouteStatusTarget');
    const setRouteStatus = (message) => {
        const status = routeStatusTarget();
        if (status) status.textContent = message;
    };

    if (element.dataset.pickupRouteEnabled !== 'true' || !routeEndpoint) return;

    const ROUTE_REFRESH_INTERVAL_MS = 15_000;
    let sharingActive = false;
    let locationUsable = false;
    let routeLayer = null;
    let riderMarker = null;
    let refreshTimer = null;
    let expiryTimer = null;
    let requestController = null;
    let hasFittedRoute = false;

    const removeRouteLayers = () => {
        if (expiryTimer !== null) window.clearTimeout(expiryTimer);
        expiryTimer = null;

        if (routeLayer) map.removeLayer(routeLayer);
        if (riderMarker) map.removeLayer(riderMarker);
        routeLayer = null;
        riderMarker = null;
    };

    const stopRouteUpdates = (message) => {
        sharingActive = false;
        if (refreshTimer !== null) window.clearInterval(refreshTimer);
        refreshTimer = null;
        requestController?.abort();
        requestController = null;
        removeRouteLayers();
        setRouteStatus(message);
    };

    const refreshRoute = async () => {
        if (!sharingActive || !locationUsable || requestController) return;

        const controller = new AbortController();
        requestController = controller;

        try {
            const response = await fetch(routeEndpoint, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: controller.signal,
            });
            const payload = await response.json().catch(() => ({}));

            if (!sharingActive || !locationUsable || requestController !== controller) return;
            if (!response.ok) {
                throw new Error(payload.message || 'The road route is unavailable right now.');
            }

            const data = payload.data || {};
            const riderCoordinates = coordinatePair(data.rider?.latitude, data.rider?.longitude);
            const lastSeenAt = Date.parse(data.rider?.last_seen_at || '');
            const staleAfterSeconds = Number(data.stale_after_seconds);
            const millisecondsUntilStale = lastSeenAt + (staleAfterSeconds * 1000) - Date.now();

            if (!riderCoordinates
                || !Number.isFinite(lastSeenAt)
                || !Number.isFinite(staleAfterSeconds)
                || staleAfterSeconds <= 0
                || millisecondsUntilStale <= 0
                || millisecondsUntilStale > staleAfterSeconds * 1000) {
                throw new Error('Rider GPS is stale. Keep location sharing on to refresh the road route.');
            }

            const nextRouteLayer = L.geoJSON(data.route, {
                style: {
                    color: '#c76500',
                    weight: 5,
                    opacity: 0.92,
                    lineCap: 'round',
                    lineJoin: 'round',
                },
            });
            const routeBounds = nextRouteLayer.getBounds();

            if (!routeBounds.isValid()) {
                throw new Error('The road-routing service returned no usable route.');
            }

            removeRouteLayers();
            routeLayer = nextRouteLayer.addTo(map);
            riderMarker = L.circleMarker(riderCoordinates, {
                radius: 8,
                color: '#ffffff',
                weight: 3,
                fillColor: '#187b72',
                fillOpacity: 1,
            })
                .bindPopup('Your last shared Rider location.')
                .addTo(map);

            if (!hasFittedRoute) {
                map.fitBounds(routeBounds.extend(riderCoordinates), {
                    padding: [24, 24],
                    maxZoom: 16,
                });
                hasFittedRoute = true;
            }

            expiryTimer = window.setTimeout(() => {
                removeRouteLayers();
                setRouteStatus('Rider GPS has gone stale. Keep location sharing on to refresh the road route.');
            }, millisecondsUntilStale);

            setRouteStatus('The Rider marker follows new GPS fixes; road geometry refreshes about every 15 seconds. No ETA is provided.');
        } catch (error) {
            if (error?.name === 'AbortError') return;

            removeRouteLayers();
            const message = error instanceof Error
                ? error.message
                : 'The road route is unavailable right now.';
            setRouteStatus(message);
        } finally {
            if (requestController === controller) requestController = null;
        }
    };

    const syncLocationSharing = (enabled, accuracyUsable, sharingState = '') => {
        if (!enabled) {
            locationUsable = false;
            stopRouteUpdates('Road route hidden because location sharing is off. Turn sharing on to show it again.');
            return;
        }

        if (!accuracyUsable) {
            locationUsable = false;
            requestController?.abort();
            requestController = null;
            removeRouteLayers();
            setRouteStatus(sharingState === 'pending'
                ? 'Waiting for a fresh, accurate GPS fix before drawing the road route…'
                : sharingState === 'error'
                    ? 'Rider location could not be refreshed. The route will return when GPS sharing recovers.'
                    : 'Rider GPS accuracy is too low for a road route. Share a location with accuracy of ±100 m or better.');
            return;
        }

        locationUsable = true;

        if (sharingActive) {
            return;
        }

        sharingActive = true;
        setRouteStatus('Calculating a road route from your latest shared location…');
        void refreshRoute();
        refreshTimer = window.setInterval(refreshRoute, ROUTE_REFRESH_INTERVAL_MS);
    };

    window.addEventListener('bearly:rider-location-sharing-updated', (event) => {
        syncLocationSharing(
            event.detail?.sharing === true,
            event.detail?.accuracyUsable === true,
            event.detail?.state || '',
        );
    });

    window.addEventListener('bearly:rider-location-shared', (event) => {
        if (!sharingActive || !locationUsable || !riderMarker) return;

        const coordinates = coordinatePair(event.detail?.latitude, event.detail?.longitude);
        if (coordinates) riderMarker.setLatLng(coordinates);
    });

    if (document.body.dataset.riderLocationSharing === 'true') {
        syncLocationSharing(document.body.dataset.riderLocationSharing === 'true',
            document.body.dataset.riderLocationUsable === 'true',
            document.body.dataset.riderLocationState || '');
    }
});
