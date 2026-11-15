import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { storeVoucherClaimPayload } from '../../resources/js/store-vouchers.js';

const read = relativePath => readFileSync(fileURLToPath(new URL(relativePath, import.meta.url)), 'utf8');

const storeView = read('../../resources/views/buyer/stores/show.blade.php');
const storeVoucherScript = read('../../resources/js/store-vouchers.js');
const buyerScript = read('../../resources/js/buyer.js');
const buyerStyles = read('../../resources/css/buyer.css');

test('the store page renders compact voucher cards with the existing claim endpoint', () => {
    assert.match(
        storeView,
        /data-store-vouchers data-store-voucher-claim-url="\{\{ route\('buyer\.vouchers\.claim'\) \}\}"/,
    );
    assert.match(
        storeView,
        /data-store-voucher-claim data-store-promotion-id="\{\{ \$voucher\['id'\] \}\}"/,
    );
    assert.match(storeView, /data-store-voucher-feedback role="status" aria-live="polite"/);
    assert.match(storeView, /\.store-voucher \{ display:grid; grid-template-columns:auto minmax\(0,1fr\) auto;/);
    assert.doesNotMatch(storeView, /class="voucher-card"/);
});

test('the store voucher helper uses the authenticated claim contract and reports failures', () => {
    assert.match(storeVoucherScript, /store_promotion_id: id/);
    assert.match(storeVoucherScript, /'X-CSRF-TOKEN': csrfToken\(\)/);
    assert.match(storeVoucherScript, /button\.disabled = true/);
    assert.match(storeVoucherScript, /Object\.values\(result\.errors \|\| \{\}\)\.flat\(\)\.find\(Boolean\)/);
    assert.match(storeVoucherScript, /bearly:vouchers-changed/);
    assert.match(storeVoucherScript, /feedback\.dataset\.tone = 'error'/);
});

test('the Buyer bundle initializes storefront voucher claiming', () => {
    assert.match(buyerScript, /import \{ initStoreVouchers \} from '\.\/store-vouchers\.js';/);
    assert.match(buyerScript, /initStoreVouchers\(\);/);
});

test('store voucher claim payloads accept database ids and reject invalid ids', () => {
    assert.deepEqual(storeVoucherClaimPayload('12'), { store_promotion_id: 12 });
    assert.throws(() => storeVoucherClaimPayload(0), /valid store voucher/);
    assert.throws(() => storeVoucherClaimPayload('not-an-id'), /valid store voucher/);
});

test('store voucher cards keep readable dark-theme surfaces and feedback tones', () => {
    assert.match(
        buyerStyles,
        /:root\[data-theme="dark"\] body\.store-page \.store-promo,[\s\S]*?\.store-voucher\s*\{[^}]*background:\s*var\(--theme-surface\)/,
    );
    assert.match(buyerStyles, /:root\[data-theme="dark"\] body\.store-page \.store-voucher-value\s*\{[^}]*color:\s*#efbd71/);
    assert.match(buyerStyles, /:root\[data-theme="dark"\] body\.store-page \.store-voucher-feedback\[data-tone="error"\]\s*\{[^}]*color:\s*#f0a58f/);
});
