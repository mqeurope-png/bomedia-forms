package com.bomedia.diccionariowolof.data

import android.content.Context
import org.json.JSONArray
import java.text.Normalizer

/**
 * Carga y mantiene en memoria el diccionario.
 *
 * El archivo `assets/diccionario.json` se lee UNA SOLA VEZ (la primera vez que
 * se accede a [entries]); a partir de ahí las búsquedas se hacen sobre la lista
 * ya cargada, sin volver a tocar el disco. Para ampliar el diccionario basta
 * con sustituir ese archivo JSON.
 */
class DictionaryRepository(private val context: Context) {

    // Lista cargada de forma perezosa y cacheada (una sola lectura del JSON).
    val entries: List<Entry> by lazy { loadFromAssets() }

    // Texto de cada entrada ya normalizado, para acelerar las búsquedas.
    private val searchIndex: List<String> by lazy {
        entries.map { normalize("${it.es} ${it.wo} ${it.pron}") }
    }

    /** Lee y parsea `assets/diccionario.json`. */
    private fun loadFromAssets(): List<Entry> {
        val json = context.assets.open(ASSET_FILE).bufferedReader(Charsets.UTF_8)
            .use { it.readText() }

        val array = JSONArray(json)
        val result = ArrayList<Entry>(array.length())
        for (i in 0 until array.length()) {
            val obj = array.getJSONObject(i)
            result.add(
                Entry(
                    es = obj.optString("es"),
                    wo = obj.optString("wo"),
                    pron = obj.optString("pron"),
                )
            )
        }
        // Orden alfabético por la palabra en español para una lista estable.
        return result.sortedBy { normalize(it.es) }
    }

    /**
     * Devuelve las entradas que coinciden con [query].
     *
     * - Ignora mayúsculas/minúsculas y acentos.
     * - Busca a la vez en español, wolof y pronunciación.
     * - Admite coincidencias parciales (subcadena).
     *
     * Con la consulta vacía se devuelve el diccionario completo.
     */
    fun search(query: String): List<Entry> {
        val q = normalize(query)
        if (q.isEmpty()) return entries
        return entries.filterIndexed { index, _ -> searchIndex[index].contains(q) }
    }

    companion object {
        private const val ASSET_FILE = "diccionario.json"

        /**
         * Normaliza un texto para comparar: minúsculas, sin acentos ni signos
         * diacríticos y con las letras específicas del wolof convertidas a su
         * equivalente latino básico (ë/é→e, à→a, ó→o, ŋ→ng).
         */
        fun normalize(text: String): String {
            val lower = text.lowercase()
                .replace('à', 'a')
                .replace('é', 'e')
                .replace('ë', 'e')
                .replace('ó', 'o')
                .replace("ŋ", "ng")
            // Descompone y elimina las marcas diacríticas combinables.
            val decomposed = Normalizer.normalize(lower, Normalizer.Form.NFD)
            return decomposed.replace(DIACRITICS, "").trim()
        }

        private val DIACRITICS = "\\p{Mn}+".toRegex()
    }
}
