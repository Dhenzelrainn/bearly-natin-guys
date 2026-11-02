import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [mapScript, pickupView, logisticsLayout] = await Promise.all([
    read('resources/js/logistics-pickup-map.js'),
    read('resources/views/logistics/pickups/index.blade.php'),
    read('resources/views/logistics/layouts/app.blade.php'),
]);

test('Logistics pickup map uses Leaflet/OpenStreetMap and refreshes while a modal is open', () => {
    assert.match(mapScript, /import L from 'leaflet'/);
    assert.match(mapScript, /L\.tileLayer/);
    assert.match(mapScript, /OpenStreetMap contributors/);
    assert.match(mapScript, /REFRESH_INTERVAL_MS = 30_000/);
    assert.match(mapScript, /MutationObserver/);
    assert.match(mapScript, /cache: 'no-store'/);
    assert.match(mapScript, /credentials: 'same-origin'/);
});

test('Pickup map keeps coordinates provider-scoped and manual', () => {
    assert.match(pickupView, /data-pickup-live-map-panel/);
    assert.match(pickupView, /data-pickup-map-endpoint/);
    assert.match(pickupView, /data-pickup-map-select-target/);
    assert.match(pickupView, /Fresh locations from your available local Riders/);
    assert.match(pickupView, /manual assignment/);
    assert.match(logisticsLayout, /resources\/js\/logistics\.js/);
});
