/* Homepage-only catalog preview. No checkout or account mutations. */
export function selectProducts(products, {category = '', search = '', sort = 'featured'} = {}) {
    const query = search.trim().toLocaleLowerCase();
    const selected = products.filter(p => (!category || p.category_slug === category) && (!query || `${p.name} ${p.category}`.toLocaleLowerCase().includes(query)));
    if (sort === 'price-low') selected.sort((a, b) => a.price - b.price);
    if (sort === 'price-high') selected.sort((a, b) => b.price - a.price);
    if (sort === 'name') selected.sort((a, b) => a.name.localeCompare(b.name));
    return selected;
}
export function photoPosition(index) {
    return {x: (index % 4) * 100 / 3, y: Math.floor(index / 4) * 100 / 3};
}
const escapeHtml = value => String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const peso = value => new Intl.NumberFormat('en-PH', {style:'currency',currency:'PHP',maximumFractionDigits:0}).format(value);
if (typeof document !== 'undefined') initialize();
function initialize() {
    const dataElement = document.getElementById('home-data');
    if (!dataElement) return;
    const {categories, products} = JSON.parse(dataElement.textContent);
    const $ = id => document.getElementById(id);
    const state = {category:'',search:'',sort:'featured',limit:20};
    let previousFocus = null;
    const categoryLabel = slug => categories.find(c => c.slug === slug)?.name || '';
    const photo = product => {
        const {x,y} = photoPosition(product.photo);
        return `<span class="product-photo" style="--x:${x}%;--y:${y}%" role="img" aria-label="${escapeHtml(product.name)}"></span>`;
    };
    const card = p => `<article class="product-card"><button class="product-open" data-product="${p.id}" aria-label="View ${escapeHtml(p.name)}">${photo(p)}<div class="product-copy"><h3>${escapeHtml(p.name)}</h3><span class="product-category">${escapeHtml(p.category)}</span><strong class="product-price">${peso(p.price)}</strong><span class="view-product">View product <span aria-hidden="true">→</span></span></div></button></article>`;
    $('category-nav').innerHTML = categories.map(c => `<button class="category-button" data-category="${escapeHtml(c.slug)}" aria-pressed="false"><span class="material-symbols-outlined" aria-hidden="true">${escapeHtml(c.icon)}</span><span>${escapeHtml(c.name)}</span><span class="chevron" aria-hidden="true">›</span></button>`).join('');
    for (const c of categories) $('search-category').add(new Option(c.name,c.slug));
    function readUrl() {
        const params = new URLSearchParams(location.search);
        const category = params.get('category') || '';
        state.category = categories.some(c=>c.slug===category) ? category : '';
        state.search = (params.get('search') || '').slice(0,120);
        state.sort = ['featured','price-low','price-high','name'].includes(params.get('sort')) ? params.get('sort') : 'featured';
        state.limit = 20;
        $('search-category').value=state.category;
        $('search-input').value=state.search;
        $('sort').value=state.sort;
    }
    function updateUrl() {
        const url = new URL(location.href);
        for(const key of ['category','search','sort']) {
            if(state[key] && state[key] !== 'featured') url.searchParams.set(key,state[key]);
            else url.searchParams.delete(key);
        }
        url.hash='results';
        history.pushState({},'',url);
    }
    function render() {
        const filtered = Boolean(state.category || state.search || state.sort !== 'featured');
        const results = selectProducts(products,state);
        const visible=results.slice(0,state.limit);
        $('editorial').hidden=filtered;
        $('outdoor').hidden=filtered;
        $('more-section').hidden=filtered || visible.length<=12;
        $('results-tools').hidden=!filtered;
        $('results-title').textContent=state.category ? categoryLabel(state.category) : state.search ? 'Search results' : 'Daily discoveries';
        $('results-caption').textContent=filtered ? `${results.length} sample ${results.length===1?'find':'finds'}${state.search ? ` for “${state.search}”` : ''}` : 'Find something good across Bearly.';
        $('product-grid').innerHTML=(filtered?visible:visible.slice(0,12)).map(card).join('');
        $('more-grid').innerHTML=filtered?'':visible.slice(12).map(card).join('');
        $('empty').hidden=results.length!==0;
        $('load-more').hidden=visible.length>=results.length;
        $('result-count').textContent=`Showing ${visible.length} of ${results.length} sample products`;
        $('view-all').hidden=!filtered;
        $('view-all').textContent='Clear all';
        $('active-filters').innerHTML=[state.category ? `<button class="chip" data-clear="category">${escapeHtml(categoryLabel(state.category))} ×</button>` : '',state.search ? `<button class="chip" data-clear="search">${escapeHtml(state.search)} ×</button>` : ''].join('');
        document.querySelectorAll('.category-button').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.category===state.category)));
    }
    function change() {state.limit=20;updateUrl();render();$('results').scrollIntoView({block:'start'});}
    function reset(){state.category='';state.search='';state.sort='featured';$('search-category').value='';$('search-input').value='';$('sort').value='featured';change();}
    function menu(open) {
        const sidebar=$('sidebar');
        if(open) previousFocus=document.activeElement;
        sidebar.classList.toggle('is-open',open);
        $('sidebar-backdrop').hidden=!open;
        $('menu-toggle').setAttribute('aria-expanded',String(open));
        document.body.classList.toggle('menu-open',open);
        if(window.matchMedia('(max-width:720px)').matches) sidebar.inert=!open;
        if(open) $('menu-close').focus(); else previousFocus?.focus();
    }
    const mobileQuery=window.matchMedia('(max-width:720px)');
    function syncSidebar(){if(!mobileQuery.matches){$('sidebar').inert=false;menu(false);}else $('sidebar').inert=!$('sidebar').classList.contains('is-open');}
    mobileQuery.addEventListener('change',syncSidebar);syncSidebar();
    $('menu-toggle').addEventListener('click',()=>menu(true));
    $('menu-close').addEventListener('click',()=>menu(false));
    $('sidebar-backdrop').addEventListener('click',()=>menu(false));
    document.addEventListener('keydown',event=>{
        if(!$('sidebar').classList.contains('is-open'))return;
        if(event.key==='Escape'){menu(false);return;}
        if(event.key==='Tab'){
            const focusable=[...$('sidebar').querySelectorAll('button,a[href]')].filter(el=>el.getClientRects().length);
            const first=focusable[0],last=focusable.at(-1);
            if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
            else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
        }
    });
    $('search-form').addEventListener('submit',event=>{event.preventDefault();state.category=$('search-category').value;state.search=$('search-input').value.trim();change();});
    $('sort').addEventListener('change',()=>{state.sort=$('sort').value;change();});
    $('view-all').addEventListener('click',reset);
    $('reset-search').addEventListener('click',reset);
    $('load-more').addEventListener('click',()=>{const count=selectProducts(products,state).slice(0,state.limit).length;state.limit+=8;render();const cards=document.querySelectorAll('.product-open');cards[count]?.focus({preventScroll:true});});
    document.addEventListener('click',event=>{
        const category=event.target.closest('[data-category]');
        if(category){
            if(category.dataset.category === 'men-s-apparel'){
                event.preventDefault();
                window.location.href = '/products?category=men-s-apparel';
                return;
            }
            state.category=category.dataset.category;
            state.search='';
            state.sort='featured';
            $('search-category').value=state.category;
            $('search-input').value='';
            $('sort').value='featured';
            if($('sidebar').classList.contains('is-open'))menu(false);
            change();
        }
        const clear=event.target.closest('[data-clear]');
        if(clear){state[clear.dataset.clear]='';$('search-category').value=state.category;$('search-input').value=state.search;change();}
        const productButton=event.target.closest('[data-product]');
        if(productButton){
            const p=products.find(p=>p.id===Number(productButton.dataset.product));
            if(!p)return;
            $('product-detail').innerHTML=`<div class="detail-grid">${photo(p)}<div><p class="eyebrow">${escapeHtml(p.category)}</p><h2 id="product-title">${escapeHtml(p.name)}</h2><strong class="product-price">${peso(p.price)}</strong><p>${escapeHtml(p.description)}</p><p>Color: ${escapeHtml(p.color)}${p.sizes.length?`<br>Sample sizes: ${p.sizes.join(', ')}`:''}</p><small>Sample listing · Ordering is not enabled for preview products.</small></div></div>`;
            $('product-dialog').showModal();
        }
        const info=event.target.closest('[data-info]');
        if(info){const copy={orders:['Your orders','Order tracking will connect to your buyer account in the next integration. This homepage preview does not create or display real orders.'],chat:['Chat with sellers','Seller messaging will connect to your buyer account. No messages are sent from this homepage preview.'],about:['A find for everyone','Bearly brings twelve shopping categories together, from everyday essentials to your next favorite find.'],help:['How can we help?','Browse a category, search for a product, or open a product card for a closer look. Sample listings cannot be purchased.']}[info.dataset.info];if(copy){$('info-title').textContent=copy[0];$('info-copy').textContent=copy[1];$('info-dialog').showModal();}}
        const close=event.target.closest('[data-close]');if(close)close.closest('dialog').close();
    });
    document.querySelectorAll('dialog').forEach(dialog=>dialog.addEventListener('click',event=>{if(event.target===dialog){const r=dialog.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)dialog.close();}}));
    window.addEventListener('popstate',()=>{readUrl();render();});
    readUrl();render();
}

