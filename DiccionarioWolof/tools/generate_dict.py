# -*- coding: utf-8 -*-
"""
Genera assets/diccionario.json para la app "Diccionario Wolof".

Los pares español/wolof provienen del libro de conversación
"Dímelo en wolof" (Mahu Thiam Fall, oozebap / les éditions madina, 2012),
aportado como fuente. No se inventan traducciones: cuando una palabra wolof
no aparece en la fuente o no es segura, se deja el campo "wo" vacío.

El campo "pron" (pronunciación aproximada para hispanohablantes) se calcula
de forma automática a partir de las reglas fonéticas del alfabeto wolof
descritas en el propio libro:
  c -> "ch"      j -> "y"       x -> "j" (jota)    ŋ -> "ng"
  q -> "k"       y -> "y/ll"    ë/é -> "e"         à -> "a"   ó -> "o"
  g siempre dura (se escribe "gu" ante e/i para mantener el sonido)
  las letras dobles son sonidos largos (se simplifican para el lector español)
"""

import json
import re
import unicodedata

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
        # 1) "g" dura: ante vocal frontal se escribe "gu" para que el lector
        #    español no la lea como jota (gato, pero gui/gue).
        s = re.sub(r"g(?=[" + FRONT_VOWELS + r"])", "gu", s)
        # 2) Vocales específicas del wolof.
        s = (s.replace("à", "a").replace("é", "e")
              .replace("ë", "e").replace("ó", "o"))
        # 3) Consonantes específicas (el orden importa: j antes que x).
        s = s.replace("j", "y")    # j wolof = "y" + vocal
        s = s.replace("x", "j")    # x wolof = jota española
        s = s.replace("c", "ch")   # c wolof = "ch"
        s = s.replace("ŋ", "ng")   # ŋ wolof = "ng" final inglés
        s = s.replace("q", "k")    # q wolof ~ "g" gutural (aprox. "k")
        # 4) Las letras dobles son sonidos largos: se simplifican.
        s = re.sub(r"([a-zñ])\1", r"\1", s)
        s = re.sub(r"([a-zñ])\1", r"\1", s)  # segunda pasada por seguridad
        return s

    # Procesamos palabra a palabra conservando los separadores.
    partes = re.split(r"([ \-/])", wo)
    resultado = "".join(conv(p) if p.strip() and p not in " -/" else p
                        for p in partes)
    # Primera letra en mayúscula, como en los ejemplos del enunciado.
    return resultado[:1].upper() + resultado[1:] if resultado else ""


# ---------------------------------------------------------------------------
# Datos (español, wolof) tomados del libro "Dímelo en wolof".
# wolof == "" -> traducción no disponible / no confirmada en la fuente.
# ---------------------------------------------------------------------------

