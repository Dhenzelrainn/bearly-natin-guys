const RIDER_STATE_KEY = 'bearlyRiderStateV1';
const readState = () => { try { return JSON.parse(localStorage.getItem(RIDER_STATE_KEY)) || {}; } catch { return {}; } };
const writeState = (state) => localStorage.setItem(RIDER_STATE_KEY, JSON.stringify(state));
const saveValue = (section, key, value) => { const state = readState(); state[section] ||= {}; state[section][key] = value; writeState(state); };
const normalize = (value) => String(value || '').trim().toLowerCase().replaceAll('_',' ');
const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'})[character]);
const refreshIcons = () => window.lucide?.createIcons();

function toast(message) {
    const stack = document.querySelector('[data-toast-stack]'); if (!stack) return;
    const item = document.createElement('div'); item.className = 'toast'; item.innerHTML = `<i data-lucide="circle-check"></i><span>${escapeHtml(message)}</span>`; stack.append(item); refreshIcons(); setTimeout(() => item.remove(), 3200);
}

function setupShell() {
    const body = document.body;
    if (localStorage.getItem('bearlyRiderSidebar') === 'collapsed') body.classList.add('sidebar-collapsed');
    document.querySelector('[data-sidebar-collapse]')?.addEventListener('click', () => { body.classList.toggle('sidebar-collapsed'); localStorage.setItem('bearlyRiderSidebar', body.classList.contains('sidebar-collapsed') ? 'collapsed' : 'open'); });
    document.querySelector('[data-mobile-menu]')?.addEventListener('click', () => body.classList.add('sidebar-open'));
    document.querySelector('[data-overlay]')?.addEventListener('click', () => body.classList.remove('sidebar-open'));
    document.querySelectorAll('[data-dismiss]').forEach((button) => button.addEventListener('click', () => button.closest('.flash-banner')?.remove()));
    document.querySelectorAll('[data-popover-toggle]').forEach((button) => button.addEventListener('click', (event) => { event.stopPropagation(); const name = button.dataset.popoverToggle; document.querySelectorAll('[data-popover]').forEach((popover) => { popover.hidden = popover.dataset.popover !== name || !popover.hidden; }); }));
    document.addEventListener('click', (event) => { if (!event.target.closest('.popover-wrap')) document.querySelectorAll('[data-popover]').forEach((popover) => { popover.hidden = true; }); });
    document.querySelector('[data-mark-notifications]')?.addEventListener('click', () => { document.querySelectorAll('.notification-row').forEach((row) => row.remove()); document.querySelector('.notification-dot')?.remove(); toast('Notifications marked as read.'); });
}

function setupModals() {
    const backdrop = document.querySelector('[data-modal-backdrop]');
    const close = () => { document.querySelectorAll('.modal').forEach((modal) => { modal.hidden = true; }); if (backdrop) backdrop.hidden = true; document.body.style.overflow = ''; };
    const open = (name) => { const modal = document.querySelector(`[data-modal="${name}"]`); if (!modal) return; modal.hidden = false; if (backdrop) backdrop.hidden = false; document.body.style.overflow = 'hidden'; modal.querySelector('input,select,textarea')?.focus(); };
    document.querySelectorAll('[data-modal-open]').forEach((button) => button.addEventListener('click', () => open(button.dataset.modalOpen)));
    document.querySelectorAll('[data-modal-close]').forEach((button) => button.addEventListener('click', close)); backdrop?.addEventListener('click', close); document.addEventListener('keydown', (event) => { if (event.key === 'Escape') close(); });
    window.openRiderModal = open; window.closeRiderModals = close;
}

function setupTabs() {
    document.querySelectorAll('[data-tabs]').forEach((tabs) => tabs.querySelectorAll('[data-tab]').forEach((button) => button.addEventListener('click', () => { tabs.querySelectorAll('[data-tab]').forEach((item) => item.classList.toggle('is-active', item === button)); const scope = tabs.closest('[data-tab-scope]') || document; scope.querySelectorAll('[data-tab-panel]').forEach((panel) => { panel.hidden = panel.dataset.tabPanel !== button.dataset.tab; }); })));
}

