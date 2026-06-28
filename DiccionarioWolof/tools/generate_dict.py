# -*- coding: utf-8 -*-
"""
Genera assets/diccionario.json para la app "Diccionario Wolof".

Los pares español/wolof provienen del libro de conversación
"Dímelo en wolof" (Mahu Thiam Fall, oozebap / les éditions madina, 2012),
aportado como fuente. Incluye tanto vocabulario como FRASES y líneas de las
CONVERSACIONES de los diez temas del libro. No se inventan traducciones:
cuando una palabra/frase no aparece en la fuente, se deja "wo" vacío.

Cada entrada lleva además una categoría ("cat") para poder filtrar/ordenar.

----------------------------------------------------------------------------
FONÉTICA DEL WOLOF (según "el alfabeto wolof", págs. 6-8 del libro)
----------------------------------------------------------------------------
El campo "pron" (pronunciación aproximada para hispanohablantes) se calcula
automáticamente aplicando estas reglas del propio libro:

  Vocales específicas:
    à -> "a"  (a larga; aquí se aproxima a "a")
    é -> "e"  (e cerrada, entre "a" y "e")
    ë -> "e"  (sonido francés "eu" de je/te; se aproxima a "e")
    ó -> "o"  (o cerrada francesa)
  Consonantes específicas:
    c -> "ch" (como en "chico")
    j -> "y"  (como "y" + vocal: jabar -> "yabar")
    x -> "j"  (jota: xale -> "jale")
    ŋ -> "ng" (final de "parking")
    q -> "k"  (g gutural; se aproxima a "k")
    y -> "y"  (como "ll")
    g -> siempre dura; se escribe "gu" ante e/i (gui/gue) para no leerla jota
  Letras dobles = sonidos largos -> se simplifican para el lector español.
----------------------------------------------------------------------------
"""

import json
import re

# ---------------------------------------------------------------------------
# Transliteración: wolof -> pronunciación aproximada en español
# ---------------------------------------------------------------------------

FRONT_VOWELS = "eéëií"  # vocales ante las que la "g" española suena suave


def aproximar_pronunciacion(wo: str) -> str:
    """Devuelve una pronunciación aproximada en grafía española."""
    if not wo:
        return ""

    def conv(token: str) -> str:
        s = token.lower()
        # 1) "g" dura: ante vocal frontal se escribe "gu" (gui/gue).
        s = re.sub(r"g(?=[" + FRONT_VOWELS + r"])", "gu", s)
        # 2) Vocales específicas del wolof.
        s = (s.replace("à", "a").replace("é", "e")
              .replace("ë", "e").replace("ó", "o"))
        # 3) Consonantes específicas (orden importante: j antes que x).
        s = s.replace("j", "y")
        s = s.replace("x", "j")
        s = s.replace("c", "ch")
        s = s.replace("ŋ", "ng")
        s = s.replace("q", "k")
        # 4) Letras dobles = sonidos largos: se simplifican.
        s = re.sub(r"([a-zñ])\1", r"\1", s)
        s = re.sub(r"([a-zñ])\1", r"\1", s)
        return s

    partes = re.split(r"([ \-/])", wo)
    resultado = "".join(conv(p) if p.strip() and p not in " -/" else p
                        for p in partes)
    return resultado[:1].upper() + resultado[1:] if resultado else ""


# ---------------------------------------------------------------------------
# Transcripción fonética para la VOZ (silabeada)
# ---------------------------------------------------------------------------
# El motor de voz (TTS) lee mejor —más despacio y claro— una versión silabeada
# de la pronunciación: "Jërëjëf" -> pron "Yereyef" -> voz "ye-re-yef".
# Aquí silabeamos la pronunciación con reglas estándar del español.

_VOCALES = "aeiouáéíóúü"
_FUERTES = "aeoáéó"
_DEBIL_TONICA = "íú"
_INSEPARABLES = {
    "bl", "cl", "fl", "gl", "pl", "br", "cr", "dr", "fr", "gr", "pr", "tr",
}
# Dígrafos/grupos que cuentan como UNA consonante (se "protegen" con marcadores).
_MARCADORES = {"\x01": "gu", "\x02": "qu", "\x03": "ch", "\x04": "ll", "\x05": "rr"}


