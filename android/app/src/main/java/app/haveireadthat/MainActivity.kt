package app.haveireadthat

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.dynamicDarkColorScheme
import androidx.compose.material3.dynamicLightColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.runtime.remember
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import androidx.work.WorkManager
import coil.Coil
import coil.ImageLoader
import coil.ImageLoaderFactory
import okhttp3.OkHttpClient
import app.haveireadthat.ui.AddScreen
import app.haveireadthat.ui.BookScreen
import app.haveireadthat.ui.EditScreen
import app.haveireadthat.ui.LibraryScreen
import app.haveireadthat.ui.SetupScreen

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        Coil.setImageLoader(coverLoader(applicationContext))
        enableEdgeToEdge()
        val shared = sharedText(intent)
        setContent { AppTheme { App(shared) } }
    }

    /** Text from "Delen" in another app: the subject (often the title) and the text or link. */
    private fun sharedText(intent: Intent?): String? {
        if (intent?.action != Intent.ACTION_SEND) return null
        val parts = listOfNotNull(intent.getStringExtra(Intent.EXTRA_SUBJECT), intent.getStringExtra(Intent.EXTRA_TEXT))
        return parts.joinToString("\n").take(2_000).ifBlank { null }
    }
}

@Composable
fun App(shared: String? = null) {
    val context = LocalContext.current
    val settings = remember { Settings(context.applicationContext) }
    val nav = rememberNavController()

    // Opened through "Delen": go straight to adding a book (once you're logged in),
    // and only once, not again after rotating the screen.
    var sharedHandled by rememberSaveable { mutableStateOf(false) }
    LaunchedEffect(shared) {
        if (shared != null && !sharedHandled && settings.configured) {
            sharedHandled = true
            nav.navigate("add?shared=${Uri.encode(shared)}")
        }
    }

    NavHost(nav, startDestination = if (settings.configured) "library" else "setup") {
        composable("setup") {
            SetupScreen(settings) {
                nav.navigate("library") { popUpTo("setup") { inclusive = true } }
            }
        }
        composable("library") {
            LibraryScreen(
                settings = settings,
                onOpen = { id -> nav.navigate("book/$id") },
                onAdd = { nav.navigate("add") },
                onLogout = {
                    settings.clear()
                    Repository.forget(context)
                    WorkManager.getInstance(context).cancelUniqueWork("sync")
                    nav.navigate("setup") { popUpTo("library") { inclusive = true } }
                },
            )
        }
        composable("book/{id}", arguments = listOf(navArgument("id") { type = NavType.IntType })) { entry ->
            BookScreen(
                settings,
                entry.arguments?.getInt("id") ?: 0,
                onBack = { nav.popBackStack() },
                onEdit = { id -> nav.navigate("edit/$id") },
            )
        }
        composable("edit/{id}", arguments = listOf(navArgument("id") { type = NavType.IntType })) { entry ->
            EditScreen(settings, entry.arguments?.getInt("id") ?: 0, onDone = { nav.popBackStack() })
        }
        composable(
            "add?shared={shared}",
            arguments = listOf(navArgument("shared") { type = NavType.StringType; nullable = true; defaultValue = null }),
        ) { entry ->
            AddScreen(
                shared = entry.arguments?.getString("shared"),
                settings = settings,
                onBack = { nav.popBackStack() },
                onOpen = { id -> nav.navigate("book/$id") { popUpTo("add?shared={shared}") { inclusive = true } } },
            )
        }
    }
}

@Composable
fun AppTheme(content: @Composable () -> Unit) {
    val dark = isSystemInDarkTheme()
    val context = LocalContext.current
    val colors = when {
        Build.VERSION.SDK_INT >= Build.VERSION_CODES.S ->
            if (dark) dynamicDarkColorScheme(context) else dynamicLightColorScheme(context)
        dark -> darkColorScheme(primary = Color(0xFF9B85FF))
        else -> lightColorScheme(primary = Color(0xFF6D4AFF))
    }
    MaterialTheme(colorScheme = colors, content = content)
}

/**
 * Covers come from your own server; private ones need the token. It is only added
 * to HTTPS requests for that exact server, never to other hosts.
 */
private fun coverLoader(context: Context) = ImageLoaderFactory {
    ImageLoader.Builder(context)
        .okHttpClient {
            OkHttpClient.Builder()
                .followSslRedirects(false)
                .addInterceptor { chain ->
                    val request = chain.request()
                    val settings = Settings(context)
                    val server = runCatching { ApiClient.baseUrl(settings.serverUrl) }.getOrNull()
                    val ours = server != null && settings.token.isNotBlank() && request.url.isHttps &&
                        request.url.host == server.host && request.url.port == server.port
                    chain.proceed(
                        if (ours) request.newBuilder().header("Authorization", "Bearer ${settings.token}").build()
                        else request
                    )
                }
                .build()
        }
        .build()
}