function setupTables() {
    document.querySelectorAll('[data-table-scope]').forEach((scope) => {
        const rows = [...scope.querySelectorAll('[data-row]')]; const empty = scope.querySelector('[data-empty-row], [data-empty-cards]');
        const apply = () => { const query = normalize(scope.querySelector('[data-table-search]')?.value); const status = normalize(scope.querySelector('[data-filter-status]')?.value); let shown = 0; rows.forEach((row) => { const match = (!query || normalize(row.textContent).includes(query)) && (!status || normalize(row.dataset.status) === status); row.classList.toggle('is-filtered-out', !match); if (match) shown++; }); if (empty) empty.hidden = shown > 0; };
        scope.querySelectorAll('[data-table-search],[data-filter-status]').forEach((field) => field.addEventListener(field.tagName === 'INPUT' ? 'input' : 'change', apply));
        scope.querySelectorAll('th[data-sort]').forEach((header) => header.addEventListener('click', () => { const tbody = header.closest('table')?.tBodies[0]; if (!tbody) return; const index = [...header.parentElement.children].indexOf(header); const direction = header.dataset.direction === 'asc' ? 'desc' : 'asc'; header.dataset.direction = direction; rows.filter((row) => row.tagName === 'TR').sort((a,b) => a.children[index].innerText.localeCompare(b.children[index].innerText,undefined,{numeric:true}) * (direction === 'asc' ? 1 : -1)).forEach((row) => tbody.append(row)); }));
    });
}

function setupPickupJobs() {
    const state = readState();
    document.querySelectorAll('[data-record-type="pickupJobs"]').forEach((card) => { const saved = state.pickupJobs?.[card.dataset.recordId]; if (!saved) return; card.dataset.status = saved; const badge = card.querySelector('[data-status-badge]'); if (badge) { badge.dataset.status = saved; badge.textContent = saved; } const button = card.querySelector('[data-accept-pickup]'); if (button && saved === 'Accepted') { button.textContent = 'Accepted'; button.disabled = true; } });
    document.querySelectorAll('[data-accept-pickup]').forEach((button) => button.addEventListener('click', () => { const card = button.closest('[data-record-row]'); card.dataset.status = 'Accepted'; const badge = card.querySelector('[data-status-badge]'); badge.dataset.status = 'Accepted'; badge.textContent = 'Accepted'; button.textContent = 'Accepted'; button.disabled = true; saveValue('pickupJobs', card.dataset.recordId, 'Accepted'); toast(`${card.dataset.recordId} accepted and added to your pickup route.`); }));
    document.querySelectorAll('[data-preview-detail]').forEach((button) => button.addEventListener('click', () => toast('Pickup details are visible in the job card.')));
    document.querySelector('[data-refresh-preview]')?.addEventListener('click', () => toast('Pickup queue refreshed with current preview data.'));
    document.querySelector('[data-optimize-route]')?.addEventListener('click', () => toast('Delivery stops reordered by distance and payment priority.'));
}

function setupWorkflow() {
    document.querySelectorAll('[data-check-all]').forEach((button) => button.addEventListener('click', () => { document.querySelectorAll(`[data-check-group="${button.dataset.checkAll}"] input[type="checkbox"]`).forEach((checkbox) => { checkbox.checked = true; }); document.querySelector('[data-workflow-step="manifest"]')?.classList.replace('is-current','is-complete'); document.querySelector('[data-workflow-step="confirm"]')?.classList.add('is-current'); toast('Every manifest item is checked.'); }));
    document.querySelector('[data-confirm-pickup]')?.addEventListener('click', () => { const checks = [...document.querySelectorAll('[data-check-group] input[type="checkbox"]')]; if (checks.some((item) => !item.checked)) { toast('Check every manifest item before confirming pickup.'); return; } window.openRiderModal?.('confirm-pickup'); });
    document.querySelector('[data-confirm-delivery]')?.addEventListener('click', () => { const checks = [...document.querySelectorAll('[data-delivery-check]')]; if (checks.some((item) => !item.checked)) { toast('Verify the package and payment before adding proof of delivery.'); return; } window.openRiderModal?.('proof-delivery'); });
    document.querySelectorAll('[data-call-contact]').forEach((button) => button.addEventListener('click', () => toast(`Call preview opened for ${button.dataset.callContact}.`)));
    document.querySelectorAll('[data-navigation-preview]').forEach((button) => button.addEventListener('click', () => toast('Route navigation preview opened.')));
    document.querySelectorAll('[data-job-complete]').forEach((form) => form.addEventListener('submit', () => saveValue('completedJobs', form.dataset.jobComplete, new Date().toISOString())));
    const proof = document.querySelector('[data-proof-file]'); proof?.addEventListener('change', () => { const name = document.querySelector('[data-proof-name]'); if (name) name.textContent = proof.files?.[0]?.name || 'JPG or PNG preview file'; });
}

