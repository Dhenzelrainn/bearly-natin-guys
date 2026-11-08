/**
 * Buyer "Follow your delivery" map: one order-level map per tracking panel
 * showing the Buyer delivery pin, Seller pickup pins, Logistics sorting
 * center pins, guideline segments between them, and — while a shipment is
 * out for delivery — the approximate live Rider location.
 */
const POLL_INTERVAL_MS = 15_000;
const TERMINAL_SHIPMENT_STATUSES = new Set([
    'delivered',
    'cancelled',
    'return_to_sender',
]);

const SEGMENT_STYLES = {
    traveled: { color: '#6f3b20', weight: 4, opacity: 0.95 },
    active: { color: '#e9a72f', weight: 4, opacity: 0.95 },
    remaining: { color: '#b7a596', weight: 3, opacity: 0.9, dashArray: '6 8' },
};

let pollTimer = null;
let pollGeneration = 0;
let pollInFlight = false;
let leafletPromise = null;

const requestControllers = new Set();
let routeContext = null;

function trackingPanelIsVisible() {
    const panel = document.querySelector('#tracking-panel');

    return Boolean(
        panel
        && !panel.hidden
        && document.visibilityState !== 'hidden',
    );
}

function shipmentStatusLabel(status) {
    return ({
        pending: 'Order placed',
        ready_for_pickup: 'Ready for pickup',
        awaiting_buyer_dropoff: 'Awaiting Buyer drop-off',
        pickup_assigned: 'Pickup assigned',
        picked_up: 'Picked up by Bearly Logistics',
        at_sorting_center: 'At sorting center',
        handoff_pending: 'In transfer',
        sorted: 'Sorted for delivery',
        dispatched: 'Dispatched to Rider',
        out_for_delivery: 'Out for delivery',
        delivered: 'Delivered',
        delivery_failed: 'Delivery attempt unsuccessful',
        return_to_sender: 'Returning to Seller',
        cancelled: 'Cancelled',
    })[status] || 'Shipment update';
}

function setShipmentStatus(card, status) {
    card.dataset.shipmentStatus = status;
    const label = card.querySelector('[data-shipment-status-label]');
    if (label) label.textContent = shipmentStatusLabel(status);
}

function unavailableMessage(status) {
    if (status === 'delivered') {
        return 'Delivery is complete. The live Rider location is no longer shown.';
    }

    if (status === 'cancelled' || status === 'return_to_sender') {
        return 'Live Rider location is unavailable for this shipment.';
    }

    if (status !== 'out_for_delivery') {
        return 'Approximate Rider location appears only while this shipment is out for delivery.';
    }

    return 'Rider location is unavailable. A fresh, accurate update will appear here when available.';
}

function routeStatusElement() {
    return document.querySelector('[data-route-map-section] [data-route-map-status]');
}

function setRouteStatus(message) {
    const status = routeStatusElement();
    if (status) status.textContent = message;
}

function hideRouteSection() {
    const section = document.querySelector('[data-route-map-section]');
    if (section) section.hidden = true;
}

function clearRiderMarkers(message) {
    if (!routeContext) return;

    if (routeContext.riderExpiryTimer !== null) {
        window.clearTimeout(routeContext.riderExpiryTimer);
        routeContext.riderExpiryTimer = null;
    }

    routeContext.riderLayer.clearLayers();
    if (message) setRouteStatus(message);
}

function stopBuyerShipmentTracking() {
    pollGeneration += 1;
    if (pollTimer !== null) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }

    requestControllers.forEach((controller) => controller.abort());
    requestControllers.clear();

    if (routeContext) {
        if (routeContext.riderExpiryTimer !== null) window.clearTimeout(routeContext.riderExpiryTimer);
        routeContext.map.remove();
        routeContext = null;
    }

    pollInFlight = false;
}

async function loadLeaflet() {
    if (!leafletPromise) {
        leafletPromise = Promise.all([
            import('leaflet'),
            import('leaflet/dist/leaflet.css'),
            import('./leaflet-default-icon.js'),
        ]).then(([leaflet]) => leaflet.default || leaflet).catch((error) => {
            leafletPromise = null;
            throw error;
        });
    }

    return leafletPromise;
}

function formattedUpdateTime(locations) {
    const latest = locations
        .map((location) => Date.parse(location?.updated_at || ''))
        .filter(Number.isFinite)
        .reduce((max, value) => Math.max(max, value), 0);

    if (!latest) return '';

    return new Date(latest).toLocaleTimeString('en-PH', {
        hour: 'numeric',
        minute: '2-digit',
    });
}

