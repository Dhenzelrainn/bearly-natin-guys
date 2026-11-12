import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [policyView, sellerLayout, sellerStyles] = await Promise.all([
    read('resources/views/seller/policies/required.blade.php'),
    read('resources/views/layouts/seller.blade.php'),
    read('resources/css/seller-polish.css'),
]);

test('Seller policy acknowledgment has a clearly labeled required checkbox and primary action', () => {
    assert.match(policyView, /class="seller-policy-content"\s*>\{\{ \$version->body \}\}/);
    assert.match(policyView, /<label class="seller-policy-consent" for="seller-policy-accepted">/);
    assert.match(policyView, /id="seller-policy-accepted"[\s\S]*?type="checkbox"[\s\S]*?required/);
    assert.match(policyView, /I have read and acknowledge this Seller policy\./);
    assert.match(policyView, /class="seller-primary-button" type="submit"/);
    assert.match(policyView, /class="field-error" role="alert"/);
    assert.match(policyView, /@if \(\$acknowledgmentRequired\)[\s\S]*?<form method="POST" action="\{\{ route\('seller\.policies\.accept'\) \}\}"/);
    assert.match(policyView, /@elseif \(! \$acknowledgmentRequired\)[\s\S]*?You acknowledged version/);
    assert.match(sellerLayout, /route\('seller\.policies\.current'\)[\s\S]*?<span>Seller Policies<\/span>/);
});

test('Seller policy acknowledgment is readable in light and dark themes and stacks on mobile', () => {
    assert.match(sellerStyles, /\.seller-policy-content\s*\{[\s\S]*?white-space:\s*pre-line;/);
    assert.match(sellerStyles, /:root\[data-theme="dark"\] body\.seller-body \.seller-policy-content\s*\{/);
    assert.match(sellerStyles, /\.seller-policy-acknowledged-notice\s*\{/);
    assert.match(
        sellerStyles,
        /@media \(max-width: 720px\)\s*\{[\s\S]*?\.seller-policy-consent-row\s*\{[\s\S]*?flex-direction:\s*column;/,
    );
});
