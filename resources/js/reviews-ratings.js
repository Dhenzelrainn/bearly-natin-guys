const REVIEWS_KEY=window.bearlyStorageKey?.('preview-reviews')||'bearly-reviews-v1';
let reviewRating=0, reviewPhoto='';
const reviewLabels=['','Poor','Fair','Good','Very Good','Excellent'];
const reviewCatalog=[
  {order:{id:'BRY-101972',date:'Mar 05, 2024'},item:{name:'Wireless Earbuds',variation:'White',qty:1,image:'/images/products/electronics/electronics-07-01.jpg'},index:0},
  {order:{id:'BRY-101804',date:'Feb 20, 2024'},item:{name:'Classic Tote Bag',variation:'Natural',qty:1,image:'/images/products/women/womens-01-01.jpg'},index:0},
  {order:{id:'BRY-100923',date:'Jan 15, 2024'},item:{name:'Everyday Basic Tee',variation:'Black - Medium',qty:1,image:'/images/products/men/mens-01-01.jpg'},index:0},
  {order:{id:'BRY-100641',date:'Jan 02, 2024'},item:{name:'Gentle Facial Cleanser',variation:'Standard',qty:1,image:'/images/products/health-beauty/health-beauty-01-01.jpg'},index:0}
];
const demoReviews=[
  {orderId:'BRY-100923',itemIndex:0,rating:5,comment:'Good quality and very comfortable to wear. The fabric is soft and fits well. Sulit for the price!',reviewedAt:'Jan 18, 2024',photo:'/images/products/men/mens-01-01.jpg',photos:['/images/products/men/mens-01-01.jpg','/images/products/men/mens-02-01.jpg','/images/products/men/mens-03-01.jpg']},
  {orderId:'BRY-100641',itemIndex:0,rating:4,comment:'Effective and gentle on skin. Noticed improvements after a week of using it. Will buy again.',reviewedAt:'Jan 05, 2024',photo:'/images/products/health-beauty/health-beauty-01-01.jpg',photos:['/images/products/health-beauty/health-beauty-01-01.jpg']}
];
function reviewEsc(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))}
function reviewStoreUrl(value){
  try{
    const current=new URL(window.location.href);
    const parsed=new URL(String(value||''),current.origin);
    if(parsed.origin!==current.origin||!/(^|\/)stores\/[^/]+\/?$/.test(parsed.pathname))return '';
    return `${parsed.pathname}${parsed.search}${parsed.hash}`;
  }catch{return ''}
}
function reviewImage(src){
  const value=String(src||'');
  if(/^(\/images\/|\/storage\/|data:image\/)/.test(value))return value;
  try{
    const parsed=new URL(value,window.location.origin);
    if(parsed.origin===window.location.origin&&/^(\/images\/|\/storage\/)/.test(parsed.pathname))return parsed.pathname;
  }catch{}
  return '/images/bearly-logo.png';
}
let serverReviewMap={},serverReviewsLoaded=false,serverReviewsFetch=null,reviewPhotoFile=null;
async function ensureServerReviews(){
  if(serverReviewsLoaded||serverReviewsFetch)return serverReviewsFetch;
  serverReviewsFetch=(async()=>{
    try{await (window.bearlyEnsurePurchases?window.bearlyEnsurePurchases():Promise.resolve()).catch(()=>{})}catch{}
    try{
      const response=await fetch('/profile/reviews/data',{headers:{'Accept':'application/json'}});
      const result=await response.json().catch(()=>({}));
      if(!response.ok||!result.data||typeof result.data!=='object')throw new Error('reviews unavailable');
      serverReviewMap=result.data;
    }catch{
      serverReviewMap={};
    }finally{
      serverReviewsLoaded=true;
    }
  })();
  return serverReviewsFetch;
}
function livePurchases(){return window.bearlyPurchasesServer==='live'}
function orderItemIdFor(orderId,index){
  const order=reviewOrders().find(o=>String(o.id)===String(orderId));
  const item=order&&Array.isArray(order.items)?order.items[Number(index)]:null;
  return item&&item.order_item_id?String(item.order_item_id):'';
}
function serverReviewFor(orderId,index){
  const id=orderItemIdFor(orderId,index);
  if(!id||!serverReviewMap[id])return null;
  const found=serverReviewMap[id];
  return {orderId,itemIndex:Number(index),orderItemId:id,rating:Number(found.rating)||0,comment:found.comment||'',photo:found.photo||'',photos:found.photo?[found.photo]:[],reviewedAt:found.reviewed_at||'',server:true};
}
function getStoredReviews(){try{const x=JSON.parse(localStorage.getItem(REVIEWS_KEY)||'null');return Array.isArray(x)?x:[]}catch{return []}}
function getReviews(){
  const merged=(livePurchases()?[]:demoReviews).map(review=>({...review}));
  getStoredReviews().forEach(review=>{const index=merged.findIndex(item=>item.orderId===review?.orderId&&Number(item.itemIndex)===Number(review?.itemIndex));if(index>=0)merged[index]={...merged[index],...review};else merged.push(review)});
  return merged;
}
function saveReviews(x){localStorage.setItem(REVIEWS_KEY,JSON.stringify(x))}
function reviewOrders(){
  if(typeof window.bearlyGetOrders==='function') return window.bearlyGetOrders();
  try {
    const saved=JSON.parse(localStorage.getItem(window.bearlyStorageKey?.('preview-orders')||'bearly-orders-v1')||'null');
    if(Array.isArray(saved)&&saved.length) return saved;
  } catch {}
  return Array.isArray(window.bearlyDemoOrders)?window.bearlyDemoOrders:[];
}
function reviewItems(){
  const live=livePurchases();
  const items=live?[]:reviewCatalog.map(entry=>({...entry,order:{...entry.order},item:{...entry.item}}));
  reviewOrders().filter(o=>{
    if(live)return o&&o.server&&o.status==='completed';
    return o.status==='completed'||o.status==='cancelled';
  }).forEach(order=>(order.items||[]).forEach((item,index)=>{if(!items.some(entry=>String(entry.order.id)===String(order.id)&&Number(entry.index)===Number(index)))items.push({order,item,index})}));
  return items;
}
function completedItems(){return reviewItems()}
function reviewFor(orderId,index){return getReviews().find(r=>r.orderId===orderId&&Number(r.itemIndex)===Number(index))||serverReviewFor(orderId,index)}
function stars(n){const rating=Math.max(0,Math.min(5,Number(n)||0));return `<span class="review-static-stars" aria-label="${rating} out of 5 stars">${'★'.repeat(rating)}${'☆'.repeat(5-rating)}</span>`}
function reviewDetails({order,item,index}){const storeUrl=reviewStoreUrl(item.store_url);return `<div class="review-product-details"><h3>${reviewEsc(item.name||'Product')}</h3><p>Variation: ${reviewEsc(item.variation||'Standard')}</p><p>Order #${reviewEsc(order.id||'')} <span aria-hidden="true">|</span> Purchased ${reviewEsc(order.date||'')}</p>${storeUrl?`<a class="review-store-link" href="${reviewEsc(storeUrl)}"><span class="material-symbols-outlined" aria-hidden="true">storefront</span>View Store</a>`:''}</div>`}
function inlineStars(orderId,index){return Array.from({length:5},(_,position)=>`<button class="review-inline-star" type="button" data-order="${reviewEsc(orderId||'')}" data-index="${index}" data-rating="${position+1}" aria-label="Rate ${position+1} out of 5 stars">☆</button>`).join('')}
function pendingReviewHTML(entry){const {order,item,index}=entry;return `<article class="review-item-card review-pending-card"><img class="review-product-image" src="${reviewEsc(reviewImage(item.image))}" onerror="this.onerror=null;this.src='/images/bearly-logo.png'" alt="${reviewEsc(item.name||'Product')}">${reviewDetails(entry)}<div class="review-rating-block"><span>How was your purchase?</span><div class="review-outline-stars" aria-label="Choose a rating">${inlineStars(order.id,index)}</div></div><button class="review-action primary review-open" type="button" data-order="${reviewEsc(order.id||'')}" data-index="${index}">Write Review</button></article>`}
function reviewedReviewHTML(entry){const {order,item,index}=entry,r=reviewFor(order.id,index),photos=(r?.photos?.length?r.photos:(r?.photo?[r.photo]:[])).slice(0,3);return `<article class="review-item-card review-completed-card"><img class="review-product-image" src="${reviewEsc(reviewImage(item.image))}" onerror="this.onerror=null;this.src='/images/bearly-logo.png'" alt="${reviewEsc(item.name||'Product')}">${reviewDetails(entry)}<div class="review-submitted"><div class="review-submitted-top">${stars(r?.rating||0)}<time>${reviewEsc(r?.reviewedAt||'')}</time></div><p>${reviewEsc(r?.comment||'')}</p>${photos.length?`<div class="review-photo-strip">${photos.map(photo=>`<img src="${reviewEsc(reviewImage(photo))}" onerror="this.onerror=null;this.src='/images/bearly-logo.png'" alt="Review photo">`).join('')}</div>`:''}</div><div class="review-card-actions"><button class="review-action edit review-open" type="button" data-order="${reviewEsc(order.id||'')}" data-index="${index}">Edit Review</button><button class="review-more" type="button" aria-label="More review actions"><span class="material-symbols-outlined" aria-hidden="true">more_vert</span></button></div></article>`}
function submitServerReview(orderItemId, comment){
  const form=new FormData();
  form.append('order_item_id',orderItemId);
  form.append('rating',String(reviewRating));
  form.append('comment',comment);
  if(reviewPhotoFile)form.append('photo',reviewPhotoFile);
  const token=document.querySelector('.account-logout-form input[name="_token"]')?.value||'';
  if(token)form.append('_token',token);
  fetch('/profile/reviews',{method:'POST',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},body:form})
    .then(async response=>{
      const result=await response.json().catch(()=>({}));
      if(!response.ok)throw new Error(Object.values(result.errors||{}).flat()[0]||result.message||'Unable to submit this review.');
      return result.data||{};
    })
    .then(data=>{
      serverReviewMap[orderItemId]={rating:data.rating||reviewRating,comment:data.comment||comment,photo:data.photo||'',reviewed_at:new Date().toLocaleDateString('en-US',{month:'short',day:'2-digit',year:'numeric'})};
      reviewPhotoFile=null;
      closeReview();
      renderReviews();
      toastReview();
    })
    .catch(error=>{alert(error.message||'Unable to submit this review.')});
}
async function renderReviews(){
  const list=document.querySelector('#review-list'),reviewedList=document.querySelector('#reviewed-list'),empty=document.querySelector('#reviews-empty');
  if(!list)return;
  let items=[],pending=[],reviewed=[];
  try{
    await ensureServerReviews();
    items=reviewItems();
    pending=items.filter(({order,index})=>!reviewFor(order.id,index));
    reviewed=items.filter(({order,index})=>reviewFor(order.id,index));
  }catch(error){
    console.error('[reviews] failed to load review items',error);
  }
  const pendingCount=document.querySelector('#reviews-to-review-count'),reviewedCount=document.querySelector('#reviews-reviewed-count');
  if(pendingCount)pendingCount.textContent=`(${pending.length})`;
  if(reviewedCount)reviewedCount.textContent=`(${reviewed.length})`;
  const safeCards=(entries,renderer)=>entries.map(entry=>{try{return renderer(entry)}catch(error){console.error('[reviews] failed to render a review card',error);return ''}}).join('');
  list.innerHTML=safeCards(pending,pendingReviewHTML);list.hidden=!pending.length;
  if(reviewedList){reviewedList.innerHTML=safeCards(reviewed,reviewedReviewHTML);reviewedList.hidden=!reviewed.length}
  document.querySelector('[data-review-section-empty="to-review"]')?.toggleAttribute('hidden',pending.length > 0 || pending.length + reviewed.length === 0);
  document.querySelector('[data-review-section-empty="reviewed"]')?.toggleAttribute('hidden',reviewed.length > 0 || pending.length + reviewed.length === 0);
  if(empty)empty.hidden=!!(pending.length||reviewed.length);
}
function activateReviewTab(filter){
  const selected=filter==='reviewed'?'reviewed':'to-review';
  document.querySelectorAll('#reviews-panel .review-tab').forEach(tab=>{
    const active=tab.dataset.reviewFilter===selected;
    tab.classList.toggle('active',active);
    tab.setAttribute('aria-selected',active?'true':'false');
    tab.tabIndex=active?0:-1;
  });
  document.querySelectorAll('#reviews-panel .review-section').forEach(section=>{
    section.hidden=section.dataset.reviewSection!==selected;
  });
}
function escapeReview(v=''){return String(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))}
function setStars(n){reviewRating=n;document.querySelectorAll('#review-stars button').forEach(b=>b.classList.toggle('active',Number(b.dataset.rating)<=n));const l=document.querySelector('#rating-label');if(l)l.textContent=reviewLabels[n]||'Select a rating'}
function openReview(orderId,index){
  const key=String(orderId??''),idx=Number(index);
  let found=completedItems().find(x=>String(x.order?.id)===key&&Number(x.index)===idx);
  if(!found){
    const order=reviewOrders().find(o=>String(o.id)===key);
    const item=order&&Array.isArray(order.items)?order.items[idx]:null;
    if(!order||!item)return;
    found={order,item,index:idx};
  }
  const old=reviewFor(key,idx);document.querySelector('#review-order-id').value=orderId;document.querySelector('#review-item-index').value=index;document.querySelector('#review-order-item-id').value=orderItemIdFor(orderId,index);document.querySelector('#review-product-name').textContent=found.item.name||'Product';document.querySelector('#review-comment').value=old?.comment||'';document.querySelector('#review-char-count').textContent=(old?.comment||'').length;reviewPhoto=old?.photo||'';setStars(old?.rating||0);showReviewPhoto();document.querySelector('#review-modal').hidden=false;}
