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
        if(category){state.category=category.dataset.category;state.search='';state.sort='featured';$('search-category').value=state.category;$('search-input').value='';$('sort').value='featured';if($('sidebar').classList.contains('is-open'))menu(false);change();}
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
