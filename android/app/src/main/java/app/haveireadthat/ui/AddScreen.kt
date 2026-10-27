package app.haveireadthat.ui

import app.haveireadthat.Shared
import app.haveireadthat.Repository
import app.haveireadthat.Outcome
import app.haveireadthat.LookupResult
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.compose.runtime.remember
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import app.haveireadthat.DuplicateException
import app.haveireadthat.NewBook
import app.haveireadthat.STATUSES
import app.haveireadthat.Settings
import com.google.zxing.client.android.Intents
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import kotlinx.coroutines.launch

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AddScreen(settings: Settings, onBack: () -> Unit, onOpen: (Int) -> Unit, shared: String? = null) {
    val context = LocalContext.current
    val repository = remember { Repository(context) }
    val scope = rememberCoroutineScope()
    var results by remember { mutableStateOf<List<LookupResult>>(emptyList()) }
    var sharedHandled by rememberSaveable { mutableStateOf(false) }
    var title by rememberSaveable { mutableStateOf("") }
    var author by rememberSaveable { mutableStateOf("") }
    var isbn by rememberSaveable { mutableStateOf("") }
    var pages by rememberSaveable { mutableStateOf("") }
    // From a scanned ISBN: saved with the book without showing extra fields.
    var publisher by rememberSaveable { mutableStateOf<String?>(null) }
    var year by rememberSaveable { mutableStateOf<Int?>(null) }
    var series by rememberSaveable { mutableStateOf<String?>(null) }
    var seriesNumber by rememberSaveable { mutableStateOf<Double?>(null) }
    var status by rememberSaveable { mutableStateOf("reading") }
    var toot by rememberSaveable { mutableStateOf(settings.postByDefault) }
    var message by rememberSaveable { mutableStateOf<String?>(null) }
    var existingId by rememberSaveable { mutableStateOf<Int?>(null) }
    // Set when saving was refused because the book already exists.
    var duplicateOnSave by rememberSaveable { mutableStateOf(false) }
    var busy by rememberSaveable { mutableStateOf(false) }

    fun lookup(code: String) {
        busy = true
        message = "Opzoeken…"
        existingId = null
        publisher = null
        year = null
        series = null
        seriesNumber = null
        scope.launch {
            try {
                val result = settings.client().lookup(code)
                isbn = result.isbn
                val existing = result.existing
                val found = result.result
                when {
                    existing != null -> {
                        existingId = existing.id
                        message = "“${existing.title}” staat al in je bibliotheek."
                    }
                    found != null -> {
                        title = found.title
                        author = found.author
                        pages = found.pages?.toString().orEmpty()
                        publisher = found.publisher
                        year = found.year
                        series = found.series
                        seriesNumber = found.seriesNumber
                        message = null
                    }
                    else -> message = result.error?.let { "Opzoeken mislukt: $it" }
                        ?: "Niet gevonden bij Open Library; vul het zelf in."
                }
            } catch (e: Exception) {
                isbn = code
                message = "Opzoeken mislukt: ${e.message}"
            } finally {
                busy = false
            }
        }
    }

    fun search(terms: String) {
        busy = true
        message = "Zoeken naar “$terms”…"
        scope.launch {
            try {
                val response = settings.client().search(terms)
                results = response.results
                message = when {
                    response.results.isNotEmpty() -> "Kies het juiste boek:"
                    response.error != null -> "Zoeken mislukt: ${response.error}"
                    else -> "Niets gevonden voor “$terms”; vul het zelf in."
                }
                if (response.results.isEmpty() && title.isBlank()) title = terms
            } catch (e: Exception) {
                message = "Zoeken mislukt: ${e.message}"
                if (title.isBlank()) title = terms
            } finally {
                busy = false
            }
        }
    }

    // Something shared from another app: an ISBN is looked up, anything else searched for.
    LaunchedEffect(shared) {
        if (shared == null || sharedHandled) return@LaunchedEffect
        sharedHandled = true
        val code = Shared.findIsbn(shared)
        val terms = Shared.searchTerms(shared)
        when {
            code != null -> lookup(code)
            terms != null -> search(terms)
            else -> message = "Hier kon ik geen boek in vinden; vul het zelf in."
        }
    }

    val scanner = rememberLauncherForActivityResult(ScanContract()) { result ->
        val code = result.contents
        when {
            code != null -> lookup(code)
            result.originalIntent?.hasExtra(Intents.Scan.MISSING_CAMERA_PERMISSION) == true ->
                message = "Geef de app toestemming voor de camera om te kunnen scannen."
        }
    }

    fun scan() {
        scanner.launch(
            ScanOptions()
                .setDesiredBarcodeFormats(ScanOptions.EAN_13, ScanOptions.EAN_8, ScanOptions.UPC_A)
                .setPrompt("Richt de camera op de streepjescode met het ISBN")
                .setBeepEnabled(false)
                .setOrientationLocked(false),
        )
    }

    fun save(allowDuplicate: Boolean = false) {
        busy = true
        message = null
        existingId = null
        duplicateOnSave = false
        scope.launch {
            try {
                val outcome = repository.create(
                    NewBook(
                        title = title.trim(),
                        author = author.trim(),
                        isbn = isbn.trim().ifEmpty { null },
                        pages = pages.toIntOrNull(),
                        status = status,
                        publisher = publisher,
                        publishedYear = year,
                        series = series,
                        seriesNumber = seriesNumber,
                        toot = toot && (status == "reading" || status == "read"),
                        allowDuplicate = allowDuplicate,
                    )
                )
                when (outcome) {
                    is Outcome.Sent -> {
                        outcome.response.toot?.takeIf { !it.posted }?.let {
                            android.widget.Toast.makeText(context, "Niet gepost: ${it.error}", android.widget.Toast.LENGTH_LONG).show()
                        }
                        onOpen(outcome.response.book.id)
                    }
                    is Outcome.Queued -> {
                        android.widget.Toast.makeText(
                            context, "Opgeslagen op je telefoon; wordt verstuurd zodra je verbinding hebt.", android.widget.Toast.LENGTH_LONG,
                        ).show()
                        onOpen(outcome.book.id)
                    }
                }
            } catch (e: DuplicateException) {
                message = e.message
                existingId = e.existing.id
                duplicateOnSave = true
                busy = false
            } catch (e: Exception) {
                message = "Opslaan mislukt: ${e.message}"
                busy = false
            }
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Boek toevoegen") },
                navigationIcon = {
                    IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Terug") }
                },
            )
        },
    ) { padding ->
        Column(
            Modifier
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            FilledTonalButton(onClick = ::scan, enabled = !busy, modifier = Modifier.fillMaxWidth()) {
                Text("Scan ISBN-barcode")
            }
            message?.let { Text(it, color = MaterialTheme.colorScheme.secondary) }
            if (results.isNotEmpty()) {
                Card(Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(vertical = 4.dp)) {
                        results.forEach { result ->
                            Text(
                                buildString {
                                    append(result.title)
                                    if (result.author.isNotBlank()) append(" — ").append(result.author)
                                    result.year?.let { append(" ($it)") }
                                },
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .clickable {
                                        title = result.title
                                        author = result.author
                                        isbn = result.isbn
                                        pages = result.pages?.toString().orEmpty()
                                        publisher = result.publisher
                                        year = result.year
                                        series = result.series
                                        seriesNumber = result.seriesNumber
                                        results = emptyList()
                                        message = null
                                    }
                                    .padding(horizontal = 16.dp, vertical = 10.dp),
                            )
                        }
                    }
                }
            }
            existingId?.let { id ->
                Card(Modifier.fillMaxWidth()) {
                    Row(Modifier.padding(12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Button(onClick = { onOpen(id) }) { Text("Open dat boek") }
                        if (duplicateOnSave) {
                            OutlinedButton(onClick = { save(allowDuplicate = true) }, enabled = !busy) { Text("Toch toevoegen") }
                        }
                    }
                }
            }
            OutlinedTextField(title, { title = it }, label = { Text("Titel") }, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(author, { author = it }, label = { Text("Auteur(s)") }, modifier = Modifier.fillMaxWidth())
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                OutlinedTextField(
                    isbn, { isbn = it }, label = { Text("ISBN") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                    modifier = Modifier.weight(2f),
                )
                OutlinedTextField(
                    pages, { pages = it.filter(Char::isDigit) }, label = { Text("Pagina's") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                    modifier = Modifier.weight(1f),
                )
            }
            Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                STATUSES.forEach { (key, label) ->
                    FilterChip(selected = status == key, onClick = { status = key }, label = { Text(label) })
                }
            }
            if (status == "reading" || status == "read") {
                CheckRow("Post naar Mastodon", toot) { toot = it }
            }
            Button(onClick = { save() }, enabled = !busy && title.isNotBlank(), modifier = Modifier.fillMaxWidth()) {
                Text("Opslaan")
            }
        }
    }
}
