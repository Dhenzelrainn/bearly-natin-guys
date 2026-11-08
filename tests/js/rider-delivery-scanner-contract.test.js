import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [scanner, deliveryView, riderEntry, riderStyles] = await Promise.all([
    read('resources/js/rider-delivery-scanner.js'),
    read('resources/views/rider/orders/delivery.blade.php'),
    read('resources/js/rider.js'),
    read('resources/css/rider.css'),
]);

test('Rider delivery completion requires an explicit per-Parcel verification workspace', () => {
    assert.match(deliveryView, /data-rider-delivery-scan-form/);
    assert.match(deliveryView, /data-delivery-expected-parcel="\{\{ \$parcel\['parcel_no'\] \}\}"/);
    assert.match(deliveryView, /data-delivery-scan-progress/);
    assert.match(deliveryView, /Each successful scan is saved for this delivery and shown to Logistics/);
    assert.match(deliveryView, /data-delivery-verify-url/);
    assert.match(deliveryView, /data-delivery-progress-url/);
    assert.match(deliveryView, /data-delivery-submit-label>Confirm delivery/);
    assert.match(deliveryView, /Scanning verifies the Parcels; it does not complete delivery/);
});

test('Rider delivery scanner reuses stable exact Code 128 reads and accepts only assigned codes', () => {
    assert.match(riderEntry, /import '\.\/rider-delivery-scanner\.js'/);
    assert.match(scanner, /observeBarcodeRead\(candidateState, rawValue, performance\.now\(\)\)/);
    assert.match(scanner, /new window\.BarcodeDetector\(\{ formats: \['code_128'\] \}\)/);
    assert.match(scanner, /parcelItemsByCode\.has\(barcode\)/);
    assert.match(scanner, /method: 'POST'/);
    assert.match(scanner, /'X-CSRF-TOKEN': csrfToken\(\)/);
    assert.match(scanner, /data\?\.verified_parcel_nos/);
    assert.match(scanner, /setInterval\(refreshSavedProgress, 5000\)/);
    assert.match(scanner, /That exact Parcel barcode is not assigned to this delivery/);
    assert.match(scanner, /autofocus is optional/i);
    assert.doesNotMatch(scanner, /verified_parcel_barcodes|hiddenCode/);
    assert.doesNotMatch(scanner, /toUpperCase\(|toLowerCase\(|requestSubmit\(/);
});

test('Scanning never submits delivery automatically and multi-Parcel progress must be complete first', () => {
    assert.match(scanner, /if \(!allParcelsVerified\(\)\) \{\s*event\.preventDefault\(\)/);
    assert.match(scanner, /Verify all \$\{expectedCodes\.length\} assigned Parcel barcodes before confirming delivery/);
    assert.match(scanner, /All assigned Parcels are verified and saved[\s\S]*?separately confirm the handoff/);
    assert.doesNotMatch(scanner, /requestSubmit\(|form\.submit\(/);
});

test('Rider delivery scan progress remains responsive and supports the dark theme', () => {
    assert.match(riderStyles, /\.rider-delivery-scan-list/);
    assert.match(riderStyles, /:root\[data-theme="dark"\][\s\S]*?\.rider-delivery-scan-list/);
    assert.match(riderStyles, /@media \(max-width: 600px\)[\s\S]*?\.rider-delivery-scan \.waybill-scan-tools button/);
});