def _silabear(palabra: str) -> list:
    """Divide en sílabas una palabra (en grafía española de pronunciación)."""
    s = palabra.lower()
    # Proteger dígrafos como una sola consonante (longitud 1 cada marcador).
    s = re.sub(r"gu(?=[eiéí])", "\x01", s)   # gu = /g/ (gui, gue)
    s = re.sub(r"qu", "\x02", s)             # qu = /k/
    s = s.replace("ch", "\x03").replace("ll", "\x04").replace("rr", "\x05")

    def es_vocal(c: str) -> bool:
        return c in _VOCALES

    letras = list(s)

    # 1) Núcleos vocálicos (agrupando diptongos, separando hiatos).
    nucleos = []
    i = 0
    while i < len(letras):
        if es_vocal(letras[i]):
            j = i
            while j + 1 < len(letras) and es_vocal(letras[j + 1]):
                a, b = letras[j], letras[j + 1]
                if a in _FUERTES and b in _FUERTES:
                    break  # hiato: dos vocales fuertes
                if a in _DEBIL_TONICA or b in _DEBIL_TONICA:
                    break  # hiato: vocal débil tónica
                j += 1
            nucleos.append((i, j))
            i = j + 1
        else:
            i += 1

    if not nucleos:
        return [palabra]

    # 2) Repartir las consonantes entre núcleos contiguos.
    silabas = []
    inicio = 0
    for idx, (_, fin_n) in enumerate(nucleos):
        if idx == len(nucleos) - 1:
            corte = len(letras)
        else:
            sig_ini = nucleos[idx + 1][0]
            cons = letras[fin_n + 1:sig_ini]
            n = len(cons)
            if n <= 1:
                corte = fin_n + 1                      # 0/1 cons -> con la sig.
            elif n == 2:
                par = cons[0] + cons[1]
                corte = fin_n + 1 if par in _INSEPARABLES else fin_n + 2
            else:
                par = cons[-2] + cons[-1]
                corte = sig_ini - 2 if par in _INSEPARABLES else sig_ini - 1
        silabas.append("".join(letras[inicio:corte]))
        inicio = corte

    # Restaurar los dígrafos protegidos.
    out = []
    for sil in silabas:
        for marca, orig in _MARCADORES.items():
            sil = sil.replace(marca, orig)
        out.append(sil)
    return out


_SIGNOS = "¿?¡!.,…:;«»\"'()"


def fonetica_voz(pron: str) -> str:
    """Transcripción silabeada de la pronunciación, lista para el TTS."""
    if not pron:
        return ""
    palabras = []
    for token in pron.split(" "):
        nucleo = token.strip(_SIGNOS)
        if not nucleo:
            palabras.append(token)
            continue
        pre = token[:len(token) - len(token.lstrip(_SIGNOS))]
        post = token[len(token.rstrip(_SIGNOS)):]
        palabras.append(pre + "-".join(_silabear(nucleo)) + post)
    return " ".join(palabras)


# ---------------------------------------------------------------------------
# Datos: (categoría, español, wolof). wolof == "" -> no disponible.
# ---------------------------------------------------------------------------

SALUDOS = "Saludos y cortesía"
PRESENTARSE = "Presentarse"
NUMEROS = "Números"
TIEMPO = "Tiempo y días"
FAMILIA = "Familia"
DESCRIP = "Descripciones"
CASA = "Casa y alojamiento"
LUGARES = "Lugares y direcciones"
TRANSPORTE = "Transporte"
COMPRAS = "Compras y dinero"
COMIDA = "Comida y bebida"
SALUD = "Salud y emergencias"
TRABAJO = "Trabajo y oficios"
VERBOS = "Verbos útiles"

