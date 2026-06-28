package com.bomedia.diccionariowolof

import android.app.Activity
import android.content.ActivityNotFoundException
import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.speech.RecognizerIntent
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Divider
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
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
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import com.bomedia.diccionariowolof.data.Entry
import com.bomedia.diccionariowolof.ui.DictionaryViewModel
import com.bomedia.diccionariowolof.ui.SpeakerState
import com.bomedia.diccionariowolof.ui.label
import com.bomedia.diccionariowolof.ui.rememberSpeaker
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
    // Motor de voz compartido por toda la app (conserva la voz elegida).
    val speaker = rememberSpeaker()

    var selected by remember { mutableStateOf<Entry?>(null) }

    val current = selected
    if (current == null) {
        SearchScreen(
            query = uiState.query,
            results = uiState.results,
            voiceHeard = uiState.voiceHeard,
            speaker = speaker,
            onQueryChange = viewModel::onQueryChange,
            onVoiceResult = viewModel::onVoiceResult,
            onEntryClick = { selected = it },
        )
    } else {
        EntryDetailScreen(
            entry = current,
            speaker = speaker,
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
    voiceHeard: String?,
    speaker: SpeakerState,
    onQueryChange: (String) -> Unit,
    onVoiceResult: (String) -> Unit,
    onEntryClick: (Entry) -> Unit,
) {
    val context = androidx.compose.ui.platform.LocalContext.current

    // Reconocimiento de voz mediante el reconocedor del sistema (se le pide
    // que trabaje SIN conexión). Devuelve una transcripción aproximada que
    // luego se compara fonéticamente con el wolof del diccionario.
    val voiceLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        if (result.resultCode == Activity.RESULT_OK) {
            val text = result.data
                ?.getStringArrayListExtra(RecognizerIntent.EXTRA_RESULTS)
                ?.firstOrNull()
            if (!text.isNullOrBlank()) onVoiceResult(text)
        }
    }

    fun startVoice() {
        val intent = Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH).apply {
            putExtra(
                RecognizerIntent.EXTRA_LANGUAGE_MODEL,
                RecognizerIntent.LANGUAGE_MODEL_FREE_FORM,
            )
            putExtra(RecognizerIntent.EXTRA_LANGUAGE, "es-ES")
            putExtra(RecognizerIntent.EXTRA_PROMPT, context.getString(R.string.voice_prompt))
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                putExtra(RecognizerIntent.EXTRA_PREFER_OFFLINE, true)
            }
        }
        try {
            voiceLauncher.launch(intent)
        } catch (e: ActivityNotFoundException) {
            Toast.makeText(context, R.string.voice_unavailable, Toast.LENGTH_LONG).show()
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.app_name)) },
                actions = { VoicePicker(speaker) },
            )
        }
    ) { innerPadding ->
        Column(modifier = Modifier.padding(innerPadding)) {

            OutlinedTextField(
                value = query,
                onValueChange = onQueryChange,
                singleLine = true,
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = 16.dp, vertical = 8.dp),
                label = { Text(stringResource(R.string.search_hint)) },
                trailingIcon = {
                    IconButton(onClick = { startVoice() }) {
                        Icon(
                            painter = painterResource(R.drawable.ic_mic),
                            contentDescription = stringResource(R.string.voice_search),
                        )
                    }
                },
            )

            if (voiceHeard != null) {
                Text(
                    text = stringResource(R.string.voice_heard, voiceHeard),
                    modifier = Modifier
                        .fillMaxWidth()
                        .background(MaterialTheme.colorScheme.surfaceVariant)
                        .padding(horizontal = 16.dp, vertical = 8.dp),
                    style = MaterialTheme.typography.bodySmall,
                )
            }

            if (results.isEmpty()) {
                Text(
                    text = stringResource(R.string.no_results),
                    modifier = Modifier.padding(16.dp),
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            } else {
                LazyColumn(modifier = Modifier.fillMaxSize()) {
                    items(results, key = { it.es }) { entry ->
                        EntryRow(
                            entry = entry,
                            onClick = { onEntryClick(entry) },
                            onSpeak = { speaker.speak(entry.speakable) },
                        )
                        Divider()
                    }
                }
            }
        }
    }
}

/**
 * Botón con menú desplegable para elegir la voz del TTS (idioma/acento y, según
 * el dispositivo, voz masculina o femenina). Si no hay voces, no se muestra.
 */
@Composable
private fun VoicePicker(speaker: SpeakerState) {
    if (speaker.voices.isEmpty()) return
    var expanded by remember { mutableStateOf(false) }

    IconButton(onClick = { expanded = true }) {
        Icon(
            painter = painterResource(R.drawable.ic_voice),
            contentDescription = stringResource(R.string.choose_voice),
        )
    }
    DropdownMenu(
        expanded = expanded,
        onDismissRequest = { expanded = false },
        modifier = Modifier.heightIn(max = 360.dp),
    ) {
        speaker.voices.forEach { voice ->
            val isSel = voice == speaker.selected
            DropdownMenuItem(
                text = { Text((if (isSel) "✓ " else "") + voice.label()) },
                onClick = {
                    speaker.select(voice)
                    // Pequeña muestra al elegir la voz.
                    speaker.speak("Jë-rë-jëf")
                    expanded = false
                },
            )
        }
    }
}

/** Una fila de la lista: español, wolof y botón para escucharla. */
@Composable
private fun EntryRow(entry: Entry, onClick: () -> Unit, onSpeak: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick)
            .padding(start = 16.dp, end = 4.dp, top = 12.dp, bottom = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(modifier = Modifier.weight(1f)) {
            Text(
                text = entry.es,
                style = MaterialTheme.typography.bodyLarge,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
            Text(
                text = entry.wo.ifEmpty { stringResource(R.string.no_translation) },
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.primary,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
        }
        if (entry.speakable.isNotEmpty()) {
            IconButton(onClick = onSpeak) {
                Icon(
                    painter = painterResource(R.drawable.ic_volume),
                    contentDescription = stringResource(R.string.listen),
                )
            }
        }
    }
}

/**
 * Ficha de la palabra: muestra español, wolof y pronunciación aproximada.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EntryDetailScreen(entry: Entry, speaker: SpeakerState, onBack: () -> Unit) {
    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.detail_title)) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(
                            painter = painterResource(R.drawable.ic_back),
                            contentDescription = stringResource(R.string.back),
                        )
                    }
                },
                actions = {
                    VoicePicker(speaker)
                    if (entry.speakable.isNotEmpty()) {
                        IconButton(onClick = { speaker.speak(entry.speakable) }) {
                            Icon(
                                painter = painterResource(R.drawable.ic_volume),
                                contentDescription = stringResource(R.string.listen),
                            )
                        }
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
            if (entry.cat.isNotEmpty()) {
                Text(
                    text = entry.cat,
                    style = MaterialTheme.typography.labelMedium,
                    color = MaterialTheme.colorScheme.primary,
                )
            }
            DetailField(stringResource(R.string.label_spanish), entry.es)
            DetailField(
                stringResource(R.string.label_wolof),
                entry.wo.ifEmpty { stringResource(R.string.no_translation) },
            )
            DetailField(stringResource(R.string.label_pron), entry.pron.ifEmpty { "—" })

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
        Text(text = value, style = MaterialTheme.typography.headlineSmall)
    }
}
