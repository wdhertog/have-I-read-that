package app.haveireadthat

import android.content.Context
import androidx.glance.appwidget.updateAll
import app.haveireadthat.widget.ReadingWidget
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import kotlinx.serialization.Serializable
import kotlinx.serialization.decodeFromString
import kotlinx.serialization.encodeToString
import java.io.File
import java.io.IOException
import java.time.LocalDate
import java.util.UUID

/** A change made while offline, sent when there is a connection again. */
@Serializable
data class PendingAction(
    val id: String,
    val type: String, // start, finish, progress, create, note, lend, return
    val bookId: Int,
    val title: String,
    val body: String, // the request as JSON
)

/** What the phone keeps: the whole library, goal, changes waiting to be sent, and problems. */
@Serializable
data class LocalState(
    val books: List<Book> = emptyList(),
    val details: Map<Int, Book> = emptyMap(),
    val goal: Goal? = null,
    val updatedAt: String? = null,
    val queue: List<PendingAction> = emptyList(),
    val failures: List<String> = emptyList(),
)

data class Loaded<T>(val value: T, val offline: Boolean)

/** Result of an action: done on the server, or saved to send later. */
sealed interface Outcome {
    data class Sent(val response: BookResponse) : Outcome
    data class Queued(val book: Book) : Outcome
}

/**
 * The app's single way to the server. Reads fall back to the copy on the phone when
 * there's no connection; start, finish, progress and new books are queued instead.
 * A plain IOException means "no connection"; ApiException is the server saying no.
 */
class Repository(context: Context) {
    private val app = context.applicationContext
    private val settings = Settings(app)
    private val file = File(app.filesDir, "library.json")

    private fun client() = settings.client()

    // --- reading -------------------------------------------------------------

    suspend fun library(): Loaded<List<Book>> = try {
        val books = client().books(null, "")
        val goal = runCatching { client().me().goal }.getOrNull()
        update { it.copy(books = books, goal = goal ?: it.goal, updatedAt = LocalDate.now().toString()) }
        sync()
        Loaded(state().books, offline = false)
    } catch (e: ApiException) {
        throw e
    } catch (e: IOException) {
        Loaded(state().books, offline = true)
    }

    suspend fun book(id: Int): Loaded<Book> {
        if (id < 0) return Loaded(cached(id) ?: throw IOException("Nog niet gesynchroniseerd"), offline = true)
        return try {
            val book = client().book(id)
            update { it.copy(details = it.details + (id to book)) }
            Loaded(book, offline = false)
        } catch (e: ApiException) {
            throw e
        } catch (e: IOException) {
            Loaded(cached(id) ?: throw e, offline = true)
        }
    }

    suspend fun goal(): Goal? = state().goal

    suspend fun state(): LocalState = lock.withLock { read() }

    private suspend fun cached(id: Int): Book? = state().let { s -> s.details[id] ?: s.books.firstOrNull { it.id == id } }

    // --- changing --------------------------------------------------------------

    suspend fun start(book: Book, request: StartRequest): Outcome = send(
        "start", book, ApiClient.json.encodeToString(request),
        online = { client().start(book.id, request) },
        local = { it.copy(status = "reading", progressPage = null, progressPercent = null, reads = listOf(Read(0, request.date)) + it.reads) },
    )

    suspend fun finish(book: Book, request: FinishRequest): Outcome = send(
        "finish", book, ApiClient.json.encodeToString(request),
        online = { client().finish(book.id, request) },
        local = {
            it.copy(
                status = if (request.dnf) "dnf" else "read",
                rating = request.rating ?: it.rating,
                review = request.review ?: it.review,
                lastFinished = request.date,
            )
        },
    )

    suspend fun progress(book: Book, request: ProgressRequest): Outcome = send(
        "progress", book, ApiClient.json.encodeToString(request),
        online = { client().progress(book.id, request) },
        local = {
            val pages = it.pages
            val percent = request.percent ?: request.page?.let { page -> if (pages != null && pages > 0) page * 100 / pages else null }
            it.copy(progressPage = request.page, progressPercent = percent)
        },
    )

    /** A note or quote; offline it's saved on the phone and sent later. */
    suspend fun addNote(book: Book, note: NewNote): Outcome = send(
        "note", book, ApiClient.json.encodeToString(note),
        online = { client().addNote(book.id, note) },
        local = { it.copy(notes = it.notes + Note(id = -1 - it.notes.size, kind = note.kind, text = note.text, page = note.page, isPublic = note.isPublic)) },
    )

    suspend fun lend(book: Book, request: LendRequest): Outcome = send(
        "lend", book, ApiClient.json.encodeToString(request),
        online = { client().lend(book.id, request) },
        local = { it.copy(lentTo = request.borrower, lentSince = request.date) },
    )

    suspend fun giveBack(book: Book, request: ReturnRequest): Outcome = send(
        "return", book, ApiClient.json.encodeToString(request),
        online = { client().giveBack(book.id, request) },
        local = { it.copy(lentTo = null, lentSince = null) },
    )

    /** Everyone you lent to; offline only what's out now, from the phone's copy. */
    suspend fun loans(): Loaded<LoansResponse> = try {
        Loaded(client().loans(), offline = false)
    } catch (e: ApiException) {
        throw e
    } catch (e: IOException) {
        val out = state().books.filter { it.lentTo != null }
        val people = out.groupBy { it.lentTo!!.lowercase() }.values.map { books ->
            Person(books.first().lentTo!!, current = books.map { Loan(0, it.id, it.lentTo!!, it.lentSince.orEmpty(), title = it.title) })
        }.sortedBy { it.name.lowercase() }
        Loaded(LoansResponse(people, people.map { it.name }), offline = true)
    }

