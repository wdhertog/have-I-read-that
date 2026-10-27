package app.haveireadthat.widget

import android.content.Context
import androidx.compose.runtime.Composable
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.glance.GlanceId
import androidx.glance.GlanceModifier
import androidx.glance.action.clickable
import androidx.glance.appwidget.GlanceAppWidget
import androidx.glance.appwidget.GlanceAppWidgetReceiver
import androidx.glance.appwidget.LinearProgressIndicator
import androidx.glance.action.actionStartActivity
import androidx.glance.appwidget.cornerRadius
import androidx.glance.appwidget.provideContent
import androidx.glance.background
import androidx.glance.layout.Column
import androidx.glance.layout.Spacer
import androidx.glance.layout.fillMaxSize
import androidx.glance.layout.fillMaxWidth
import androidx.glance.layout.height
import androidx.glance.layout.padding
import androidx.glance.text.FontWeight
import androidx.glance.text.Text
import androidx.glance.text.TextStyle
import androidx.glance.unit.ColorProvider
import app.haveireadthat.ui.Palette
import app.haveireadthat.Book
import app.haveireadthat.Goal
import app.haveireadthat.MainActivity
import app.haveireadthat.Repository
import app.haveireadthat.Settings
import kotlinx.coroutines.withTimeoutOrNull

/** Home-screen widget: what you're reading now, and this year's goal. */
class ReadingWidget : GlanceAppWidget() {
    override suspend fun provideGlance(context: Context, id: GlanceId) {
        val repository = Repository(context)
        if (Settings(context).configured) {
            // Refresh when the network is quick; otherwise show what the phone has. Only
            // reads: sending queued changes is for the app and the sync worker.
            withTimeoutOrNull(8_000) { runCatching { repository.refresh() } }
        }
        val state = repository.state()
        val reading = state.books.filter { it.status == "reading" }.take(3)
        provideContent { Content(reading, state.goal) }
    }

    @Composable
    private fun Content(reading: List<Book>, goal: Goal?) {
        // The app's own warm dark colours, not the wallpaper's.
        val text = TextStyle(color = ColorProvider(Palette.textStrong), fontSize = 14.sp)
        val muted = TextStyle(color = ColorProvider(Palette.textMuted), fontSize = 12.sp)
        Column(
            modifier = GlanceModifier
                .fillMaxSize()
                .cornerRadius(16.dp)
                .background(ColorProvider(Palette.bg))
                .padding(12.dp)
                .clickable(actionStartActivity<MainActivity>()),
        ) {
            Text("Aan het lezen", style = TextStyle(color = ColorProvider(Palette.accent), fontSize = 14.sp, fontWeight = FontWeight.Bold))
            Spacer(GlanceModifier.height(6.dp))
            if (reading.isEmpty()) {
                Text("Je leest nu geen boek.", style = muted)
            }
            reading.forEach { book ->
                Text(book.title, style = text, maxLines = 1)
                val percent = book.progressPercent
                if (percent != null) {
                    LinearProgressIndicator(
                        progress = percent / 100f,
                        modifier = GlanceModifier.fillMaxWidth().height(4.dp),
                        color = ColorProvider(Palette.accent),
                        backgroundColor = ColorProvider(Palette.track),
                    )
                    Text("$percent%", style = muted)
                } else if (book.author.isNotBlank()) {
                    Text(book.author, style = muted, maxLines = 1)
                }
                Spacer(GlanceModifier.height(6.dp))
            }
            val target = goal?.goal
            if (goal != null && target != null) {
                Text("Leesdoel ${goal.year}: ${goal.read} van $target", style = muted)
            }
        }
    }
}

class ReadingWidgetReceiver : GlanceAppWidgetReceiver() {
    override val glanceAppWidget: GlanceAppWidget = ReadingWidget()
}
