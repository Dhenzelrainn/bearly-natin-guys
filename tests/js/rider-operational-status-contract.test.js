import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [
    riderScript,
    riderLayout,
    riderStyles,
    riderPickupView,
    courierView,
    logisticsPickupView,
] = await Promise.all([
    read('resources/js/rider.js'),
    read('resources/views/rider/layouts/app.blade.php'),
    read('resources/css/rider.css'),
    read('resources/views/rider/dashboard/pickups.blade.php'),
    read('resources/views/rider/dashboard/courier.blade.php'),
    read('resources/views/logistics/pickups/index.blade.php'),
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

test('Rider foreground location sharing uses the authenticated latest-location contract', () => {
    assert.match(riderScript, /navigator\.geolocation\.watchPosition/);
    assert.match(riderScript, /navigator\.geolocation\.getCurrentPosition/);
    assert.match(riderScript, /method:\s*'POST'/);
    assert.match(riderScript, /accuracy_meters/);
    assert.match(riderScript, /RIDER_LOCATION_SEND_INTERVAL_MS/);
    assert.match(riderScript, /bearly:rider-operational-status-updated/);
    assert.match(riderLayout, /data-rider-location-endpoint/);
    assert.match(riderLayout, /data-rider-location-clear-endpoint/);
    assert.match(riderLayout, /data-rider-work-type/);
    assert.match(riderStyles, /\.rider-location-control/);
});

test('Logistics pickup UI keeps the recommendation as a manual confirmation', async () => {
    const pickupStyles = await read('resources/css/logistics.css');

    assert.match(logisticsPickupView, /Suggested Rider:/);
    assert.match(logisticsPickupView, /data-lucide="sparkles"/);
    assert.match(logisticsPickupView, /Assign Rider &amp; schedule pickup/);
    assert.match(pickupStyles, /\.pickup-rider-recommendation/);
});
