(() => {
const KEY='bearly-chat-v1';
const buyerFirstName=String(window.bearlyBuyerProfile?.fullName||'there').trim().split(/\s+/)[0]||'there';
const conversations=[
  {id:'bearly',name:'Bearly Assistant',subtitle:'Shopping Assistant',avatar:'🧸',assistant:true,preview:`Hi ${buyerFirstName}! How can I help today?`},
 {id:'greenline',name:'Greenline Home',subtitle:'Usually replies within an hour',avatar:'GH',preview:'Your desk lamp is on the way.'},
 {id:'sundays',name:'Sundays Market',subtitle:'Seller',avatar:'SM',preview:'Thanks for your order!'}
];
const defaults={active:'bearly',messages:{bearly:[{from:'them',text:`Hi ${buyerFirstName}! I’m the Bearly Assistant. I can help with orders, shipping, payments, vouchers, returns, and your account.`,time:'Now'}],greenline:[{from:'them',text:'Hi! Your desk lamp has been handed to the courier.',time:'2m'},{from:'me',text:'Great, thank you for the update!',time:'2m'}],sundays:[{from:'them',text:'Thanks for your order! Let us know if you need anything.',time:'Yesterday'}]}};
const quick=[['Track my order','track'],['My vouchers','vouchers'],['Shipping','shipping'],['Payments','payments'],['Returns & refunds','returns'],['Account help','account']];
function load(){try{const saved=JSON.parse(localStorage.getItem(KEY)||'null');return saved&&saved.messages?{...defaults,...saved,messages:{...defaults.messages,...saved.messages}}:structuredClone(defaults)}catch{return structuredClone(defaults)}}
let state=load();
function save(){localStorage.setItem(KEY,JSON.stringify(state))}
function answer(text){const q=text.toLowerCase();if(/track|where.*order|order status/.test(q))return ['You can check your latest order status in My Purchases, then open Order Tracking.','/profile#purchases','View My Purchases'];if(/voucher|discount|coupon/.test(q))return ['Your claimed and available vouchers are in My Vouchers. You can also apply eligible vouchers during checkout.','/profile#vouchers','Open My Vouchers'];if(/ship|delivery|courier/.test(q))return ['Shipping status appears in Order Tracking. Delivery timing depends on the seller and courier status shown on your order.','/profile#purchases','Track an Order'];if(/payment|pay|gcash|maya|card|cod/.test(q))return ['Bearly currently uses Cash on Delivery (COD) for buyer orders. Pay with cash when your order is delivered.','/checkout','Go to Checkout'];if(/return|refund|cancel/.test(q))return ['For returns, refunds, or cancellation questions, check the Help Center first and review the status of the related purchase.','/profile#help','Open Help Center'];if(/address|account|profile/.test(q))return ['You can update your profile and saved delivery addresses from Buyer Account.','/profile#addresses','Manage Addresses'];if(/help|support/.test(q))return ['I can help with orders, shipping, payments, vouchers, returns, and account settings. Pick a quick option below or type your question.','/profile#help','Open Help Center'];return ['I can help with Bearly shopping questions. Try asking about your order, shipping, payment, vouchers, returns, or account.',null,null]}
function renderRoot(root){const list=root.querySelector('[data-chat-list]'),head=root.querySelector('[data-chat-thread-head]'),msgs=root.querySelector('[data-chat-messages]'),quickEl=root.querySelector('[data-chat-quick]');if(!list||!head||!msgs)return;const search=(root.querySelector('[data-chat-search]')?.value||'').toLowerCase();list.innerHTML=conversations.filter(c=>c.assistant||!search||c.name.toLowerCase().includes(search)).map(c=>`<button type="button" class="bearly-chat-item ${c.assistant?'is-assistant':''} ${state.active===c.id?'is-active':''}" data-chat-id="${c.id}"><span class="bearly-chat-avatar">${c.avatar}</span><span class="bearly-chat-item-copy">${c.assistant?'<span class="bearly-chat-official">Pinned · Official</span>':''}<strong>${c.name}</strong><small>${c.preview}</small></span></button>`).join('');const c=conversations.find(x=>x.id===state.active)||conversations[0];head.innerHTML=`<span class="bearly-chat-avatar">${c.avatar}</span><div><strong>${c.name}</strong><small>${c.subtitle}${c.assistant?' · Always pinned':''}</small></div>`;msgs.innerHTML=(state.messages[c.id]||[]).map(m=>`<div class="bearly-message ${m.from}">${escapeHtml(m.text)}${m.action?`<div style="margin-top:8px"><a class="bearly-chat-action" href="${m.action.url}">${m.action.label}</a></div>`:''}<span class="bearly-message-time">${m.time||''}</span></div>`).join('');msgs.scrollTop=msgs.scrollHeight;quickEl.innerHTML=c.assistant?quick.map(([label,key])=>`<button type="button" data-chat-quick="${key}">${label}</button>`).join(''):''}
function renderAll(){document.querySelectorAll('[data-bearly-chat-root]').forEach(renderRoot)}
function escapeHtml(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML}
function send(text){const value=text.trim();if(!value)return;const id=state.active;state.messages[id]=state.messages[id]||[];state.messages[id].push({from:'me',text:value,time:'Now'});if(id==='bearly'){const [reply,url,label]=answer(value);state.messages[id].push({from:'them',text:reply,time:'Now',action:url?{url,label}:null})}else{state.messages[id].push({from:'them',text:'Thanks for your message! This seller conversation is a frontend demo for now.',time:'Now'})}save();renderAll()}
function openDrawer(forceBearly=false){const drawer=document.querySelector('[data-bearly-chat-drawer]'),backdrop=document.querySelector('.bearly-chat-backdrop');if(!drawer)return;if(forceBearly)state.active='bearly';save();drawer.classList.add('is-open');drawer.setAttribute('aria-hidden','false');if(backdrop)backdrop.hidden=false;renderAll()}
function closeDrawer(){const drawer=document.querySelector('[data-bearly-chat-drawer]'),backdrop=document.querySelector('.bearly-chat-backdrop');if(!drawer)return;drawer.classList.remove('is-open');drawer.setAttribute('aria-hidden','true');if(backdrop)backdrop.hidden=true}
document.addEventListener('click',e=>{const launch=e.target.closest('[data-bearly-chat-launcher]');if(launch){e.preventDefault();openDrawer(launch.hasAttribute('data-open-bearly'));return}if(e.target.closest('[data-bearly-chat-close]')){closeDrawer();return}const item=e.target.closest('[data-chat-id]');if(item){state.active=item.dataset.chatId;save();renderAll();return}const q=e.target.closest('[data-chat-quick]');if(q){const labels={track:'Where is my order?',vouchers:'Show me my vouchers',shipping:'How does shipping work?',payments:'What payment methods are available?',returns:'How do returns and refunds work?',account:'Help me with my account'};send(labels[q.dataset.chatQuick]||q.textContent)}});
document.addEventListener('submit',e=>{const form=e.target.closest('[data-chat-form]');if(!form)return;e.preventDefault();const input=form.querySelector('[data-chat-input]');send(input.value);input.value='';input.focus()});
document.addEventListener('input',e=>{if(e.target.matches('[data-chat-search]'))renderRoot(e.target.closest('[data-bearly-chat-root]'))});
const params=new URLSearchParams(location.search);if(params.get('conversation')==='bearly'){state.active='bearly';save()}renderAll();window.BearlyChat={open:openDrawer,openAssistant:()=>openDrawer(true)};
})();

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
