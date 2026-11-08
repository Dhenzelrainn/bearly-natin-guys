import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [
    destinationMapScript,
    riderScript,
    riderLayout,
    riderStyles,
    riderPickupView,
    courierView,
    waybillStyles,
    logisticsPickupView,
    logisticsScript,
    logisticsLayout,
] = await Promise.all([
    read('resources/js/destination-map.js'),
    read('resources/js/rider.js'),
    read('resources/views/rider/layouts/app.blade.php'),
    read('resources/css/rider.css'),
    read('resources/views/rider/dashboard/pickups.blade.php'),
    read('resources/views/rider/dashboard/courier.blade.php'),
    read('resources/css/waybill-scanner.css'),
    read('resources/views/logistics/pickups/index.blade.php'),
    read('resources/js/logistics.js'),
    read('resources/views/logistics/layouts/app.blade.php'),
]);

test('Rider operational status events update the live alert and lock availability controls', () => {
    assert.match(riderScript, /window\.addEventListener\('bearly:rider-operational-status-updated'/);
    assert.match(riderScript, /accountAlert\.hidden = !isLocked/);
    assert.match(riderScript, /submit\.disabled = isLocked/);
    assert.match(riderScript, /input\.value = isLocked[\s\S]*?'offline'/);
    assert.match(riderScript, /Your Rider account is suspended\./);
    assert.match(riderScript, /Your Rider account is deactivated\./);
    assert.match(riderStyles, /\.rider-account-live-alert\[hidden\][\s\S]*?display:\s*none\s*!important/);
});

test('Rider availability surfaces expose hooks for both local pickup and Courier pages', () => {
    assert.match(riderLayout, /data-rider-account-alert/);
    assert.match(riderPickupView, /data-rider-availability-control/);
    assert.match(riderPickupView, /data-rider-availability-submit/);
    assert.match(riderPickupView, /Off duty/);
    assert.match(courierView, /data-rider-availability-badge/);
    assert.match(courierView, /data-rider-availability-form/);
    assert.match(courierView, /Off duty/);
});

test('Local Rider availability labels describe assignment intake separately from attendance', async () => {
    const attendanceView = await read('resources/views/rider/attendance/index.blade.php');

    assert.match(riderPickupView, /Accept new assignments/);
    assert.match(riderPickupView, /Pause new assignments/);
    assert.match(riderPickupView, /does not change assignment availability or Local Rider per-job earnings/);
    assert.match(riderPickupView, /route\('rider\.attendance\.history'\)/);
    assert.match(attendanceView, /Philippine Time \(PHT \/ UTC\+8\)/);
    assert.match(attendanceView, /Still checked in/);
    assert.match(attendanceView, /Open shift/);
    assert.match(attendanceView, /\$shifts->nextPageUrl\(\)/);
    assert.match(riderLayout, /route\('rider\.attendance\.history'\)/);
});

test('Rider and Logistics success flash messages auto-dismiss after five seconds', () => {
    for (const layout of [riderLayout, logisticsLayout]) {
        assert.match(layout, /class="flash-banner is-success" data-auto-dismiss="5000"/);
    }

    for (const script of [riderScript, logisticsScript]) {
        assert.match(script, /\.flash-banner\.is-success\[data-auto-dismiss\]/);
        assert.match(script, /window\.setTimeout\(\(\) => banner\.remove\(\), delay\)/);
    }
});

test('Rider foreground location sharing uses the authenticated latest-location contract', () => {
    assert.match(riderScript, /navigator\.geolocation\.watchPosition/);
    assert.match(riderScript, /navigator\.geolocation\.getCurrentPosition/);
    assert.match(riderScript, /const RIDER_GEOLOCATION_OPTIONS = Object\.freeze\(\{\s*enableHighAccuracy:\s*true,\s*maximumAge:\s*0,\s*timeout:\s*30_000/s);
    assert.equal([...riderScript.matchAll(/navigator\.geolocation\.(?:watchPosition|getCurrentPosition)\([\s\S]*?RIDER_GEOLOCATION_OPTIONS/g)].length, 2);
    assert.match(riderScript, /RIDER_LOCATION_MAX_USABLE_ACCURACY_METERS = 100/);
    assert.match(riderScript, /RIDER_LOCATION_SEND_INTERVAL_MS = 5_000/);
    assert.match(riderScript, /RIDER_LOCATION_HEARTBEAT_INTERVAL_MS = 15_000/);
    assert.match(riderScript, /RIDER_FIRST_FIX_TIMEOUT_MS = 15_000/);
    assert.match(riderScript, /Still waiting for GPS/);
    assert.match(riderScript, /bearly:rider-location-shared/);
    assert.match(riderScript, /watchId === null \|\| !sharingPreference\(\)/);
    assert.match(riderScript, /if \(requestInFlight\) await locationRequestSettled/);
    assert.match(riderScript, /let sharingSession = 0/);
    assert.match(riderScript, /sharingSession \+= 1/);
    assert.match(riderScript, /const session = \+\+sharingSession;[\s\S]*?lastSentAt = 0/);
    assert.match(riderScript, /session !== sharingSession/);
    assert.match(riderScript, /setSharingPreference\(true\);\s*setUi\('pending'/);
    assert.match(riderScript, /Location shared · approximate/);
    assert.match(riderScript, /Windows Location and browser site permissions/);
    assert.match(riderScript, /method:\s*'POST'/);
    assert.match(riderScript, /accuracy_meters/);
    assert.match(riderScript, /RIDER_LOCATION_SEND_INTERVAL_MS/);
    assert.match(riderScript, /bearly:rider-operational-status-updated/);
    assert.match(riderLayout, /data-rider-location-endpoint/);
    assert.match(riderLayout, /data-rider-location-clear-endpoint/);
    assert.match(riderLayout, /data-rider-work-type/);
    assert.match(riderLayout, /PC and phone supported; allow device and browser location/);
    assert.match(riderStyles, /\.rider-location-control/);
});

test('Accepted pickup road route follows Geoapify geometry only while Rider location sharing is active', async () => {
    const pickupDetails = await read('resources/views/rider/orders/pickup.blade.php');
    const controller = await read('app/Http/Controllers/RiderController.php');
    const routeService = await read('app/Services/RiderPickupRouteService.php');
    const webRoutes = await read('routes/web.php');

    assert.match(pickupDetails, /data-pickup-route-endpoint=/);
    assert.match(pickupDetails, /data-pickup-route-enabled=.*\$canShowPickupRoute/);
    assert.match(pickupDetails, /data-pickup-marker-label=/);
    assert.match(pickupDetails, /data-pickup-marker-kind=/);
    assert.match(pickupDetails, /data-pickup-status=/);
    assert.match(pickupDetails, /Share your live location to display the road route/);
    assert.match(pickupDetails, /class="pickup-route-legend"/);
    assert.match(pickupDetails, /Direct guide line · not a road route/);
    assert.match(controller, /public function pickupRoute\([\s\S]*?pickupAssignments\(\)[\s\S]*?whereHas\([\s\S]*?pickup_no/);
    assert.match(webRoutes, /Route::get\('\/pickup\/\{id\}\/route', \[RiderController::class, 'pickupRoute'\]\)/);
    assert.match(routeService, /'https:\/\/api\.geoapify\.com\/v1\/routing'/);
    assert.match(routeService, /'apiKey' => trim\(\$apiKey\)/);
    assert.match(routeService, /isUsableForProximity\(\$location\)/);
    assert.match(destinationMapScript, /L\.geoJSON\(data\.route/);
    assert.match(destinationMapScript, /SELLER_PICKUP_PIN_SVG/);
    assert.match(destinationMapScript, /pickupMapPinIcon\(pickupKind, pickupSvg\)/);
    assert.match(destinationMapScript, /RIDER_PIN_SVG/);
    assert.match(destinationMapScript, /pickupMapPinIcon\('rider', RIDER_PIN_SVG\)/);
    assert.match(destinationMapScript, /const guideLayer = L\.polyline\(\[\]/);
    assert.match(destinationMapScript, /guideLayer\.setLatLngs\(\[coordinates, pickupCoordinates\]\)/);
    assert.match(destinationMapScript, /setRiderPosition\(coordinates\)/);
    assert.match(destinationMapScript, /L\.map\(element, \{ scrollWheelZoom: false, dragging: true \}\)/);
    assert.match(destinationMapScript, /map\.fitBounds\(L\.latLngBounds\(points\), \{ padding: \[28, 28\], maxZoom: 15 \}\)/);
    assert.match(destinationMapScript, /routeAvailable = routeStageEnabled && Boolean\(routeEndpoint\)/);
    assert.match(destinationMapScript, /Your shared Rider pin and direct guide are shown here/);
    assert.match(destinationMapScript, /ROUTE_REFRESH_INTERVAL_MS = 15_000/);
    assert.match(destinationMapScript, /bearly:rider-location-shared/);
    assert.match(destinationMapScript, /riderMarker\.setLatLng\(coordinates\)/);
    assert.match(destinationMapScript, /locationAgeSeconds = Number\(data\.rider\?\.location_age_seconds\)/);
    assert.match(destinationMapScript, /millisecondsUntilStale = \(staleAfterSeconds - locationAgeSeconds\) \* 1000/);
    assert.doesNotMatch(destinationMapScript, /Date\.parse\(data\.rider\?\.last_seen_at/);
    assert.match(destinationMapScript, /cache: 'no-store'/);
    assert.match(destinationMapScript, /accuracyUsable === true/);
    assert.match(destinationMapScript, /Rider GPS accuracy is too low for a road route/);
    assert.match(destinationMapScript, /sharingState === 'sharing'[\s\S]*?Your approximate Rider pin and dashed direct guide are shown/);
    assert.match(destinationMapScript, /requestController\?\.abort\(\)/);
    assert.match(destinationMapScript, /bearly:rider-location-sharing-updated/);
    assert.match(destinationMapScript, /Road route hidden because location sharing is off/);
    assert.match(destinationMapScript, /removeRoadRoute\(\);[\s\S]*?const message = error instanceof Error/);
    assert.match(riderStyles, /\.rider-pickup-map-marker--seller > span/);
    assert.match(riderStyles, /\.rider-pickup-map-marker--rider > span/);
    assert.match(riderStyles, /\.pickup-route-legend-key\.is-guide/);
    assert.match(riderScript, /new CustomEvent\('bearly:rider-location-sharing-updated'[\s\S]*?accuracyUsable/);
});

test('Courier pages omit local GPS controls and clear stale local location without starting geolocation', () => {
    assert.match(riderLayout, /@unless\(\$isCourier\)[\s\S]*?data-rider-location-status[\s\S]*?@endunless/);
    assert.match(riderScript, /const isLocalRider[\s\S]*?if \(!isLocalRider\)[\s\S]*?method:\s*'DELETE'[\s\S]*?return;/);
});

test('Rider pickup details keep workflow, action footer, map metadata, and waybill readable in dark mode', async () => {
    const pickupDetails = await read('resources/views/rider/orders/pickup.blade.php');

    assert.match(pickupDetails, /class="workflow-steps"/);
    assert.match(pickupDetails, /class="panel-footer"/);
    assert.match(pickupDetails, /class="map-meta"/);
    assert.match(riderStyles, /:root\[data-theme="dark"\] body\[data-module="rider"\] \.workflow-steps[\s\S]*?background:\s*var\(--theme-surface\)/);
    assert.match(riderStyles, /:root\[data-theme="dark"\] body\[data-module="rider"\] \.panel-footer[\s\S]*?background:\s*var\(--theme-surface\)/);
    assert.match(riderStyles, /:root\[data-theme="dark"\] body\[data-module="rider"\] \.pickup-map-shell \.map-meta[\s\S]*?background:\s*var\(--theme-surface-soft\)/);
    assert.match(riderStyles, /:root\[data-theme="dark"\] body\[data-module="rider"\] \.destination-map-status[\s\S]*?color:\s*var\(--theme-muted\)/);
    assert.match(waybillStyles, /:root\[data-theme="dark"\] \.ops-body\[data-module="rider"\] \.rider-waybill-verification\{[^}]*background:var\(--theme-surface\)/);
    assert.match(waybillStyles, /:root\[data-theme="dark"\] \.ops-body\[data-module="rider"\] \.rider-waybill-expected-item\{[^}]*background:var\(--theme-surface-soft\)/);
    assert.match(waybillStyles, /:root\[data-theme="dark"\] \.ops-body\[data-module="rider"\] \.rider-waybill-scan-field input\{[^}]*background:var\(--theme-input\)/);
    assert.match(waybillStyles, /:root\[data-theme="dark"\] \.ops-body\[data-module="rider"\] \.waybill-camera-button\{[^}]*background:var\(--theme-surface-soft\)/);
    assert.match(waybillStyles, /:root\[data-theme="dark"\] \.ops-body\[data-module="rider"\] \.rider-waybill-code>code\{color:var\(--theme-text\)\}/);
});

test('Logistics pickup UI keeps the recommendation as a manual confirmation', async () => {
    const pickupStyles = await read('resources/css/logistics.css');

    assert.match(logisticsPickupView, /Suggested Rider:/);
    assert.match(logisticsPickupView, /data-lucide="sparkles"/);
    assert.match(logisticsPickupView, /Assign Rider &amp; schedule pickup/);
    assert.match(pickupStyles, /\.pickup-rider-recommendation/);
});
