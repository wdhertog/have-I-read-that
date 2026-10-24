"""Parse and import a StoryGraph CSV export.

StoryGraph exports (Manage Account -> Export StoryGraph Library) look like:

    Title,Authors,Contributors,ISBN/UID,Format,Read Status,Date Added,
    Last Date Read,Dates Read,Read Count,Moods,Pace,...,Star Rating,Review,
    Content Warnings,Content Warning Description,Tags,Owned?
"""

import csv
import io
import re
from datetime import datetime

STATUS_MAP = {
    "read": "read",
    "to-read": "to-read",
    "currently-reading": "reading",
    "paused": "reading",
    "did-not-finish": "dnf",
}

DATE_RE = re.compile(r"\d{4}[/-]\d{1,2}[/-]\d{1,2}")


def parse_date(value):
    """Return an ISO date (YYYY-MM-DD) or None."""
    value = (value or "").strip()
    if not value:
        return None
    for fmt in ("%Y/%m/%d", "%Y-%m-%d", "%d/%m/%Y", "%m/%d/%Y"):
        try:
            return datetime.strptime(value, fmt).date().isoformat()
        except ValueError:
            pass
    return None


def parse_dates_read(value, status):
    """Parse "2023/01/05-2023/01/20, 2024/03/01-2024/03/09" into (start, end) pairs."""
    reads = []
    for segment in (value or "").split(","):
        dates = [parse_date(d) for d in DATE_RE.findall(segment)]
        if len(dates) >= 2:
            reads.append((dates[0], dates[1]))
        elif len(dates) == 1:
            # "2023/01/05-" is an open start, "-2023/01/20" only an end. Without a
            # dash, a single date is a finish date for finished books.
            segment = segment.strip()
            if segment.endswith("-"):
                reads.append((dates[0], None))
            elif segment.startswith("-") or status == "read":
                reads.append((None, dates[0]))
            else:
                reads.append((dates[0], None))
    return reads


def clean_isbn(value):
    isbn = re.sub(r"[^0-9Xx]", "", value or "").upper()
    return isbn if len(isbn) in (10, 13) else None


def parse_rating(value):
    try:
        rating = float((value or "").strip())
    except ValueError:
        return None
    return rating if 0 < rating <= 5 else None


def parse_rows(text):
    """Yield normalized book dicts from the CSV text."""
    reader = csv.DictReader(io.StringIO(text))
    if not reader.fieldnames or "Title" not in reader.fieldnames:
        raise ValueError("Dit lijkt geen StoryGraph-export te zijn (kolom 'Title' ontbreekt).")
    for row in reader:
        title = (row.get("Title") or "").strip()
        if not title:
            continue
        status = STATUS_MAP.get((row.get("Read Status") or "").strip().lower(), "to-read")
        reads = parse_dates_read(row.get("Dates Read"), status)
        last_read = parse_date(row.get("Last Date Read"))
        if not reads and last_read:
            reads = [(None, last_read)]
        yield {
            "title": title,
            "author": (row.get("Authors") or "").strip(),
            "isbn": clean_isbn(row.get("ISBN/UID")),
            "format": (row.get("Format") or "").strip() or None,
            "status": status,
            "rating": parse_rating(row.get("Star Rating")),
            "review": (row.get("Review") or "").strip() or None,
            "tags": (row.get("Tags") or "").strip() or None,
            "moods": (row.get("Moods") or "").strip() or None,
            "owned": 1 if (row.get("Owned?") or "").strip().lower() == "yes" else 0,
            "date_added": parse_date(row.get("Date Added")),
            "reads": reads,
        }


def import_csv(db, text):
    """Import a StoryGraph export. Books that already exist are skipped.

    Returns (imported, skipped) lists of titles.
    """
    imported, skipped = [], []
    with db:
        for book in parse_rows(text):
            if _exists(db, book):
                skipped.append(book["title"])
                continue
            cur = db.execute(
                """INSERT INTO books (title, author, isbn, format, status, rating, review,
                                      tags, moods, owned, source, date_added)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'storygraph', COALESCE(?, date('now')))""",
                (book["title"], book["author"], book["isbn"], book["format"], book["status"],
                 book["rating"], book["review"], book["tags"], book["moods"], book["owned"],
                 book["date_added"]),
            )
            db.executemany(
                "INSERT INTO reads (book_id, started, finished) VALUES (?, ?, ?)",
                [(cur.lastrowid, s, f) for s, f in book["reads"]],
            )
            imported.append(book["title"])
    return imported, skipped


def _exists(db, book):
    if book["isbn"] and db.execute("SELECT 1 FROM books WHERE isbn = ?", (book["isbn"],)).fetchone():
        return True
    return db.execute(
        "SELECT 1 FROM books WHERE lower(title) = lower(?) AND lower(author) = lower(?)",
        (book["title"], book["author"]),
    ).fetchone() is not None
