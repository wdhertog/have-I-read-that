package app.haveireadthat

import android.content.Context

/** Server address and API token, kept in private app storage. */
class Settings(context: Context) {
    private val prefs = context.getSharedPreferences("settings", Context.MODE_PRIVATE)

    var serverUrl: String
        get() = prefs.getString("server_url", "").orEmpty()
        set(value) = prefs.edit().putString("server_url", value).apply()

    var token: String
        get() = prefs.getString("token", "").orEmpty()
        set(value) = prefs.edit().putString("token", value).apply()

    var postByDefault: Boolean
        get() = prefs.getBoolean("post_by_default", true)
        set(value) = prefs.edit().putBoolean("post_by_default", value).apply()

    val configured: Boolean get() = serverUrl.isNotBlank() && token.isNotBlank()

    fun client(): ApiClient = ApiClient(serverUrl, token)

    fun clear() = prefs.edit().clear().apply()
}
