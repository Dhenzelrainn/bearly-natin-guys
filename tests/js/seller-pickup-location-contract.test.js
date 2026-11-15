import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import {
    bestGpsReading,
    gpsReadingFromPosition,
    isStableGpsFix,
    isWithinGpsAccuracyLimit,
} from '../../resources/js/geolocation-fix.js';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [storeView, sellerMapScript, mapPickerScript, sellerStyles, gpsFixScript, sellerLayout] = await Promise.all([
    read('resources/views/seller/Store/store.blade.php'),
    read('resources/js/seller-store-map.js'),
    read('resources/js/address-map-picker.js'),
    read('resources/css/seller.css'),
    read('resources/js/geolocation-fix.js'),
    read('resources/views/layouts/seller.blade.php'),
]);

test('Seller pickup map exposes device detection and explicit pin saving', () => {
    assert.match(storeView, /data-detect-pickup-location/);
    assert.match(storeView, /data-place-pickup-pin-manually/);
    assert.match(storeView, /aria-pressed="false"/);
    assert.match(storeView, /Tap the map to place a pin/);
    assert.match(storeView, /Detect my current location/);
    assert.match(storeView, /data-save-pickup-pin/);
    assert.match(storeView, /role="status" aria-live="polite"/);
    assert.match(sellerMapScript, /locationButton:\s*document\.querySelector\('\[data-detect-pickup-location\]'\)/);
    assert.match(sellerMapScript, /manualPlacementButton:\s*document\.querySelector\('\[data-place-pickup-pin-manually\]'\)/);
    assert.match(sellerMapScript, /stableLocation:\s*true/);
    assert.match(sellerMapScript, /saveLabel:\s*'Save Pickup Pin'/);
    assert.match(sellerStyles, /\.store-profile-page \.store-pickup-pin-actions/);
    assert.match(sellerLayout, /data-seller-toast role="status" aria-live="polite"/);
});

test('Seller location detection requests browser permission and reports GPS accuracy before saving', () => {
    assert.match(mapPickerScript, /locationButton\?\.addEventListener\('click'/);
    assert.match(mapPickerScript, /navigator\.geolocation\.watchPosition/);
    assert.match(mapPickerScript, /navigator\.geolocation\.getCurrentPosition/);
    assert.match(mapPickerScript, /navigator\.geolocation\?\.clearWatch/);
    assert.match(mapPickerScript, /enableHighAccuracy:\s*true/);
    assert.match(mapPickerScript, /maximumAge:\s*0/);
    assert.match(mapPickerScript, /GPS_WATCH_DURATION_MS/);
    assert.match(gpsFixScript, /GPS_WATCH_DURATION_MS\s*=\s*45_000/);
    assert.match(gpsFixScript, /GPS_ACCURACY_TARGET_METERS\s*=\s*30/);
    assert.match(gpsFixScript, /GPS_STABLE_READINGS_REQUIRED\s*=\s*3/);
    assert.match(mapPickerScript, /Reported GPS accuracy/);
    assert.match(mapPickerScript, /Approximate location/);
    assert.match(mapPickerScript, /You can still click the map or drag the pin manually/);
    assert.match(mapPickerScript, /map\.on\('click', event => \{\s*const wasManualPlacementActive = manualPlacementActive;\s*stopLocationDetection\(\);\s*setPin\(event\.latlng\);/);
    assert.match(mapPickerScript, /marker\.on\('dragend', \(\) => \{\s*stopLocationDetection\(\);\s*setManualPlacementActive\(false\);\s*setPin\(marker\.getLatLng\(\)\);/);
    assert.match(mapPickerScript, /manualPlacementButton\?\.addEventListener\('click'/);
    assert.match(mapPickerScript, /element\.classList\.toggle\('is-placing-pin', active\)/);
    assert.match(mapPickerScript, /Manual pin placement is active/);
    assert.match(sellerStyles, /\.address-map-manual\[aria-pressed="true"\]/);
    assert.match(sellerMapScript, /Pickup pin saved successfully\./);
    assert.match(sellerMapScript, /showToast\(message, 'success'\)/);
    assert.match(sellerMapScript, /toast\.classList\.add\('is-visible'\)/);
    assert.match(sellerMapScript, /status\.dataset\.state = state/);
    assert.match(sellerStyles, /\.address-map-status\[data-state="success"\]/);
    assert.match(sellerStyles, /\.seller-toast\[data-state="success"\]/);
});

test('GPS fixes require three accurate, nearby readings and fallback chooses the best reported accuracy', () => {
    const position = (latitude, longitude, accuracy) => gpsReadingFromPosition({
        coords: { latitude, longitude, accuracy },
    });
    const stableReadings = [
        position(14.599500, 120.984200, 28),
        position(14.599580, 120.984210, 19),
        position(14.599610, 120.984260, 12),
    ];

    assert.equal(isStableGpsFix(stableReadings.slice(0, 2)), false);
    assert.equal(isStableGpsFix(stableReadings), true);
    assert.equal(isStableGpsFix([
        ...stableReadings.slice(0, 2),
        position(14.599900, 120.984260, 12),
    ]), false);
    assert.equal(isStableGpsFix([
        ...stableReadings.slice(0, 2),
        position(14.599610, 120.984260, 31),
    ]), false);
    assert.equal(bestGpsReading([stableReadings[0], stableReadings[2]]), stableReadings[2]);
    assert.equal(isWithinGpsAccuracyLimit(stableReadings[0], 100), true);
    assert.equal(isWithinGpsAccuracyLimit(position(14.599610, 120.984260, 10000), 100), false);
    assert.equal(isWithinGpsAccuracyLimit(position(14.599610, 120.984260, null), 100), false);
    assert.equal(isWithinGpsAccuracyLimit(position(14.599610, 120.984260, undefined), 100), false);
    assert.equal(position(91, 120, 5), null);
});
