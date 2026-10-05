const PURCHASES_KEY=window.bearlyStorageKey?.('preview-orders')||'bearly-orders-v1';
const demoOrders=[
 {id:'BRY-102641',status:'to-receive',statusLabel:'To Receive',shop:'Bearly Official Store',date:'Mar 10, 2024',time:'10:24 AM',total:499,items:[{name:'Gentle Facial Cleanser',variation:'Standard',qty:1,price:499,image:'/images/products/health-beauty/health-beauty-01-01.jpg'}]},
 {id:'BRY-102508',status:'to-ship',statusLabel:'To Ship',shop:'Bearly Official Store',date:'Mar 08, 2024',time:'03:15 PM',total:898,items:[{name:'Everyday Basic Tee',variation:'Brown - Medium',qty:2,price:449,image:'/images/products/men/mens-01-01.jpg'}]},
 {id:'BRY-101972',status:'completed',statusLabel:'Completed',shop:'Bearly Official Store',date:'Mar 05, 2024',time:'08:40 AM',total:799,items:[{name:'Wireless Earbuds',variation:'Black',qty:1,price:799,image:'/images/products/electronics/electronics-07-01.jpg'}]},
 {id:'BRY-101804',status:'cancelled',statusLabel:'Cancelled',shop:'Bearly Official Store',date:'Feb 28, 2024',time:'11:20 AM',total:350,items:[{name:'Classic Tote Bag',variation:'Natural',qty:1,price:350,image:'/images/products/women/womens-01-01.jpg'}]}
];
let purchaseFilter='all';
let purchaseSort='recent';
const peso=n=>'₱'+Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
const purchaseEsc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
function getOrders(){try{const saved=JSON.parse(localStorage.getItem(PURCHASES_KEY)||'null');return Array.isArray(saved)&&saved.length?saved:demoOrders}catch{return demoOrders}}
function safeImage(img){const value=String(img||'');return /^(\/images\/|\/storage\/)/.test(value)?value:'/images/bearly-logo.png'}
function orderTimestamp(order){const value=[order?.date,order?.time].filter(Boolean).join(' '),timestamp=Date.parse(value);return Number.isNaN(timestamp)?0:timestamp}
function sortedOrders(orders){return [...orders].sort((a,b)=>{if(purchaseSort==='high')return Number(b?.total||0)-Number(a?.total||0);if(purchaseSort==='low')return Number(a?.total||0)-Number(b?.total||0);const direction=purchaseSort==='oldest'?1:-1;return (orderTimestamp(a)-orderTimestamp(b))*direction})}
function placedDate(order){return [order?.date,order?.time].filter(Boolean).join(' ')||'Date unavailable'}
function actionButtons(order){
  const id=purchaseEsc(order.id||'');
  if(order.status==='to-pay')return '<button class="order-btn primary" type="button">Pay Now</button><button class="order-btn" type="button">View Details</button>';
  if(order.status==='to-ship')return '<button class="order-btn" type="button">View Details</button><button class="order-btn order-cancel" type="button">Cancel Order</button>';
  if(order.status==='to-receive')return `<button class="order-btn primary" type="button">Order Received</button><button class="order-btn order-track" type="button" data-order="${id}">Track Order</button><button class="order-btn" type="button">View Details</button>`;
  if(order.status==='completed')return `<button class="order-btn primary order-rate-product" type="button" data-order="${id}" data-index="0">Rate Product</button><button class="order-btn" type="button">Buy Again</button><button class="order-btn" type="button">View Details</button>`;
  return '<button class="order-btn" type="button">View Details</button>';
}
function orderHTML(o){
   const items=(o.items||[]).map(i=>{const item=i||{};return `<div class="order-product"><img src="${purchaseEsc(safeImage(item.image))}" alt="${purchaseEsc(item.name||'Product')}" onerror="this.onerror=null;this.src='/images/bearly-logo.png'"><div class="order-product-copy"><h3>${purchaseEsc(item.name||'Product')}</h3><p>Variation: ${purchaseEsc(item.variation||'Standard')}</p><p>x${purchaseEsc(item.qty||1)}</p></div><div class="order-price"><strong>${peso(item.price)}</strong></div></div>`}).join('');
  const status=o.status||'', label=purchaseEsc(o.statusLabel||status||'Order update');
  return `<article class="order-card"><div class="order-card-head"><div class="order-meta"><div class="order-shop"><span class="material-symbols-outlined" aria-hidden="true">storefront</span><strong>${purchaseEsc(o.shop||'Bearly Official Store')}</strong></div><span class="order-id">Order #${purchaseEsc(o.id||'')}</span><span class="order-date">Placed on ${purchaseEsc(placedDate(o))}</span></div><span class="order-status">${label}</span></div>${items}<div class="order-card-foot"><div class="order-actions">${actionButtons(o)}</div></div></article>`;
}
function renderMyPurchases(){
  const list=document.querySelector('#purchase-list'),empty=document.querySelector('#purchases-empty'),count=document.querySelector('#purchase-count');if(!list)return;
  const all=getOrders(),counts=all.reduce((result,order)=>{const status=order?.status||'';result.all+=1;if(Object.prototype.hasOwnProperty.call(result,status))result[status]+=1;return result},{all:0,'to-pay':0,'to-ship':0,'to-receive':0,completed:0,cancelled:0}),shown=sortedOrders(purchaseFilter==='all'?all:all.filter(o=>o.status===purchaseFilter));
  if(count) count.textContent=`${all.length} ${all.length===1?'order':'orders'}`;
  document.querySelectorAll('[data-filter-count]').forEach(node=>{const value=counts[node.dataset.filterCount]??0;node.textContent=`(${value})`});
  list.innerHTML=shown.map(orderHTML).join('');
 list.hidden=!shown.length;
 if(empty) empty.hidden=!!shown.length;
}
function getTrackingOrder(){
  const id=sessionStorage.getItem(window.bearlyStorageKey?.('tracking-order-id')||'bearly-tracking-order-id');
  return id?getOrders().find(o=>String(o.id)===String(id))||null:null;
}
function readTrackingAddress(){try{const all=JSON.parse(localStorage.getItem(window.bearlyStorageKey?.('preview-addresses')||'bearly-addresses-v1')||'[]');return Array.isArray(all)?(all.find(a=>a.isDefault)||all[0]||null):null}catch{return null}}
async function readServerTrackingAddress(){
  try{
    const response=await fetch('/addresses/data',{headers:{'Accept':'application/json'}});
    const result=await response.json();
    if(response.ok&&Array.isArray(result.data))return result.data.find(a=>a.isDefault)||result.data[0]||null;
  }catch{}
  return readTrackingAddress();
}
function trackingBaseDate(order){
  const parsed=Date.parse([order?.date,order?.time].filter(Boolean).join(' '));
  return Number.isNaN(parsed)?new Date('2024-03-10T10:24:00'):new Date(parsed);
}
function trackingDate(date){
  return date.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})+' · '+date.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
}
function trackingDateOnly(date){
  return date.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
}
function trackingDateRange(start,end){
  return start.toLocaleDateString('en-US',{month:'short',day:'numeric'})+' – '+trackingDateOnly(end);
}
function trackingDateAt(base,days,hour,minute){
  const date=new Date(base);date.setDate(date.getDate()+days);date.setHours(hour,minute,0,0);return date;
}
function trackingEventDates(order){
  if(Array.isArray(order?.trackingEvents))return order.trackingEvents.map(event=>event?.date||'');
  const base=trackingBaseDate(order);
  return [
    trackingDate(base),
    trackingDate(trackingDateAt(base,0,10,28)),
    trackingDate(trackingDateAt(base,0,14,15)),
    trackingDate(trackingDateAt(base,1,9,40)),
    trackingDate(trackingDateAt(base,2,8,30)),
    trackingDate(trackingDateAt(base,3,11,0))
  ];
}
function trackingProgress(order){return {'to-pay':0,'to-ship':2,'to-receive':4,'completed':5,'cancelled':0}[order?.status]??0}
function trackingStatusLabel(order){return order?.trackingStatus||({'to-pay':'Order Placed','to-ship':'Preparing to Ship','to-receive':'Out for Delivery',completed:'Delivered',cancelled:'Cancelled'}[order?.status]||order?.statusLabel||'Order update')}
function trackingEstimate(order){
  if(order?.estimatedDelivery)return {range:order.estimatedDelivery,note:order.estimatedDeliveryNote||'Your order is on the way to your address.'};
  const base=trackingBaseDate(order),start=trackingDateAt(base,2,0,0),end=trackingDateAt(base,4,0,0),completed=order?.status==='completed';
  return {range:completed?'Delivered':trackingDateRange(start,end),note:completed?'Order delivered successfully.':'Your order is on the way to your address.'};
}
function trackingSteps(order){
 const steps=[
  ['Order Placed','Your order was placed successfully.'],
  [order?.paymentMethod==='Cash on Delivery'?'Cash on Delivery':'Payment Confirmed',order?.paymentMethod==='Cash on Delivery'?'Payment will be collected when your order is delivered.':'Payment has been confirmed.'],
  ['Preparing to Ship','Bearly Official is preparing your parcel.'],
  ['Shipped','Your parcel has left the seller.'],
  ['Out for Delivery','Your parcel is on the way to you.'],
  ['Delivered','Order delivered successfully.']
 ],progress=trackingProgress(order),dates=trackingEventDates(order);
 return steps.map((x,i)=>{const done=i<=progress,current=i===progress,icon=current&&x[0]!=='Delivered'?'local_shipping':done?'check':'circle';return `<div class="tracking-step ${done?'done':''} ${current?'current':''}"><div class="tracking-dot"><span class="material-symbols-outlined">${icon}</span></div><div><strong>${purchaseEsc(x[0])}</strong><p>${purchaseEsc(x[1])}</p>${done&&dates[i]?`<small>${purchaseEsc(dates[i])}</small>`:''}</div></div>`}).join('');
}
async function renderOrderTracking(){
  const root=document.querySelector('#tracking-content'), label=document.querySelector('#tracking-order-id'); if(!root)return;
  const order=getTrackingOrder(); if(!order){root.innerHTML='<div class="tracking-empty">No order selected for tracking.</div>';if(label)label.textContent='';return}
  if(label)label.textContent='#'+order.id;
   const item=(order.items||[])[0]||{}, a=await readServerTrackingAddress(),estimate=trackingEstimate(order),status=trackingStatusLabel(order);
   const address=a?[a.street,a.barangay,a.city,a.province,a.postal].filter(Boolean).join(', '):'No delivery address saved yet';
    root.innerHTML=`<div class="tracking-summary"><img src="${purchaseEsc(safeImage(item.image))}" alt="${purchaseEsc(item.name||'Product')}" onerror="this.onerror=null;this.src='/images/bearly-logo.png'"><div><span>${purchaseEsc(order.shop||'Bearly Official')}</span><h2>${purchaseEsc(item.name||'Product')}</h2><p>${purchaseEsc(item.variation||'Standard')} · x${purchaseEsc(item.qty||1)}</p></div><strong>${peso(order.total)}</strong></div><div class="tracking-grid"><div class="tracking-timeline"><h3>Delivery Progress</h3>${trackingSteps(order)}</div><aside class="tracking-address"><h3 class="tracking-section-title"><span class="material-symbols-outlined" aria-hidden="true">location_on</span><span>Delivery Address</span></h3><strong>${purchaseEsc(a?.name||window.bearlyBuyerProfile?.fullName||'Buyer')}</strong>${a?.phone?`<p>${purchaseEsc(a.phone)}</p>`:''}<p>${purchaseEsc(address)}</p><div class="tracking-status-box"><span>Current Status</span><strong>${purchaseEsc(status)}</strong></div><div class="tracking-estimate"><span class="tracking-estimate-icon material-symbols-outlined" aria-hidden="true">local_shipping</span><div><span>Estimated Delivery</span><strong>${purchaseEsc(estimate.range)}</strong><p>${purchaseEsc(estimate.note)}</p></div></div></aside></div>`;
}
function initMyPurchases(){
  if (window.bearlyMyPurchasesInitialized) return;
  window.bearlyMyPurchasesInitialized = true;

  const filters=document.querySelectorAll('.purchase-filter');
  if(filters.length){
    filters.forEach(b=>b.addEventListener('click',()=>{document.querySelectorAll('.purchase-filter').forEach(x=>{x.classList.toggle('active',x===b);x.setAttribute('aria-selected',x===b?'true':'false')});purchaseFilter=b.dataset.orderFilter;renderMyPurchases();}));
  }
  const sort=document.querySelector('#purchase-sort');
  if(sort)sort.addEventListener('change',()=>{purchaseSort=sort.value||'recent';renderMyPurchases()});
  const list=document.querySelector('#purchase-list');
   if(list) list.addEventListener('click',e=>{const btn=e.target.closest('.order-track');if(!btn)return;sessionStorage.setItem(window.bearlyStorageKey?.('tracking-order-id')||'bearly-tracking-order-id',btn.dataset.order||'');if(typeof window.bearlyShowAccountPanel==='function')window.bearlyShowAccountPanel('tracking');else location.hash='tracking';renderOrderTracking();});
  const back=document.querySelector('#tracking-back'); if(back) back.addEventListener('click',()=>{if(typeof window.bearlyShowAccountPanel==='function')window.bearlyShowAccountPanel('purchases');else location.hash='purchases'});
  renderMyPurchases();
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