/* ===== MEN'S APPAREL PAGE ===== */
function initializeBuyerProductsPage() {
    const page = document.getElementById('buyer-products-page');
    if (!page) return;

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
    const conditionInputs = [...document.querySelectorAll('.bp-condition-input')];
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

        if (state.search) labels.push({key: 'search', label: `“${state.search}”`});
        if (state.subcategory) {
            const button = document.querySelector(`[data-bp-subcategory="${state.subcategory}"]`);
            labels.push({key: 'subcategory', label: button?.textContent.trim() || 'Subcategory'});
        }

        state.sizes.forEach(value => labels.push({key: `size:${value}`, label: value}));

        state.colors.forEach(value => {
            const input = colorInputs.find(item => item.value === value);
            labels.push({key: `color:${value}`, label: input?.dataset.label || value});
        });

        state.conditions.forEach(value => {
            const input = conditionInputs.find(item => item.value === value);
            labels.push({key: `condition:${value}`, label: input?.dataset.label || value});
        });

        if (state.minPrice !== null || state.maxPrice !== null) {
            const low = state.minPrice ?? 0;
            const high = state.maxPrice ?? '∞';
            labels.push({key: 'price', label: `₱${low}–₱${high}`});
        }

        if (state.freeShipping) labels.push({key: 'shipping', label: 'Free shipping'});

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
            document.querySelectorAll('[data-bp-subcategory]').forEach(button => button.classList.remove('is-active'));
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
            state.conditions = state.conditions.filter(item => item !== value);
            const input = conditionInputs.find(item => item.value === value);
            if (input) input.checked = false;
        }

        render();
    }

    function renderChips() {
        const labels = selectedLabels();
        selectedCount.textContent = String(labels.length);

        const chipMarkup = labels.map(item => `
            <span class="bp-selected-chip">
                ${item.label}
                <button type="button" data-bp-remove="${item.key}" aria-label="Remove ${item.label}">×</button>
            </span>
        `).join('');

        selectedChips.innerHTML = chipMarkup;

        toolbarChips.innerHTML = labels.length
            ? labels.map(item => `
                <span class="bp-toolbar-chip">
                    ${item.label}
                    <button type="button" data-bp-remove="${item.key}" aria-label="Remove ${item.label}">×</button>
                </span>
            `).join('')
            : '<span class="bp-toolbar-placeholder">All Men\'s Apparel</span>';
    }

    function filteredProducts() {
        return cards.map(readCard).filter(product => {
            if (state.search && !product.name.includes(text(state.search))) return false;

            if (state.minPrice !== null && product.price < state.minPrice) return false;
            if (state.maxPrice !== null && product.price > state.maxPrice) return false;

            if (state.subcategory && product.subcategory !== state.subcategory) return false;

            if (state.sizes.length && !state.sizes.some(size => product.sizes.includes(size))) return false;
            if (state.colors.length && !state.colors.some(color => product.colors.includes(color))) return false;
            if (state.conditions.length && !state.conditions.includes(product.condition)) return false;

            if (state.freeShipping && !product.freeShipping) return false;

            return true;
        });
    }

    function sortProducts(products) {
        const sorted = [...products];

        if (state.sort === 'price-low') sorted.sort((a, b) => a.price - b.price);
        if (state.sort === 'price-high') sorted.sort((a, b) => b.price - a.price);
        if (state.sort === 'name') sorted.sort((a, b) => a.name.localeCompare(b.name));

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
        headingCount.textContent = `${count} sample ${count === 1 ? 'result' : 'results'}`;
        resultStatus.textContent = `Showing ${count} sample ${count === 1 ? 'listing' : 'listings'}`;
        emptyState.hidden = count !== 0;
        grid.hidden = count === 0;

        // The sample page already contains 16 cards, so this is kept visually
        // for the concept but disabled when there is nothing else to reveal.
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

        document.querySelectorAll('[data-bp-subcategory]').forEach(button => {
            button.classList.remove('is-active');
        });

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
        const min = minPrice.value === '' ? null : Number(minPrice.value);
        const max = maxPrice.value === '' ? null : Number(maxPrice.value);

        state.minPrice = Number.isFinite(min) ? min : null;
        state.maxPrice = Number.isFinite(max) ? max : null;

        if (state.minPrice !== null && state.maxPrice !== null && state.minPrice > state.maxPrice) {
            [state.minPrice, state.maxPrice] = [state.maxPrice, state.minPrice];
            minPrice.value = state.minPrice;
            maxPrice.value = state.maxPrice;
        }

        render();
    });

    sizeInputs.forEach(input => {
        input.addEventListener('change', () => {
            state.sizes = sizeInputs.filter(item => item.checked).map(item => item.value);
            render();
        });
    });

    colorInputs.forEach(input => {
        input.addEventListener('change', () => {
            state.colors = colorInputs.filter(item => item.checked).map(item => item.value);
            render();
        });
    });

    conditionInputs.forEach(input => {
        input.addEventListener('change', () => {
            state.conditions = conditionInputs.filter(item => item.checked).map(item => item.value);
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

    document.querySelectorAll('[data-bp-subcategory]').forEach(button => {
        button.addEventListener('click', () => {
            const value = button.dataset.bpSubcategory;

            if (state.subcategory === value) {
                state.subcategory = '';
                button.classList.remove('is-active');
            } else {
                state.subcategory = value;
                document.querySelectorAll('[data-bp-subcategory]').forEach(item => item.classList.remove('is-active'));
                button.classList.add('is-active');
            }

            render();
        });
    });

    document.querySelectorAll('[data-bp-clear-all]').forEach(button => {
        button.addEventListener('click', clearAll);
    });

    document.addEventListener('click', event => {
        const remove = event.target.closest('[data-bp-remove]');
        if (remove) removeFilter(remove.dataset.bpRemove);

        const viewToggle = event.target.closest('[data-bp-view]');
        if (viewToggle) {
            document.querySelectorAll('[data-bp-view]').forEach(button => button.classList.remove('is-active'));
            viewToggle.classList.add('is-active');
            grid.classList.toggle('is-list', viewToggle.dataset.bpView === 'list');
        }

        const preview = event.target.closest('[data-bp-preview]');
        if (preview) {
            window.alert(`${preview.dataset.bpPreview}\n\nFront-end preview only. Product details will connect to the backend later.`);
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
        if (event.key === 'Escape') setSidebar(false);
    });

    render();
}

if (typeof document !== 'undefined') {
    initializeBuyerProductsPage();
}
