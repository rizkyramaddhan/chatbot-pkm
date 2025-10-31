(function(){
const f=document.createElement('iframe');
f.src='widget.html';
f.style='position:fixed;bottom:0;right:0;width:420px;height:600px;max-width:100vw;border:0;z-index:99999;border-radius:12px;box-shadow:0 0 20px rgba(0,0,0,.25)';
f.setAttribute('allow','clipboard-read; clipboard-write');
const btn=document.createElement('button');
btn.innerHTML='💬';
btn.style='position:fixed;bottom:20px;right:20px;width:60px;height:60px;border-radius:50%;background:#0b84ff;color:#fff;font-size:26px;border:0;cursor:pointer;z-index:999999;box-shadow:0 6px 18px rgba(11,132,255,.24)';
let open=false;
btn.onclick=()=>{ open=!open; if(open) document.body.appendChild(f); else f.remove(); };
document.body.appendChild(btn);
})();