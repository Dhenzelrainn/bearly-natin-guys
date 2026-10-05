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
function reviewImage(src){const value=String(src||'');return /^(\/images\/|\/storage\/|data:image\/)/.test(value)?value:'/images/bearly-logo.png'}
function getStoredReviews(){try{const x=JSON.parse(localStorage.getItem(REVIEWS_KEY)||'null');return Array.isArray(x)?x:[]}catch{return []}}
function getReviews(){
  const merged=demoReviews.map(review=>({...review}));
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
  const items=reviewCatalog.map(entry=>({...entry,order:{...entry.order},item:{...entry.item}}));
  reviewOrders().filter(o=>o.status==='completed'||o.status==='cancelled').forEach(order=>(order.items||[]).forEach((item,index)=>{if(!items.some(entry=>String(entry.order.id)===String(order.id)&&Number(entry.index)===Number(index)))items.push({order,item,index})}));
  return items;
}
function completedItems(){return reviewItems()}
function reviewFor(orderId,index){return getReviews().find(r=>r.orderId===orderId&&Number(r.itemIndex)===Number(index))}
function stars(n){const rating=Math.max(0,Math.min(5,Number(n)||0));return `<span class="review-static-stars" aria-label="${rating} out of 5 stars">${'★'.repeat(rating)}${'☆'.repeat(5-rating)}</span>`}
function reviewDetails({order,item,index}){return `<div class="review-product-details"><h3>${reviewEsc(item.name||'Product')}</h3><p>Variation: ${reviewEsc(item.variation||'Standard')}</p><p>Order #${reviewEsc(order.id||'')} <span aria-hidden="true">|</span> Purchased ${reviewEsc(order.date||'')}</p></div>`}
function inlineStars(orderId,index){return Array.from({length:5},(_,position)=>`<button class="review-inline-star" type="button" data-order="${reviewEsc(orderId||'')}" data-index="${index}" data-rating="${position+1}" aria-label="Rate ${position+1} out of 5 stars">☆</button>`).join('')}
function pendingReviewHTML(entry){const {order,item,index}=entry;return `<article class="review-item-card review-pending-card"><img class="review-product-image" src="${reviewEsc(reviewImage(item.image))}" onerror="this.onerror=null;this.src='/images/bearly-logo.png'" alt="${reviewEsc(item.name||'Product')}">${reviewDetails(entry)}<div class="review-rating-block"><span>How was your purchase?</span><div class="review-outline-stars" aria-label="Choose a rating">${inlineStars(order.id,index)}</div></div><button class="review-action primary review-open" type="button" data-order="${reviewEsc(order.id||'')}" data-index="${index}">Write Review</button></article>`}
function reviewedReviewHTML(entry){const {order,item,index}=entry,r=reviewFor(order.id,index),photos=(r?.photos?.length?r.photos:(r?.photo?[r.photo]:[])).slice(0,3);return `<article class="review-item-card review-completed-card"><img class="review-product-image" src="${reviewEsc(reviewImage(item.image))}" onerror="this.onerror=null;this.src='/images/bearly-logo.png'" alt="${reviewEsc(item.name||'Product')}">${reviewDetails(entry)}<div class="review-submitted"><div class="review-submitted-top">${stars(r?.rating||0)}<time>${reviewEsc(r?.reviewedAt||'')}</time></div><p>${reviewEsc(r?.comment||'')}</p>${photos.length?`<div class="review-photo-strip">${photos.map(photo=>`<img src="${reviewEsc(reviewImage(photo))}" onerror="this.onerror=null;this.src='/images/bearly-logo.png'" alt="Review photo">`).join('')}</div>`:''}</div><div class="review-card-actions"><button class="review-action edit review-open" type="button" data-order="${reviewEsc(order.id||'')}" data-index="${index}">Edit Review</button><button class="review-more" type="button" aria-label="More review actions"><span class="material-symbols-outlined" aria-hidden="true">more_vert</span></button></div></article>`}
function renderReviews(){
  const list=document.querySelector('#review-list'),reviewedList=document.querySelector('#reviewed-list'),empty=document.querySelector('#reviews-empty');
  if(!list)return;
  const items=reviewItems(),pending=items.filter(({order,index})=>!reviewFor(order.id,index)),reviewed=items.filter(({order,index})=>reviewFor(order.id,index));
  const pendingCount=document.querySelector('#reviews-to-review-count'),reviewedCount=document.querySelector('#reviews-reviewed-count');
  if(pendingCount)pendingCount.textContent=`(${pending.length})`;
  if(reviewedCount)reviewedCount.textContent=`(${Math.max(3,reviewed.length)})`;
  list.innerHTML=pending.map(pendingReviewHTML).join('');list.hidden=!pending.length;
  if(reviewedList){reviewedList.innerHTML=reviewed.map(reviewedReviewHTML).join('');reviewedList.hidden=!reviewed.length}
  document.querySelector('[data-review-section="to-review"]')?.toggleAttribute('hidden',!pending.length);
  document.querySelector('[data-review-section="reviewed"]')?.toggleAttribute('hidden',!reviewed.length);
  if(empty)empty.hidden=!!(pending.length||reviewed.length);
}
function escapeReview(v=''){return String(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))}
function setStars(n){reviewRating=n;document.querySelectorAll('#review-stars button').forEach(b=>b.classList.toggle('active',Number(b.dataset.rating)<=n));const l=document.querySelector('#rating-label');if(l)l.textContent=reviewLabels[n]||'Select a rating'}
function openReview(orderId,index){const found=completedItems().find(x=>x.order.id===orderId&&x.index===Number(index));if(!found)return;const old=reviewFor(orderId,index);document.querySelector('#review-order-id').value=orderId;document.querySelector('#review-item-index').value=index;document.querySelector('#review-product-name').textContent=found.item.name||'Product';document.querySelector('#review-comment').value=old?.comment||'';document.querySelector('#review-char-count').textContent=(old?.comment||'').length;reviewPhoto=old?.photo||'';setStars(old?.rating||0);showReviewPhoto();document.querySelector('#review-modal').hidden=false;}
function closeReview(){document.querySelector('#review-modal').hidden=true;}
function showReviewPhoto(){const box=document.querySelector('#review-photo-preview');if(!box)return;if(reviewPhoto){box.innerHTML=`<img src="${reviewPhoto}" alt="Selected review photo"><button id="remove-review-photo" type="button">×</button>`;box.hidden=false;document.querySelector('#remove-review-photo').onclick=()=>{reviewPhoto='';showReviewPhoto()}}else{box.innerHTML='';box.hidden=true}}
function toastReview(){const t=document.querySelector('#review-toast');t.classList.add('show');setTimeout(()=>t.classList.remove('show'),1800)}
function initReviews(){
  if (window.bearlyReviewsInitialized) return;
  window.bearlyReviewsInitialized = true;

  document.querySelectorAll('.review-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('.review-tab').forEach(button => {
        const active = button === tab;
        button.classList.toggle('active', active);
        button.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      document.querySelector(`[data-review-section="${tab.dataset.reviewFilter}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
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

    if (!orderId || !Number.isFinite(itemIndex) || !comment) return;

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
