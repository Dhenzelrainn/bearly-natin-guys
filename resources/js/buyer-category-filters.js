export function normalizeCategorySlug(slug) {
    const value = String(slug ?? '').trim().toLowerCase();
    const legacyMap = {
        mens: 'men-s-apparel',
        'mens-apparel': 'men-s-apparel',
        womens: 'women-s-apparel',
        'womens-apparel': 'women-s-apparel',
        'foods-and-gourmet': 'food-and-gourmet',
    };

    return legacyMap[value] ?? value;
}

export const defaults = (priceLimit = 5000) => ({
    search: '',
    subcategory: '',
    min: 0,
    max: priceLimit,
    size: [],
    color: [],
    condition: [],
    location: [],
    voucher: false,
    rating: 0,
    sort: 'featured',
    saved: false,
    view: 'grid',
});

export function matchesCatalogSubcategory(product, subcategory) {
    if (!subcategory) return false;

    const selected = String(subcategory);
    return String(product.marketplace_subcategory_slug || '') === selected ||
        String(product.marketplace_subcategory_id || '') === selected ||
        product.subcategory === selected ||
        (product.filter_groups || []).includes(selected);
}

export function readState(params, products, preserveUnknownFacets = false) {
    const priceLimit = Math.max(
        catalogPriceLimit(products),
        Number(params.get('min')) || 0,
        Number(params.get('max')) || 0,
    );
    const state = defaults(priceLimit);

    state.search = (params.get('q') || '').slice(0, 120);
    state.subcategory = preserveUnknownFacets || products.some(
        product => matchesCatalogSubcategory(product, params.get('sub'))
    )
        ? (params.get('sub') || '')
        : '';

    for (const key of ['size', 'color', 'condition', 'location']) {
        const valid = new Set(
            products.flatMap(product =>
                key === 'size'
                    ? product.sizes
                    : [product[key]]
            )
        );

        state[key] = [...new Set(params.getAll(key))]
            .filter(value => preserveUnknownFacets || valid.has(value));
    }

    for (const key of ['min', 'max']) {
        const value = Number(params.get(key) ?? state[key]);
        if (Number.isFinite(value)) {
            state[key] = Math.min(priceLimit, Math.max(0, value));
        }
    }

    if (state.min > state.max) {
        state.min = 0;
        state.max = priceLimit;
    }

    state.voucher = params.get('voucher') === '1';
    state.saved = params.get('saved') === '1';
    state.rating = params.get('rating') === '4' ? 4 : 0;
    state.sort = ['featured', 'newest', 'price-low', 'price-high'].includes(params.get('sort'))
        ? params.get('sort')
        : 'featured';
    state.view = params.get('view') === 'list' ? 'list' : 'grid';

    return state;
}

export function catalogPriceLimit(products) {
    return Math.max(5000, ...products.map(product =>
        Math.ceil((Number(product.price) || 0) / 1000) * 1000
    ));
}

export function normalizeCategoryFacets(facets = {}) {
    const values = input => {
        const seen = new Set();

        return (Array.isArray(input) ? input : [])
            .map(value => String(value ?? '').trim())
            .filter(value => {
                const key = value.toLocaleLowerCase();
                if (!value || seen.has(key)) return false;
                seen.add(key);
                return true;
            });
    };

    return {
        sizes: values(facets.sizes),
        colors: values(facets.colors),
        conditions: values(facets.conditions),
        locations: values(facets.locations),
        price_limit: Math.max(5000, Number(facets.price_limit) || 0),
    };
}

export function categoryResultsUrl(currentHref, endpointPath, category, page = 1) {
    const current = new URL(currentHref);
    const query = new URLSearchParams();
    const preservedFilters = [
        'q', 'sub', 'size', 'color', 'condition', 'location',
        'min', 'max', 'voucher', 'saved', 'rating', 'sort', 'view',
    ];

    for (const key of preservedFilters) {
        for (const value of current.searchParams.getAll(key)) {
            // PHP only parses query parameters as arrays when the key uses
            // bracket notation. The address bar can keep its compact form
            // (`color=Red`), while the JSON endpoint receives `color[]=Red`.
            const requestKey = ['size', 'color', 'condition', 'location'].includes(key)
                ? `${key}[]`
                : key;
            query.append(requestKey, value);
        }
    }

    query.set('category', normalizeCategorySlug(category) || category);
    query.set('page', String(Math.max(1, Number(page) || 1)));

    const endpoint = new URL(endpointPath, current.origin);
    endpoint.search = query;

    return endpoint;
}
