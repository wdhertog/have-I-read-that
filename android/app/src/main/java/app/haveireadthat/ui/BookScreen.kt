package app.haveireadthat.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Slider
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.unit.dp
import app.haveireadthat.Book
import app.haveireadthat.BookResponse
import app.haveireadthat.FinishRequest
import app.haveireadthat.STATUSES
import app.haveireadthat.Settings
import app.haveireadthat.StartRequest
import kotlinx.coroutines.launch
import java.time.LocalDate

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun BookScreen(settings: Settings, id: Int, onBack: () -> Unit) {
    var book by remember { mutableStateOf<Book?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val snackbar = remember { SnackbarHostState() }
    val scope = rememberCoroutineScope()

    LaunchedEffect(id) {
        try {
            book = settings.client().book(id)
        } catch (e: Exception) {
            error = e.message ?: "Laden mislukt"
        }
    }

    /** Run an action that returns the updated book, then report how posting went. */
    fun act(success: String, call: suspend () -> BookResponse) {
        busy = true
        scope.launch {
            try {
                val response = call()
                book = response.book
                val toot = response.toot
                snackbar.showSnackbar(
                    when {
                        toot == null -> success
                        toot.posted -> "$success Gepost naar Mastodon."
                        else -> "$success Niet gepost: ${toot.error}"
                    }
                )
            } catch (e: Exception) {
                snackbar.showSnackbar("Mislukt: ${e.message}")
            } finally {
                busy = false
            }
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(book?.title ?: "") },
                navigationIcon = {
                    IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Terug") }
                },
            )
        },
        snackbarHost = { SnackbarHost(snackbar) },
    ) { padding ->
        val current = book
        if (current == null) {
            Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                error?.let { Text(it, color = MaterialTheme.colorScheme.error) } ?: CircularProgressIndicator()
            }
            return@Scaffold
        }
        Column(
            Modifier
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            Header(current)

            if (current.status != "reading") {
                StartCard(busy, settings.postByDefault, again = current.status == "read") { toot ->
                    act("Veel leesplezier!") {
                        settings.client().start(current.id, StartRequest(LocalDate.now().toString(), toot))
                    }
                }
            }
            if (current.status == "reading" || current.status == "to-read") {
                FinishCard(current, busy, settings.postByDefault) { request ->
                    act(if (request.dnf) "Gemarkeerd als niet uitgelezen." else "Uitgelezen! 🎉") {
                        settings.client().finish(current.id, request)
                    }
                }
            }
            History(current)
        }
    }
}

@Composable
private fun Header(book: Book) {
    Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
        Cover(book, 110.dp)
        Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(book.title, style = MaterialTheme.typography.headlineSmall)
            if (book.author.isNotBlank()) Text(book.author, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Text(STATUSES[book.status] ?: book.status, style = MaterialTheme.typography.labelLarge)
            book.rating?.let {
                Text("${stars(it)}  ${formatRating(it)}", color = MaterialTheme.colorScheme.primary)
            }
            listOfNotNull(book.isbn, book.pages?.let { "$it pagina's" }, book.format)
                .takeIf { it.isNotEmpty() }
                ?.let { Text(it.joinToString(" · "), style = MaterialTheme.typography.bodySmall) }
        }
    }
    book.review?.let { Text(it, style = MaterialTheme.typography.bodyMedium) }
}

@Composable
private fun StartCard(busy: Boolean, postByDefault: Boolean, again: Boolean, onStart: (toot: Boolean) -> Unit) {
    var toot by rememberSaveable { mutableStateOf(postByDefault) }
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(if (again) "Opnieuw lezen" else "Begin met lezen", style = MaterialTheme.typography.titleMedium)
            CheckRow("Post naar Mastodon", toot) { toot = it }
            Button(enabled = !busy, onClick = { onStart(toot) }) { Text("Start vandaag") }
        }
    }
}

@Composable
private fun FinishCard(book: Book, busy: Boolean, postByDefault: Boolean, onFinish: (FinishRequest) -> Unit) {
    // Slider in quarter stars; 0 means "no rating".
    var rating by rememberSaveable { mutableFloatStateOf(book.rating?.toFloat() ?: 0f) }
    var review by rememberSaveable { mutableStateOf(book.review.orEmpty()) }
    var dnf by rememberSaveable { mutableStateOf(false) }
    var toot by rememberSaveable { mutableStateOf(postByDefault) }
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text("Uitgelezen", style = MaterialTheme.typography.titleMedium)
            Text(
                if (rating > 0) "${stars(rating.toDouble())}  ${formatRating(rating.toDouble())}" else "Geen beoordeling",
                color = MaterialTheme.colorScheme.primary,
            )
            Slider(value = rating, onValueChange = { rating = it }, valueRange = 0f..5f, steps = 19)
            OutlinedTextField(
                value = review,
                onValueChange = { review = it },
                label = { Text("Recensie") },
                minLines = 3,
                modifier = Modifier.fillMaxWidth(),
            )
            CheckRow("Niet uitgelezen (DNF)", dnf) { dnf = it }
            if (!dnf) CheckRow("Post naar Mastodon", toot) { toot = it }
            Button(
                enabled = !busy,
                onClick = {
                    onFinish(
                        FinishRequest(
                            date = LocalDate.now().toString(),
                            rating = rating.takeIf { it > 0 }?.toDouble(),
                            review = review.trim().ifEmpty { null },
                            dnf = dnf,
                            toot = toot && !dnf,
                        )
                    )
                },
            ) { Text("Opslaan") }
        }
    }
}

@Composable
private fun History(book: Book) {
    val uriHandler = LocalUriHandler.current
    if (book.reads.isNotEmpty()) {
        Text("Leesmomenten", style = MaterialTheme.typography.titleMedium)
        book.reads.forEach { read ->
            Text("${read.started ?: "—"}  →  ${read.finished ?: "…"}")
        }
    }
    if (book.posts.isNotEmpty()) {
        Text("Op Mastodon", style = MaterialTheme.typography.titleMedium)
        book.posts.forEach { post ->
            val label = "${post.createdAt} — ${if (post.kind == "finished") "uitgelezen" else "begonnen"}"
            val url = post.url
            if (url != null && url.startsWith("https://")) {
                TextButton(onClick = { uriHandler.openUri(url) }) { Text(label) }
            } else {
                Text(label)
            }
        }
    }
}
