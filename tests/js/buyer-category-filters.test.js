import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import {
    categoryResultsUrl,
    defaults,
    normalizeCategoryFacets,
    readState,
} from '../../resources/js/buyer-category-filters.js';

const read = relativePath => readFileSync(fileURLToPath(new URL(relativePath, import.meta.url)), 'utf8');
const buyerJs = read('../../resources/js/buyer.js');
const categoryViewsPath = fileURLToPath(new URL('../../resources/views/buyer/Category/', import.meta.url));

function filesBelow(directory) {
    return readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
        const path = `${directory}/${entry.name}`;
        return entry.isDirectory() ? filesBelow(path) : [path];
    });
}

test('category filters preserve valid full-catalog query state before the first result page arrives', () => {
    const state = readState(
        new URLSearchParams('color=Cerulean&size=One%20Size&location=Santa%20Rosa%2C%20Laguna&rating=4&voucher=1'),
        [],
        true,
    );

    assert.deepEqual(state.color, ['Cerulean']);
    assert.deepEqual(state.size, ['One Size']);
    assert.deepEqual(state.location, ['Santa Rosa, Laguna']);
    assert.equal(state.rating, 4);
    assert.equal(state.voucher, true);
    assert.equal(Object.hasOwn(defaults(), 'shipping'), false);
});

test('category facets retain newly-added values and de-duplicate case-insensitively', () => {
    const facets = normalizeCategoryFacets({
        sizes: ['Small', 'One Size', 'small'],
        colors: ['Red', 'Cerulean', 'cerulean'],
        conditions: ['New'],
        locations: ['Santa Rosa, Laguna'],
        price_limit: 18000,
    });

    assert.deepEqual(facets.sizes, ['Small', 'One Size']);
    assert.deepEqual(facets.colors, ['Red', 'Cerulean']);
    assert.deepEqual(facets.conditions, ['New']);
    assert.deepEqual(facets.locations, ['Santa Rosa, Laguna']);
    assert.equal(facets.price_limit, 18000);
});

test('category results pagination encodes array facets for PHP and replaces stale page/shipping parameters', () => {
    const url = categoryResultsUrl(
        'https://bearly.test/products?category=electronics-and-gadgets&color=Cerulean&size=Large&condition=New&location=Santa%20Rosa%2C%20Laguna&min=1000&shipping=1&page=8&debug=secret',
        '/products/category-data',
        'electronics-and-gadgets',
        2,
    );

    assert.equal(url.pathname, '/products/category-data');
    assert.deepEqual(url.searchParams.getAll('color[]'), ['Cerulean']);
    assert.deepEqual(url.searchParams.getAll('size[]'), ['Large']);
    assert.deepEqual(url.searchParams.getAll('condition[]'), ['New']);
    assert.deepEqual(url.searchParams.getAll('location[]'), ['Santa Rosa, Laguna']);
    assert.equal(url.searchParams.has('color'), false);
    assert.equal(url.searchParams.has('size'), false);
    assert.equal(url.searchParams.has('condition'), false);
    assert.equal(url.searchParams.has('location'), false);
    assert.equal(url.searchParams.get('min'), '1000');
    assert.equal(url.searchParams.get('page'), '2');
    assert.equal(url.searchParams.has('shipping'), false);
    assert.equal(url.searchParams.has('debug'), false);
});

test('every Buyer category uses server-backed results and omits Free Shipping from product filters', () => {
    const filterFiles = filesBelow(categoryViewsPath).filter(path => path.endsWith('-filter.blade.php'));
    assert.ok(filterFiles.length >= 10, 'expected all category filter partials to be covered');

    for (const path of filterFiles) {
        const filterMarkup = readFileSync(path, 'utf8');
        assert.doesNotMatch(filterMarkup, /Free shipping|data-field="shipping"/i, path);
        assert.match(filterMarkup, /data-field="voucher"/, path);
    }

    assert.match(buyerJs, /fetch\(endpoint,\s*\{/);
    assert.match(buyerJs, /from '\.\/buyer-category-filters\.js'/);
    assert.match(buyerJs, /data\.pagination\?\.has_more/);
    assert.match(buyerJs, /append\s*\?\s*\[\.\.\.products,\s*\.\.\.nextProducts/);
    assert.match(buyerJs, /aria-busy/);
});
