package app.haveireadthat.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ExitToApp
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.ui.platform.LocalContext
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.material3.TextButton
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import app.haveireadthat.Book
import kotlinx.coroutines.launch
import app.haveireadthat.Repository
import app.haveireadthat.LocalState
import app.haveireadthat.Goal
import app.haveireadthat.STATUSES
import app.haveireadthat.Settings

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LibraryScreen(settings: Settings, onOpen: (Int) -> Unit, onAdd: () -> Unit, onLoans: () -> Unit, onLogout: () -> Unit) {
    val context = LocalContext.current
    val repository = remember { Repository(context) }
    val scope = rememberCoroutineScope()
    var status by rememberSaveable { mutableStateOf<String?>(null) }
    var shelf by rememberSaveable { mutableStateOf<String?>(null) }
    var query by rememberSaveable { mutableStateOf("") }
    var books by remember { mutableStateOf<List<Book>?>(null) }
    var state by remember { mutableStateOf(LocalState()) }
    var confirmLogout by remember { mutableStateOf(false) }
    var offline by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var refreshing by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }

    // The whole library lives on the phone; filtering and searching happen here, offline too.
    LaunchedEffect(reload) {
        if (books == null) books = repository.state().books.takeIf { it.isNotEmpty() }
        refreshing = true
        try {
            val loaded = repository.library()
            books = loaded.value
            offline = loaded.offline
            error = null
        } catch (e: Exception) {
            error = e.message ?: "Laden mislukt"
        } finally {
            state = repository.state()
            refreshing = false
        }
    }

    // Shelves are tags; StoryGraph's come in as shelves.
    val shelves = remember(books) {
        books.orEmpty().flatMap { tagList(it.tags) }.groupBy { it.lowercase() }.map { (_, names) -> names.first() }
            .sortedBy { it.lowercase() }
    }
    val shown = remember(books, status, query, shelf) {
        val q = query.trim().lowercase()
        books.orEmpty().filter { book ->
            (status == null || book.status == status) &&
                (shelf == null || tagList(book.tags).any { it.equals(shelf, ignoreCase = true) }) &&
                (q.isEmpty() || listOfNotNull(book.title, book.author, book.tags, book.isbn, book.series).any { it.lowercase().contains(q) })
        }.sortedWith(compareByDescending<Book> { it.status == "reading" })
    }

    if (confirmLogout) {
        val waiting = state.queue.size
        androidx.compose.material3.AlertDialog(
            onDismissRequest = { confirmLogout = false },
            title = { Text("Uitloggen?") },
            text = {
                Text(
                    "Je bibliotheek op deze telefoon wordt gewist." +
                        if (waiting > 0) " Let op: ${if (waiting == 1) "1 wijziging is" else "$waiting wijzigingen zijn"} nog niet verstuurd en ${if (waiting == 1) "gaat" else "gaan"} verloren." else "",
                )
            },
            confirmButton = {
                androidx.compose.material3.TextButton(onClick = { confirmLogout = false; onLogout() }) { Text("Uitloggen") }
            },
            dismissButton = { androidx.compose.material3.TextButton(onClick = { confirmLogout = false }) { Text("Annuleren") } },
        )
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Boeken") },
                actions = {
                    IconButton(onClick = onLoans) {
                        Icon(Icons.Default.Person, contentDescription = "Uitgeleend")
                    }
                    IconButton(onClick = { confirmLogout = true }) {
                        Icon(Icons.AutoMirrored.Filled.ExitToApp, contentDescription = "Uitloggen")
                    }
                },
            )
        },
        floatingActionButton = {
            FloatingActionButton(onClick = onAdd) { Icon(Icons.Default.Add, contentDescription = "Boek toevoegen") }
        },
    ) { padding ->
        Column(Modifier.padding(padding)) {
            OutlinedTextField(
                value = query,
                onValueChange = { query = it },
                leadingIcon = { Icon(Icons.Default.Search, contentDescription = null) },
                placeholder = { Text("Zoek titel, auteur, tag…") },
                singleLine = true,
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = 16.dp),
            )
            Row(
                Modifier
                    .horizontalScroll(rememberScrollState())
                    .padding(horizontal = 16.dp, vertical = 8.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                FilterChip(selected = status == null, onClick = { status = null }, label = { Text("Alles") })
                STATUSES.forEach { (key, label) ->
                    FilterChip(selected = status == key, onClick = { status = key }, label = { Text(label) })
                }
            }
            if (shelves.isNotEmpty()) {
                Row(
                    Modifier
                        .horizontalScroll(rememberScrollState())
                        .padding(horizontal = 16.dp),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    shelves.forEach { name ->
                        FilterChip(
                            selected = shelf.equals(name, ignoreCase = true),
                            onClick = { shelf = if (shelf.equals(name, ignoreCase = true)) null else name },
                            label = { Text("🏷 $name") },
                        )
                    }
                }
            }
            if (offline) {
                Notice("Offline: je ziet de versie van ${state.updatedAt ?: "eerder"}. Wijzigingen worden verstuurd zodra je weer verbinding hebt.")
            }
            if (state.queue.isNotEmpty()) {
                val n = state.queue.size
                Notice(if (n == 1) "1 wijziging wacht op verbinding." else "$n wijzigingen wachten op verbinding.")
            }
            if (state.failures.isNotEmpty()) {
                Notice(
                    "Niet gelukt bij het versturen:\n" + state.failures.joinToString("\n"),
                    error = true,
                    onDismiss = {
                        scope.launch {
                            repository.clearFailures()
                            state = repository.state()
                        }
                    },
                )
            }
            state.goal?.let { GoalLine(it) }
            error?.let {
                Text(it, color = MaterialTheme.colorScheme.error, modifier = Modifier.padding(16.dp))
            }
            PullToRefreshBox(
                isRefreshing = refreshing,
                onRefresh = { reload++ },
                modifier = Modifier.fillMaxSize(),
            ) {
                LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(bottom = 88.dp)) {
                    if (books != null && shown.isEmpty()) {
                        item { Text("Geen boeken gevonden.", modifier = Modifier.padding(16.dp)) }
                    }
                    items(shown, key = { it.id }) { book ->
                        BookRow(book) { onOpen(book.id) }
                        HorizontalDivider()
                    }
                }
            }
        }
    }
}

