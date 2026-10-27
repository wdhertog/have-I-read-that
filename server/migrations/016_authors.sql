-- What Wikidata and Wikipedia say about the writers in your library, per name as it
-- appears on your books; looked up again after 30 days. qid is NULL when not found.
CREATE TABLE IF NOT EXISTS authors (
    name        VARCHAR(255) NOT NULL PRIMARY KEY,
    qid         VARCHAR(20) NULL,
    data        MEDIUMTEXT NULL,
    fetched_at  DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
