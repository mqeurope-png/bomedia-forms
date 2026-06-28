# -*- coding: utf-8 -*-
"""
Genera la guía de instalación/uso (docs/COMO_INSTALAR.html) usando las CAPTURAS
REALES del usuario (docs/img/capN.jpg), incrustadas en base64 para que el HTML
sea un único archivo autónomo. Pensado además para exportarse a PDF con páginas
del tamaño de la pantalla de un móvil.
"""
import base64
import os

DOCS = os.path.join(os.path.dirname(__file__), "..", "docs")
IMG = os.path.join(DOCS, "img")

LATEST = "https://github.com/mqeurope-png/bomedia-forms/releases/latest/download/app-release.apk"


def datauri(fn):
    path = os.path.join(IMG, fn)
    mime = "image/png" if fn.endswith(".png") else "image/jpeg"
    with open(path, "rb") as f:
        b64 = base64.b64encode(f.read()).decode()
    return f"data:{mime};base64,{b64}"


# (número, título, texto HTML, archivo de captura)
PASOS = [
    ("1", "Descarga el archivo",
     "Pulsa el botón de arriba (o el enlace que te pasen). El archivo "
     "<b>wolofapp.apk</b> se guarda en <b>Descargas</b>. Ábrelo tocándolo.",
     "cap2.jpg"),
    ("2", "Play Protect: «Más detalles»",
     "Puede salir un aviso de <b>Google Play Protect</b>. Es normal en apps que "
     "no vienen de Google Play. Toca <b>«Más detalles»</b>.",
     "cap3.jpg"),
    ("3", "«Instalar de todas formas»",
     "Se mostrará más texto y, abajo, la opción <b>«Instalar de todas formas»</b>. "
     "Tócala.",
     "cap4.jpg"),
    ("4", "«Instalar»",
     "Confirma tocando <b>«Instalar»</b>.",
     "cap5.jpg"),
    ("5", "¡Instalada!",
     "Ya tienes el icono <b>«Diccionario Wolof»</b> (la <b>W</b> verde) en tu "
     "pantalla. Ábrelo y a viajar. 🇸🇳",
     "cap6.jpg"),
]

USO = (
    "<p><b>🔎 Buscar:</b> escribe en <b>español o wolof</b>; la lista se filtra "
    "al instante (ej.: «gracias» → <b>Jërëjëf</b>). Sin acentos también vale.</p>"
    "<p><b>🗣️ Selector «Idioma: Español / Wolof»:</b> cambia micro y altavoz. "
    "En <b>Wolof</b> el 🔊 lee la pronunciación wolof y el 🎤 busca por sonido "
    "wolof; en <b>Español</b> el 🔊 lee la palabra en español y el 🎤 busca lo "
    "que dices en castellano.</p>"
    "<p><b>🔊 Escuchar:</b> toca el altavoz de cada palabra.</p>"
    "<p><b>🎤 Buscar por voz:</b> toca el micrófono de la barra y habla.</p>"
    "<p><b>🎛️ Elegir voz</b> (icono de ajustes, arriba a la derecha): francesa, "
    "española o inglesa. Al elegir, suena una muestra.</p>"
    "<p><b>📄 Ficha:</b> toca una palabra para verla en grande.</p>"
)

pasos_html = ""
for num, titulo, texto, cap in PASOS:
    pasos_html += f"""
  <section class="card">
    <h2><span class="num">{num}</span> {titulo}</h2>
    <p>{texto}</p>
    <img class="shot" src="{datauri(cap)}" alt="Paso {num}">
  </section>"""

html = f"""<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diccionario Wolof — Guía de instalación y uso</title>
<style>
  @page {{ size: 360px 740px; margin: 0; }}
  :root{{ --green:#00695c; }}
  *{{ box-sizing:border-box; }}
  html,body{{ margin:0; }}
  body{{ font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
    color:#1b1b1b; background:#eef1f0; -webkit-print-color-adjust:exact; print-color-adjust:exact; }}
  .page{{ width:360px; margin:0 auto; }}
  header{{ background:var(--green); color:#fff; padding:18px 16px; }}
  header .ic{{ display:inline-flex; width:38px; height:38px; border-radius:9px;
    background:#fff; color:var(--green); align-items:center; justify-content:center;
    font-weight:800; font-size:22px; vertical-align:middle; margin-right:9px; }}
  header h1{{ display:inline; font-size:19px; margin:0; vertical-align:middle; }}
  header p{{ margin:9px 0 0; font-size:12.5px; opacity:.92; }}
  .dl{{ display:block; text-align:center; background:#fff; color:var(--green);
    font-weight:800; text-decoration:none; padding:11px; border-radius:10px; margin-top:12px; }}
  .card{{ background:#fff; border-radius:14px; padding:14px; margin:12px;
    box-shadow:0 1px 3px rgba(0,0,0,.10); break-inside:avoid; }}
  .card.intro{{ background:#e8f5f3; }}
  h2{{ font-size:15.5px; margin:0 0 6px; display:flex; align-items:center; gap:9px; }}
  .num{{ flex:none; width:26px; height:26px; border-radius:50%; background:var(--green);
    color:#fff; font-size:14px; display:flex; align-items:center; justify-content:center; }}
  p{{ font-size:13.5px; line-height:1.45; margin:6px 0; color:#333; }}
  p b{{ color:#0a4a43; }}
  .shot{{ display:block; max-width:100%; max-height:530px; width:auto;
    margin:10px auto 0; border:1px solid #e3e6e6; border-radius:10px; }}
  .note{{ font-size:12px; color:#666; }}
  footer{{ text-align:center; font-size:11.5px; color:#777; padding:16px; }}
</style>
</head>
<body>
<div class="page">

  <header>
    <div><span class="ic">W</span><h1>Diccionario Wolof</h1></div>
    <p>Guía para instalar y usar la app · español ⇄ wolof · sin Internet</p>
    <a class="dl" href="{LATEST}">⬇️ Descargar la app</a>
  </header>
{pasos_html}

  <section class="card intro">
    <h2><span class="num">📖</span> Cómo usar la app</h2>
    {USO}
    <img class="shot" src="{datauri('cap7.jpg')}" alt="App en uso">
  </section>

  <section class="card">
    <h2>💡 Si no se instala</h2>
    <p>• <b>«Conflicto con el paquete»</b>: tenías una versión anterior con otra
      firma → <b>desinstala</b> la app y vuelve a instalar (solo una vez).<br>
      • Para no ver el aviso de Play Protect: <b>Play Store → tu perfil → Play
      Protect → ⚙️ → desactivar «Analizar apps»</b>, instalar, y reactivarlo.</p>
  </section>

  <footer>Diccionario Wolof · Guía de instalación y uso</footer>
</div>
</body>
</html>"""

with open(os.path.join(DOCS, "COMO_INSTALAR.html"), "w", encoding="utf-8") as f:
    f.write(html)
print("Generado docs/COMO_INSTALAR.html con capturas reales")
