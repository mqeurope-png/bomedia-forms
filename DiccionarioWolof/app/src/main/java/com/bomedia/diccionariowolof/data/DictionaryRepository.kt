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
    // Se incluye también la categoría para poder buscar por tema.
    private val searchIndex: List<String> by lazy {
        entries.map { normalize("${it.es} ${it.wo} ${it.pron} ${it.cat}") }
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
                    cat = obj.optString("cat"),
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

    /**
     * Búsqueda fonética aproximada para el reconocimiento de voz (offline).
     *
     * El reconocedor de voz devuelve una transcripción aproximada de lo que se
     * ha oído. Como no existe un reconocedor de wolof, comparamos esa cadena
     * con la **pronunciación** y el **wolof** de cada entrada usando una medida
     * de similitud (distancia de edición + coincidencia de palabras), y
     * devolvemos las entradas más parecidas.
     *
     * Es intencionadamente tolerante: prioriza ofrecer candidatos razonables
     * antes que exigir una coincidencia exacta.
     *
     * @return lista ordenada de mejores candidatos (vacía si nada se parece).
     */
    fun phoneticSearch(spoken: String, limit: Int = 15): List<Entry> {
        val target = normalize(spoken)
        if (target.isEmpty()) return emptyList()

        val scored = entries.mapNotNull { entry ->
            val candidates = listOfNotNull(
                normalize(entry.pron).ifEmpty { null },
                normalize(entry.wo).ifEmpty { null },
            )
            if (candidates.isEmpty()) return@mapNotNull null
            val score = candidates.maxOf { phoneticSimilarity(target, it) }
            if (score >= MIN_PHONETIC_SCORE) entry to score else null
        }
        return scored.sortedByDescending { it.second }.take(limit).map { it.first }
    }

    companion object {
        private const val ASSET_FILE = "diccionario.json"

        // Umbral mínimo de parecido (0..1) para considerar un candidato de voz.
        private const val MIN_PHONETIC_SCORE = 0.34

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

        /**
         * Similitud fonética entre dos cadenas ya normalizadas (rango 0..1).
         * Combina la similitud global y la mejor coincidencia palabra a palabra,
         * para que frases y palabras sueltas funcionen razonablemente.
         */
        private fun phoneticSimilarity(a: String, b: String): Double {
            val global = ratio(a, b)
            val wordsA = a.split(' ').filter { it.isNotBlank() }
            val wordsB = b.split(' ').filter { it.isNotBlank() }
            // Mejor parecido de cada palabra dicha con alguna palabra de la entrada.
            val perWord = if (wordsA.isEmpty() || wordsB.isEmpty()) 0.0
            else wordsA.map { wa -> wordsB.maxOf { wb -> ratio(wa, wb) } }.average()
            return maxOf(global, perWord)
        }

        /** Similitud basada en la distancia de Levenshtein (0..1). */
        private fun ratio(a: String, b: String): Double {
            if (a.isEmpty() && b.isEmpty()) return 1.0
            val maxLen = maxOf(a.length, b.length)
            if (maxLen == 0) return 1.0
            return 1.0 - levenshtein(a, b).toDouble() / maxLen
        }

        /** Distancia de edición de Levenshtein (con una sola fila de memoria). */
        private fun levenshtein(a: String, b: String): Int {
            if (a == b) return 0
            if (a.isEmpty()) return b.length
            if (b.isEmpty()) return a.length
            var prev = IntArray(b.length + 1) { it }
            var curr = IntArray(b.length + 1)
            for (i in 1..a.length) {
                curr[0] = i
                for (j in 1..b.length) {
                    val cost = if (a[i - 1] == b[j - 1]) 0 else 1
                    curr[j] = minOf(
                        curr[j - 1] + 1,      // inserción
                        prev[j] + 1,          // borrado
                        prev[j - 1] + cost,   // sustitución
                    )
                }
                val tmp = prev; prev = curr; curr = tmp
            }
            return prev[b.length]
        }
    }
}