function closeReview(){document.querySelector('#review-modal').hidden=true;reviewPhotoFile=null;}
function showReviewPhoto(){const box=document.querySelector('#review-photo-preview');if(!box)return;if(reviewPhoto){box.innerHTML=`<img src="${reviewPhoto}" alt="Selected review photo"><button id="remove-review-photo" type="button">×</button>`;box.hidden=false;document.querySelector('#remove-review-photo').onclick=()=>{reviewPhoto='';reviewPhotoFile=null;const input=document.querySelector('#review-photo');if(input)input.value='';showReviewPhoto()}}else{box.innerHTML='';box.hidden=true}}
function toastReview(){const t=document.querySelector('#review-toast');if(!t)return;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),1800)}
function initReviews(){
  if (window.bearlyReviewsInitialized) return;
  window.bearlyReviewsInitialized = true;

  activateReviewTab(document.querySelector('#reviews-panel .review-tab[aria-selected="true"]')?.dataset.reviewFilter);
  const reviewTabs=[...document.querySelectorAll('#reviews-panel .review-tab')];
  reviewTabs.forEach((tab,index) => {
    tab.addEventListener('click', () => {
      activateReviewTab(tab.dataset.reviewFilter);
    });
    tab.addEventListener('keydown',event=>{
      let nextIndex=index;
      if(event.key==='ArrowRight')nextIndex=(index+1)%reviewTabs.length;
      else if(event.key==='ArrowLeft')nextIndex=(index-1+reviewTabs.length)%reviewTabs.length;
      else if(event.key==='Home')nextIndex=0;
      else if(event.key==='End')nextIndex=reviewTabs.length-1;
      else return;
      event.preventDefault();
      reviewTabs[nextIndex].focus();
      activateReviewTab(reviewTabs[nextIndex].dataset.reviewFilter);
    });
  });

  document.addEventListener('click', e => {
    const inlineStar = e.target.closest('.review-inline-star');
    if (inlineStar) {
      openReview(inlineStar.dataset.order, inlineStar.dataset.index);
      setStars(Number(inlineStar.dataset.rating));
      return;
    }

    const open = e.target.closest('.review-open');
    if (open) {
      openReview(open.dataset.order, open.dataset.index);
      return;
    }

    const purchaseRate = e.target.closest('.order-rate-product');
    if (purchaseRate) {
      openReview(purchaseRate.dataset.order, purchaseRate.dataset.index);
    }
  });

  document.querySelectorAll('#review-stars button').forEach(b => {
    b.addEventListener('click', () => setStars(Number(b.dataset.rating)));
  });

  document.querySelector('#close-review-modal')?.addEventListener('click', closeReview);
  document.querySelector('#cancel-review')?.addEventListener('click', closeReview);
  document.querySelector('#review-modal')?.addEventListener('click', e => {
    if (e.target.id === 'review-modal') closeReview();
  });

  document.querySelector('#review-comment')?.addEventListener('input', e => {
    const count = document.querySelector('#review-char-count');
    if (count) count.textContent = e.target.value.length;
  });

  document.querySelector('#select-review-photo')?.addEventListener('click', () => document.querySelector('#review-photo')?.click());
  document.querySelector('#review-photo')?.addEventListener('change', e => {
    const f = e.target.files?.[0];
    if (!f) return;
    if (f.size > 1024 * 1024) {
      alert('Please choose an image under 1 MB.');
      return;
    }
    reviewPhotoFile=f;
    const r = new FileReader();
    r.onload = () => {
      reviewPhoto = r.result;
      showReviewPhoto();
    };
    r.readAsDataURL(f);
  });

  document.querySelector('#review-form')?.addEventListener('submit', e => {
    e.preventDefault();
    if (!reviewRating) {
      alert('Please select a star rating.');
      return;
    }

    const orderId = document.querySelector('#review-order-id')?.value;
    const itemIndex = Number(document.querySelector('#review-item-index')?.value);
    const comment = document.querySelector('#review-comment')?.value.trim();
    const orderItemId = document.querySelector('#review-order-item-id')?.value || '';

    if (!orderId || !Number.isFinite(itemIndex) || !comment) return;

    if (orderItemId) {
      submitServerReview(orderItemId, comment);
      return;
    }

    const reviews = getReviews();
    const previous = reviewFor(orderId, itemIndex);
    const data = { orderId, itemIndex, rating: reviewRating, comment, photo: reviewPhoto, photos: previous?.photos || (reviewPhoto ? [reviewPhoto] : []), reviewedAt: previous?.reviewedAt || new Date().toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' }), updatedAt: new Date().toISOString() };
    const idx = reviews.findIndex(r => r.orderId === orderId && Number(r.itemIndex) === itemIndex);
    if (idx >= 0) reviews[idx] = data; else reviews.push(data);
    saveReviews(reviews);
    closeReview();
    renderReviews();
    toastReview();
  });

  document.querySelector('#reviews-go-purchases')?.addEventListener('click', () => document.querySelector('[data-account-tab="purchases"]')?.click());
  renderReviews();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initReviews);
} else {
  initReviews();
}

window.renderReviews = renderReviews;
window.bearlyOpenReview = openReview;
window.bearlyReviewsReload = () => { serverReviewsLoaded = false; serverReviewsFetch = null; return renderReviews(); };
