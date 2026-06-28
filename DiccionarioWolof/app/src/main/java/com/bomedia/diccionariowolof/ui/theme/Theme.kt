package com.bomedia.diccionariowolof.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

// Paleta sencilla con fondo blanco, según el enunciado.
private val LightColors = lightColorScheme(
    primary = Color(0xFF00695C),       // verde teal (acento)
    onPrimary = Color.White,
    background = Color.White,           // fondo blanco
    onBackground = Color(0xFF1B1B1B),
    surface = Color.White,
    onSurface = Color(0xFF1B1B1B),
    surfaceVariant = Color(0xFFF2F2F2),
)

/**
 * Tema Material Design 3 de la aplicación.
 *
 * Se usa siempre el esquema claro (fondo blanco) para mantener la interfaz
 * sencilla y legible, sin animaciones ni color dinámico.
 */
@Composable
fun DiccionarioWolofTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit,
) {
    MaterialTheme(
        colorScheme = LightColors,
        typography = Typography(),
        content = content,
    )
}
