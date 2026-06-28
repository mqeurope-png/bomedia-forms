package com.bomedia.diccionariowolof.ui

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import com.bomedia.diccionariowolof.data.DictionaryRepository
import com.bomedia.diccionariowolof.data.Entry
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * Estado de la pantalla principal: el texto buscado y la lista de resultados.
 */
data class DictionaryUiState(
    val query: String = "",
    val results: List<Entry> = emptyList(),
)

/**
 * ViewModel de la pantalla principal.
 *
 * El diccionario se carga una sola vez (a través del repositorio) y la búsqueda
 * se recalcula en memoria cada vez que cambia el texto introducido.
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
        )
    }
}
