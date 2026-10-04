const PURCHASES_KEY='bearly-orders-v1';
const demoOrders=[
 {id:'BRY-102641',status:'to-receive',statusLabel:'To Receive',shop:'Bearly Official',date:'Oct 2, 2026',total:499,items:[{name:'Gentle Facial Cleanser',variation:'Standard',qty:1,price:499,image:'/images/products/health-beauty/health-beauty-01-01.jpg'}]},
 {id:'BRY-102508',status:'to-ship',statusLabel:'To Ship',shop:'Bearly Official',date:'Oct 1, 2026',total:898,items:[{name:'Everyday Basic Tee',variation:'Brown · Medium',qty:2,price:449,image:'/images/products/men/men-01-01.jpg'}]},
 {id:'BRY-101972',status:'completed',statusLabel:'Completed',shop:'Bearly Official',date:'Sep 27, 2026',total:799,items:[{name:'Wireless Earbuds',variation:'Black',qty:1,price:799,image:'/images/products/electronics/electronics-07-01.jpg'}]},
 {id:'BRY-101804',status:'cancelled',statusLabel:'Cancelled',shop:'Bearly Official',date:'Sep 25, 2026',total:350,items:[{name:'Classic Tote Bag',variation:'Natural',qty:1,price:350,image:'/images/products/women/women-01-01.jpg'}]}
];
let purchaseFilter='all';
const peso=n=>'₱'+Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
function getOrders(){try{const saved=JSON.parse(localStorage.getItem(PURCHASES_KEY)||'null');return Array.isArray(saved)&&saved.length?saved:demoOrders}catch{return demoOrders}}
function safeImage(img){return img||'/images/logo.png'}
function actionButtons(order){
 if(order.status==='to-pay')return '<button class="order-btn primary" type="button">Pay Now</button><button class="order-btn" type="button">View Details</button>';
 if(order.status==='to-ship')return '<button class="order-btn" type="button">View Details</button>';
 if(order.status==='to-receive')return `<button class="order-btn primary" type="button">Order Received</button><button class="order-btn order-track" type="button" data-order="${order.id}">Track Order</button>`;
 if(order.status==='completed')return `<button class="order-btn primary order-rate-product" type="button" data-order="${order.id}" data-index="0">Rate Product</button><button class="order-btn" type="button">Buy Again</button>`;
 return '<button class="order-btn" type="button">View Details</button>';
}
function orderHTML(o){
 const items=(o.items||[]).map(i=>`<div class="order-product"><img src="${safeImage(i.image)}" alt="${i.name||'Product'}" onerror="this.src='/images/logo.png'"><div><h3>${i.name||'Product'}</h3><p>Variation: ${i.variation||'Standard'}</p><p>x${i.qty||1}</p></div><div class="order-price"><strong>${peso(i.price)}</strong></div></div>`).join('');
 return `<article class="order-card"><div class="order-card-head"><div class="order-shop"><span class="material-symbols-outlined">storefront</span>${o.shop||'Bearly'} <span style="font-weight:400;color:#948881">#${o.id||''}</span></div><span class="order-status ${o.status==='cancelled'?'cancelled':''}">${o.statusLabel||o.status}</span></div>${items}<div class="order-card-foot"><span class="order-total">${o.paymentMethod?`<small style="display:block;color:#8b7d75;margin-bottom:3px">${o.paymentMethod}</small>`:''}Order Total: <strong>${peso(o.total)}</strong></span>${actionButtons(o)}</div></article>`;
}
function renderMyPurchases(){
 const list=document.querySelector('#purchase-list'),empty=document.querySelector('#purchases-empty'),count=document.querySelector('#purchase-count');if(!list)return;
 const all=getOrders(),shown=purchaseFilter==='all'?all:all.filter(o=>o.status===purchaseFilter);
 if(count) count.textContent=`${all.length} ${all.length===1?'order':'orders'}`;
 list.innerHTML=shown.map(orderHTML).join('');
 list.hidden=!shown.length;
 if(empty) empty.hidden=!!shown.length;
}
function getTrackingOrder(){
 const id=sessionStorage.getItem('bearly-tracking-order-id');
 return getOrders().find(o=>String(o.id)===String(id))||getOrders().find(o=>o.status==='to-receive')||null;
}
function readTrackingAddress(){try{const all=JSON.parse(localStorage.getItem('bearly-addresses-v1')||'[]');return Array.isArray(all)?(all.find(a=>a.isDefault)||all[0]||null):null}catch{return null}}
function trackingSteps(order){
 const steps=[
  ['Order Placed','Your order was placed successfully.',order?.date||''],
  [order?.paymentMethod==='Cash on Delivery'?'Cash on Delivery':'Payment Confirmed',order?.paymentMethod==='Cash on Delivery'?'Payment will be collected when your order is delivered.':'Payment has been confirmed.',''],
  ['Preparing to Ship','Bearly Official is preparing your parcel.',''],
  ['Shipped','Your parcel has left the seller.',''],
  ['Out for Delivery','Your parcel is on the way to you.',''],
  ['Delivered','Order delivered successfully.','']
 ];
 const progress={'to-pay':0,'to-ship':2,'to-receive':4,'completed':5,'cancelled':0}[order?.status]??0;
 return steps.map((x,i)=>`<div class="tracking-step ${i<=progress?'done':''} ${i===progress?'current':''}"><div class="tracking-dot"><span class="material-symbols-outlined">${i<=progress?'check':'circle'}</span></div><div><strong>${x[0]}</strong><p>${x[1]}</p>${x[2]?`<small>${x[2]}</small>`:''}</div></div>`).join('');
}
function renderOrderTracking(){
 const root=document.querySelector('#tracking-content'), label=document.querySelector('#tracking-order-id'); if(!root)return;
 const order=getTrackingOrder(); if(!order){root.innerHTML='<div class="tracking-empty">No order selected for tracking.</div>';if(label)label.textContent='';return}
 if(label)label.textContent='#'+order.id;
 const item=(order.items||[])[0]||{}, a=readTrackingAddress();
 const address=a?[a.street,a.barangay,a.city,a.province,a.postal].filter(Boolean).join(', '):'No delivery address saved yet';
 root.innerHTML=`<div class="tracking-summary"><img src="${safeImage(item.image)}" alt="${item.name||'Product'}" onerror="this.src='/images/logo.png'"><div><span>${order.shop||'Bearly Official'}</span><h2>${item.name||'Product'}</h2><p>${item.variation||'Standard'} · x${item.qty||1}</p></div><strong>${peso(order.total)}</strong></div><div class="tracking-grid"><div class="tracking-timeline"><h3>Delivery Progress</h3>${trackingSteps(order)}</div><aside class="tracking-address"><h3>Delivery Address</h3><strong>${a?.name||'Mia Santos'}</strong>${a?.phone?`<p>${a.phone}</p>`:''}<p>${address}</p><div class="tracking-status-box"><span>Current Status</span><strong>${order.statusLabel||order.status}</strong></div></aside></div>`;
}
function initMyPurchases(){
  if (window.bearlyMyPurchasesInitialized) return;
  window.bearlyMyPurchasesInitialized = true;

  const filters=document.querySelectorAll('.purchase-filter');
  if(filters.length){
    filters.forEach(b=>b.addEventListener('click',()=>{document.querySelectorAll('.purchase-filter').forEach(x=>x.classList.remove('active'));b.classList.add('active');purchaseFilter=b.dataset.orderFilter;renderMyPurchases();}));
  }
  const list=document.querySelector('#purchase-list');
  if(list) list.addEventListener('click',e=>{const btn=e.target.closest('.order-track');if(!btn)return;sessionStorage.setItem('bearly-tracking-order-id',btn.dataset.order||'');if(typeof window.bearlyShowAccountPanel==='function')window.bearlyShowAccountPanel('tracking');else location.hash='tracking';renderOrderTracking();});
  const back=document.querySelector('#tracking-back'); if(back) back.addEventListener('click',()=>{if(typeof window.bearlyShowAccountPanel==='function')window.bearlyShowAccountPanel('purchases');else location.hash='purchases'});
  renderMyPurchases();
  if(location.hash==='#tracking')renderOrderTracking();
}
if(document.readyState === 'loading'){
  document.addEventListener('DOMContentLoaded', initMyPurchases);
} else {
  initMyPurchases();
}
window.renderMyPurchases=renderMyPurchases;
window.bearlyGetOrders=getOrders;
window.bearlyDemoOrders=demoOrders;

