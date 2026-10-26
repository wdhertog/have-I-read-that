-- Reading goal per year (number of books).
CREATE TABLE IF NOT EXISTS goals (
    year   SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
    books  SMALLINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- How far along the current read is.
ALTER TABLE `reads`
    ADD COLUMN progress_page INT UNSIGNED NULL,
    ADD COLUMN progress_percent TINYINT UNSIGNED NULL;
