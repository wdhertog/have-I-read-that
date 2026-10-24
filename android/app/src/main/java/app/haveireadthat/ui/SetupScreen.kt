package app.haveireadthat.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import app.haveireadthat.ApiClient
import app.haveireadthat.Settings
import kotlinx.coroutines.launch

@Composable
fun SetupScreen(settings: Settings, onDone: () -> Unit) {
    var server by rememberSaveable { mutableStateOf(settings.serverUrl) }
    var token by rememberSaveable { mutableStateOf("") }
    var error by rememberSaveable { mutableStateOf<String?>(null) }
    var busy by rememberSaveable { mutableStateOf(false) }
    val scope = rememberCoroutineScope()

    Column(
        Modifier
            .fillMaxSize()
            .safeDrawingPadding()
            .imePadding()
            .verticalScroll(rememberScrollState())
            .padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        Text("📚 Have I read that?", style = MaterialTheme.typography.headlineMedium)
        Text(
            "Vul het adres van je site in en een token. Een token maak je op de site aan onder Instellingen → Android-app.",
            style = MaterialTheme.typography.bodyMedium,
        )
        OutlinedTextField(
            value = server,
            onValueChange = { server = it },
            label = { Text("Serveradres") },
            placeholder = { Text("https://boeken.example.nl") },
            singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
            modifier = Modifier.fillMaxWidth(),
        )
        OutlinedTextField(
            value = token,
            onValueChange = { token = it.trim() },
            label = { Text("Token") },
            singleLine = true,
            modifier = Modifier.fillMaxWidth(),
        )
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        Button(
            enabled = !busy && server.isNotBlank() && token.isNotBlank(),
            onClick = {
                busy = true
                error = null
                scope.launch {
                    try {
                        val me = ApiClient(server, token).me()
                        settings.serverUrl = server.trim()
                        settings.token = token
                        settings.postByDefault = me.mastodon.postByDefault
                        onDone()
                    } catch (e: Exception) {
                        error = "Verbinden mislukt: ${e.message}"
                    } finally {
                        busy = false
                    }
                }
            },
        ) {
            if (busy) CircularProgressIndicator(Modifier.padding(end = 8.dp).size(18.dp), strokeWidth = 2.dp)
            Text("Verbinden")
        }
    }
}
