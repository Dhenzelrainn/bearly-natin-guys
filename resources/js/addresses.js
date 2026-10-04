
function bindPhilippinePhone(input){
 if(!input)return;
 const normalize=()=>{
  let digits=input.value.replace(/\D/g,'');
  if(digits.startsWith('63')){
   digits=digits.slice(0,12);
   input.value='+63'+digits.slice(2);
  }else if(digits.startsWith('0')){
   input.value=digits.slice(0,11);
  }else{
   digits=digits.slice(0,10);
   input.value=digits?'+63'+digits:'';
  }
  const raw=input.value.replace(/\D/g,'');
  const valid=input.value.startsWith('+63') ? raw.length===12 : /^0\d{10}$/.test(input.value);
  input.setCustomValidity(input.value && !valid ? 'Enter 10 digits after +63, or an 11-digit number starting with 0.' : '');
 };
 input.addEventListener('input',normalize);
 input.addEventListener('blur',normalize);
 normalize();
}
function bindDigitsOnly(input,maxLength){
 if(!input)return;
 input.addEventListener('input',()=>{input.value=input.value.replace(/\D/g,'').slice(0,maxLength)});
}

const ADDRESS_KEY='bearly-addresses-v1';
const $=id=>document.getElementById(id);
let activeLabel='Home',pendingDelete=null;
const read=()=>{try{const a=JSON.parse(localStorage.getItem(ADDRESS_KEY)||'[]');return Array.isArray(a)?a:[]}catch{return[]}};
const write=a=>localStorage.setItem(ADDRESS_KEY,JSON.stringify(a));
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const uid=()=>String(Date.now())+Math.random().toString(16).slice(2);
function toast(m){let e=$('address-toast');e.textContent=m;e.classList.add('show');clearTimeout(window.__at);window.__at=setTimeout(()=>e.classList.remove('show'),1400)}
function fullAddress(a){return [a.street,a.barangay,a.city,a.province,a.postal].filter(Boolean).join(', ')}
function render(){let a=read();$('address-list').innerHTML=a.map(x=>`<article class="address-item" data-id="${esc(x.id)}"><div><div class="address-name-line"><strong>${esc(x.name)}</strong><span class="address-phone">${esc(x.phone)}</span></div><div class="address-text">${esc(fullAddress(x))}</div><div class="address-tags"><span class="address-tag">${esc(x.label||'Home')}</span>${x.isDefault?'<span class="address-tag default-badge">Default</span>':''}</div></div><div class="address-actions"><div class="address-actions-top"><button class="edit-btn" data-edit>Edit</button><button class="delete-btn" data-delete>Delete</button></div><button class="set-default-btn" data-default ${x.isDefault?'disabled':''}>${x.isDefault?'Default Address':'Set as Default'}</button></div></article>`).join('');$('address-empty').hidden=!!a.length}
function setLabel(v){activeLabel=v;document.querySelectorAll('.label-choice').forEach(b=>b.classList.toggle('active',b.dataset.label===v))}
function openForm(a=null){$('address-modal-title').textContent=a?'Edit Address':'New Address';$('editing-id').value=a?.id||'';$('full-name').value=a?.name||'';$('phone-number').value=a?.phone||'';$('province').value=a?.province||'';$('city').value=a?.city||'';$('barangay').value=a?.barangay||'';$('postal-code').value=a?.postal||'';$('street').value=a?.street||'';$('set-default').checked=!!a?.isDefault;setLabel(a?.label||'Home');$('address-modal').hidden=false;setTimeout(()=>$('full-name').focus(),30)}
function closeForm(){$('address-modal').hidden=true;$('address-form').reset();$('editing-id').value='';setLabel('Home')}
$('add-address').onclick=()=>openForm();$('empty-add-address').onclick=()=>openForm();$('close-address-modal').onclick=closeForm;$('cancel-address').onclick=closeForm;
document.querySelectorAll('.label-choice').forEach(b=>b.onclick=()=>setLabel(b.dataset.label));
$('address-form').onsubmit=e=>{e.preventDefault();let all=read(),id=$('editing-id').value,isNew=!id,makeDefault=$('set-default').checked||all.length===0;let data={id:id||uid(),name:$('full-name').value.trim(),phone:$('phone-number').value.trim(),province:$('province').value.trim(),city:$('city').value.trim(),barangay:$('barangay').value.trim(),postal:$('postal-code').value.trim(),street:$('street').value.trim(),label:activeLabel,isDefault:makeDefault};if(makeDefault)all=all.map(x=>({...x,isDefault:false}));if(isNew)all.push(data);else all=all.map(x=>x.id===id?data:x);write(all);closeForm();render();toast(isNew?'Address added':'Address updated')};
$('address-list').onclick=e=>{let row=e.target.closest('.address-item');if(!row)return;let id=row.dataset.id,all=read(),a=all.find(x=>x.id===id);if(e.target.closest('[data-edit]'))openForm(a);if(e.target.closest('[data-delete]')){pendingDelete=id;$('delete-modal').hidden=false}if(e.target.closest('[data-default]')){all=all.map(x=>({...x,isDefault:x.id===id}));write(all);render();toast('Default address updated')}};
$('cancel-delete').onclick=()=>{$('delete-modal').hidden=true;pendingDelete=null};
$('confirm-delete').onclick=()=>{let all=read(),wasDefault=all.find(x=>x.id===pendingDelete)?.isDefault;all=all.filter(x=>x.id!==pendingDelete);if(wasDefault&&all.length)all[0].isDefault=true;write(all);$('delete-modal').hidden=true;pendingDelete=null;render();toast('Address deleted')};
$('address-modal').onclick=e=>{if(e.target===$('address-modal'))closeForm()};
$('delete-modal').onclick=e=>{if(e.target===$('delete-modal')){$('delete-modal').hidden=true;pendingDelete=null}};
render();

bindPhilippinePhone($('phone-number'));
bindDigitsOnly($('postal-code'),4);


// Task 8: keep standalone Addresses navbar badges in sync with the buyer UI.
document.addEventListener('DOMContentLoaded', () => {
  const readArray = (key) => {
    try {
      const value = JSON.parse(localStorage.getItem(key) || '[]');
      return Array.isArray(value) ? value : [];
    } catch (_) { return []; }
  };
  const cartCount = readArray('bearlyCart').reduce((sum, item) => sum + Math.max(1, Number(item?.qty || item?.quantity || 1)), 0);
  const cartLink = [...document.querySelectorAll('.header-actions a')].find(a => a.textContent.trim().includes('Cart'));
  if (cartLink) {
    let badge = cartLink.querySelector('[data-cart-badge]');
    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'cart-badge';
      badge.dataset.cartBadge = '';
      cartLink.appendChild(badge);
    }
    badge.textContent = String(cartCount);
    badge.hidden = cartCount < 1;
  }
});
