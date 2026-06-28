package com.bomedia.diccionariowolof.ui

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import com.bomedia.diccionariowolof.data.DictionaryRepository
import com.bomedia.diccionariowolof.data.Entry
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * Estado de la pantalla principal.
 *
 * @property query      texto escrito en la barra de búsqueda.
 * @property results    entradas que se muestran en la lista.
 * @property voiceHeard si la última búsqueda vino del micrófono, el texto
 *                      reconocido (para mostrar "he oído: …"); si no, null.
 */
data class DictionaryUiState(
    val query: String = "",
    val results: List<Entry> = emptyList(),
    val voiceHeard: String? = null,
)

/**
 * ViewModel de la pantalla principal.
 *
 * El diccionario se carga una sola vez (a través del repositorio) y la búsqueda
 * se recalcula en memoria cada vez que cambia el texto o llega audio.
 */
class DictionaryViewModel(app: Application) : AndroidViewModel(app) {

    private val repository = DictionaryRepository(app)

    private val _uiState = MutableStateFlow(
        // Estado inicial: sin filtro, se muestra el diccionario completo.
        DictionaryUiState(query = "", results = repository.entries)
    )
    val uiState: StateFlow<DictionaryUiState> = _uiState.asStateFlow()

    /** Se llama mientras el usuario escribe en la barra de búsqueda. */
    fun onQueryChange(newQuery: String) {
        _uiState.value = DictionaryUiState(
            query = newQuery,
            results = repository.search(newQuery),
            voiceHeard = null,
        )
    }

    /**
     * Se llama cuando el reconocedor de voz devuelve una transcripción.
     *
     * @param spanishMode si es `true`, lo dicho se interpreta como ESPAÑOL y se
     *   busca en el texto (español/wolof). Si es `false`, se interpreta como
     *   WOLOF y se compara fonéticamente con la pronunciación/wolof.
     */
    fun onVoiceResult(spoken: String, spanishMode: Boolean) {
        val results = if (spanishMode) {
            repository.search(spoken)
        } else {
            // Wolof: parecido fonético; si no hay, caemos a la búsqueda de texto.
            repository.phoneticSearch(spoken).ifEmpty { repository.search(spoken) }
        }
        _uiState.value = DictionaryUiState(
            query = spoken,
            results = results,
            voiceHeard = spoken,
        )
    }
}
