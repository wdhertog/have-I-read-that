package app.haveireadthat.ui

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import app.haveireadthat.R

/** The colours of the website (DESIGN.md): warm and dark, with amber as the only accent. */
object Palette {
    val bg = Color(0xFF17130F)
    val surface = Color(0xFF1F1813)
    val raised = Color(0xFF2A221B)
    val track = Color(0xFF2E261F)
    val line = Color(0xFF2B231D)
    val border = Color(0xFF3A3029)
    val textStrong = Color(0xFFF3EADC)
    val text = Color(0xFFEFE6DA)
    val textSoft = Color(0xFFD6C8B5)
    val textMuted = Color(0xFFA89A88)
    val placeholder = Color(0xFF8E8072)
    val accent = Color(0xFFE0A458)
    val link = Color(0xFFE8B56E)
    val onAccent = Color(0xFF1B140D)
    val coverText = Color(0xFFF5ECDD)
    val danger = Color(0xFFE6AFA3)

    /** Covers without a picture get one of these, by book id, like on the website. */
    val tints = listOf(
        Color(0xFF2B3A55), Color(0xFF5C2B26), Color(0xFF2F4A35), Color(0xFF6E5122),
        Color(0xFF4A2F45), Color(0xFF1F3D3A), Color(0xFF3B3530),
    )

    /** Text and background per reading status. */
    fun status(status: String): Pair<Color, Color> = when (status) {
        "reading" -> Color(0xFFEDBB76) to Color(0x26E0A458)
        "read" -> Color(0xFFB3D1A9) to Color(0x2696BE8C)
        "to-read" -> Color(0xFFB3C8EA) to Color(0x268CAADC)
        "dnf" -> Color(0xFFE6AFA3) to Color(0x26D68C7C)
        else -> textSoft to raised
    }
}

val Literata = FontFamily(
    Font(R.font.literata_regular, FontWeight.Normal),
    Font(R.font.literata_medium, FontWeight.Medium),
    Font(R.font.literata_semibold, FontWeight.SemiBold),
    Font(R.font.literata_italic, FontWeight.Normal, FontStyle.Italic),
)

val Figtree = FontFamily(
    Font(R.font.figtree_regular, FontWeight.Normal),
    Font(R.font.figtree_medium, FontWeight.Medium),
    Font(R.font.figtree_semibold, FontWeight.SemiBold),
    Font(R.font.figtree_bold, FontWeight.Bold),
)

private val colors = darkColorScheme(
    primary = Palette.accent,
    onPrimary = Palette.onAccent,
    primaryContainer = Palette.raised,
    onPrimaryContainer = Palette.textStrong,
    secondary = Palette.link,
    onSecondary = Palette.onAccent,
    secondaryContainer = Palette.raised,
    onSecondaryContainer = Palette.textStrong,
    tertiary = Palette.link,
    background = Palette.bg,
    onBackground = Palette.text,
    surface = Palette.bg,
    onSurface = Palette.text,
    surfaceVariant = Palette.raised,
    onSurfaceVariant = Palette.textMuted,
    surfaceContainerLowest = Palette.bg,
    surfaceContainerLow = Palette.surface,
    surfaceContainer = Palette.surface,
    surfaceContainerHigh = Palette.raised,
    surfaceContainerHighest = Palette.surface,
    surfaceBright = Palette.raised,
    surfaceDim = Palette.bg,
    inverseSurface = Palette.textStrong,
    inverseOnSurface = Palette.bg,
    outline = Palette.border,
    outlineVariant = Palette.line,
    error = Palette.danger,
    onError = Palette.onAccent,
    errorContainer = Color(0x26D68C7C),
    onErrorContainer = Palette.danger,
    scrim = Color(0xCC000000),
)

private fun serif(size: Int, line: Int, weight: FontWeight = FontWeight.Medium) =
    TextStyle(fontFamily = Literata, fontWeight = weight, fontSize = size.sp, lineHeight = line.sp, color = Palette.textStrong)

private fun sans(size: Int, line: Int, weight: FontWeight = FontWeight.Normal) =
    TextStyle(fontFamily = Figtree, fontWeight = weight, fontSize = size.sp, lineHeight = line.sp)

private val typography = Typography(
    displayLarge = serif(48, 54),
    displayMedium = serif(40, 46),
    displaySmall = serif(34, 40),
    headlineLarge = serif(32, 38),
    headlineMedium = serif(28, 34),
    headlineSmall = serif(24, 30),
    titleLarge = serif(22, 28),
    titleMedium = sans(16, 22, FontWeight.SemiBold),
    titleSmall = sans(14, 20, FontWeight.SemiBold),
    bodyLarge = sans(16, 24),
    bodyMedium = sans(15, 22),
    bodySmall = sans(13, 18),
    labelLarge = sans(15, 20, FontWeight.SemiBold),
    labelMedium = sans(13, 18, FontWeight.SemiBold),
    labelSmall = sans(12, 16, FontWeight.SemiBold),
)

private val shapes = Shapes(
    extraSmall = RoundedCornerShape(6.dp),
    small = RoundedCornerShape(10.dp),
    medium = RoundedCornerShape(16.dp),
    large = RoundedCornerShape(20.dp),
    extraLarge = RoundedCornerShape(28.dp),
)

/** One theme, dark, the same as the website; no wallpaper colours. */
@Composable
fun AppTheme(content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = colors, typography = typography, shapes = shapes, content = content)
}