    /** Add a book; offline it gets a temporary negative id until it's sent. */
    suspend fun create(request: NewBook): Outcome = try {
        client().create(request).also { refreshAfterChange() }.let { Outcome.Sent(it) }
    } catch (e: ApiException) {
        throw e
    } catch (e: IOException) {
        val temp = Book(
            id = -(System.currentTimeMillis() % 1_000_000_000).toInt() - 1,
            title = request.title,
            author = request.author,
            isbn = request.isbn,
            pages = request.pages,
            status = request.status,
        )
        enqueue(PendingAction(UUID.randomUUID().toString(), "create", temp.id, temp.title, ApiClient.json.encodeToString(request))) {
            it.copy(books = listOf(temp) + it.books, details = it.details + (temp.id to temp))
        }
        Outcome.Queued(temp)
    }

    private suspend fun send(
        type: String,
        book: Book,
        body: String,
        online: suspend () -> BookResponse,
        local: (Book) -> Book,
    ): Outcome {
        if (book.id < 0) throw ApiException("Dit boek is nog niet naar de server gestuurd; probeer het straks opnieuw.")
        return try {
            val response = online()
            update { it.withBook(response.book) }
            refreshAfterChange()
            Outcome.Sent(response)
        } catch (e: ApiException) {
            throw e
        } catch (e: IOException) {
            val changed = local(book)
            enqueue(PendingAction(UUID.randomUUID().toString(), type, book.id, book.title, body)) { it.withBook(changed) }
            Outcome.Queued(changed)
        }
    }

    // --- syncing ----------------------------------------------------------------

    /**
     * Send queued changes in order. Stops at the first connection problem (true is
     * returned when everything went). A change the server refuses is dropped and
     * remembered in failures, so one bad change can't block the rest.
     */
    suspend fun sync(): Boolean {
        while (true) {
            val next = state().queue.firstOrNull() ?: break
            try {
                val response = when (next.type) {
                    "start" -> client().start(next.bookId, ApiClient.json.decodeFromString<StartRequest>(next.body))
                    "finish" -> client().finish(next.bookId, ApiClient.json.decodeFromString<FinishRequest>(next.body))
                    "progress" -> client().progress(next.bookId, ApiClient.json.decodeFromString<ProgressRequest>(next.body))
                    "create" -> client().create(ApiClient.json.decodeFromString<NewBook>(next.body))
                    "note" -> client().addNote(next.bookId, ApiClient.json.decodeFromString<NewNote>(next.body))
                    "lend" -> client().lend(next.bookId, ApiClient.json.decodeFromString<LendRequest>(next.body))
                    "return" -> client().giveBack(next.bookId, ApiClient.json.decodeFromString<ReturnRequest>(next.body))
                    else -> null
                }
                update { s ->
                    val rest = s.queue.filterNot { it.id == next.id }
                    var updated = s.copy(queue = rest)
                    if (next.type == "create") {
                        updated = updated.copy(books = updated.books.filterNot { it.id == next.bookId }, details = updated.details - next.bookId)
                    }
                    response?.let { updated.withBook(it.book) } ?: updated
                }
            } catch (e: ApiException) {
                update { s ->
                    s.copy(
                        queue = s.queue.filterNot { it.id == next.id },
                        failures = (s.failures + "“${next.title}”: ${e.message}").takeLast(20),
                    )
                }
            } catch (e: IOException) {
                SyncWorker.schedule(app)
                return false
            }
        }
        ReadingWidget().updateAll(app)
        return true
    }

    suspend fun clearFailures() = update { it.copy(failures = emptyList()) }


    private suspend fun refreshAfterChange() {
        runCatching { client().me().goal }.getOrNull()?.let { goal -> update { it.copy(goal = goal) } }
        ReadingWidget().updateAll(app)
    }

    private suspend fun enqueue(action: PendingAction, change: (LocalState) -> LocalState) {
        update { change(it).copy(queue = it.queue + action) }
        SyncWorker.schedule(app)
        ReadingWidget().updateAll(app)
    }

    // --- storage ----------------------------------------------------------------

    private suspend fun update(change: (LocalState) -> LocalState) = lock.withLock {
        withContext(Dispatchers.IO) { write(change(read())) }
    }

    private suspend fun read(): LocalState = withContext(Dispatchers.IO) {
        if (!file.exists()) LocalState()
        else runCatching { ApiClient.json.decodeFromString<LocalState>(file.readText()) }.getOrDefault(LocalState())
    }

    private fun write(state: LocalState) {
        val temp = File(file.parentFile, file.name + ".tmp")
        temp.writeText(ApiClient.json.encodeToString(state))
        temp.renameTo(file)
    }

    companion object {
        /** One lock for all instances: the app, the sync worker and the widget share the file. */
        private val lock = Mutex()

        /** Forget the library on the phone (when logging out). */
        fun forget(context: Context) {
            File(context.applicationContext.filesDir, "library.json").delete()
        }
    }
}

/** Put a newer version of a book into both the list and the details. */
private fun LocalState.withBook(book: Book): LocalState = copy(
    books = if (books.any { it.id == book.id }) books.map { if (it.id == book.id) book.copy(reads = emptyList(), posts = emptyList(), notes = emptyList(), loans = emptyList()) else it }
    else listOf(book) + books,
    details = details + (book.id to book),
)
