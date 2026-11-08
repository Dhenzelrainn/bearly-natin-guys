import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const [buyerView, logisticsView, riderView, buyerScript, logisticsScript, riderScript] = await Promise.all([
    readFile(new URL('../../resources/views/auth/register.blade.php', import.meta.url), 'utf8'),
    readFile(new URL('../../resources/views/logistics/applications/create.blade.php', import.meta.url), 'utf8'),
    readFile(new URL('../../resources/views/rider/applications/create.blade.php', import.meta.url), 'utf8'),
    readFile(new URL('../../resources/js/registration-email.js', import.meta.url), 'utf8'),
    readFile(new URL('../../resources/js/logistics.js', import.meta.url), 'utf8'),
    readFile(new URL('../../resources/js/rider.js', import.meta.url), 'utf8'),
]);

test('All registration flows expose the server-side email-proof status endpoint', () => {
    for (const view of [buyerView, logisticsView, riderView]) {
        assert.match(view, /data-email-status="\{\{ route\('register\.email\.status'\) \}\}"/);
    }
});

test('All registration email widgets restore proof and keep submission pending during the check', () => {
    for (const script of [buyerScript, logisticsScript, riderScript]) {
        assert.match(script, /async function restoreVerifiedProof\(\)/);
        assert.match(script, /form\.dataset\.emailStatus/);
        assert.match(script, /void restoreVerifiedProof\(\)/);
        assert.match(script, /Checking whether this email is already verified\. Please wait\./);
    }
});
