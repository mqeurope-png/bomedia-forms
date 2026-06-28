# -*- coding: utf-8 -*-
"""
Construye una DEMO WEB de un solo archivo (demo/index.html) a partir de
app/src/main/assets/diccionario.json.

La demo replica la lógica de la app Android (búsqueda bidireccional sin
acentos/mayúsculas y con coincidencias parciales, fichas, "escuchar" por voz y
búsqueda por micrófono con coincidencia fonética) para poder probar el contenido
y la experiencia ANTES de compilar el APK. Basta con abrir el archivo en
cualquier navegador (en el móvil se recomienda Chrome para voz/micrófono).

El diccionario se incrusta dentro del HTML, así que el archivo funciona offline
(con doble clic, sin servidor).
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
  .searchbar { display:flex; gap:8px; padding:12px 16px; position:sticky; top:56px;
               background:#fff; border-bottom:1px solid #eee; z-index:9; }
  #q { flex:1; padding:10px 12px; font-size:16px; border:1px solid #ccc; border-radius:8px; }
  button.icon { border:1px solid var(--primary); background:#fff; color:var(--primary);
                border-radius:8px; padding:0 12px; font-size:18px; cursor:pointer; }
  button.icon:active { background:#e0f2f1; }
  .banner { background:#f2f2f2; padding:8px 16px; font-size:13px; }
  ul { list-style:none; margin:0; padding:0; }
  li { display:flex; align-items:center; gap:8px; padding:12px 16px;
       border-bottom:1px solid #eee; cursor:pointer; }
  li:hover { background:#fafafa; }
  .es { font-size:16px; }
  .wo { font-size:14px; color:var(--primary); }
  .cat { font-size:11px; color:#888; }
  .grow { flex:1; min-width:0; }
  .spk { border:none; background:transparent; font-size:20px; cursor:pointer; color:#555; }
  .empty { padding:24px 16px; color:#888; }
  /* Ficha */
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
<div id="voiceBanner" class="banner" style="display:none"></div>

<ul id="list"></ul>
<div id="empty" class="empty" style="display:none">No se han encontrado resultados.</div>
<div class="hint">Pulsa una entrada para ver su ficha. El altavoz 🔊 lee la pronunciación.
  El micrófono 🎤 es experimental (usa el reconocedor del navegador y busca por parecido fonético).</div>

<!-- Ficha de la palabra -->
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

// --- Búsqueda de texto ----------------------------------------------------
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

// --- Voz: hablar (TTS) ----------------------------------------------------
function speak(text){
  if(!text || !window.speechSynthesis) return;
  const u=new SpeechSynthesisUtterance(text);
  u.lang="es-ES";
  speechSynthesis.cancel(); speechSynthesis.speak(u);
}

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
    const toSpeak=e.pron||e.wo;
    if(toSpeak){
      const b=document.createElement("button"); b.className="spk"; b.textContent="🔊";
      b.title="Escuchar";
      b.onclick=ev=>{ev.stopPropagation(); speak(toSpeak);};
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
document.getElementById("dspk").onclick=()=>{ if(currentDetail) speak(currentDetail.pron||currentDetail.wo); };

// --- Búsqueda en tiempo real ---------------------------------------------
const q=document.getElementById("q");
const banner=document.getElementById("voiceBanner");
q.addEventListener("input",()=>{ banner.style.display="none"; render(search(q.value)); });

// --- Micrófono (experimental) --------------------------------------------
const SR=window.SpeechRecognition||window.webkitSpeechRecognition;
document.getElementById("mic").onclick=()=>{
  if(!SR){ alert("Este navegador no tiene reconocimiento de voz. Prueba con Chrome."); return; }
  const r=new SR(); r.lang="es-ES"; r.interimResults=false; r.maxAlternatives=1;
  r.onresult=ev=>{
    const text=ev.results[0][0].transcript;
    q.value=text;
    const res=phonetic(text);
    render(res.length?res:search(text));
    banner.textContent="🎤 He oído: «"+text+"». Mejores coincidencias:";
    banner.style.display="block";
  };
  r.onerror=()=>{ banner.textContent="🎤 No se pudo reconocer la voz."; banner.style.display="block"; };
  r.start();
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
