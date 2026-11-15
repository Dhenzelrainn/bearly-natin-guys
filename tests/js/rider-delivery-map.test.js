import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import {
    directionsUrl,
    normalizeRiderFix,
    shouldAppendTrailPoint,
    validCoordinatePair,
} from '../../resources/js/rider-delivery-map-state.js';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

test('delivery map plots valid Rider GPS coordinates and labels uncertain accuracy', () => {
    assert.deepEqual(validCoordinatePair('14.5995', '120.9842'), [14.5995, 120.9842]);
    assert.equal(validCoordinatePair('', '120.9842'), null);
    assert.equal(validCoordinatePair('91', '120.9842'), null);

    assert.deepEqual(normalizeRiderFix({
        latitude: 14.5995,
        longitude: 120.9842,
        accuracyMeters: 100,
    }), {
        coordinates: [14.5995, 120.9842],
        accuracyMeters: 100,
        accuracyUsable: true,
    });
    assert.deepEqual(normalizeRiderFix({
        latitude: 14.5995,
        longitude: 120.9842,
        accuracyMeters: 100.1,
    }), {
        coordinates: [14.5995, 120.9842],
        accuracyMeters: 100.1,
        accuracyUsable: false,
    });
    assert.deepEqual(normalizeRiderFix({
        latitude: 14.5995,
        longitude: 120.9842,
        accuracyMeters: null,
    }), {
        coordinates: [14.5995, 120.9842],
        accuracyMeters: null,
        accuracyUsable: false,
    });
    assert.equal(normalizeRiderFix({ latitude: 91, longitude: 120 }), null);
});

test('movement trail ignores GPS jitter and records meaningful movement', () => {
    const first = [14.5995, 120.9842];

    assert.equal(shouldAppendTrailPoint(null, first), true);
    assert.equal(shouldAppendTrailPoint(first, [14.59951, 120.9842]), false);
    assert.equal(shouldAppendTrailPoint(first, [14.5997, 120.9842]), true);
});

test('delivery directions use the saved destination pin or safely fall back to its address', () => {
    const pinned = new URL(directionsUrl([14.5995, 120.9842], '123 Main Street'));
    assert.equal(pinned.searchParams.get('destination'), '14.5995,120.9842');
    assert.equal(pinned.searchParams.get('travelmode'), 'driving');

    const addressFallback = new URL(directionsUrl(null, '123 Main Street, San Pablo City'));
    assert.equal(addressFallback.searchParams.get('destination'), '123 Main Street, San Pablo City');
    assert.equal(directionsUrl(null, 'Address unavailable'), '');
});

test('delivery map follows only the Rider foreground-sharing events and clears on stop or staleness', async () => {
    const [mapScript, riderScript, deliveryView] = await Promise.all([
        read('resources/js/rider-delivery-map.js'),
        read('resources/js/rider.js'),
        read('resources/views/rider/orders/delivery.blade.php'),
    ]);

    assert.match(mapScript, /bearly:rider-location-shared/);
    assert.match(mapScript, /bearly:rider-location-sharing-updated/);
    assert.match(mapScript, /detail\.sharing !== true/);
    assert.match(mapScript, /RIDER_FIX_FRESHNESS_MS = 180_000/);
    assert.match(mapScript, /L\.marker\(destination/);
    assert.match(mapScript, /deliveryPinIcon\('rider'/);
    assert.match(mapScript, /destinationGuideLayer\.setLatLngs\(\[fix\.coordinates, destination\]\)/);
    assert.match(mapScript, /riderTrailLayer\.setLatLngs/);
    assert.match(mapScript, /map\.panInside\(fix\.coordinates/);
    assert.match(mapScript, /MAX_TRAIL_ACCURACY_METERS = 250/);
    assert.match(mapScript, /element\.dataset\.deliveryActive === 'true'/);
    assert.match(mapScript, /The movement trail and destination guide appear after delivery starts/);
    assert.match(riderScript, /import '\.\/rider-delivery-map\.js'/);
    assert.match(deliveryView, /data-rider-delivery-map/);
    assert.match(deliveryView, /Buyer live GPS is not used/);
    assert.match(deliveryView, /data-latitude="\{\{ \$job\['destination_latitude'\]/);
    assert.match(mapScript, /No destination pin was saved with this \$\{destinationSource\}/);
    assert.match(deliveryView, /No destination pin was saved with this \{\{ \$job\['purpose'\]/);
    assert.match(deliveryView, /Movement trail · this page only/);
    assert.match(deliveryView, /Straight-line guide · not a road route/);
});
