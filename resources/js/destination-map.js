import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import './leaflet-default-icon.js';

const DEFAULT_CENTER = [12.8797, 121.7740];
const SELLER_PICKUP_PIN_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m2 7 1-4h18l1 4M4 7v14h16V7M4 7a2 2 0 0 0 4 0 2 2 0 0 0 4 0 2 2 0 0 0 4 0 2 2 0 0 0 4 0M10 21v-6h4v6"/></svg>';
const BUYER_PICKUP_PIN_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 9-7 9 7M5 9v12h14V9M9 21v-8h6v8"/></svg>';
const RIDER_PIN_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><circle cx="15" cy="5" r="1"/><path d="m12 17.5 3-6-4-3-3 8m-2.5 1h5l3.5-6h-5"/></svg>';

function pickupMapPinIcon(kind, svg) {
    return L.divIcon({
        className: `rider-pickup-map-marker rider-pickup-map-marker--${kind}`,
        html: `<span>${svg}</span>`,
        iconSize: [42, 48],
        iconAnchor: [21, 44],
        tooltipAnchor: [0, -40],
    });
}

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

    const map = L.map(element, { scrollWheelZoom: false, dragging: true }).setView(
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
    let pickupCoordinates = coordinates;
    const pickupLabel = element.dataset.pickupMarkerLabel?.trim() || 'Seller pickup location';
    const pickupKind = element.dataset.pickupMarkerKind === 'buyer' ? 'buyer' : 'seller';
    const pickupSvg = pickupKind === 'buyer' ? BUYER_PICKUP_PIN_SVG : SELLER_PICKUP_PIN_SVG;
    const showLocation = (latlng, zoom = 16) => {
        pickupCoordinates = latlng;
        map.setView(latlng, zoom);
        if (!marker) {
            marker = L.marker(latlng, {
                icon: pickupMapPinIcon(pickupKind, pickupSvg),
                title: pickupLabel,
                alt: pickupLabel,
                keyboard: false,
            })
                .bindTooltip(pickupLabel, { direction: 'top' })
                .addTo(map);
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
    const routeStageEnabled = element.dataset.pickupRouteEnabled === 'true';
    const routeAvailable = routeStageEnabled && Boolean(routeEndpoint);
    const pickupStatus = element.dataset.pickupStatus || '';
    const routeStatusTarget = () => targetElement(element, 'pickupRouteStatusTarget');
    const setRouteStatus = (message) => {
        const status = routeStatusTarget();
        if (status && status.textContent !== message) status.textContent = message;
    };

    const ROUTE_REFRESH_INTERVAL_MS = 15_000;
    let sharingActive = false;
    let locationUsable = false;
    let routeLayer = null;
    let riderMarker = null;
    const guideLayer = L.polyline([], {
        color: '#287090',
        weight: 3,
        opacity: 0.9,
        dashArray: '6 7',
        lineCap: 'round',
        interactive: false,
    }).addTo(map);
    let refreshTimer = null;
    let expiryTimer = null;
    let requestController = null;
    let hasFittedRoute = false;
    let hasFittedRiderPosition = false;

    const removeRoadRoute = () => {
        if (routeLayer) map.removeLayer(routeLayer);
        routeLayer = null;
    };

    const removeRouteLayers = () => {
        if (expiryTimer !== null) window.clearTimeout(expiryTimer);
        expiryTimer = null;

        removeRoadRoute();
        if (riderMarker) map.removeLayer(riderMarker);
        riderMarker = null;
        guideLayer.setLatLngs([]);
        hasFittedRoute = false;
        hasFittedRiderPosition = false;
    };

    const setRiderPosition = (coordinates, fitMap = true) => {
        if (!riderMarker) {
            riderMarker = L.marker(coordinates, {
                icon: pickupMapPinIcon('rider', RIDER_PIN_SVG),
                title: 'Your live Rider location',
                alt: 'Your live Rider location',
                keyboard: false,
            })
                .bindTooltip('Your live Rider location', { direction: 'top' })
                .addTo(map);
        } else {
            riderMarker.setLatLng(coordinates);
        }

        if (pickupCoordinates) guideLayer.setLatLngs([coordinates, pickupCoordinates]);

        if (fitMap && !hasFittedRiderPosition) {
            const points = pickupCoordinates ? [pickupCoordinates, coordinates] : [coordinates];
            map.fitBounds(L.latLngBounds(points), { padding: [28, 28], maxZoom: 15 });
            hasFittedRiderPosition = true;
        }
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
        if (!routeAvailable || !sharingActive || !locationUsable || requestController) return;

        const controller = new AbortController();
        requestController = controller;

        try {
            const response = await fetch(routeEndpoint, {
                credentials: 'same-origin',
                cache: 'no-store',
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
            const routePickupCoordinates = coordinatePair(data.pickup?.latitude, data.pickup?.longitude);
            const locationAgeSeconds = Number(data.rider?.location_age_seconds);
            const staleAfterSeconds = Number(data.stale_after_seconds);
            const millisecondsUntilStale = (staleAfterSeconds - locationAgeSeconds) * 1000;

            if (!riderCoordinates
                || !routePickupCoordinates
                || !Number.isFinite(locationAgeSeconds)
                || locationAgeSeconds < 0
                || !Number.isFinite(staleAfterSeconds)
                || staleAfterSeconds <= 0
                || locationAgeSeconds >= staleAfterSeconds) {
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

            pickupCoordinates = routePickupCoordinates;
            marker?.setLatLng(pickupCoordinates);
            updateOsmLink(element, pickupCoordinates[0], pickupCoordinates[1]);
            removeRoadRoute();
            if (expiryTimer !== null) window.clearTimeout(expiryTimer);
            expiryTimer = null;
            routeLayer = nextRouteLayer.addTo(map);
            setRiderPosition(riderCoordinates, false);
            routeBounds.extend(riderCoordinates);

            if (!hasFittedRoute) {
                map.fitBounds(routeBounds, {
                    padding: [24, 24],
                    maxZoom: 16,
                });
                hasFittedRoute = true;
                hasFittedRiderPosition = true;
            }

            expiryTimer = window.setTimeout(() => {
                removeRouteLayers();
                setRouteStatus('Rider GPS has gone stale. Keep location sharing on to refresh the road route.');
            }, millisecondsUntilStale);

            setRouteStatus('The Rider pin follows new GPS fixes. The dashed line is direct guidance; the solid line follows the road route. No ETA is provided.');
        } catch (error) {
            if (error?.name === 'AbortError') return;

            removeRoadRoute();
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

        sharingActive = true;

        if (!accuracyUsable) {
            locationUsable = false;
            if (refreshTimer !== null) window.clearInterval(refreshTimer);
            refreshTimer = null;
            requestController?.abort();
            requestController = null;
            removeRoadRoute();

            if (sharingState !== 'sharing') removeRouteLayers();

            if (sharingState === 'sharing') {
                const savedPinMessage = routeStageEnabled && !routeEndpoint
                    ? 'A saved pickup pin is also required for a road route.'
                    : '';
                const routeAccuracyMessage = routeStageEnabled
                    ? 'A solid road route needs fresh GPS accuracy of ±100 m or better.'
                    : 'Accept this pickup and share accurate GPS for a road route.';
                setRouteStatus(`Your approximate Rider pin and dashed direct guide are shown. ${savedPinMessage} ${routeAccuracyMessage}`.replace(/\s+/g, ' ').trim());
            } else if (sharingState === 'pending') {
                setRouteStatus('Waiting for a fresh GPS fix before showing your Rider pin and route guidance…');
            } else if (sharingState === 'error') {
                setRouteStatus('Rider location could not be refreshed. The pin and route guidance will return when GPS sharing recovers.');
            } else {
                setRouteStatus('Rider GPS accuracy is too low for a road route. Share a location with accuracy of ±100 m or better.');
            }
            return;
        }

        locationUsable = true;

        if (!routeAvailable) {
            if (routeStageEnabled) {
                setRouteStatus('A saved pickup map pin is required for a road route. Your shared Rider pin and direct guide remain available.');
            } else if (pickupStatus === 'assigned') {
                setRouteStatus('Your shared Rider pin and direct guide are shown here. Accept this pickup and share accurate GPS for a road route.');
            } else {
                setRouteStatus('Your shared Rider pin and direct guide are shown here. Road routing ends when pickup is confirmed.');
            }
            return;
        }

        if (refreshTimer !== null) return;

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
        if (!sharingActive) return;

        const coordinates = coordinatePair(event.detail?.latitude, event.detail?.longitude);
        if (!coordinates) return;

        setRiderPosition(coordinates);
        if (expiryTimer !== null) window.clearTimeout(expiryTimer);
        expiryTimer = window.setTimeout(() => {
            removeRouteLayers();
            setRouteStatus('Rider GPS has gone stale. Keep location sharing on to refresh the road route.');
        }, 180_000);
    });

    if (document.body.dataset.riderLocationSharing === 'true') {
        syncLocationSharing(document.body.dataset.riderLocationSharing === 'true',
            document.body.dataset.riderLocationUsable === 'true',
            document.body.dataset.riderLocationState || '');
    }
});