window.renderOrderTracking=renderOrderTracking;


let historyFilter='all';
let historyQuery='';
function historyOrders(){
 return getOrders().filter(o=>o.status==='completed'||o.status==='cancelled');
}
function historyMatches(order){
 if(historyFilter!=='all'&&order.status!==historyFilter)return false;
 if(!historyQuery)return true;
 const hay=[order.id,order.shop,order.statusLabel,...(order.items||[]).flatMap(i=>[i.name,i.variation])].join(' ').toLowerCase();
 return hay.includes(historyQuery.toLowerCase());
}
function renderOrderHistory(){
 const list=document.querySelector('#history-list');
 const empty=document.querySelector('#history-empty');
 const count=document.querySelector('#history-count');
 if(!list)return;
 const all=historyOrders();
 const shown=all.filter(historyMatches);
 if(count)count.textContent=`${shown.length} ${shown.length===1?'order':'orders'}`;
 list.innerHTML=shown.map(orderHTML).join('');
 list.hidden=shown.length===0;
 if(empty)empty.hidden=shown.length!==0;
}
function initOrderHistory(){
 const search=document.querySelector('#history-search');
 if(search)search.addEventListener('input',()=>{historyQuery=search.value.trim();renderOrderHistory();});
 document.querySelectorAll('.history-filter').forEach(btn=>btn.addEventListener('click',()=>{
   document.querySelectorAll('.history-filter').forEach(x=>x.classList.toggle('active',x===btn));
   historyFilter=btn.dataset.historyFilter||'all';
   renderOrderHistory();
 }));
 renderOrderHistory();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initOrderHistory);else initOrderHistory();
window.renderOrderHistory=renderOrderHistory;
