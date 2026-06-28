# Diccionario Wolof 🇸🇳

Diccionario **offline** entre **español** y **wolof**, pensado para viajeros que
visitan Senegal (el wolof también se habla en Gambia y en zonas de Mali y
Mauritania).

- Sin Internet · Sin publicidad · Sin APIs externas · Sin login
- Kotlin + Jetpack Compose + **Material Design 3**
- Compatible con **Android 7.0 (API 24)** o superior
- **427 entradas**: vocabulario + **frases y conversaciones** del libro fuente
- Datos en un único archivo **`assets/diccionario.json`**, organizado por categorías

---

## 🚀 Probar la demo SIN instalar nada

En la carpeta **`demo/`** hay un archivo **`index.html`** que es una demo web del
diccionario (mismo contenido y misma lógica de búsqueda que la app).

1. Abre **`demo/index.html`** en cualquier navegador (doble clic, o ábrelo en el
   móvil). Funciona **offline**, sin servidor.
2. Prueba la búsqueda, pulsa una entrada para ver su ficha, usa 🔊 para escuchar
   la pronunciación y 🎤 para la búsqueda por voz (experimental).

> Para la voz y el micrófono en la web se recomienda **Chrome**. La demo sirve
> para validar el contenido y la experiencia antes de compilar el APK; la app
> real Android es la versión definitiva.

La demo se regenera con:
```bash
cd DiccionarioWolof
python3 tools/build_web_demo.py
```

---

## ✨ Características

1. **Pantalla principal**: barra de búsqueda arriba y lista debajo. Filtra
   **mientras escribes**, a la vez en **español, wolof, pronunciación y categoría**.
   - `gracias` → **Jërëjëf** · `jërëjëf` (o `jereje`) → **Gracias**
   - Ignora mayúsculas, acentos y caracteres wolof (`ë à é ó ŋ`); admite
     coincidencias parciales (`gra` → *Gracias*).

2. **Ficha de la palabra**: categoría, Español, Wolof y Pronunciación aproximada.
   ```
   Español:        Gracias
   Wolof:          Jërëjëf
   Pronunciación:  Yereyef
   ```

3. **🔊 Escuchar (offline)**: cada entrada tiene un botón de altavoz (Text-to-
   Speech). Se puede **elegir la voz** (idioma/acento y, según el móvil, hombre
   o mujer) con el botón de voz de la barra superior. La voz **por defecto es
   francesa** (lengua cooficial en Senegal); también hay voz española, etc.
   - Para cada voz se usa una transcripción fonética distinta y silabeada:
     `Jërëjëf` → voz española **"ye-re-yef"**, voz francesa **"dje-re-djef"**.
   - Los grupos prenasales del wolof (`nga`, `nd`, `mb`…) se adaptan con una
     "e" de apoyo (`nga` → `ne-ga`) para que la voz no los deletree.
   - Funciona sin conexión si el móvil tiene instalada esa voz.

4. **Selector de idioma [ Español | Wolof ]**: decide cómo se comportan el
   micrófono y el altavoz.
   - **Español**: el micro 🎤 busca lo que dices en español (encuentra el
     término español) y el 🔊 lee la palabra en español (voz española).
   - **Wolof**: el micro busca por parecido fonético con el wolof y el 🔊 lee la
     pronunciación wolof con la voz elegida.

5. **🎤 Búsqueda por voz (experimental)** — ver la sección siguiente.

5. **Rendimiento**: el JSON se carga **una sola vez** al iniciar y se mantiene en
   memoria; todo funciona sin conexión.

---

## 🎤 Sobre el reconocimiento de voz en wolof (léelo)

**No existe un reconocedor de voz de wolof** ni online ni, mucho menos, offline.
Android (Google) no reconoce wolof, y los modelos experimentales que hay son
grandes, requieren Internet y tienen poca calidad.

Lo que esta app hace es una **aproximación offline honesta**:

1. Usa el **reconocedor de voz del propio sistema** y le pide trabajar **sin
   conexión** (`EXTRA_PREFER_OFFLINE`), en modo español (el idioma más cercano a
   nuestra grafía de pronunciación).
2. Ese reconocedor produce una **transcripción aproximada** de lo que ha oído.
3. La app compara esa transcripción con la **pronunciación** y el **wolof** de
   cada entrada mediante una **coincidencia fonética** (distancia de edición de
   Levenshtein + parecido palabra a palabra) y muestra los **candidatos más
   parecidos**.

**Limitaciones (importante):**
- Es **aproximado**: acertará en palabras/frases del diccionario y fallará con
  frases largas o muy distintas a las de la fuente.
- El reconocimiento *offline* depende de que el móvil tenga instalado el
  **paquete de idioma español sin conexión** (Ajustes → Sistema → Idiomas →
  Reconocimiento de voz / Voz sin conexión). Si no lo tiene, el sistema puede
  usar Internet o no funcionar.
- La app **no añade el permiso de micrófono** en su manifiesto: delega en la
  pantalla de reconocimiento del sistema, que gestiona el micrófono. Así se
  mantiene "sin permisos" en la propia app.

> Alternativa 100% autónoma (avanzada): integrar un modelo **Vosk** en español
> dentro de la APK para no depender del sistema. Aumenta mucho el tamaño y queda
> fuera del alcance de esta app sencilla; el código de coincidencia fonética
> (`DictionaryRepository.phoneticSearch`) ya está preparado para reutilizarse.

