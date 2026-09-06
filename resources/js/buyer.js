/* BEARLY shared buyer JavaScript: homepage + legacy page + category V2. */
/* Frontend-only catalog preview. No real checkout/account mutations yet. */

export function selectProducts(products, { category = '', search = '', sort = 'featured' } = {}) {
    const query = search.trim().toLocaleLowerCase();
    const selected = products.filter(
        p =>
            (!category || p.category_slug === category) &&
            (!query || `${p.name} ${p.category}`.toLocaleLowerCase().includes(query))
    );

    if (sort === 'price-low') selected.sort((a, b) => a.price - b.price);
    if (sort === 'price-high') selected.sort((a, b) => b.price - a.price);
    if (sort === 'name') selected.sort((a, b) => a.name.localeCompare(b.name));

    return selected;
}

export function photoPosition(index) {
    return {
        x: (index % 4) * 100 / 3,
        y: Math.floor(index / 4) * 100 / 3,
    };
}

const escapeHtml = value =>
    String(value).replace(
        /[&<>"']/g,
        char =>
            ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
            })[char]
    );

const peso = value =>
    new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        maximumFractionDigits: 0,
    }).format(value);

if (typeof document !== 'undefined') {
    initialize();
}

function initialize() {
    const dataElement = document.getElementById('home-data');
    if (!dataElement) return;

    const { categories, products } = JSON.parse(dataElement.textContent);
    const $ = id => document.getElementById(id);

    const state = {
        category: '',
        search: '',
        sort: 'featured',
        limit: 20,
    };

    let previousFocus = null;

    const categoryLabel = slug =>
        categories.find(category => category.slug === slug)?.name || '';

    const photo = product => {
        const { x, y } = photoPosition(product.photo);

        return `
            <span
                class="product-photo"
                style="--x:${x}%;--y:${y}%"
                role="img"
                aria-label="${escapeHtml(product.name)}"
            ></span>
        `;
    };

    const card = product => `
        <article class="product-card">
            <button
                class="product-open"
                data-product="${product.id}"
                aria-label="View ${escapeHtml(product.name)}"
            >
                ${photo(product)}
                <div class="product-copy">
                    <h3>${escapeHtml(product.name)}</h3>
                    <span class="product-category">${escapeHtml(product.category)}</span>
                    <strong class="product-price">${peso(product.price)}</strong>
                    <span class="view-product">
                        View product
                        <span aria-hidden="true">→</span>
                    </span>
                </div>
            </button>
        </article>
    `;

    $('category-nav').innerHTML = categories
        .map(
            category => `
                <button
                    class="category-button"
                    data-category="${escapeHtml(category.slug)}"
                    aria-pressed="false"
                >
                    <span class="material-symbols-outlined" aria-hidden="true">
                        ${escapeHtml(category.icon)}
                    </span>
                    <span>${escapeHtml(category.name)}</span>
                    <span class="chevron" aria-hidden="true">›</span>
                </button>
            `
        )
        .join('');

    for (const category of categories) {
        $('search-category').add(new Option(category.name, category.slug));
    }

    function readUrl() {
        const params = new URLSearchParams(location.search);
        const category = params.get('category') || '';

        state.category = categories.some(item => item.slug === category)
            ? category
            : '';

        state.search = (params.get('search') || '').slice(0, 120);

        state.sort = ['featured', 'price-low', 'price-high', 'name'].includes(
            params.get('sort')
        )
            ? params.get('sort')
            : 'featured';

        state.limit = 20;

        $('search-category').value = state.category;
        $('search-input').value = state.search;
        $('sort').value = state.sort;
    }

    function updateUrl() {
        const url = new URL(location.href);

        for (const key of ['category', 'search', 'sort']) {
            if (state[key] && state[key] !== 'featured') {
                url.searchParams.set(key, state[key]);
            } else {
                url.searchParams.delete(key);
            }
        }

        url.hash = 'results';
        history.pushState({}, '', url);
    }

    function render() {
        const filtered = Boolean(
            state.category ||
            state.search ||
            state.sort !== 'featured'
        );

        const results = selectProducts(products, state);
        const visible = results.slice(0, state.limit);

        $('editorial').hidden = filtered;
        $('outdoor').hidden = filtered;
        $('more-section').hidden = filtered || visible.length <= 12;
        $('results-tools').hidden = !filtered;

        $('results-title').textContent = state.category
            ? categoryLabel(state.category)
            : state.search
                ? 'Search results'
                : 'Daily discoveries';

        $('results-caption').textContent = filtered
            ? `${results.length} sample ${results.length === 1 ? 'find' : 'finds'}${
                state.search ? ` for “${state.search}”` : ''
            }`
            : 'Find something good across Bearly.';

        $('product-grid').innerHTML = (
            filtered ? visible : visible.slice(0, 12)
        )
            .map(card)
            .join('');

        $('more-grid').innerHTML = filtered
            ? ''
            : visible.slice(12).map(card).join('');

        $('empty').hidden = results.length !== 0;
        $('load-more').hidden = visible.length >= results.length;

        $('result-count').textContent =
            `Showing ${visible.length} of ${results.length} sample products`;

        $('view-all').hidden = !filtered;
        $('view-all').textContent = 'Clear all';

        $('active-filters').innerHTML = [
            state.category
                ? `<button class="chip" data-clear="category">${escapeHtml(
                    categoryLabel(state.category)
                )} ×</button>`
                : '',
            state.search
                ? `<button class="chip" data-clear="search">${escapeHtml(
                    state.search
                )} ×</button>`
                : '',
        ].join('');

        document
            .querySelectorAll('.category-button')
            .forEach(button =>
                button.setAttribute(
                    'aria-pressed',
                    String(button.dataset.category === state.category)
                )
            );
    }

    function change() {
        state.limit = 20;
        updateUrl();
        render();
        $('results').scrollIntoView({ block: 'start' });
    }

    function reset() {
        state.category = '';
        state.search = '';
        state.sort = 'featured';

        $('search-category').value = '';
        $('search-input').value = '';
        $('sort').value = 'featured';

        change();
    }

    function menu(open) {
        const sidebar = $('sidebar');

        if (open) {
            previousFocus = document.activeElement;
        }

        sidebar.classList.toggle('is-open', open);
        $('sidebar-backdrop').hidden = !open;
        $('menu-toggle').setAttribute('aria-expanded', String(open));
        document.body.classList.toggle('menu-open', open);

        if (window.matchMedia('(max-width:720px)').matches) {
            sidebar.inert = !open;
        }

        if (open) {
            $('menu-close').focus();
        } else {
            previousFocus?.focus();
        }
    }

    const mobileQuery = window.matchMedia('(max-width:720px)');

    function syncSidebar() {
        if (!mobileQuery.matches) {
            $('sidebar').inert = false;
            menu(false);
        } else {
            $('sidebar').inert = !$('sidebar').classList.contains('is-open');
        }
    }

    mobileQuery.addEventListener('change', syncSidebar);
    syncSidebar();

    $('menu-toggle').addEventListener('click', () => menu(true));
    $('menu-close').addEventListener('click', () => menu(false));
    $('sidebar-backdrop').addEventListener('click', () => menu(false));

    document.addEventListener('keydown', event => {
        if (!$('sidebar').classList.contains('is-open')) return;

        if (event.key === 'Escape') {
            menu(false);
            return;
        }

        if (event.key === 'Tab') {
            const focusable = [
                ...$('sidebar').querySelectorAll('button,a[href]'),
            ].filter(element => element.getClientRects().length);

            const first = focusable[0];
            const last = focusable.at(-1);

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    });

    $('search-form').addEventListener('submit', event => {
        event.preventDefault();

        state.category = $('search-category').value;
        state.search = $('search-input').value.trim();

        change();
    });

    $('sort').addEventListener('change', () => {
        state.sort = $('sort').value;
        change();
    });

    $('view-all').addEventListener('click', reset);
    $('reset-search').addEventListener('click', reset);

    $('load-more').addEventListener('click', () => {
        const count = selectProducts(products, state).slice(
            0,
            state.limit
        ).length;

        state.limit += 8;
        render();

        const cards = document.querySelectorAll('.product-open');
        cards[count]?.focus({ preventScroll: true });
    });

    document.addEventListener('click', event => {
        const category = event.target.closest('[data-category]');

        if (category) {
            if (category.dataset.category === 'men-s-apparel') {
                event.preventDefault();
                window.location.href = '/products?category=men-s-apparel';
                return;
            }

            state.category = category.dataset.category;
            state.search = '';
            state.sort = 'featured';

            $('search-category').value = state.category;
            $('search-input').value = '';
            $('sort').value = 'featured';

            if ($('sidebar').classList.contains('is-open')) {
                menu(false);
            }

            change();
        }

        const clear = event.target.closest('[data-clear]');

        if (clear) {
            state[clear.dataset.clear] = '';
            $('search-category').value = state.category;
            $('search-input').value = state.search;
            change();
        }

        const productButton = event.target.closest('[data-product]');

        if (productButton) {
            const product = products.find(
                item => item.id === Number(productButton.dataset.product)
            );

            if (!product) return;

            $('product-detail').innerHTML = `
                <div class="detail-grid">
                    ${photo(product)}
                    <div>
                        <p class="eyebrow">${escapeHtml(product.category)}</p>
                        <h2 id="product-title">${escapeHtml(product.name)}</h2>
                        <strong class="product-price">${peso(product.price)}</strong>
                        <p>${escapeHtml(product.description)}</p>
                        <p>
                            Color: ${escapeHtml(product.color)}
                            ${
                                product.sizes.length
                                    ? `<br>Sample sizes: ${product.sizes
                                        .map(escapeHtml)
                                        .join(', ')}`
                                    : ''
                            }
                        </p>
                        <small>
                            Sample listing · Ordering is not enabled for preview products.
                        </small>
                    </div>
                </div>
            `;

            $('product-dialog').showModal();
        }

        const info = event.target.closest('[data-info]');

        if (info) {
            const copy = {
                orders: [
                    'Your orders',
                    'Order tracking will connect to your buyer account in the next integration. This homepage preview does not create or display real orders.',
                ],
                chat: [
                    'Chat with sellers',
                    'Seller messaging will connect to your buyer account. No messages are sent from this homepage preview.',
                ],
                about: [
                    'A find for everyone',
                    'Bearly brings twelve shopping categories together, from everyday essentials to your next favorite find.',
                ],
                help: [
                    'How can we help?',
                    'Browse a category, search for a product, or open a product card for a closer look. Sample listings cannot be purchased.',
                ],
            }[info.dataset.info];

            if (copy) {
                $('info-title').textContent = copy[0];
                $('info-copy').textContent = copy[1];
                $('info-dialog').showModal();
            }
        }

        const close = event.target.closest('[data-close]');

        if (close) {
            close.closest('dialog').close();
        }
    });

    document.querySelectorAll('dialog').forEach(dialog =>
        dialog.addEventListener('click', event => {
            if (event.target !== dialog) return;

            const rect = dialog.getBoundingClientRect();

            if (
                event.clientX < rect.left ||
                event.clientX > rect.right ||
                event.clientY < rect.top ||
                event.clientY > rect.bottom
            ) {
                dialog.close();
            }
        })
    );

    window.addEventListener('popstate', () => {
        readUrl();
        render();
    });

    readUrl();
    render();
}


/* =========================================================
   LEGACY MEN'S APPAREL PAGE
   ========================================================= */

function initializeBuyerProductsPage() {
    const page = document.getElementById('buyer-products-page');

    if (!page || document.getElementById('bc-data')) {
        return;
    }

    const grid = document.getElementById('bp-product-grid');
    const cards = [...grid.querySelectorAll('[data-bp-product]')];

    const filterSearch = document.getElementById('bp-filter-search');
    const globalSearchForm = document.getElementById('bp-global-search');
    const globalSearchInput = document.getElementById('bp-global-search-input');

    const minPrice = document.getElementById('bp-min-price');
    const maxPrice = document.getElementById('bp-max-price');
    const applyPrice = document.getElementById('bp-apply-price');

    const sizeInputs = [...document.querySelectorAll('.bp-size-input')];
    const colorInputs = [...document.querySelectorAll('.bp-color-input')];
    const conditionInputs = [
        ...document.querySelectorAll('.bp-condition-input'),
    ];

    const freeShipping = document.getElementById('bp-free-shipping');
    const sort = document.getElementById('bp-sort');

    const selectedCount = document.getElementById('bp-selected-count');
    const selectedChips = document.getElementById('bp-selected-chips');
    const toolbarChips = document.getElementById('bp-toolbar-chips');

    const headingCount = document.getElementById('bp-heading-count');
    const resultStatus = document.getElementById('bp-result-status');
    const emptyState = document.getElementById('bp-empty-state');
    const loadMore = document.getElementById('bp-load-more');

    const sidebar = document.getElementById('bp-sidebar');
    const sidebarBackdrop = document.getElementById('bp-sidebar-backdrop');
    const openSidebar = document.getElementById('bp-mobile-filter-button');
    const closeSidebar = document.getElementById('bp-sidebar-close');

    const state = {
        search: '',
        minPrice: null,
        maxPrice: null,
        sizes: [],
        colors: [],
        conditions: [],
        freeShipping: false,
        subcategory: '',
        sort: 'relevance',
    };

    const text = value => String(value || '').trim().toLowerCase();

    function readCard(card) {
        return {
            element: card,
            id: Number(card.dataset.id),
            name: text(card.dataset.name),
            price: Number(card.dataset.price),
            condition: card.dataset.condition,
            subcategory: card.dataset.subcategory,
            sizes: (card.dataset.sizes || '').split('|').filter(Boolean),
            colors: (card.dataset.colors || '').split('|').filter(Boolean),
            freeShipping: card.dataset.freeShipping === '1',
        };
    }

    function selectedLabels() {
        const labels = [];

        if (state.search) {
            labels.push({
                key: 'search',
                label: `“${state.search}”`,
            });
        }

        if (state.subcategory) {
            const button = document.querySelector(
                `[data-bp-subcategory="${state.subcategory}"]`
            );

            labels.push({
                key: 'subcategory',
                label: button?.textContent.trim() || 'Subcategory',
            });
        }

        state.sizes.forEach(value =>
            labels.push({
                key: `size:${value}`,
                label: value,
            })
        );

        state.colors.forEach(value => {
            const input = colorInputs.find(item => item.value === value);

            labels.push({
                key: `color:${value}`,
                label: input?.dataset.label || value,
            });
        });

        state.conditions.forEach(value => {
            const input = conditionInputs.find(item => item.value === value);

            labels.push({
                key: `condition:${value}`,
                label: input?.dataset.label || value,
            });
        });

        if (state.minPrice !== null || state.maxPrice !== null) {
            const low = state.minPrice ?? 0;
            const high = state.maxPrice ?? '∞';

            labels.push({
                key: 'price',
                label: `₱${low}–₱${high}`,
            });
        }

        if (state.freeShipping) {
            labels.push({
                key: 'shipping',
                label: 'Free shipping',
            });
        }

        return labels;
    }

    function removeFilter(key) {
        if (key === 'search') {
            state.search = '';
            filterSearch.value = '';
            globalSearchInput.value = '';
        }

        if (key === 'subcategory') {
            state.subcategory = '';

            document
                .querySelectorAll('[data-bp-subcategory]')
                .forEach(button => button.classList.remove('is-active'));
        }

        if (key === 'price') {
            state.minPrice = null;
            state.maxPrice = null;
            minPrice.value = '';
            maxPrice.value = '';
        }

        if (key === 'shipping') {
            state.freeShipping = false;
            freeShipping.checked = false;
        }

        if (key.startsWith('size:')) {
            const value = key.split(':')[1];

            state.sizes = state.sizes.filter(item => item !== value);

            const input = sizeInputs.find(item => item.value === value);
            if (input) input.checked = false;
        }

        if (key.startsWith('color:')) {
            const value = key.split(':')[1];

            state.colors = state.colors.filter(item => item !== value);

            const input = colorInputs.find(item => item.value === value);
            if (input) input.checked = false;
        }

        if (key.startsWith('condition:')) {
            const value = key.split(':')[1];

            state.conditions = state.conditions.filter(
                item => item !== value
            );

            const input = conditionInputs.find(item => item.value === value);
            if (input) input.checked = false;
        }

        render();
    }

    function renderChips() {
        const labels = selectedLabels();
        selectedCount.textContent = String(labels.length);

        const chipMarkup = labels
            .map(
                item => `
                    <span class="bp-selected-chip">
                        ${escapeHtml(item.label)}
                        <button
                            type="button"
                            data-bp-remove="${escapeHtml(item.key)}"
                            aria-label="Remove ${escapeHtml(item.label)}"
                        >×</button>
                    </span>
                `
            )
            .join('');

        selectedChips.innerHTML = chipMarkup;

        toolbarChips.innerHTML = labels.length
            ? labels
                .map(
                    item => `
                        <span class="bp-toolbar-chip">
                            ${escapeHtml(item.label)}
                            <button
                                type="button"
                                data-bp-remove="${escapeHtml(item.key)}"
                                aria-label="Remove ${escapeHtml(item.label)}"
                            >×</button>
                        </span>
                    `
                )
                .join('')
            : '<span class="bp-toolbar-placeholder">All Men\'s Apparel</span>';
    }

    function filteredProducts() {
        return cards.map(readCard).filter(product => {
            if (
                state.search &&
                !product.name.includes(text(state.search))
            ) {
                return false;
            }

            if (
                state.minPrice !== null &&
                product.price < state.minPrice
            ) {
                return false;
            }

            if (
                state.maxPrice !== null &&
                product.price > state.maxPrice
            ) {
                return false;
            }

            if (
                state.subcategory &&
                product.subcategory !== state.subcategory
            ) {
                return false;
            }

            if (
                state.sizes.length &&
                !state.sizes.some(size => product.sizes.includes(size))
            ) {
                return false;
            }

            if (
                state.colors.length &&
                !state.colors.some(color => product.colors.includes(color))
            ) {
                return false;
            }

            if (
                state.conditions.length &&
                !state.conditions.includes(product.condition)
            ) {
                return false;
            }

            if (state.freeShipping && !product.freeShipping) {
                return false;
            }

            return true;
        });
    }

    function sortProducts(items) {
        const sorted = [...items];

        if (state.sort === 'price-low') {
            sorted.sort((a, b) => a.price - b.price);
        }

        if (state.sort === 'price-high') {
            sorted.sort((a, b) => b.price - a.price);
        }

        if (state.sort === 'name') {
            sorted.sort((a, b) => a.name.localeCompare(b.name));
        }

        return sorted;
    }

    function render() {
        const results = sortProducts(filteredProducts());

        cards.forEach(card => {
            card.hidden = true;
        });

        results.forEach(product => {
            product.element.hidden = false;
            grid.appendChild(product.element);
        });

        const count = results.length;

        headingCount.textContent =
            `${count} sample ${count === 1 ? 'result' : 'results'}`;

        resultStatus.textContent =
            `Showing ${count} sample ${count === 1 ? 'listing' : 'listings'}`;

        emptyState.hidden = count !== 0;
        grid.hidden = count === 0;

        loadMore.disabled = true;
        loadMore.textContent = 'All sample products loaded';

        renderChips();
    }

    function clearAll() {
        state.search = '';
        state.minPrice = null;
        state.maxPrice = null;
        state.sizes = [];
        state.colors = [];
        state.conditions = [];
        state.freeShipping = false;
        state.subcategory = '';
        state.sort = 'relevance';

        filterSearch.value = '';
        globalSearchInput.value = '';
        minPrice.value = '';
        maxPrice.value = '';
        freeShipping.checked = false;
        sort.value = 'relevance';

        [...sizeInputs, ...colorInputs, ...conditionInputs].forEach(input => {
            input.checked = false;
        });

        document
            .querySelectorAll('[data-bp-subcategory]')
            .forEach(button => button.classList.remove('is-active'));

        render();
    }

    filterSearch.addEventListener('input', () => {
        state.search = filterSearch.value.trim();
        globalSearchInput.value = filterSearch.value;
        render();
    });

    globalSearchForm.addEventListener('submit', event => {
        event.preventDefault();

        state.search = globalSearchInput.value.trim();
        filterSearch.value = state.search;

        render();
    });

    applyPrice.addEventListener('click', () => {
        const min =
            minPrice.value === ''
                ? null
                : Number(minPrice.value);

        const max =
            maxPrice.value === ''
                ? null
                : Number(maxPrice.value);

        state.minPrice = Number.isFinite(min) ? min : null;
        state.maxPrice = Number.isFinite(max) ? max : null;

        if (
            state.minPrice !== null &&
            state.maxPrice !== null &&
            state.minPrice > state.maxPrice
        ) {
            [state.minPrice, state.maxPrice] = [
                state.maxPrice,
                state.minPrice,
            ];

            minPrice.value = state.minPrice;
            maxPrice.value = state.maxPrice;
        }

        render();
    });

    sizeInputs.forEach(input => {
        input.addEventListener('change', () => {
            state.sizes = sizeInputs
                .filter(item => item.checked)
                .map(item => item.value);

            render();
        });
    });

    colorInputs.forEach(input => {
        input.addEventListener('change', () => {
            state.colors = colorInputs
                .filter(item => item.checked)
                .map(item => item.value);

            render();
        });
    });

    conditionInputs.forEach(input => {
        input.addEventListener('change', () => {
            state.conditions = conditionInputs
                .filter(item => item.checked)
                .map(item => item.value);

            render();
        });
    });

    freeShipping.addEventListener('change', () => {
        state.freeShipping = freeShipping.checked;
        render();
    });

    sort.addEventListener('change', () => {
        state.sort = sort.value;
        render();
    });

    document
        .querySelectorAll('[data-bp-subcategory]')
        .forEach(button => {
            button.addEventListener('click', () => {
                const value = button.dataset.bpSubcategory;

                if (state.subcategory === value) {
                    state.subcategory = '';
                    button.classList.remove('is-active');
                } else {
                    state.subcategory = value;

                    document
                        .querySelectorAll('[data-bp-subcategory]')
                        .forEach(item => item.classList.remove('is-active'));

                    button.classList.add('is-active');
                }

                render();
            });
        });

    document
        .querySelectorAll('[data-bp-clear-all]')
        .forEach(button => {
            button.addEventListener('click', clearAll);
        });

    document.addEventListener('click', event => {
        const remove = event.target.closest('[data-bp-remove]');

        if (remove) {
            removeFilter(remove.dataset.bpRemove);
        }

        const viewToggle = event.target.closest('[data-bp-view]');

        if (viewToggle) {
            document
                .querySelectorAll('[data-bp-view]')
                .forEach(button => button.classList.remove('is-active'));

            viewToggle.classList.add('is-active');

            grid.classList.toggle(
                'is-list',
                viewToggle.dataset.bpView === 'list'
            );
        }

        const preview = event.target.closest('[data-bp-preview]');

        if (preview) {
            window.alert(
                `${preview.dataset.bpPreview}\n\nFront-end preview only. Product details will connect to the backend later.`
            );
        }
    });

    function setSidebar(open) {
        sidebar.classList.toggle('is-open', open);
        sidebarBackdrop.hidden = !open;
        document.body.classList.toggle('menu-open', open);
    }

    openSidebar?.addEventListener('click', () => setSidebar(true));
    closeSidebar?.addEventListener('click', () => setSidebar(false));
    sidebarBackdrop?.addEventListener('click', () => setSidebar(false));

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            setSidebar(false);
        }
    });

    render();
}

