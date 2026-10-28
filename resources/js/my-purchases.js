let purchaseFilter='all';
let purchaseSort='recent';
const peso=n=>'₱'+Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
const purchaseEsc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
function getOrders(){return [...serverOrders]}
let serverOrders=[],serverLoaded=false,serverFetch=null,renderedOrders=[];
function csrfToken(){const input=document.querySelector('.account-logout-form input[name="_token"]');return input?input.value:''}
async function ensureServerOrders(){
  if(serverLoaded||serverFetch)return serverFetch;
  serverFetch=(async()=>{
    try{
      const response=await fetch('/profile/purchases/data',{headers:{'Accept':'application/json'}});
      const result=await response.json().catch(()=>({}));
      if(!response.ok||!Array.isArray(result.data))throw new Error('purchases unavailable');
      serverOrders=result.data.filter(order=>order&&typeof order==='object');
      try{window.bearlyPurchasesServer='live'}catch{}
    }catch{
      serverOrders=[];
      try{window.bearlyPurchasesServer='fallback'}catch{}
    }finally{
      serverLoaded=true;
    }
  })();
  return serverFetch;
}
if(typeof window!=='undefined')window.bearlyEnsurePurchases=ensureServerOrders;
function safeImage(img){
  const value=String(img||'');
  if(/^(\/images\/|\/storage\/)/.test(value))return value;
  try{
    const parsed=new URL(value,window.location.origin);
    if(parsed.origin===window.location.origin&&/^(\/images\/|\/storage\/)/.test(parsed.pathname))return parsed.pathname;
  }catch{}
  return '/images/bearly-logo.png';
}
function orderTimestamp(order){const value=[order?.date,order?.time].filter(Boolean).join(' '),timestamp=Date.parse(value);return Number.isNaN(timestamp)?0:timestamp}
function sortedOrders(orders){return [...orders].sort((a,b)=>{if(purchaseSort==='high')return Number(b?.total||0)-Number(a?.total||0);if(purchaseSort==='low')return Number(a?.total||0)-Number(b?.total||0);const direction=purchaseSort==='oldest'?1:-1;return (orderTimestamp(a)-orderTimestamp(b))*direction})}
function placedDate(order){return [order?.date,order?.time].filter(Boolean).join(' ')||'Date unavailable'}
function sellerDeliveryLabel(state){return ({preparing:'Preparing',in_transit:'In transit',out_for_delivery:'Out for delivery',delivered:'Delivered',cancelled:'Cancelled'}[state]||'Preparing')}
function serverActionForm(action,reason,label,cls){
  return `<form method="post" action="${purchaseEsc(action)}" class="order-server-action"><input type="hidden" name="_token" value="${purchaseEsc(csrfToken())}"><input type="hidden" name="reason" value="${purchaseEsc(reason)}"><button class="${cls}" type="submit">${label}</button></form>`;
}
export function actionButtons(order){
  const id=purchaseEsc(order.id||'');
  if(order&&order.server){
    const track=`<button class="order-btn order-track" type="button" data-order="${id}">Track Order</button>`;
    const details=order.details_url?`<a class="order-btn" href="${purchaseEsc(order.details_url)}">View Details</a>`:'<button class="order-btn" type="button">View Details</button>';
    if(order.status==='to-pay')return details;
    if(order.status==='to-ship'&&order.cancel_url)return `${details}${serverActionForm(order.cancel_url,'Buyer requested cancellation','Cancel Order','order-btn order-cancel')}`;
    if(order.status==='to-ship')return `${details}`;
    if(order.status==='to-receive'&&order.can_confirm&&order.confirm_url)return `${serverActionForm(order.confirm_url,'Buyer confirmed receipt','Order Received','order-btn primary')}${track}${details}`;
    if(order.status==='to-receive')return `${track}${details}`;
    if(order.status==='completed')return `<button class="order-btn primary order-rate-product" type="button" data-order="${id}" data-index="0">Rate Product</button>${details}`;
    return details;
  }
  if(order.status==='to-pay')return '<button class="order-btn" type="button">View Details</button>';
  if(order.status==='to-ship')return '<button class="order-btn" type="button">View Details</button><button class="order-btn order-cancel" type="button">Cancel Order</button>';
  if(order.status==='to-receive')return `<button class="order-btn primary" type="button">Order Received</button><button class="order-btn order-track" type="button" data-order="${id}">Track Order</button><button class="order-btn" type="button">View Details</button>`;
  if(order.status==='completed')return `<button class="order-btn primary order-rate-product" type="button" data-order="${id}" data-index="0">Rate Product</button><button class="order-btn" type="button">View Details</button>`;
  return '<button class="order-btn" type="button">View Details</button>';
}
export function purchaseItemLineTotalMinor(item){
  const savedLineTotal=item?.line_total_minor;
  if(savedLineTotal!==null&&savedLineTotal!==undefined&&String(savedLineTotal).trim()!==''&&Number.isFinite(Number(savedLineTotal)))return Math.max(0,Math.round(Number(savedLineTotal)));
  const quantity=Math.max(1,Math.trunc(Number(item?.qty)||1));
  const unitPriceMinor=Math.max(0,Math.round(Number(item?.price||0)*100));
  return unitPriceMinor*quantity;
}
function orderHTML(o){
    const items=(o.items||[]).map(i=>{const item=i||{},quantity=Math.max(1,Math.trunc(Number(item.qty)||1)),lineTotal=purchaseItemLineTotalMinor(item)/100;return `<div class="order-product"><img src="${purchaseEsc(safeImage(item.image))}" alt="${purchaseEsc(item.name||'Product')}" onerror="this.onerror=null;this.src='/images/bearly-logo.png'"><div class="order-product-copy"><h3>${purchaseEsc(item.name||'Product')}</h3><p>Variation: ${purchaseEsc(item.variation||'Standard')}</p><p>x${purchaseEsc(item.qty||1)}</p></div><div class="order-price"><strong>${peso(lineTotal)}</strong>${quantity>1?`<small>${peso(item.price)} each</small>`:''}</div></div>`}).join('');
    const sellerProgress=(o.seller_orders||[]).map(sellerOrder=>`<div class="order-seller-progress-row"><strong>${purchaseEsc(sellerOrder.store||'Seller')}</strong><span>Seller order #${purchaseEsc(sellerOrder.number||sellerOrder.id||'')}</span><span>${purchaseEsc(sellerDeliveryLabel(sellerOrder.delivery_status))}</span></div>`).join('');
    const returnLinks=(o.seller_orders||[]).map(sellerOrder=>{
      const cases=(sellerOrder.return_cases||[]).map(returnCase=>`<a class="order-btn" href="${purchaseEsc(returnCase.url)}">Case ${purchaseEsc(returnCase.number)} · ${purchaseEsc(String(returnCase.status||'').replaceAll('_',' '))}</a>`).join('');
      const request=sellerOrder.return_url?`<a class="order-btn" href="${purchaseEsc(sellerOrder.return_url)}">Return / refund · ${purchaseEsc(sellerOrder.store||'Seller')}</a>`:'';
      const dispute=sellerOrder.dispute_url?`<a class="order-btn" href="${purchaseEsc(sellerOrder.dispute_url)}">${purchaseEsc(sellerOrder.dispute_label||'Report a problem')}</a>`:'';
      return cases+request+dispute;
    }).join('');
   const status=o.status||'', label=purchaseEsc(o.statusLabel||status||'Order update');
    return `<article class="order-card"><div class="order-card-head"><div class="order-meta"><div class="order-shop"><span class="material-symbols-outlined" aria-hidden="true">storefront</span><strong>${purchaseEsc(o.shop||'Seller')}</strong></div><span class="order-id">Order #${purchaseEsc(o.id||'')}</span><span class="order-date">Placed on ${purchaseEsc(placedDate(o))}</span></div><span class="order-status">${label}</span></div>${sellerProgress?`<div class="order-seller-progress" aria-label="Progress by Seller Order">${sellerProgress}</div>`:''}${items}<div class="order-card-foot"><div class="order-actions">${actionButtons(o)}${returnLinks}</div></div></article>`;
}
async function renderMyPurchases(){
  const list=document.querySelector('#purchase-list'),empty=document.querySelector('#purchases-empty'),count=document.querySelector('#purchase-count');if(!list)return;
  try{await ensureServerOrders()}catch{}
  const all=serverLoaded?serverOrders:[];
  renderedOrders=all;
  const counts=all.reduce((result,order)=>{const status=order?.status||'';result.all+=1;if(Object.prototype.hasOwnProperty.call(result,status))result[status]+=1;return result},{all:0,'to-pay':0,'to-ship':0,'to-receive':0,completed:0,cancelled:0}),shown=sortedOrders(purchaseFilter==='all'?all:all.filter(o=>o.status===purchaseFilter));
  if(count) count.textContent=`${all.length} ${all.length===1?'order':'orders'}`;
  document.querySelectorAll('[data-filter-count]').forEach(node=>{const value=counts[node.dataset.filterCount]??0;node.textContent=`(${value})`});
  list.innerHTML=shown.map(orderHTML).join('');
 list.hidden=!shown.length;
 if(empty) empty.hidden=!!shown.length;
}
function getTrackingOrder(){
  const id=sessionStorage.getItem(window.bearlyStorageKey?.('tracking-order-id')||'bearly-tracking-order-id');
  if(!id)return null;
  return renderedOrders.find(o=>String(o.id)===String(id))||getOrders().find(o=>String(o.id)===String(id))||null;
}
function trackingDate(date){
  return date.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})+' · '+date.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
}
function trackingStatusLabel(order){return order?.trackingStatus||({'to-pay':'Order Placed','to-ship':'Preparing to Ship','to-receive':'Out for Delivery',completed:'Delivered',cancelled:'Cancelled'}[order?.status]||order?.statusLabel||'Order update')}
function trackingEstimate(order){
  if(order?.estimatedDelivery)return {range:order.estimatedDelivery,note:order.estimatedDeliveryNote||'Your order is on the way to your address.'};
  const delivered=order&&order.server&&order.delivery_status==='delivered';
  if(delivered)return {range:order.delivered_at_display||'Delivered',note:order.status==='completed'?'Order delivered and receipt confirmed.':'Delivered. Please confirm receipt in My Purchases.'};
  return {range:'Not available',note:'Bearly Logistics has not provided an estimated delivery date.'};
}
function trackingSteps(order){
 const events=Array.isArray(order?.trackingEvents)?order.trackingEvents:[];
 if(!events.length)return '<p class="tracking-empty">No shipment events have been recorded yet.</p>';
 return events.map((event,index)=>{const last=index===events.length-1,date=event.date?new Date(event.date):null,when=date&&!Number.isNaN(date.valueOf())?trackingDate(date):'';return `<div class="tracking-step done ${last?'current':''}"><div class="tracking-dot"><span class="material-symbols-outlined">${last?'local_shipping':'check'}</span></div><div><strong>${purchaseEsc(event.label||'Shipment update')}</strong>${event.note?`<p>${purchaseEsc(event.note)}</p>`:''}${when?`<small>${purchaseEsc(when)}</small>`:''}</div></div>`}).join('');
}
async function renderOrderTracking(){
  const root=document.querySelector('#tracking-content'), label=document.querySelector('#tracking-order-id'); if(!root)return;
  const order=getTrackingOrder(); if(!order){root.innerHTML='<div class="tracking-empty">No order selected for tracking.</div>';if(label)label.textContent='';return}
  if(label)label.textContent='#'+order.id;
   const item=(order.items||[])[0]||{}, destination=order.delivery_address||{}, estimate=trackingEstimate(order),status=trackingStatusLabel(order);
   const address=[destination.street,destination.barangay,destination.city,destination.province,destination.postal].filter(Boolean).join(', ')||'Address unavailable';
    root.innerHTML=`<div class="tracking-summary"><img src="${purchaseEsc(safeImage(item.image))}" alt="${purchaseEsc(item.name||'Product')}" onerror="this.onerror=null;this.src='/images/bearly-logo.png'"><div><span>${purchaseEsc(order.shop||'Seller')}</span><h2>${purchaseEsc(item.name||'Purchased items')}</h2><p>${purchaseEsc(item.variation||'')} ${item.qty?`· x${purchaseEsc(item.qty)}`:''}</p></div><strong>${peso(order.total)}</strong></div><div class="tracking-grid"><div class="tracking-timeline"><h3>Shipment Event History</h3>${trackingSteps(order)}</div><aside class="tracking-address"><h3 class="tracking-section-title"><span class="material-symbols-outlined" aria-hidden="true">location_on</span><span>Delivery Address</span></h3><strong>${purchaseEsc(destination.name||'Buyer')}</strong>${destination.phone?`<p>${purchaseEsc(destination.phone)}</p>`:''}<p>${purchaseEsc(address)}</p><div class="tracking-status-box"><span>Current Status</span><strong>${purchaseEsc(status)}</strong></div><div class="tracking-estimate"><span class="tracking-estimate-icon material-symbols-outlined" aria-hidden="true">local_shipping</span><div><span>Estimated Delivery</span><strong>${purchaseEsc(estimate.range)}</strong><p>${purchaseEsc(estimate.note)}</p></div></div></aside></div>`;
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
if(typeof document!=='undefined'){
  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', initMyPurchases);
  } else {
    initMyPurchases();
  }
}
if(typeof window!=='undefined'){
  window.renderMyPurchases=renderMyPurchases;
  window.bearlyGetOrders=getOrders;
  window.renderOrderTracking=renderOrderTracking;
}
