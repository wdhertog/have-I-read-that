"""SQLite storage."""

import sqlite3

from flask import current_app, g

SCHEMA = """
CREATE TABLE IF NOT EXISTS books (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT NOT NULL,
    author      TEXT NOT NULL DEFAULT '',
    isbn        TEXT,
    format      TEXT,
    status      TEXT NOT NULL DEFAULT 'to-read'
                CHECK (status IN ('to-read', 'reading', 'read', 'dnf')),
    rating      REAL,
    review      TEXT,
    tags        TEXT,
    moods       TEXT,
    pages       INTEGER,
    owned       INTEGER NOT NULL DEFAULT 0,
    source      TEXT NOT NULL DEFAULT 'manual',
    date_added  TEXT NOT NULL DEFAULT (date('now')),
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS books_status ON books (status);
CREATE INDEX IF NOT EXISTS books_isbn ON books (isbn);

-- One row per (re)read of a book.
CREATE TABLE IF NOT EXISTS reads (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    book_id   INTEGER NOT NULL REFERENCES books (id) ON DELETE CASCADE,
    started   TEXT,
    finished  TEXT
);

CREATE INDEX IF NOT EXISTS reads_book ON reads (book_id);

-- Log of toots sent to Mastodon.
CREATE TABLE IF NOT EXISTS posts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    book_id     INTEGER NOT NULL REFERENCES books (id) ON DELETE CASCADE,
    kind        TEXT NOT NULL,
    url         TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);
"""


def connect(path):
    conn = sqlite3.connect(path)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")
    return conn


def get_db():
    if "db" not in g:
        g.db = connect(current_app.config["DATABASE"])
    return g.db


def close_db(_exc=None):
    db = g.pop("db", None)
    if db is not None:
        db.close()


def init_db(path):
    conn = connect(path)
    with conn:
        conn.executescript(SCHEMA)
    conn.close()
