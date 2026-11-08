import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [scanner, intakeView, logisticsStyles, transfersView, riderPickupView, scannerStyles, decoderUtils] = await Promise.all([
    read('resources/js/waybill-scanner.js'),
    read('resources/views/logistics/sorting/incoming.blade.php'),
    read('resources/css/logistics.css'),
    read('resources/views/logistics/transfers/index.blade.php'),
    read('resources/views/rider/orders/pickup.blade.php'),
    read('resources/css/waybill-scanner.css'),
    read('resources/js/waybill-scanner-utils.js'),
]);

test('Logistics intake loads provider-scoped waybill parcel data before submission', () => {
    assert.match(intakeView, /data-waybill-lookup-url="\{\{ route\('logistics\.waybills\.lookup'/);
    assert.match(scanner, /credentials: 'same-origin'/);
    assert.match(scanner, /cache: 'no-store'/);
    assert.match(scanner, /encodeURIComponent\(identifier\)/);
    assert.match(scanner, /loadedIdentifier !== input\.value\.trim\(\)/);
});

test('The selected physical parcel gets a required size selector using safe DOM text rendering', () => {
    assert.match(intakeView, /data-parcel-size-options/);
    assert.match(intakeView, /Package size for the selected parcel/);
    assert.match(scanner, /select\.name = `parcel_size_classes\[\$\{parcel\.id\}\]`/);
    assert.match(scanner, /select\.required = true/);
    assert.match(scanner, /parcelNumber\.textContent = parcel\.parcel_no/);
    assert.match(scanner, /sizeList\.replaceChildren\(fragment\)/);
    assert.match(logisticsStyles, /\.waybill-parcel-size-row\s*\{/);
    assert.match(logisticsStyles, /\.waybill-parcel-size-control select:focus-visible/);
});

test('Camera, keyboard barcode, and manual lookup all load parcel size rows', () => {
    assert.match(scanner, /await loadParcels\(value\)/);
    assert.match(scanner, /event\.key === 'Enter'[\s\S]*?loadParcels\(input\.value\)/);
    assert.match(scanner, /loadButton\?\.addEventListener\('click', \(\) => loadParcels\(input\.value\)\)/);
});

test('Rider pickup camera scans the printed Code 128 Parcel barcode', () => {
    assert.match(riderPickupView, /data-waybill-scan-form/);
    assert.match(riderPickupView, /Printed Parcel barcode \(Code 128\):/);
    assert.match(riderPickupView, />Scan Parcel<\/button>/);
    assert.match(scanner, /new window\.BarcodeDetector\(\{ formats: \['code_128'\] \}\)/);
    assert.match(scanner, /input\.value = value;\s*method\.value = 'camera'/);
});

test('Rider camera scan button is full-width and visible on narrow mobile screens', () => {
    assert.match(scannerStyles, /@media\(max-width:480px\)[\s\S]*?\.rider-waybill-scan-field \.waybill-scan-tools\{display:grid/);
    assert.match(scannerStyles, /\.rider-waybill-scan-field \.waybill-camera-button\{display:inline-flex;width:100%/);
});

test('camera scanning requests rear high-resolution video and optional continuous autofocus', () => {
    assert.match(scanner, /facingMode:\s*\{ ideal: 'environment' \}/);
    assert.match(scanner, /width:\s*\{ ideal: 1920 \}/);
    assert.match(scanner, /height:\s*\{ ideal: 1080 \}/);
    assert.match(scanner, /focusModes\.includes\('continuous'\)/);
    assert.match(scanner, /track\.applyConstraints\(\{ advanced: \[\{ focusMode: 'continuous' \}\] \}\)/);
});

test('camera decoder requires stable valid reads and keeps intake scanning after a failed lookup', () => {
    assert.match(scanner, /observeBarcodeRead\(candidateState, rawValue, performance\.now\(\)\)/);
    assert.match(decoderUtils, /REQUIRED_STABLE_BARCODE_READS = 3/);
    assert.match(decoderUtils, /!\/\^\[a-z\\d-\]\+\$\/i\.test\(raw\)/);
    assert.match(scanner, /const loaded = await loadParcels\(value\);[\s\S]*?if \(loaded\) \{\s*stop\(\);\s*return;\s*\}[\s\S]*?Could not confirm that code[\s\S]*?Move the barcode out of view/);
});

test('City handoff scanning previews the parent Waybill before explicit whole-shipment acceptance', () => {
    assert.match(transfersView, /data-waybill-scan-form/);
    assert.doesNotMatch(transfersView, /data-waybill-lookup-url/);
    assert.match(transfersView, /data-handoff-lookup-url="\{\{ route\('logistics\.transfers\.lookup'/);
    assert.match(transfersView, /data-handoff-preview/);
    assert.match(transfersView, /data-handoff-preview-count/);
    assert.match(transfersView, /data-handoff-accept-label>Review Waybill/);
    assert.match(transfersView, /receives every parcel in the shipment, not only the scanned Parcel/);
    assert.match(scanner, /const requiresIntakeLookup = Boolean\(lookupUrl && sizeList\)/);
    assert.match(scanner, /const requiresHandoffLookup = Boolean\(handoffLookupUrl && form\.hasAttribute\('data-handoff-accept-form'\)\)/);
    assert.match(scanner, /url\.searchParams\.set\('identifier', identifier\)/);
    assert.match(scanner, /data\.matched_by === 'parcel_barcode'/);
    assert.match(scanner, /setHandoffAcceptLabel\('Accept all parcels'\)/);
    assert.match(scanner, /response\.status === 404[\s\S]*?No eligible incoming Waybill was found for that code at this destination/);
    assert.match(scanner, /if \(handoffPreviewIdentifier === input\.value\.trim\(\) && handoffPreviewData\) return;\s*event\.preventDefault\(\);\s*await lookupIncomingHandoff\(input\.value\);\s*return;/);
    assert.doesNotMatch(scanner, /handoffForm\.requestSubmit/);
});

test('failed handoff camera lookup leaves the camera running for another scan', () => {
    assert.match(scanner, /const preview = await lookupIncomingHandoff\(value\);\s*\n\s*if \(!scanning\) return;\s*\n\s*if \(preview\) \{\s*stop\(\);[\s\S]*?No eligible incoming Waybill was found/);
    assert.match(scanner, /if \(requiresHandoffLookup\) clearHandoffPreview\(\)/);
    assert.match(scanner, /setHandoffStatus\(error\?\.message \|\| 'Could not find an incoming Waybill/);
});

test('The incoming Waybill queue selects and focuses the single acceptance form', () => {
    assert.match(transfersView, /<h3>Courier Run Status<\/h3>/);
    assert.match(transfersView, /<h3>Waybills Awaiting Acceptance<\/h3>/);
    assert.match(transfersView, /data-handoff-waybill-prefill=/);
    assert.match(scanner, /document\.querySelectorAll\('\[data-handoff-waybill-prefill\]'\)/);
    assert.match(scanner, /handoffInput\.value = waybill/);
    assert.match(scanner, /handoffForm\.scrollIntoView\(\{ behavior: 'smooth', block: 'center' \}\)/);
    assert.match(scanner, /handoffInput\.focus\(\{ preventScroll: true \}\)/);
});