@Composable
private fun Notice(text: String, error: Boolean = false, onDismiss: (() -> Unit)? = null) {
    Row(
        Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            text,
            style = MaterialTheme.typography.bodySmall,
            color = if (error) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.weight(1f),
        )
        if (onDismiss != null) {
            TextButton(onClick = onDismiss) { Text("Wissen") }
        }
    }
}

@Composable
private fun BookRow(book: Book, onClick: () -> Unit) {
    Row(
        Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick)
            .padding(horizontal = 16.dp, vertical = 10.dp),
        horizontalArrangement = Arrangement.spacedBy(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Cover(book, 44.dp)
        Column(Modifier.weight(1f)) {
            Text(book.title, style = MaterialTheme.typography.titleMedium, maxLines = 2, overflow = TextOverflow.Ellipsis)
            if (book.author.isNotBlank()) {
                Text(book.author, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            val meta = listOfNotNull(
                STATUSES[book.status],
                book.series?.let { s -> s + (book.seriesNumber?.let { " #${formatNumber(it)}" } ?: "") },
                stars(book.rating).ifEmpty { null },
                book.lastFinished,
                book.lentTo?.let { "📕 bij $it" },
            )
            Text(meta.joinToString(" · "), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            if (book.status == "reading") ProgressLine(book)
        }
    }
}

@Composable
private fun GoalLine(goal: Goal) {
    val target = goal.goal ?: return
    Column(Modifier.padding(horizontal = 16.dp, vertical = 4.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(
            if (goal.read >= target) "Leesdoel ${goal.year} gehaald: ${goal.read} van $target boeken 🎉"
            else "Leesdoel ${goal.year}: ${goal.read} van $target boeken",
            style = MaterialTheme.typography.bodyMedium,
        )
        LinearProgressIndicator(progress = { (goal.read.toFloat() / target).coerceAtMost(1f) }, modifier = Modifier.fillMaxWidth())
    }
}

private fun tagList(tags: String?): List<String> = tags.orEmpty().split(',').map { it.trim() }.filter { it.isNotEmpty() }