if (typeof document !== 'undefined') {
    initializeBuyerProductsPage();
}


/* =========================================================
   CATEGORY PAGE V2
   ========================================================= */

export const defaults = () => ({
    search: '',
    subcategory: '',
    min: 0,
    max: 5000,
    size: [],
    color: [],
    condition: [],
    location: [],
    shipping: false,
    voucher: false,
    rating: 0,
    sort: 'featured',
    saved: false,
    view: 'grid',
});

export function filterCatalog(products, state, savedIds = []) {
    const search = state.search.trim().toLowerCase();

    const rows = products.filter(
        product =>
            (!search || product.name.toLowerCase().includes(search)) &&
            (!state.subcategory ||
                product.subcategory === state.subcategory) &&
            product.price >= state.min &&
            product.price <= state.max &&
            (!state.size.length ||
                state.size.some(size => product.sizes.includes(size))) &&
            ['color', 'condition', 'location'].every(
                key =>
                    !state[key].length ||
                    state[key].includes(product[key])
            ) &&
            (!state.shipping || product.free_shipping) &&
            (!state.voucher || product.voucher) &&
            product.rating >= state.rating &&
            (!state.saved || savedIds.includes(product.id))
    );

    if (state.sort === 'price-low') {
        rows.sort((a, b) => a.price - b.price || a.id - b.id);
    }

    if (state.sort === 'price-high') {
        rows.sort((a, b) => b.price - a.price || a.id - b.id);
    }

    if (state.sort === 'newest') {
        rows.sort((a, b) => b.id - a.id);
    }

    return rows;
}

