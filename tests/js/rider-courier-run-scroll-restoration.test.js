import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const [riderScript, courierView, riderController] = await Promise.all([
    read('resources/js/rider.js'),
    read('resources/views/rider/dashboard/courier.blade.php'),
    read('app/Http/Controllers/RiderController.php'),
]);

test('Courier run actions restore the same run-card viewport after successful redirects', () => {
    assert.match(courierView, /data-courier-run-dashboard/);
    assert.match(courierView, /data-courier-run-feedback="success"/);
    assert.match(courierView, /data-courier-run-card="\{\{ \$run->id \}\}"/);
    assert.equal([...courierView.matchAll(/data-courier-run-action/g)].length, 6);
    assert.equal([...courierView.matchAll(/data-single-submit/g)].length, 6);

    assert.match(riderScript, /COURIER_RUN_SCROLL_KEY/);
    assert.match(riderScript, /sessionStorage\.setItem\(COURIER_RUN_SCROLL_KEY/);
    assert.match(riderScript, /sessionStorage\.getItem\(COURIER_RUN_SCROLL_KEY/);
    assert.match(riderScript, /runCard\.getBoundingClientRect\(\)\.top/);
    assert.match(riderScript, /setupCourierRunScrollRestoration\(\);/);
    assert.match(riderScript, /sidebarCollapsed: document\.body\.classList\.contains\('sidebar-collapsed'\)/);
    assert.match(riderScript, /body\.classList\.toggle\('sidebar-collapsed', sidebarCollapsed\)/);
    assert.match(riderScript, /'bearlyRiderSidebar',[\s\S]*?sidebarCollapsed \? 'collapsed' : 'open'/);

    assert.equal(
        [...riderController.matchAll(/with\('courierRunActionComplete', true\)/g)].length,
        6,
    );
});
