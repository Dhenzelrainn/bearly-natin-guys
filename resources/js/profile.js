
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

const KEY='bearly-demo-profile-v1';
const defaults={username:'miasantos',fullName:'Mia Santos',email:'mia.santos@example.com',phone:'',gender:'',birthMonth:'',birthDay:'',birthYear:'',photo:''};
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
function getProfile(){try{return {...defaults,...JSON.parse(localStorage.getItem(KEY)||'{}')}}catch{return {...defaults}}}
function avatar(photo){
 const profileAvatar=$('#profile-avatar');
 const sidebarAvatar=$('#sidebar-avatar');
 const navbarAvatar=$('#navbar-avatar');
 const html=photo?`<img src="${photo}" alt="Profile photo">`:`<span class="material-symbols-outlined">person</span>`;
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
 if(sidebarName) sidebarName.textContent=p.fullName||'Mia Santos';
 avatar(p.photo);
}
function save(){
 const old=getProfile(), gender=document.querySelector('[name=gender]:checked')?.value||'';
 const username=$('#username');
 const fullName=$('#full-name');
 const email=$('#email');
 const phone=$('#phone');
 const birthMonth=$('#birth-month');
 const birthDay=$('#birth-day');
 const birthYear=$('#birth-year');
 const p={...old,username:username?username.value.trim():'',fullName:fullName?fullName.value.trim():'',email:email?email.value.trim():'',phone:phone?phone.value.trim():'',gender,birthMonth:birthMonth?birthMonth.value:'',birthDay:birthDay?birthDay.value:'',birthYear:birthYear?birthYear.value:''};
 localStorage.setItem(KEY,JSON.stringify(p)); localStorage.setItem('bearly-demo-account',p.email||'mia.santos@example.com');
 const sidebarName=$('#sidebar-name');
 if(sidebarName) sidebarName.textContent=p.fullName||'Mia Santos';
 const t=$('#profile-toast'); if(t){ t.classList.add('show'); setTimeout(()=>t.classList.remove('show'),1700); }
}

const VOUCHER_KEY='bearly-claimed-vouchers-v1';
const voucherCatalog=[
 {id:'BEARLY100',kind:'discount',value:100,title:'₱100 Off',description:'Get ₱100 off when you spend at least ₱799.',minSpend:799,expiry:'Oct 31, 2026',icon:'confirmation_number'},
 {id:'BEARLYSHIP',kind:'shipping',value:50,title:'Free Shipping',description:'Save up to ₱50 shipping on eligible Bearly orders.',minSpend:499,expiry:'Oct 31, 2026',icon:'local_shipping'},
 {id:'PAYDAY30',kind:'percent',value:30,title:'30% Off Payday',description:'Save 30% up to ₱150 on selected everyday finds.',minSpend:599,cap:150,expiry:'Oct 15, 2026',icon:'sell'},
 {id:'BEARLY50',kind:'discount',value:50,title:'₱50 Off',description:'₱50 off your next order with a ₱399 minimum spend.',minSpend:399,expiry:'Nov 15, 2026',icon:'redeem'}
];
function getClaimedVouchers(){try{const v=JSON.parse(localStorage.getItem(VOUCHER_KEY)||'[]');return Array.isArray(v)?v:[]}catch{return[]}}
function saveClaimedVouchers(v){localStorage.setItem(VOUCHER_KEY,JSON.stringify(v));window.dispatchEvent(new CustomEvent('bearly:vouchers-changed'));}
function initVouchers(){
 const list=$('#voucher-list'); if(!list)return;
 let filter='available';
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
 const updateCount=()=>{const count=getClaimedVouchers().length;const badge=$('#vouchers-nav-count');if(badge){badge.textContent=count;badge.hidden=count===0}const label=$('#voucher-claimed-count');if(label)label.textContent=`${count} claimed`;};
 const render=()=>{const claimed=getClaimedVouchers();const rows=voucherCatalog.filter(v=>filter==='claimed'?claimed.includes(v.id):!claimed.includes(v.id));list.innerHTML=rows.map(v=>`<article class="voucher-card"><div class="voucher-stub"><span class="material-symbols-outlined">${esc(v.icon)}</span><strong>${v.kind==='shipping'?'FREE':v.kind==='percent'?v.value+'%':'₱'+v.value}</strong><small>${v.kind==='shipping'?'SHIPPING':'OFF'}</small></div><div class="voucher-body"><h3>${esc(v.title)}</h3><p>${esc(v.description)}</p><span class="voucher-code">Code: ${esc(v.id)}</span><span class="voucher-expiry">Valid until ${esc(v.expiry)}</span><div class="voucher-actions">${claimed.includes(v.id)?'<a class="voucher-use" href="/checkout">Use Now</a>':`<button class="voucher-claim" type="button" data-claim-voucher="${esc(v.id)}">Claim</button>`}</div></div></article>`).join('');const empty=$('#vouchers-empty');if(empty)empty.hidden=rows.length!==0;updateCount();};
 list.addEventListener('click',e=>{const b=e.target.closest('[data-claim-voucher]');if(!b)return;const claimed=getClaimedVouchers();if(!claimed.includes(b.dataset.claimVoucher)){claimed.push(b.dataset.claimVoucher);saveClaimedVouchers(claimed)}render();});
 document.querySelectorAll('[data-voucher-filter]').forEach(btn=>btn.addEventListener('click',()=>{filter=btn.dataset.voucherFilter||'available';document.querySelectorAll('[data-voucher-filter]').forEach(x=>x.classList.toggle('active',x===btn));render();}));
 window.renderVouchers=render;render();
}

