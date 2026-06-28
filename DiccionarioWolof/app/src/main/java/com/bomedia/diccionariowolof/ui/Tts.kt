package com.bomedia.diccionariowolof.ui

import android.speech.tts.TextToSpeech
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.ui.platform.LocalContext
import java.util.Locale

/**
 * Crea un motor de Texto-a-Voz (TTS) ligado al ciclo de vida del Composable y
 * devuelve una función `hablar(texto)`.
 *
 * Se usa la voz **española** del dispositivo: como la pronunciación aproximada
 * (`pron`) está escrita en grafía española, al leerla en voz alta suena
 * parecida al wolof original. Funciona **sin conexión** en los móviles que
 * tienen instalada la voz española (la mayoría).
 *
 * El motor se libera automáticamente al salir de la pantalla.
 */
@Composable
fun rememberSpeaker(): (String) -> Unit {
    val context = LocalContext.current
    val ready = remember { mutableStateOf(false) }
    // Contenedor de 1 hueco para poder leer el motor desde la lambda `hablar`.
    val holder = remember { arrayOfNulls<TextToSpeech>(1) }

    DisposableEffect(Unit) {
        // `engine` se captura por referencia: cuando el callback de init se
        // ejecuta (de forma asíncrona) la variable ya tiene valor asignado.
        var engine: TextToSpeech? = null
        engine = TextToSpeech(context) { status ->
            if (status == TextToSpeech.SUCCESS) {
                engine?.language = Locale("es", "ES")
                ready.value = true
            }
        }
        holder[0] = engine
        onDispose {
            engine?.stop()
            engine?.shutdown()
            holder[0] = null
        }
    }

    return { texto ->
        if (ready.value && texto.isNotBlank()) {
            holder[0]?.speak(texto, TextToSpeech.QUEUE_FLUSH, null, "wolof-pron")
        }
    }
}
