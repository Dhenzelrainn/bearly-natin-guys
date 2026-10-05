import { getPreviewNotifications as getNotifications, savePreviewNotifications as saveNotifications } from './buyer-notification-data.js';

function bindPhilippinePhone(input){
 if(!input)return;
 const normalize=()=>{
  let digits=input.value.replace(/\D/g,'');
  if(digits.startsWith('63')){digits=digits.slice(0,12);input.value='+63'+digits.slice(2);}
  else if(digits.startsWith('0')){input.value=digits.slice(0,11);}
  else{digits=digits.slice(0,10);input.value=digits?'+63'+digits:'';}
  const raw=input.value.replace(/\D/g,'');
  const valid=input.value.startsWith('+63') ? raw.length===12 : /^0\d{10}$/.test(input.value);
  input.setCustomValidity(input.value && !valid ? 'Enter 10 digits after +63, or an 11-digit number starting with 0.' : '');
 };
 input.addEventListener('input',normalize); input.addEventListener('blur',normalize); normalize();
}

const serverProfile=window.bearlyBuyerProfile||{};
const serverBirthday=String(serverProfile.birthday||'').split('-');
const defaults={username:serverProfile.username||'buyer',fullName:serverProfile.fullName||'Buyer',email:serverProfile.email||'',phone:serverProfile.phone||'',gender:serverProfile.gender||'',birthMonth:serverBirthday[1]||'',birthDay:serverBirthday[2]||'',birthYear:serverBirthday[0]||'',photo:serverProfile.photo||''};
let pendingPhotoFile=null,pendingRemovePhoto=false;
const $=s=>document.querySelector(s);
const months=['January','February','March','April','May','June','July','August','September','October','November','December'];