DATOS = [
    # ===================== Saludos y cortesía ==========================
    (SALUDOS, "¡Hola! / Buenos días (saludo)", "Salaamaalekum"),
    (SALUDOS, "Respuesta al saludo (y contigo)", "Maalekum salaam"),
    (SALUDOS, "¿Qué tal? / ¿Cómo estás?", "Nan nga def?"),
    (SALUDOS, "¿Cómo estás?", "Naka nga def?"),
    (SALUDOS, "¿Cómo estáis?", "Nan ngeen def?"),
    (SALUDOS, "Estoy bien", "Maa ngi fi rekk"),
    (SALUDOS, "Bien, gracias a Dios", "Maa ngi sant"),
    (SALUDOS, "Bien, en paz", "Jàmm rekk"),
    (SALUDOS, "Gracias", "Jërëjëf"),
    (SALUDOS, "Muchas gracias", "Jërëjëf waay"),
    (SALUDOS, "De nada", "Amul benn solo"),
    (SALUDOS, "De nada / No es nada", "Du dara"),
    (SALUDOS, "Sí", "Waaw"),
    (SALUDOS, "No", "Déedéet"),
    (SALUDOS, "Perdona / Perdóname", "Baal ma"),
    (SALUDOS, "Perdóname, por favor", "Nga baal ma de"),
    (SALUDOS, "Por favor", "Nga baal ma"),
    (SALUDOS, "No importa / No tiene importancia", "Amul benn solo"),
    (SALUDOS, "Lo siento", "Maa ngi jéglu"),
    (SALUDOS, "Hasta mañana", "Ba suba"),
    (SALUDOS, "Hasta mañana, entonces", "Baax na, kon ba suba"),
    (SALUDOS, "Hasta luego / Hasta otra", "Ba beneen"),
    (SALUDOS, "¡Que paséis buena noche!", "Fanaan leen ak jàmm"),
    (SALUDOS, "¿Has dormido bien?", "Nelaw nga bu baax?"),
    (SALUDOS, "Sí, he dormido muy bien", "Waaw, nelaw naa bu baax"),
    (SALUDOS, "¿Cómo va la mañana?", "Naka suba si?"),
    (SALUDOS, "¿Cómo has pasado el día?", "Nan nga yendoo?"),
    (SALUDOS, "¿Cómo están tus hijos?", "Naka xale yi?"),
    (SALUDOS, "Están muy bien", "Ñu ngi ci jàmm"),
    (SALUDOS, "¿Cómo está tu padre?", "Naka sa pàppa?"),
    (SALUDOS, "¿Cómo están tus padres?", "Naka sa pàppa ak sa yaay?"),
    (SALUDOS, "Mi madre está bien", "Sama yaay mu ngi ci jàmm"),
    (SALUDOS, "Se encuentra bien, se ha ido a trabajar", "Mu ngi ci sant, dafa dem ligeeyi"),
    (SALUDOS, "¿Qué tal está tu madre?", "Ana sa yaay?"),
    (SALUDOS, "Estamos bien", "Ñu ngi fi rekk"),
    (SALUDOS, "¿Cómo va el trabajo?", "Naka liggéey bi nak?"),
    (SALUDOS, "Tengo mucho trabajo estos días", "Damaa bari ligeey lool fan yii"),
    (SALUDOS, "Siéntate un poco", "Toogal tuuti"),
    (SALUDOS, "Dale recuerdos a la familia", "Nuyul ma njaboot ga"),
    (SALUDOS, "¿Qué haces por la calle a estas horas?", "Yow lan ngay def ci mbedd mi waxtu wii?"),
    (SALUDOS, "Vale / De acuerdo / Está bien", "Baax na"),
    (SALUDOS, "¡Diga! / ¿Dígame? (al teléfono)", "Alóo"),
    (SALUDOS, "¿Quién es? / ¿Quién llama?", "Yow yaay kan?"),
    (SALUDOS, "Espera, ahora se pone", "Xaaral, mu ngi ñëw"),
    (SALUDOS, "Espera (un momento)", "Xaaral"),
    (SALUDOS, "Esperad un momento", "Xaar leen tuuti"),
    (SALUDOS, "Bienvenido", ""),

    # ===================== Presentarse =================================
    (PRESENTARSE, "¿Cómo te llamas?", "Noo tuddu?"),
    (PRESENTARSE, "Me llamo...", "Maa ngi tuddu..."),
    (PRESENTARSE, "Me llamo Amina", "Maa ngi tuddu Amina"),
    (PRESENTARSE, "¿De dónde eres?", "Waa fan nga?"),
    (PRESENTARSE, "Soy español", "Waa Espaañ laa"),
    (PRESENTARSE, "Soy senegalés", "Waa Senegaal laa"),
    (PRESENTARSE, "Soy senegalés pero vivo en Barcelona", "Waa Senegaal laa waaye Barcelona laa dëkk"),
    (PRESENTARSE, "Soy de Dakar", "Waa Ndakaaru laa"),
    (PRESENTARSE, "¿Dónde vives?", "Fan nga dëkk?"),
    (PRESENTARSE, "Vivo en Barcelona", "Maa ngi dëkk Barcelona"),
    (PRESENTARSE, "¿Vives aquí?", "Fii nga dëkk?"),
    (PRESENTARSE, "Sí, vivo aquí en Barcelona", "Waaw, fii ci Barcelona laa dëkk"),
    (PRESENTARSE, "¿En qué barrio vives?", "Ban koñ nga dëkk?"),
    (PRESENTARSE, "¿Cuándo has llegado?", "Kañ nga egsi?"),
    (PRESENTARSE, "Llegué ayer por la tarde", "Egsi naa démb ci ngoon"),
    (PRESENTARSE, "¿Es tu primer viaje a Senegal?", "Bii mooy sa yoon bu njëkk ci Senegaal?"),
    (PRESENTARSE, "Estoy muy contento de venir a Senegal", "Kontaan naa lool ci ñëw fii ci Senegaal"),
    (PRESENTARSE, "¿Eres tú Astu?", "Yow yaay Astu?"),
    (PRESENTARSE, "No, no soy yo", "Déedéet, du man de"),
    (PRESENTARSE, "Y tú, ¿quién eres?", "Yow nak yaay kan?"),
    (PRESENTARSE, "¿Conoces a Faatu?", "Xam nga Faatu?"),
    (PRESENTARSE, "No, no nos conocemos", "Déedéet, xamantewuñu de"),
    (PRESENTARSE, "Es mi esposa", "Mooy sama soxna"),
    (PRESENTARSE, "Encantado de conocerte", "Kontaan naa lool ci xamante ak yow"),
    (PRESENTARSE, "Yo también, encantado", "Man tamit kontaan naa ci"),
    (PRESENTARSE, "¿Cuántos años tienes?", "Ñaata at nga am?"),
    (PRESENTARSE, "Tengo veinte años", "Am naa ñaar-fukki at"),
    (PRESENTARSE, "¿Tienes hijos?", "Ndax am nga doom?"),
    (PRESENTARSE, "Tengo dos hijos: un niño y una niña", "Am naa ñaari doom: kenn ku góor ak kenn ku jigeen"),
    (PRESENTARSE, "¿A qué te dedicas?", "Ci lan ngay yëngëtu?"),
    (PRESENTARSE, "¿Estudias o trabajas?", "Dangay jàng wala dangay liggéey?"),
    (PRESENTARSE, "¿Tienes teléfono móvil?", "Ndax yor nga portaabal?"),
    (PRESENTARSE, "¿Cuál es tu número de teléfono?", "Lan mooy sa nimero telefon?"),
    (PRESENTARSE, "¿Me puedes dar tu teléfono?", "Ndax mën nga ma jox sa telefon?"),
    (PRESENTARSE, "Te llamaré uno de estos días", "Dinaa la woo ci fan yii"),
    (PRESENTARSE, "Vale, espero tu llamada", "Baax na, maa ngi xaar sa woote"),
    (PRESENTARSE, "¿Tienes marido?", "Am nga jëkkër?"),
    (PRESENTARSE, "No tengo marido", "Amuma jëkkër"),
    (PRESENTARSE, "¿Tienes esposa?", "Am nga jabar?"),
    (PRESENTARSE, "No, soy soltero", "Déedéet, selibateer laa"),

    # ===================== Números =====================================
    (NUMEROS, "Cero", "tus"),
    (NUMEROS, "Uno", "benn"),
    (NUMEROS, "Dos", "ñaar"),
    (NUMEROS, "Tres", "ñett"),
    (NUMEROS, "Cuatro", "ñent"),
    (NUMEROS, "Cinco", "juróom"),
    (NUMEROS, "Seis", "juróom benn"),
    (NUMEROS, "Siete", "juróom ñaar"),
    (NUMEROS, "Ocho", "juróom ñett"),
    (NUMEROS, "Nueve", "juróom ñent"),
    (NUMEROS, "Diez", "fukk"),
    (NUMEROS, "Once", "fukk ak benn"),
    (NUMEROS, "Doce", "fukk ak ñaar"),
    (NUMEROS, "Trece", "fukk ak ñett"),
    (NUMEROS, "Catorce", "fukk ak ñent"),
    (NUMEROS, "Quince", "fukk ak juróom"),
    (NUMEROS, "Dieciséis", "fukk ak juróom benn"),
    (NUMEROS, "Diecisiete", "fukk ak juróom ñaar"),
    (NUMEROS, "Dieciocho", "fukk ak juróom ñett"),
    (NUMEROS, "Diecinueve", "fukk ak juróom ñent"),
    (NUMEROS, "Veinte", "ñaar fukk"),
    (NUMEROS, "Treinta", "fan weer"),
    (NUMEROS, "Cuarenta", "ñent fukk"),
    (NUMEROS, "Cincuenta", "juróom fukk"),
    (NUMEROS, "Sesenta", "juróom benn fukk"),
    (NUMEROS, "Setenta", "juróom ñaar fukk"),
    (NUMEROS, "Ochenta", "juróom ñett fukk"),
    (NUMEROS, "Noventa", "juróom ñent fukk"),
    (NUMEROS, "Cien", "téeméer"),
    (NUMEROS, "Doscientos", "ñaari téeméer"),
    (NUMEROS, "Quinientos", "juróomi téeméer"),
    (NUMEROS, "Mil", "junni"),
    (NUMEROS, "Un millón", "milyoŋ"),

    # ===================== Tiempo y días ===============================
    (TIEMPO, "Lunes", "Altine"),
    (TIEMPO, "Martes", "Talaata"),
    (TIEMPO, "Miércoles", "Àllarba"),
    (TIEMPO, "Jueves", "Alxames"),
    (TIEMPO, "Viernes", "Àjjuma"),
    (TIEMPO, "Sábado", "Gaawu"),
    (TIEMPO, "Domingo", "Dibéer"),
    (TIEMPO, "Día", "bés"),
    (TIEMPO, "Hoy", "Tey"),
    (TIEMPO, "Mañana (el día siguiente)", "Ëllëg"),
    (TIEMPO, "Pasado mañana", "Ginnaaw suba"),
    (TIEMPO, "Ayer", "Démb"),
    (TIEMPO, "Antes de ayer", "Bërki-démb"),
    (TIEMPO, "Semana", "Ayubés"),
    (TIEMPO, "Mes", "Weer"),
    (TIEMPO, "Año", "At"),
    (TIEMPO, "Por la mañana", "Ci suba"),
    (TIEMPO, "Por la tarde", "Ci ngoon"),
    (TIEMPO, "Por la noche", "Ci guddi"),
    (TIEMPO, "¿Qué día es hoy?", "Tey ban bés la?"),
    (TIEMPO, "¿Qué hora es? / ¿Tienes hora?", "Ban waxtu moo jot?"),
    (TIEMPO, "A las ocho", "Juróom ñetti waxtu"),
    (TIEMPO, "¿A qué hora te levantas?", "Ban waxtu ngay jóg?"),
    (TIEMPO, "¿A qué hora empiezas a trabajar?", "Ban waxtu ngay tàmbalee liggéey?"),
    (TIEMPO, "Feliz año nuevo", "Dewénati"),

    # ===================== Familia =====================================
    (FAMILIA, "Padre", "pàppa"),
    (FAMILIA, "Madre", "yaay"),
    (FAMILIA, "Hijo / Hija", "doom"),
    (FAMILIA, "Hijo mayor", "taaw"),
    (FAMILIA, "Hijo menor", "caat"),
    (FAMILIA, "Hermano/a mayor", "mag"),
    (FAMILIA, "Hermano/a menor", "rakk"),
    (FAMILIA, "Marido / Esposo", "jëkkër"),
    (FAMILIA, "Esposa / Mujer", "jabar"),
    (FAMILIA, "Abuelo / Abuela", "maam"),
    (FAMILIA, "Nieto / Nieta", "sët"),
    (FAMILIA, "Tío", "nijaay"),
    (FAMILIA, "Tía", "tànta"),
    (FAMILIA, "Sobrino / Sobrina", "jarbaat"),
    (FAMILIA, "Primo / Prima (materno)", "doomu tànta"),
    (FAMILIA, "Primo / Prima (paterno)", "doomu bàjjen"),
    (FAMILIA, "Amigo / Amiga", "xarit"),
    (FAMILIA, "Vecino / Vecina", "dëkkandoo"),
    (FAMILIA, "Hombre", "góor"),
    (FAMILIA, "Mujer", "jigeen"),
    (FAMILIA, "Niño / Niña", "xale"),
    (FAMILIA, "Joven", "ndaw"),
    (FAMILIA, "Familia", "njaboot"),
    (FAMILIA, "Es mi primo", "Mooy sama doomu nijaay"),
    (FAMILIA, "¿Y éste, quién es?", "Kii nak mooy kan?"),
    (FAMILIA, "¡Qué guapo es!", "A ka rafet!"),
    (FAMILIA, "¿Está casado?", "Ndax mu ngi sëy?"),
    (FAMILIA, "No, es soltero", "Déedéet, selibateer la"),

    # ===================== Descripciones ===============================
    (DESCRIP, "Es alto", "dafa njool"),
    (DESCRIP, "Es bajo (de estatura)", "dafa gàtt"),
    (DESCRIP, "Es guapo/a", "dafa rafet"),
    (DESCRIP, "Es feo/a", "dangaa ñaaw"),
    (DESCRIP, "Es amable", "dafa baax"),
    (DESCRIP, "Eres simpático/a", "dangaa baax"),
    (DESCRIP, "Soy inteligente", "damaa am xel"),
    (DESCRIP, "Es grande", "dafa rëy"),
    (DESCRIP, "Eres moreno/a", "nit ku ñuul nga"),
    (DESCRIP, "Soy joven", "xale laa"),
    (DESCRIP, "Eres viejo/a", "màgget nga"),
    (DESCRIP, "Estoy contento/a", "kontaan naa"),

    # ===================== Casa y alojamiento ==========================
    (CASA, "Casa", "kër"),
    (CASA, "Habitación / Dormitorio", "néeg"),
    (CASA, "Salón", "saal"),
    (CASA, "Cocina", "waañ"),
    (CASA, "Cuarto de baño", "wanag"),
    (CASA, "Ducha", "sangukaay"),
    (CASA, "Cama", "lal"),
    (CASA, "Mesa", "taabal"),
    (CASA, "Silla", "siis"),
    (CASA, "Puerta", "bunt"),
    (CASA, "Ventana", "polonteer"),
    (CASA, "Llave", "caabi"),
    (CASA, "Apartamento", "apartamaŋ"),
    (CASA, "Hotel", "otel"),
    (CASA, "Ascensor", "asaansëer"),
    (CASA, "Frigorífico", "filisideer"),
    (CASA, "Armario", "armoor"),
    (CASA, "Lámpara", "làmp"),
    (CASA, "Espejo", "seetu"),
    (CASA, "Alfombra", "moket"),
    (CASA, "Terraza", "teeraas"),
    (CASA, "¿Vives en una casa o en un apartamento?", "Ci kër nga dëkk wala ci apartamaŋ?"),
    (CASA, "¿Vives sola?", "Yow rekk yaa dëkk?"),
    (CASA, "¿Cuántas habitaciones tiene tu piso?", "Ñaata néeg la sa kër am?"),
    (CASA, "¿Cómo es tu piso?", "Nan la sa kër mel?"),
    (CASA, "Mi piso es muy grande", "Sama kër rëy na lool"),
    (CASA, "¿Quieres venir a mi casa?", "Ndax bëgg nga ñëw sama kër?"),
    (CASA, "Puedes venir cuando quieras", "Mën nga ñëw saa yoo bëggee"),
    (CASA, "Está muy cerca de aquí", "Jege na fii lool"),

    # ===================== Lugares y direcciones =======================
    (LUGARES, "Aeropuerto", "ayropoor"),
    (LUGARES, "Ayuntamiento", "meeri"),
    (LUGARES, "Banco", "bànk"),
    (LUGARES, "Hospital", "opitaal"),
    (LUGARES, "Mezquita", "jàkka"),
    (LUGARES, "Iglesia", "igiliis"),
    (LUGARES, "Cine", "sinemaa"),
    (LUGARES, "Estación de tren", "gaar"),
    (LUGARES, "Gasolinera", "estasiyoŋ"),
    (LUGARES, "Mercado", "marse"),
    (LUGARES, "Oficina de correos", "post"),
    (LUGARES, "Parada de autobús", "arebiis"),
    (LUGARES, "Parque", "sardeŋ"),
    (LUGARES, "Puerto", "poor"),
    (LUGARES, "Calle", "mbedd"),
    (LUGARES, "Carretera", "tali bu mag"),
    (LUGARES, "Supermercado", "ipeermarse"),
    (LUGARES, "Estación de metro", "gaaru metoró"),
    (LUGARES, "Farmacia", "farmasi"),
    (LUGARES, "Restaurante", "restoraŋ"),
    (LUGARES, "Tienda", "bitig"),
    (LUGARES, "A la derecha", "ci ndeyjoor"),
    (LUGARES, "A la izquierda", "ci càmmooñ"),
    (LUGARES, "Recto / Sigue recto", "Jubalal"),
    (LUGARES, "Gira (a la derecha/izquierda)", "Jàddal"),
    (LUGARES, "Cruza", "Jéggil"),
    (LUGARES, "Toma / Coge", "Jëlal"),
    (LUGARES, "Cerca", "jege"),
    (LUGARES, "Lejos", "sori"),
    (LUGARES, "Aquí", "fii"),
    (LUGARES, "Allí", "foofu"),
    (LUGARES, "Encima de", "ci kaw"),
    (LUGARES, "Debajo de", "ci suuf"),
    (LUGARES, "Delante de", "ci kanam"),
    (LUGARES, "Detrás de", "ci ginaaw"),
    (LUGARES, "Al lado de", "ci wetu"),
    (LUGARES, "Dentro de", "ci biir"),
    (LUGARES, "Entre", "ci diggante"),
    (LUGARES, "Por favor, ¿sabes dónde está la calle...?", "Nga baal ma, fan la mbedd ... nekk?"),
    (LUGARES, "Lo siento, no lo sé", "Maa ngi jéglu de, xamuma ko"),
    (LUGARES, "¿Hay un banco por aquí?", "Ndax ab bànk am na ci boor yii?"),
    (LUGARES, "¿Está muy lejos?", "Mbaa du dafa sori lool?"),
    (LUGARES, "¿Sabes dónde hay una parada de autobuses?", "Xam nga fan moo am arebiis?"),
    (LUGARES, "¿Sabe usted dónde hay una estación de metro?", "Xamuloo fan moo am gaaru metoró?"),
    (LUGARES, "Muchas gracias, es usted muy amable", "Jërëjëf waay, ku baax nga"),
    (LUGARES, "No hay de qué", "Amul benn solo"),
    (LUGARES, "Querría enviar este paquete a Senegal", "Damaa bëggoon a yónnee kóli bii Senegaal"),

    # ===================== Transporte ==================================
    (TRANSPORTE, "Coche", "oto"),
    (TRANSPORTE, "Taxi", "taksi"),
    (TRANSPORTE, "Autobús", "biis"),
    (TRANSPORTE, "Avión", "abiyoŋ"),
    (TRANSPORTE, "Camión", "kamiyoŋ"),
    (TRANSPORTE, "Metro", "metoró"),
    (TRANSPORTE, "Coger un taxi", "jël taksi"),
    (TRANSPORTE, "¿Adónde va usted?", "Fan ngeen di dem?"),
    (TRANSPORTE, "Voy a la plaza de España, número 7", "Maa ngi dem ci palaasu Espaañ, nimero 7"),
    (TRANSPORTE, "¿Cuánto tardaremos en llegar?", "Ci ñaata minit lañu fay yegg?"),
    (TRANSPORTE, "Llegaremos en unos diez minutos", "Ñu ngi egg ci fukki minit rekk"),
    (TRANSPORTE, "¿Cuánto tardas andando?", "Ci ñaata minit nga koy dox?"),
    (TRANSPORTE, "Sólo veinte minutos", "Ci ñaar-fukki minit rekk"),

    # ===================== Compras y dinero ============================
    (COMPRAS, "Comprar", "jënd"),
    (COMPRAS, "Vender", "jaay"),
    (COMPRAS, "Dinero", "xaalis"),
    (COMPRAS, "¿Cuánto cuesta? / ¿Cuánto es?", "Ñaata la?"),
    (COMPRAS, "¿Cuánto cuestan?", "Ñaata lañuy jar?"),
    (COMPRAS, "Es caro", "dafa seer"),
    (COMPRAS, "No son caras / No es caro", "Seeruñu de"),
    (COMPRAS, "Diez euros", "Fukki ëró"),
    (COMPRAS, "Vale. Me las llevo", "Baax na. Jël naa leen"),
    (COMPRAS, "¿Desea algo más?", "Bëgguloo leneen?"),
    (COMPRAS, "No, nada más, gracias", "Déedéet, bëgguma leneen, jërëjëf"),
    (COMPRAS, "Cóbreme, por favor", "Fayyeeku ma"),
    (COMPRAS, "¿Qué desea?", "Lan ngeen bëggoon?"),
    (COMPRAS, "Color", "kulëer"),
    (COMPRAS, "¿De qué color la quiere?", "Ban kulëer nga bëgg?"),
    (COMPRAS, "La quiero de color azul", "Kuloor bu buló laa bëgg"),
    (COMPRAS, "Talla", "taay"),
    (COMPRAS, "¿Cuál es su talla?", "Lan mooy sa taay?"),
    (COMPRAS, "Quiero comprar una camisa", "Damaa bëggoon a jënd ab simis"),
    (COMPRAS, "Estoy buscando unos zapatos", "Ay dàll laa bëgg"),
    (COMPRAS, "Póngame un kilo de naranjas", "Jaay ma benn kiló oraans"),
    (COMPRAS, "¿Tiene...?", "Ndax am nga...?"),
    (COMPRAS, "¿Tiene sandía?", "Ndax am nga xaal?"),
    (COMPRAS, "Lo siento, no me quedan", "Jégal ma de, desewuma ci dara"),
    (COMPRAS, "¿Puedo ver esas gafas?", "Ndax mën naa xool linet yii?"),
    (COMPRAS, "¿Me los puedo probar?", "Mën naa leen a natt?"),

    # ===================== Comida y bebida =============================
    (COMIDA, "Comer", "lekk"),
    (COMIDA, "Beber", "naan"),
    (COMIDA, "Agua", "ndox"),
    (COMIDA, "Agua natural (no fría)", "ndox mu seddul"),
    (COMIDA, "Pan", "mburu"),
    (COMIDA, "Arroz", "ceeb"),
    (COMIDA, "Pollo", "ginaar"),
    (COMIDA, "Carne", "yàpp"),
    (COMIDA, "Pescado", "jën"),
    (COMIDA, "Sal", "xorom"),
    (COMIDA, "Azúcar", "suukër"),
    (COMIDA, "Aceite", "diwlin"),
    (COMIDA, "Leche", "meew"),
    (COMIDA, "Huevo", "nen"),
    (COMIDA, "Café", "kafe"),
    (COMIDA, "Té", "attaaya"),
    (COMIDA, "Queso", "formaas"),
    (COMIDA, "Mantequilla", "bëer"),
    (COMIDA, "Naranja", "oraans"),
    (COMIDA, "Plátano", "banaana"),
    (COMIDA, "Manzana", "pom"),
    (COMIDA, "Tomate", "tamaate"),
    (COMIDA, "Patata", "pombiteer"),
    (COMIDA, "Cerveza", "beer"),
    (COMIDA, "Fruta", "firwi"),
    (COMIDA, "Tengo hambre", "Damaa xiif"),
    (COMIDA, "Tengo sed", "Dama mar a naan"),
    (COMIDA, "¿Qué quieres beber?", "Lan nga bëgg a naan?"),
    (COMIDA, "¿Qué van a tomar?", "Lan ngeen di jël?"),
    (COMIDA, "¿Y para beber?", "Lan ngeen di naan nak?"),
    (COMIDA, "¿Qué tomarán de postre?", "Lan ngeen di jël deseer?"),
    (COMIDA, "Picante", "saf kaani"),
    (COMIDA, "Dulce", "saf suukër"),
    (COMIDA, "¡Qué rico! / ¡Está bueno!", "A ka neex!"),
    (COMIDA, "Está muy bueno", "Neex na lool"),
    (COMIDA, "¿Te gusta?", "Neex na ci yow?"),
    (COMIDA, "Me gustaría invitarte a comer a mi casa", "Dama laa bëggoon a inwite añ sama kër"),
    (COMIDA, "¡Que aproveche!", "Lekkal bu baax"),
    (COMIDA, "¿Nos trae un poco de pan, por favor?", "Indil ñu tuuti mburu, nga baal ñu?"),
    (COMIDA, "¡Camarero! ¡Por favor!", "Serwëer! Nga baal ma!"),
    (COMIDA, "¿Qué nos recomienda usted?", "Lan nga ñuy digël?"),
    (COMIDA, "La cuenta, por favor", "Ñaata la?"),
    (COMIDA, "Camarero / Camarera", "serwëer"),
    (COMIDA, "Plato", "palaat"),
    (COMIDA, "Vaso", "kaas"),
    (COMIDA, "Cuchara", "kudd"),
    (COMIDA, "Cuchillo", "paaka"),
    (COMIDA, "Café con leche", "kafe ak meew"),

    # ===================== Salud y emergencias =========================
    (SALUD, "Médico / Doctor", "doktoor"),
    (SALUD, "Hospital / Ambulatorio", "dispanseer"),
    (SALUD, "¿Qué te pasa?", "Lan moo la dal?"),
    (SALUD, "¿No te encuentras bien?", "Xanaa sa yaram neexul?"),
    (SALUD, "No me encuentro bien", "Sama yaram neexul"),
    (SALUD, "Me duele la barriga", "Sama biir dafay metti"),
    (SALUD, "Me duele muchísimo la barriga", "Sama biir dafay metti lool"),
    (SALUD, "Me duele la cabeza", "Sama bopp dafay metti"),
    (SALUD, "Me duelen el pecho y el cuello", "Sama dënn ak sama put gi dañuy metti"),
    (SALUD, "Me duele todo", "Lépp a may metti"),
    (SALUD, "¿Cómo te encuentras hoy?", "Naka yaram wi téy?"),
    (SALUD, "Tengo fiebre", "Sama yaram dafa tàng"),
    (SALUD, "Estoy enfermo", "Damaa feebar"),
    (SALUD, "Estoy cansado", "Damaa sonn"),
    (SALUD, "Tengo gripe", "Am naa girip"),
    (SALUD, "Tengo tos", "Damay sëxët"),
    (SALUD, "Tengo frío", "Damaa sedd"),
    (SALUD, "Tengo calor", "Damaa tàng"),
    (SALUD, "Tengo diarrea", "Am naa biir buy daw"),
    (SALUD, "Estoy mareado", "Damay miir"),
    (SALUD, "Me he caído", "Damaa daanu"),
    (SALUD, "Descansar", "noppaliku"),
    (SALUD, "Pastillas / Medicina", "doom"),
    (SALUD, "Respira hondo", "Noyyil bu baax"),
    (SALUD, "Quería pedir cita con mi médico", "Damaa bëggoon a wut randewuu ak sama doktoor"),
    (SALUD, "¿Quieres un vaso de leche?", "Ndax bëgg nga kaasu meew?"),
    (SALUD, "Cuerpo", "yaram"),
    (SALUD, "Cabeza", "bopp"),
    (SALUD, "Ojo", "bët"),
    (SALUD, "Mano", "loxo"),
    (SALUD, "Brazo", "loxo"),
    (SALUD, "Pie / Pierna", "tànk"),
    (SALUD, "Rodilla", "óom"),
    (SALUD, "Boca", "gemmiñ"),
    (SALUD, "Nariz", "bakkan"),
    (SALUD, "Oreja", "nopp"),
    (SALUD, "Diente", "bëñ"),
    (SALUD, "Garganta", "put"),
    (SALUD, "Cuello", "baat"),
    (SALUD, "Espalda", "diggu ginaaw"),
    (SALUD, "Pecho", "dënn"),
    (SALUD, "Vientre / Barriga", "biir"),
    (SALUD, "Cara", "kanam"),
    (SALUD, "Dedos", "baaraam"),
    (SALUD, "Pelo", "karaw"),

    # ===================== Trabajo y oficios ===========================
    (TRABAJO, "Trabajo", "liggéey"),
    (TRABAJO, "¿Dónde trabajas ahora?", "Fan ngay liggéeye leegi?"),
    (TRABAJO, "¿De qué trabajas?", "Lan ngay liggéey?"),
    (TRABAJO, "¿Tienes trabajo?", "Mbaa am nga liggéey?"),
    (TRABAJO, "No tengo, sigo buscando", "Maa ngi wut ba leegi"),
    (TRABAJO, "¿Te gusta tu trabajo?", "Mbaa sa liggéey neex na la?"),
    (TRABAJO, "¡Cuánto tiempo sin vernos!", "Gëj nañu gise!"),
    (TRABAJO, "Cocinero / Cocinera", "toggkat"),
    (TRABAJO, "Profesor / Profesora", "jàngalekat"),
    (TRABAJO, "Carpintero", "minise"),
    (TRABAJO, "Taxista", "dawalkatu taksi"),
    (TRABAJO, "Policía", "pólise"),
    (TRABAJO, "Pescador", "nappkat"),
    (TRABAJO, "Vendedor / Vendedora", "jaaykat"),
    (TRABAJO, "Panadero", "mbulaŋse"),
    (TRABAJO, "Mecánico", "mekaniseŋ"),
    (TRABAJO, "Electricista", "elektirisiyeŋ"),

    # ===================== Verbos útiles ===============================
    (VERBOS, "Querer", "bëgg"),
    (VERBOS, "Saber", "xam"),
    (VERBOS, "Poder", "mën"),
    (VERBOS, "Ir", "dem"),
    (VERBOS, "Venir", "ñëw"),
    (VERBOS, "Hablar", "wax"),
    (VERBOS, "Ver / Mirar", "xool"),
    (VERBOS, "Ir de compras", "jëndi"),
    (VERBOS, "Cocinar", "togg"),
    (VERBOS, "Lavar la ropa", "fóot"),
    (VERBOS, "Levantarse", "jóg"),
    (VERBOS, "Dormir", "nelaw"),
    (VERBOS, "Ducharse", "sangu"),
    (VERBOS, "Caminar", "dox"),
    (VERBOS, "Pasear", "doxantu"),
    (VERBOS, "Estudiar / Leer", "jàng"),
    (VERBOS, "Trabajar", "liggéey"),
]


def construir_entradas():
    """Construye la lista de entradas evitando claves 'es' repetidas."""
    entradas = []
    vistos = set()
    for cat, es, wo in DATOS:
        if es in vistos:
            continue
        vistos.add(es)
        pron = aproximar_pronunciacion(wo)
        entradas.append({
            "cat": cat,
            "es": es,
            "wo": wo,
            "pron": pron,
            "fon": fonetica_voz(pron),  # silabeado para el TTS
        })
    return entradas


def main():
    entradas = construir_entradas()
    ruta = "app/src/main/assets/diccionario.json"
    with open(ruta, "w", encoding="utf-8") as f:
        json.dump(entradas, f, ensure_ascii=False, indent=2)

    con_wo = sum(1 for e in entradas if e["wo"])
    cats = sorted({e["cat"] for e in entradas})
    print(f"Generadas {len(entradas)} entradas ({con_wo} con wolof) -> {ruta}")
    print(f"Categorías ({len(cats)}): " + ", ".join(cats))


if __name__ == "__main__":
    main()
