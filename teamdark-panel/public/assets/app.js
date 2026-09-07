'use strict';
document.addEventListener('click',e=>{const b=e.target.closest('[data-copy]');if(!b)return;const v=b.getAttribute('data-copy')||'';navigator.clipboard?.writeText(v).then(()=>{const t=b.textContent;b.textContent='Copied';setTimeout(()=>b.textContent=t,900)}).catch(()=>{});});
