package app.haveireadthat.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Checkbox
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import app.haveireadthat.Book
import app.haveireadthat.STATUSES
import coil.compose.AsyncImage

/** Same rounding as the server: 4.25 shows as four and a half stars. */
fun stars(rating: Double?): String {
    if (rating == null || rating <= 0) return ""
    val halves = (rating * 2 + 0.5).toInt()
    val full = halves / 2
    val half = halves % 2
    return "★".repeat(full) + (if (half == 1) "½" else "") + "☆".repeat(5 - full - half)
}

/** 3.0 → "3", 2.5 → "2,5" (series numbers). */
fun formatNumber(value: Double): String =
    if (value % 1.0 == 0.0) value.toInt().toString() else value.toString().replace('.', ',')

/** "2026-09-14" → "14 september 2026"; anything else stays as it is. */
fun formatDate(date: String): String {
    val parsed = runCatching { java.time.LocalDate.parse(date.take(10)) }.getOrNull() ?: return date
    val months = listOf("januari", "februari", "maart", "april", "mei", "juni", "juli", "augustus", "september", "oktober", "november", "december")
    return "${parsed.dayOfMonth} ${months[parsed.monthValue - 1]} ${parsed.year}"
}

fun formatRating(rating: Double): String =
    if (rating % 1.0 == 0.0) rating.toInt().toString() else rating.toString().trimEnd('0')

/**
 * The cover, or, without one, a typographic cover like on the website: a tint chosen
 * by the book's id, a spine on the left, the short title and (when there's room) the
 * author in capitals.
 */
@Composable
fun Cover(book: Book, width: Dp) {
    val coverHeight = width * 1.5f
    val tint = Palette.tints[Math.floorMod(book.id, Palette.tints.size)]
    val big = width >= 80.dp
    Box(
        Modifier
            .size(width, coverHeight)
            .clip(RoundedCornerShape(if (big) 8.dp else 4.dp))
            .background(tint),
    ) {
        Box(
            Modifier
                .fillMaxHeight()
                .width(if (big) 6.dp else 3.dp)
                .background(Color.Black.copy(alpha = .22f)),
        )
        Column(
            Modifier
                .fillMaxSize()
                .padding(start = if (big) 16.dp else 7.dp, end = if (big) 12.dp else 4.dp, top = if (big) 14.dp else 6.dp, bottom = if (big) 14.dp else 6.dp),
        ) {
            Text(
                shortTitle(book.title),
                fontFamily = Literata,
                fontWeight = FontWeight.Medium,
                fontSize = (width.value / 7.5f).coerceIn(7f, 24f).sp,
                lineHeight = (width.value / 6.3f).coerceIn(8.5f, 29f).sp,
                color = Palette.coverText,
                maxLines = if (big) 5 else 4,
                overflow = TextOverflow.Ellipsis,
            )
            if (big && book.author.isNotBlank()) {
                Spacer(Modifier.weight(1f))
                Box(Modifier.width(26.dp).height(1.dp).background(Palette.coverText.copy(alpha = .5f)))
                Spacer(Modifier.height(8.dp))
                Text(
                    book.author.split(',', '&').first().trim().uppercase(),
                    fontFamily = Figtree,
                    fontWeight = FontWeight.SemiBold,
                    fontSize = 10.sp,
                    letterSpacing = 1.2.sp,
                    color = Palette.coverText.copy(alpha = .85f),
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
        }
        if (book.coverUrl != null) {
            AsyncImage(
                model = book.coverUrl,
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier.size(width, coverHeight),
            )
        }
    }
}

/** "De avonden: roman (1947)" → "De avonden". */
private fun shortTitle(title: String): String =
    title.split(Regex("\\s*[:(\\[]")).first().trim().ifEmpty { title }

/** The reading status as a coloured label, the same colours as on the website. */
@Composable
fun StatusChip(status: String, big: Boolean = false) {
    val (fg, bg) = Palette.status(status)
    Text(
        STATUSES[status] ?: status,
        color = fg,
        style = if (big) MaterialTheme.typography.labelLarge else MaterialTheme.typography.labelMedium,
        modifier = Modifier
            .clip(RoundedCornerShape(999.dp))
            .background(bg)
            .padding(horizontal = if (big) 14.dp else 10.dp, vertical = if (big) 6.dp else 3.dp),
    )
}

/** A neutral label, like "bij Anna" for a lent book. */
@Composable
fun Tag(text: String) {
    Text(
        text,
        color = Palette.textSoft,
        style = MaterialTheme.typography.labelMedium,
        modifier = Modifier
            .clip(RoundedCornerShape(999.dp))
            .background(Palette.raised)
            .padding(horizontal = 10.dp, vertical = 3.dp),
    )
}

/** Amber stars; screen readers hear "4,5 van 5 sterren". */
@Composable
fun Stars(rating: Double?, big: Boolean = false) {
    if (rating == null || rating <= 0) return
    Text(
        stars(rating),
        color = Palette.accent,
        fontSize = if (big) 22.sp else 14.sp,
        letterSpacing = if (big) 2.sp else 1.sp,
        modifier = Modifier.semantics { contentDescription = "${formatRating(rating).replace('.', ',')} van 5 sterren" },
    )
}

@Composable
fun CheckRow(label: String, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Checkbox(checked = checked, onCheckedChange = onChange)
        Text(label)
    }
}
