package com.bomedia.diccionariowolof.data

/**
 * Una entrada del diccionario.
 *
 * @property cat   categoría temática (saludos, comida, salud…).
 * @property es    palabra o frase en español.
 * @property wo    traducción en wolof (puede estar vacía si no se conoce).
 * @property pron  pronunciación aproximada en grafía española (para mostrar).
 * @property fonEs transcripción silabeada para una voz ESPAÑOLA ("ye-re-yef").
 * @property fonFr transcripción silabeada para una voz FRANCESA ("dje-re-djef").
 */
data class Entry(
    val cat: String,
    val es: String,
    val wo: String,
    val pron: String,
    val fonEs: String,
    val fonFr: String,
) {
    /**
     * Texto que debe leer el TTS según el idioma de la voz elegida.
     * Para voz francesa usa la transcripción francesa; para el resto, la
     * española (que es la grafía más fiel a la pronunciación del libro).
     */
    fun speakable(voiceLanguage: String?): String {
        val fr = voiceLanguage?.startsWith("fr") == true
        val elegido = if (fr) fonFr else fonEs
        return elegido.ifEmpty { fonEs.ifEmpty { pron.ifEmpty { wo } } }
    }
}
