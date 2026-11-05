import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [mapScript, pickupView, logisticsLayout, logisticsStyles, realtimeScript] = await Promise.all([
    read('resources/js/logistics-pickup-map.js'),
    read('resources/views/logistics/pickups/index.blade.php'),
    read('resources/views/logistics/layouts/app.blade.php'),
    read('resources/css/logistics.css'),
    read('resources/js/realtime.js'),
]);

test('Logistics pickup map uses Leaflet/OpenStreetMap and refreshes while a modal is open', () => {
    assert.match(mapScript, /import L from 'leaflet'/);
    assert.match(mapScript, /L\.tileLayer/);
    assert.match(mapScript, /OpenStreetMap contributors/);
    assert.match(mapScript, /REFRESH_INTERVAL_MS = 10_000/);
    assert.match(mapScript, /MutationObserver/);
    assert.match(mapScript, /cache: 'no-store'/);
    assert.match(mapScript, /credentials: 'same-origin'/);
    assert.match(mapScript, /bearly:rider-location-updated/);
    assert.match(mapScript, /refreshQueued/);
    assert.match(realtimeScript, /\.listen\('\.rider\.location-updated'/);
    assert.match(realtimeScript, /bearly:rider-location-updated/);
});

test('Pickup map keeps coordinates provider-scoped and manual', () => {
    assert.match(pickupView, /data-pickup-live-map-panel/);
    assert.match(pickupView, /data-pickup-map-endpoint/);
    assert.match(pickupView, /data-pickup-map-select-target/);
    assert.match(pickupView, /All assignable local Riders appear in the list/);
    assert.match(pickupView, /manual assignment/);
    assert.match(logisticsLayout, /resources\/js\/logistics\.js/);
});

test('Pickup manifest and activity rows use readable dark-mode surfaces and text', () => {
    assert.match(logisticsStyles, /:root\[data-theme="dark"\] body\.ops-body \.pickup-manifest-wrap\s*\{[^}]*background:\s*var\(--theme-surface\)/);
    assert.match(logisticsStyles, /:root\[data-theme="dark"\] body\.ops-body \.pickup-manifest-products-row > td,[\s\S]*?background:\s*var\(--theme-surface-soft\)/);
    assert.match(logisticsStyles, /:root\[data-theme="dark"\] body\.ops-body \.pickup-activity-copy > strong,[\s\S]*?color:\s*var\(--theme-text\)/);
    assert.match(logisticsStyles, /:root\[data-theme="dark"\] body\.ops-body \.pickup-activity-list li:first-child \.pickup-activity-marker/);
});

test('Pickup live map keeps its minimum height inside the scrolling request modal', () => {
    assert.match(logisticsStyles, /\.pickup-request-modal-body\s*\{[^}]*grid-auto-rows:\s*max-content/);
    assert.match(logisticsStyles, /\.pickup-live-map-card\s*\{[^}]*min-height:\s*420px/);
    assert.match(logisticsStyles, /\.pickup-live-map-layout\s*\{[^}]*min-height:\s*310px/);
    assert.match(logisticsStyles, /\.pickup-live-map-wrap\s*\{[^}]*min-height:\s*310px/);
    assert.match(logisticsStyles, /\.pickup-live-map\s*\{[^}]*min-height:\s*310px/);
});

test('Pickup map draws one approximate straight-line distance to the selected or recommended fresh Rider', () => {
    assert.match(mapScript, /connectorLayer = L\.layerGroup\(\)\.addTo\(map\)/);
    assert.match(mapScript, /const selectedRiderForConnector = \(\) => \{[\s\S]*?selectedId[\s\S]*?riders\.find\(\(rider\) => rider\.is_recommended\)/);
    assert.match(mapScript, /if \(!pickupCoordinates\) return;[\s\S]*?coordinatePair\(rider\?\.latitude, rider\?\.longitude\);[\s\S]*?if \(!riderCoordinates\) return;[\s\S]*?L\.polyline\(\[pickupCoordinates, riderCoordinates\]/);
    assert.match(mapScript, /Approx\. straight-line · \$\{rider\.distance_label\}/);
    assert.match(mapScript, /const area = rider\.area_match \? 'Same service area' : 'Outside service area'/);
    assert.match(mapScript, /assignmentSelect\(\)\?\.addEventListener\('change', syncMapSelection\)/);
    assert.match(pickupView, /pickup-map-legend-line/);
    assert.match(pickupView, /Straight-line distance \(approx\.\)/);
    assert.match(logisticsStyles, /\.pickup-map-legend-line\s*\{[^}]*border-top:\s*3px dashed #c76500/);
    assert.match(logisticsStyles, /\.pickup-map-rider-button small\s*\{[^}]*white-space:\s*normal/);
});

test('Pickup map refits once when accurate Rider locations arrive after initially centering on pickup', () => {
    assert.match(mapScript, /let hasCenteredInitialPoint = false/);
    assert.match(mapScript, /let hasFittedRiderLocations = false/);
    assert.match(mapScript, /if \(!hasFittedRiderLocations && points\.length > 1\)[\s\S]*?map\.fitBounds\(L\.latLngBounds\(points\)/);
    assert.match(mapScript, /hasFittedRiderLocations = riders\.some\(\(rider\) => rider\.location_usable === true/);
});

test('Pickup map expires Rider pins and distances locally if a refresh fails past the freshness window', () => {
    assert.match(mapScript, /const serverGeneratedAt = Date\.parse\(data\.generated_at\)/);
    assert.match(mapScript, /const lastSeenAt = Date\.parse\(rider\.last_seen_at\)/);
    assert.match(mapScript, /const requestDuration = Number\.isFinite\(requestStartedAt\)/);
    assert.match(mapScript, /local_fresh_until: receivedAt \+ freshnessWindowMs - ageAtResponse - requestDuration/);
    assert.match(mapScript, /const expireStaleRiderLocations = \(\) => \{[\s\S]*?return markLocationStale\(rider\)/);
    assert.match(mapScript, /distance_km: null,[\s\S]*?distance_label: null/);
    assert.match(mapScript, /Candidate selection remains available; Rider pins and distances are removed when their 3-minute freshness expires/);
    assert.match(mapScript, /expireStaleRiderLocations\(\);[\s\S]*?startRefreshing\(\)/);
});

test('All eligible Riders remain selectable while only fresh accurate GPS gets map pins', () => {
    assert.match(mapScript, /rider\.location_usable === true[\s\S]*?rider\.gps_quality === 'usable'[\s\S]*?coordinatePair\(rider\.latitude, rider\.longitude\)/);
    assert.match(mapScript, /riderList\.innerHTML = riders\.map\(\(rider\) => \{/);
    assert.match(mapScript, /location_state === 'stale'[\s\S]*?GPS stale; not plotted/);
    assert.match(mapScript, /location_state === 'missing'[\s\S]*?no GPS report; not plotted/);
    assert.match(mapScript, /gps_quality === 'low_accuracy'[\s\S]*?no map pin or distance/);
    assert.match(mapScript, /accuracy is worse than ±\$\{accuracyLimit\} m; no marker or distance is shown/);
    assert.match(mapScript, /pickup-map-rider-dot\$\{dotClass\}/);
    assert.match(mapScript, /All listed Riders remain selectable; Sorting Zone\/workload fallback remains available/);
    assert.match(mapScript, /'warning'\)/);
    assert.match(pickupView, /Fresh, accurate GPS reports get map pins/);
    assert.match(pickupView, /latest GPS report is too imprecise/);
    assert.match(pickupView, /The Rider's last location is older than 3 minutes/);
    assert.match(pickupView, /GPS stale@if\(\$rider\['area_match'\]\)/);
    assert.match(pickupView, /GPS too imprecise/);
    assert.match(mapScript, /data-pickup-map-select-rider/);
    assert.match(logisticsStyles, /\.pickup-map-status\[data-state="warning"\]/);
});