function validPin(pin) {
    const latitude = Number(pin?.latitude);
    const longitude = Number(pin?.longitude);

    return Number.isFinite(latitude) && Number.isFinite(longitude)
        && latitude >= -90 && latitude <= 90
        && longitude >= -180 && longitude <= 180
        ? { latitude, longitude }
        : null;
}

function validRiderLocations(locations) {
    return locations
        .map((location) => ({
            latitude: Number(location?.latitude),
            longitude: Number(location?.longitude),
            freshForSeconds: Number(location?.fresh_for_seconds),
        }))
        .filter((location) => Number.isFinite(location.latitude)
            && Number.isFinite(location.longitude)
            && Number.isFinite(location.freshForSeconds)
            && location.freshForSeconds > 0
            && location.latitude >= -90
            && location.latitude <= 90
            && location.longitude >= -180
            && location.longitude <= 180);
}

/**
 * Segment status for the guideline chain. A two-point chain (Seller → Buyer)
 * collapses to one shared segment state.
 */
function segmentStates(deliveryState, segmentCount) {
    if (segmentCount <= 1) {
        if (deliveryState === 'delivered') return ['traveled'];
        if (deliveryState === 'in_transit' || deliveryState === 'out_for_delivery') return ['active'];
        return ['remaining'];
    }

    if (deliveryState === 'delivered') return ['traveled', 'traveled'];
    if (deliveryState === 'out_for_delivery') return ['traveled', 'active'];
    if (deliveryState === 'in_transit') return ['active', 'remaining'];

    return ['remaining', 'remaining'];
}

function addGuidelineSegment(leaflet, layer, latLngs, style) {
    if (!latLngs || latLngs.length < 2) return;
    layer.addLayer(leaflet.polyline(latLngs, style));
}

function drawStaticPins(leaflet, pins) {
    const pinDefinitions = [
        ...pins.sellers.map((pin) => ({ pin, kind: 'seller', icon: 'storefront' })),
        ...pins.hubs.map((pin) => ({ pin, kind: 'hub', icon: 'warehouse' })),
        ...((pins.buyer && [{ pin: pins.buyer, kind: 'buyer', icon: 'home' }]) || []),
    ];

    pinDefinitions.forEach(({ pin, kind, icon }) => {
        leaflet.marker([pin.latitude, pin.longitude], {
            icon: leaflet.divIcon({
                className: `tracking-pin tracking-pin-${kind}`,
                html: `<span class="material-symbols-outlined" aria-hidden="true">${icon}</span>`,
                iconSize: [28, 28],
                iconAnchor: [14, 28],
            }),
            keyboard: false,
            alt: `${kind} location`,
        }).bindTooltip(pin.label || ({ seller: 'Seller pickup', hub: 'Sorting center', buyer: 'Delivery address' })[kind], {
            direction: 'top',
            offset: [0, -26],
        }).addTo(routeContext.pinsLayer);
    });
}

