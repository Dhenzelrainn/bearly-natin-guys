import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const [confirmationView, profileView, purchasesScript] = await Promise.all([
    readFile(new URL('../../resources/views/buyer/checkout-confirmation.blade.php', import.meta.url), 'utf8'),
    readFile(new URL('../../resources/views/buyer/profile.blade.php', import.meta.url), 'utf8'),
    readFile(new URL('../../resources/js/my-purchases.js', import.meta.url), 'utf8'),
]);

test('order confirmation returns Buyers to My Purchases with To Receive selected', () => {
    assert.match(
        confirmationView,
        /route\('buyer\.profile', \['purchase_filter' => 'to-receive'\]\) }}#purchases/,
    );
    assert.match(confirmationView, /> Back to To Receive Orders<\/a>/);
    assert.match(
        profileView,
        /data-initial-purchase-filter="\{\{ request\(\)->query\('purchase_filter'\) === 'to-receive' \? 'to-receive' : 'all' \}\}"/,
    );
    assert.match(purchasesScript, /function initialPurchaseFilter\(\)[\s\S]*?dataset\.initialPurchaseFilter[\s\S]*?return \['all','to-pay','to-ship','to-receive','completed','cancelled'\]\.includes\(requested\)\?requested:'all'/);
    assert.match(purchasesScript, /const selected=b\.dataset\.orderFilter===purchaseFilter;[\s\S]*?b\.setAttribute\('aria-selected',selected\?'true':'false'\)/);
});
