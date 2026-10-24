import hmac
import time
import uuid
from datetime import date

from flask import (Blueprint, abort, current_app, flash, redirect, render_template,
                   request, session, url_for)

from . import mastodon, openlibrary, storygraph
from .db import get_db

bp = Blueprint("main", __name__)

STATUSES = {
    "reading": "Aan het lezen",
    "to-read": "Wil ik lezen",
    "read": "Gelezen",
    "dnf": "Niet uitgelezen",
}
BOOK_FIELDS = ("title", "author", "isbn", "format", "pages", "status", "rating", "review", "tags")


@bp.app_context_processor
def inject():
    return {"STATUSES": STATUSES, "today": date.today().isoformat()}


@bp.before_app_request
def require_login():
    if request.endpoint in ("main.login", "static") or session.get("logged_in"):
        return None
    return redirect(url_for("main.login", next=request.full_path))


# --- auth -------------------------------------------------------------------

@bp.route("/login", methods=["GET", "POST"])
def login():
    password = current_app.config["APP_PASSWORD"]
    if request.method == "POST":
        if password and hmac.compare_digest(request.form.get("password", "").encode(), password.encode()):
            session.clear()
            session["logged_in"] = True
            session.permanent = True
            target = request.args.get("next", "")
            return redirect(target if target.startswith("/") and not target.startswith("//") else url_for("main.index"))
        time.sleep(1)
        flash("Onjuist wachtwoord.", "error")
    return render_template("login.html", configured=bool(password))


@bp.route("/logout", methods=["POST"])
def logout():
    session.clear()
    return redirect(url_for("main.login"))


# --- helpers ----------------------------------------------------------------

def mastodon_client():
    cfg = current_app.config
    return mastodon.Client(cfg["MASTODON_INSTANCE"], cfg["MASTODON_ACCESS_TOKEN"], cfg["MASTODON_VISIBILITY"])


def get_book(book_id):
    book = get_db().execute("SELECT * FROM books WHERE id = ?", (book_id,)).fetchone()
    if book is None:
        abort(404)
    return book


def compose_toot(book, kind):
    cfg = current_app.config
    template = cfg["MASTODON_TEMPLATE_FINISHED"] if kind == "finished" else cfg["MASTODON_TEMPLATE_STARTED"]
    return mastodon.compose(template, dict(book), cfg["MASTODON_MAX_CHARS"])


def toot(book_id, kind, text=None):
    """Post about a book and flash the result. Never raises."""
    client = mastodon_client()
    if not client.configured:
        flash("Niet gepost: Mastodon is nog niet ingesteld.", "error")
        return
    db = get_db()
    book = get_book(book_id)
    text = (text or "").strip() or compose_toot(book, kind)
    try:
        url = client.post(text, idempotency_key=str(uuid.uuid4()))
    except mastodon.MastodonError as exc:
        flash(f"Posten naar Mastodon mislukt: {exc}", "error")
        return
    with db:
        db.execute("INSERT INTO posts (book_id, kind, url) VALUES (?, ?, ?)", (book_id, kind, url))
    flash("Gepost naar Mastodon.", "ok")


def wants_toot():
    return request.form.get("toot") == "on"


def clean_date(value):
    value = (value or "").strip()
    try:
        return date.fromisoformat(value).isoformat() if value else None
    except ValueError:
        return None


def book_from_form(form):
    data = {key: (form.get(key) or "").strip() or None for key in BOOK_FIELDS}
    if not data["title"]:
        raise ValueError("Titel is verplicht.")
    data["author"] = data["author"] or ""
    if data["status"] not in STATUSES:
        data["status"] = "to-read"
    try:
        data["pages"] = int(data["pages"]) if data["pages"] else None
        data["rating"] = float(data["rating"].replace(",", ".")) if data["rating"] else None
    except ValueError:
        raise ValueError("Pagina's en beoordeling moeten getallen zijn.")
    if data["rating"] is not None and not 0 < data["rating"] <= 5:
        raise ValueError("Beoordeling moet tussen 0 en 5 liggen.")
    data["owned"] = 1 if form.get("owned") == "on" else 0
    return data


# --- books ------------------------------------------------------------------

@bp.route("/")
def index():
    status = request.args.get("status", "")
    q = request.args.get("q", "").strip()
    sql = """SELECT b.*, (SELECT max(finished) FROM reads r WHERE r.book_id = b.id) AS last_finished
             FROM books b WHERE 1=1"""
    args = []
    if status in STATUSES:
        sql += " AND b.status = ?"
        args.append(status)
    if q:
        sql += " AND (b.title LIKE ? OR b.author LIKE ? OR b.tags LIKE ? OR b.isbn = ?)"
        args += [f"%{q}%"] * 3 + [q]
    sql += """ ORDER BY CASE b.status WHEN 'reading' THEN 0 ELSE 1 END,
                        coalesce(last_finished, b.date_added) DESC, b.id DESC"""
    db = get_db()
    books = db.execute(sql, args).fetchall()
    counts = dict(db.execute("SELECT status, count(*) FROM books GROUP BY status").fetchall())
    return render_template("index.html", books=books, status=status, q=q, counts=counts)


