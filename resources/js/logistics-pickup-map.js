import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import './leaflet-default-icon.js';

const DEFAULT_CENTER = [12.8797, 121.7740];
const REFRESH_INTERVAL_MS = 10_000;
const PIN_COLLISION_RADIUS_PX = 38;
const SELLER_PIN_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m2 7 1-4h18l1 4M4 7v14h16V7M4 7a2 2 0 0 0 4 0 2 2 0 0 0 4 0 2 2 0 0 0 4 0 2 2 0 0 0 4 0M10 21v-6h4v6"/></svg>';
const RETURN_PICKUP_PIN_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 8-9-5-9 5 9 5 9-5ZM3 8v8l9 5 9-5V8M12 13v8"/></svg>';
const FACILITY_PIN_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-7h6v7M9 9h.01M15 9h.01"/></svg>';
const LOGISTICS_LIVE_PIN_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="2"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2"/></svg>';
const RIDER_PIN_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><circle cx="15" cy="5" r="1"/><path d="m12 17.5 3-6-4-3-3 8m-2.5 1h5l3.5-6h-5"/></svg>';

function pickupMapPinIcon(kind, svg, highlighted = false) {
    return L.divIcon({
        className: 'pickup-map-leaflet-marker',
        html: `<span class="pickup-map-pin pickup-map-pin--${kind}${highlighted ? ' pickup-map-pin--highlighted' : ''}" aria-hidden="true">${svg}</span>`,
        iconSize: [38, 38],
        iconAnchor: [19, 27],
        popupAnchor: [0, -25],
    });
}

function coordinatePair(latitude, longitude) {
    const parse = (value, minimum, maximum) => {
        if (value === null || value === undefined || String(value).trim() === '') return null;

        const number = Number(value);
        return Number.isFinite(number) && number >= minimum && number <= maximum
            ? number
            : null;
    };

    const lat = parse(latitude, -90, 90);
    const lng = parse(longitude, -180, 180);

    return lat === null || lng === null ? null : [lat, lng];
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#39;',
        '"': '&quot;',
    })[character]);
}

function formatTimestamp(value) {
    if (!value) return 'time unavailable';

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'time unavailable';

    return new Intl.DateTimeFormat(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    }).format(date);
}

function pluralize(count, singular, plural = `${singular}s`) {
    return `${count} ${count === 1 ? singular : plural}`;
}

