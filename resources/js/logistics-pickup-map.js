import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import './leaflet-default-icon.js';

const DEFAULT_CENTER = [12.8797, 121.7740];
const REFRESH_INTERVAL_MS = 30_000;

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
        const selectTarget = panel.dataset.pickupMapSelectTarget || '';

        if (!mapElement || !riderList) return;

        let map = null;
        let markerLayer = null;
        let hasFittedBounds = false;
        let refreshTimer = null;
        let requestInFlight = false;
        let riders = [];

        const assignmentSelect = () => selectTarget
            ? document.querySelector(selectTarget)
            : null;

        const setStatus = (message) => {
            if (statusElement) statusElement.textContent = message;
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

        const selectRider = (riderId) => {
            const select = assignmentSelect();
            if (select) {
                select.value = String(riderId);
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }

            syncSelectedRider();
        };

        const ensureMap = () => {
            if (map || !tileUrl) return map;

            map = L.map(mapElement, {
                scrollWheelZoom: false,
            }).setView(DEFAULT_CENTER, 6);
            markerLayer = L.layerGroup().addTo(map);

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
                riderList.innerHTML = '<p class="pickup-map-empty">No fresh available Rider locations. Use the Sorting Zone, workload, or manual selection fallback.</p>';
                return;
            }

            riderList.innerHTML = riders.map((rider) => {
                const distance = rider.distance_label || 'Fresh location';
                const accuracy = Number.isFinite(Number(rider.accuracy_meters))
                    ? ` · ±${Math.round(Number(rider.accuracy_meters))} m accuracy`
                    : '';
                const recommendation = rider.is_recommended ? 'Suggested · ' : '';

                return `<div class="pickup-map-rider-item${rider.is_recommended ? ' is-recommended' : ''}" data-pickup-map-rider-item="${escapeHtml(rider.id)}">
                    <i class="pickup-map-rider-dot" aria-hidden="true"></i>
                    <button class="pickup-map-rider-button" type="button" data-pickup-map-select-rider="${escapeHtml(rider.id)}" aria-pressed="false">
                        <strong>${escapeHtml(rider.name)}</strong>
                        <small>${escapeHtml(recommendation + distance + accuracy)} · Updated ${escapeHtml(formatTimestamp(rider.last_seen_at))}</small>
                    </button>
                </div>`;
            }).join('');

            syncSelectedRider();
        };

        const renderMarkers = (pickup) => {
            if (!map || !markerLayer) return;

            markerLayer.clearLayers();
            const points = [];
            const pickupCoordinates = coordinatePair(pickup?.latitude, pickup?.longitude);

            if (pickupCoordinates) {
                points.push(pickupCoordinates);
                L.marker(pickupCoordinates)
                    .bindPopup('<strong>Pickup location</strong><br>Seller / return pickup')
                    .addTo(markerLayer);
            }

            riders.forEach((rider) => {
                const coordinates = coordinatePair(rider.latitude, rider.longitude);
                if (!coordinates) return;

                points.push(coordinates);
                const marker = L.circleMarker(coordinates, {
                    radius: 8,
                    color: '#2e8250',
                    weight: 2,
                    fillColor: '#5eaf73',
                    fillOpacity: 0.9,
                })
                    .bindPopup(`<strong>${escapeHtml(rider.name)}</strong><br>${escapeHtml(rider.distance_label || 'Fresh location')}<br>Updated ${escapeHtml(formatTimestamp(rider.last_seen_at))}`)
                    .addTo(markerLayer);

                marker.on('click', () => selectRider(rider.id));
            });

            if (!hasFittedBounds && points.length > 1) {
                map.fitBounds(L.latLngBounds(points), {
                    padding: [28, 28],
                    maxZoom: 15,
                });
                hasFittedBounds = true;
            } else if (!hasFittedBounds && points.length === 1) {
                map.setView(points[0], 15);
                hasFittedBounds = true;
            }
        };

        const render = (payload) => {
            const data = payload?.data || {};
            riders = Array.isArray(data.riders) ? data.riders : [];
            renderMarkers(data.pickup || {});
            renderRiderList();

            if (!riders.length) {
                setStatus(data.pickup?.latitude === null || data.pickup?.latitude === undefined
                    ? 'No fresh Rider markers are available, and the pickup has no saved map pin.'
                    : 'No fresh available Rider markers are available. Fallback ranking remains active.');
            } else if (data.pickup?.latitude === null || data.pickup?.latitude === undefined) {
                setStatus(`${pluralize(riders.length, 'fresh Rider location')} shown. The pickup has no saved map pin.`);
            } else {
                setStatus(`${pluralize(riders.length, 'fresh Rider location')} shown. Only your provider-owned Riders are included.`);
            }

            setSync(`Updated ${formatTimestamp(data.generated_at)}`, 'live');
        };

        const refresh = async () => {
            if (!endpoint || requestInFlight || document.hidden) return;

            requestInFlight = true;

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

                render(payload);
            } catch (error) {
                const message = error instanceof Error
                    ? error.message
                    : 'Live Rider locations could not be loaded.';
                setSync('Map update unavailable', 'error');
                setStatus(`${message} Existing markers remain available, or use manual Rider selection.`);
            } finally {
                requestInFlight = false;
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
            window.setTimeout(() => map?.invalidateSize(), 0);
            startRefreshing();
        };

        riderList.addEventListener('click', (event) => {
            const button = event.target.closest('[data-pickup-map-select-rider]');
            if (!button) return;

            selectRider(button.dataset.pickupMapSelectRider);
        });

        assignmentSelect()?.addEventListener('change', syncSelectedRider);
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
