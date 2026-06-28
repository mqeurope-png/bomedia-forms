# Diccionario Wolof 🇸🇳

Diccionario **completamente offline** entre **español** y **wolof**, pensado
para viajeros que visitan Senegal (el wolof también se habla en Gambia y en
zonas de Mali y Mauritania).

- Sin Internet · Sin publicidad · Sin APIs externas · Sin permisos · Sin login
- Kotlin + Jetpack Compose + **Material Design 3**
- Compatible con **Android 7.0 (API 24)** o superior
- Datos en un único archivo **`assets/diccionario.json`**

---

## ✨ Características

1. **Pantalla principal**
   - Barra de búsqueda arriba y lista de palabras debajo.
   - La búsqueda funciona **mientras escribes** (en tiempo real).
   - Busca a la vez en **español y en wolof** (y también en la pronunciación).

   Ejemplos:
   - Buscar `gracias` → muestra **Jërëjëf**.
   - Buscar `jërëjëf` (o `jereje`) → muestra **Gracias**.

2. **Ficha de la palabra** (al pulsar una entrada)
   - Español
   - Wolof
   - Pronunciación aproximada en español

   Ejemplo:
   ```
   Español:        Gracias
   Wolof:          Jërëjëf
   Pronunciación:  Yereyef
   ```

3. **Búsqueda inteligente**
   - Ignora mayúsculas/minúsculas.
   - Ignora acentos y caracteres específicos del wolof (`ë`, `à`, `é`, `ó`, `ŋ`).
   - Encuentra **coincidencias parciales** (`gra` → *Gracias*, `jeri`/`jere` → *Jërëjëf*).

4. **Rendimiento**
   - El JSON se carga **una sola vez** al iniciar y se mantiene en memoria.
   - Todo funciona sin conexión.

---

## 🗂️ Estructura del proyecto

```
DiccionarioWolof/
├─ app/
│  ├─ build.gradle.kts
│  └─ src/main/
│     ├─ AndroidManifest.xml
│     ├─ assets/
│     │  └─ diccionario.json          ← ¡los datos van aquí!
│     ├─ java/com/bomedia/diccionariowolof/
│     │  ├─ MainActivity.kt            ← interfaz (Compose)
│     │  ├─ data/
│     │  │  ├─ Entry.kt                ← modelo de datos
│     │  │  └─ DictionaryRepository.kt ← carga del JSON + búsqueda
│     │  └─ ui/
│     │     ├─ DictionaryViewModel.kt  ← estado de la pantalla
│     │     └─ theme/Theme.kt          ← tema Material 3 (fondo blanco)
│     └─ res/                          ← textos, tema, iconos
├─ build.gradle.kts
├─ settings.gradle.kts
└─ gradlew / gradlew.bat               ← Gradle Wrapper (incluido)
```

---

## 🛠️ Cómo generar el APK desde Android Studio

### Requisitos
- **Android Studio** (Hedgehog 2023.1 o posterior recomendado).
- **JDK 17** (Android Studio ya lo incluye).
- Android SDK con la **API 34** instalada (Android Studio lo ofrece al abrir).

### Pasos
1. Abre Android Studio y elige **File → Open…** (o *Open an existing project*).
2. Selecciona la carpeta **`DiccionarioWolof/`** y pulsa *OK*.
3. Espera a que termine el **Gradle Sync** (Android Studio descargará las
   dependencias la primera vez; necesita conexión solo en este paso).
4. Para probarla: selecciona un emulador o un móvil y pulsa **Run ▶**.
5. Para generar el APK:
   - Menú **Build → Build Bundle(s) / APK(s) → Build APK(s)**.
   - Al terminar, pulsa el enlace **locate** del aviso, o busca el archivo en:
     ```
     app/build/outputs/apk/debug/app-debug.apk
     ```
   - Ese **APK de depuración** ya se puede instalar en cualquier móvil
     (activando "Orígenes desconocidos").

### APK de publicación (opcional, firmado)
1. Menú **Build → Generate Signed Bundle / APK…**
2. Elige **APK**, crea o selecciona un *keystore* y sigue el asistente.
3. El APK firmado quedará en `app/build/outputs/apk/release/`.

### Desde la línea de comandos (alternativa)
Con el Android SDK instalado y la variable `ANDROID_HOME` configurada:
```bash
cd DiccionarioWolof
./gradlew assembleDebug      # APK de depuración
./gradlew assembleRelease    # APK de publicación (sin firmar)
```

---

## ➕ Cómo ampliar el diccionario

Solo tienes que editar **un archivo**:
`app/src/main/assets/diccionario.json`.

Cada entrada tiene este formato:

```json
[
  {
    "es": "Gracias",
    "wo": "Jërëjëf",
    "pron": "Yereyef"
  }
]
```

- `es`   → palabra o frase en español (obligatorio).
- `wo`   → traducción en wolof. **Déjalo como `""`** si no la conoces con
  certeza (la app lo mostrará como *"traducción no disponible"* en lugar de
  inventar una traducción).
- `pron` → pronunciación aproximada en español.

Añade tantos objetos como quieras al array, guarda el archivo y vuelve a
compilar. **No hay que tocar el código**: la app ordena, indexa y busca
automáticamente sobre el nuevo contenido.

> Consejo: el repositorio incluye el script
> `tools/generate_dict.py`, que genera este JSON calculando la pronunciación
> aproximada de forma automática a partir de la ortografía wolof.

---

## 📚 Fuente de los datos

El diccionario inicial (~336 entradas: saludos, números, comida, transporte,
mercado, dinero, alojamiento, salud, emergencias y expresiones habituales)
está tomado del libro de conversación:

> **Dímelo en wolof** — Mahu Thiam Fall.
> Coedición de *oozebap* y *les éditions madina*, Barcelona, 2012.

Cuando una traducción no aparece en la fuente o no es segura, el campo `wo`
se ha dejado vacío en lugar de inventarla.

La pronunciación es **aproximada** y sigue las reglas del alfabeto wolof
descritas en ese mismo libro (por ejemplo: `c`→"ch", `j`→"y", `x`→"j" jota,
`ŋ`→"ng", `ë`/`é`→"e", `à`→"a", `ó`→"o", y la `g` siempre dura).