export function readState(params, products) {
    const state = defaults();

    state.search = (params.get('q') || '').slice(0, 120);

    state.subcategory = products.some(
        product => product.subcategory === params.get('sub')
    )
        ? params.get('sub')
        : '';

    for (const key of ['size', 'color', 'condition', 'location']) {
        const valid = new Set(
            products.flatMap(product =>
                key === 'size'
                    ? product.sizes
                    : [product[key]]
            )
        );

        state[key] = [
            ...new Set(params.getAll(key)),
        ].filter(value => valid.has(value));
    }

    for (const key of ['min', 'max']) {
        const value = Number(params.get(key) ?? state[key]);

        if (Number.isFinite(value)) {
            state[key] = Math.min(
                5000,
                Math.max(0, value)
            );
        }
    }

    if (state.min > state.max) {
        state.min = 0;
        state.max = 5000;
    }

    state.shipping = params.get('shipping') === '1';
    state.voucher = params.get('voucher') === '1';
    state.saved = params.get('saved') === '1';
    state.rating = params.get('rating') === '4' ? 4 : 0;

    state.sort = [
        'featured',
        'newest',
        'price-low',
        'price-high',
    ].includes(params.get('sort'))
        ? params.get('sort')
        : 'featured';

    state.view =
        params.get('view') === 'list'
            ? 'list'
            : 'grid';

    return state;
}

