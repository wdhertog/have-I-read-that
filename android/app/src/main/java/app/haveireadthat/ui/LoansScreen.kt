package app.haveireadthat.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import app.haveireadthat.Loan
import app.haveireadthat.Person
import app.haveireadthat.Repository

/** Who has which of your books, and what they borrowed before. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LoansScreen(onBack: () -> Unit, onOpen: (Int) -> Unit) {
    val context = LocalContext.current
    val repository = remember { Repository(context) }
    var people by remember { mutableStateOf<List<Person>?>(null) }
    var offline by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(Unit) {
        try {
            val loaded = repository.loans()
            people = loaded.value.people
            offline = loaded.offline
        } catch (e: Exception) {
            error = e.message ?: "Laden mislukt"
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Uitgeleend") },
                navigationIcon = {
                    IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Terug") }
                },
            )
        },
    ) { padding ->
        LazyColumn(
            Modifier
                .fillMaxSize()
                .padding(padding),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            if (offline) {
                item { Text("Offline: je ziet alleen wat nu uitgeleend is.", style = MaterialTheme.typography.bodySmall) }
            }
            error?.let { item { Text(it, color = MaterialTheme.colorScheme.error) } }
            if (people?.isEmpty() == true) {
                item { Text("Je hebt nog niets uitgeleend. Dat doe je op de pagina van een boek.") }
            }
            items(people.orEmpty(), key = { it.name }) { person -> PersonCard(person, onOpen) }
        }
    }
}

@Composable
private fun PersonCard(person: Person, onOpen: (Int) -> Unit) {
    var showHistory by rememberSaveable(person.name) { mutableStateOf(false) }
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(person.name, style = MaterialTheme.typography.titleMedium)
            if (person.current.isEmpty()) {
                Text("Heeft nu niets van je.", style = MaterialTheme.typography.bodySmall)
            }
            person.current.forEach { loan -> LoanLine(loan, onOpen, current = true) }
            if (person.history.isNotEmpty()) {
                TextButton(onClick = { showHistory = !showHistory }) {
                    Text(if (showHistory) "Verberg eerder geleend" else "Eerder geleend (${person.history.size})")
                }
                if (showHistory) person.history.forEach { loan -> LoanLine(loan, onOpen, current = false) }
            }
        }
    }
}

@Composable
private fun LoanLine(loan: Loan, onOpen: (Int) -> Unit, current: Boolean) {
    Column(
        Modifier
            .fillMaxWidth()
            .clickable { onOpen(loan.bookId) }
            .padding(vertical = 4.dp),
    ) {
        Text(loan.title ?: "Boek", style = MaterialTheme.typography.bodyLarge)
        Text(
            if (current) "sinds ${loan.lentOn}" + (if (loan.days > 0) " (${loan.days} dagen)" else "") + (loan.note?.let { " · $it" } ?: "")
            else "${loan.lentOn} – ${loan.returnedOn}",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}