---

## 🗂️ Estructura del proyecto

```
DiccionarioWolof/
├─ demo/
│  └─ index.html                       ← demo web (pruébala sin compilar)
├─ tools/
│  ├─ generate_dict.py                 ← genera el JSON (con la pronunciación)
│  └─ build_web_demo.py                ← genera demo/index.html desde el JSON
├─ app/
│  ├─ build.gradle.kts
│  └─ src/main/
│     ├─ AndroidManifest.xml
│     ├─ assets/
│     │  └─ diccionario.json           ← ¡los datos van aquí!
│     ├─ java/com/bomedia/diccionariowolof/
│     │  ├─ MainActivity.kt            ← interfaz (Compose) + voz/TTS
│     │  ├─ data/
│     │  │  ├─ Entry.kt                ← modelo (cat, es, wo, pron)
│     │  │  └─ DictionaryRepository.kt ← carga JSON + búsqueda + fonética
│     │  └─ ui/
│     │     ├─ DictionaryViewModel.kt  ← estado de la pantalla
│     │     ├─ Tts.kt                  ← "escuchar" (Text-to-Speech)
│     │     └─ theme/Theme.kt          ← tema Material 3 (fondo blanco)
│     └─ res/                          ← textos, tema, iconos
├─ build.gradle.kts · settings.gradle.kts
└─ gradlew / gradlew.bat               ← Gradle Wrapper (incluido)
```

---

## 🛠️ Cómo generar el APK desde Android Studio

### Requisitos
- **Android Studio** (Hedgehog 2023.1 o posterior recomendado).
- **JDK 17** (Android Studio ya lo incluye).
- Android SDK con la **API 34** instalada (Android Studio lo ofrece al abrir).

### Pasos
1. En Android Studio: **File → Open…** y selecciona la carpeta **`DiccionarioWolof/`**.
2. Espera al **Gradle Sync** (solo la primera vez descarga dependencias).
3. Para probarla: elige un emulador o móvil y pulsa **Run ▶**.
4. Para generar el APK: **Build → Build Bundle(s) / APK(s) → Build APK(s)**.
   El archivo queda en:
   ```
   app/build/outputs/apk/debug/app-debug.apk
   ```
   Ese APK de depuración se puede instalar en cualquier móvil (activando
   "Orígenes desconocidos").

### Desde la línea de comandos (con el Android SDK y `ANDROID_HOME` configurados)
```bash
cd DiccionarioWolof
./gradlew assembleDebug      # APK de depuración
./gradlew assembleRelease    # APK de publicación (sin firmar)
```

### APK automático en GitHub (para probar en el móvil sin compilar tú)
El repositorio incluye un workflow (`.github/workflows/build-apk.yml`) que
**compila el APK solo** cuando hay cambios en `DiccionarioWolof/` (o lanzándolo
a mano en la pestaña **Actions → Build APK Diccionario Wolof → Run workflow**).

Al terminar tendrás el APK en dos sitios:
- En la propia ejecución (**Actions → … → Artifacts → `diccionario-wolof-debug`**).
- Como **Release** (`apk-build-N`) con el archivo `app-debug.apk` enlazado
  directamente, ideal para **abrirlo y descargarlo desde el móvil**.

Luego, en el teléfono: abre el `.apk`, permite "orígenes desconocidos" e instala.

---

## ➕ Cómo ampliar el diccionario

Edita **`app/src/main/assets/diccionario.json`**. Cada entrada:

```json
[
  {
    "cat": "Saludos y cortesía",
    "es": "Gracias",
    "wo": "Jërëjëf",
    "pron": "Yereyef",
    "fon_es": "ye-re-yef",
    "fon_fr": "dje-re-djef"
  }
]
```

- `cat`    → categoría temática (para agrupar/buscar). Opcional.
- `es`     → palabra o frase en español (obligatorio).
- `wo`     → traducción en wolof. **Déjalo `""`** si no la conoces con certeza
  (la app mostrará *"traducción no disponible"* en vez de inventarla).
- `pron`   → pronunciación aproximada en español (la que se muestra).
- `fon_es` / `fon_fr` → transcripción silabeada para la voz española / francesa.

Lo más cómodo es **no editar `pron`/`fon_*` a mano**: ejecuta
`python3 tools/generate_dict.py` y se recalculan solos. Añade tus pares
español/wolof en ese script. **No hay que tocar el código de la app**: ordena,
indexa, busca y lee en voz alta automáticamente.

> El script `tools/generate_dict.py` regenera el JSON calculando la
> pronunciación de forma automática a partir de la ortografía wolof, y
> `tools/build_web_demo.py` regenera la demo web.

---

## 📚 Fuente de los datos

Vocabulario, frases y **conversaciones** de los diez temas del libro:

> **Dímelo en wolof** — Mahu Thiam Fall.
> Coedición de *oozebap* y *les éditions madina*, Barcelona, 2012.

Cuando una traducción no aparece en la fuente o no es segura, el campo `wo` se
ha dejado vacío en lugar de inventarla.

La **pronunciación es aproximada** y sigue las reglas del alfabeto wolof
descritas en el propio libro (págs. 6-8): `c`→"ch", `j`→"y", `x`→"j" (jota),
`ŋ`→"ng", `q`→"k", `ë`/`é`→"e", `à`→"a", `ó`→"o", `g` siempre dura, y las letras
dobles como sonidos largos.
