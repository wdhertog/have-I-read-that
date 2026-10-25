package app.haveireadthat.ui

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
import app.haveireadthat.NewBook
import app.haveireadthat.STATUSES
import app.haveireadthat.Settings
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.codescanner.GmsBarcodeScannerOptions
import com.google.mlkit.vision.codescanner.GmsBarcodeScanning
import kotlinx.coroutines.launch

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AddScreen(settings: Settings, onBack: () -> Unit, onOpen: (Int) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var title by rememberSaveable { mutableStateOf("") }
    var author by rememberSaveable { mutableStateOf("") }
    var isbn by rememberSaveable { mutableStateOf("") }
    var pages by rememberSaveable { mutableStateOf("") }
    var status by rememberSaveable { mutableStateOf("reading") }
    var toot by rememberSaveable { mutableStateOf(settings.postByDefault) }
    var message by rememberSaveable { mutableStateOf<String?>(null) }
    var existingId by rememberSaveable { mutableStateOf<Int?>(null) }
    var busy by rememberSaveable { mutableStateOf(false) }

    fun lookup(code: String) {
        busy = true
        message = "Opzoeken…"
        existingId = null
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

    fun scan() {
        val options = GmsBarcodeScannerOptions.Builder()
            .setBarcodeFormats(Barcode.FORMAT_EAN_13, Barcode.FORMAT_EAN_8, Barcode.FORMAT_UPC_A)
            .enableAutoZoom()
            .build()
        GmsBarcodeScanning.getClient(context, options).startScan()
            .addOnSuccessListener { barcode -> barcode.rawValue?.let(::lookup) }
            .addOnFailureListener { e -> message = "Scannen mislukt: ${e.message}" }
    }

    fun save() {
        busy = true
        message = null
        scope.launch {
            try {
                val response = settings.client().create(
                    NewBook(
                        title = title.trim(),
                        author = author.trim(),
                        isbn = isbn.trim().ifEmpty { null },
                        pages = pages.toIntOrNull(),
                        status = status,
                        toot = toot && (status == "reading" || status == "read"),
                    )
                )
                response.toot?.takeIf { !it.posted }?.let {
                    android.widget.Toast.makeText(context, "Niet gepost: ${it.error}", android.widget.Toast.LENGTH_LONG).show()
                }
                onOpen(response.book.id)
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
                Text("📷  Scan ISBN-barcode")
            }
            message?.let { Text(it, color = MaterialTheme.colorScheme.secondary) }
            existingId?.let { id ->
                Card(Modifier.fillMaxWidth()) {
                    Button(onClick = { onOpen(id) }, modifier = Modifier.padding(12.dp)) { Text("Open dat boek") }
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
            Button(onClick = ::save, enabled = !busy && title.isNotBlank(), modifier = Modifier.fillMaxWidth()) {
                Text("Opslaan")
            }
        }
    }
}
