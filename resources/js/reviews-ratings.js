const REVIEWS_KEY='bearly-reviews-v1';
let reviewRating=0, reviewPhoto='';
const reviewLabels=['','Poor','Fair','Good','Very Good','Excellent'];
function getReviews(){try{const x=JSON.parse(localStorage.getItem(REVIEWS_KEY)||'[]');return Array.isArray(x)?x:[]}catch{return []}}
function saveReviews(x){localStorage.setItem(REVIEWS_KEY,JSON.stringify(x))}
function reviewOrders(){
  if(typeof window.bearlyGetOrders==='function') return window.bearlyGetOrders();
  try {
    const saved=JSON.parse(localStorage.getItem('bearly-orders-v1')||'null');
    if(Array.isArray(saved)&&saved.length) return saved;
  } catch {}
  return Array.isArray(window.bearlyDemoOrders)?window.bearlyDemoOrders:[];
}
function completedItems(){const orders=reviewOrders();return orders.filter(o=>o.status==='completed').flatMap(o=>(o.items||[]).map((i,index)=>({order:o,item:i,index})));}
function reviewFor(orderId,index){return getReviews().find(r=>r.orderId===orderId&&Number(r.itemIndex)===Number(index))}
function stars(n){return `<span class="review-static-stars">${'★'.repeat(n)}${'☆'.repeat(5-n)}</span>`}
function renderReviews(){const list=document.querySelector('#review-list'),empty=document.querySelector('#reviews-empty'),count=document.querySelector('#reviews-count');if(!list)return;const items=completedItems(),reviews=getReviews();count.textContent=`${reviews.length} ${reviews.length===1?'review':'reviews'}`;list.innerHTML=items.map(({order,item,index})=>{const r=reviewFor(order.id,index);return `<article class="review-card"><img src="${item.image||'/images/bearly-logo.png'}" onerror="this.src='/images/bearly-logo.png'" alt="${item.name||'Product'}"><div class="review-main"><div class="review-meta"><span>Order #${order.id}</span><span>${order.date||''}</span></div><h3>${item.name||'Product'}</h3>${r?`<div class="submitted-rating">${stars(r.rating)} <strong>${reviewLabels[r.rating]}</strong></div><p class="review-copy">${escapeReview(r.comment)}</p>${r.photo?`<img class="submitted-review-photo" src="${r.photo}" alt="Review photo">`:''}`:'<p class="review-prompt">How was your purchase? Share your experience with other buyers.</p>'}</div><button class="order-btn ${r?'':'primary'} review-open" type="button" data-order="${order.id}" data-index="${index}">${r?'Edit Review':'Rate Product'}</button></article>`}).join('');list.hidden=!items.length;empty.hidden=!!items.length;}
function escapeReview(v=''){return String(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))}
function setStars(n){reviewRating=n;document.querySelectorAll('#review-stars button').forEach(b=>b.classList.toggle('active',Number(b.dataset.rating)<=n));const l=document.querySelector('#rating-label');if(l)l.textContent=reviewLabels[n]||'Select a rating'}
function openReview(orderId,index){const found=completedItems().find(x=>x.order.id===orderId&&x.index===Number(index));if(!found)return;const old=reviewFor(orderId,index);document.querySelector('#review-order-id').value=orderId;document.querySelector('#review-item-index').value=index;document.querySelector('#review-product-name').textContent=found.item.name||'Product';document.querySelector('#review-comment').value=old?.comment||'';document.querySelector('#review-char-count').textContent=(old?.comment||'').length;reviewPhoto=old?.photo||'';setStars(old?.rating||0);showReviewPhoto();document.querySelector('#review-modal').hidden=false;}
function closeReview(){document.querySelector('#review-modal').hidden=true;}
function showReviewPhoto(){const box=document.querySelector('#review-photo-preview');if(!box)return;if(reviewPhoto){box.innerHTML=`<img src="${reviewPhoto}" alt="Selected review photo"><button id="remove-review-photo" type="button">×</button>`;box.hidden=false;document.querySelector('#remove-review-photo').onclick=()=>{reviewPhoto='';showReviewPhoto()}}else{box.innerHTML='';box.hidden=true}}
function toastReview(){const t=document.querySelector('#review-toast');t.classList.add('show');setTimeout(()=>t.classList.remove('show'),1800)}
function initReviews(){
  if (window.bearlyReviewsInitialized) return;
  window.bearlyReviewsInitialized = true;

  document.addEventListener('click', e => {
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
    const data = { orderId, itemIndex, rating: reviewRating, comment, photo: reviewPhoto, updatedAt: new Date().toISOString() };
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