async function renderRouteMap(order, generation) {
    if (!order || !order.id) {
        hideRouteSection();
        return;
    }

    const mapElement = document.querySelector('[data-route-map]');
    if (!mapElement) {
        hideRouteSection();
        return;
    }

    const tileUrl = mapElement.dataset.mapTileUrl?.trim();
    if (!tileUrl) {
        hideRouteSection();
        return;
    }

    let leaflet;
    try {
        leaflet = await loadLeaflet();
    } catch {
        hideRouteSection();
        return;
    }

    if (generation !== pollGeneration || !trackingPanelIsVisible()) return;

    let payload = null;
    const controller = new AbortController();
    requestControllers.add(controller);
    try {
        const response = await fetch(
            `/profile/orders/${encodeURIComponent(String(order.id))}/route-map`,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                cache: 'no-store',
                credentials: 'same-origin',
                signal: controller.signal,
            },
        );
        const result = await response.json().catch(() => ({}));
        if (generation !== pollGeneration) return;

        if ([401, 403, 404].includes(response.status) || !result?.data) {
            hideRouteSection();
            return;
        }

        payload = result.data;
    } catch {
        setRouteStatus('The delivery route could not be loaded. Please try again shortly.');
        return;
    } finally {
        requestControllers.delete(controller);
    }

    const pins = (payload?.pins && typeof payload.pins === 'object') ? payload.pins : {};
    const sellerPins = (Array.isArray(pins.sellers) ? pins.sellers : [])
        .map(validPin)
        .filter(Boolean)
        .slice(0, 3);
    const hubPins = (Array.isArray(pins.hubs) ? pins.hubs : [])
        .map(validPin)
        .filter(Boolean)
        .slice(0, 3);
    const buyerPin = validPin(pins.buyer);

    if (!sellerPins.length && !hubPins.length && !buyerPin) {
        hideRouteSection();
        return;
    }

    const labels = (payload?.pins && {
        sellers: Array.isArray(payload.pins.sellers) ? payload.pins.sellers : [],
        hubs: Array.isArray(payload.pins.hubs) ? payload.pins.hubs : [],
    }) || { sellers: [], hubs: [] };
    const labeledSellers = sellerPins.map((pin, index) => ({ ...pin, label: labels.sellers[index]?.label }));
    const labeledHubs = hubPins.map((pin, index) => ({ ...pin, label: labels.hubs[index]?.label }));

    mapElement.hidden = false;

    if (routeContext) routeContext.map.remove();
    const map = leaflet.map(mapElement, {
        scrollWheelZoom: false,
        doubleClickZoom: false,
    });
    leaflet.tileLayer(tileUrl, {
        attribution: mapElement.dataset.mapAttribution || '&copy; OpenStreetMap contributors',
        maxZoom: 19,
    }).addTo(map);

    routeContext = {
        map,
        leaflet,
        routeLayer: leaflet.layerGroup().addTo(map),
        pinsLayer: leaflet.layerGroup().addTo(map),
        riderLayer: leaflet.layerGroup().addTo(map),
        riderExpiryTimer: null,
        riderFitted: false,
        points: [],
    };

    drawStaticPins(leaflet, {
        sellers: labeledSellers,
        hubs: labeledHubs,
        buyer: labeled(buyerPin, 'Delivery address'),
    });

    const chain = [
        labeledSellers[0] || null,
        labeledHubs[0] || null,
        labeled(buyerPin, 'Delivery address'),
    ].filter(Boolean);

    const legs = Array.isArray(payload?.legs) ? payload.legs : [];
    const useRoadLegs = chain.length > 1 && legs.length === chain.length - 1
        && legs.every((leg) => Array.isArray(leg?.lines));

    const states = segmentStates(order.delivery_status, Math.max(1, chain.length - 1));

    for (let index = 0; index + 1 < chain.length; index++) {
        const style = SEGMENT_STYLES[states[index]] || SEGMENT_STYLES.remaining;

        if (useRoadLegs) {
            legs[index].lines
                .map((line) => Array.isArray(line)
                    ? line.map((point) => [Number(point[1]), Number(point[0])])
                    : [])
                .filter((latLngs) => latLngs.length > 1)
                .forEach((latLngs) => addGuidelineSegment(leaflet, routeContext.routeLayer, latLngs, style));
        } else {
            addGuidelineSegment(
                leaflet,
                routeContext.routeLayer,
                [[chain[index].latitude, chain[index].longitude], [chain[index + 1].latitude, chain[index + 1].longitude]],
                style,
            );
        }
    }

    const points = [
        ...labeledSellers,
        ...labeledHubs,
        ...(buyerPin ? [labeled(buyerPin, 'Delivery address')] : []),
    ].map((pin) => [pin.latitude, pin.longitude]);
    routeContext.points = points;

    if (points.length === 1) {
        map.setView(points[0], 14);
    } else {
        map.fitBounds(leaflet.latLngBounds(points), {
            padding: [28, 28],
            maxZoom: 14,
        });
    }

    window.requestAnimationFrame(() => {
        if (generation === pollGeneration && routeContext?.map === map) {
            map.invalidateSize();
        }
    });
}

function labeled(pin, label) {
    return pin ? { ...pin, label } : null;
}

