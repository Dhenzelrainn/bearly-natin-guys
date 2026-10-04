const CART_KEY='bearly-preview-cart-v1',SEL_KEY='bearly-checkout-selection-v1',ADDRESS_KEY='bearly-addresses-v1',VOUCHER_KEY='bearly-claimed-vouchers-v1',ORDERS_KEY='bearly-orders-v1';
const $=id=>document.getElementById(id),money=n=>'₱'+Number(n||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
const read=(k,d=[])=>{try{let v=JSON.parse(localStorage.getItem(k));return v??d}catch{return d}};
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const itemKey=(i,n)=>String(i.key??i.product_id??n);
let shipping=50,discount=0,payment='Cash on Delivery',voucher=null;
let cart=read(CART_KEY),selectedKeys=read(SEL_KEY),items=cart.filter((i,n)=>selectedKeys.includes(itemKey(i,n)));
function toast(m){let e=$('checkout-toast');e.textContent=m;e.classList.add('show');clearTimeout(window._co);window._co=setTimeout(()=>e.classList.remove('show'),1500)}
function img(v){v=String(v||'').trim();if(!v)return '/images/bearly-logo.png';return /^(https?:|data:|\/)/.test(v)?v:'/'+v.replace(/^\.\//,'')}
function subtotal(){return items.reduce((s,i)=>s+Number(i.price||0)*Number(i.quantity||1),0)}
function addresses(){return read(ADDRESS_KEY,[])}
function loadDefaultAddress(){let a=addresses();let d=a.find(x=>x.isDefault)||a[0];if(!d)return;$('address-name').textContent=d.name;$('address-phone').textContent=d.phone;$('address-text').textContent=[d.street,d.barangay,d.city,d.province,d.postal].filter(Boolean).join(', ')}
function render(){if(!items.length){$('checkout-empty').hidden=false;return}
 $('checkout-products').innerHTML=items.map(i=>`<article class="checkout-product"><div class="checkout-product-main"><img src="${esc(img(i.image||i.photo))}" onerror="this.onerror=null;this.src='/images/bearly-logo.png'" alt="${esc(i.name)}"><div><div class="checkout-product-name">${esc(i.name)}</div><div class="checkout-product-meta">${i.color?`Variation: ${esc(i.color)}`:''}${i.size?` · ${esc(i.size)}`:''}</div></div></div><div>${money(i.price)}</div><div>${Number(i.quantity||1)}</div><div class="checkout-product-subtotal">${money(Number(i.price)*Number(i.quantity||1))}</div></article>`).join('');
 let q=items.reduce((s,i)=>s+Number(i.quantity||1),0),sub=subtotal(),total=Math.max(0,sub+shipping-discount);
 $('order-items').textContent=q;$('summary-subtotal').textContent=money(sub);$('summary-shipping').textContent=money(shipping);$('summary-discount').textContent='−'+money(discount);$('summary-total').textContent=money(total);$('order-total').textContent=money(total);$('shipping-price').textContent=money(shipping);$('payment-label').textContent=payment;$('voucher-status').textContent=voucher?voucher.label:'No voucher selected';
}
function choose(title,options,onPick){$('choice-title').textContent=title;$('choice-options').innerHTML=options.map((o,n)=>`<button class="choice-option" data-choice="${n}"><span>${esc(o.label)}</span><strong>${esc(o.note||'')}</strong></button>`).join('');$('choice-modal').hidden=false;$('choice-options').onclick=e=>{let b=e.target.closest('[data-choice]');if(!b)return;onPick(options[+b.dataset.choice]);$('choice-modal').hidden=true;render()}}
document.querySelector('[data-close]').onclick=()=>$('choice-modal').hidden=true;
$('change-shipping').onclick=()=>choose('Choose Shipping Option',[{label:'Standard Delivery',note:'₱50',price:50,days:'Estimated 3–7 days'},{label:'Economy Delivery',note:'₱35',price:35,days:'Estimated 5–10 days'},{label:'Express Delivery',note:'₱120',price:120,days:'Estimated 1–2 days'}],o=>{shipping=o.price;$('shipping-label').textContent=o.label;$('shipping-label').nextElementSibling.textContent=o.days});
$('voucher-btn').onclick=()=>{
 const claimed=read(VOUCHER_KEY,[]);
 const catalog=[
  {id:'BEARLY50',label:'BEARLY50',note:'₱50 off · Min ₱399',discount:50,min:399},
  {id:'BEARLY100',label:'BEARLY100',note:'₱100 off · Min ₱799',discount:100,min:799},
  {id:'BEARLYSHIP',label:'BEARLYSHIP',note:'Free shipping up to ₱50 · Min ₱499',discount:50,min:499,shippingVoucher:true},
  {id:'PAYDAY30',label:'PAYDAY30',note:'30% off up to ₱150 · Min ₱599',percent:30,cap:150,min:599}
 ];
 const eligible=catalog.filter(v=>claimed.includes(v.id));
 const options=[{label:'No Voucher',note:'₱0 off',discount:0},...eligible];
 if(!eligible.length){toast('Claim a voucher from My Vouchers first.');setTimeout(()=>window.location.href='/profile#vouchers',700);return}
 choose('Select Bearly Voucher',options,o=>{
   if(!o.id){discount=0;voucher=null;return}
   if(subtotal()<o.min){discount=0;voucher=null;toast(`Minimum spend is ₱${o.min}.`);return}
   discount=o.percent?Math.min(o.cap,subtotal()*(o.percent/100)):Math.min(o.discount,subtotal());
   voucher=o;
 });
};

$('change-address').onclick=()=>{let a=addresses();if(!a.length){window.location.href='/addresses';return}choose('Choose Delivery Address',a.map(x=>({label:x.name+' · '+x.phone,note:[x.street,x.barangay,x.city,x.province,x.postal].filter(Boolean).join(', '),...x})),o=>{$('address-name').textContent=o.name;$('address-phone').textContent=o.phone;$('address-text').textContent=o.note})};
$('place-order').onclick=()=>{
 if(!items.length){toast('No items selected for checkout.');return}
 const a=addresses(),address=a.find(x=>x.isDefault)||a[0]||null;
 if(!address){toast('Please add a delivery address first.');setTimeout(()=>window.location.href='/addresses',700);return}
 const sub=subtotal(),total=Math.max(0,sub+shipping-discount);
 const now=new Date();
 const id='BRY-'+String(now.getTime()).slice(-6);
 const order={
   id,
   status:'to-ship',
   statusLabel:'To Ship',
   shop:'Bearly Official',
   date:now.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}),
   subtotal:sub,
   shipping,
   discount,
   total,
   paymentMethod:'Cash on Delivery',
   paymentStatus:'Cash on Delivery',
   voucher:voucher?.id||null,
   sellerMessage:$('seller-message')?.value?.trim()||'',
   shippingOption:$('shipping-label')?.textContent?.trim()||'Standard Delivery',
   address:{...address},
   items:items.map(i=>({
     name:i.name||'Product',
     variation:[i.color,i.size].filter(Boolean).join(' · ')||'Standard',
     color:i.color||'',size:i.size||'',qty:Number(i.quantity||1),price:Number(i.price||0),
     image:img(i.image||i.photo),product_id:i.product_id??i.id??null
   }))
 };
 const existing=read(ORDERS_KEY,[]);
 localStorage.setItem(ORDERS_KEY,JSON.stringify([order,...existing]));
 const selected=new Set(selectedKeys.map(String));
 const remaining=cart.filter((i,n)=>!selected.has(itemKey(i,n)));
 localStorage.setItem(CART_KEY,JSON.stringify(remaining));
 localStorage.removeItem(SEL_KEY);
 toast('Order placed! Pay with cash upon delivery.');
 setTimeout(()=>window.location.href='/profile#purchases',900);
};
loadDefaultAddress();render();
/* Bearly global navbar state: keeps notification/cart badges in sync across buyer pages. */
(function initBearlyGlobalNavbarState(){
  const CART_KEY='bearly-preview-cart-v1';
  const NOTIFICATION_KEY='bearly-notifications-v1';
  function cartCount(){
    try{const x=JSON.parse(localStorage.getItem(CART_KEY)||'[]');return Array.isArray(x)?x.reduce((n,i)=>n+Math.max(0,Number(i?.quantity||0)),0):0}catch{return 0}
  }
  function notificationCount(){
    try{
      const raw=localStorage.getItem(NOTIFICATION_KEY);
      if(!raw){const existing=document.querySelector('[data-notification-badge]');return Number(existing?.textContent||3)||0}
      const x=JSON.parse(raw);return Array.isArray(x)?x.filter(i=>!i?.read).length:0;
    }catch{return 0}
  }
  function ensureBadge(link,type){
    if(!link)return null;
    const selector=type==='cart'?'[data-global-cart-badge]':'[data-notification-badge]';
    let badge=link.querySelector(selector);
    /* Reuse older page-specific cart counters instead of creating a second badge. */
    if(!badge && type==='cart') badge=link.querySelector('#cart-header-count,.cart-badge');
    if(!badge){
      badge=document.createElement('span');
      badge.setAttribute(type==='cart'?'data-global-cart-badge':'data-notification-badge','');
      link.appendChild(badge);
    } else if(type==='cart') {
      badge.setAttribute('data-global-cart-badge','');
    }
    Object.assign(badge.style,{position:'absolute',top:'-7px',right:'0',minWidth:'17px',height:'17px',padding:'0 4px',borderRadius:'999px',background:'#e9a321',color:'#432718',fontSize:'10px',fontWeight:'800',lineHeight:'17px',textAlign:'center',zIndex:'3'});
    link.style.position='relative';
    return badge;
  }
  function sync(){
    document.querySelectorAll('a[href$="/cart"],a[href="/cart"]').forEach(link=>{const b=ensureBadge(link,'cart');const n=cartCount();b.textContent=n;b.hidden=n===0;b.style.display=n===0?'none':''});
    document.querySelectorAll('a[href*="#notifications"]').forEach(link=>{const b=ensureBadge(link,'notification');const n=notificationCount();b.textContent=n;b.hidden=n===0;b.style.display=n===0?'none':''});
    document.querySelectorAll('[data-notification-badge]').forEach(b=>{const n=notificationCount();b.textContent=n;b.hidden=n===0;if(b.style)b.style.display=n===0?'none':''});
    /* Profile header must follow Home: logo left, actions pushed to the far right. */
    document.querySelectorAll('.profile-top-actions').forEach(el=>{el.style.marginLeft='auto'});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',sync);else sync();
  window.addEventListener('storage',sync);
  window.addEventListener('focus',sync);
  setInterval(sync,700);
  window.BearlyNavbarState={sync};
})();
