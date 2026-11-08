import test from 'node:test';
import assert from 'node:assert/strict';
import {
    createBarcodeReadState,
    observeBarcodeRead,
    REQUIRED_STABLE_BARCODE_READS,
} from '../../resources/js/waybill-scanner-utils.js';

test('a camera barcode is accepted only after three matching valid reads', () => {
    const state = createBarcodeReadState();
    const code = `PCL-${'A'.repeat(20)}`;

    assert.equal(observeBarcodeRead(state, code, 100).stable, false);
    assert.equal(observeBarcodeRead(state, code, 220).stable, false);
    const thirdRead = observeBarcodeRead(state, code, 340);

    assert.equal(REQUIRED_STABLE_BARCODE_READS, 3);
    assert.equal(thirdRead.value, code);
    assert.equal(thirdRead.reads, 3);
    assert.equal(thirdRead.stable, true);
});

test('a different read restarts the stable-read count', () => {
    const state = createBarcodeReadState();

    observeBarcodeRead(state, 'PCL-AAAAAAAAAAAAAAAAAAAA', 100);
    observeBarcodeRead(state, 'PCL-AAAAAAAAAAAAAAAAAAAA', 220);
    const changedRead = observeBarcodeRead(state, 'PCL-BBBBBBBBBBBBBBBBBBBB', 340);

    assert.equal(changedRead.value, 'PCL-BBBBBBBBBBBBBBBBBBBB');
    assert.equal(changedRead.reads, 1);
    assert.equal(changedRead.stable, false);
});

test('malformed camera output is rejected without guessing character substitutions', () => {
    const state = createBarcodeReadState();
    const malformed = observeBarcodeRead(state, 'PCL-OSBNAASEX7QQ*PX7O7', 100);

    assert.equal(malformed.value, '');
    assert.equal(malformed.invalid, true);
    assert.equal(malformed.stable, false);
    assert.equal(state.value, '');

    const validAmbiguousCharacters = observeBarcodeRead(state, 'PCL-O0I1', 200);
    assert.equal(validAmbiguousCharacters.value, 'PCL-O0I1');
    assert.equal(validAmbiguousCharacters.invalid, false);
});

test('a long gap or empty frame resets the candidate before another scan', () => {
    const state = createBarcodeReadState();
    const code = 'PCL-AAAAAAAAAAAAAAAAAAAA';

    observeBarcodeRead(state, code, 100);
    const emptyRead = observeBarcodeRead(state, '', 1500);
    const nextRead = observeBarcodeRead(state, code, 1600);

    assert.equal(emptyRead.reads, 0);
    assert.equal(nextRead.reads, 1);
    assert.equal(nextRead.stable, false);
});
