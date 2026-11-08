import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [scanner, intakeView, logisticsStyles, transfersView] = await Promise.all([
    read('resources/js/waybill-scanner.js'),
    read('resources/views/logistics/sorting/incoming.blade.php'),
    read('resources/css/logistics.css'),
    read('resources/views/logistics/transfers/index.blade.php'),
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

test('City handoff acceptance bypasses the intake-only parcel lookup and submits normally', () => {
    assert.match(transfersView, /data-waybill-scan-form/);
    assert.doesNotMatch(transfersView, /data-waybill-lookup-url/);
    assert.match(scanner, /const requiresIntakeLookup = Boolean\(lookupUrl && sizeList\)/);
    assert.match(scanner, /form\.addEventListener\('submit', async \(event\) => \{\s*if \(!requiresIntakeLookup\) return;/);
    assert.match(scanner, /if \(requiresIntakeLookup\) \{\s*event\.preventDefault\(\);\s*loadParcels\(input\.value\);/);
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
