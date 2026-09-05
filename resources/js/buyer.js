/* BEARLY shared buyer JavaScript: homepage + legacy page + category V2. */
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
    if (!page || document.getElementById('bc-data')) return;

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


/* ===== CATEGORY V2: initializes only when #bc-data exists ===== */
export const defaults = () => ({search:'',subcategory:'',min:0,max:5000,size:[],color:[],condition:[],location:[],shipping:false,voucher:false,rating:0,sort:'featured',saved:false,view:'grid'});
export function filterCatalog(products,state,savedIds=[]) {
    const search=state.search.trim().toLowerCase();
    const rows=products.filter(p=>(!search||p.name.toLowerCase().includes(search))&&(!state.subcategory||p.subcategory===state.subcategory)&&p.price>=state.min&&p.price<=state.max&&(!state.size.length||state.size.some(size=>p.sizes.includes(size)))&&['color','condition','location'].every(k=>!state[k].length||state[k].includes(p[k]))&&(!state.shipping||p.free_shipping)&&(!state.voucher||p.voucher)&&p.rating>=state.rating&&(!state.saved||savedIds.includes(p.id)));
    if(state.sort==='price-low') rows.sort((a,b)=>a.price-b.price||a.id-b.id);
    if(state.sort==='price-high') rows.sort((a,b)=>b.price-a.price||a.id-b.id);
    if(state.sort==='newest') rows.sort((a,b)=>b.id-a.id);
    return rows;
}
export function readState(params, products) {
    const s=defaults();
    s.search=(params.get('q')||'').slice(0,120);
    s.subcategory=products.some(p=>p.subcategory===params.get('sub'))?params.get('sub'):'';
    for(const key of ['size','color','condition','location']){
        const valid=new Set(products.flatMap(p=>key==='size'?p.sizes:[p[key]]));
        s[key]=[...new Set(params.getAll(key))].filter(v=>valid.has(v));
    }
    for(const key of ['min','max']){const value=Number(params.get(key)??s[key]);if(Number.isFinite(value))s[key]=Math.min(5000,Math.max(0,value));}
    if(s.min>s.max){s.min=0;s.max=5000;}
    s.shipping=params.get('shipping')==='1';s.voucher=params.get('voucher')==='1';s.saved=params.get('saved')==='1';s.rating=params.get('rating')==='4'?4:0;
    s.sort=['featured','newest','price-low','price-high'].includes(params.get('sort'))?params.get('sort'):'featured';s.view=params.get('view')==='list'?'list':'grid';return s;
}
const categoryEscapeHtml=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const price=n=>new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP',maximumFractionDigits:0}).format(n);
if(typeof document!=='undefined'&&document.getElementById('bc-data'))init();
function init(){
    const $=id=>document.getElementById(id), products=JSON.parse($('bc-data').textContent), validIds=new Set(products.map(p=>p.id));
    let state=readState(new URLSearchParams(location.search),products),limit=20,saved=[];
    try{const stored=JSON.parse(localStorage.getItem('bearly-category-saved-v1')||'[]');if(Array.isArray(stored))saved=stored.filter(id=>validIds.has(id));}catch{}
    const colors={Blue:'#305e94',Black:'#26292a',White:'#fff',Beige:'#d8c8a4',Green:'#68745a',Gray:'#8a8b8e',Brown:'#79543b'};
    function option(container,key,values){$(container).innerHTML=values.map(value=>key==='size'?`<label><input type="checkbox" data-field="size" value="${categoryEscapeHtml(value)}"><span>${categoryEscapeHtml(value)}</span></label>`:key==='color'?`<label><input type="checkbox" data-field="color" value="${categoryEscapeHtml(value)}"><span class="swatch" style="--swatch:${colors[value]||'#aaa'}"></span>${categoryEscapeHtml(value)}</label>`:`<label><input type="checkbox" data-field="${key}" value="${categoryEscapeHtml(value)}">${categoryEscapeHtml(value)}</label>`).join('');}
    option('bc-size-options','size',[...new Set(products.flatMap(p=>p.sizes))]);
    for(const key of ['color','condition','location'])option(`bc-${key}-options`,key,[...new Set(products.map(p=>p[key]))]);
    function chips(){const a=[];if(state.search)a.push(['search',state.search,`Search: ${state.search}`]);if(state.subcategory)a.push(['subcategory',state.subcategory,state.subcategory]);if(state.min||state.max!==5000)a.push(['price','',`${price(state.min)}–${price(state.max)}`]);for(const key of ['size','color','condition','location'])state[key].forEach(value=>a.push([key,value,value]));if(state.shipping)a.push(['shipping','','Free shipping']);if(state.voucher)a.push(['voucher','','Has voucher']);if(state.rating)a.push(['rating','','4 stars & up']);if(state.saved)a.push(['saved','','Saved items']);return a;}
    function photoStyle(el,p){el.style.setProperty('--x',`${p.photo%5*25}%`);el.style.setProperty('--y',`${Math.floor(p.photo/5)*100/3}%`);}
    function render(){
        const filtered=filterCatalog(products,state,saved),shown=filtered.slice(0,limit),fragment=document.createDocumentFragment();
        for(const p of shown){const card=$('bc-card-template').content.firstElementChild.cloneNode(true);card.dataset.id=p.id;photoStyle(card.querySelector('.photo'),p);card.querySelector('.photo').setAttribute('aria-label',p.name);card.querySelector('h2').textContent=p.name;card.querySelector('.condition').textContent=p.condition;card.querySelector('.price-row strong').textContent=price(p.price);card.querySelector('.color-label').textContent=p.color;card.querySelectorAll('[data-quick]').forEach(b=>b.setAttribute('aria-label',`Quick view: ${p.name}`));const heart=card.querySelector('[data-save]');heart.setAttribute('aria-pressed',String(saved.includes(p.id)));heart.setAttribute('aria-label',`${saved.includes(p.id)?'Remove':'Save'} ${p.name}`);fragment.append(card);}
        $('bc-grid').replaceChildren(fragment);$('bc-grid').classList.toggle('is-list',state.view==='list');$('bc-count').textContent=`${filtered.length} sample products`;$('bc-status').textContent=`Showing ${shown.length} of ${filtered.length} sample products`;$('bc-empty').hidden=filtered.length>0;$('bc-more').hidden=shown.length>=filtered.length;
        const selected=chips(),markup=selected.map(([key,value,label])=>`<button class="chip" data-remove="${key}" data-value="${categoryEscapeHtml(value)}" aria-label="Remove ${categoryEscapeHtml(label)}">${categoryEscapeHtml(label)} ×</button>`).join('');$('bc-chips').innerHTML=markup;$('bc-side-chips').innerHTML=markup;$('bc-selected').textContent=`Selected (${selected.length})`;$('bc-mobile-count').textContent=selected.length?`(${selected.length})`:'';$('bc-show-results').textContent=`Show ${filtered.length} results`;$('bc-saved-count').textContent=saved.length;$('bc-saved-filter').setAttribute('aria-pressed',String(state.saved));
        document.querySelectorAll('[data-sub]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.sub===state.subcategory)));document.querySelectorAll('[data-sort]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.sort===state.sort)));document.querySelectorAll('[data-view]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.view===state.view)));
    }
    function syncInputs(){for(const input of document.querySelectorAll('[data-field]')){const key=input.dataset.field;input.checked=Array.isArray(state[key])?state[key].includes(input.value):key==='rating'?Number(input.value)===state.rating:Boolean(state[key]);}$('bc-search').value=state.search;$('bc-within').value=state.search;for(const key of ['min','max']){$(`bc-${key}`).value=state[key];$(`bc-${key}-range`).value=state[key];}$('bc-price-error').hidden=true;}
    function writeUrl(){const url=new URL(location.href);const keys=['q','sub','size','color','condition','location','min','max','shipping','voucher','rating','sort','saved','view'];keys.forEach(key=>url.searchParams.delete(key));url.searchParams.set('category','men-s-apparel');if(state.search)url.searchParams.set('q',state.search);if(state.subcategory)url.searchParams.set('sub',state.subcategory);for(const key of ['size','color','condition','location'])state[key].forEach(v=>url.searchParams.append(key,v));if(state.min)url.searchParams.set('min',state.min);if(state.max!==5000)url.searchParams.set('max',state.max);for(const k of ['shipping','voucher','saved'])if(state[k])url.searchParams.set(k,'1');if(state.rating)url.searchParams.set('rating',state.rating);if(state.sort!=='featured')url.searchParams.set('sort',state.sort);if(state.view!=='grid')url.searchParams.set('view',state.view);history.replaceState({},'',url);}
    function change(){limit=20;syncInputs();render();writeUrl();}
    function reset(){const view=state.view;state=defaults();state.view=view;change();}
    $('bc-search-form').addEventListener('submit',e=>{e.preventDefault();state.search=$('bc-search').value.trim();change();});
    $('bc-within').addEventListener('input',()=>{state.search=$('bc-within').value.trim();$('bc-search').value=state.search;limit=20;render();writeUrl();});
    document.addEventListener('change',e=>{const input=e.target.closest('[data-field]');if(!input)return;const k=input.dataset.field;if(Array.isArray(state[k]))state[k]=[...document.querySelectorAll(`[data-field="${k}"]:checked`)].map(el=>el.value);else state[k]=k==='rating'?Number(input.value):input.checked;change();});
    function updatePrice(key,range){let value=Number($(`bc-${key}${range?'-range':''}`).value);if(!Number.isFinite(value))return;value=Math.round(Math.min(5000,Math.max(0,value)));const candidate={...state,[key]:value};if(candidate.min>candidate.max){if(range)candidate[key]=key==='min'?candidate.max:candidate.min;else{$('bc-price-error').hidden=false;return;}}state.min=candidate.min;state.max=candidate.max;change();}
    for(const key of ['min','max']){$(`bc-${key}`).addEventListener('change',()=>updatePrice(key,false));$(`bc-${key}-range`).addEventListener('input',()=>updatePrice(key,true));}
    $('bc-location-search').addEventListener('input',e=>{const q=e.target.value.toLowerCase();document.querySelectorAll('#bc-location-options label').forEach(label=>label.hidden=!label.textContent.toLowerCase().includes(q));});
    $('bc-collapse').addEventListener('click',()=>{const nodes=[...$('bc-filter-panel').querySelectorAll('details')];const expand=!nodes.some(el=>el.open);nodes.forEach(el=>el.open=expand);$('bc-collapse').textContent=expand?'Collapse all':'Expand all';$('bc-collapse').setAttribute('aria-expanded',String(expand));});
    $('bc-more').addEventListener('click',()=>{const previousCount=$('bc-grid').children.length;limit+=20;render();$('bc-grid').children[previousCount]?.querySelector('[data-quick]')?.focus({preventScroll:true});});
    $('bc-saved-filter').addEventListener('click',()=>{state.saved=!state.saved;change();});
    const mobile=$('bc-mobile-dialog'),panel=$('bc-filter-panel');
    $('bc-open-filters').addEventListener('click',()=>{$('bc-mobile-content').append(panel);mobile.showModal();document.body.style.overflow='hidden';});
    mobile.addEventListener('close',()=>{$('bc-sidebar').append(panel);document.body.style.overflow='';$('bc-open-filters').focus();});
    $('bc-show-results').addEventListener('click',()=>mobile.close());
    const mq=matchMedia('(min-width:721px)');mq.addEventListener('change',()=>{if(mq.matches&&mobile.open)mobile.close();});
    function showProduct(p){const sizes=p.sizes.length?`<label>Sample size <select aria-label="Select sample size">${p.sizes.map(s=>`<option>${categoryEscapeHtml(s)}</option>`).join('')}</select></label>`:'';$('bc-product-detail').innerHTML=`<div class="detail"><div class="photo" role="img" aria-label="${categoryEscapeHtml(p.name)}"></div><div><p>${categoryEscapeHtml(p.subcategory)}</p><h2 id="bc-product-title">${categoryEscapeHtml(p.name)}</h2><strong>${price(p.price)}</strong><dl><dt>Condition</dt><dd>${categoryEscapeHtml(p.condition)}</dd><dt>Color</dt><dd>${categoryEscapeHtml(p.color)}</dd><dt>Seller location</dt><dd>${categoryEscapeHtml(p.location)}</dd><dt>Sample rating</dt><dd>${p.rating||'Not rated'}</dd></dl>${sizes}<p>${categoryEscapeHtml(p.description)}</p><small>Preview only. These sample listings cannot be purchased.</small></div></div>`;photoStyle($('bc-product-detail').querySelector('.photo'),p);$('bc-product-dialog').showModal();}
    document.addEventListener('click',e=>{const button=e.target.closest('button');if(!button)return;
        if(button.hasAttribute('data-sub')){state.subcategory=state.subcategory===button.dataset.sub?'':button.dataset.sub;change();}
        if(button.dataset.sort){state.sort=button.dataset.sort;change();}
        if(button.dataset.view){state.view=button.dataset.view;render();writeUrl();}
        if(button.hasAttribute('data-reset'))reset();
        if(button.dataset.price){[state.min,state.max]=button.dataset.price.split(',').map(Number);change();}
        if(button.dataset.remove){const key=button.dataset.remove;if(Array.isArray(state[key]))state[key]=state[key].filter(v=>v!==button.dataset.value);else if(key==='price'){state.min=0;state.max=5000;}else state[key]=defaults()[key];change();}
        if(button.hasAttribute('data-save')){const id=Number(button.closest('.card').dataset.id);saved=saved.includes(id)?saved.filter(v=>v!==id):[...saved,id];try{localStorage.setItem('bearly-category-saved-v1',JSON.stringify(saved));}catch{}render();$('bc-grid').querySelector(`[data-id="${id}"] [data-save]`)?.focus({preventScroll:true});}
        if(button.hasAttribute('data-quick')){const p=products.find(p=>p.id===Number(button.closest('.card').dataset.id));if(p)showProduct(p);}
        if(button.hasAttribute('data-close'))button.closest('dialog').close();
        if(button.dataset.info){const info={orders:['Your orders','Order tracking will be connected to approved buyer accounts during backend integration. No orders are created in this preview.'],chat:['Chat with sellers','Messaging will be connected to buyer and seller accounts. This preview does not send messages.'],help:['Explore Bearly','Search within Men’s Apparel, combine filters, save sample items on this browser, or open Quick view. Prices and ratings are illustrative.'],about:['About Bearly','A marketplace for everyday finds across twelve categories. This page previews the Men’s Apparel collection.']}[button.dataset.info];if(info){$('bc-info-title').textContent=info[0];$('bc-info-copy').textContent=info[1];$('bc-info-dialog').showModal();}}
    });
    document.querySelectorAll('dialog').forEach(d=>d.addEventListener('click',e=>{if(e.target!==d)return;const r=d.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)d.close();}));
    window.addEventListener('popstate',()=>{state=readState(new URLSearchParams(location.search),products);limit=20;syncInputs();render();});
    syncInputs();render();
}
