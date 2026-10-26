-- Series, and StoryGraph's own id per book so a re-import still recognises a book
-- after you changed its title (also for books without an ISBN).
ALTER TABLE books
    ADD COLUMN series VARCHAR(255) NULL,
    ADD COLUMN series_number DECIMAL(6, 1) NULL,
    ADD COLUMN storygraph_id VARCHAR(100) NULL,
    ADD KEY books_series (series),
    ADD KEY books_storygraph_id (storygraph_id);

-- Notes and quotes per book. Private unless marked public (then on the public book page).
CREATE TABLE IF NOT EXISTS notes (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    book_id     INT UNSIGNED NOT NULL,
    kind        ENUM('note', 'quote') NOT NULL DEFAULT 'note',
    text        TEXT NOT NULL,
    page        INT UNSIGNED NULL,
    public      TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY notes_book (book_id),
    CONSTRAINT notes_book FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
