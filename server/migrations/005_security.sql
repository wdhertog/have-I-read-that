-- Small key/value store for app settings (two-step verification).
CREATE TABLE IF NOT EXISTS settings (
    name   VARCHAR(50) NOT NULL PRIMARY KEY,
    value  TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tokens can be limited to reading.
ALTER TABLE api_tokens ADD COLUMN scope VARCHAR(10) NOT NULL DEFAULT 'full';
