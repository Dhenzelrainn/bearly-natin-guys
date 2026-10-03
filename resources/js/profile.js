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
 const html=photo?`<img src="${photo}" alt="Profile photo">`:`<span class="material-symbols-outlined">person</span>`;
 if(profileAvatar) profileAvatar.innerHTML=html;
 if(sidebarAvatar) sidebarAvatar.innerHTML=html;
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
  if(selectPhoto) selectPhoto.addEventListener('click',()=>$('#photo-input')?.click());
  const photoInput=$('#photo-input');
  if(photoInput) photoInput.addEventListener('change',e=>{
   const f=e.target.files?.[0]; if(!f)return;
   if(f.size>1024*1024){alert('Please choose an image smaller than 1 MB.');e.target.value='';return}
   if(!['image/jpeg','image/png'].includes(f.type)){alert('JPEG and PNG images only.');e.target.value='';return}
   const r=new FileReader(); r.onload=()=>{const p=getProfile();p.photo=r.result;localStorage.setItem(KEY,JSON.stringify(p));avatar(p.photo)};r.readAsDataURL(f);
  });

  const accountTabs=document.querySelectorAll('[data-account-tab]');
  const accountPanels=document.querySelectorAll('[data-account-panel]');
  function showAccountPanel(name){
    accountTabs.forEach(b=>b.classList.toggle('active',b.dataset.accountTab===name));
    accountPanels.forEach(p=>p.hidden=p.dataset.accountPanel!==name);
    const hash=name==='profile'?'':`#${name}`;
    history.replaceState(null,'',`/profile${hash}`);
    if(name==='likes'&&typeof window.renderMyLikes==='function')window.renderMyLikes();
    if(name==='purchases'&&typeof window.renderMyPurchases==='function')window.renderMyPurchases();
    if(name==='reviews'&&typeof window.renderReviews==='function')window.renderReviews();
    if(name==='tracking'&&typeof window.renderOrderTracking==='function')window.renderOrderTracking();
    window.bearlyCurrentAccountPanel=name;
  }
  initNotifications(showAccountPanel);
  if(accountTabs.length){
    accountTabs.forEach(b=>b.addEventListener('click',()=>showAccountPanel(b.dataset.accountTab)));
  }
  if(location.hash==='#addresses')showAccountPanel('addresses');
  if(location.hash==='#likes')showAccountPanel('likes');
  if(location.hash==='#purchases')showAccountPanel('purchases');
  if(location.hash==='#reviews')showAccountPanel('reviews');
  if(location.hash==='#notifications')showAccountPanel('notifications');
  if(location.hash==='#tracking')showAccountPanel('tracking');
  window.bearlyShowAccountPanel=showAccountPanel;
}

if(document.readyState === 'loading'){
  document.addEventListener('DOMContentLoaded', initProfile);
} else {
  initProfile();
}
