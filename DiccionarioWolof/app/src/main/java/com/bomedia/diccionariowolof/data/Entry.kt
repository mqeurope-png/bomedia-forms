package com.bomedia.diccionariowolof.data

/**
 * Una entrada del diccionario.
 *
 * @property es   palabra o frase en español.
 * @property wo   traducción en wolof (puede estar vacía si no se conoce).
 * @property pron pronunciación aproximada en grafía española.
 */
data class Entry(
    val es: String,
    val wo: String,
    val pron: String,
)
