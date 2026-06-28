# -*- coding: utf-8 -*-
"""
Construye una DEMO WEB de un solo archivo (demo/index.html) a partir de
app/src/main/assets/diccionario.json.

La demo replica la lógica de la app Android (búsqueda bidireccional, fichas,
"escuchar" con voz seleccionable y búsqueda por micrófono con coincidencia
fonética) para probar el contenido ANTES de compilar el APK. El diccionario se
incrusta en el HTML, así que funciona offline (con doble clic, sin servidor).

Nota: el reconocimiento por micrófono del navegador (Web Speech API) solo
funciona desde https:// o http://localhost (no desde file://) y en Chrome usa
Internet. La versión Android usa el reconocedor del propio móvil.
"""

import json

PLANTILLA = r"""<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diccionario Wolof — Demo</title>
<style>
  :root { --primary:#00695c; }
  * { box-sizing: border-box; }
  body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
         margin:0; background:#fff; color:#1b1b1b; }
  header { background:var(--primary); color:#fff; padding:14px 16px; font-size:20px;
           position:sticky; top:0; z-index:10; }
  header small { display:block; font-size:12px; opacity:.85; font-weight:normal; }
  .searchbar { display:flex; gap:8px; padding:12px 16px 4px; position:sticky; top:56px;
               background:#fff; z-index:9; }
  #q { flex:1; padding:10px 12px; font-size:16px; border:1px solid #ccc; border-radius:8px; }
  button.icon { border:1px solid var(--primary); background:#fff; color:var(--primary);
                border-radius:8px; padding:0 12px; font-size:18px; cursor:pointer; }
  button.icon:active { background:#e0f2f1; }
  button.mode { border:1px solid var(--primary); background:#fff; color:var(--primary);
                border-radius:16px; padding:4px 12px; cursor:pointer; font-size:13px; }
  button.mode.active { background:var(--primary); color:#fff; }
  .controls { display:flex; gap:10px; align-items:center; flex-wrap:wrap;
              padding:0 16px 10px; border-bottom:1px solid #eee; position:sticky;
              top:104px; background:#fff; z-index:9; font-size:13px; color:#555; }
  .controls select { padding:6px 8px; border:1px solid #ccc; border-radius:6px; max-width:60vw; }
  .banner { background:#f2f2f2; padding:8px 16px; font-size:13px; }
  ul { list-style:none; margin:0; padding:0; }
  li { display:flex; align-items:center; gap:8px; padding:12px 16px;
       border-bottom:1px solid #eee; cursor:pointer; }
  li:hover { background:#fafafa; }
  .es { font-size:16px; }
  .wo { font-size:14px; color:var(--primary); }
  .grow { flex:1; min-width:0; }
  .spk { border:none; background:transparent; font-size:20px; cursor:pointer; color:#555; }
  .empty { padding:24px 16px; color:#888; }
  #detail { position:fixed; inset:0; background:#fff; display:none; flex-direction:column; z-index:20; }
  #detail.open { display:flex; }
  #detail .bar { background:var(--primary); color:#fff; padding:14px 16px; display:flex;
                 align-items:center; gap:12px; }
  #detail .bar button { background:transparent; border:none; color:#fff; font-size:20px; cursor:pointer; }
  #detail .body { padding:24px; }
  #detail .field { margin-bottom:22px; }
  #detail .label { font-size:12px; color:#888; text-transform:uppercase; letter-spacing:.5px; }
  #detail .value { font-size:24px; margin-top:4px; }
  .hint { padding:10px 16px; font-size:12px; color:#888; }
</style>
</head>
<body>
<header>Diccionario Wolof <small>Demo web · español ⇄ wolof · offline</small></header>

<div class="searchbar">
  <input id="q" type="text" placeholder="Buscar en español o wolof…" autocomplete="off">
  <button class="icon" id="mic" title="Buscar por voz">🎤</button>
</div>
<div class="controls">
  <span>Idioma:</span>
  <button class="mode" id="modeEs">Español</button>
  <button class="mode" id="modeWo">Wolof</button>
  <label>🔊 Voz wolof: <select id="voiceSel"></select></label>
  <label>Velocidad: <input id="rate" type="range" min="0.5" max="1.1" step="0.05" value="0.78"></label>
</div>
<div id="voiceBanner" class="banner" style="display:none"></div>

<ul id="list"></ul>
<div id="empty" class="empty" style="display:none">No se han encontrado resultados.</div>
<div class="hint">Pulsa una entrada para ver su ficha. El altavoz 🔊 lee la pronunciación silabeada
  con la voz elegida (prueba voces en español/italiano para mayor fidelidad, o francesa para otro acento).
  El micrófono 🎤 es experimental y solo funciona si abres la página desde <b>http://localhost</b> o
  <b>https</b> (no desde un archivo local). En el móvil, esa función la hace la app Android.</div>

<div id="detail">
  <div class="bar"><button id="back">←</button><span>Ficha de la palabra</span>
    <span class="grow"></span><button id="dspk">🔊</button></div>
  <div class="body">
    <div class="field"><div class="label">Categoría</div><div class="value" id="dcat"></div></div>
    <div class="field"><div class="label">Español</div><div class="value" id="des"></div></div>
    <div class="field"><div class="label">Wolof</div><div class="value" id="dwo"></div></div>
    <div class="field"><div class="label">Pronunciación</div><div class="value" id="dpron"></div></div>
  </div>
</div>

<script>
const DATA = __DATA__;

// --- Normalización (mismas reglas que la app Android) ---------------------
function normalize(t){
  return (t||"").toLowerCase()
    .replace(/à/g,"a").replace(/é/g,"e").replace(/ë/g,"e").replace(/ó/g,"o")
    .replace(/ŋ/g,"ng")
    .normalize("NFD").replace(/[̀-ͯ]/g,"").trim();
}
const INDEX = DATA.map(e => normalize(e.es+" "+e.wo+" "+e.pron+" "+e.cat));

function search(q){
  const n = normalize(q);
  if(!n) return DATA.slice();
  return DATA.filter((_,i)=>INDEX[i].includes(n));
}

// --- Búsqueda fonética para el micrófono ----------------------------------
function lev(a,b){
  if(a===b) return 0; if(!a.length) return b.length; if(!b.length) return a.length;
  let prev=[...Array(b.length+1).keys()], cur=new Array(b.length+1);
  for(let i=1;i<=a.length;i++){ cur[0]=i;
    for(let j=1;j<=b.length;j++){
      const cost=a[i-1]===b[j-1]?0:1;
      cur[j]=Math.min(cur[j-1]+1, prev[j]+1, prev[j-1]+cost);
    }
    [prev,cur]=[cur,prev];
  }
  return prev[b.length];
}
function ratio(a,b){ const m=Math.max(a.length,b.length); return m? 1-lev(a,b)/m : 1; }
function sim(a,b){
  const g=ratio(a,b);
  const wa=a.split(" ").filter(Boolean), wb=b.split(" ").filter(Boolean);
  let pw=0;
  if(wa.length&&wb.length) pw=wa.map(x=>Math.max(...wb.map(y=>ratio(x,y)))).reduce((s,v)=>s+v,0)/wa.length;
  return Math.max(g,pw);
}
function phonetic(spoken){
  const t=normalize(spoken); if(!t) return [];
  const scored=[];
  DATA.forEach(e=>{
    const cands=[normalize(e.pron),normalize(e.wo)].filter(Boolean);
    if(!cands.length) return;
    const s=Math.max(...cands.map(c=>sim(t,c)));
    if(s>=0.34) scored.push([e,s]);
  });
  scored.sort((a,b)=>b[1]-a[1]);
  return scored.slice(0,15).map(x=>x[0]);
}

// --- Voz: selección + hablar (TTS) ----------------------------------------
let voices=[];
const voiceSel=document.getElementById("voiceSel");
const rateEl=document.getElementById("rate");

function populateVoices(){
  if(!window.speechSynthesis) return;
  voices = speechSynthesis.getVoices();
  voiceSel.innerHTML="";
  // Ordenar: francés primero, luego español, italiano y el resto.
  const rank = v => v.lang.startsWith("fr")?0 : v.lang.startsWith("es")?1 :
                    v.lang.startsWith("it")?2 : 3;
  voices.map((v,i)=>({v,i})).sort((a,b)=>rank(a.v)-rank(b.v))
    .forEach(({v,i})=>{
      const o=document.createElement("option");
      o.value=i; o.textContent=v.name+"  ("+v.lang+")";
      voiceSel.appendChild(o);
    });
  // Selección por defecto: voz francesa; si no hay, española.
  let def = voices.findIndex(v=>v.lang.startsWith("fr"));
  if(def<0) def = voices.findIndex(v=>v.lang.startsWith("es"));
  if(def>=0) voiceSel.value=def;
}
if(window.speechSynthesis){
  populateVoices();
  speechSynthesis.onvoiceschanged = populateVoices;
}

function speak(entry){
  if(!window.speechSynthesis) return;
  const u=new SpeechSynthesisUtterance();
  if(mode==="es"){
    // Modo Español: lee la palabra española con una voz española automática.
    u.text = entry.es;
    const sv = voices.find(v=>v.lang && v.lang.toLowerCase().startsWith("es"));
    if(sv){ u.voice=sv; u.lang=sv.lang; } else { u.lang="es-ES"; }
    u.rate = 0.95;
  } else {
    // Modo Wolof: lee la pronunciación con la voz wolof elegida (fr/es).
    const v = voices[parseInt(voiceSel.value,10)];
    const fr = v && v.lang && v.lang.toLowerCase().startsWith("fr");
    u.text = (fr ? entry.fon_fr : entry.fon_es) || entry.fon_es || entry.pron || entry.wo;
    if(v){ u.voice=v; u.lang=v.lang; } else { u.lang="es-ES"; }
    u.rate = parseFloat(rateEl.value)||0.78;
  }
  if(!u.text) return;
  speechSynthesis.cancel(); speechSynthesis.speak(u);
}

// --- Selector de idioma (Español / Wolof) ---------------------------------
let mode="wo";
const modeEs=document.getElementById("modeEs"), modeWo=document.getElementById("modeWo");
function setMode(m){
  mode=m;
  modeEs.classList.toggle("active", m==="es");
  modeWo.classList.toggle("active", m==="wo");
}
modeEs.onclick=()=>setMode("es");
modeWo.onclick=()=>setMode("wo");
setMode("wo");

// --- Render ---------------------------------------------------------------
const listEl=document.getElementById("list");
const emptyEl=document.getElementById("empty");
function render(items){
  listEl.innerHTML="";
  emptyEl.style.display = items.length? "none":"block";
  const frag=document.createDocumentFragment();
  items.forEach(e=>{
    const li=document.createElement("li");
    const g=document.createElement("div"); g.className="grow";
    g.innerHTML=`<div class="es"></div><div class="wo"></div>`;
    g.querySelector(".es").textContent=e.es;
    g.querySelector(".wo").textContent=e.wo || "(traducción no disponible)";
    li.appendChild(g);
    if(e.fon_es||e.pron||e.wo){
      const b=document.createElement("button"); b.className="spk"; b.textContent="🔊";
      b.title="Escuchar";
      b.onclick=ev=>{ev.stopPropagation(); speak(e);};
      li.appendChild(b);
    }
    li.onclick=()=>openDetail(e);
    frag.appendChild(li);
  });
  listEl.appendChild(frag);
}

// --- Ficha ----------------------------------------------------------------
const detail=document.getElementById("detail");
let currentDetail=null;
function openDetail(e){
  currentDetail=e;
  document.getElementById("dcat").textContent=e.cat||"—";
  document.getElementById("des").textContent=e.es;
  document.getElementById("dwo").textContent=e.wo||"(traducción no disponible)";
  document.getElementById("dpron").textContent=e.pron||"—";
  detail.classList.add("open");
}
document.getElementById("back").onclick=()=>detail.classList.remove("open");
document.getElementById("dspk").onclick=()=>{ if(currentDetail) speak(currentDetail); };

// --- Búsqueda en tiempo real ---------------------------------------------
const q=document.getElementById("q");
const banner=document.getElementById("voiceBanner");
q.addEventListener("input",()=>{ banner.style.display="none"; render(search(q.value)); });

// --- Micrófono (experimental) --------------------------------------------
const SR=window.SpeechRecognition||window.webkitSpeechRecognition;
function showBanner(msg){ banner.textContent=msg; banner.style.display="block"; }
document.getElementById("mic").onclick=()=>{
  // El reconocimiento web exige contexto seguro (https/localhost).
  if(location.protocol==="file:" || (window.isSecureContext===false)){
    showBanner("🎤 El micrófono del navegador no funciona abriendo el archivo directamente. "
      +"Ábrelo desde http://localhost o https, o usa la app Android (que reconoce sin conexión).");
    return;
  }
  if(!SR){ showBanner("🎤 Este navegador no tiene reconocimiento de voz. Prueba con Chrome."); return; }
  const r=new SR(); r.lang="es-ES"; r.interimResults=false; r.maxAlternatives=1;
  r.onresult=ev=>{
    const text=ev.results[0][0].transcript;
    q.value=text;
    // Español: busca el texto; Wolof: parecido fonético (con respaldo de texto).
    let res;
    if(mode==="es"){ res=search(text); }
    else { res=phonetic(text); if(!res.length) res=search(text); }
    render(res);
    showBanner("🎤 He oído: «"+text+"» ("+(mode==="es"?"español":"wolof")+"). Resultados:");
  };
  r.onerror=ev=>{
    const m = ev.error==="not-allowed" ? "permiso de micrófono denegado"
            : ev.error==="network" ? "necesita conexión (Chrome) y contexto seguro"
            : ev.error;
    showBanner("🎤 No se pudo reconocer la voz ("+m+").");
  };
  try { r.start(); } catch(e){ showBanner("🎤 No se pudo iniciar el micrófono."); }
};

render(DATA);
</script>
</body>
</html>
"""


def main():
    with open("app/src/main/assets/diccionario.json", encoding="utf-8") as f:
        data = json.load(f)
    html = PLANTILLA.replace("__DATA__", json.dumps(data, ensure_ascii=False))
    with open("demo/index.html", "w", encoding="utf-8") as f:
        f.write(html)
    print(f"Demo web generada con {len(data)} entradas -> demo/index.html")


if __name__ == "__main__":
    main()
