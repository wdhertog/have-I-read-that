package app.haveireadthat.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
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
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import app.haveireadthat.Book
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

fun formatRating(rating: Double): String =
    if (rating % 1.0 == 0.0) rating.toInt().toString() else rating.toString().trimEnd('0')

@Composable
fun Cover(book: Book, width: Dp) {
    Box(
        Modifier
            .size(width, width * 1.5f)
            .clip(RoundedCornerShape(4.dp))
            .background(MaterialTheme.colorScheme.surfaceVariant),
        contentAlignment = Alignment.Center,
    ) {
        Text(
            book.title,
            modifier = Modifier.padding(4.dp),
            fontSize = 10.sp,
            textAlign = TextAlign.Center,
            maxLines = 4,
            overflow = TextOverflow.Ellipsis,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        if (book.coverUrl != null) {
            AsyncImage(
                model = book.coverUrl,
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier.size(width, width * 1.5f),
            )
        }
    }
}

@Composable
fun CheckRow(label: String, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Checkbox(checked = checked, onCheckedChange = onChange)
        Text(label)
    }
}
