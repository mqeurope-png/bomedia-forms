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
import com.bomedia.diccionariowolof.data.Entry
import java.util.Locale

/**
 * Estado del motor de Texto-a-Voz (TTS), preparado para Compose.
 *
 * Sabe leer de dos formas:
 *  - **Wolof**: la transcripción silabeada (`fon`) con la VOZ elegida por el
 *    usuario (francesa por defecto), más despacio para articular bien.
 *  - **Español**: la palabra española con una voz ESPAÑOLA automática.
 */
class SpeakerState {
    private var tts: TextToSpeech? = null

    // Voz española elegida automáticamente para leer en español.
    private var spanishVoice: Voice? = null

    var ready by mutableStateOf(false)
        private set

    /** Voces disponibles sin conexión, ordenadas por idioma. */
    var voices by mutableStateOf<List<Voice>>(emptyList())
        private set

    /** Voz seleccionada para el wolof (null = la de por defecto). */
    var selected by mutableStateOf<Voice?>(null)
        private set

    internal fun attach(engine: TextToSpeech) {
        tts = engine
        engine.setSpeechRate(RATE_WOLOF)
        // Voces utilizables offline (descartamos las que exigen red).
        val available = runCatching {
            engine.voices
                ?.filter { !it.isNetworkConnectionRequired && !it.features.orEmpty()
                    .contains(TextToSpeech.Engine.KEY_FEATURE_NOT_INSTALLED) }
                ?.sortedBy { it.locale.displayName }
                ?: emptyList()
        }.getOrDefault(emptyList())
        voices = available

        spanishVoice = available.firstOrNull { it.locale.language == "es" }

        // Voz wolof por defecto: FRANCESA (cooficial en Senegal); si no hay,
        // española y, en último caso, la de por defecto del motor.
        val preferida = available.firstOrNull { it.locale.language == "fr" }
            ?: spanishVoice
            ?: available.firstOrNull()
        preferida?.let { applyVoice(it) } ?: run { engine.language = Locale.FRENCH }
        ready = true
    }

    /** Cambia la voz del wolof. */
    fun select(voice: Voice) = applyVoice(voice)

    private fun applyVoice(voice: Voice) {
        runCatching { tts?.voice = voice }
        selected = voice
    }

    /** Lee una entrada en WOLOF (pronunciación) con la voz elegida. */
    fun speakWolof(entry: Entry) {
        if (!ready) return
        selected?.let { runCatching { tts?.voice = it } }
        speakWith(entry.speakable(selected?.locale?.language), RATE_WOLOF)
    }

    /** Lee un texto en WOLOF (muestras puntuales) con la voz elegida. */
    fun speakWolofRaw(text: String) {
        if (!ready) return
        selected?.let { runCatching { tts?.voice = it } }
        speakWith(text, RATE_WOLOF)
    }

    /** Lee un texto en ESPAÑOL con una voz española automática. */
    fun speakSpanish(text: String) {
        if (!ready) return
        val v = spanishVoice
        if (v != null) runCatching { tts?.voice = v }
        else runCatching { tts?.language = Locale("es", "ES") }
        speakWith(text, RATE_SPANISH)
    }

    private fun speakWith(text: String, rate: Float) {
        if (text.isNotBlank()) {
            tts?.setSpeechRate(rate)
            tts?.speak(text, TextToSpeech.QUEUE_FLUSH, null, "tts")
        }
    }

    /**
     * Voces con una etiqueta legible y numerada por idioma, p. ej.
     * "Francés (Francia) · voz 1". Android no expone el género de la voz, por
     * eso se numeran para poder distinguirlas (al elegir una suena una muestra).
     */
    val labeledVoices: List<Pair<Voice, String>>
        get() {
            val cuenta = HashMap<String, Int>()
            return voices.map { v ->
                val idioma = v.locale.displayName.replaceFirstChar { it.uppercase() }
                val n = (cuenta[idioma] ?: 0) + 1
                cuenta[idioma] = n
                v to "$idioma · voz $n"
            }
        }

    internal fun release() {
        tts?.stop()
        tts?.shutdown()
        tts = null
        ready = false
    }

    companion object {
        private const val RATE_WOLOF = 0.78f    // lento: articula las sílabas
        private const val RATE_SPANISH = 0.95f  // español casi natural
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
