package com.bomedia.diccionariowolof.ui

import android.speech.tts.TextToSpeech
import android.speech.tts.Voice
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalContext
import java.util.Locale

/**
 * Estado del motor de Texto-a-Voz (TTS), preparado para Compose.
 *
 * - Lee la transcripción silabeada (`fon`) más despacio para que suene claro.
 * - Permite elegir la VOZ entre las instaladas en el dispositivo (distintos
 *   idiomas/acentos y, según el aparato, voz masculina o femenina).
 */
class SpeakerState {
    private var tts: TextToSpeech? = null

    var ready by mutableStateOf(false)
        private set

    /** Voces disponibles sin conexión, ordenadas por idioma. */
    var voices by mutableStateOf<List<Voice>>(emptyList())
        private set

    /** Voz seleccionada actualmente (null = la de por defecto). */
    var selected by mutableStateOf<Voice?>(null)
        private set

    internal fun attach(engine: TextToSpeech) {
        tts = engine
        engine.setSpeechRate(SPEECH_RATE)
        // Voces utilizables offline (descartamos las que exigen red).
        val available = runCatching {
            engine.voices
                ?.filter { !it.isNetworkConnectionRequired && !it.features.orEmpty()
                    .contains(TextToSpeech.Engine.KEY_FEATURE_NOT_INSTALLED) }
                ?.sortedBy { it.locale.displayName }
                ?: emptyList()
        }.getOrDefault(emptyList())
        voices = available

        // Por defecto: una voz española (la grafía de la pronunciación es
        // española, así que se lee más fiel); si no hay, la de por defecto.
        val preferida = available.firstOrNull { it.locale.language == "es" }
            ?: available.firstOrNull()
        preferida?.let { applyVoice(it) } ?: run {
            engine.language = Locale("es", "ES")
        }
        ready = true
    }

    /** Cambia la voz activa. */
    fun select(voice: Voice) = applyVoice(voice)

    private fun applyVoice(voice: Voice) {
        runCatching { tts?.voice = voice }
        selected = voice
    }

    /** Lee el texto en voz alta con la voz y velocidad actuales. */
    fun speak(text: String) {
        if (ready && text.isNotBlank()) {
            tts?.setSpeechRate(SPEECH_RATE)
            tts?.speak(text, TextToSpeech.QUEUE_FLUSH, null, "wolof-pron")
        }
    }

    internal fun release() {
        tts?.stop()
        tts?.shutdown()
        tts = null
        ready = false
    }

    companion object {
        // Más lento que el habitual (1.0) para articular mejor las sílabas.
        private const val SPEECH_RATE = 0.78f
    }
}

/**
 * Crea y recuerda un [SpeakerState] ligado al ciclo de vida del Composable.
 * El motor se libera automáticamente al salir de la pantalla.
 */
@Composable
fun rememberSpeaker(): SpeakerState {
    val context = LocalContext.current
    val state = remember { SpeakerState() }

    DisposableEffect(Unit) {
        var engine: TextToSpeech? = null
        engine = TextToSpeech(context) { status ->
            if (status == TextToSpeech.SUCCESS) {
                engine?.let { state.attach(it) }
            }
        }
        onDispose { state.release() }
    }
    return state
}

/** Etiqueta legible para una voz (idioma/acento + identificador corto). */
fun Voice.label(): String {
    val idioma = locale.displayName.replaceFirstChar { it.uppercase() }
    // El nombre técnico ayuda a distinguir voces (p. ej. masculina/femenina).
    val corto = name.substringAfterLast('-').substringAfterLast('#')
    return if (corto.isNotBlank() && corto != name) "$idioma · $corto" else idioma
}