PARES = [
    # --- Saludos y expresiones habituales -------------------------------
    ("¡Hola! / Buenos días (saludo)", "Salaamaalekum"),
    ("Respuesta al saludo (y contigo)", "Maalekum salaam"),
    ("¿Qué tal? / ¿Cómo estás?", "Nan nga def?"),
    ("¿Cómo estás?", "Naka nga def?"),
    ("¿Cómo estáis?", "Nan ngeen def?"),
    ("Estoy bien", "Maa ngi fi rekk"),
    ("Bien, gracias a Dios", "Maa ngi sant"),
    ("Bien, en paz", "Jàmm rekk"),
    ("Gracias", "Jërëjëf"),
    ("Muchas gracias", "Jërëjëf waay"),
    ("De nada", "Amul benn solo"),
    ("De nada / No es nada", "Du dara"),
    ("Sí", "Waaw"),
    ("No", "Déedéet"),
    ("Perdona / Perdóname", "Baal ma"),
    ("Por favor / Disculpa", "Nga baal ma"),
    ("No importa", "Amul benn solo"),
    ("Hasta mañana", "Ba suba"),
    ("Hasta luego / Hasta otra", "Ba beneen"),
    ("¡Que paséis buena noche!", "Fanaan leen ak jàmm"),
    ("¿Has dormido bien?", "Nelaw nga bu baax?"),
    ("He dormido bien", "Nelaw naa bu baax"),
    ("¿Cómo has pasado el día?", "Nan nga yendoo?"),
    ("Dale recuerdos a la familia", "Nuyul ma njaboot ga"),
    ("Encantado de conocerte", "Kontaan naa lool ci xamante ak yow"),
    ("Yo también, encantado", "Man tamit kontaan naa ci"),
    ("¡Que aproveche!", "Lekkal bu baax"),
    ("Estás en tu casa", "Fii sa kër la"),
    ("Espera (un momento)", "Xaaral"),
    ("Esperad un momento", "Xaar leen tuuti"),
    ("Vale / De acuerdo / Está bien", "Baax na"),
    ("¡Diga! / ¿Dígame? (al teléfono)", "Alóo"),
    ("¿Quién es? / ¿Quién llama?", "Yow yaay kan?"),
    ("Te llamaré", "Dinaa la woo"),
    ("Bienvenido", ""),

    # --- Presentarse / información personal ------------------------------
    ("¿Cómo te llamas?", "Noo tuddu?"),
    ("Me llamo...", "Maa ngi tuddu..."),
    ("¿De dónde eres?", "Waa fan nga?"),
    ("Soy español", "Waa Espaañ laa"),
    ("Soy senegalés", "Waa Senegaal laa"),
    ("Soy de Dakar", "Waa Ndakaaru laa"),
    ("¿Dónde vives?", "Fan nga dëkk?"),
    ("Vivo en Barcelona", "Maa ngi dëkk Barcelona"),
    ("¿En qué barrio vives?", "Ban koñ nga dëkk?"),
    ("¿Cuántos años tienes?", "Ñaata at nga am?"),
    ("Tengo veinte años", "Am naa ñaar-fukki at"),
    ("¿Tienes hijos?", "Ndax am nga doom?"),
    ("¿A qué te dedicas?", "Ci lan ngay yëngëtu?"),
    ("¿Estudias o trabajas?", "Dangay jàng wala dangay liggéey?"),
    ("¿Tienes teléfono móvil?", "Ndax yor nga portaabal?"),
    ("¿Cuál es tu número de teléfono?", "Lan mooy sa nimero telefon?"),
    ("¿Tienes marido?", "Am nga jëkkër?"),
    ("¿Tienes esposa?", "Am nga jabar?"),
    ("Soy soltero", "Selibateer laa"),
    ("¿Me puedes dar tu teléfono?", "Ndax mën nga ma jox sa telefon?"),
    ("¿Es tu primer viaje a Senegal?", "Bii mooy sa yoon bu njëkk ci Senegaal?"),
    ("Estoy muy contento de venir", "Kontaan naa lool ci ñëw"),

    # --- Números --------------------------------------------------------
    ("Cero", "tus"),
    ("Uno", "benn"),
    ("Dos", "ñaar"),
    ("Tres", "ñett"),
    ("Cuatro", "ñent"),
    ("Cinco", "juróom"),
    ("Seis", "juróom benn"),
    ("Siete", "juróom ñaar"),
    ("Ocho", "juróom ñett"),
    ("Nueve", "juróom ñent"),
    ("Diez", "fukk"),
    ("Once", "fukk ak benn"),
    ("Doce", "fukk ak ñaar"),
    ("Trece", "fukk ak ñett"),
    ("Catorce", "fukk ak ñent"),
    ("Quince", "fukk ak juróom"),
    ("Dieciséis", "fukk ak juróom benn"),
    ("Diecisiete", "fukk ak juróom ñaar"),
    ("Dieciocho", "fukk ak juróom ñett"),
    ("Diecinueve", "fukk ak juróom ñent"),
    ("Veinte", "ñaar fukk"),
    ("Treinta", "fan weer"),
    ("Cuarenta", "ñent fukk"),
    ("Cincuenta", "juróom fukk"),
    ("Sesenta", "juróom benn fukk"),
    ("Setenta", "juróom ñaar fukk"),
    ("Ochenta", "juróom ñett fukk"),
    ("Noventa", "juróom ñent fukk"),
    ("Cien", "téeméer"),
    ("Doscientos", "ñaari téeméer"),
    ("Quinientos", "juróomi téeméer"),
    ("Mil", "junni"),
    ("Un millón", "milyoŋ"),

    # --- Días y tiempo --------------------------------------------------
    ("Lunes", "Altine"),
    ("Martes", "Talaata"),
    ("Miércoles", "Àllarba"),
    ("Jueves", "Alxames"),
    ("Viernes", "Àjjuma"),
    ("Sábado", "Gaawu"),
    ("Domingo", "Dibéer"),
    ("Día", "bés"),
    ("Hoy", "Tey"),
    ("Mañana (el día siguiente)", "Ëllëg"),
    ("Pasado mañana", "Ginnaaw suba"),
    ("Ayer", "Démb"),
    ("Antes de ayer", "Bërki-démb"),
    ("Semana", "Ayubés"),
    ("Mes", "Weer"),
    ("Año", "At"),
    ("Por la mañana", "Ci suba"),
    ("Por la tarde", "Ci ngoon"),
    ("Por la noche", "Ci guddi"),
    ("¿Qué día es hoy?", "Tey ban bés la?"),
    ("¿Qué hora es? / ¿Tienes hora?", "Ban waxtu moo jot?"),
    ("A las ocho", "Juróom ñetti waxtu"),
    ("Feliz año nuevo", "Dewénati"),

    # --- Familia y personas ---------------------------------------------
    ("Padre", "pàppa"),
    ("Madre", "yaay"),
    ("Hijo / Hija", "doom"),
    ("Hijo mayor", "taaw"),
    ("Hijo menor", "caat"),
    ("Hermano/a mayor", "mag"),
    ("Hermano/a menor", "rakk"),
    ("Marido / Esposo", "jëkkër"),
    ("Esposa / Mujer", "jabar"),
    ("Abuelo / Abuela", "maam"),
    ("Nieto / Nieta", "sët"),
    ("Tío", "nijaay"),
    ("Tía", "tànta"),
    ("Sobrino / Sobrina", "jarbaat"),
    ("Primo / Prima (materno)", "doomu tànta"),
    ("Amigo / Amiga", "xarit"),
    ("Vecino / Vecina", "dëkkandoo"),
    ("Hombre", "góor"),
    ("Mujer", "jigeen"),
    ("Niño / Niña", "xale"),
    ("Joven", "ndaw"),
    ("Familia", "njaboot"),

    # --- Descripciones --------------------------------------------------
    ("Es alto", "dafa njool"),
    ("Es bajo (de estatura)", "dafa gàtt"),
    ("Es guapo/a", "dafa rafet"),
    ("Es feo/a", "dafa ñaaw"),
    ("Es amable", "dafa baax"),
    ("Eres simpático/a", "dangaa baax"),
    ("Soy inteligente", "damaa am xel"),
    ("Es grande", "dafa rëy"),
    ("Soy joven", "xale laa"),
    ("Eres viejo/a", "màgget nga"),
    ("Estoy contento/a", "kontaan naa"),

    # --- Casa y alojamiento ---------------------------------------------
    ("Casa", "kër"),
    ("Habitación / Dormitorio", "néeg"),
    ("Salón", "saal"),
    ("Cocina", "waañ"),
    ("Cuarto de baño", "wanag"),
    ("Ducha", "sangukaay"),
    ("Cama", "lal"),
    ("Mesa", "taabal"),
    ("Silla", "siis"),
    ("Puerta", "bunt"),
    ("Ventana", "polonteer"),
    ("Llave", "caabi"),
    ("Apartamento", "apartamaŋ"),
    ("Hotel", "otel"),
    ("Ascensor", "asaansëer"),
    ("Frigorífico", "filisideer"),
    ("Armario", "armoor"),
    ("Lámpara", "làmp"),
    ("Espejo", "seetu"),
    ("Alfombra", "moket"),
    ("Terraza", "teeraas"),
    ("¿Vives en una casa o en un apartamento?", "Ci kër nga dëkk wala ci apartamaŋ?"),
    ("¿Cuántas habitaciones tiene?", "Ñaata néeg la am?"),

    # --- Lugares y edificios --------------------------------------------
    ("Aeropuerto", "ayropoor"),
    ("Ayuntamiento", "meeri"),
    ("Banco", "bànk"),
    ("Hospital", "opitaal"),
    ("Mezquita", "jàkka"),
    ("Iglesia", "igiliis"),
    ("Cine", "sinemaa"),
    ("Estación de tren", "gaar"),
    ("Gasolinera", "estasiyoŋ"),
    ("Mercado", "marse"),
    ("Oficina de correos", "post"),
    ("Parada de autobús", "arebiis"),
    ("Parque", "sardeŋ"),
    ("Puerto", "poor"),
    ("Calle", "mbedd"),
    ("Carretera", "tali bu mag"),
    ("Supermercado", "ipeermarse"),
    ("Estación de metro", "gaaru metoró"),
    ("Farmacia", "farmasi"),
    ("Restaurante", "restoraŋ"),
    ("Tienda", "bitig"),

    # --- Transporte y direcciones ---------------------------------------
    ("Coche", "oto"),
    ("Taxi", "taksi"),
    ("Autobús", "biis"),
    ("Avión", "abiyoŋ"),
    ("Camión", "kamiyoŋ"),
    ("Metro", "metoró"),
    ("A la derecha", "ci ndeyjoor"),
    ("A la izquierda", "ci càmmooñ"),
    ("Recto / Sigue recto", "Jubalal"),
    ("Gira (a la derecha/izquierda)", "Jàddal"),
    ("Cruza", "Jéggil"),
    ("Cerca", "jege"),
    ("Lejos", "sori"),
    ("Aquí", "fii"),
    ("Allí", "foofu"),
    ("Encima de", "ci kaw"),
    ("Debajo de", "ci suuf"),
    ("Delante de", "ci kanam"),
    ("Detrás de", "ci ginaaw"),
    ("Al lado de", "ci wetu"),
    ("Dentro de", "ci biir"),
    ("Entre", "ci diggante"),
    ("¿Dónde está la calle...?", "Fan la mbedd ... nekk?"),
    ("¿Está lejos?", "Mbaa du dafa sori?"),
    ("Coger un taxi", "jël taksi"),
    ("¿Adónde va usted?", "Fan ngeen di dem?"),
    ("Voy a...", "Maa ngi dem ci..."),
    ("¿Cuánto tardaremos en llegar?", "Ci ñaata minit lañu fay yegg?"),

    # --- Comprar, vender, mercado y dinero ------------------------------
    ("Comprar", "jënd"),
    ("Vender", "jaay"),
    ("Dinero", "xaalis"),
    ("¿Cuánto cuesta? / ¿Cuánto es?", "Ñaata la?"),
    ("¿Cuánto cuestan?", "Ñaata lañuy jar?"),
    ("Es caro", "dafa seer"),
    ("No es caro / barato", "seeruñu"),
    ("Me lo llevo", "Jël naa ko"),
    ("¿Desea algo más?", "Bëgguloo leneen?"),
    ("Nada más, gracias", "Bëgguma leneen, jërëjëf"),
    ("Cóbreme, por favor", "Fayyeeku ma"),
    ("¿Qué desea?", "Lan ngeen bëggoon?"),
    ("Color", "kulëer"),
    ("Talla", "taay"),
    ("Quiero comprar una camisa", "Damaa bëggoon a jënd ab simis"),
    ("Póngame un kilo de naranjas", "Jaay ma benn kiló oraans"),
    ("¿Tiene...?", "Ndax am nga...?"),
    ("¿Puedo ver esas gafas?", "Ndax mën naa xool linet yii?"),
    ("¿Me los puedo probar?", "Mën naa leen a natt?"),

    # --- Comida y bebida ------------------------------------------------
    ("Comer", "lekk"),
    ("Beber", "naan"),
    ("Agua", "ndox"),
    ("Pan", "mburu"),
    ("Arroz", "ceeb"),
    ("Pollo", "ginaar"),
    ("Carne", "yàpp"),
    ("Pescado", "jën"),
    ("Sal", "xorom"),
    ("Azúcar", "suukër"),
    ("Aceite", "diwlin"),
    ("Leche", "meew"),
    ("Huevo", "nen"),
    ("Café", "kafe"),
    ("Té", "attaaya"),
    ("Queso", "formaas"),
    ("Mantequilla", "bëer"),
    ("Naranja", "oraans"),
    ("Plátano", "banaana"),
    ("Manzana", "pom"),
    ("Tomate", "tamaate"),
    ("Patata", "pombiteer"),
    ("Cerveza", "beer"),
    ("Fruta", "firwi"),
    ("Tengo hambre", "Damaa xiif"),
    ("Tengo sed", "Dama mar a naan"),
    ("¿Qué quieres beber?", "Lan nga bëgg a naan?"),
    ("Picante", "saf kaani"),
    ("Dulce", "saf suukër"),
    ("¡Qué rico! / ¡Está bueno!", "A ka neex!"),
    ("Está muy bueno", "Neex na lool"),
    ("La cuenta, por favor", "Ñaata la?"),
    ("Camarero / Camarera", "serwëer"),
    ("Plato", "palaat"),
    ("Vaso", "kaas"),
    ("Cuchara", "kudd"),
    ("Cuchillo", "paaka"),
    ("Café con leche", "kafe ak meew"),

    # --- Salud y emergencias --------------------------------------------
    ("Médico / Doctor", "doktoor"),
    ("Hospital / Ambulatorio", "dispanseer"),
    ("Me duele la barriga", "Sama biir dafay metti"),
    ("Me duele la cabeza", "Sama bopp dafay metti"),
    ("¿Qué te pasa?", "Lan moo la dal?"),
    ("No me encuentro bien", "Sama yaram neexul"),
    ("Tengo fiebre", "Sama yaram dafa tàng"),
    ("Estoy enfermo", "Damaa feebar"),
    ("Estoy cansado", "Damaa sonn"),
    ("Tengo gripe", "Am naa girip"),
    ("Tengo tos", "Damay sëxët"),
    ("Tengo frío", "Damaa sedd"),
    ("Tengo calor", "Damaa tàng"),
    ("Tengo diarrea", "Am naa biir buy daw"),
    ("Estoy mareado", "Damay miir"),
    ("Me he caído", "Damaa daanu"),
    ("Descansar", "noppaliku"),
    ("Pastillas / Medicina", "doom"),
    ("Respira hondo", "Noyyil bu baax"),
    ("Cuerpo", "yaram"),
    ("Cabeza", "bopp"),
    ("Ojo", "bët"),
    ("Mano", "loxo"),
    ("Pie / Pierna", "tànk"),
    ("Boca", "gemmiñ"),
    ("Nariz", "bakkan"),
    ("Oreja", "nopp"),
    ("Diente", "bëñ"),
    ("Garganta", "put"),
    ("Espalda", "diggu ginaaw"),

    # --- Trabajo y oficios ----------------------------------------------
    ("Trabajo", "liggéey"),
    ("¿Dónde trabajas?", "Fan ngay liggéeye?"),
    ("¿De qué trabajas?", "Lan ngay liggéey?"),
    ("¿Tienes trabajo?", "Am nga liggéey?"),
    ("Cocinero / Cocinera", "toggkat"),
    ("Profesor / Profesora", "jàngalekat"),
    ("Carpintero", "minise"),
    ("Taxista", "dawalkatu taksi"),
    ("Policía", "pólise"),
    ("Pescador", "nappkat"),
    ("Vendedor / Vendedora", "jaaykat"),
    ("Panadero", "mbulaŋse"),
    ("Mecánico", "mekaniseŋ"),
    ("Electricista", "elektirisiyeŋ"),

    # --- Verbos y acciones cotidianas -----------------------------------
    ("Querer", "bëgg"),
    ("Saber", "xam"),
    ("Poder", "mën"),
    ("Ir", "dem"),
    ("Venir", "ñëw"),
    ("Hablar", "wax"),
    ("Ver / Mirar", "xool"),
    ("Ir de compras", "jëndi"),
    ("Cocinar", "togg"),
    ("Lavar la ropa", "fóot"),
    ("Levantarse", "jóg"),
    ("Dormir", "nelaw"),
    ("Ducharse", "sangu"),
    ("Caminar", "dox"),
    ("Pasear", "doxantu"),
    ("Estudiar / Leer", "jàng"),
    ("Trabajar", "liggéey"),
]


def main():
    entradas = []
    for es, wo in PARES:
        entradas.append({
            "es": es,
            "wo": wo,
            "pron": aproximar_pronunciacion(wo),
        })

    ruta = "app/src/main/assets/diccionario.json"
    with open(ruta, "w", encoding="utf-8") as f:
        json.dump(entradas, f, ensure_ascii=False, indent=2)

    con_wo = sum(1 for e in entradas if e["wo"])
    print(f"Generadas {len(entradas)} entradas ({con_wo} con wolof) -> {ruta}")
    # Muestra de control
    for e in entradas[:6]:
        print(e)


if __name__ == "__main__":
    main()
