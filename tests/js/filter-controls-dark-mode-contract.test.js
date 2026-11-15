import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [adminCss, themeCss, adminLayout, logisticsLayout, riderLayout, sellerLegibility] = await Promise.all([
    read('resources/css/admin.css'),
    read('resources/css/theme.css'),
    read('resources/views/layouts/admin.blade.php'),
    read('resources/views/logistics/layouts/app.blade.php'),
    read('resources/views/rider/layouts/app.blade.php'),
    read('resources/css/seller-legibility.css'),
]);

test('Admin and ops filter controls avoid duplicate search outlines and light select chrome', () => {
    assert.match(adminCss, /\.admin-body[\s\S]*?input\[type="search"\]:focus-visible[\s\S]*?outline:\s*none/);
    assert.match(themeCss, /body\.admin-body[\s\S]*?input\[type="search"\]:focus-visible[\s\S]*?outline:\s*none !important/);
    assert.match(themeCss, /body\.admin-body :is\(\.admin-search, \.field-with-icon\) input\[type="search"\][\s\S]*?background-color:\s*transparent !important/);
    assert.match(themeCss, /body\.admin-body select:not\(\[multiple\]\):not\(\[size\]\)[\s\S]*?appearance:\s*none !important/);
    assert.match(themeCss, /body\.ops-body select:not\(\[multiple\]\):not\(\[size\]\)[\s\S]*?background-image:/);
    assert.match(sellerLegibility, /input\[type="search"\], select\)[\s\S]*?outline:\s*none !important/);

    for (const layout of [adminLayout, logisticsLayout, riderLayout]) {
        assert.match(layout, /select:not\(\[multiple\]\):not\(\[size\]\)/);
        assert.match(layout, /background-image: url\("data:image\/svg\+xml/);
        assert.match(layout, /input(?:\[type="search"\])?:focus-visible/);
        assert.match(layout, /outline: none !important/);
    }

    assert.match(adminLayout, /body\.admin-body :is\(\.admin-search, \.field-with-icon\) input\[type="search"\][\s\S]*?background-color: transparent !important/);
});