function downloadCsv(filename, rows) { const csv = rows.map((row) => row.map((value) => `"${String(value).replaceAll('"','""')}"`).join(',')).join('\n'); const link = document.createElement('a'); link.href = URL.createObjectURL(new Blob([csv],{type:'text/csv'})); link.download = filename; link.click(); URL.revokeObjectURL(link.href); }
function setupReports() {
    document.querySelector('[data-apply-earnings]')?.addEventListener('click', () => toast('Earnings preview refreshed for the selected date range.'));
    document.querySelector('[data-export-earnings]')?.addEventListener('click', () => { const rows = [['Date','Job','Type','Amount','Status']]; document.querySelectorAll('[data-earnings-row]').forEach((row) => rows.push([...row.children].map((cell) => cell.innerText.trim()))); downloadCsv('bearly-rider-earnings.csv', rows); toast('Earnings CSV exported.'); });
    document.querySelector('[data-export-history]')?.addEventListener('click', () => { const rows = [['Date','Delivery','Customer','Area','Outcome','Earning']]; document.querySelectorAll('tbody tr[data-row]').forEach((row) => rows.push([...row.children].slice(0,6).map((cell) => cell.innerText.trim()))); downloadCsv('bearly-rider-history.csv', rows); toast('Delivery history CSV exported.'); });
    document.querySelectorAll('[data-history-detail]').forEach((button) => button.addEventListener('click', () => { const title = document.querySelector('[data-history-title]'); if (title) title.textContent = `${button.dataset.historyDetail} timeline`; window.openRiderModal?.('history-detail'); }));
}

function setupChat() {
    const conversations = [...document.querySelectorAll('[data-conversation]')]; const messages = document.querySelector('[data-chat-messages]'); const input = document.querySelector('[data-chat-input]'); if (!conversations.length || !messages) return;
    let active = conversations[0].dataset.conversation; const base = {'sorting-center':[{mine:false,text:'Your next dispatch is ready at Intake Bay 2.',time:'2:10 PM'},{mine:true,text:'Received. I will return after the current pickup.',time:'2:12 PM'}],'karen-yu':[{mine:false,text:'Please call me when you are near the subdivision gate.',time:'1:55 PM'}],'techvault-ph':[{mine:false,text:'The six pickup parcels are ready at our operations counter.',time:'1:31 PM'}]};
    const render = () => { const button = conversations.find((item) => item.dataset.conversation === active); document.querySelector('[data-chat-name]').textContent = button?.dataset.name || 'Conversation'; document.querySelector('[data-chat-role]').textContent = button?.dataset.role || ''; const saved = readState().messages?.[active] || []; messages.innerHTML = [...(base[active] || []),...saved].map((item) => `<div class="message-bubble ${item.mine?'is-mine':''}">${escapeHtml(item.text)}<small>${escapeHtml(item.time)}</small></div>`).join(''); messages.scrollTop = messages.scrollHeight; };
    conversations.forEach((button) => button.addEventListener('click', () => { active = button.dataset.conversation; conversations.forEach((item) => item.classList.toggle('is-active',item===button)); render(); }));
    document.querySelector('[data-chat-search]')?.addEventListener('input', (event) => conversations.forEach((button) => button.hidden = !normalize(button.textContent).includes(normalize(event.target.value))));
    const send = () => { const text = input.value.trim(); if (!text) return; const state = readState(); state.messages ||= {}; state.messages[active] ||= []; state.messages[active].push({mine:true,text,time:new Date().toLocaleTimeString([],{hour:'numeric',minute:'2-digit'})}); writeState(state); input.value=''; render(); };
    document.querySelector('[data-chat-send]')?.addEventListener('click',send); input?.addEventListener('keydown',(event)=>{if(event.key==='Enter'){event.preventDefault();send();}}); render();
}