export function setupLogisticsPickupMaps() {
    document.querySelectorAll('[data-pickup-live-map-panel]').forEach((panel) => {
        const mapElement = panel.querySelector('[data-pickup-live-map]');
        const statusElement = panel.querySelector('[data-pickup-map-status]');
        const syncElement = panel.querySelector('[data-pickup-map-sync]');
        const riderList = panel.querySelector('[data-pickup-map-riders]');
        const modal = panel.closest('[data-modal]');
        const endpoint = panel.dataset.pickupMapEndpoint || '';
        const tileUrl = panel.dataset.pickupMapTileUrl || '';
        const tileAttribution = panel.dataset.pickupMapAttribution || '&copy; OpenStreetMap contributors';
        const pickupLabel = panel.dataset.pickupMapPickupLabel || 'Seller pickup';
        const isReturnPickup = panel.dataset.pickupMapIsReturn === 'true';
        const selectTarget = panel.dataset.pickupMapSelectTarget || '';

        if (!mapElement || !riderList) return;

        let map = null;
        let markerLayer = null;
        let connectorLayer = null;
        let hasCenteredInitialPoint = false;
        let hasFittedRiderLocations = false;
        let refreshTimer = null;
        let requestInFlight = false;
        let refreshQueued = false;
        let riders = [];
        let mapPinEntries = [];
        let pinDetailPopup = null;
        let nearbyChooserPopup = null;
        let pickupCoordinates = null;
        let pickupData = {};
        let facilityCoordinates = null;
        let liveLogisticsLocation = null;
        let statusData = {};
        let freshnessTimer = null;

        const assignmentSelect = () => selectTarget
            ? document.querySelector(selectTarget)
            : null;

        const setStatus = (message, state = '') => {
            if (!statusElement) return;
            statusElement.textContent = message;
            if (state) statusElement.dataset.state = state;
            else delete statusElement.dataset.state;
        };

        const setSync = (message, state = '') => {
            if (!syncElement) return;

            syncElement.textContent = message;
            syncElement.classList.toggle('is-live', state === 'live');
            syncElement.classList.toggle('is-error', state === 'error');
        };

        const selectedRiderId = () => assignmentSelect()?.value || '';

        const syncSelectedRider = () => {
            riderList.querySelectorAll('[data-pickup-map-rider-item]').forEach((item) => {
                const isSelected = item.dataset.pickupMapRiderItem === selectedRiderId();
                item.classList.toggle('is-selected', isSelected);

                const button = item.querySelector('[data-pickup-map-select-rider]');
                if (button) button.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
            });
        };

        const selectedRiderForConnector = () => {
            const selectedId = selectedRiderId();

            if (selectedId) {
                return riders.find((rider) => String(rider.id) === String(selectedId)) || null;
            }

            return riders.find((rider) => rider.is_recommended) || null;
        };

        const renderDistanceLine = () => {
            if (!connectorLayer) return;

            connectorLayer.clearLayers();
            const rider = selectedRiderForConnector();
            const riderCoordinates = rider?.location_usable === true && rider?.gps_quality === 'usable'
                ? coordinatePair(rider.latitude, rider.longitude)
                : null;
            const guideOptions = {
                color: '#c76500',
                weight: 3,
                opacity: 0.88,
                dashArray: '7 7',
                lineCap: 'round',
                interactive: false,
            };

            if (riderCoordinates) {
                if (pickupCoordinates) {
                    L.polyline([pickupCoordinates, riderCoordinates], guideOptions).addTo(connectorLayer);
                }
                if (facilityCoordinates) {
                    L.polyline([riderCoordinates, facilityCoordinates], guideOptions).addTo(connectorLayer);
                }
            } else if (pickupCoordinates && facilityCoordinates) {
                L.polyline([pickupCoordinates, facilityCoordinates], guideOptions).addTo(connectorLayer);
            }
        };

        const syncMapSelection = () => {
            syncSelectedRider();
            renderMarkers(pickupData);
            renderDistanceLine();
        };

        const selectRider = (riderId) => {
            const select = assignmentSelect();
            if (select) {
                select.value = String(riderId);
                select.dispatchEvent(new Event('change', { bubbles: true }));
            } else {
                syncMapSelection();
            }
        };

        const showNearbyPinChooser = (entries, anchor) => {
            if (pinDetailPopup) {
                map.closePopup(pinDetailPopup);
                pinDetailPopup = null;
            }
            if (nearbyChooserPopup) map.closePopup(nearbyChooserPopup);

            const content = document.createElement('div');
            content.className = 'pickup-map-nearby-chooser';

            const heading = document.createElement('strong');
            heading.className = 'pickup-map-nearby-heading';
            heading.textContent = 'Nearby locations';
            content.append(heading);

            const options = document.createElement('div');
            options.className = 'pickup-map-nearby-options';
            options.setAttribute('role', 'group');
            options.setAttribute('aria-label', 'Choose a map location');

            entries.forEach((entry) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = `pickup-map-nearby-choice pickup-map-nearby-choice--${entry.kind}`;
                button.textContent = entry.label;
                button.addEventListener('click', () => openMapPin(entry.key));
                options.append(button);
            });

            content.append(options);
            L.DomEvent.disableClickPropagation(content);
            L.DomEvent.disableScrollPropagation(content);

            const popup = L.popup({
                className: 'pickup-map-nearby-popup',
                minWidth: 190,
                maxWidth: 260,
                autoPan: true,
            })
                .setLatLng(anchor)
                .setContent(content);

            nearbyChooserPopup = popup;
            map.once('popupopen', (event) => {
                if (event.popup === popup) content.querySelector('button')?.focus();
            });
            popup.openOn(map);
        };

        const openMapPin = (key) => {
            let entry = mapPinEntries.find((candidate) => candidate.key === key);
            if (!map) return;

            if (nearbyChooserPopup) {
                map.closePopup(nearbyChooserPopup);
                nearbyChooserPopup = null;
            }
            if (pinDetailPopup) {
                map.closePopup(pinDetailPopup);
                pinDetailPopup = null;
            }

            if (!entry) return;

            entry.onSelect?.();

            // Selecting a Rider can synchronously rerender the marker layer.
            entry = mapPinEntries.find((candidate) => candidate.key === key) || entry;
            pinDetailPopup = L.popup({
                className: 'pickup-map-pin-popup',
                maxWidth: 280,
                autoPan: true,
            })
                .setLatLng(entry.coordinates)
                .setContent(entry.popupContent)
                .openOn(map);
        };

        const handleMapPinClick = (key) => {
            const entry = mapPinEntries.find((candidate) => candidate.key === key);
            if (!entry || !map) return;

            const clickedPoint = map.latLngToContainerPoint(entry.coordinates);
            const nearbyEntries = mapPinEntries.filter((candidate) => map
                .latLngToContainerPoint(candidate.coordinates)
                .distanceTo(clickedPoint) <= PIN_COLLISION_RADIUS_PX);

            if (nearbyEntries.length > 1) {
                showNearbyPinChooser(nearbyEntries, entry.coordinates);
                return;
            }

            openMapPin(key);
        };

        const registerMapPin = ({ key, kind, label, coordinates, popupContent, icon, onSelect = null }) => {
            const marker = L.marker(coordinates, {
                icon,
                title: label,
            }).addTo(markerLayer);

            mapPinEntries.push({
                key,
                kind,
                label,
                coordinates: L.latLng(coordinates),
                popupContent,
                onSelect,
            });
            marker.on('click', () => handleMapPinClick(key));

            return marker;
        };

        const ensureMap = () => {
            if (map || !tileUrl) return map;

            map = L.map(mapElement, {
                scrollWheelZoom: false,
            }).setView(DEFAULT_CENTER, 6);
            markerLayer = L.layerGroup().addTo(map);
            connectorLayer = L.layerGroup().addTo(map);

            const tileLayer = L.tileLayer(tileUrl, {
                attribution: tileAttribution,
                maxZoom: 19,
            }).addTo(map);

            tileLayer.on('tileerror', () => {
                setSync('Map tiles unavailable', 'error');
                setStatus('Map tiles could not load. The Rider list can still be used for manual assignment.');
            });

            window.setTimeout(() => map?.invalidateSize(), 0);

            return map;
        };

        const renderRiderList = () => {
            if (!riders.length) {
                riderList.innerHTML = '<p class="pickup-map-empty">No schedule-eligible, available Local Riders are available for this pickup window.</p>';
                return;
            }

            riderList.innerHTML = riders.map((rider) => {
                const hasMapPosition = rider.location_usable === true
                    && rider.gps_quality === 'usable'
                    && coordinatePair(rider.latitude, rider.longitude) !== null;
                const hasAccuracy = rider.accuracy_meters !== null
                    && rider.accuracy_meters !== undefined
                    && Number.isFinite(Number(rider.accuracy_meters));
                const accuracyLabel = hasAccuracy
                    ? `±${Math.round(Number(rider.accuracy_meters))} m`
                    : 'unknown';
                const areaLabel = rider.area_match ? 'Same service area' : 'Outside service area';
                let locationLabel;
                let updateLabel;

                if (rider.location_state === 'stale') {
                    locationLabel = `${areaLabel} · GPS stale; not plotted`;
                    updateLabel = `Last seen ${formatTimestamp(rider.last_seen_at)}`;
                } else if (rider.location_state === 'missing') {
                    locationLabel = `${areaLabel} · no GPS report; not plotted`;
                    updateLabel = 'No location report';
                } else if (rider.gps_quality === 'low_accuracy') {
                    locationLabel = `${areaLabel} · GPS too imprecise (${accuracyLabel}); no map pin or distance`;
                    updateLabel = `Updated ${formatTimestamp(rider.last_seen_at)}`;
                } else if (rider.gps_quality === 'unknown') {
                    locationLabel = `${areaLabel} · GPS accuracy unavailable; no map pin or distance`;
                    updateLabel = `Updated ${formatTimestamp(rider.last_seen_at)}`;
                } else if (rider.distance_label) {
                    locationLabel = `${areaLabel} · Approx. straight-line · ${rider.distance_label}${hasAccuracy ? ` · ${accuracyLabel} accuracy` : ''}`;
                    updateLabel = `Updated ${formatTimestamp(rider.last_seen_at)}`;
                } else {
                    locationLabel = `${areaLabel} · ${hasMapPosition ? 'Pickup distance unavailable' : 'GPS location unavailable'}`;
                    updateLabel = `Updated ${formatTimestamp(rider.last_seen_at)}`;
                }

                const recommendation = rider.is_recommended
                    ? rider.area_match
                        ? 'Service-area recommendation · '
                        : 'Nearby fallback · '
                    : '';
                const dotClass = hasMapPosition ? '' : ' is-unavailable';

                return `<div class="pickup-map-rider-item${rider.is_recommended ? ' is-recommended' : ''}" data-pickup-map-rider-item="${escapeHtml(rider.id)}">
                    <i class="pickup-map-rider-dot${dotClass}" aria-hidden="true"></i>
                    <button class="pickup-map-rider-button" type="button" data-pickup-map-select-rider="${escapeHtml(rider.id)}" aria-pressed="false">
                        <strong>${escapeHtml(rider.name)}</strong>
                        <small>${escapeHtml(recommendation + locationLabel)} · ${escapeHtml(updateLabel)}</small>
                    </button>
                </div>`;
            }).join('');

            syncSelectedRider();
        };

        const renderMarkers = (pickup) => {
            pickupCoordinates = coordinatePair(pickup?.latitude, pickup?.longitude);
            if (!map || !markerLayer) return;

            if (pinDetailPopup) {
                map.closePopup(pinDetailPopup);
                pinDetailPopup = null;
            }
            markerLayer.clearLayers();
            mapPinEntries = [];
            const points = [];

            if (pickupCoordinates) {
                points.push(pickupCoordinates);
                const pickupKind = isReturnPickup ? 'buyer' : 'seller';
                const pickupSvg = isReturnPickup ? RETURN_PICKUP_PIN_SVG : SELLER_PIN_SVG;
                registerMapPin({
                    key: 'pickup',
                    kind: pickupKind,
                    label: pickupLabel,
                    coordinates: pickupCoordinates,
                    popupContent: `<strong>${escapeHtml(pickupLabel)}</strong><br>Saved pickup coordinates`,
                    icon: pickupMapPinIcon(pickupKind, pickupSvg),
                });
            }

            if (facilityCoordinates) {
                points.push(facilityCoordinates);
                registerMapPin({
                    key: 'facility',
                    kind: 'facility',
                    label: 'Logistics facility',
                    coordinates: facilityCoordinates,
                    popupContent: '<strong>Logistics facility</strong><br>Saved Sorting Center coordinates',
                    icon: pickupMapPinIcon('facility', FACILITY_PIN_SVG),
                });
            }

            if (liveLogisticsLocation) {
                const liveCoordinates = coordinatePair(
                    liveLogisticsLocation.latitude,
                    liveLogisticsLocation.longitude,
                );

                if (liveCoordinates) {
                    points.push(liveCoordinates);
                    const rawAccuracy = liveLogisticsLocation.accuracy_meters;
                    const accuracy = rawAccuracy !== null && rawAccuracy !== undefined
                        && Number.isFinite(Number(rawAccuracy)) && Number(rawAccuracy) >= 0
                        ? Number(rawAccuracy)
                        : null;
                    const accuracyCopy = accuracy === null
                        ? 'GPS accuracy unavailable; treat as approximate'
                        : `Reported accuracy ±${Math.round(accuracy)} m`;
                    const marker = registerMapPin({
                        key: 'logistics-live',
                        kind: 'logistics-live',
                        label: 'Live Logistics device · approximate',
                        coordinates: liveCoordinates,
                        popupContent: `<strong>Live Logistics device · approximate</strong><br>${escapeHtml(accuracyCopy)}<br>Updated ${escapeHtml(formatTimestamp(liveLogisticsLocation.last_seen_at))}`,
                        icon: pickupMapPinIcon('logistics-live', LOGISTICS_LIVE_PIN_SVG),
                    });

                    if (accuracy !== null && accuracy > 0) {
                        L.circle(liveCoordinates, {
                            radius: accuracy,
                            color: '#17738a',
                            weight: 2,
                            dashArray: '5 5',
                            fillColor: '#55b4c8',
                            fillOpacity: 0.12,
                            interactive: false,
                        }).addTo(markerLayer);
                    }

                    marker.setZIndexOffset(250);
                }
            }

            riders.forEach((rider) => {
                if (rider.location_usable !== true || rider.gps_quality !== 'usable') return;
                const coordinates = coordinatePair(rider.latitude, rider.longitude);
                if (!coordinates) return;

                points.push(coordinates);
                const distance = rider.distance_label
                    ? `Approx. straight-line · ${rider.distance_label}`
                    : 'Straight-line distance unavailable';
                const hasAccuracy = rider.accuracy_meters !== null
                    && rider.accuracy_meters !== undefined
                    && Number.isFinite(Number(rider.accuracy_meters));
                const accuracy = hasAccuracy
                    ? `<br>GPS accuracy: ±${Math.round(Number(rider.accuracy_meters))} m`
                    : '';
                const area = rider.area_match ? 'Same service area' : 'Outside service area';
                const isSelected = String(rider.id) === String(selectedRiderId());
                const isHighlighted = isSelected || rider.is_recommended;
                registerMapPin({
                    key: `rider:${rider.id}`,
                    kind: 'rider',
                    label: `Rider · ${rider.name}`,
                    coordinates,
                    popupContent: `<strong>${escapeHtml(rider.name)}</strong><br>${escapeHtml(area)}<br>${escapeHtml(distance)}${accuracy}<br>Updated ${escapeHtml(formatTimestamp(rider.last_seen_at))}`,
                    icon: pickupMapPinIcon('rider', RIDER_PIN_SVG, isHighlighted),
                    onSelect: () => selectRider(rider.id),
                });
            });

            const hasUsableRiderLocation = riders.some((rider) => rider.location_usable === true
                && rider.gps_quality === 'usable'
                && coordinatePair(rider.latitude, rider.longitude) !== null);

            if (!hasFittedRiderLocations && hasUsableRiderLocation) {
                map.fitBounds(L.latLngBounds(points), {
                    padding: [28, 28],
                    maxZoom: 15,
                });
                hasFittedRiderLocations = true;
                hasCenteredInitialPoint = true;
            } else if (!hasCenteredInitialPoint && points.length > 1) {
                map.fitBounds(L.latLngBounds(points), {
                    padding: [28, 28],
                    maxZoom: 15,
                });
                hasCenteredInitialPoint = true;
            } else if (!hasCenteredInitialPoint && points.length === 1) {
                map.setView(points[0], 15);
                hasCenteredInitialPoint = true;
            }
        };

        const renderStatus = (data) => {
            const mapRiderCount = riders.filter((rider) => rider.location_usable === true
                && rider.gps_quality === 'usable'
                && coordinatePair(rider.latitude, rider.longitude)).length;
            const staleCount = riders.filter((rider) => rider.location_state === 'stale').length;
            const missingCount = riders.filter((rider) => rider.location_state === 'missing').length;
            const lowAccuracyCount = riders.filter((rider) => rider.location_state === 'fresh'
                && rider.gps_quality === 'low_accuracy').length;
            const unknownAccuracyCount = riders.filter((rider) => rider.location_state === 'fresh'
                && rider.gps_quality === 'unknown').length;
            const maxAccuracy = Number(data.max_usable_accuracy_meters);
            const accuracyLimit = Number.isFinite(maxAccuracy) && maxAccuracy >= 0
                ? Math.round(maxAccuracy)
                : 100;
            const pickupHasCoordinates = coordinatePair(data.pickup?.latitude, data.pickup?.longitude) !== null;
            const facilityHasCoordinates = coordinatePair(data.facility?.latitude, data.facility?.longitude) !== null;

            if (!riders.length) {
                const missingPins = [
                    !pickupHasCoordinates ? 'the pickup has no saved map pin' : '',
                    !facilityHasCoordinates ? 'the Logistics facility has no saved map pin' : '',
                ].filter(Boolean);
                setStatus(
                    `No schedule-eligible, available Local Riders are available for this pickup window.${missingPins.length ? ` ${missingPins.join('; ')}.` : ''}`,
                    'warning',
                );
            } else {
                const messages = [];
                if (mapRiderCount) messages.push(`${pluralize(mapRiderCount, 'accurate Rider position')} shown on the map`);
                if (staleCount) messages.push(`${pluralize(staleCount, 'Rider location')} stale and not plotted`);
                if (missingCount) messages.push(`${pluralize(missingCount, 'available Rider')} without a shared GPS location`);
                if (lowAccuracyCount) {
                    messages.push(`${pluralize(lowAccuracyCount, 'fresh GPS fix', 'fresh GPS fixes')} hidden because reported accuracy is worse than ±${accuracyLimit} m; no marker or distance is shown for those Riders`);
                }
                if (unknownAccuracyCount) {
                    messages.push(`${pluralize(unknownAccuracyCount, 'fresh GPS fix', 'fresh GPS fixes')} hidden because accuracy was not reported`);
                }
                if (!pickupHasCoordinates) messages.push('the pickup has no saved map pin');
                if (!facilityHasCoordinates) messages.push('the Logistics facility has no saved map pin');
                messages.push('All listed Riders remain selectable; Sorting Zone/workload fallback remains available');
                setStatus(
                    `${messages.join('. ')}.`,
                    staleCount || missingCount || lowAccuracyCount || unknownAccuracyCount || !mapRiderCount || !pickupHasCoordinates
                        ? 'warning'
                        : '',
                );
            }
        };

        const markLocationStale = (rider) => ({
            ...rider,
            latitude: null,
            longitude: null,
            location_state: 'stale',
            location_usable: false,
            distance_km: null,
            distance_label: null,
        });

        const clearFreshnessTimer = () => {
            if (freshnessTimer !== null) window.clearTimeout(freshnessTimer);
            freshnessTimer = null;
        };

        const expireStaleRiderLocations = () => {
            const now = Date.now();
            let changed = false;

            riders = riders.map((rider) => {
                if (!Number.isFinite(rider.local_fresh_until) || now <= rider.local_fresh_until) {
                    return rider;
                }

                changed = true;
                return markLocationStale(rider);
            });

            if (liveLogisticsLocation
                && Number.isFinite(liveLogisticsLocation.local_fresh_until)
                && now > liveLogisticsLocation.local_fresh_until) {
                liveLogisticsLocation = null;
                changed = true;
            }

            if (!changed) return;

            renderMarkers(pickupData);
            renderRiderList();
            renderDistanceLine();
            renderStatus(statusData);
        };

        const scheduleFreshnessExpiry = () => {
            clearFreshnessTimer();

            const deadlines = riders
                .map((rider) => rider.local_fresh_until)
                .filter(Number.isFinite);

            if (Number.isFinite(liveLogisticsLocation?.local_fresh_until)) {
                deadlines.push(liveLogisticsLocation.local_fresh_until);
            }

            if (!deadlines.length) return;

            const delay = Math.max(0, Math.min(...deadlines) - Date.now() + 1);
            freshnessTimer = window.setTimeout(() => {
                freshnessTimer = null;
                expireStaleRiderLocations();
                scheduleFreshnessExpiry();
            }, delay);
        };

        const render = (payload, requestStartedAt) => {
            const data = payload?.data || {};
            const receivedAt = Date.now();
            const requestDuration = Number.isFinite(requestStartedAt)
                ? Math.max(0, receivedAt - requestStartedAt)
                : 0;
            const serverGeneratedAt = Date.parse(data.generated_at);
            const freshnessSeconds = Number(data.stale_after_seconds);
            const freshnessWindowMs = (Number.isFinite(freshnessSeconds) && freshnessSeconds >= 0
                ? freshnessSeconds
                : 180) * 1000;
            const logisticsFreshnessSeconds = Number(data.logistics_location_stale_after_seconds);
            const logisticsFreshnessWindowMs = (Number.isFinite(logisticsFreshnessSeconds) && logisticsFreshnessSeconds >= 0
                ? logisticsFreshnessSeconds
                : 180) * 1000;

            statusData = data;
            pickupData = data.pickup || {};
            facilityCoordinates = coordinatePair(data.facility?.latitude, data.facility?.longitude);
            const sharedLogisticsLocation = data.logistics_location;
            const liveLatitude = Number(sharedLogisticsLocation?.latitude);
            const liveLongitude = Number(sharedLogisticsLocation?.longitude);
            const logisticsLastSeenAt = Date.parse(sharedLogisticsLocation?.last_seen_at || '');
            const logisticsAgeAtResponse = Number.isFinite(serverGeneratedAt) && Number.isFinite(logisticsLastSeenAt)
                ? Math.max(0, serverGeneratedAt - logisticsLastSeenAt)
                : Number.POSITIVE_INFINITY;
            liveLogisticsLocation = Number.isFinite(liveLatitude) && liveLatitude >= -90 && liveLatitude <= 90
                && Number.isFinite(liveLongitude) && liveLongitude >= -180 && liveLongitude <= 180
                && logisticsAgeAtResponse + requestDuration <= logisticsFreshnessWindowMs
                ? {
                    ...sharedLogisticsLocation,
                    local_fresh_until: receivedAt + logisticsFreshnessWindowMs - logisticsAgeAtResponse - requestDuration,
                }
                : null;
            riders = (Array.isArray(data.riders) ? data.riders : []).map((rider) => {
                const isPlottable = rider.location_usable === true
                    && rider.gps_quality === 'usable'
                    && coordinatePair(rider.latitude, rider.longitude) !== null;

                if (!isPlottable) return rider;

                const lastSeenAt = Date.parse(rider.last_seen_at);
                if (!Number.isFinite(serverGeneratedAt) || !Number.isFinite(lastSeenAt)) {
                    return markLocationStale(rider);
                }

                const ageAtResponse = Math.max(0, serverGeneratedAt - lastSeenAt);
                if (ageAtResponse + requestDuration > freshnessWindowMs) return markLocationStale(rider);

                return {
                    ...rider,
                    local_fresh_until: receivedAt + freshnessWindowMs - ageAtResponse - requestDuration,
                };
            });

            renderMarkers(pickupData);
            renderRiderList();
            renderDistanceLine();
            renderStatus(data);
            scheduleFreshnessExpiry();

            setSync(`Updated ${formatTimestamp(data.generated_at)}`, 'live');
        };

        const refresh = async () => {
            if (!endpoint || document.hidden || modal?.hidden) return;

            if (requestInFlight) {
                refreshQueued = true;
                return;
            }

            requestInFlight = true;
            const requestStartedAt = Date.now();

            try {
                const response = await fetch(endpoint, {
                    cache: 'no-store',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    throw new Error(payload.message || 'Live Rider locations could not be loaded.');
                }

                render(payload, requestStartedAt);
            } catch (error) {
                const message = error instanceof Error
                    ? error.message
                    : 'Live Rider locations could not be loaded.';
                setSync('Map update unavailable', 'error');
                setStatus(`${message} Candidate selection remains available; Rider pins and distances are removed when their 3-minute freshness expires.`, 'warning');
            } finally {
                requestInFlight = false;

                if (refreshQueued) {
                    refreshQueued = false;
                    if (!document.hidden && !modal?.hidden) {
                        window.setTimeout(() => { void refresh(); }, 100);
                    }
                }
            }
        };

        const stopRefreshing = () => {
            if (refreshTimer !== null) window.clearInterval(refreshTimer);
            refreshTimer = null;
        };

        const startRefreshing = () => {
            if (refreshTimer !== null) return;

            void refresh();
            refreshTimer = window.setInterval(refresh, REFRESH_INTERVAL_MS);
        };

        const openMap = () => {
            if (modal?.hidden) return;

            ensureMap();
            expireStaleRiderLocations();
            window.setTimeout(() => map?.invalidateSize(), 0);
            startRefreshing();
        };

        riderList.addEventListener('click', (event) => {
            const button = event.target.closest('[data-pickup-map-select-rider]');
            if (!button) return;

            selectRider(button.dataset.pickupMapSelectRider);
        });

        assignmentSelect()?.addEventListener('change', syncMapSelection);
        window.addEventListener('bearly:rider-location-updated', (event) => {
            if (!Number.isFinite(Number(event.detail?.rider_user_id))) return;
            if (document.hidden || modal?.hidden) return;

            void refresh();
        });
        window.addEventListener('bearly:logistics-location-updated', () => {
            if (document.hidden || modal?.hidden) return;

            void refresh();
        });
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stopRefreshing();
            } else if (!modal?.hidden) {
                openMap();
            }
        });

        if (modal) {
            const observer = new MutationObserver(() => {
                if (modal.hidden) {
                    stopRefreshing();
                } else {
                    openMap();
                }
            });
            observer.observe(modal, {
                attributes: true,
                attributeFilter: ['hidden'],
            });
        } else {
            openMap();
        }
    });
}
