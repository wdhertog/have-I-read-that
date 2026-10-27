-- Other ISBNs and StoryGraph ids of a book, kept when editions are merged into it,
-- so a scan or a new import recognises the merged book.
CREATE TABLE IF NOT EXISTS book_aliases (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    book_id        INT UNSIGNED NOT NULL,
    isbn           VARCHAR(13) NULL,
    storygraph_id  VARCHAR(100) NULL,
    KEY book_aliases_isbn (isbn),
    KEY book_aliases_storygraph (storygraph_id),
    CONSTRAINT book_aliases_book FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The series in Wikidata (its Q-id), remembered when looking a book up.
ALTER TABLE books ADD COLUMN wikidata_series VARCHAR(20) NULL;

-- All parts of a series as Wikidata knows them, per series name in your library;
-- looked up again after 30 days. qid is NULL when Wikidata doesn't know the series.
CREATE TABLE IF NOT EXISTS series_parts (
    series      VARCHAR(255) NOT NULL PRIMARY KEY,
    qid         VARCHAR(20) NULL,
    parts       MEDIUMTEXT NULL,
    fetched_at  DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Suggestions you said no to.
CREATE TABLE IF NOT EXISTS dismissed_suggestions (
    qid  VARCHAR(20) NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