@bp.route("/add", methods=["GET", "POST"])
def add():
    if request.method == "POST":
        try:
            data = book_from_form(request.form)
        except ValueError as exc:
            flash(str(exc), "error")
            return render_template("book_form.html", book=request.form, results=None, q="")
        db = get_db()
        with db:
            cur = db.execute(
                """INSERT INTO books (title, author, isbn, format, pages, status, rating, review, tags, owned)
                   VALUES (:title, :author, :isbn, :format, :pages, :status, :rating, :review, :tags, :owned)""",
                data,
            )
            book_id = cur.lastrowid
            started, finished = clean_date(request.form.get("started")), clean_date(request.form.get("finished"))
            if data["status"] == "reading":
                db.execute("INSERT INTO reads (book_id, started) VALUES (?, ?)", (book_id, started or date.today().isoformat()))
            elif data["status"] in ("read", "dnf") and (started or finished or data["status"] == "read"):
                db.execute("INSERT INTO reads (book_id, started, finished) VALUES (?, ?, ?)",
                           (book_id, started, finished or date.today().isoformat()))
        flash("Boek toegevoegd.", "ok")
        if wants_toot() and data["status"] in ("reading", "read"):
            toot(book_id, "started" if data["status"] == "reading" else "finished")
        return redirect(url_for("main.book", book_id=book_id))

    q = request.args.get("q", "").strip()
    results = None
    if q:
        try:
            results = openlibrary.search(q)
        except Exception as exc:  # network trouble shouldn't break adding a book by hand
            flash(f"Zoeken bij Open Library mislukt: {exc}", "error")
            results = []
    prefill = {key: request.args.get(key, "") for key in ("title", "author", "isbn", "pages")}
    prefill["status"] = request.args.get("status", "read")
    return render_template("book_form.html", book=prefill, results=results, q=q)


@bp.route("/book/<int:book_id>")
def book(book_id):
    db = get_db()
    book = get_book(book_id)
    reads = db.execute("SELECT * FROM reads WHERE book_id = ? ORDER BY coalesce(finished, started, '9999') DESC, id DESC",
                       (book_id,)).fetchall()
    posts = db.execute("SELECT * FROM posts WHERE book_id = ? ORDER BY id DESC", (book_id,)).fetchall()
    return render_template("book.html", book=book, reads=reads, posts=posts,
                           preview_finished=compose_toot(book, "finished"),
                           preview_started=compose_toot(book, "started"))


@bp.route("/book/<int:book_id>/edit", methods=["GET", "POST"])
def edit(book_id):
    book = get_book(book_id)
    if request.method == "POST":
        try:
            data = book_from_form(request.form)
        except ValueError as exc:
            flash(str(exc), "error")
            return render_template("book_form.html", book=request.form, edit=True, book_id=book_id)
        db = get_db()
        with db:
            db.execute(
                """UPDATE books SET title = :title, author = :author, isbn = :isbn, format = :format,
                       pages = :pages, status = :status, rating = :rating, review = :review, tags = :tags,
                       owned = :owned, updated_at = datetime('now')
                   WHERE id = :id""",
                {**data, "id": book_id},
            )
        flash("Opgeslagen.", "ok")
        return redirect(url_for("main.book", book_id=book_id))
    return render_template("book_form.html", book=dict(book), edit=True, book_id=book_id)


@bp.route("/book/<int:book_id>/delete", methods=["POST"])
def delete(book_id):
    get_book(book_id)
    db = get_db()
    with db:
        db.execute("DELETE FROM books WHERE id = ?", (book_id,))
    flash("Boek verwijderd.", "ok")
    return redirect(url_for("main.index"))


@bp.route("/book/<int:book_id>/start", methods=["POST"])
def start(book_id):
    get_book(book_id)
    db = get_db()
    with db:
        db.execute("INSERT INTO reads (book_id, started) VALUES (?, ?)",
                   (book_id, clean_date(request.form.get("started")) or date.today().isoformat()))
        db.execute("UPDATE books SET status = 'reading', updated_at = datetime('now') WHERE id = ?", (book_id,))
    flash("Veel leesplezier!", "ok")
    if wants_toot():
        toot(book_id, "started")
    return redirect(url_for("main.book", book_id=book_id))