const NOTIFICATION_KEY='bearly-notifications-v1';
const notificationDefaults=[
 {id:'notif-order-shipped',type:'orders',icon:'local_shipping',title:'Your order is on the way',message:'Order #BRY-102841 has been shipped. Open My Purchases to follow its delivery status.',time:'Today, 10:24 AM',read:false,target:'purchases'},
 {id:'notif-payment',type:'payment',icon:'payments',title:'Payment confirmed',message:'Your payment for order #BRY-102841 was confirmed successfully.',time:'Today, 9:48 AM',read:false,target:'purchases'},
 {id:'notif-delivery',type:'orders',icon:'inventory_2',title:'Package delivered',message:'Order #BRY-101972 was delivered. You can now rate your product from Completed purchases.',time:'Yesterday, 4:16 PM',read:false,target:'purchases'},
 {id:'notif-voucher',type:'promos',icon:'confirmation_number',title:'A Bearly voucher is waiting',message:'Check your vouchers before your next checkout.',time:'Sep 30, 2026',read:true,target:''}
];
function getNotifications(){
 try{const saved=JSON.parse(localStorage.getItem(NOTIFICATION_KEY)||'null');return Array.isArray(saved)?saved:notificationDefaults.map(x=>({...x}))}catch{return notificationDefaults.map(x=>({...x}))}
}
function saveNotifications(items){localStorage.setItem(NOTIFICATION_KEY,JSON.stringify(items));}
function updateNotificationBadges(items=getNotifications()){
 const count=items.filter(x=>!x.read).length;
 document.querySelectorAll('[data-notification-badge]').forEach(el=>{el.textContent=count;el.hidden=count===0});
 const nav=$('#notifications-nav-count'); if(nav){nav.textContent=count;nav.hidden=count===0;}
}
function initNotifications(showAccountPanel){
 const list=$('#notification-list'); if(!list){updateNotificationBadges();return;}
 let filter='all';
 const escapeHtml=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
 const render=()=>{
   const items=getNotifications(); updateNotificationBadges(items);
   const visible=filter==='all'?items:items.filter(x=>x.type===filter);
   list.innerHTML=visible.map(n=>`<article class="notification-item ${n.read?'':'unread'}" data-notification-id="${escapeHtml(n.id)}" tabindex="0" role="button"><div class="notification-icon"><span class="material-symbols-outlined">${escapeHtml(n.icon)}</span></div><div class="notification-copy"><strong>${escapeHtml(n.title)}</strong><p>${escapeHtml(n.message)}</p><time>${escapeHtml(n.time)}</time></div><span class="notification-dot" aria-label="Unread"></span></article>`).join('');
   const empty=$('#notifications-empty'); if(empty) empty.hidden=visible.length!==0;
 };
 const openNotification=id=>{
   const items=getNotifications(); const item=items.find(x=>x.id===id); if(!item)return;
   item.read=true; saveNotifications(items); render();
   if(item.target==='purchases' && typeof showAccountPanel==='function') showAccountPanel('purchases');
 };
 list.addEventListener('click',e=>{const row=e.target.closest('[data-notification-id]');if(row)openNotification(row.dataset.notificationId)});
 list.addEventListener('keydown',e=>{if((e.key==='Enter'||e.key===' ')&&e.target.matches('[data-notification-id]')){e.preventDefault();openNotification(e.target.dataset.notificationId)}});
 document.querySelectorAll('[data-notification-filter]').forEach(btn=>btn.addEventListener('click',()=>{filter=btn.dataset.notificationFilter||'all';document.querySelectorAll('[data-notification-filter]').forEach(x=>x.classList.toggle('active',x===btn));render()}));
 $('#mark-all-notifications')?.addEventListener('click',()=>{const items=getNotifications().map(x=>({...x,read:true}));saveNotifications(items);render()});
 render();
}


