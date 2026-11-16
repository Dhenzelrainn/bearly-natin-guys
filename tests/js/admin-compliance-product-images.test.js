import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');
const [partial, overview, violations, css] = await Promise.all([
    read('resources/views/admin/compliance/partials/product-image.blade.php'),
    read('resources/views/admin/compliance/index.blade.php'),
    read('resources/views/admin/compliance/violations.blade.php'),
    read('resources/css/admin.css'),
]);

test('the overview, violation table, and modal all use the same uploaded-image partial', () => {
    assert.match(overview, /@include\('admin\.compliance\.partials\.product-image'/);
    assert.equal((violations.match(/@include\('admin\.compliance\.partials\.product-image'/g) || []).length, 2);
    assert.match(violations, /'preview' => true/);
    assert.match(partial, /src="\{\{ \$imageUrl \}\}"/);
    assert.match(partial, /alt="\{\{ \$imageAlt \}\}"/);
    assert.match(partial, /loading="lazy"/);
    assert.match(partial, /aria-label="Product image unavailable"/);
});

test('a failed image load shows the fallback instead of a broken image or unrelated stock photo', () => {
    const handler = partial.match(/onerror="([^"]+)"/)[1];
    const image = { hidden: false, nextElementSibling: { hidden: true } };
    new Function(handler).call(image);
    assert.equal(image.hidden, true);
    assert.equal(image.nextElementSibling.hidden, false);
    assert.doesNotMatch(handler, /\.src\s*=/);
});

test('product photos preserve their full contents and the modal preview fits its container', () => {
    assert.match(css, /\.admin-body \.admin-compliance-product-image > img\s*\{[^}]*object-fit:\s*contain;/s);
    assert.match(css, /\.admin-body \.admin-compliance-product-image\.is-preview\s*\{[^}]*width:\s*100%;[^}]*max-width:\s*196px;[^}]*aspect-ratio:\s*1;/s);
    assert.match(css, /\.admin-body \.admin-compliance-product-image\s*\{[^}]*var\(--theme-border\)[^}]*var\(--theme-surface\)/s);
});
