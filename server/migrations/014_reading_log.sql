-- A reading diary: the page reached on each day you updated your progress or
-- finished a book, for pages per day.
CREATE TABLE IF NOT EXISTS reading_log (
    read_id  INT UNSIGNED NOT NULL,
    day      DATE NOT NULL,
    page     INT UNSIGNED NOT NULL,
    PRIMARY KEY (read_id, day),
    CONSTRAINT reading_log_read FOREIGN KEY (read_id) REFERENCES `reads` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Where the diary of a read starts: books you're in the middle of now count from
-- where you are, not from page 1 on the first day you log.
ALTER TABLE `reads` ADD COLUMN log_from_page INT UNSIGNED NULL;
UPDATE `reads` SET log_from_page = progress_page WHERE finished IS NULL AND progress_page IS NOT NULL;