const helpTopics=[
 {category:'orders',icon:'receipt_long',question:'Where can I see my orders?',answer:'Open My Purchases to view orders by status. Order History keeps your completed and cancelled orders together.',action:'purchases',actionLabel:'Open My Purchases'},
 {category:'orders',icon:'cancel',question:'Can I cancel an order?',answer:'Cancellation options depend on the order status. For this frontend demo, cancelled orders appear in Order History after their status is updated.'},
 {category:'shipping',icon:'local_shipping',question:'How do I track my order?',answer:'Go to My Purchases, open the To Receive tab, then choose Track Order to see the delivery timeline.',action:'purchases',actionLabel:'Track from My Purchases'},
 {category:'shipping',icon:'location_on',question:'How do I change my delivery address?',answer:'Open Addresses in My Account to add, edit, delete, or set your default delivery address.',action:'addresses',actionLabel:'Manage Addresses'},
 {category:'payments',icon:'payments',question:'What payment methods are available?',answer:'Bearly currently accepts Cash on Delivery (COD) for buyer orders. You pay with cash when your order is delivered.'},
 {category:'returns',icon:'assignment_return',question:'How do returns and refunds work?',answer:'Returns and refunds are not connected to a backend yet. This Help Center keeps the buyer flow ready for that feature without creating a fake transaction.'},
 {category:'account',icon:'person',question:'How do I update my profile?',answer:'Open Profile, edit your buyer information, then save your changes. Demo profile details are stored locally in your browser.',action:'profile',actionLabel:'Open Profile'},
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
  if(profileForm) profileForm.addEventListener('submit',e=>{e.preventDefault();save();});
  const editProfile=$('#edit-profile');
  if(editProfile) editProfile.addEventListener('click',()=>$('#full-name')?.focus());
  const selectPhoto=$('#select-photo');
  const profileAvatarButton=$('#profile-avatar');
  if(selectPhoto) selectPhoto.addEventListener('click',()=>$('#photo-input')?.click());
  if(profileAvatarButton) profileAvatarButton.addEventListener('click',()=>$('#photo-input')?.click());
  const photoInput=$('#photo-input');
  if(photoInput) photoInput.addEventListener('change',e=>{
   const f=e.target.files?.[0]; if(!f)return;
   if(f.size>5*1024*1024){alert('Please choose an image smaller than 5 MB.');e.target.value='';return}
   if(!['image/jpeg','image/png','image/webp'].includes(f.type)){alert('JPG, PNG, and WEBP images only.');e.target.value='';return}
   const r=new FileReader(); r.onload=()=>{const p=getProfile();p.photo=r.result;localStorage.setItem(KEY,JSON.stringify(p));avatar(p.photo)};r.readAsDataURL(f);
  });
  const removePhoto=$('#remove-photo');
  if(removePhoto) removePhoto.addEventListener('click',()=>{
    const p=getProfile();
    p.photo='';
    localStorage.setItem(KEY,JSON.stringify(p));
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
    if(name==='history'&&typeof window.renderOrderHistory==='function')window.renderOrderHistory();
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
  if(location.hash==='#history')showAccountPanel('history');
  if(location.hash==='#reviews')showAccountPanel('reviews');
  if(location.hash==='#notifications')showAccountPanel('notifications');
  if(location.hash==='#tracking')showAccountPanel('tracking');
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

document.addEventListener('DOMContentLoaded',()=>{bindPhilippinePhone(document.getElementById('phone'));bindPhilippinePhone(document.getElementById('address-phone-number'));});
