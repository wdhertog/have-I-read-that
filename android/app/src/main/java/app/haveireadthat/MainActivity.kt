package app.haveireadthat

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
import androidx.compose.runtime.remember
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import app.haveireadthat.ui.AddScreen
import app.haveireadthat.ui.BookScreen
import app.haveireadthat.ui.EditScreen
import app.haveireadthat.ui.LibraryScreen
import app.haveireadthat.ui.SetupScreen

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent { AppTheme { App() } }
    }
}

@Composable
fun App() {
    val context = LocalContext.current
    val settings = remember { Settings(context.applicationContext) }
    val nav = rememberNavController()

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
        composable("add") {
            AddScreen(
                settings = settings,
                onBack = { nav.popBackStack() },
                onOpen = { id -> nav.navigate("book/$id") { popUpTo("add") { inclusive = true } } },
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