async function updateRiderLocations(locations, generation) {
    const validLocations = validRiderLocations(locations);

    if (!routeContext || !validLocations.length) {
        clearRiderMarkers(unavailableMessage('out_for_delivery'));
        return;
    }

    if (generation !== pollGeneration || !trackingPanelIsVisible()) return;

    routeContext.riderLayer.clearLayers();
    if (routeContext.riderExpiryTimer !== null) window.clearTimeout(routeContext.riderExpiryTimer);

    const leaflet = routeContext.leaflet;
    const riderPoints = validLocations.map((location) => {
        const coordinates = [location.latitude, location.longitude];
        leaflet.circle(coordinates, {
            radius: 100,
            color: '#6f3b20',
            weight: 2,
            fillColor: '#e9a72f',
            fillOpacity: 0.12,
        }).addTo(routeContext.riderLayer);
        leaflet.marker(coordinates, {
            icon: leaflet.divIcon({
                className: 'tracking-pin tracking-pin-rider',
                html: '<span class="material-symbols-outlined" aria-hidden="true">local_shipping</span>',
                iconSize: [28, 28],
                iconAnchor: [14, 28],
            }),
            keyboard: false,
            alt: 'Approximate Rider location',
        }).addTo(routeContext.riderLayer);

        return coordinates;
    });

    if (!routeContext.riderFitted) {
        routeContext.riderFitted = true;
        routeContext.map.fitBounds(leaflet.latLngBounds([...routeContext.points, ...riderPoints]), {
            padding: [28, 28],
            maxZoom: 15,
        });
    }

    const earliestExpirySeconds = Math.min(...validLocations.map((location) => location.freshForSeconds));
    routeContext.riderExpiryTimer = window.setTimeout(() => {
        if (generation === pollGeneration) {
            clearRiderMarkers(unavailableMessage('out_for_delivery'));
        }
    }, earliestExpirySeconds * 1_000);

    const updatedAt = formattedUpdateTime(locations);
    setRouteStatus(
        `Showing an approximate Rider location${updatedAt ? ` · updated ${updatedAt}` : ''}.`,
    );
}

async function pollShipment(card, generation, results) {
    const endpoint = card.dataset.liveLocationUrl?.trim();
    if (!endpoint || card.dataset.liveTrackingTerminal === 'true') return;

    const controller = new AbortController();
    requestControllers.add(controller);

    try {
        const response = await fetch(endpoint, {
            method: 'GET',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            cache: 'no-store',
            credentials: 'same-origin',
            signal: controller.signal,
        });
        const result = await response.json().catch(() => ({}));
        if (generation !== pollGeneration) return;

        if (!response.ok || !result?.data) {
            if ([401, 403, 404].includes(response.status)) {
                card.dataset.liveTrackingTerminal = 'true';
            }
            results.push({ status: card.dataset.shipmentStatus || '', locations: [] });
            return;
        }

        const data = result.data;
        const status = String(data.shipment_status || '');
        setShipmentStatus(card, status);

        if (TERMINAL_SHIPMENT_STATUSES.has(status)) {
            card.dataset.liveTrackingTerminal = 'true';
        }

        results.push({
            status,
            locations: status === 'out_for_delivery' && data.available === true && Array.isArray(data.locations)
                ? data.locations
                : [],
        });
    } catch (error) {
        if (error?.name !== 'AbortError' && generation === pollGeneration) {
            results.push({ status: card.dataset.shipmentStatus || 'out_for_delivery', locations: [] });
        }
    } finally {
        requestControllers.delete(controller);
    }
}

function aggregatedRiderMessage(results) {
    if (!results.length) return null;

    if (results.every((result) => TERMINAL_SHIPMENT_STATUSES.has(result.status))) {
        return unavailableMessage('delivered');
    }

    if (results.some((result) => result.status === 'out_for_delivery')) {
        return unavailableMessage('out_for_delivery');
    }

    return unavailableMessage('pending');
}

export async function startBuyerShipmentTracking(order) {
    stopBuyerShipmentTracking();

    const generation = pollGeneration;
    const cards = [...document.querySelectorAll('[data-live-tracking-shipment]')]
        .filter((card) => card.dataset.liveTrackingTerminal !== 'true');

    await renderRouteMap(order, generation);

    if (!cards.length || !trackingPanelIsVisible()) return;

    const poll = async () => {
        if (generation !== pollGeneration) return;
        if (!trackingPanelIsVisible()) {
            stopBuyerShipmentTracking();
            return;
        }
        if (pollInFlight) return;

        pollInFlight = true;
        try {
            const results = [];
            await Promise.all(cards.map((card) => pollShipment(card, generation, results)));

            const locations = results.flatMap((result) => result.locations);
            if (locations.length) {
                await updateRiderLocations(locations, generation);
            } else {
                clearRiderMarkers(aggregatedRiderMessage(results));
            }

            if (cards.every((card) => card.dataset.liveTrackingTerminal === 'true')) {
                window.clearInterval(pollTimer);
                pollTimer = null;
            }
        } finally {
            if (generation === pollGeneration) pollInFlight = false;
        }
    };

    void poll();
    pollTimer = window.setInterval(() => void poll(), POLL_INTERVAL_MS);
}

export { stopBuyerShipmentTracking };
