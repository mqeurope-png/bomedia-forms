package com.bomedia.diccionariowolof.data

/**
 * Una entrada del diccionario.
 *
 * @property cat  categoría temática (saludos, comida, salud…).
 * @property es   palabra o frase en español.
 * @property wo   traducción en wolof (puede estar vacía si no se conoce).
 * @property pron pronunciación aproximada en grafía española (para mostrar).
 * @property fon  transcripción silabeada de la pronunciación, para el motor de
 *                voz (TTS): "Jërëjëf" -> "ye-re-yef". Suena más claro y lento.
 */
data class Entry(
    val cat: String,
    val es: String,
    val wo: String,
    val pron: String,
    val fon: String,
) {
    /** Texto que debe leer el TTS: prioriza la versión silabeada. */
    val speakable: String
        get() = fon.ifEmpty { pron.ifEmpty { wo } }
}
