const CART_KEY='bearly-preview-cart-v1',SEL_KEY='bearly-checkout-selection-v1',ADDRESS_KEY='bearly-addresses-v1';
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
$('voucher-btn').onclick=()=>choose('Select Bearly Voucher',[{label:'No Voucher',note:'₱0 off',discount:0},{label:'BEARLY50',note:'₱50 off',discount:50},{label:'BEARLY100',note:'₱100 off',discount:100}],o=>{discount=Math.min(o.discount,subtotal());voucher=o.discount?o:null});
$('payment-btn').onclick=()=>choose('Payment Method',[{label:'Cash on Delivery',note:'COD'},{label:'GCash',note:'Frontend preview'},{label:'Debit / Credit Card',note:'Frontend preview'}],o=>payment=o.label);
$('change-address').onclick=()=>{let a=addresses();if(!a.length){window.location.href='/addresses';return}choose('Choose Delivery Address',a.map(x=>({label:x.name+' · '+x.phone,note:[x.street,x.barangay,x.city,x.province,x.postal].filter(Boolean).join(', '),...x})),o=>{$('address-name').textContent=o.name;$('address-phone').textContent=o.phone;$('address-text').textContent=o.note})};
$('place-order').onclick=()=>toast('Order preview ready — database/order saving will be connected later.');
loadDefaultAddress();render();