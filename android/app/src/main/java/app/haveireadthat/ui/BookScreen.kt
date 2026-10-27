package app.haveireadthat.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.Share
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import android.content.Intent
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import app.haveireadthat.Book
import app.haveireadthat.ApiException
import app.haveireadthat.Outcome
import app.haveireadthat.Repository
import app.haveireadthat.FinishRequest
import app.haveireadthat.LendRequest
import app.haveireadthat.ReturnRequest
import app.haveireadthat.NewNote
import app.haveireadthat.Note
import app.haveireadthat.ProgressRequest
import app.haveireadthat.Read
import app.haveireadthat.ReadDates
import app.haveireadthat.LANGUAGES
import app.haveireadthat.STATUSES
import app.haveireadthat.Settings
import app.haveireadthat.StartRequest
import kotlinx.coroutines.launch
import java.io.IOException
import java.time.LocalDate

/** Which read the dates dialog is editing: an existing one, or a new one for this book. */
private sealed interface ReadEdit {
    data class Existing(val read: Read) : ReadEdit
    data object New : ReadEdit
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun BookScreen(settings: Settings, id: Int, onBack: () -> Unit, onEdit: (Int) -> Unit) {
    val context = LocalContext.current
    val repository = remember { Repository(context) }
    var book by remember { mutableStateOf<Book?>(null) }
    var offline by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var confirmDelete by remember { mutableStateOf(false) }
    var readEdit by remember { mutableStateOf<ReadEdit?>(null) }
    var addingNote by remember { mutableStateOf(false) }
    var lending by remember { mutableStateOf(false) }
    var borrowers by remember { mutableStateOf<List<String>>(emptyList()) }
    val snackbar = remember { SnackbarHostState() }
    val scope = rememberCoroutineScope()

    LaunchedEffect(id) {
        try {
            val loaded = repository.book(id)
            book = loaded.value
            offline = loaded.offline
        } catch (e: Exception) {
            error = e.message ?: "Laden mislukt"
        }
    }

    /**
     * Run an action, then say how it went: done (and posted or not), or saved on the
     * phone to send later.
     */
    fun act(success: String, call: suspend () -> Outcome) {
        busy = true
        scope.launch {
            try {
                when (val outcome = call()) {
                    is Outcome.Sent -> {
                        book = outcome.response.book
                        val toot = outcome.response.toot
                        snackbar.showSnackbar(
                            when {
                                toot == null -> success
                                toot.posted -> "$success Gepost naar Mastodon."
                                else -> "$success Niet gepost: ${toot.error}"
                            }
                        )
                    }
                    is Outcome.Queued -> {
                        book = outcome.book
                        snackbar.showSnackbar("$success Opgeslagen op je telefoon; wordt verstuurd zodra je verbinding hebt.")
                    }
                }
            } catch (e: ApiException) {
                snackbar.showSnackbar("Mislukt: ${e.message}")
            } catch (e: IOException) {
                snackbar.showSnackbar("Geen verbinding; probeer het later opnieuw.")
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
                actions = {
                    if (book != null && id > 0) {
                        ShareButton(book!!)
                        IconButton(onClick = { onEdit(id) }) { Icon(Icons.Default.Edit, contentDescription = "Bewerken") }
                        IconButton(onClick = { confirmDelete = true }) { Icon(Icons.Default.Delete, contentDescription = "Verwijderen") }
                    }
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
            if (offline || current.id < 0) {
                Text(
                    if (current.id < 0) "Nog niet naar de server gestuurd; dat gebeurt zodra je verbinding hebt."
                    else "Offline: dit is de versie die op je telefoon staat.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }

            if (current.status == "reading") {
                ProgressCard(current, busy) { request ->
                    act("Bijgewerkt.") { repository.progress(current, request) }
                }
            }
            if (current.status != "reading") {
                StartCard(busy, settings.postByDefault, again = current.status == "read") { toot ->
                    act("Veel leesplezier!") {
                        repository.start(current, StartRequest(LocalDate.now().toString(), toot))
                    }
                }
            }
            if (current.status == "reading" || current.status == "to-read") {
                FinishCard(current, busy, settings.postByDefault) { request ->
                    act(if (request.dnf) "Gemarkeerd als niet uitgelezen." else "Uitgelezen! 🎉") {
                        repository.finish(current, request)
                    }
                }
            }
            if (current.id > 0) {
                Lending(
                    current,
                    busy = busy,
                    onLend = {
                        lending = true
                        scope.launch { borrowers = runCatching { repository.loans().value.borrowers }.getOrDefault(emptyList()) }
                    },
                    onReturn = {
                        act("Teruggekregen.") { repository.giveBack(current, ReturnRequest(LocalDate.now().toString())) }
                    },
                )
            }
            Notes(
                current,
                onAdd = { addingNote = true },
                onDelete = { note ->
                    act("Verwijderd.") { Outcome.Sent(settings.client().deleteNote(note.id)) }
                },
            )
            if (current.id > 0) {
                Reads(current, onEdit = { readEdit = ReadEdit.Existing(it) }, onAdd = { readEdit = ReadEdit.New })
            }
            Posts(current)
        }
    }

    if (confirmDelete) {
        AlertDialog(
            onDismissRequest = { confirmDelete = false },
            title = { Text("Boek verwijderen?") },
            text = { Text("“${book?.title}” en alle leesmomenten worden verwijderd.") },
            confirmButton = {
                TextButton(onClick = {
                    confirmDelete = false
                    scope.launch {
                        try {
                            settings.client().delete(id)
                            repository.forgetBook(id)
                            onBack()
                        } catch (e: Exception) {
                            snackbar.showSnackbar("Verwijderen mislukt: ${e.message}")
                        }
                    }
                }) { Text("Verwijderen") }
            },
            dismissButton = { TextButton(onClick = { confirmDelete = false }) { Text("Annuleren") } },
        )
    }

    if (lending) {
        LendDialog(
            borrowers = borrowers,
            onDismiss = { lending = false },
            onSave = { request ->
                lending = false
                book?.let { current -> act("Uitgeleend aan ${request.borrower}.") { repository.lend(current, request) } }
            },
        )
    }

    if (addingNote) {
        NoteDialog(
            onDismiss = { addingNote = false },
            onSave = { note ->
                addingNote = false
                book?.let { current -> act(if (note.kind == "quote") "Citaat bewaard." else "Notitie bewaard.") { repository.addNote(current, note) } }
            },
        )
    }

    readEdit?.let { edit ->
        val existing = (edit as? ReadEdit.Existing)?.read
        ReadDialog(
            read = existing,
            onDismiss = { readEdit = null },
            onSave = { dates ->
                readEdit = null
                act("Opgeslagen.") {
                    Outcome.Sent(
                        if (existing != null) settings.client().updateRead(existing.id, dates)
                        else settings.client().addRead(id, dates)
                    )
                }
            },
            onDelete = existing?.let { read ->
                {
                    readEdit = null
                    act("Verwijderd.") { Outcome.Sent(settings.client().deleteRead(read.id)) }
                }
            },
        )
    }
}

/**
 * Send a message about the book through Signal, WhatsApp, mail…: what you thought of
 * it, or (when lent out) asking for it back. Opens the phone's share menu.
 */
@Composable
private fun ShareButton(book: Book) {
    val context = LocalContext.current
    var open by remember { mutableStateOf(false) }
    val opinion = book.share?.opinion?.ifBlank { null } ?: "📖 “${book.title}”" + (if (book.author.isNotBlank()) " van ${book.author}" else "")
    val askBack = book.share?.askBack
    fun send(text: String) {
        val intent = Intent(Intent.ACTION_SEND).apply {
            type = "text/plain"
            putExtra(Intent.EXTRA_TEXT, text)
        }
        context.startActivity(Intent.createChooser(intent, "Delen via"))
    }
    Box {
        IconButton(onClick = { if (askBack == null) send(opinion) else open = true }) {
            Icon(Icons.Default.Share, contentDescription = "Delen")
        }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            DropdownMenuItem(text = { Text("Wat ik ervan vind") }, onClick = { open = false; send(opinion) })
            askBack?.let { text ->
                DropdownMenuItem(text = { Text("Terugvragen bij ${book.lentTo.orEmpty()}") }, onClick = { open = false; send(text) })
            }
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
            book.series?.let { series ->
                Text(
                    series + (book.seriesNumber?.let { ", deel ${formatNumber(it)}" } ?: ""),
                    style = MaterialTheme.typography.bodySmall,
                )
            }
            Text(STATUSES[book.status] ?: book.status, style = MaterialTheme.typography.labelLarge)
            book.rating?.let {
                Text("${stars(it)}  ${formatRating(it)}", color = MaterialTheme.colorScheme.primary)
            }
            listOfNotNull(book.isbn, book.pages?.let { "$it pagina's" }, book.format)
                .takeIf { it.isNotEmpty() }
                ?.let { Text(it.joinToString(" · "), style = MaterialTheme.typography.bodySmall) }
            if (book.status == "reading") ProgressLine(book)
        }
    }
    About(book)
    book.review?.let { Text(it, style = MaterialTheme.typography.bodyMedium) }
}

/** Genre, translation, first publication and the description (tap to read it all). */
@Composable
private fun About(book: Book) {
    val small = MaterialTheme.typography.bodySmall
    val muted = MaterialTheme.colorScheme.onSurfaceVariant
    book.genre?.let { Text(it, style = small, color = muted) }
    val original = listOfNotNull(
        book.translator?.let { "Vertaald door $it" },
        book.originalLanguage?.takeIf { book.translator != null || book.originalTitle != null }
            ?.let { "uit het ${LANGUAGES[it] ?: it}" },
        book.originalTitle?.let { "($it)" },
    ).joinToString(" ")
    if (original.isNotEmpty()) Text(original, style = small, color = muted)
    book.firstPublished?.takeIf { it != book.publishedYear }?.let { Text("Eerst verschenen in $it", style = small, color = muted) }
    book.awards?.let { Text("Prijzen: $it", style = small, color = muted) }
    book.description?.let { description ->
        var expanded by rememberSaveable(book.id) { mutableStateOf(false) }
        Text(
            description,
            style = MaterialTheme.typography.bodyMedium,
            maxLines = if (expanded) Int.MAX_VALUE else 4,
            overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis,
            modifier = Modifier.clickable { expanded = !expanded },
        )
    }
}

@Composable
fun ProgressLine(book: Book) {
    val percent = book.progressPercent ?: return
    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
        LinearProgressIndicator(progress = { percent / 100f }, modifier = Modifier.fillMaxWidth())
        val pages = book.pages
        val page = book.progressPage
        Text(
            if (page != null && pages != null) "p. $page van $pages · $percent%" else "$percent%",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun ProgressCard(book: Book, busy: Boolean, onSave: (ProgressRequest) -> Unit) {
    val pages = book.pages
    var page by rememberSaveable(book.id) { mutableStateOf(book.progressPage?.toString().orEmpty()) }
    var percent by rememberSaveable(book.id) { mutableFloatStateOf((book.progressPercent ?: 0).toFloat()) }
    var toot by rememberSaveable { mutableStateOf(false) }
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text("Hoe ver ben je?", style = MaterialTheme.typography.titleMedium)
            if (pages != null) {
                OutlinedTextField(
                    value = page,
                    onValueChange = { page = it.filter(Char::isDigit).take(6) },
                    label = { Text("Op pagina (van $pages)") },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                    modifier = Modifier.fillMaxWidth(),
                )
            } else {
                Text("${percent.toInt()}% gelezen")
                Slider(value = percent, onValueChange = { percent = it }, valueRange = 0f..100f, steps = 19)
            }
            CheckRow("Post naar Mastodon", toot) { toot = it }
            Button(
                enabled = !busy && (pages == null || page.isNotEmpty()),
                onClick = {
                    onSave(
                        if (pages != null) ProgressRequest(page = page.toIntOrNull(), toot = toot)
                        else ProgressRequest(percent = percent.toInt(), toot = toot)
                    )
                },
            ) { Text("Bijwerken") }
        }
    }
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
private fun Reads(book: Book, onEdit: (Read) -> Unit, onAdd: () -> Unit) {
    Text("Leesmomenten", style = MaterialTheme.typography.titleMedium)
    book.reads.forEach { read ->
        Text(
            "${read.started ?: "—"}  →  ${read.finished ?: "…"}",
            modifier = Modifier
                .fillMaxWidth()
                .clickable { onEdit(read) }
                .padding(vertical = 8.dp),
        )
    }
    OutlinedButton(onClick = onAdd) { Text("Leesmoment toevoegen") }
}

@Composable
private fun Posts(book: Book) {
    if (book.posts.isEmpty()) return
    val uriHandler = LocalUriHandler.current
    Text("Op Mastodon", style = MaterialTheme.typography.titleMedium)
    book.posts.forEach { post ->
        val kind = when (post.kind) {
            "finished" -> "uitgelezen"
            "progress" -> "voortgang"
            else -> "begonnen"
        }
        val label = "${post.createdAt} — $kind"
        val url = post.url
        if (url != null && url.startsWith("https://")) {
            TextButton(onClick = { uriHandler.openUri(url) }) { Text(label) }
        } else {
            Text(label)
        }
    }
}

/** Edit or add a read. Dates are typed as JJJJ-MM-DD; empty means unknown. */
@Composable
private fun ReadDialog(read: Read?, onDismiss: () -> Unit, onSave: (ReadDates) -> Unit, onDelete: (() -> Unit)?) {
    var started by rememberSaveable { mutableStateOf(read?.started.orEmpty()) }
    var finished by rememberSaveable { mutableStateOf(read?.finished.orEmpty()) }
    val valid = listOf(started, finished).all { it.isEmpty() || isDate(it) } && (started.isNotEmpty() || finished.isNotEmpty())
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(if (read == null) "Leesmoment toevoegen" else "Leesmoment") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(started, { started = it.trim() }, label = { Text("Begonnen (JJJJ-MM-DD)") }, singleLine = true)
                OutlinedTextField(finished, { finished = it.trim() }, label = { Text("Uitgelezen (JJJJ-MM-DD)") }, singleLine = true)
                if (onDelete != null) {
                    TextButton(onClick = onDelete) { Text("Dit leesmoment verwijderen", color = MaterialTheme.colorScheme.error) }
                }
            }
        },
        confirmButton = {
            TextButton(enabled = valid, onClick = {
                onSave(ReadDates(started.ifEmpty { null }, finished.ifEmpty { null }))
            }) { Text("Opslaan") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Annuleren") } },
    )
}

private fun isDate(value: String): Boolean = runCatching { LocalDate.parse(value) }.isSuccess

@Composable
private fun Notes(book: Book, onAdd: () -> Unit, onDelete: (Note) -> Unit) {
    Text("Notities en citaten", style = MaterialTheme.typography.titleMedium)
    if (book.notes.isEmpty()) {
        Text("Bewaar hier mooie zinnen of je gedachten tijdens het lezen.", style = MaterialTheme.typography.bodySmall)
    }
    book.notes.forEach { note ->
        Column(Modifier.fillMaxWidth()) {
            Text(
                if (note.kind == "quote") "“${note.text}”" else note.text,
                style = if (note.kind == "quote") MaterialTheme.typography.bodyLarge.copy(fontStyle = FontStyle.Italic)
                else MaterialTheme.typography.bodyLarge,
            )
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    listOfNotNull(
                        note.page?.let { "p. $it" },
                        if (note.kind == "quote") "citaat" else "notitie",
                        if (note.isPublic) "openbaar" else "privé",
                    ).joinToString(" · "),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                // Notes still waiting to be sent have a negative id and can't be deleted yet.
                if (note.id > 0) {
                    TextButton(onClick = { onDelete(note) }) { Text("verwijder") }
                }
            }
        }
    }
    OutlinedButton(onClick = onAdd, enabled = book.id > 0) { Text("Notitie of citaat toevoegen") }
}

@Composable
private fun NoteDialog(onDismiss: () -> Unit, onSave: (NewNote) -> Unit) {
    var quote by rememberSaveable { mutableStateOf(false) }
    var text by rememberSaveable { mutableStateOf("") }
    var page by rememberSaveable { mutableStateOf("") }
    var isPublic by rememberSaveable { mutableStateOf(false) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(if (quote) "Citaat" else "Notitie") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    FilterChip(selected = !quote, onClick = { quote = false }, label = { Text("Notitie") })
                    FilterChip(selected = quote, onClick = { quote = true }, label = { Text("Citaat") })
                }
                OutlinedTextField(text, { text = it.take(5000) }, label = { Text("Tekst") }, minLines = 3)
                OutlinedTextField(
                    page, { page = it.filter(Char::isDigit).take(6) }, label = { Text("Pagina") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                )
                CheckRow("Openbaar (op de openbare boekpagina)", isPublic) { isPublic = it }
            }
        },
        confirmButton = {
            TextButton(enabled = text.isNotBlank(), onClick = {
                onSave(NewNote(if (quote) "quote" else "note", text.trim(), page.toIntOrNull(), isPublic))
            }) { Text("Bewaren") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Annuleren") } },
    )
}

@Composable
private fun Lending(book: Book, busy: Boolean, onLend: () -> Unit, onReturn: () -> Unit) {
    Text("Uitlenen", style = MaterialTheme.typography.titleMedium)
    val lentTo = book.lentTo
    if (lentTo != null) {
        Text("📕 Uitgeleend aan $lentTo sinds ${book.lentSince ?: "?"}.")
        Button(enabled = !busy, onClick = onReturn) { Text("Teruggekregen") }
    } else {
        OutlinedButton(enabled = !busy, onClick = onLend) { Text("Uitlenen") }
    }
    val past = book.loans.filter { it.returnedOn != null }
    if (past.isNotEmpty()) {
        Text(
            "Eerder: " + past.joinToString(", ") { "${it.borrower} (${it.lentOn})" },
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun LendDialog(borrowers: List<String>, onDismiss: () -> Unit, onSave: (LendRequest) -> Unit) {
    var name by rememberSaveable { mutableStateOf("") }
    var note by rememberSaveable { mutableStateOf("") }
    val suggestions = borrowers.filter { name.isBlank() || it.contains(name.trim(), ignoreCase = true) }.take(6)
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Uitlenen") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(name, { name = it.take(100) }, label = { Text("Aan") }, singleLine = true)
                if (suggestions.isNotEmpty()) {
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        suggestions.forEach { suggestion ->
                            FilterChip(selected = suggestion == name, onClick = { name = suggestion }, label = { Text(suggestion) })
                        }
                    }
                }
                OutlinedTextField(note, { note = it.take(500) }, label = { Text("Notitie (optioneel)") }, singleLine = true)
            }
        },
        confirmButton = {
            TextButton(enabled = name.isNotBlank(), onClick = {
                onSave(LendRequest(name.trim(), LocalDate.now().toString(), note.trim().ifEmpty { null }))
            }) { Text("Uitlenen") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Annuleren") } },
    )
}
