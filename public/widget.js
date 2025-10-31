(function(){
const toggle = document.getElementById('gc-toggle');
const panel = document.getElementById('gc-panel');
const body = document.getElementById('gc-body');
const input = document.getElementById('gc-input');
const send = document.getElementById('gc-send');


let intents = [];
let open = false;


function el(tag, cls='', txt=''){ const d = document.createElement(tag); if(cls) d.className=cls; if(txt) d.textContent=txt; return d }
function addMessage(text, who='bot'){
const m = el('div', 'msg ' + who, text);
body.appendChild(m); body.scrollTop = body.scrollHeight;
}


async function loadIntents(){
try{
const res = await fetch('../backend/chatbot.php?action=intents');
const j = await res.json(); if(j.ok) intents = j.intents || [];
}catch(e){ console.warn('Could not load intents',e); }
}


toggle.addEventListener('click', ()=>{
open = !open; panel.classList.toggle('gc-hidden', !open);
if(open){ input.focus(); }
});


async function sendMessage(){
const text = input.value.trim(); if(!text) return; input.value=''; addMessage(text,'user');


// local simple matching hint
const lower = text.toLowerCase();
for(const it of intents){
for(const s of (it.samples||[])){
//if(s && lower.includes(s.toLowerCase())){ addMessage('(Detected: '+it.name+')','bot'); break; }
if(s && lower.includes(s.toLowerCase())){ addMessage('mencari jawaban...','bot'); break; }
}
}


try{
const form = new FormData(); form.append('action','ask'); form.append('message', text);
const r = await fetch('../backend/chatbot.php', { method:'POST', body:form });
const j = await r.json();
if(j.ok) addMessage(j.reply || '(no reply)'); else addMessage('Error: '+(j.error||'unknown'));
}catch(err){ addMessage('Network error'); }
}


send.addEventListener('click', sendMessage);
input.addEventListener('keydown', e=>{ if(e.key==='Enter') sendMessage(); });


// init
loadIntents();
})();