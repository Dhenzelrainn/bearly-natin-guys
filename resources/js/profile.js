import './notification-center.js';

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

const VOUCHER_ICON='/images/vouchers/Bearly-voucher-icon.png';
const voucherSections=[
 {key:'shipping',title:'Shipping Vouchers'},
 {key:'discount',title:'Discount Vouchers'},
 {key:'category',title:'Category Vouchers'},
 {key:'shop',title:'Shop Vouchers'}
];
function initVouchers(){
 const list=$('#voucher-list'); if(!list)return;
 const filterButtons=[...document.querySelectorAll('#vouchers-panel [data-voucher-filter]')];
 const allowedFilters=new Set(['all','shipping','discount','category','shop','new','claimed']);
 const initialFilter=new URLSearchParams(location.search).get('voucher');
 let filter=allowedFilters.has(initialFilter)?initialFilter:'all';
 let wallet={platform:[],store:[],claimed_count:0};
 let loading=false;
 let walletRevision=0;
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
 const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
 const allVouchers=()=>[...(wallet.platform||[]),...(wallet.store||[])];
 const applyClaimedVoucher=voucher=>{
  const source=voucher?.source==='store'?'store':(voucher?.source==='platform'?'platform':null);
  if(!source||!voucher?.id)return false;
  const rows=[...(wallet[source]||[])];
  const index=rows.findIndex(row=>Number(row.id)===Number(voucher.id));
  if(index>=0)rows[index]={...rows[index],...voucher,claimed:true,can_claim:false};
  else rows.push({...voucher,claimed:true,can_claim:false});
  wallet[source]=rows;
  wallet.claimed_count=allVouchers().filter(row=>row.claimed).length;
  walletRevision+=1;
  return true;
 };
 const updateCount=()=>{const count=allVouchers().filter(v=>v.claimed).length;wallet.claimed_count=count;const badge=$('#vouchers-nav-count');if(badge){badge.textContent=count;badge.hidden=count===0}const label=$('#voucher-claimed-count');if(label)label.textContent=`${count} claimed`;};
 const notify=message=>{const toast=$('#voucher-toast');if(!toast)return;toast.textContent=message;toast.classList.add('show');clearTimeout(window.__bearlyVoucherToast);window.__bearlyVoucherToast=setTimeout(()=>toast.classList.remove('show'),2100)};
 const setCodeStatus=(message,tone='')=>{const node=$('#voucher-code-status');if(!node)return;node.textContent=message||'';node.dataset.tone=tone;node.hidden=!message};
  const voucherTypeLabel=voucher=>voucher.source==='store'?'Store Voucher':(voucher.kind==='free_shipping'?'Free Shipping':'Platform Voucher');
  const renderCard=(voucher,claimed)=>`<article class="voucher-card" data-voucher-card data-voucher-id="${esc(voucher.id)}" data-voucher-source="${esc(voucher.source)}" data-voucher-title="${esc(voucher.title)}" data-voucher-type="${esc(voucher.type||'discount')}" data-voucher-terms="${esc(voucher.terms)}"><div class="voucher-brand-panel"><img src="${VOUCHER_ICON}" alt="" aria-hidden="true"><div class="voucher-brand-label"><strong>${esc(voucher.value_label)}</strong><small>${esc(voucher.value_caption)}</small></div></div><div class="voucher-card-body"><button type="button" class="voucher-terms-button" data-voucher-terms-button>T&amp;C</button><h3>${esc(voucher.title)}</h3><p class="voucher-minimum">${esc(voucher.minimum)}</p><p class="voucher-description">${esc(voucher.description)}</p><div class="voucher-tags"><span>${esc(voucher.scope)}</span><span>${esc(voucherTypeLabel(voucher))}</span>${voucher.code?`<span>Code ${esc(voucher.code)}</span>`:''}</div><div class="voucher-card-footer"><span class="voucher-validity"><span class="material-symbols-outlined" aria-hidden="true">schedule</span>Valid until ${esc(voucher.expires)}</span>${claimed?'<a class="voucher-claim voucher-use" href="/checkout">Use Now</a>':(voucher.can_claim?'<button type="button" class="voucher-claim" data-voucher-claim>Claim</button>':(voucher.auto_applies?'<span class="voucher-auto-applies"><span class="material-symbols-outlined" aria-hidden="true">local_shipping</span>Auto-applies at checkout</span>':'<span class="voucher-claim" aria-disabled="true">Unavailable</span>'))}</div></div></article>`;
 const visibleVouchers=()=>{
   const rows=allVouchers();
   if(filter==='claimed')return rows.filter(v=>v.claimed);
   const available=rows.filter(v=>!v.claimed);
   if(filter==='all')return available;
   if(filter==='new')return available.filter(v=>v.is_new);
   return available.filter(v=>v.section===filter);
 };
 const render=()=>{
   const rows=visibleVouchers();
   const groups=filter==='claimed'
     ?[{key:'claimed',title:'Claimed Vouchers',rows}]
     :voucherSections.map(section=>({key:section.key,title:section.title,rows:rows.filter(v=>v.section===section.key)})).filter(section=>section.rows.length);
   list.innerHTML=groups.map(group=>`<section class="voucher-section" data-voucher-section="${esc(group.key)}"><div class="voucher-section-heading"><h2>${esc(group.title)}</h2><span>${group.rows.length} ${group.rows.length===1?'voucher':'vouchers'}</span></div><div class="voucher-grid">${group.rows.map(v=>renderCard(v,v.claimed)).join('')}</div></section>`).join('');
   const empty=$('#vouchers-empty');if(empty){empty.hidden=rows.length!==0;const heading=empty.querySelector('h2');const copy=empty.querySelector('p');if(heading)heading.textContent=filter==='claimed'?'No claimed vouchers yet':'No available vouchers here';if(copy)copy.textContent=filter==='claimed'?'Claim a voucher from the available rewards and it will appear here.':'New Bearly and Store vouchers will appear here when they become available.'}
   updateCount();
 };
 const fetchWallet=async(quiet=false)=>{
   if(loading)return;
   loading=true;
   const revision=walletRevision;
   try{
     const response=await fetch('/profile/vouchers/data',{headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},cache:'no-store'});
     const result=await response.json().catch(()=>({}));
     if(!response.ok)throw new Error(result.message||'Unable to load your vouchers.');
     if(revision!==walletRevision)return;
     wallet=result.data||{platform:[],store:[],claimed_count:0};
     render();
   }catch(error){
     if(!quiet)notify(error.message);
   }finally{
     loading=false;
   }
 };
 const claim=async(payload,button)=>{
   const original=button?.textContent;
   if(button){button.disabled=true;button.textContent='Claiming...'}
   try{
     const response=await fetch('/profile/vouchers/claim',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf()},body:JSON.stringify(payload)});
     const result=await response.json().catch(()=>({}));
     if(!response.ok){const message=Object.values(result.errors||{}).flat()[0]||result.message||'Unable to claim this voucher.';throw new Error(message)}
     if(applyClaimedVoucher(result.data))render();
     else await fetchWallet(true);
     notify(result.message||'Voucher claimed.');
   }catch(error){
     notify(error.message);
   }finally{
     if(button){button.disabled=false;button.textContent=original}
   }
 };
 const setFilter=value=>{filter=allowedFilters.has(value)?value:'all';filterButtons.forEach(button=>{const active=button.dataset.voucherFilter===filter;button.classList.toggle('active',active);button.setAttribute('aria-pressed',String(active))});render()};
 filterButtons.forEach(button=>button.addEventListener('click',()=>setFilter(button.dataset.voucherFilter||'all')));
 list.addEventListener('click',e=>{
   const terms=e.target.closest('[data-voucher-terms-button]');
    if(terms){const card=terms.closest('[data-voucher-card]');const dialog=$('#voucher-terms-dialog');if(card&&dialog){$('#voucher-dialog-title').textContent=card.dataset.voucherTitle||'Voucher terms';$('#voucher-dialog-type').textContent=card.dataset.voucherSource==='store'?'Bearly Store Voucher':(card.dataset.voucherType==='shipping'?'Bearly Shipping Offer':'Bearly Platform Voucher');$('#voucher-dialog-copy').textContent=card.dataset.voucherTerms||'This Bearly voucher is subject to eligibility and checkout conditions.';if(!dialog.open)dialog.showModal()}return}
   const button=e.target.closest('[data-voucher-claim]');if(!button)return;const card=button.closest('[data-voucher-card]');const id=Number(card?.dataset.voucherId||0);if(!id)return;
   claim(card.dataset.voucherSource==='store'?{store_promotion_id:id}:{platform_promotion_id:id},button);
 });
 const codeInput=$('#voucher-code-input');
 const codeButton=$('#voucher-code-claim');
 const claimCode=async()=>{
   const code=(codeInput?.value||'').trim().toUpperCase();
   if(!code){setCodeStatus('Enter a voucher code first.','error');codeInput?.focus();return}
   if(codeButton){codeButton.disabled=true;codeButton.textContent='Checking...'}
   try{
     const response=await fetch('/profile/vouchers/claim',{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf()},body:JSON.stringify({code})});
     const result=await response.json().catch(()=>({}));
     if(!response.ok){const message=Object.values(result.errors||{}).flat()[0]||result.message||'That voucher code is invalid or no longer available.';throw new Error(message)}
     if(codeInput)codeInput.value='';
     if(applyClaimedVoucher(result.data))render();
     else await fetchWallet(true);
     setCodeStatus(result.message||'Voucher added to My Vouchers.','success');
     notify(result.message||'Voucher added to My Vouchers.');
   }catch(error){
     setCodeStatus(error.message,'error');
   }finally{
     if(codeButton){codeButton.disabled=false;codeButton.textContent='Claim code'}
   }
 };
 codeButton?.addEventListener('click',claimCode);
 codeInput?.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();claimCode()}});
 document.querySelectorAll('[data-voucher-dialog-close]').forEach(button=>button.addEventListener('click',()=>$('#voucher-terms-dialog')?.close()));
 $('#voucher-terms-dialog')?.addEventListener('click',e=>{if(e.target===$('#voucher-terms-dialog'))e.target.close()});
 window.renderVouchers=()=>fetchWallet(true);
 window.addEventListener('bearly:vouchers-changed',()=>fetchWallet(true));
 setFilter(filter);
 fetchWallet();
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
 {category:'vouchers',icon:'confirmation_number',question:'How do I claim a voucher?',answer:'Open My Vouchers, choose an available reward, and press Claim. You can also enter a platform or Store voucher code to add it to your wallet.',action:'vouchers',actionLabel:'Open My Vouchers'},
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
  /* Notification badges mirror the real unread count synced by notification-center.js. */
  function cartCount(){
    return Math.max(0, Number(window.bearlyBuyerProfile?.cartCount || 0));
  }
  function notificationCount(){
    const existing=document.querySelector('[data-notification-badge]');
    return Math.max(0,Number(existing?.textContent||0)||0);
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
