package app.haveireadthat

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.decodeFromString
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException
import java.util.concurrent.TimeUnit

val STATUSES = linkedMapOf(
    "reading" to "Aan het lezen",
    "to-read" to "Wil ik lezen",
    "read" to "Gelezen",
    "dnf" to "Niet uitgelezen",
)

@Serializable
data class Book(
    val id: Int,
    val title: String,
    val author: String = "",
    val isbn: String? = null,
    val format: String? = null,
    val pages: Int? = null,
    val status: String,
    val rating: Double? = null,
    val review: String? = null,
    val tags: String? = null,
    val owned: Boolean = false,
    @SerialName("date_added") val dateAdded: String? = null,
    @SerialName("last_finished") val lastFinished: String? = null,
    @SerialName("cover_url") val coverUrl: String? = null,
    val reads: List<Read> = emptyList(),
    val posts: List<Post> = emptyList(),
)

@Serializable
data class Read(val id: Int, val started: String? = null, val finished: String? = null)

@Serializable
data class Post(val id: Int, val kind: String, val url: String? = null, @SerialName("created_at") val createdAt: String)

@Serializable
data class TootResult(val posted: Boolean, val url: String? = null, val error: String? = null)

@Serializable
data class BookResponse(val book: Book, val toot: TootResult? = null)

@Serializable
data class BooksResponse(val books: List<Book>)

@Serializable
data class LookupResult(
    val title: String,
    val author: String = "",
    val isbn: String = "",
    val pages: Int? = null,
    val year: Int? = null,
)

@Serializable
data class LookupResponse(
    val isbn: String,
    val existing: Book? = null,
    val result: LookupResult? = null,
    val error: String? = null,
)

@Serializable
data class MastodonInfo(
    val configured: Boolean,
    val instance: String = "",
    @SerialName("post_by_default") val postByDefault: Boolean = true,
)

@Serializable
data class Me(val ok: Boolean, val mastodon: MastodonInfo)

@Serializable
data class NewBook(
    val title: String,
    val author: String,
    val isbn: String? = null,
    val pages: Int? = null,
    val status: String,
    val rating: Double? = null,
    val review: String? = null,
    val toot: Boolean = false,
)

@Serializable
data class StartRequest(val date: String, val toot: Boolean)

@Serializable
data class FinishRequest(
    val date: String,
    val rating: Double? = null,
    val review: String? = null,
    val dnf: Boolean = false,
    val toot: Boolean = false,
)

class ApiException(message: String) : IOException(message)

/** Talks to the /api endpoints of the PHP server. */
class ApiClient(serverUrl: String, private val token: String) {
    // The token gives full access, so it only ever travels over HTTPS.
    private val base: HttpUrl = serverUrl.trim().trimEnd('/').let {
        when {
            it.startsWith("https://", ignoreCase = true) -> it
            it.contains("://") -> throw ApiException("Gebruik een https://-adres.")
            else -> "https://$it"
        }
    }.toHttpUrl()

    suspend fun me(): Me = get("api/me")

    suspend fun books(status: String?, query: String): List<Book> =
        get<BooksResponse>("api/books", mapOf("status" to status, "q" to query)).books

    suspend fun book(id: Int): Book = get<BookResponse>("api/books/$id").book

    suspend fun create(book: NewBook): BookResponse = post("api/books", json.encodeToString(book))

    suspend fun start(id: Int, request: StartRequest): BookResponse =
        post("api/books/$id/start", json.encodeToString(request))

    suspend fun finish(id: Int, request: FinishRequest): BookResponse =
        post("api/books/$id/finish", json.encodeToString(request))

    suspend fun lookup(isbn: String): LookupResponse = get("api/lookup", mapOf("isbn" to isbn))

    private suspend inline fun <reified T> get(path: String, query: Map<String, String?> = emptyMap()): T {
        val url = base.newBuilder().addPathSegments(path).apply {
            query.forEach { (key, value) -> if (!value.isNullOrBlank()) addQueryParameter(key, value) }
        }.build()
        return json.decodeFromString<T>(execute(Request.Builder().url(url).get()))
    }

    private suspend inline fun <reified T> post(path: String, body: String): T {
        val url = base.newBuilder().addPathSegments(path).build()
        return json.decodeFromString<T>(execute(Request.Builder().url(url).post(body.toRequestBody(JSON))))
    }

    private suspend fun execute(builder: Request.Builder): String = withContext(Dispatchers.IO) {
        val request = builder.header("Authorization", "Bearer $token").header("Accept", "application/json").build()
        http.newCall(request).execute().use { response ->
            val body = response.body?.string().orEmpty()
            if (!response.isSuccessful) {
                throw ApiException(errorMessage(body) ?: "Server gaf HTTP ${response.code}")
            }
            body
        }
    }

    private fun errorMessage(body: String): String? = try {
        json.parseToJsonElement(body).jsonObject["error"]?.jsonPrimitive?.content
    } catch (e: Exception) {
        null
    }

    companion object {
        private val JSON = "application/json; charset=utf-8".toMediaType()
        private val http = OkHttpClient.Builder()
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .followSslRedirects(false) // never follow a redirect from https to http
            .build()
        val json = Json {
            ignoreUnknownKeys = true
            explicitNulls = false
            coerceInputValues = true
        }
    }
}
