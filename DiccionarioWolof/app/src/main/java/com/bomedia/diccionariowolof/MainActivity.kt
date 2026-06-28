package com.bomedia.diccionariowolof

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Divider
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import com.bomedia.diccionariowolof.data.Entry
import com.bomedia.diccionariowolof.ui.DictionaryViewModel
import com.bomedia.diccionariowolof.ui.theme.DiccionarioWolofTheme

/**
 * Única actividad de la aplicación. Aloja toda la interfaz hecha con Compose.
 */
class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            DiccionarioWolofTheme {
                Surface(modifier = Modifier.fillMaxSize()) {
                    DictionaryApp()
                }
            }
        }
    }
}

/**
 * Punto de entrada de la UI. Decide si mostrar la lista de búsqueda o la ficha
 * de una palabra concreta (sin librería de navegación: basta con un estado).
 */
@Composable
fun DictionaryApp(viewModel: DictionaryViewModel = viewModel()) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()

    // Entrada seleccionada: si es null se ve la lista; si no, su ficha.
    var selected by remember { mutableStateOf<Entry?>(null) }

    val current = selected
    if (current == null) {
        SearchScreen(
            query = uiState.query,
            results = uiState.results,
            onQueryChange = viewModel::onQueryChange,
            onEntryClick = { selected = it },
        )
    } else {
        EntryDetailScreen(
            entry = current,
            onBack = { selected = null },
        )
    }
}

/**
 * Pantalla principal: barra de búsqueda arriba y lista de palabras debajo.
 * La lista se filtra en tiempo real a medida que se escribe.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SearchScreen(
    query: String,
    results: List<Entry>,
    onQueryChange: (String) -> Unit,
    onEntryClick: (Entry) -> Unit,
) {
    Scaffold(
        topBar = { TopAppBar(title = { Text(stringResource(R.string.app_name)) }) }
    ) { innerPadding ->
        Column(modifier = Modifier.padding(innerPadding)) {

            // Barra de búsqueda.
            OutlinedTextField(
                value = query,
                onValueChange = onQueryChange,
                singleLine = true,
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = 16.dp, vertical = 8.dp),
                label = { Text(stringResource(R.string.search_hint)) },
            )

            if (results.isEmpty()) {
                // Sin resultados para la búsqueda actual.
                Text(
                    text = stringResource(R.string.no_results),
                    modifier = Modifier.padding(16.dp),
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            } else {
                // Lista de palabras.
                LazyColumn(modifier = Modifier.fillMaxSize()) {
                    items(results, key = { it.es }) { entry ->
                        EntryRow(entry = entry, onClick = { onEntryClick(entry) })
                        Divider()
                    }
                }
            }
        }
    }
}

/** Una fila de la lista: español arriba y wolof debajo. */
@Composable
private fun EntryRow(entry: Entry, onClick: () -> Unit) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick)
            .padding(horizontal = 16.dp, vertical = 12.dp)
    ) {
        Text(
            text = entry.es,
            style = MaterialTheme.typography.bodyLarge,
            maxLines = 2,
            overflow = TextOverflow.Ellipsis,
        )
        Text(
            // Si no se conoce la traducción se indica de forma explícita.
            text = entry.wo.ifEmpty { stringResource(R.string.no_translation) },
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.primary,
            maxLines = 2,
            overflow = TextOverflow.Ellipsis,
        )
    }
}

/**
 * Ficha de la palabra: muestra español, wolof y pronunciación aproximada.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EntryDetailScreen(entry: Entry, onBack: () -> Unit) {
    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.detail_title)) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(
                            painter = androidx.compose.ui.res.painterResource(R.drawable.ic_back),
                            contentDescription = stringResource(R.string.back),
                        )
                    }
                },
            )
        }
    ) { innerPadding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(innerPadding)
                .padding(24.dp),
            verticalArrangement = Arrangement.spacedBy(20.dp),
        ) {
            DetailField(
                label = stringResource(R.string.label_spanish),
                value = entry.es,
            )
            DetailField(
                label = stringResource(R.string.label_wolof),
                value = entry.wo.ifEmpty { stringResource(R.string.no_translation) },
            )
            DetailField(
                label = stringResource(R.string.label_pron),
                value = entry.pron.ifEmpty { "—" },
            )

            TextButton(onClick = onBack) {
                Text(stringResource(R.string.back))
            }
        }
    }
}

/** Bloque "etiqueta + valor" usado en la ficha de la palabra. */
@Composable
private fun DetailField(label: String, value: String) {
    Column {
        Text(
            text = label,
            style = MaterialTheme.typography.labelMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Text(
            text = value,
            style = MaterialTheme.typography.headlineSmall,
        )
    }
}
