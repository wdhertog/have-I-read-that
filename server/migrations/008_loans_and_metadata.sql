-- Books you lent out: to whom, since when, and when they came back.
CREATE TABLE IF NOT EXISTS loans (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    book_id      INT UNSIGNED NOT NULL,
    borrower     VARCHAR(100) NOT NULL,
    lent_on      DATE NOT NULL,
    returned_on  DATE NULL,
    note         VARCHAR(500) NULL,
    KEY loans_book (book_id),
    KEY loans_borrower (borrower),
    CONSTRAINT loans_book FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- More from catalogues like the KB's.
ALTER TABLE books
    ADD COLUMN publisher VARCHAR(255) NULL,
    ADD COLUMN published_year SMALLINT UNSIGNED NULL,
    ADD COLUMN language VARCHAR(20) NULL,
    ADD COLUMN enriched_at DATETIME NULL;
