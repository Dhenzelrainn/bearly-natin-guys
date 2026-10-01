const KEY='bearly-demo-profile-v1';
const defaults={username:'miasantos',fullName:'Mia Santos',email:'mia.santos@example.com',phone:'',gender:'',birthMonth:'',birthDay:'',birthYear:'',photo:''};
const $=s=>document.querySelector(s);
const months=['January','February','March','April','May','June','July','August','September','October','November','December'];

function fillSelects(){
 const m=$('#birth-month'),d=$('#birth-day'),y=$('#birth-year');
 months.forEach((x,i)=>m.insertAdjacentHTML('beforeend',`<option value="${i+1}">${x}</option>`));
 for(let i=1;i<=31;i++) d.insertAdjacentHTML('beforeend',`<option value="${i}">${i}</option>`);
 const now=new Date().getFullYear();
 for(let i=now;i>=1940;i--) y.insertAdjacentHTML('beforeend',`<option value="${i}">${i}</option>`);
}
function getProfile(){try{return {...defaults,...JSON.parse(localStorage.getItem(KEY)||'{}')}}catch{return {...defaults}}}
function avatar(photo){
 const html=photo?`<img src="${photo}" alt="Profile photo">`:`<span class="material-symbols-outlined">person</span>`;
 $('#profile-avatar').innerHTML=html; $('#sidebar-avatar').innerHTML=html;
}
function load(){
 const p=getProfile();
 $('#username').value=p.username; $('#full-name').value=p.fullName; $('#email').value=p.email; $('#phone').value=p.phone;
 $('#birth-month').value=p.birthMonth; $('#birth-day').value=p.birthDay; $('#birth-year').value=p.birthYear;
 document.querySelectorAll('[name=gender]').forEach(r=>r.checked=r.value===p.gender);
 $('#sidebar-name').textContent=p.fullName||'Mia Santos'; avatar(p.photo);
}
function save(){
 const old=getProfile(), gender=document.querySelector('[name=gender]:checked')?.value||'';
 const p={...old,username:$('#username').value.trim(),fullName:$('#full-name').value.trim(),email:$('#email').value.trim(),phone:$('#phone').value.trim(),gender,birthMonth:$('#birth-month').value,birthDay:$('#birth-day').value,birthYear:$('#birth-year').value};
 localStorage.setItem(KEY,JSON.stringify(p)); localStorage.setItem('bearly-demo-account',p.email||'mia.santos@example.com');
 $('#sidebar-name').textContent=p.fullName||'Mia Santos';
 const t=$('#profile-toast'); t.classList.add('show'); setTimeout(()=>t.classList.remove('show'),1700);
}
fillSelects(); load();
$('#profile-form').addEventListener('submit',e=>{e.preventDefault();save()});
$('#edit-profile').addEventListener('click',()=>$('#full-name').focus());
$('#select-photo').addEventListener('click',()=>$('#photo-input').click());
$('#photo-input').addEventListener('change',e=>{
 const f=e.target.files?.[0]; if(!f)return;
 if(f.size>1024*1024){alert('Please choose an image smaller than 1 MB.');e.target.value='';return}
 if(!['image/jpeg','image/png'].includes(f.type)){alert('JPEG and PNG images only.');e.target.value='';return}
 const r=new FileReader(); r.onload=()=>{const p=getProfile();p.photo=r.result;localStorage.setItem(KEY,JSON.stringify(p));avatar(p.photo)};r.readAsDataURL(f);
});
