package app.haveireadthat.ui

import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
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
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Slider
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import app.haveireadthat.FORMATS
import app.haveireadthat.NewBook
import app.haveireadthat.STATUSES
import app.haveireadthat.Settings
import kotlinx.coroutines.launch

/** Edit a book's details. Reads and progress are changed on the book screen itself. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EditScreen(settings: Settings, id: Int, onDone: () -> Unit) {
    val scope = rememberCoroutineScope()
    var loaded by rememberSaveable { mutableStateOf(false) }
    var title by rememberSaveable { mutableStateOf("") }
    var author by rememberSaveable { mutableStateOf("") }
    var isbn by rememberSaveable { mutableStateOf("") }
    var pages by rememberSaveable { mutableStateOf("") }
    var format by rememberSaveable { mutableStateOf<String?>(null) }
    var status by rememberSaveable { mutableStateOf("to-read") }
    var rating by rememberSaveable { mutableFloatStateOf(0f) }
    var review by rememberSaveable { mutableStateOf("") }
    var tags by rememberSaveable { mutableStateOf("") }
    var owned by rememberSaveable { mutableStateOf(false) }
    var message by rememberSaveable { mutableStateOf<String?>(null) }
    var busy by rememberSaveable { mutableStateOf(false) }

    LaunchedEffect(id) {
        if (loaded) return@LaunchedEffect // keep edits across rotation
        try {
            val book = settings.client().book(id)
            title = book.title
            author = book.author
            isbn = book.isbn.orEmpty()
            pages = book.pages?.toString().orEmpty()
            format = book.format
            status = book.status
            rating = book.rating?.toFloat() ?: 0f
            review = book.review.orEmpty()
            tags = book.tags.orEmpty()
            owned = book.owned
            loaded = true
        } catch (e: Exception) {
            message = "Laden mislukt: ${e.message}"
        }
    }

    fun save() {
        busy = true
        message = null
        scope.launch {
            try {
                settings.client().update(
                    id,
                    NewBook(
                        title = title.trim(),
                        author = author.trim(),
                        isbn = isbn.trim().ifEmpty { null },
                        pages = pages.toIntOrNull(),
                        status = status,
                        rating = rating.takeIf { it > 0 }?.toDouble(),
                        review = review.trim().ifEmpty { null },
                        format = format,
                        tags = tags.trim().ifEmpty { null },
                        owned = owned,
                    ),
                )
                onDone()
            } catch (e: Exception) {
                message = "Opslaan mislukt: ${e.message}"
                busy = false
            }
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Boek bewerken") },
                navigationIcon = {
                    IconButton(onClick = onDone) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Terug") }
                },
            )
        },
    ) { padding ->
        if (!loaded) {
            Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) {
                message?.let { Text(it, color = MaterialTheme.colorScheme.error) } ?: CircularProgressIndicator()
            }
            return@Scaffold
        }
        Column(
            Modifier
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            OutlinedTextField(title, { title = it }, label = { Text("Titel") }, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(author, { author = it }, label = { Text("Auteur(s)") }, modifier = Modifier.fillMaxWidth())
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                OutlinedTextField(
                    isbn, { isbn = it }, label = { Text("ISBN") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                    modifier = Modifier.weight(2f),
                )
                OutlinedTextField(
                    pages, { pages = it.filter(Char::isDigit).take(6) }, label = { Text("Pagina's") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                    modifier = Modifier.weight(1f),
                )
            }
            Text("Status", style = MaterialTheme.typography.labelLarge)
            Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                STATUSES.forEach { (key, label) ->
                    FilterChip(selected = status == key, onClick = { status = key }, label = { Text(label) })
                }
            }
            Text("Formaat", style = MaterialTheme.typography.labelLarge)
            Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FORMATS.forEach { (key, label) ->
                    FilterChip(
                        selected = format == key,
                        onClick = { format = if (format == key) null else key },
                        label = { Text(label) },
                    )
                }
            }
            Text(
                if (rating > 0) "Beoordeling: ${stars(rating.toDouble())}  ${formatRating(rating.toDouble())}" else "Geen beoordeling",
                color = MaterialTheme.colorScheme.primary,
            )
            Slider(value = rating, onValueChange = { rating = it }, valueRange = 0f..5f, steps = 19)
            OutlinedTextField(review, { review = it }, label = { Text("Recensie") }, minLines = 3, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(tags, { tags = it }, label = { Text("Tags") }, singleLine = true, modifier = Modifier.fillMaxWidth())
            CheckRow("In bezit", owned) { owned = it }
            message?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            Button(onClick = ::save, enabled = !busy && title.isNotBlank(), modifier = Modifier.fillMaxWidth()) {
                Text("Opslaan")
            }
        }
    }
}