@bp.route("/book/<int:book_id>/finish", methods=["POST"])
def finish(book_id):
    book = get_book(book_id)
    status = "dnf" if request.form.get("dnf") == "on" else "read"
    finished = clean_date(request.form.get("finished")) or date.today().isoformat()
    rating = request.form.get("rating", "").replace(",", ".").strip()
    try:
        rating = float(rating) if rating else book["rating"]
    except ValueError:
        rating = book["rating"]
    review = request.form.get("review", "").strip() or book["review"]
    db = get_db()
    with db:
        open_read = db.execute("SELECT id FROM reads WHERE book_id = ? AND finished IS NULL ORDER BY id DESC LIMIT 1",
                               (book_id,)).fetchone()
        if open_read:
            db.execute("UPDATE reads SET finished = ? WHERE id = ?", (finished, open_read["id"]))
        else:
            db.execute("INSERT INTO reads (book_id, finished) VALUES (?, ?)", (book_id, finished))
        db.execute("""UPDATE books SET status = ?, rating = ?, review = ?, updated_at = datetime('now')
                      WHERE id = ?""", (status, rating, review, book_id))
    flash("Uitgelezen! 🎉" if status == "read" else "Gemarkeerd als niet uitgelezen.", "ok")
    if wants_toot() and status == "read":
        toot(book_id, "finished")
    return redirect(url_for("main.book", book_id=book_id))


@bp.route("/book/<int:book_id>/toot", methods=["POST"])
def toot_again(book_id):
    get_book(book_id)
    kind = "started" if request.form.get("kind") == "started" else "finished"
    toot(book_id, kind, request.form.get("text"))
    return redirect(url_for("main.book", book_id=book_id))


@bp.route("/book/<int:book_id>/reads", methods=["POST"])
def add_read(book_id):
    get_book(book_id)
    started, finished = clean_date(request.form.get("started")), clean_date(request.form.get("finished"))
    if not (started or finished):
        flash("Vul minstens één datum in.", "error")
    else:
        db = get_db()
        with db:
            db.execute("INSERT INTO reads (book_id, started, finished) VALUES (?, ?, ?)", (book_id, started, finished))
    return redirect(url_for("main.book", book_id=book_id))


@bp.route("/reads/<int:read_id>/delete", methods=["POST"])
def delete_read(read_id):
    db = get_db()
    row = db.execute("SELECT book_id FROM reads WHERE id = ?", (read_id,)).fetchone()
    if row is None:
        abort(404)
    with db:
        db.execute("DELETE FROM reads WHERE id = ?", (read_id,))
    return redirect(url_for("main.book", book_id=row["book_id"]))


# --- stats, import, settings ------------------------------------------------

@bp.route("/stats")
def stats():
    db = get_db()
    years = db.execute(
        """SELECT substr(r.finished, 1, 4) AS year, count(*) AS books, sum(b.pages) AS pages,
                  round(avg(b.rating), 2) AS avg_rating
           FROM reads r JOIN books b ON b.id = r.book_id
           WHERE r.finished IS NOT NULL AND b.status = 'read'
           GROUP BY year ORDER BY year DESC"""
    ).fetchall()
    year = request.args.get("year") or (years[0]["year"] if years else str(date.today().year))
    books = db.execute(
        """SELECT b.*, r.finished FROM reads r JOIN books b ON b.id = r.book_id
           WHERE substr(r.finished, 1, 4) = ? AND b.status = 'read' ORDER BY r.finished DESC""",
        (year,),
    ).fetchall()
    top_authors = db.execute(
        """SELECT author, count(*) AS n FROM books WHERE status = 'read' AND author != ''
           GROUP BY author ORDER BY n DESC, author LIMIT 10"""
    ).fetchall()
    max_books = max((y["books"] for y in years), default=0)
    return render_template("stats.html", years=years, year=year, books=books,
                           top_authors=top_authors, max_books=max_books)


@bp.route("/import", methods=["GET", "POST"])
def import_storygraph():
    result = None
    if request.method == "POST":
        upload = request.files.get("file")
        if not upload or not upload.filename:
            flash("Kies eerst een CSV-bestand.", "error")
        else:
            try:
                text = upload.read().decode("utf-8-sig")
                imported, skipped = storygraph.import_csv(get_db(), text)
                result = {"imported": imported, "skipped": skipped}
                flash(f"{len(imported)} boeken geïmporteerd, {len(skipped)} overgeslagen (bestonden al).", "ok")
            except (UnicodeDecodeError, ValueError) as exc:
                flash(f"Import mislukt: {exc}", "error")
    return render_template("import.html", result=result)


@bp.route("/settings", methods=["GET", "POST"])
def settings():
    client = mastodon_client()
    account = None
    if request.method == "POST":
        try:
            account = client.verify()
            flash(f"Verbonden als @{account.get('acct')}.", "ok")
        except mastodon.MastodonError as exc:
            flash(str(exc), "error")
    sample = {"title": "De avonden", "author": "Gerard Reve", "rating": 4.5,
              "review": "Een prachtig boek over verveling.", "isbn": "9789023417069"}
    cfg = current_app.config
    return render_template(
        "settings.html", client=client, account=account,
        preview_finished=mastodon.compose(cfg["MASTODON_TEMPLATE_FINISHED"], sample, cfg["MASTODON_MAX_CHARS"]),
        preview_started=mastodon.compose(cfg["MASTODON_TEMPLATE_STARTED"], sample, cfg["MASTODON_MAX_CHARS"]),
    )