const categoryEscapeHtml = value =>
    String(value).replace(
        /[&<>"']/g,
        char =>
            ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
            })[char]
    );

const price = value =>
    new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        maximumFractionDigits: 0,
    }).format(value);

if (
    typeof document !== 'undefined' &&
    document.getElementById('bc-data')
) {
    init();
}

function init() {
    const $ = id => document.getElementById(id);

    const products = JSON.parse(
        $('bc-data').textContent
    );

    const validIds = new Set(
        products.map(product => product.id)
    );

    let state = readState(
        new URLSearchParams(location.search),
        products
    );

    let limit = 20;
    let saved = [];

    try {
        const stored = JSON.parse(
            localStorage.getItem(
                'bearly-category-saved-v1'
            ) || '[]'
        );

        if (Array.isArray(stored)) {
            saved = stored.filter(id =>
                validIds.has(id)
            );
        }
    } catch {
        saved = [];
    }

    const colors = {
        Blue: '#305e94',
        Black: '#26292a',
        White: '#fff',
        Beige: '#d8c8a4',
        Green: '#68745a',
        Gray: '#8a8b8e',
        Brown: '#79543b',
    };

    function option(container, key, values) {
        $(container).innerHTML = values
            .map(value => {
                if (key === 'size') {
                    return `
                        <label>
                            <input
                                type="checkbox"
                                data-field="size"
                                value="${categoryEscapeHtml(value)}"
                            >
                            <span>
                                ${categoryEscapeHtml(value)}
                            </span>
                        </label>
                    `;
                }

                if (key === 'color') {
                    return `
                        <label>
                            <input
                                type="checkbox"
                                data-field="color"
                                value="${categoryEscapeHtml(value)}"
                            >
                            <span
                                class="swatch"
                                style="--swatch:${colors[value] || '#aaa'}"
                            ></span>
                            ${categoryEscapeHtml(value)}
                        </label>
                    `;
                }

                return `
                    <label>
                        <input
                            type="checkbox"
                            data-field="${key}"
                            value="${categoryEscapeHtml(value)}"
                        >
                        ${categoryEscapeHtml(value)}
                    </label>
                `;
            })
            .join('');
    }

    option(
        'bc-size-options',
        'size',
        [...new Set(products.flatMap(product => product.sizes))]
    );

    for (const key of [
        'color',
        'condition',
        'location',
    ]) {
        option(
            `bc-${key}-options`,
            key,
            [
                ...new Set(
                    products.map(product => product[key])
                ),
            ]
        );
    }

    function chips() {
        const result = [];

        if (state.search) {
            result.push([
                'search',
                state.search,
                `Search: ${state.search}`,
            ]);
        }

        if (state.subcategory) {
            result.push([
                'subcategory',
                state.subcategory,
                state.subcategory,
            ]);
        }

        if (
            state.min ||
            state.max !== 5000
        ) {
            result.push([
                'price',
                '',
                `${price(state.min)}–${price(
                    state.max
                )}`,
            ]);
        }

        for (const key of [
            'size',
            'color',
            'condition',
            'location',
        ]) {
            state[key].forEach(value =>
                result.push([
                    key,
                    value,
                    value,
                ])
            );
        }

        if (state.shipping) {
            result.push([
                'shipping',
                '',
                'Free shipping',
            ]);
        }

        if (state.voucher) {
            result.push([
                'voucher',
                '',
                'Has voucher',
            ]);
        }

        if (state.rating) {
            result.push([
                'rating',
                '',
                '4 stars & up',
            ]);
        }

        if (state.saved) {
            result.push([
                'saved',
                '',
                'Saved items',
            ]);
        }

        return result;
    }

    function photoStyle(element, product) {
        element.style.setProperty(
            '--x',
            `${(product.photo % 5) * 25}%`
        );

        element.style.setProperty(
            '--y',
            `${Math.floor(product.photo / 5) * 100 / 3}%`
        );
    }

    function render() {
        const filtered = filterCatalog(
            products,
            state,
            saved
        );

        const shown = filtered.slice(
            0,
            limit
        );

        const fragment =
            document.createDocumentFragment();

        for (const product of shown) {
            const card = $('bc-card-template')
                .content
                .firstElementChild
                .cloneNode(true);

            card.dataset.id = product.id;

            photoStyle(
                card.querySelector('.photo'),
                product
            );

            card
                .querySelector('.photo')
                .setAttribute(
                    'aria-label',
                    product.name
                );

            card.querySelector('h2').textContent =
                product.name;

            card.querySelector(
                '.condition'
            ).textContent = product.condition;

            card.querySelector(
                '.price-row strong'
            ).textContent = price(product.price);

            card.querySelector(
                '.color-label'
            ).textContent = product.color;

            card
                .querySelectorAll('[data-quick]')
                .forEach(button =>
                    button.setAttribute(
                        'aria-label',
                        `Quick view: ${product.name}`
                    )
                );

            const heart =
                card.querySelector('[data-save]');

            heart.setAttribute(
                'aria-pressed',
                String(saved.includes(product.id))
            );

            heart.setAttribute(
                'aria-label',
                `${
                    saved.includes(product.id)
                        ? 'Remove'
                        : 'Save'
                } ${product.name}`
            );

            fragment.append(card);
        }

        $('bc-grid').replaceChildren(fragment);

        $('bc-grid').classList.toggle(
            'is-list',
            state.view === 'list'
        );

        $('bc-count').textContent =
            `${filtered.length} sample products`;

        $('bc-status').textContent =
            `Showing ${shown.length} of ${filtered.length} sample products`;

        $('bc-empty').hidden =
            filtered.length > 0;

        $('bc-more').hidden =
            shown.length >= filtered.length;

        const selected = chips();

        const markup = selected
            .map(
                ([key, value, label]) => `
                    <button
                        class="chip"
                        data-remove="${key}"
                        data-value="${categoryEscapeHtml(value)}"
                        aria-label="Remove ${categoryEscapeHtml(label)}"
                    >
                        ${categoryEscapeHtml(label)} ×
                    </button>
                `
            )
            .join('');

        $('bc-chips').innerHTML = markup;
        $('bc-side-chips').innerHTML = markup;

        $('bc-selected').textContent =
            `Selected (${selected.length})`;

        $('bc-mobile-count').textContent =
            selected.length
                ? `(${selected.length})`
                : '';

        $('bc-show-results').textContent =
            `Show ${filtered.length} results`;

        $('bc-saved-count').textContent =
            saved.length;

        $('bc-saved-filter').setAttribute(
            'aria-pressed',
            String(state.saved)
        );

        document
            .querySelectorAll('[data-sub]')
            .forEach(button =>
                button.setAttribute(
                    'aria-pressed',
                    String(
                        button.dataset.sub ===
                        state.subcategory
                    )
                )
            );

        document
            .querySelectorAll('[data-sort]')
            .forEach(button =>
                button.setAttribute(
                    'aria-pressed',
                    String(
                        button.dataset.sort ===
                        state.sort
                    )
                )
            );

        document
            .querySelectorAll('[data-view]')
            .forEach(button =>
                button.setAttribute(
                    'aria-pressed',
                    String(
                        button.dataset.view ===
                        state.view
                    )
                )
            );
    }

    function syncInputs() {
        for (
            const input of
            document.querySelectorAll(
                '[data-field]'
            )
        ) {
            const key = input.dataset.field;

            input.checked = Array.isArray(
                state[key]
            )
                ? state[key].includes(input.value)
                : key === 'rating'
                    ? Number(input.value) ===
                    state.rating
                    : Boolean(state[key]);
        }

        $('bc-search').value = state.search;
        $('bc-within').value = state.search;

        for (const key of ['min', 'max']) {
            $(`bc-${key}`).value =
                state[key];

            $(`bc-${key}-range`).value =
                state[key];
        }

        $('bc-price-error').hidden = true;
    }

    function writeUrl() {
        const url = new URL(location.href);

        const keys = [
            'q',
            'sub',
            'size',
            'color',
            'condition',
            'location',
            'min',
            'max',
            'shipping',
            'voucher',
            'rating',
            'sort',
            'saved',
            'view',
        ];

        keys.forEach(key =>
            url.searchParams.delete(key)
        );

        url.searchParams.set(
            'category',
            'men-s-apparel'
        );

        if (state.search) {
            url.searchParams.set(
                'q',
                state.search
            );
        }

        if (state.subcategory) {
            url.searchParams.set(
                'sub',
                state.subcategory
            );
        }

        for (const key of [
            'size',
            'color',
            'condition',
            'location',
        ]) {
            state[key].forEach(value =>
                url.searchParams.append(
                    key,
                    value
                )
            );
        }

        if (state.min) {
            url.searchParams.set(
                'min',
                state.min
            );
        }

        if (state.max !== 5000) {
            url.searchParams.set(
                'max',
                state.max
            );
        }

        for (const key of [
            'shipping',
            'voucher',
            'saved',
        ]) {
            if (state[key]) {
                url.searchParams.set(key, '1');
            }
        }

        if (state.rating) {
            url.searchParams.set(
                'rating',
                state.rating
            );
        }

        if (state.sort !== 'featured') {
            url.searchParams.set(
                'sort',
                state.sort
            );
        }

        if (state.view !== 'grid') {
            url.searchParams.set(
                'view',
                state.view
            );
        }

        history.replaceState(
            {},
            '',
            url
        );
    }

    function change() {
        limit = 20;
        syncInputs();
        render();
        writeUrl();
    }

    function reset() {
        const view = state.view;

        state = defaults();
        state.view = view;

        change();
    }

    $('bc-search-form').addEventListener(
        'submit',
        event => {
            event.preventDefault();

            state.search =
                $('bc-search').value.trim();

            change();
        }
    );

    $('bc-within').addEventListener(
        'input',
        () => {
            state.search =
                $('bc-within').value.trim();

            $('bc-search').value =
                state.search;

            limit = 20;

            render();
            writeUrl();
        }
    );

    document.addEventListener(
        'change',
        event => {
            const input =
                event.target.closest(
                    '[data-field]'
                );

            if (!input) return;

            const key =
                input.dataset.field;

            if (Array.isArray(state[key])) {
                state[key] = [
                    ...document.querySelectorAll(
                        `[data-field="${key}"]:checked`
                    ),
                ].map(element => element.value);
            } else {
                state[key] =
                    key === 'rating'
                        ? Number(input.value)
                        : input.checked;
            }

            change();
        }
    );

    function updatePrice(
        key,
        range
    ) {
        let value = Number(
            $(
                `bc-${key}${
                    range ? '-range' : ''
                }`
            ).value
        );

        if (!Number.isFinite(value)) {
            return;
        }

        value = Math.round(
            Math.min(
                5000,
                Math.max(0, value)
            )
        );

        const candidate = {
            ...state,
            [key]: value,
        };

        if (
            candidate.min >
            candidate.max
        ) {
            if (range) {
                candidate[key] =
                    key === 'min'
                        ? candidate.max
                        : candidate.min;
            } else {
                $('bc-price-error').hidden =
                    false;
                return;
            }
        }

        state.min = candidate.min;
        state.max = candidate.max;

        change();
    }

    for (const key of ['min', 'max']) {
        $(`bc-${key}`).addEventListener(
            'change',
            () => updatePrice(key, false)
        );

        $(
            `bc-${key}-range`
        ).addEventListener(
            'input',
            () => updatePrice(key, true)
        );
    }

    $('bc-location-search').addEventListener(
        'input',
        event => {
            const query =
                event.target.value.toLowerCase();

            document
                .querySelectorAll(
                    '#bc-location-options label'
                )
                .forEach(label => {
                    label.hidden =
                        !label.textContent
                            .toLowerCase()
                            .includes(query);
                });
        }
    );

    $('bc-collapse').addEventListener(
        'click',
        () => {
            const nodes = [
                ...$('bc-filter-panel')
                    .querySelectorAll('details'),
            ];

            const expand =
                !nodes.some(
                    element => element.open
                );

            nodes.forEach(
                element =>
                    (element.open = expand)
            );

            $('bc-collapse').textContent =
                expand
                    ? 'Collapse all'
                    : 'Expand all';

            $('bc-collapse').setAttribute(
                'aria-expanded',
                String(expand)
            );
        }
    );

    $('bc-more').addEventListener(
        'click',
        () => {
            const previousCount =
                $('bc-grid').children.length;

            limit += 20;

            render();

            $('bc-grid')
                .children[
                    previousCount
                ]?.querySelector(
                    '[data-quick]'
                )
                ?.focus({
                    preventScroll: true,
                });
        }
    );

    $('bc-saved-filter').addEventListener(
        'click',
        () => {
            state.saved = !state.saved;
            change();
        }
    );

    const mobile =
        $('bc-mobile-dialog');

    const panel =
        $('bc-filter-panel');

    $('bc-open-filters').addEventListener(
        'click',
        () => {
            $('bc-mobile-content').append(panel);

            mobile.showModal();

            document.body.style.overflow =
                'hidden';
        }
    );

    mobile.addEventListener(
        'close',
        () => {
            $('bc-sidebar').append(panel);

            document.body.style.overflow =
                '';

            $('bc-open-filters').focus();
        }
    );

    $('bc-show-results').addEventListener(
        'click',
        () => mobile.close()
    );

    const mq =
        matchMedia('(min-width:721px)');

    mq.addEventListener(
        'change',
        () => {
            if (
                mq.matches &&
                mobile.open
            ) {
                mobile.close();
            }
        }
    );


    /* =====================================================
       PRODUCT DETAILS — FRONTEND ONLY
       ===================================================== */

    function showProduct(product) {
        const dialog =
            $('bc-product-dialog');

        const detail =
            $('bc-product-detail');

        const sizes =
            Array.isArray(product.sizes)
                ? product.sizes.filter(Boolean)
                : [];

        const productColors =
            Array.isArray(product.colors) &&
            product.colors.length
                ? product.colors.filter(Boolean)
                : [product.color].filter(Boolean);

        const variants =
            Array.isArray(product.variants)
                ? product.variants
                : [];

        const productId =
            Number(product.id) || 1;

        const sellerName =
            product.seller_name ||
            'Bearly Sample Seller';

        const sellerLocation =
            product.seller_location ||
            product.location ||
            'Philippines';

        const sellerRating =
            Number(
                product.seller_rating ??
                Math.min(
                    5,
                    (Number(product.rating) ||
                        4.7) + 0.1
                )
            ).toFixed(1);

        const fallbackStock =
            12 + (productId % 9);

        const baseStock =
            Number.isFinite(
                Number(product.stock)
            )
                ? Math.max(
                    0,
                    Number(product.stock)
                )
                : fallbackStock;

        const soldCount =
            Number.isFinite(
                Number(product.sold)
            )
                ? Math.max(
                    0,
                    Number(product.sold)
                )
                : 24 +
                ((productId * 17) %
                    180);

        const productRating =
            Number(product.rating) || 0;

        let selectedSize =
            sizes[0] || '';

        let selectedColor =
            productColors[0] || '';

        let quantity = 1;

        const findVariant = () => {
            if (!variants.length) {
                return null;
            }

            return (
                variants.find(variant => {
                    const sizeMatches =
                        !selectedSize ||
                        !variant.size ||
                        variant.size ===
                        selectedSize;

                    const colorMatches =
                        !selectedColor ||
                        !variant.color ||
                        variant.color ===
                        selectedColor;

                    return (
                        sizeMatches &&
                        colorMatches
                    );
                }) || null
            );
        };

        const sizeOptions =
            sizes.length
                ? `
                    <div class="pd-option-group">
                        <div class="pd-option-heading">
                            <span>Size</span>
                            <button
                                type="button"
                                class="pd-size-guide"
                                data-pd-size-guide
                            >
                                Size guide
                            </button>
                        </div>

                        <div
                            class="pd-options"
                            role="group"
                            aria-label="Choose size"
                        >
                            ${sizes
                                .map(
                                    (
                                        size,
                                        index
                                    ) => `
                                        <button
                                            type="button"
                                            class="pd-option"
                                            data-pd-size="${categoryEscapeHtml(
                                                size
                                            )}"
                                            aria-pressed="${
                                                index === 0
                                                    ? 'true'
                                                    : 'false'
                                            }"
                                        >
                                            ${categoryEscapeHtml(
                                                size
                                            )}
                                        </button>
                                    `
                                )
                                .join('')}
                        </div>
                    </div>
                `
                : '';

        const colorOptions =
            productColors.length
                ? `
                    <div class="pd-option-group">
                        <div class="pd-option-heading">
                            <span>Color</span>

                            <strong data-pd-color-label>
                                ${categoryEscapeHtml(
                                    selectedColor
                                )}
                            </strong>
                        </div>

                        <div
                            class="pd-options pd-color-options"
                            role="group"
                            aria-label="Choose color"
                        >
                            ${productColors
                                .map(
                                    (
                                        color,
                                        index
                                    ) => `
                                        <button
                                            type="button"
                                            class="pd-option pd-color-option"
                                            data-pd-color="${categoryEscapeHtml(
                                                color
                                            )}"
                                            aria-pressed="${
                                                index === 0
                                                    ? 'true'
                                                    : 'false'
                                            }"
                                        >
                                            <span
                                                class="pd-color-dot"
                                                style="--pd-color:${
                                                    colors[
                                                        color
                                                    ] ||
                                                    '#aaa'
                                                }"
                                                aria-hidden="true"
                                            ></span>

                                            ${categoryEscapeHtml(
                                                color
                                            )}
                                        </button>
                                    `
                                )
                                .join('')}
                        </div>
                    </div>
                `
                : '';

        const shippingBadge =
            product.free_shipping
                ? `
                    <span class="pd-badge">
                        <i class="mi" aria-hidden="true">
                            local_shipping
                        </i>
                        Free shipping
                    </span>
                `
                : '';

        const voucherBadge =
            product.voucher
                ? `
                    <span class="pd-badge">
                        <i class="mi" aria-hidden="true">
                            sell
                        </i>
                        Voucher available
                    </span>
                `
                : '';

        const sellerInitial =
            categoryEscapeHtml(
                sellerName
                    .charAt(0)
                    .toUpperCase()
            );

        detail.innerHTML = `
            <article class="pd-shell">

                <div class="pd-top">

                    <section class="pd-media">

                        <div
                            class="photo pd-main-photo"
                            role="img"
                            aria-label="${categoryEscapeHtml(
                                product.name
                            )}"
                        ></div>

                        <div class="pd-thumbnails">
                            <button
                                type="button"
                                class="pd-thumbnail is-active"
                                aria-label="View product image"
                            >
                                <span
                                    class="photo pd-thumb-photo"
                                    aria-hidden="true"
                                ></span>
                            </button>
                        </div>

                        <div class="pd-media-note">
                            <i class="mi" aria-hidden="true">
                                verified
                            </i>
                            Product preview
                        </div>

                    </section>

                    <section class="pd-info">

                        <p class="pd-category">
                            ${categoryEscapeHtml(
                                product.subcategory
                            )}
                        </p>

                        <h2 id="bc-product-title">
                            ${categoryEscapeHtml(
                                product.name
                            )}
                        </h2>

                        <div class="pd-social-proof">

                            <span class="pd-rating">
                                <i
                                    class="mi"
                                    aria-hidden="true"
                                >
                                    star
                                </i>
                                ${
                                    productRating
                                        ? productRating.toFixed(
                                            1
                                        )
                                        : 'New'
                                }
                            </span>

                            <span class="pd-divider"></span>

                            <span>
                                ${soldCount} sold
                            </span>

                        </div>

                        <div class="pd-price-box">
                            <strong
                                class="pd-price"
                                data-pd-price
                            >
                                ${price(
                                    product.price
                                )}
                            </strong>
                        </div>

                        ${
                            shippingBadge ||
                            voucherBadge
                                ? `
                                    <div class="pd-badges">
                                        ${shippingBadge}
                                        ${voucherBadge}
                                    </div>
                                `
                                : ''
                        }

                        <div class="pd-overview">

                            <div>
                                <span>
                                    Condition
                                </span>

                                <strong>
                                    ${categoryEscapeHtml(
                                        product.condition
                                    )}
                                </strong>
                            </div>

                            <div>
                                <span>
                                    Ships from
                                </span>

                                <strong>
                                    ${categoryEscapeHtml(
                                        sellerLocation
                                    )}
                                </strong>
                            </div>

                        </div>

                        ${colorOptions}

                        ${sizeOptions}

                        <div
                            class="pd-option-group pd-quantity-group"
                        >

                            <div class="pd-option-heading">
                                <span>
                                    Quantity
                                </span>

                                <small data-pd-stock>
                                    ${baseStock}
                                    pieces available
                                </small>
                            </div>

                            <div class="pd-quantity-row">

                                <div class="pd-stepper">

                                    <button
                                        type="button"
                                        data-pd-minus
                                        aria-label="Decrease quantity"
                                    >
                                        <i
                                            class="mi"
                                            aria-hidden="true"
                                        >
                                            remove
                                        </i>
                                    </button>

                                    <span
                                        data-pd-quantity
                                    >
                                        1
                                    </span>

                                    <button
                                        type="button"
                                        data-pd-plus
                                        aria-label="Increase quantity"
                                    >
                                        <i
                                            class="mi"
                                            aria-hidden="true"
                                        >
                                            add
                                        </i>
                                    </button>

                                </div>

                            </div>

                        </div>

                        <div class="pd-actions">

                            <button
                                type="button"
                                class="pd-add-cart"
                                data-pd-add-cart
                            >
                                <i
                                    class="mi"
                                    aria-hidden="true"
                                >
                                    shopping_cart
                                </i>

                                Add to Cart
                            </button>

                            <button
                                type="button"
                                class="pd-buy-now"
                                data-pd-buy-now
                            >
                                Buy Now
                            </button>

                        </div>

                        <div class="pd-action-note">
                            <i
                                class="mi"
                                aria-hidden="true"
                            >
                                info
                            </i>

                            Frontend preview only —
                            checkout and live
                            inventory will be
                            connected during
                            backend integration.
                        </div>

                    </section>

                </div>

                <section class="pd-seller-card">

                    <div class="pd-seller-avatar">
                        ${sellerInitial}
                    </div>

                    <div class="pd-seller-info">

                        <small>Sold by</small>

                        <h3>
                            ${categoryEscapeHtml(
                                sellerName
                            )}
                        </h3>

                        <div class="pd-seller-meta">

                            <span>
                                <i
                                    class="mi"
                                    aria-hidden="true"
                                >
                                    star
                                </i>
                                ${sellerRating}
                                seller rating
                            </span>

                            <span>
                                <i
                                    class="mi"
                                    aria-hidden="true"
                                >
                                    location_on
                                </i>
                                ${categoryEscapeHtml(
                                    sellerLocation
                                )}
                            </span>

                        </div>

                    </div>

                    <div class="pd-seller-actions">

                        <button
                            type="button"
                            class="pd-shop-button"
                            data-pd-chat
                        >
                            <i
                                class="mi"
                                aria-hidden="true"
                            >
                                chat_bubble
                            </i>

                            Chat
                        </button>

                        <button
                            type="button"
                            class="pd-shop-button"
                            data-pd-shop
                        >
                            <i
                                class="mi"
                                aria-hidden="true"
                            >
                                storefront
                            </i>

                            View Shop
                        </button>

                    </div>

                </section>

                <section class="pd-description">

                    <h3>
                        Product Description
                    </h3>

                    <p>
                        ${categoryEscapeHtml(
                            product.description ||
                            'No description available.'
                        )}
                    </p>

                    <div class="pd-description-grid">

                        <div>
                            <span>
                                Category
                            </span>

                            <strong>
                                ${categoryEscapeHtml(
                                    product.subcategory
                                )}
                            </strong>
                        </div>

                        <div>
                            <span>
                                Condition
                            </span>

                            <strong>
                                ${categoryEscapeHtml(
                                    product.condition
                                )}
                            </strong>
                        </div>

                        <div>
                            <span>
                                Color
                            </span>

                            <strong
                                data-pd-description-color
                            >
                                ${categoryEscapeHtml(
                                    selectedColor ||
                                    '—'
                                )}
                            </strong>
                        </div>

                        <div>
                            <span>
                                Location
                            </span>

                            <strong>
                                ${categoryEscapeHtml(
                                    sellerLocation
                                )}
                            </strong>
                        </div>

                    </div>

                </section>

            </article>
        `;

        const mainPhoto =
            detail.querySelector(
                '.pd-main-photo'
            );

        const thumbPhoto =
            detail.querySelector(
                '.pd-thumb-photo'
            );

        if (mainPhoto) {
            photoStyle(
                mainPhoto,
                product
            );
        }

        if (thumbPhoto) {
            photoStyle(
                thumbPhoto,
                product
            );
        }

        function notify(message) {
            let toast =
                document.getElementById(
                    'bc-preview-toast'
                );

            if (!toast) {
                toast =
                    document.createElement(
                        'div'
                    );

                toast.id =
                    'bc-preview-toast';

                toast.className =
                    'bc-preview-toast';

                toast.setAttribute(
                    'role',
                    'status'
                );

                toast.setAttribute(
                    'aria-live',
                    'polite'
                );

                document.body.append(toast);
            }

            toast.textContent = message;
            toast.classList.add(
                'is-visible'
            );

            clearTimeout(
                window.__bearlyPreviewToast
            );

            window.__bearlyPreviewToast =
                setTimeout(() => {
                    toast.classList.remove(
                        'is-visible'
                    );
                }, 2400);
        }

        function currentVariant() {
            return findVariant();
        }

        function currentStock() {
            const variant =
                currentVariant();

            if (
                variants.length &&
                !variant
            ) {
                return 0;
            }

            if (
                variant &&
                Number.isFinite(
                    Number(variant.stock)
                )
            ) {
                return Math.max(
                    0,
                    Number(variant.stock)
                );
            }

            return baseStock;
        }

        function currentPrice() {
            const variant =
                currentVariant();

            if (
                variant &&
                Number.isFinite(
                    Number(variant.price)
                )
            ) {
                return Number(
                    variant.price
                );
            }

            return (
                Number(product.price) ||
                0
            );
        }

        function syncProductSelection() {
            const stock =
                currentStock();

            if (stock > 0) {
                quantity = Math.max(
                    1,
                    Math.min(
                        quantity,
                        stock
                    )
                );
            } else {
                quantity = 1;
            }

            const priceNode =
                detail.querySelector(
                    '[data-pd-price]'
                );

            const stockNode =
                detail.querySelector(
                    '[data-pd-stock]'
                );

            const quantityNode =
                detail.querySelector(
                    '[data-pd-quantity]'
                );

            const minusButton =
                detail.querySelector(
                    '[data-pd-minus]'
                );

            const plusButton =
                detail.querySelector(
                    '[data-pd-plus]'
                );

            const addButton =
                detail.querySelector(
                    '[data-pd-add-cart]'
                );

            const buyButton =
                detail.querySelector(
                    '[data-pd-buy-now]'
                );

            if (priceNode) {
                priceNode.textContent =
                    price(
                        currentPrice()
                    );
            }

            if (stockNode) {
                stockNode.textContent =
                    stock > 0
                        ? `${stock} piece${
                            stock === 1
                                ? ''
                                : 's'
                        } available`
                        : 'Out of stock';

                stockNode.classList.toggle(
                    'is-out',
                    stock <= 0
                );
            }

            if (quantityNode) {
                quantityNode.textContent =
                    quantity;
            }

            if (minusButton) {
                minusButton.disabled =
                    quantity <= 1 ||
                    stock <= 0;
            }

            if (plusButton) {
                plusButton.disabled =
                    quantity >= stock ||
                    stock <= 0;
            }

            if (addButton) {
                addButton.disabled =
                    stock <= 0;
            }

            if (buyButton) {
                buyButton.disabled =
                    stock <= 0;
            }
        }

        detail
            .querySelectorAll(
                '[data-pd-size]'
            )
            .forEach(button => {
                button.addEventListener(
                    'click',
                    () => {
                        selectedSize =
                            button.dataset
                                .pdSize || '';

                        detail
                            .querySelectorAll(
                                '[data-pd-size]'
                            )
                            .forEach(
                                option => {
                                    option.setAttribute(
                                        'aria-pressed',
                                        String(
                                            option ===
                                            button
                                        )
                                    );
                                }
                            );

                        quantity = 1;

                        syncProductSelection();
                    }
                );
            });

        detail
            .querySelectorAll(
                '[data-pd-color]'
            )
            .forEach(button => {
                button.addEventListener(
                    'click',
                    () => {
                        selectedColor =
                            button.dataset
                                .pdColor || '';

                        detail
                            .querySelectorAll(
                                '[data-pd-color]'
                            )
                            .forEach(
                                option => {
                                    option.setAttribute(
                                        'aria-pressed',
                                        String(
                                            option ===
                                            button
                                        )
                                    );
                                }
                            );

                        const colorLabel =
                            detail.querySelector(
                                '[data-pd-color-label]'
                            );

                        const descriptionColor =
                            detail.querySelector(
                                '[data-pd-description-color]'
                            );

                        if (colorLabel) {
                            colorLabel.textContent =
                                selectedColor;
                        }

                        if (
                            descriptionColor
                        ) {
                            descriptionColor.textContent =
                                selectedColor;
                        }

                        quantity = 1;

                        syncProductSelection();
                    }
                );
            });

        detail
            .querySelector(
                '[data-pd-minus]'
            )
            ?.addEventListener(
                'click',
                () => {
                    if (quantity > 1) {
                        quantity--;
                        syncProductSelection();
                    }
                }
            );

        detail
            .querySelector(
                '[data-pd-plus]'
            )
            ?.addEventListener(
                'click',
                () => {
                    const stock =
                        currentStock();

                    if (
                        quantity <
                        stock
                    ) {
                        quantity++;
                        syncProductSelection();
                    }
                }
            );

        detail
            .querySelector(
                '[data-pd-add-cart]'
            )
            ?.addEventListener(
                'click',
                () => {
                    const stock =
                        currentStock();

                    if (stock <= 0) {
                        notify(
                            'This variation is out of stock.'
                        );
                        return;
                    }

                    const cartKey =
                        'bearly-preview-cart-v1';

                    let previewCart = [];

                    try {
                        const stored =
                            JSON.parse(
                                localStorage.getItem(
                                    cartKey
                                ) || '[]'
                            );

                        if (
                            Array.isArray(
                                stored
                            )
                        ) {
                            previewCart =
                                stored;
                        }
                    } catch {
                        previewCart = [];
                    }

                    const itemKey = [
                        product.id,
                        selectedColor,
                        selectedSize,
                    ].join('-');

                    const existing =
                        previewCart.find(
                            item =>
                                item.key ===
                                itemKey
                        );

                    if (existing) {
                        existing.quantity =
                            Math.min(
                                stock,
                                existing.quantity +
                                quantity
                            );
                    } else {
                        previewCart.push({
                            key: itemKey,
                            product_id:
                                product.id,
                            name:
                                product.name,
                            price:
                                currentPrice(),
                            color:
                                selectedColor,
                            size:
                                selectedSize,
                            quantity,
                            photo:
                                product.photo,
                            seller_name:
                                sellerName,
                            frontend_preview:
                                true,
                        });
                    }

                    try {
                        localStorage.setItem(
                            cartKey,
                            JSON.stringify(
                                previewCart
                            )
                        );
                    } catch {
                        // LocalStorage unavailable.
                    }

                    const button =
                        detail.querySelector(
                            '[data-pd-add-cart]'
                        );

                    const original =
                        button.innerHTML;

                    button.innerHTML = `
                        <i
                            class="mi"
                            aria-hidden="true"
                        >
                            check
                        </i>
                        Added to Cart
                    `;

                    notify(
                        `${quantity} × ${product.name} added to the preview cart.`
                    );

                    setTimeout(() => {
                        if (
                            button.isConnected
                        ) {
                            button.innerHTML =
                                original;
                        }
                    }, 1400);
                }
            );

        detail
            .querySelector(
                '[data-pd-buy-now]'
            )
            ?.addEventListener(
                'click',
                () => {
                    notify(
                        'Buy Now is frontend-only for now. Checkout will be connected later.'
                    );
                }
            );

        detail
            .querySelector(
                '[data-pd-chat]'
            )
            ?.addEventListener(
                'click',
                () => {
                    notify(
                        'Seller chat will be connected during backend integration.'
                    );
                }
            );

        detail
            .querySelector(
                '[data-pd-shop]'
            )
            ?.addEventListener(
                'click',
                () => {
                    notify(
                        'Seller shop page will be connected later.'
                    );
                }
            );

        detail
            .querySelector(
                '[data-pd-size-guide]'
            )
            ?.addEventListener(
                'click',
                () => {
                    notify(
                        'Size guide preview can be added after the product details UI.'
                    );
                }
            );

        syncProductSelection();

        if (!dialog.open) {
            dialog.showModal();
        }

        document.body.style.overflow =
            'hidden';

        dialog.addEventListener(
            'close',
            () => {
                document.body.style.overflow =
                    '';
            },
            { once: true }
        );
    }


    document.addEventListener(
        'click',
        event => {
            const button =
                event.target.closest(
                    'button'
                );

            if (!button) return;

            if (
                button.hasAttribute(
                    'data-sub'
                )
            ) {
                state.subcategory =
                    state.subcategory ===
                    button.dataset.sub
                        ? ''
                        : button.dataset.sub;

                change();
            }

            if (button.dataset.sort) {
                state.sort =
                    button.dataset.sort;

                change();
            }

            if (button.dataset.view) {
                state.view =
                    button.dataset.view;

                render();
                writeUrl();
            }

            if (
                button.hasAttribute(
                    'data-reset'
                )
            ) {
                reset();
            }

            if (button.dataset.price) {
                [
                    state.min,
                    state.max,
                ] = button.dataset.price
                    .split(',')
                    .map(Number);

                change();
            }

            if (button.dataset.remove) {
                const key =
                    button.dataset.remove;

                if (
                    Array.isArray(
                        state[key]
                    )
                ) {
                    state[key] =
                        state[key].filter(
                            value =>
                                value !==
                                button.dataset
                                    .value
                        );
                } else if (
                    key === 'price'
                ) {
                    state.min = 0;
                    state.max = 5000;
                } else {
                    state[key] =
                        defaults()[key];
                }

                change();
            }

            if (
                button.hasAttribute(
                    'data-save'
                )
            ) {
                const id =
                    Number(
                        button.closest(
                            '.card'
                        ).dataset.id
                    );

                saved =
                    saved.includes(id)
                        ? saved.filter(
                            value =>
                                value !== id
                        )
                        : [...saved, id];

                try {
                    localStorage.setItem(
                        'bearly-category-saved-v1',
                        JSON.stringify(
                            saved
                        )
                    );
                } catch {
                    // LocalStorage unavailable.
                }

                render();

                $('bc-grid')
                    .querySelector(
                        `[data-id="${id}"] [data-save]`
                    )
                    ?.focus({
                        preventScroll: true,
                    });
            }

            if (
                button.hasAttribute(
                    'data-quick'
                )
            ) {
                const product =
                    products.find(
                        item =>
                            item.id ===
                            Number(
                                button.closest(
                                    '.card'
                                ).dataset.id
                            )
                    );

                if (product) {
                    showProduct(
                        product
                    );
                }
            }

            if (
                button.hasAttribute(
                    'data-close'
                )
            ) {
                button
                    .closest('dialog')
                    .close();
            }

            if (button.dataset.info) {
                const info = {
                    orders: [
                        'Your orders',
                        'Order tracking will be connected to approved buyer accounts during backend integration. No orders are created in this preview.',
                    ],
                    chat: [
                        'Chat with sellers',
                        'Messaging will be connected to buyer and seller accounts. This preview does not send messages.',
                    ],
                    help: [
                        'Explore Bearly',
                        'Search within Men’s Apparel, combine filters, save sample items on this browser, or open Quick view. Prices and ratings are illustrative.',
                    ],
                    about: [
                        'About Bearly',
                        'A marketplace for everyday finds across twelve categories. This page previews the Men’s Apparel collection.',
                    ],
                }[button.dataset.info];

                if (info) {
                    $('bc-info-title')
                        .textContent =
                        info[0];

                    $('bc-info-copy')
                        .textContent =
                        info[1];

                    $('bc-info-dialog')
                        .showModal();
                }
            }
        }
    );

    document
        .querySelectorAll('dialog')
        .forEach(dialog =>
            dialog.addEventListener(
                'click',
                event => {
                    if (
                        event.target !==
                        dialog
                    ) {
                        return;
                    }

                    const rect =
                        dialog.getBoundingClientRect();

                    if (
                        event.clientX <
                        rect.left ||
                        event.clientX >
                        rect.right ||
                        event.clientY <
                        rect.top ||
                        event.clientY >
                        rect.bottom
                    ) {
                        dialog.close();
                    }
                }
            )
        );

    window.addEventListener(
        'popstate',
        () => {
            state = readState(
                new URLSearchParams(
                    location.search
                ),
                products
            );

            limit = 20;

            syncInputs();
            render();
        }
    );

    syncInputs();
    render();
}
