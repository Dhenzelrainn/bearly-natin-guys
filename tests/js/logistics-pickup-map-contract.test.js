import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [mapScript, pickupView, logisticsLayout, logisticsStyles, realtimeScript, logisticsScript, accountView, addressPickerScript] = await Promise.all([
    read('resources/js/logistics-pickup-map.js'),
    read('resources/views/logistics/pickups/index.blade.php'),
    read('resources/views/logistics/layouts/app.blade.php'),
    read('resources/css/logistics.css'),
    read('resources/js/realtime.js'),
    read('resources/js/logistics.js'),
    read('resources/views/logistics/profile/index.blade.php'),
    read('resources/js/address-map-picker.js'),
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
    assert.match(pickupView, /Saved pickup and Logistics facility pins are omitted when no valid coordinates are saved/);
    assert.match(pickupView, /Same-service-area Riders stay the primary recommendation/);
    assert.match(pickupView, /All eligible Riders remain selectable/);
    assert.match(pickupView, /Logistics manually confirms any assignment or replacement/);
    assert.match(logisticsLayout, /resources\/js\/logistics\.js/);
});

test('Pickup map shows distinct Seller, Logistics, and fresh Rider pins with approximate guide lines', () => {
    assert.match(mapScript, /const SELLER_PIN_SVG/);
    assert.match(mapScript, /const RETURN_PICKUP_PIN_SVG/);
    assert.match(mapScript, /const FACILITY_PIN_SVG/);
    assert.match(mapScript, /const RIDER_PIN_SVG/);
    assert.match(mapScript, /pickupKind = isReturnPickup \? 'buyer' : 'seller'/);
    assert.match(mapScript, /pickupMapPinIcon\(pickupKind, pickupSvg\)/);
    assert.match(mapScript, /pickupMapPinIcon\('facility', FACILITY_PIN_SVG\)/);
    assert.match(mapScript, /pickupMapPinIcon\('rider', RIDER_PIN_SVG, isHighlighted\)/);
    assert.match(mapScript, /coordinatePair\(data\.facility\?\.latitude, data\.facility\?\.longitude\)/);
    assert.match(mapScript, /L\.polyline\(\[pickupCoordinates, riderCoordinates\]/);
    assert.match(mapScript, /L\.polyline\(\[riderCoordinates, facilityCoordinates\]/);
    assert.match(mapScript, /L\.polyline\(\[pickupCoordinates, facilityCoordinates\]/);
    assert.match(pickupView, /Seller pickup/);
    assert.match(pickupView, /Logistics facility/);
    assert.match(pickupView, /Rider with accurate GPS/);
    assert.match(pickupView, /not a road route/);
    assert.match(logisticsStyles, /\.pickup-map-pin--seller\s*\{/);
    assert.match(logisticsStyles, /\.pickup-map-pin--facility\s*\{/);
    assert.match(logisticsStyles, /\.pickup-map-pin--rider\s*\{/);
});

test('Pickup manifest and activity rows use readable dark-mode surfaces and text', () => {
    assert.match(logisticsStyles, /:root\[data-theme="dark"\] body\.ops-body \.pickup-manifest-wrap\s*\{[^}]*background:\s*var\(--theme-surface\)/);
    assert.match(logisticsStyles, /:root\[data-theme="dark"\] body\.ops-body \.pickup-manifest-wrap\s*\{[^}]*scrollbar-color:\s*#665343 var\(--theme-surface\)/);
    assert.match(logisticsStyles, /:root\[data-theme="dark"\] body\.ops-body \.pickup-manifest-products-panel,[\s\S]*?background:\s*#30241d/);
    assert.match(logisticsStyles, /:root\[data-theme="dark"\] body\.ops-body \.pickup-manifest-products-panel,[\s\S]*?\.pickup-manifest-reference-panel\s*\{[^}]*background:\s*var\(--theme-surface\)/);
    assert.match(pickupView, /class="pickup-manifest-products-panel"[\s\S]*?class="pickup-manifest-reference-panel"/);
    assert.doesNotMatch(pickupView, /pickup-manifest-products-row/);
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

test('Pickup replacement controls use a readable grid and stack on narrow screens', () => {
    assert.match(pickupView, /pickup-replacement-form/);
    assert.match(logisticsStyles, /\.pickup-modal-main-actions form\.pickup-replacement-form\s*\{[^}]*display:\s*grid/);
    assert.match(logisticsStyles, /\.pickup-replacement-form \.pickup-assignment-note textarea\s*\{[^}]*min-height:\s*80px/);
    assert.match(logisticsStyles, /@media \(max-width: 760px\) \{[\s\S]*?\.pickup-modal-main-actions form\.pickup-replacement-form\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\)/);
});

test('Pickup map draws approximate straight-line guides through the selected or recommended fresh Rider', () => {
    assert.match(mapScript, /connectorLayer = L\.layerGroup\(\)\.addTo\(map\)/);
    assert.match(mapScript, /const selectedRiderForConnector = \(\) => \{[\s\S]*?selectedId[\s\S]*?riders\.find\(\(rider\) => rider\.is_recommended\)/);
    assert.match(mapScript, /rider\?\.location_usable === true && rider\?\.gps_quality === 'usable'[\s\S]*?coordinatePair\(rider\.latitude, rider\.longitude\)/);
    assert.match(mapScript, /L\.polyline\(\[pickupCoordinates, riderCoordinates\]/);
    assert.match(mapScript, /L\.polyline\(\[riderCoordinates, facilityCoordinates\]/);
    assert.match(mapScript, /else if \(pickupCoordinates && facilityCoordinates\)[\s\S]*?L\.polyline\(\[pickupCoordinates, facilityCoordinates\]/);
    assert.match(mapScript, /Approx\. straight-line · \$\{rider\.distance_label\}/);
    assert.match(mapScript, /const area = rider\.area_match \? 'Same service area' : 'Outside service area'/);
    assert.match(mapScript, /assignmentSelect\(\)\?\.addEventListener\('change', syncMapSelection\)/);
    assert.match(pickupView, /pickup-map-legend-line/);
    assert.match(pickupView, /Approx\. straight-line guide; not a road route/);
    assert.match(logisticsStyles, /\.pickup-map-legend-line\s*\{[^}]*border-top:\s*2px dashed #c76500/);
    assert.match(logisticsStyles, /\.pickup-map-rider-button small\s*\{[^}]*white-space:\s*normal/);
});

test('Pickup map refits once when accurate Rider locations arrive after initially centering on pickup', () => {
    assert.match(mapScript, /let hasCenteredInitialPoint = false/);
    assert.match(mapScript, /let hasFittedRiderLocations = false/);
    assert.match(mapScript, /if \(!hasFittedRiderLocations && hasUsableRiderLocation\)[\s\S]*?map\.fitBounds\(L\.latLngBounds\(points\)/);
    assert.match(mapScript, /else if \(!hasCenteredInitialPoint && points\.length > 1\)[\s\S]*?map\.fitBounds\(L\.latLngBounds\(points\)/);
    assert.match(mapScript, /hasFittedRiderLocations = true/);
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
    assert.match(pickupView, /A Rider pin requires a location update within 3 minutes and reported accuracy of ±100 m or better/);
    assert.match(pickupView, /latest GPS report is too imprecise/);
    assert.match(pickupView, /The Rider's last location is older than 3 minutes/);
    assert.match(pickupView, /@elseif\(\$rider\['location_state'\] === 'stale'\) · GPS stale/);
    assert.match(pickupView, /GPS too imprecise/);
    assert.match(mapScript, /data-pickup-map-select-rider/);
    assert.match(logisticsStyles, /\.pickup-map-status\[data-state="warning"\]/);
});

test('Logistics can set and clear the active facility pin without editing registered address details', () => {
    assert.match(accountView, /data-logistics-facility-form/);
    assert.match(accountView, /data-logistics-facility-map/);
    assert.match(accountView, /name="latitude"/);
    assert.match(accountView, /name="longitude"/);
    assert.match(accountView, /data-detect-facility-location/);
    assert.match(accountView, /data-place-facility-pin-manually/);
    assert.match(accountView, /data-clear-facility-pin/);
    assert.match(accountView, /data-facility-location-status/);
    assert.match(accountView, /data-stop-facility-location/);
    assert.match(accountView, /facility pin supplements the registered address/);
    assert.match(accountView, /reported accuracy of ±100 m or better/);
    assert.match(logisticsScript, /import \{ createAddressMapPicker \} from '\.\/address-map-picker'/);
    assert.match(logisticsScript, /stableLocation: false/);
    assert.match(logisticsScript, /continuousLocation: true/);
    assert.match(logisticsScript, /maximumLocationAccuracyMeters: 100/);
    assert.match(addressPickerScript, /if \(!stableLocation && !continuousLocation\)[\s\S]*?navigator\.geolocation\.getCurrentPosition/);
    assert.match(addressPickerScript, /continuousLocation\) \{[\s\S]*?navigator\.geolocation\.watchPosition/);
    assert.match(addressPickerScript, /Location active · approximate/);
    assert.match(addressPickerScript, /activeLocationRequest\?\.finish\?\.\('Location check stopped\.'/);
    assert.match(addressPickerScript, /timeout: 10000, maximumAge: 0/);
    assert.match(addressPickerScript, /applyDetectedLocation\(reading, quality\)/);
    assert.match(addressPickerScript, /isWithinGpsAccuracyLimit\(reading, maximumLocationAccuracyMeters\)/);
    assert.match(addressPickerScript, /approximateLocationCircle = L\.circle/);
    assert.match(addressPickerScript, /map\.fitBounds\(approximateLocationCircle\.getBounds\(\)/);
    assert.match(addressPickerScript, /The dashed circle shows the approximate GPS area; no pin was placed/);
    assert.match(addressPickerScript, /The map was left unchanged; no pin was placed/);
    assert.match(addressPickerScript, /no pin was placed/);
    assert.match(logisticsScript, /element\.closest\('\[data-tab-panel\]'\)\?\.hidden/);
    assert.match(logisticsScript, /setupTabs\(\);\s*setupLogisticsLocationSharing\(\);\s*setupLogisticsFacilityMap\(\);/);
    assert.match(logisticsStyles, /:root\[data-theme="dark"\] body\.ops-body \[data-logistics-facility-form\] \.address-map-picker-section/);
});

test('Logistics live location is opt-in, provider-only, separate from the fixed facility pin, and expires locally', () => {
    assert.match(logisticsLayout, /data-logistics-location-status-endpoint/);
    assert.match(logisticsLayout, /data-logistics-location-endpoint/);
    assert.match(logisticsLayout, /data-logistics-location-clear-endpoint/);
    assert.match(logisticsLayout, /data-logistics-location-toggle>Share location/);
    assert.match(logisticsScript, /navigator\.geolocation\.watchPosition/);
    assert.match(logisticsScript, /navigator\.geolocation\.getCurrentPosition/);
    assert.match(logisticsScript, /setupLogisticsLocationSharing\(\);/);
    assert.match(logisticsScript, /body\.dataset\.realtimeUserId/);
    assert.match(logisticsScript, /Stop sharing/);
    assert.match(logisticsScript, /bearly:logistics-location-updated/);
    assert.match(logisticsScript, /logisticsLocationFreshnessMs = 180_000/);
    assert.match(logisticsScript, /accuracy_meters: accuracy/);
    assert.match(logisticsScript, /separate from the saved facility pin/);
    assert.match(logisticsScript, /L\.circle\(coordinates/);
    assert.match(mapScript, /data\.logistics_location/);
    assert.match(mapScript, /pickupMapPinIcon\('logistics-live', LOGISTICS_LIVE_PIN_SVG\)/);
    assert.match(mapScript, /L\.circle\(liveCoordinates/);
    assert.match(mapScript, /bearly:logistics-location-updated/);
    assert.match(pickupView, /Live Logistics device · approximate/);
    assert.match(pickupView, /visible only to its own provider/);
    assert.match(accountView, /blue live-device marker, when shared, is separate/);
});

test('Overlapping pickup-map pins offer an accessible nearby-location chooser without zooming', () => {
    const chooserStart = mapScript.indexOf('const showNearbyPinChooser =');
    const chooserEnd = mapScript.indexOf('const openMapPin =', chooserStart);
    const chooserImplementation = mapScript.slice(chooserStart, chooserEnd);

    assert.match(mapScript, /const PIN_COLLISION_RADIUS_PX = 38/);
    assert.match(mapScript, /map\s*\.latLngToContainerPoint\(candidate\.coordinates\)\s*\.distanceTo\(clickedPoint\) <= PIN_COLLISION_RADIUS_PX/);
    assert.match(mapScript, /key: 'pickup',[\s\S]*?label: pickupLabel/);
    assert.match(mapScript, /key: 'facility',[\s\S]*?label: 'Logistics facility'/);
    assert.match(chooserImplementation, /heading\.textContent = 'Nearby locations'/);
    assert.match(chooserImplementation, /options\.setAttribute\('role', 'group'\)/);
    assert.match(chooserImplementation, /button\.textContent = entry\.label/);
    assert.match(chooserImplementation, /openMapPin\(entry\.key\)/);
    assert.doesNotMatch(chooserImplementation, /setZoom|fitBounds/);
    assert.match(logisticsStyles, /\.pickup-map-nearby-choice\s*\{[^}]*min-height:\s*40px/);
    assert.match(logisticsStyles, /\.pickup-map-nearby-choice:focus-visible/);
    assert.match(logisticsStyles, /:root\[data-theme="dark"\] body\.ops-body \.pickup-map-nearby-choice/);
});