function setupForms() {
    document.querySelectorAll('[data-preview-form]').forEach((form) => form.addEventListener('submit',(event)=>{event.preventDefault();if(!form.reportValidity())return;saveValue('forms',form.dataset.previewForm,Object.fromEntries(new FormData(form)));window.closeRiderModals?.();toast('Changes saved in this browser preview.');}));
    document.querySelectorAll('[data-address-edit]').forEach((button)=>button.addEventListener('click',()=>toast('Address editor opened in preview mode.')));
    document.querySelector('[data-password-form]')?.addEventListener('submit',(event)=>{event.preventDefault();const form=event.currentTarget,next=form.querySelector('[name="new_password"]').value,confirm=form.querySelector('[name="new_password_confirmation"]').value;if(next.length<8||next!==confirm){toast('Passwords must match and contain at least 8 characters.');return;}form.reset();toast('Password update validated for this preview.');});
}

function setupRegistration() {
    const form=document.querySelector('[data-registration-form]');if(!form)return;let step=1;
    const show=(next)=>{step=next;document.querySelectorAll('[data-form-step]').forEach((panel)=>panel.hidden=Number(panel.dataset.formStep)!==step);document.querySelectorAll('[data-step-marker]').forEach((marker)=>marker.classList.toggle('is-active',Number(marker.dataset.stepMarker)===step));};
    const valid=()=>[...form.querySelector(`[data-form-step="${step}"]`).querySelectorAll('input,select')].every((field)=>field.reportValidity());
    document.querySelectorAll('[data-step-next]').forEach((button)=>button.addEventListener('click',()=>{if(valid())show(Math.min(3,step+1));}));document.querySelectorAll('[data-step-back]').forEach((button)=>button.addEventListener('click',()=>show(Math.max(1,step-1))));
    const birthday=form.querySelector('[name="birthday"]');birthday?.addEventListener('change',()=>{const birth=new Date(birthday.value),now=new Date();let age=now.getFullYear()-birth.getFullYear();if(now<new Date(now.getFullYear(),birth.getMonth(),birth.getDate()))age--;const field=form.querySelector('[data-age]');if(field)field.value=Number.isFinite(age)?age:'';});
    form.querySelectorAll('input[type="file"]').forEach((input)=>input.addEventListener('change',()=>{const label=input.closest('.upload-field')?.querySelector('[data-file-name]');if(label)label.textContent=input.files?.[0]?.name||'Choose a file';}));
    const province=form.querySelector('[data-province]'),city=form.querySelector('[data-city]'),barangay=form.querySelector('[data-barangay]');const fallback=(url)=>url.endsWith('/provinces')?[{name:'Laguna',code:'043400000'},{name:'Batangas',code:'041000000'},{name:'Cavite',code:'042100000'},{name:'Quezon',code:'045600000'}]:url.includes('/provinces/043400000/')?[{name:'San Pablo City',code:'043426000'},{name:'Santa Cruz',code:'043428000'},{name:'Calauan',code:'043405000'},{name:'Pila',code:'043419000'},{name:'Bay',code:'043403000'}]:url.includes('/provinces/')?[{name:'Provincial Capital',code:'000000001'},{name:'Service Municipality',code:'000000002'}]:[{name:'Poblacion',code:'000000101'},{name:'San Rafael',code:'000000102'},{name:'San Antonio',code:'000000103'},{name:'Del Remedio',code:'000000104'}];
    const fill=(select,items,placeholder)=>{select.innerHTML=`<option value="">${placeholder}</option>`+items.map((item)=>`<option value="${escapeHtml(item.name)}" data-code="${escapeHtml(item.code)}">${escapeHtml(item.name)}</option>`).join('');select.disabled=false;};
    const load=async(url,select,placeholder)=>{select.disabled=true;select.innerHTML='<option>Loading…</option>';try{const response=await fetch(url);if(!response.ok)throw new Error();const payload=await response.json(),items=Array.isArray(payload)?payload:payload.data;if(!Array.isArray(items))throw new Error();fill(select,items,placeholder);}catch{fill(select,fallback(url),`${placeholder} (preview list)`);toast('Using the offline preview location list.');}};
    if(province&&city&&barangay){load('/api/psgc/provinces',province,'Select province');province.addEventListener('change',()=>{const code=province.selectedOptions[0]?.dataset.code;if(code)load(`/api/psgc/provinces/${code}/cities`,city,'Select municipality / city');});city.addEventListener('change',()=>{const code=city.selectedOptions[0]?.dataset.code;if(code)load(`/api/psgc/cities/${code}/barangays`,barangay,'Select barangay');});}show(1);
}

document.addEventListener('DOMContentLoaded',()=>{setupShell();setupModals();setupTabs();setupTables();setupPickupJobs();setupWorkflow();setupReports();setupChat();setupForms();setupRegistration();refreshIcons();});
