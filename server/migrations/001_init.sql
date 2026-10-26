-- IF NOT EXISTS: some installs created these tables by hand before migrations were tracked.
CREATE TABLE IF NOT EXISTS books (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(500) NOT NULL,
    author      VARCHAR(500) NOT NULL DEFAULT '',
    isbn        VARCHAR(13) NULL,
    format      VARCHAR(50) NULL,
    status      ENUM('to-read', 'reading', 'read', 'dnf') NOT NULL DEFAULT 'to-read',
    rating      DECIMAL(3, 2) NULL,
    review      TEXT NULL,
    tags        VARCHAR(1000) NULL,
    moods       VARCHAR(1000) NULL,
    pages       INT UNSIGNED NULL,
    owned       TINYINT(1) NOT NULL DEFAULT 0,
    source      VARCHAR(20) NOT NULL DEFAULT 'manual',
    date_added  DATE NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY books_status (status),
    KEY books_isbn (isbn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per (re)read of a book.
CREATE TABLE IF NOT EXISTS `reads` (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    book_id   INT UNSIGNED NOT NULL,
    started   DATE NULL,
    finished  DATE NULL,
    KEY reads_finished (finished),
    CONSTRAINT reads_book FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log of toots sent to Mastodon.
CREATE TABLE IF NOT EXISTS posts (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    book_id     INT UNSIGNED NOT NULL,
    kind        VARCHAR(20) NOT NULL,
    url         VARCHAR(500) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT posts_book FOREIGN KEY (book_id) REFERENCES books (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tokens for the Android app (only the SHA-256 hash is stored).
CREATE TABLE IF NOT EXISTS api_tokens (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    token_hash    CHAR(64) NOT NULL UNIQUE,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at  DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