function fillSelects(){
 const m=$('#birth-month'),d=$('#birth-day'),y=$('#birth-year');
 if(!m || !d || !y) return;
 months.forEach((x,i)=>m.insertAdjacentHTML('beforeend',`<option value="${i+1}">${x}</option>`));
 for(let i=1;i<=31;i++) d.insertAdjacentHTML('beforeend',`<option value="${i}">${i}</option>`);
 const now=new Date().getFullYear();
 for(let i=now;i>=1940;i--) y.insertAdjacentHTML('beforeend',`<option value="${i}">${i}</option>`);
}
function getProfile(){
  const server=window.bearlyBuyerProfile||serverProfile;
  const birthday=String(server.birthday||'').split('-');
  const current={
    username:server.username||defaults.username,
    fullName:server.fullName||defaults.fullName,
    email:server.email||defaults.email,
    phone:server.phone||'',
    gender:server.gender==='prefer_not_to_say'?'Other':(server.gender||''),
    birthMonth:birthday[1]||'',
    birthDay:birthday[2]||'',
    birthYear:birthday[0]||'',
    photo:server.photo||''
  };
   return current;
}
function avatar(photo){
 const profileAvatar=$('#profile-avatar');
 const sidebarAvatar=$('#sidebar-avatar');
 const navbarAvatar=$('#navbar-avatar');
  const value=String(photo||'');
  const safePhoto=/^(?:https?:\/\/|\/|data:image\/(?:jpeg|png|webp);base64,)/.test(value)?value:'';
 const html=safePhoto?`<img src="${safePhoto}" alt="Profile photo">`:`<span class="material-symbols-outlined">person</span>`;
 if(profileAvatar) profileAvatar.innerHTML=html;
 if(sidebarAvatar) sidebarAvatar.innerHTML=html;
 if(navbarAvatar) navbarAvatar.innerHTML=html;
}
function load(){
 const p=getProfile();
 const username=$('#username');
 const fullName=$('#full-name');
 const email=$('#email');
 const phone=$('#phone');
 const birthMonth=$('#birth-month');
 const birthDay=$('#birth-day');
 const birthYear=$('#birth-year');
 if(username) username.value=p.username;
 if(fullName) fullName.value=p.fullName;
 if(email) email.value=p.email;
 if(phone) phone.value=p.phone;
 if(birthMonth) birthMonth.value=p.birthMonth;
 if(birthDay) birthDay.value=p.birthDay;
 if(birthYear) birthYear.value=p.birthYear;
 document.querySelectorAll('[name=gender]').forEach(r=>r.checked=r.value===p.gender);
 const sidebarName=$('#sidebar-name');
 if(sidebarName) sidebarName.textContent=p.fullName||defaults.fullName;
 avatar(p.photo);
}
async function save(){
  const gender=document.querySelector('[name=gender]:checked')?.value||'';
 const username=$('#username');
 const fullName=$('#full-name');
 const email=$('#email');
 const phone=$('#phone');
 const birthMonth=$('#birth-month');
 const birthDay=$('#birth-day');
 const birthYear=$('#birth-year');
  const birthday=([birthYear?.value,birthMonth?.value?.padStart(2,'0'),birthDay?.value?.padStart(2,'0')].every(Boolean))
    ? `${birthYear.value}-${birthMonth.value.padStart(2,'0')}-${birthDay.value.padStart(2,'0')}`
    : '';
  const data=new FormData();
  data.append('_method','PATCH');
  data.append('full_name',fullName?.value.trim()||'');
  data.append('phone',phone?.value.trim()||'');
  data.append('gender',gender);
  data.append('birthday',birthday);
  data.append('remove_photo',pendingRemovePhoto?'1':'0');
  if(pendingPhotoFile&&!pendingRemovePhoto)data.append('photo',pendingPhotoFile);
  const response=await fetch('/profile',{method:'POST',headers:{'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||'','Accept':'application/json'},body:data});
  const result=await response.json().catch(()=>({}));
  if(!response.ok){
    const message=Object.values(result.errors||{}).flat()[0]||result.message||'Unable to update your profile.';
    throw new Error(message);
  }
  window.bearlyBuyerProfile={...window.bearlyBuyerProfile,...result.data};
  const savedBirthday=String(result.data.birthday||'').split('-');
  Object.assign(defaults,{...result.data,birthMonth:savedBirthday[1]||'',birthDay:savedBirthday[2]||'',birthYear:savedBirthday[0]||''});
  pendingPhotoFile=null;pendingRemovePhoto=false;
  load();
  const t=$('#profile-toast'); if(t){ t.textContent=result.message||'Profile saved.';t.classList.add('show'); setTimeout(()=>t.classList.remove('show'),1700); }
}

const VOUCHER_KEY=window.bearlyStorageKey?.('claimed-vouchers')||'bearly-claimed-vouchers-v1';
const LEGACY_VOUCHER_KEY=window.bearlyStorageKey?.('claimed-platform-vouchers')||'bearly-claimed-platform-vouchers-v1';
const VOUCHER_ICON='/images/vouchers/Bearly-voucher-icon.png';
const voucherCatalog=[
 {id:'BEARLY100',section:'recommended',type:'discount',title:'₱100 OFF',minimum:'Min. Spend ₱799',scope:'All Products',valueLabel:'₱100',valueCaption:'OFF',description:'Get ₱100 off when you spend at least ₱799.',minSpend:799,discount:100,expires:'Oct 31, 2026',terms:'Get ₱100 off when your eligible Bearly order reaches ₱799. One platform voucher may be applied per eligible order.'},
 {id:'BEARLY50',section:'recommended',type:'discount',title:'₱50 OFF',minimum:'Min. Spend ₱399',scope:'All Products',valueLabel:'₱50',valueCaption:'OFF',description:'Save ₱50 on your next eligible Bearly order.',minSpend:399,discount:50,expires:'Nov 15, 2026',terms:'Get ₱50 off when your eligible Bearly order reaches ₱399. Valid on participating products only.'},
 {id:'PAYDAY30',section:'recommended',type:'discount',title:'30% OFF Payday',minimum:'Min. Spend ₱599',scope:'Selected Products',valueLabel:'30%',valueCaption:'OFF',description:'Save 30% up to ₱150 on selected everyday finds.',minSpend:599,percent:30,cap:150,expires:'Oct 15, 2026',terms:'Save 30% up to a ₱150 discount when your eligible order reaches ₱599.'},
 {id:'BEARLY30',section:'recommended',type:'discount',title:'₱30 OFF',minimum:'Min. Spend ₱300',scope:'All Products',valueLabel:'₱30',valueCaption:'OFF',description:'Get ₱30 off your next eligible Bearly order.',minSpend:300,discount:30,expires:'Oct 31, 2026',terms:'Get ₱30 off when your eligible Bearly order reaches ₱300. This voucher cannot be exchanged for cash.'},
 {id:'BEARLYSHIP',section:'shipping',type:'shipping',title:'Free Shipping',minimum:'Min. Spend ₱499',scope:'All Products',valueLabel:'FREE',valueCaption:'SHIPPING',description:'Save up to ₱50 on shipping for eligible Bearly orders.',minSpend:499,discount:50,shippingVoucher:true,expires:'Oct 31, 2026',terms:'Free shipping applies to eligible delivery options for orders worth at least ₱499. Coverage and shipping partners may vary.'},
 {id:'BEARLYSHIP50',section:'shipping',type:'shipping',title:'50% OFF Shipping',minimum:'Min. Spend ₱99',scope:'All Products',valueLabel:'50%',valueCaption:'OFF SHIPPING',description:'Pay less for shipping on qualifying orders.',minSpend:99,shippingPercent:50,shippingVoucher:true,expires:'Oct 31, 2026',terms:'Enjoy 50% off the eligible shipping fee for an order worth at least ₱99, subject to the platform shipping discount cap.'},
 {id:'BEARLYSHIP30',section:'shipping',type:'shipping',title:'₱30 OFF Shipping',minimum:'Min. Spend ₱399',scope:'All Products',valueLabel:'₱30',valueCaption:'OFF SHIPPING',description:'Get up to ₱30 off eligible shipping fees.',minSpend:399,discount:30,shippingVoucher:true,expires:'Oct 31, 2026',terms:'Get up to ₱30 off eligible shipping fees on qualifying Bearly orders worth at least ₱399.'},
 {id:'BEARLYELEC150',section:'category',type:'category',title:'₱150 OFF',minimum:'Min. Spend ₱1,500',scope:'Electronics and Gadgets',valueLabel:'₱150',valueCaption:'OFF',description:'Save on eligible electronics and gadget finds.',minSpend:1500,discount:150,expires:'Oct 31, 2026',terms:'Get ₱150 off eligible Electronics and Gadgets products when the category subtotal reaches ₱1,500.'},
 {id:'BEARLYMENS100',section:'category',type:'category',title:'₱100 OFF',minimum:'Min. Spend ₱800',scope:"Men's Apparel",valueLabel:'₱100',valueCaption:'OFF',description:'Save on eligible men’s apparel products.',minSpend:800,discount:100,expires:'Oct 31, 2026',terms:"Get ₱100 off eligible Men's Apparel products when the category subtotal reaches ₱800."},
 {id:'BEARLYHOME80',section:'category',type:'category',title:'₱80 OFF',minimum:'Min. Spend ₱600',scope:'Home and Garden',valueLabel:'₱80',valueCaption:'OFF',description:'Save on eligible home and garden finds.',minSpend:600,discount:80,expires:'Nov 15, 2026',terms:'Get ₱80 off eligible Home and Garden products when the category subtotal reaches ₱600.'},
 {id:'SHOPHOME50',section:'shop',type:'shop',title:'₱50 OFF',minimum:'Min. Spend ₱499',scope:'Bearly Home Store',valueLabel:'₱50',valueCaption:'OFF',description:'Save ₱50 on eligible finds from Bearly Home Store.',minSpend:499,discount:50,shopVoucher:true,expires:'Nov 30, 2026',terms:'Get ₱50 off eligible products from Bearly Home Store when the shop subtotal reaches ₱499. One shop voucher may be applied per eligible store order.'},
 {id:'SHOPTECH100',section:'shop',type:'shop',title:'₱100 OFF',minimum:'Min. Spend ₱999',scope:'Tech Haven',valueLabel:'₱100',valueCaption:'OFF',description:'Save ₱100 on qualifying Tech Haven products.',minSpend:999,discount:100,shopVoucher:true,expires:'Nov 30, 2026',terms:'Get ₱100 off eligible Tech Haven products when the shop subtotal reaches ₱999. Participating products only.'},
 {id:'SHOPSTYLE15',section:'shop',type:'shop',title:'15% OFF',minimum:'Min. Spend ₱799',scope:'Everyday Style Shop',valueLabel:'15%',valueCaption:'OFF',description:'Take 15% off eligible Everyday Style Shop finds.',minSpend:799,percent:15,cap:150,shopVoucher:true,expires:'Dec 15, 2026',terms:'Save 15% up to ₱150 on eligible Everyday Style Shop products when the shop subtotal reaches ₱799.'},
 {id:'BEARLYNEW60',section:'new',type:'new',title:'₱60 OFF',minimum:'Min. Spend ₱600',scope:'All Products',valueLabel:'₱60',valueCaption:'OFF',description:'A new Bearly platform reward for eligible orders.',minSpend:600,discount:60,expires:'Nov 15, 2026',terms:'A new Bearly platform voucher worth ₱60 on eligible orders reaching ₱600.'},
 {id:'BEARLYNEW120',section:'new',type:'new',title:'₱120 OFF',minimum:'Min. Spend ₱1,200',scope:'All Products',valueLabel:'₱120',valueCaption:'OFF',description:'Save more on a larger eligible Bearly order.',minSpend:1200,discount:120,expires:'Nov 15, 2026',terms:'A new Bearly platform voucher worth ₱120 on eligible orders reaching ₱1,200.'},
 {id:'BEARLYNEW200',section:'new',type:'new',title:'₱200 OFF',minimum:'Min. Spend ₱2,000',scope:'All Products',valueLabel:'₱200',valueCaption:'OFF',description:'A bigger reward for your next eligible order.',minSpend:2000,discount:200,expires:'Nov 15, 2026',terms:'A new Bearly platform voucher worth ₱200 on eligible orders reaching ₱2,000.'}
];
const voucherSections=[
 {key:'recommended',title:'Recommended Vouchers'},
 {key:'shipping',title:'Shipping Vouchers'},
 {key:'category',title:'Category Vouchers'},
 {key:'shop',title:'Shop Vouchers'},
 {key:'new',title:'New Vouchers'}
];
const legacyVoucherIds={
 'bearly-100-off':'BEARLY100',
 'bearly-50-off':'BEARLY50',
 'bearly-30-off':'BEARLY30',
 'bearly-free-shipping':'BEARLYSHIP',
 'bearly-50-shipping':'BEARLYSHIP50',
 'bearly-30-shipping':'BEARLYSHIP30',
 'bearly-electronics-150':'BEARLYELEC150',
 'bearly-mens-100':'BEARLYMENS100',
 'bearly-home-80':'BEARLYHOME80',
 'bearly-new-60':'BEARLYNEW60',
 'bearly-new-120':'BEARLYNEW120',
 'bearly-new-200':'BEARLYNEW200'
};
function readVoucherIds(key){try{const value=JSON.parse(localStorage.getItem(key)||'[]');return Array.isArray(value)?value.map(String):[]}catch{return[]}}
function normalizeVoucherId(id){const value=String(id||'');return legacyVoucherIds[value]||value}
function getClaimedVouchers(){
 const known=new Set(voucherCatalog.map(v=>v.id));
 const current=readVoucherIds(VOUCHER_KEY).map(normalizeVoucherId);
 const legacy=readVoucherIds(LEGACY_VOUCHER_KEY).map(normalizeVoucherId);
 const claimed=[...new Set([...current,...legacy])].filter(id=>known.has(id));
 if(JSON.stringify(readVoucherIds(VOUCHER_KEY))!==JSON.stringify(claimed)){try{localStorage.setItem(VOUCHER_KEY,JSON.stringify(claimed))}catch{}}
 return claimed;
}
function saveClaimedVouchers(v){try{localStorage.setItem(VOUCHER_KEY,JSON.stringify([...new Set(v.map(normalizeVoucherId))]))}catch{}window.dispatchEvent(new CustomEvent('bearly:vouchers-changed'));}
function initVouchers(){
 const list=$('#voucher-list'); if(!list)return;
 const filterButtons=[...document.querySelectorAll('#vouchers-panel [data-voucher-filter]')];
 const allowedFilters=new Set(['all','shipping','discount','category','shop','new','claimed']);
 let filter=allowedFilters.has(new URLSearchParams(location.search).get('voucher'))?new URLSearchParams(location.search).get('voucher'):'all';
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
 const updateCount=()=>{const count=getClaimedVouchers().length;const badge=$('#vouchers-nav-count');if(badge){badge.textContent=count;badge.hidden=count===0}const label=$('#voucher-claimed-count');if(label)label.textContent=`${count} claimed`;};
 const notify=message=>{const toast=$('#voucher-toast');if(!toast)return;toast.textContent=message;toast.classList.add('show');clearTimeout(window.__bearlyVoucherToast);window.__bearlyVoucherToast=setTimeout(()=>toast.classList.remove('show'),1700)};
  const renderCard=(voucher,claimed)=>`<article class="voucher-card" data-voucher-card data-voucher-id="${esc(voucher.id)}" data-voucher-title="${esc(voucher.title)}" data-voucher-type="${voucher.shopVoucher?'shop':'platform'}" data-voucher-terms="${esc(voucher.terms)}"><div class="voucher-brand-panel"><img src="${VOUCHER_ICON}" alt="" aria-hidden="true"><div class="voucher-brand-label"><strong>${esc(voucher.valueLabel)}</strong><small>${esc(voucher.valueCaption)}</small></div></div><div class="voucher-card-body"><button type="button" class="voucher-terms-button" data-voucher-terms-button>T&amp;C</button><h3>${esc(voucher.title)}</h3><p class="voucher-minimum">${esc(voucher.minimum)}</p><p class="voucher-description">${esc(voucher.description)}</p><div class="voucher-tags"><span>${esc(voucher.scope)}</span><span>${voucher.shopVoucher?'Shop Voucher':'Platform Voucher'}</span></div><div class="voucher-card-footer"><span class="voucher-validity"><span class="material-symbols-outlined" aria-hidden="true">schedule</span>Valid until ${esc(voucher.expires)}</span>${claimed?'<a class="voucher-claim voucher-use" href="/checkout">Use Now</a>':'<button type="button" class="voucher-claim" data-voucher-claim>Claim</button>'}</div></div></article>`;
 const render=()=>{
  const claimedIds=getClaimedVouchers();
  const rows=filter==='claimed'?voucherCatalog.filter(v=>claimedIds.includes(v.id)):voucherCatalog.filter(v=>!claimedIds.includes(v.id)&&(filter==='all'||v.type===filter));
  const groups=filter==='claimed'?[{key:'claimed',title:'Claimed Vouchers',rows}]:voucherSections.map(section=>({key:section.key,title:section.title,rows:rows.filter(v=>v.section===section.key)})).filter(section=>section.rows.length);
  list.innerHTML=groups.map(group=>`<section class="voucher-section" data-voucher-section="${esc(group.key)}"><div class="voucher-section-heading"><h2>${esc(group.title)}</h2><span>${group.rows.length} ${group.rows.length===1?'voucher':'vouchers'}</span></div><div class="voucher-grid">${group.rows.map(v=>renderCard(v,claimedIds.includes(v.id))).join('')}</div></section>`).join('');
  const empty=$('#vouchers-empty');if(empty){empty.hidden=rows.length!==0;const heading=empty.querySelector('h2');const copy=empty.querySelector('p');if(heading)heading.textContent=filter==='claimed'?'No claimed vouchers yet':'No available vouchers here';if(copy)copy.textContent=filter==='claimed'?'Claim a voucher from the available rewards and it will appear here.':'You have claimed all vouchers in this section.'}updateCount();
 };
 const setFilter=value=>{filter=allowedFilters.has(value)?value:'all';filterButtons.forEach(button=>{const active=button.dataset.voucherFilter===filter;button.classList.toggle('active',active);button.setAttribute('aria-pressed',String(active))});render()};
 filterButtons.forEach(button=>button.addEventListener('click',()=>setFilter(button.dataset.voucherFilter||'all')));
 list.addEventListener('click',e=>{
  const terms=e.target.closest('[data-voucher-terms-button]');
   if(terms){const card=terms.closest('[data-voucher-card]');const dialog=$('#voucher-terms-dialog');if(card&&dialog){$('#voucher-dialog-title').textContent=card.dataset.voucherTitle||'Voucher terms';$('#voucher-dialog-type').textContent=card.dataset.voucherType==='shop'?'Bearly Shop Voucher':'Bearly Platform Voucher';$('#voucher-dialog-copy').textContent=card.dataset.voucherTerms||'This Bearly voucher is subject to eligibility and checkout conditions.';if(!dialog.open)dialog.showModal()}return}
  const button=e.target.closest('[data-voucher-claim]');if(!button)return;const card=button.closest('[data-voucher-card]');const id=card?.dataset.voucherId;if(!id)return;const claimed=getClaimedVouchers();if(!claimed.includes(id)){claimed.push(id);saveClaimedVouchers(claimed);notify(`${card.dataset.voucherTitle||'Voucher'} claimed.`);render()}
 });
 document.querySelectorAll('[data-voucher-dialog-close]').forEach(button=>button.addEventListener('click',()=>$('#voucher-terms-dialog')?.close()));
 $('#voucher-terms-dialog')?.addEventListener('click',e=>{if(e.target===$('#voucher-terms-dialog'))e.target.close()});
 window.addEventListener('storage',e=>{if(!e.key||e.key===VOUCHER_KEY||e.key===LEGACY_VOUCHER_KEY)render()});
 window.addEventListener('bearly:vouchers-changed',render);
 window.renderVouchers=render;
 setFilter(filter);
}

function updateNotificationBadges(items=getNotifications()){
 const count=items.filter(x=>!x.read).length;
 document.querySelectorAll('[data-notification-badge]').forEach(el=>{el.textContent=count;el.hidden=count===0});
 const nav=$('#notifications-nav-count'); if(nav){nav.textContent=count;nav.hidden=count===0;}
}
function initNotifications(showAccountPanel){
  const list=$('#notification-list'); if(!list){updateNotificationBadges();return;}
  let filter='all';
  const escapeHtml=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const safeImage=src=>{const value=String(src||'');return /^(\/images\/|\/storage\/)/.test(value)?value:'/images/bearly-logo.png'};
 const render=()=>{
   const items=getNotifications(); updateNotificationBadges(items);
   const visible=filter==='all'?items:items.filter(x=>x.type===filter);
    const groups=['Today','Yesterday','Earlier'];
    list.innerHTML=groups.map(group=>{const groupItems=visible.filter(item=>(item.group||'Earlier')===group);if(!groupItems.length)return '';return `<section class="notification-group" aria-labelledby="notification-group-${group.toLowerCase()}"><h2 id="notification-group-${group.toLowerCase()}">${group}</h2>${groupItems.map(n=>{const attachment=n.image?`<img src="${escapeHtml(safeImage(n.image))}" onerror="this.onerror=null;this.src='/images/bearly-logo.png'" alt="">`:n.actionLabel?`<a class="notification-cta" href="${escapeHtml(n.actionHref||'#')}" data-notification-action>${escapeHtml(n.actionLabel)}</a>`:'';return `<article class="notification-item ${n.read?'':'unread'}" data-notification-id="${escapeHtml(n.id)}" tabindex="0" role="button"><span class="notification-unread-dot" aria-label="${n.read?'':'Unread'}"></span><div class="notification-icon"><span class="material-symbols-outlined" aria-hidden="true">${escapeHtml(n.icon)}</span></div><div class="notification-copy"><strong>${escapeHtml(n.title)}</strong><p>${escapeHtml(n.message)}</p>${n.detail?`<small>${escapeHtml(n.detail)}</small>`:''}</div><div class="notification-attachment">${attachment}</div><span class="notification-arrow material-symbols-outlined" aria-hidden="true">chevron_right</span><time class="notification-time">${escapeHtml(n.time)}</time></article>`}).join('')}</section>`}).join('');
   const empty=$('#notifications-empty'); if(empty) empty.hidden=visible.length!==0;
 };
 const openNotification=id=>{
   const items=getNotifications(); const item=items.find(x=>x.id===id); if(!item)return;
   item.read=true; saveNotifications(items); render();
    if(['purchases','reviews','vouchers'].includes(item.target) && typeof showAccountPanel==='function') showAccountPanel(item.target);
 };
 list.addEventListener('click',e=>{const row=e.target.closest('[data-notification-id]');if(row)openNotification(row.dataset.notificationId)});
 list.addEventListener('keydown',e=>{if((e.key==='Enter'||e.key===' ')&&e.target.matches('[data-notification-id]')){e.preventDefault();openNotification(e.target.dataset.notificationId)}});
  document.querySelectorAll('[data-notification-filter]').forEach(btn=>btn.addEventListener('click',()=>{filter=btn.dataset.notificationFilter||'all';document.querySelectorAll('[data-notification-filter]').forEach(x=>{const active=x===btn;x.classList.toggle('active',active);x.setAttribute('aria-selected',active?'true':'false')});render()}));
  $('#mark-all-notifications')?.addEventListener('click',()=>{const items=getNotifications().map(x=>({...x,read:true}));saveNotifications(items);render()});
  window.addEventListener('bearly:notifications-changed',render);
 render();
}


const helpTopics=[
  {category:'orders',icon:'receipt_long',question:'Where can I see my orders?',answer:'Open My Purchases to view orders by status. Completed and cancelled orders are available in their respective tabs.',action:'purchases',actionLabel:'Open My Purchases'},
  {category:'orders',icon:'cancel',question:'Can I cancel an order?',answer:'Cancellation options depend on the order status. For this frontend demo, cancelled orders appear in the Cancelled tab after their status is updated.'},
 {category:'shipping',icon:'local_shipping',question:'How do I track my order?',answer:'Go to My Purchases, open the To Receive tab, then choose Track Order to see the delivery timeline.',action:'purchases',actionLabel:'Track from My Purchases'},
 {category:'shipping',icon:'location_on',question:'How do I change my delivery address?',answer:'Open Addresses in My Account to add, edit, delete, or set your default delivery address.',action:'addresses',actionLabel:'Manage Addresses'},
 {category:'payments',icon:'payments',question:'What payment methods are available?',answer:'Bearly currently accepts Cash on Delivery (COD) for buyer orders. You pay with cash when your order is delivered.'},
 {category:'returns',icon:'assignment_return',question:'How do returns and refunds work?',answer:'Returns and refunds are not connected to a backend yet. This Help Center keeps the buyer flow ready for that feature without creating a fake transaction.'},
  {category:'account',icon:'person',question:'How do I update my profile?',answer:'Open Profile, edit your buyer information, then save your changes. Profile details are saved to your Bearly account.',action:'profile',actionLabel:'Open Profile'},
 {category:'account',icon:'favorite',question:'Where can I find products I liked?',answer:'Open My Likes to see products saved with the heart button.',action:'likes',actionLabel:'Open My Likes'},
 {category:'vouchers',icon:'confirmation_number',question:'How do I claim a voucher?',answer:'Open My Vouchers, choose an available reward, and press Claim. Claimed vouchers stay saved locally in this demo.',action:'vouchers',actionLabel:'Open My Vouchers'},
 {category:'vouchers',icon:'shopping_cart_checkout',question:'How do I use a claimed voucher?',answer:'From My Vouchers, choose Use Now to continue to Checkout. Eligible claimed vouchers can be selected there.',action:'vouchers',actionLabel:'View Vouchers'}
];
function initHelpCenter(showAccountPanel){
 const list=$('#help-list'); if(!list)return;
 const search=$('#help-search'); let category='all';
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
 const render=()=>{const q=(search?.value||'').trim().toLowerCase();const rows=helpTopics.filter(t=>(category==='all'||t.category===category)&&(!q||`${t.question} ${t.answer} ${t.category}`.toLowerCase().includes(q)));list.innerHTML=rows.map((t,i)=>`<article class="help-item"><button class="help-question" type="button" aria-expanded="false"><span class="help-topic-icon material-symbols-outlined">${esc(t.icon)}</span><span>${esc(t.question)}</span><span class="material-symbols-outlined help-chevron">expand_more</span></button><div class="help-answer" hidden><p>${esc(t.answer)}</p>${t.action?`<button class="help-action" type="button" data-help-action="${esc(t.action)}">${esc(t.actionLabel)}</button>`:''}</div></article>`).join('');const empty=$('#help-empty');if(empty)empty.hidden=rows.length!==0;};
 list.addEventListener('click',e=>{const action=e.target.closest('[data-help-action]');if(action){showAccountPanel?.(action.dataset.helpAction);return}const q=e.target.closest('.help-question');if(!q)return;const answer=q.nextElementSibling;const open=q.getAttribute('aria-expanded')==='true';q.setAttribute('aria-expanded',String(!open));if(answer)answer.hidden=open;});
 search?.addEventListener('input',render);document.querySelectorAll('[data-help-category]').forEach(btn=>btn.addEventListener('click',()=>{category=btn.dataset.helpCategory||'all';document.querySelectorAll('[data-help-category]').forEach(x=>x.classList.toggle('active',x===btn));render()}));
 window.renderHelpCenter=render;render();
}

function initProfile(){
  if (window.bearlyProfileInitialized) return;
  window.bearlyProfileInitialized = true;

  fillSelects();
  load();
  const profileForm=$('#profile-form');
  if(profileForm) profileForm.addEventListener('submit',async e=>{e.preventDefault();try{await save()}catch(error){alert(error.message)}});
  const editProfile=$('#edit-profile');
   if(editProfile) editProfile.addEventListener('click',()=>{
     showAccountPanel('profile');
     requestAnimationFrame(()=>$('#full-name')?.focus());
   });
  const selectPhoto=$('#select-photo');
  const profileAvatarButton=$('#profile-avatar');
  if(selectPhoto) selectPhoto.addEventListener('click',()=>$('#photo-input')?.click());
  if(profileAvatarButton) profileAvatarButton.addEventListener('click',()=>$('#photo-input')?.click());
  const photoInput=$('#photo-input');
  if(photoInput) photoInput.addEventListener('change',e=>{
   const f=e.target.files?.[0]; if(!f)return;
   if(f.size>5*1024*1024){alert('Please choose an image smaller than 5 MB.');e.target.value='';return}
   if(!['image/jpeg','image/png','image/webp'].includes(f.type)){alert('JPG, PNG, and WEBP images only.');e.target.value='';return}
    pendingPhotoFile=f;pendingRemovePhoto=false;const r=new FileReader(); r.onload=()=>avatar(r.result);r.readAsDataURL(f);
  });
  const removePhoto=$('#remove-photo');
  if(removePhoto) removePhoto.addEventListener('click',()=>{
    pendingPhotoFile=null;pendingRemovePhoto=true;
    avatar('');
    if(photoInput) photoInput.value='';
  });

  const accountTabs=document.querySelectorAll('[data-account-tab]');
  const accountPanels=document.querySelectorAll('[data-account-panel]');
  function showAccountPanel(name){
    const tabs=document.querySelectorAll('[data-account-tab]');
    const panels=document.querySelectorAll('[data-account-panel]');
    tabs.forEach(b=>b.classList.toggle('active',b.dataset.accountTab===name));
    panels.forEach(p=>p.hidden=p.dataset.accountPanel!==name);
    const hash=name==='profile'?'':`#${name}`;
    history.replaceState(null,'',`/profile${hash}`);
    if(name==='likes'&&typeof window.renderMyLikes==='function')window.renderMyLikes();
    if(name==='purchases'&&typeof window.renderMyPurchases==='function')window.renderMyPurchases();
    if(name==='reviews'&&typeof window.renderReviews==='function')window.renderReviews();
    if(name==='tracking'&&typeof window.renderOrderTracking==='function')window.renderOrderTracking();
    if(name==='vouchers'&&typeof window.renderVouchers==='function')window.renderVouchers();
    if(name==='help'&&typeof window.renderHelpCenter==='function')window.renderHelpCenter();
    window.bearlyCurrentAccountPanel=name;
  }
  initNotifications(showAccountPanel);
  initVouchers();
  initHelpCenter(showAccountPanel);
  if(accountTabs.length){
    accountTabs.forEach(b=>b.addEventListener('click',()=>showAccountPanel(b.dataset.accountTab)));
  }
  if(location.hash==='#addresses')showAccountPanel('addresses');
  if(location.hash==='#likes')showAccountPanel('likes');
  if(location.hash==='#purchases')showAccountPanel('purchases');
  if(location.hash==='#history')showAccountPanel('purchases');
  if(location.hash==='#reviews')showAccountPanel('reviews');
  if(location.hash==='#notifications')showAccountPanel('notifications');
  if(location.hash==='#tracking')showAccountPanel('purchases');
  if(location.hash==='#vouchers')showAccountPanel('vouchers');
  if(location.hash==='#help')showAccountPanel('help');
  window.bearlyShowAccountPanel=showAccountPanel;
}

if(document.readyState === 'loading'){
  document.addEventListener('DOMContentLoaded', initProfile);
} else {
  initProfile();
}

/* Bearly global navbar state: keeps notification/cart badges in sync across buyer pages. */
(function initBearlyGlobalNavbarState(){
  if(window.BearlyNavbarState)return;
  const CART_KEY=window.bearlyStorageKey?.('preview-cart')||'bearly-preview-cart-v1';
  const NOTIFICATION_KEY=window.bearlyStorageKey?.('preview-notifications')||'bearly-notifications-v1';
  function cartCount(){
    try{const x=JSON.parse(localStorage.getItem(CART_KEY)||'[]');const preview=Array.isArray(x)?x.reduce((n,i)=>n+Math.max(0,Number(i?.quantity||0)),0):0;return preview+Number(window.bearlyBuyerProfile?.cartCount||0)}catch{return Number(window.bearlyBuyerProfile?.cartCount||0)}
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
  setInterval(sync,5000);
  window.BearlyNavbarState={sync};
})();

document.addEventListener('DOMContentLoaded',()=>{bindPhilippinePhone(document.getElementById('phone'));bindPhilippinePhone(document.getElementById('address-phone-number'));});
